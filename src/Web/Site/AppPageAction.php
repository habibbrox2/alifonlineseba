<?php

declare(strict_types=1);

namespace App\Web\Site;

use App\Auth\Identity;
use App\Env;
use App\Notification\Push\VapidKeys;
use App\Repository\PushSubscriptionRepository;
use App\Service\AppReleaseService;
use App\Service\QrEncoder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET /app — the public landing page for the Android client.
 *
 * ## Why this page exists at all
 *
 * It is the only page a signed-out visitor can be *given*, because it is the
 * one page whose call to action is "get the app". That makes it the natural
 * home for the two things a not-yet-a-user can be offered:
 *
 *  - the APK, with a QR code so the same page works on a desktop browser
 *    (the person reading it) and on a phone (the person who will install it);
 *  - browser Web Push, which is the notification channel available to them
 *    *before* they have an account or an app.
 *
 * ## Why every failure mode degrades rather than throws
 *
 * `current()` returns null for a missing file, an unreadable path, a size
 * outside the limit or a row that was never published, and `VapidKeys::fromEnv()`
 * returns null when the deployment has no keys. Both are ordinary states on a
 * fresh install, so the page renders "coming soon" instead of a 500 — this is
 * the most-visited marketing page on the site and it must not be the one that
 * breaks.
 */
final readonly class AppPageAction
{
    /**
     * Quiet zone in modules. Four is the ISO/IEC 18004 minimum and the value
     * `QrEncoder::toSvg()` documents as required; going narrower makes the
     * symbol harder for a phone camera to acquire, which is the one thing this
     * particular QR code has to be good at.
     */
    private const QR_BORDER = 4;

    /**
     * QR modules per unit of output — 5 renders a version-3 symbol at ~185px,
     * about a fist width. Bigger and it stops being scannable at a glance on
     * a phone photographing a laptop screen.
     */
    private const QR_SCALE = 5;

    public function __construct(
        private WebViewRenderer $view,
        private AppReleaseService $app,
        private PushSubscriptionRepository $subscriptions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $release = $this->app->downloadsEnabled() ? $this->app->current() : null;
        $keys = VapidKeys::fromEnv();

        /** @var Identity|null $identity */
        $identity = $request->getAttribute('identity');

        return $this->view->render('site/app.twig', [
            'release' => $release,
            'downloadsEnabled' => $this->app->downloadsEnabled(),
            // Rendered inline rather than fetched at runtime: the client JS
            // needs the key before it can call subscribe(), and a page that
            // renders a disabled button until a second request lands is a
            // worse first impression than one that is simply correct.
            'vapidEnabled' => $keys !== null,
            'vapidPublicKey' => $keys?->publicKey(),
            // Only meaningful when signed in; zero otherwise, which the view
            // uses to decide whether to promise "your browsers are covered".
            'pushSubscriptions' => $identity instanceof Identity
                ? $this->subscriptions->countActiveForUser($identity->id)
                : 0,
            'apkQr' => $release === null ? null : $this->downloadQr(),
            'apkUrl' => $this->downloadUrl(),
            'apkSizeLabel' => $release === null ? '' : self::formatSize((int) $release['apk_size']),
        ]);
    }

    /**
     * A QR code for the absolute download URL.
     *
     * Absolute, not a path: the code is going to be photographed off a desktop
     * screen by a phone on a different network, where `/app/apk` means nothing.
     * The fallback returns null rather than throwing when the URL is unusable,
     * because a missing QR must not take the download button down with it.
     */
    private function downloadQr(): ?string
    {
        $url = $this->downloadUrl();
        if ($url === '') {
            return null;
        }

        try {
            // Foreground is the brand green and the background is opaque white
            // on purpose: a transparent background inherits whatever the page
            // is painting, and this site has a dark mode.
            return QrEncoder::encode($url)->toSvg(
                self::QR_BORDER,
                self::QR_SCALE,
                '#083f31',
                '#ffffff',
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function downloadUrl(): string
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');

        return $base === '' ? '/app/apk' : $base . '/app/apk';
    }

    /** Binary units, because that is what a phone's "Downloads" app shows. */
    private static function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
