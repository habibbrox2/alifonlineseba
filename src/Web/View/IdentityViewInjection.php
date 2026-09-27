<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Auth\Identity;
use App\Env;
use App\Repository\NotificationRepository;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;
use Yiisoft\RequestProvider\RequestProviderInterface;

/**
 * Injects common view parameters: csrf handled by CsrfViewInjection; this adds
 * identity, unread notification count, flash message and current path.
 */
final class IdentityViewInjection implements CommonParametersInjectionInterface
{
    public function __construct(
        private readonly RequestProviderInterface $requestProvider,
        private readonly NotificationRepository $notifications,
    ) {}

    public function getCommonParameters(): array
    {
        $request = $this->requestProvider->get();
        /** @var Identity|null $identity */
        $identity = $request->getAttribute('identity');

        return [
            'identity' => $identity,
            'unread' => $identity !== null ? $this->notifications->unreadCount($identity->id) : 0,
            'flash_success' => $request->getAttribute('flash_success'),
            'flash_error' => $request->getAttribute('flash_error'),
            'currentPath' => $request->getUri()->getPath(),
            'siteUrl' => rtrim((string) Env::get('APP_URL', ''), '/'),
        ];
    }
}
