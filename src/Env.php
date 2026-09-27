<?php

declare(strict_types=1);

namespace App;

/**
 * Central environment + application settings.
 *
 * Values come from the process environment (.env in development via phpdotenv,
 * real environment variables on shared hosting / CI).
 */
final class Env
{
    private const DEFAULTS = [
        'APP_ENV' => 'dev',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'http://localhost:8080',
        'DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=th_tools;charset=utf8mb4',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => '',
        'DB_TABLE_PREFIX' => '',
        'CACHE_PATH' => 'runtime/cache',
        'SESSION_NAME' => 'TH_SESSION',
        'THROTTLE_MAX_ATTEMPTS' => '5',
        'THROTTLE_DECAY_SECONDS' => '300',
    ];

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? self::DEFAULTS[$name] ?? $default;
        return $value === null ? null : (string) $value;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $name, int $default = 0): int
    {
        $value = self::get($name);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function isProd(): bool
    {
        return strtolower((string) self::get('APP_ENV')) === 'prod';
    }

    public static function isDev(): bool
    {
        return strtolower((string) self::get('APP_ENV')) === 'dev';
    }
}
