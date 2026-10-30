<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Test runs use an isolated ignored env file; production continues to use process env.
$appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ''));

if (class_exists(\Dotenv\Dotenv::class)) {
    if ($appEnv === 'test') {
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.test.local')->safeLoad();
        $_ENV['APP_ENV'] = 'test';
        $_SERVER['APP_ENV'] = 'test';
    } elseif ($appEnv === '') {
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}
Environment::prepare();
