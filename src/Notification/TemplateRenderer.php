<?php

declare(strict_types=1);

namespace App\Notification;

use App\Repository\SettingsRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Event + channel → title/body, resolved from the notification_template table
 * with the compiled-in {@see MessageTemplates} copy as fallback. Keeping the
 * fallback is what makes a missing or truncated template table a non-event:
 * notifications degrade to the seeded copy instead of failing.
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param array<string, mixed> $params
     * @return array{title: string, body: string}
     */
    public function render(string $event, string $channel, array $params): array
    {
        $template = $this->fromDatabase($event, $channel) ?? MessageTemplates::forEvent($event)[$channel] ?? null;
        if ($template === null) {
            // Unknown channel for a known event (or vice versa) is a config
            // bug; rather than throwing mid-dispatch, degrade to the in-app copy.
            $template = MessageTemplates::forEvent($event)['in_app'] ?? ['title' => 'নোটিফিকেশন', 'body' => ''];
        }

        $params += [
            'username' => '',
            'amount' => '',
            'reference' => '—',
            'reason' => '',
            'service' => 'সার্ভিস',
            'method' => '',
            'user_id' => '',
            'site_name' => $this->settings->get('site_tagline', 'Alif Tools'),
        ];

        return [
            'title' => self::interpolate($template['title'], $params),
            'body' => self::interpolate($template['body'], $params),
        ];
    }

    /** @return array{title: string, body: string}|null */
    private function fromDatabase(string $event, string $channel): ?array
    {
        try {
            $row = $this->db
                ->createCommand(
                    'SELECT [[title]], [[body]] FROM {{%notification_template}}'
                    . " WHERE [[event]] = :e AND [[channel]] = :c AND [[locale]] = 'bn' LIMIT 1"
                )
                ->bindValues([':e' => $event, ':c' => $channel])
                ->queryOne();
        } catch (\Throwable) {
            // A broken template table must not break the money path.
            return null;
        }

        return $row === false ? null : ['title' => (string) $row['title'], 'body' => (string) ($row['body'] ?? '')];
    }

    /**
     * {placeholder} substitution. Values are inserted as-is because every
     * channel driver escapes for its own transport (JSON for FCM/Telegram,
     * HTML-escaped for the web UI) — double-encoding here would corrupt copy.
     *
     * @param array<string, mixed> $params
     */
    private static function interpolate(string $text, array $params): string
    {
        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => (string) ($params[$m[1]] ?? $m[0]),
            $text,
        ) ?? $text;
    }
}
