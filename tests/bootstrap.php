<?php

declare(strict_types=1);

/**
 * Codeception bootstrap.
 *
 * APP_ENV=test loads only the ignored .env.test.local file. This keeps local
 * test runs isolated from the developer's ordinary .env database. CI may
 * provide DB_* values directly in the process environment; dotenv does not
 * overwrite those values.
 *
 * When APP_ENV is not set, retain the normal development bootstrap behavior.
 */

$appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ''));

if (class_exists(\Dotenv\Dotenv::class)) {
    if ($appEnv === 'test') {
        // Tests must not inherit the developer's ordinary .env database.
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.test.local')->safeLoad();
        $_ENV['APP_ENV'] = 'test';
        $_SERVER['APP_ENV'] = 'test';
    } elseif ($appEnv === '') {
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

App\Environment::prepare();
