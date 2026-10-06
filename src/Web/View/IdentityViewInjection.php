<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Auth\Identity;
use App\Env;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;
use Yiisoft\RequestProvider\RequestProviderInterface;

/**
 * Injects common view parameters: csrf handled by CsrfViewInjection; this adds
 * identity, unread notification count, flash message, current path and whether
 * this account has notifications switched on.
 */
final class IdentityViewInjection implements CommonParametersInjectionInterface
{
    public function __construct(
        private readonly RequestProviderInterface $requestProvider,
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
    ) {}

    public function getCommonParameters(): array
    {
        $request = $this->requestProvider->get();
        /** @var Identity|null $identity */
        $identity = $request->getAttribute('identity');

        return [
            'identity' => $identity,
            'unread' => $identity !== null ? $this->notifications->unreadCount($identity->id) : 0,
            // The account-wide push switch, read here rather than per action:
            // `partials/push-reminder.twig` renders in the shared dashboard
            // layout, so every authenticated page needs to know whether this
            // person still has notifications switched off. `/profile` passes
            // its own `pushEnabled` from the same call, so the two agree.
            // `isPushEnabled()` rather than the column, for the same reason
            // ProfileAction does it: an absent column must read as "on".
            'pushEnabled' => $identity !== null && $this->users->isPushEnabled($identity->id),
            'flash_success' => $request->getAttribute('flash_success'),
            'flash_error' => $request->getAttribute('flash_error'),
            'flash_undo' => $request->getAttribute('flash_undo'),
            'currentPath' => $request->getUri()->getPath(),
            'siteUrl' => $this->siteUrl($request),
        ];
    }

    /**
     * The domain every absolute URL in the page is built from: canonical,
     * og:url, JSON-LD, share images.
     *
     * `APP_URL` is the deployment's own domain and always wins when the
     * deployment has set it — a page reached on a subdomain or a second domain
     * must still advertise one name, because canonical following the request
     * host would split every page into two competing copies and disagree with
     * NoIndexMiddleware, which already treats the request host as a mere
     * delivery detail.
     *
     * The second half of that contract: when no APP_URL has been configured
     * at all (a new domain pointed at the app before anyone edited .env), the
     * request's own origin is the only address known to work. Falling through
     * to Env's built-in localhost default would publish
     * `http://localhost:8080` as the canonical address of a real site. A
     * hostless request — the functional-test harness builds URIs like
     * `/dashboard` — keeps that default, so nothing there changes.
     */
    private function siteUrl(ServerRequestInterface $request): string
    {
        $configured = rtrim((string) Env::get('APP_URL', ''), '/');
        if (Env::has('APP_URL')) {
            return $configured;
        }

        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return $configured;
        }

        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
        $port = $uri->getPort();

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }
}
