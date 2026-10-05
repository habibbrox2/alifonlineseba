<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Auth\Identity;
use App\Env;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
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
            'siteUrl' => rtrim((string) Env::get('APP_URL', ''), '/'),
        ];
    }
}
