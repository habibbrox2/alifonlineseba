<?php

declare(strict_types=1);

use App\Env;
use App\Web\SecureCookieSession;
use Yiisoft\Definitions\Reference;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Session\Session as YiiSession;
use Yiisoft\Session\SessionInterface;

/** @var array $params */

return [
    YiiSession::class => [
        '__construct()' => [
            [
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                // The Secure flag is decided per request by SecureCookieSession, because
                // the loopback origin is plain HTTP while the tunnel terminates HTTPS.
                'cookie_secure' => false,
                'name' => (string) Env::get('SESSION_NAME', 'ALIF_SESSION'),
                'use_strict_mode' => true,
            ],
            null,
        ],
    ],
    SessionInterface::class => [
        'class' => SecureCookieSession::class,
        '__construct()' => [
            Reference::to(YiiSession::class),
            Reference::to(RequestProviderInterface::class),
        ],
    ],
];
