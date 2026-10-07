<?php

declare(strict_types=1);

namespace App\Web;

use App\Repository\SettingsRepository;
use App\Service\Api;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Closes the public site behind a maintenance page when the owner says so.
 *
 * ## The second gate, deliberately
 *
 * There is already a maintenance gate: `runtime/maintenance.lock`, checked in
 * `public/index.php` *before* the autoloader, because a deploy that dies
 * half-way must still render a page when the framework itself is the broken
 * thing. That gate cannot be reached from a settings form, and it must not be:
 * it answers "is the code on disk safe to run", a question nobody can ask from
 * inside code that may not be safe to run.
 *
 * This one answers a different question — "does the owner want the site
 * closed" — and it can only be asked from inside a *healthy* application,
 * which is exactly why it is allowed to read the database. The two never
 * substitute for each other: a lock file brought up by a deploy still wins,
 * because this middleware is never reached when that one is in place.
 *
 * ## Why the open paths are paths, not roles
 *
 * The obvious design is "let staff through": resolve the session identity and
 * check the role. That costs a user lookup on every request while the site is
 * closed, duplicates the bearer-token dance that `AdminApiMiddleware` already
 * owns, and — worst — could be *wrong* in the one direction that matters, a
 * demoted or logged-out owner staring at a site they can no longer open.
 *
 * So the check is on the path instead: `/admin`, `/api/admin/*` and the four
 * account-recovery routes stay reachable, everything else does not. No
 * authority is granted by that, because those paths are already guarded by
 * `AdminMiddleware` / `AdminApiMiddleware`, which re-read the live role — a
 * guest who types `/admin` is redirected to `/login`, which is open for the
 * same reason and no other. And it means the switch can always be undone:
 * from the panel, from the Android app, from any browser the owner can sign
 * into, with no lock file, cookie or token to lose.
 *
 * ## Availability first
 *
 * A settings table that cannot be read fails *open*. Failing closed would
 * turn a database hiccup into "the whole site is down", hide the real error
 * behind a page that claims it is intentional, and lock the owner out of the
 * very panel that would tell them what broke.
 */
final readonly class MaintenanceMiddleware implements MiddlewareInterface
{
    /**
     * Seconds a client is told to wait. Matches MAINTENANCE_RETRY_AFTER in
     * `public/maintenance.php` so both gates ask for the same patience.
     */
    private const RETRY_AFTER = 60;

    /** Used when the owner left the message box empty. */
    private const DEFAULT_MESSAGE = 'সাইটটি সাময়িকভাবে বন্ধ আছে। শীঘ্রই ফিরে আসছি।';

    /**
     * Paths that stay open while the site is closed.
     *
     * Sign-in and sign-out, because an owner who cannot sign in cannot turn
     * this off; and the two recovery routes, because a lost password must not
     * become a lost site. All four are CSRF-protected and throttled, and none
     * of them leads anywhere a closed site would not already put you — after
     * a successful login the dashboard answers with this same page.
     *
     * @var list<string>
     */
    private const OPEN_PATHS = ['/login', '/logout', '/forgot-password', '/reset-password'];

    public function __construct(
        private SettingsRepository $settings,
        private WebViewRenderer $view,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $message = $this->maintenanceMessage();
        if ($message === null) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($this->isOpen($path)) {
            return $handler->handle($request);
        }

        $headers = [
            // 503 + Retry-After is what makes caches, the TWA and monitoring
            // back off instead of hammering a host that is closed on purpose;
            // no-store keeps a CDN from pinning the page for the next visitor
            // after the switch is flipped back off.
            'Retry-After' => (string) self::RETRY_AFTER,
            'Cache-Control' => 'no-store, max-age=0',
        ];

        // The app's own API clients — the Android app above all — are told the
        // same thing in the envelope they already understand, rather than
        // being handed an HTML page they cannot parse.
        if (str_starts_with($path, '/api/')) {
            return Api::json([
                'success' => false,
                'message' => $message,
                'data' => null,
                'errors' => [],
            ], 503)->withHeader('Retry-After', $headers['Retry-After'])
                ->withHeader('Cache-Control', $headers['Cache-Control']);
        }

        $html = $this->view->renderAsString('site/maintenance.twig', [
            'maintenanceMessage' => $message,
        ]);

        return new Response(503, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, $html);
    }

    /**
     * The message to show, or null while the site is open.
     *
     * Null rather than '' so that "off" and "on with an empty box" stay
     * distinguishable — one of them must let the request through.
     *
     * When a daily maintenance window is configured (maintenance_schedule_start /
     * maintenance_schedule_end in HH:MM Bangladesh time) and the current time is
     * inside that window, the site is treated as if maintenance were enabled in
     * that window, even if maintenance_enabled itself is off: the scheduled close
     * wins during its window.
     */
    private function maintenanceMessage(): ?string
    {
        try {
            $enabled = $this->settings->get('maintenance_enabled') === '1';
            $scheduled = $this->isInsideScheduledWindow();

            if (!$enabled && !$scheduled) {
                return null;
            }

            // The scheduled window carries its own message; when both the manual
            // switch and the window are active the manual message is shown.
            if ($enabled) {
                $message = trim($this->settings->get('maintenance_message'));
                return $message !== '' ? $message : self::DEFAULT_MESSAGE;
            }

            // Scheduled close — use the window's own message, falling back to the
            // generic default so the visitor always gets something.
            $msg = trim($this->settings->get('maintenance_schedule_message'));
            return $msg !== '' ? $msg : self::DEFAULT_MESSAGE;
        } catch (\Throwable) {
            // Fail open — see the class docblock.
            return null;
        }
    }

    /**
     * Whether the current Bangladesh-time clock is inside the daily maintenance
     * window configured in settings.
     *
     * A missing or malformed window is treated as "no window" rather than as a
     * hard close, because a bad HH:MM should not take the site down by accident.
     *
     * The window is inclusive on both ends: 02:00-04:00 closes at exactly 02:00:00
     * and reopens at exactly 04:00:00. Across midnight (start > end) the window
     * wraps, e.g. 23:00-01:00 closes during the late-night/early-morning hours.
     */
    private function isInsideScheduledWindow(): bool
    {
        $start = trim($this->settings->get('maintenance_schedule_start'));
        $end   = trim($this->settings->get('maintenance_schedule_end'));

        if ($start === '' || $end === '') {
            return false;
        }

        // Only HH:MM is accepted; anything else is treated as unconfigured.
        if (!
            preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $start, $sm)
            || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $end, $em)
        ) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Dhaka'));
        $currentMinutes = (int) $now->format('H') * 60 + (int) $now->format('i');

        $startMinutes = (int) $sm[1] * 60 + (int) $sm[2];
        $endMinutes   = (int) $em[1] * 60 + (int) $em[2];

        if ($startMinutes <= $endMinutes) {
            // Same-day window, e.g. 02:00-04:00.
            return $currentMinutes >= $startMinutes && $currentMinutes < $endMinutes;
        }

        // Overnight window wrapping past midnight, e.g. 23:00-01:00.
        return $currentMinutes >= $startMinutes || $currentMinutes < $endMinutes;
    }

    /**
     * Whether this path is exempt from the gate.
     *
     * `/admin` and `/api/admin` are matched exactly as well as by prefix: the
     * panel's own index lives at the bare path, and a bare prefix test would
     * miss it or over-match something like `/admin-tools`.
     */
    private function isOpen(string $path): bool
    {
        if (in_array($path, self::OPEN_PATHS, true)) {
            return true;
        }

        foreach (['/admin', '/api/admin'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
