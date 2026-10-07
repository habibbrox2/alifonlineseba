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
        'APP_URL' => 'https://beta.allseba.online',
        // The one host that search engines are allowed to index. Empty = "same
        // as APP_URL". It only differs while a staging/beta copy of this app
        // is being published from elsewhere (beta.allseba.online), where APP_URL
        // must point at the copy — its own URLs, sitemap, deep links — but every
        // page must still answer X-Robots-Tag: noindex so the copy never
        // competes with production in the index. See App\Web\NoIndexMiddleware.
        'CANONICAL_URL' => '',
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

        // --- Web Push (VAPID / RFC 8292) — both empty = channel disabled ---
        // VAPID_SUBJECT must be a mailto: or https: URI identifying the sender;
        // push services contact it about a revoked subscription.
        'VAPID_SUBJECT' => '',
        // The raw 32-byte P-256 scalar, base64url encoded — a PEM would need
        // newlines, which no dotenv parser preserves. Generate a pair with:
        //   php scripts/generate-vapid-keys.php mailto:ops@example.com
        'VAPID_PRIVATE_KEY' => '',
        // How long a push service may hold a message for an offline device, and
        // whether to mark it urgent. Urgency > 5 becomes `high`, which makes
        // the service wake the device and is billed/rationed accordingly.
        'WEB_PUSH_TTL' => '86400',
        'WEB_PUSH_URGENCY' => '5',

        // --- Android app distribution ---
        // Set to 0 to take /app/apk offline (e.g. while a build is broken)
        // without unpublishing the release record.
        'APP_DOWNLOADS_ENABLED' => 'true',

        // --- Trusted Web Activity (the /app APK) ---
        // Without both of these, /.well-known/assetlinks.json answers 404 and
        // the installed app runs in a Custom Tab with a browser bar instead of
        // full screen. TWA_ORIGIN must be the bare https origin — no path, no
        // trailing slash — and is never taken from the request's Host header.
        'TWA_ORIGIN' => '',
        // `package@SHA256:AA:BB:…` pairs, comma or newline separated; the same
        // package may appear more than once (debug + release certificates).
        // Run `php yii app:twa:fingerprints` to produce the value.
        'TWA_FINGERPRINTS' => '',
    ];

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? self::DEFAULTS[$name] ?? $default;
        return $value === null ? null : (string) $value;
    }

    /**
     * Whether the deployment actually set this name, as opposed to it merely
     * having a built-in default.
     *
     * Needed by anything that must distinguish "configured to X" from "nobody
     * said": a domain pointed at the app before anyone edited .env reads as
     * the localhost default through get(), which is a fine internal fallback
     * and a terrible public URL.
     */
    public static function has(string $name): bool
    {
        return isset($_ENV[$name]) || isset($_SERVER[$name]);
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
