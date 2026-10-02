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
        'SESSION_NAME' => 'ALIF_SESSION',
        'THROTTLE_MAX_ATTEMPTS' => '5',
        'THROTTLE_DECAY_SECONDS' => '300',

        // --- Notifications (Phase 1) — empty = disabled, safe defaults ---
        'NOTIFY_QUEUE_BATCH' => '200',
        'NOTIFY_MAX_ATTEMPTS' => '3',
        'NOTIFY_BACKOFF_BASE' => '60',
        'NOTIFY_RETENTION_DAYS' => '180',

        // --- API tokens (Phase 1.5) — the APK unblocker ---
        'API_TOKEN_TTL_DAYS' => '30',
        'APP_KEY' => '',

        // --- Channels: present but disabled until credentials exist ---
        'TELEGRAM_BOT_TOKEN' => '',
        'TELEGRAM_WEBHOOK_SECRET' => '',
        'FIREBASE_CREDENTIALS_PATH' => '',
        'FIREBASE_PROJECT_ID' => '',
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
