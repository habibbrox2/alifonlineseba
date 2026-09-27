<?php

declare(strict_types=1);

use App\Env;

/** @var array $params */

return [
    SessionInterface::class => [
        'class' => Yiisoft\Session\Session::class,
        '__construct()' => [
            [
                'cookie_httponly' => '1',
                'cookie_samesite' => 'Lax',
                'cookie_secure' => str_starts_with((string) Env::get('APP_URL'), 'https://') ? '1' : '0',
                'name' => (string) Env::get('SESSION_NAME', 'TH_SESSION'),
                'use_strict_mode' => '1',
            ],
            null,
        ],
    ],
];
