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
        $seeded = MessageTemplates::forEvent($event);
        $template = $this->fromDatabase($event, $channel)
            // `webpush` borrows the fcm copy rather than duplicating it. The
            // two channels address the same person through different clients,
            // so an order-completes message that reads one way on the phone
            // and another way in the browser is a bug the user has to notice
            // and work around. Re-pointing the fcm row in the admin template
            // editor would leave webpush behind, so the borrow is here.
            ?? $seeded[$channel]
            // `$seeded['fcm'] ?? $seeded['telegram']` rather than a bare
            // `$seeded['fcm']` read: three events carry webpush but no fcm copy
            // at all (topup.requested, topup.cancelled, system.alert), and
            // reading a missing key raises a warning on the way to the in_app
            // fallback that was always meant to catch it. In production that
            // warning is a log line nobody reads; in the test suite it fails the
            // dispatch outright.
            //
            // The telegram step matters for those three. They are the
            // admins-only events, so their telegram copy is the wording meant
            // for the recipient — and their recipients are staff, on the web.
            // Falling through to in_app would push "your recharge was
            // submitted, awaiting verification" at an admin who is waiting for
            // somebody else's recharge.
            ?? ($channel === 'webpush' ? ($seeded['fcm'] ?? $seeded['telegram'] ?? null) : null);
        if ($template === null) {
            // Unknown channel for a known event (or vice versa) is a config
            // bug; rather than throwing mid-dispatch, degrade to the in-app copy.
            $template = $seeded['in_app'] ?? ['title' => 'নোটিফিকেশন', 'body' => ''];
        }

        $params += [
            'username' => '',
            'amount' => '',
            'reference' => '—',
            'reason' => '',
            'service' => 'সার্ভিস',
            'method' => '',
            'user_id' => '',
            'site_name' => $this->settings->get('site_tagline', 'All Seba'),
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

        // queryOne() returns null for "no row", not false. Guarding on `=== false`
        // alone therefore fell through to reading a null array, which produced a
        // template of two empty strings instead of null — and a non-null template
        // suppresses the MessageTemplates fallback below, so an event with no row
        // in the table (a new one, say service_request.file) reached the user as a
        // blank notification. Check for both.
        if ($row === null || $row === false) {
            return null;
        }

        return ['title' => (string) $row['title'], 'body' => (string) ($row['body'] ?? '')];
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
