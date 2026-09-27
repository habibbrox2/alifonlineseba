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
                'cookie_httponly' => '1',
                'cookie_samesite' => 'Lax',
                // The Secure flag is decided per request by SecureCookieSession, because
                // the loopback origin is plain HTTP while the tunnel terminates HTTPS.
                'cookie_secure' => '0',
                'name' => (string) Env::get('SESSION_NAME', 'TH_SESSION'),
                'use_strict_mode' => '1',
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
