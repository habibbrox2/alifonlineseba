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
 *
 * Copy is audience-sensitive on exactly one channel. `telegram` exists in the
 * seed data only for `also_admins` events and was written for staff; once
 * profile fan-out made Telegram a customer channel, render() takes a
 * `$staffAudience` flag so a customer never receives the wording meant for the
 * person who processes their order. Every other channel reads the same for
 * both audiences, which is why the flag defaults to staff and is passed
 * explicitly only from the user-side fan-out.
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param array<string, mixed> $params
     * @param bool $staffAudience whether the copy is being written for somebody
     * who works here. See the note on `telegram` below — it is the only channel
     * whose wording depends on this.
     * @return array{title: string, body: string}
     */
    public function render(string $event, string $channel, array $params, bool $staffAudience = true): array
    {
        $seeded = MessageTemplates::forEvent($event);

        // Every event carrying seeded `telegram` copy is an `also_admins` one,
        // because until profile fan-out Telegram was a staff-only channel and
        // the wording was written accordingly: "নতুন সার্ভিস অনুরোধ — … —
        // ব্যবহারকারী #41" is a line for the person about to *process* that
        // order. Fan-out turns Telegram into a customer channel, and handing
        // that line to the customer who just placed the order is worse than
        // sending them nothing: it reads as a message from a stranger who has
        // their order number and their user id. So for a non-staff recipient the
        // telegram copy is not a fallback candidate at all — the same seed rows
        // in the editable table included, since an admin editing
        // service_request.created/telegram is editing staff wording too.
        //
        // Unsetting it before the lookup closes the second door as well: the
        // `whatsapp` borrow below reaches for `$seeded['telegram']` when an
        // event has no fcm copy, which would have re-imported the staff line
        // through a different channel.
        if (!$staffAudience) {
            unset($seeded['telegram']);
        }

        $template = ($staffAudience || $channel !== 'telegram' ? $this->fromDatabase($event, $channel) : null)
            // `webpush` borrows the fcm copy rather than duplicating it. The
            // two channels address the same person through different clients,
            // so an order-completes message that reads one way on the phone
            // and another way in the browser is a bug the user has to notice
            // and work around. Re-pointing the fcm row in the admin template
            // editor would leave webpush behind, so the borrow is here.
            ?? ($seeded[$channel] ?? null)
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
            //
            // A user or admin who has put a number on their profile gets the
            // full spread, but seed data ships without per-channel copies for
            // every event, so any missing copy falls back to the person's
            // *own* inbox copy rather than that exact wording disappearing
            // entirely. A WhatsApp body that ends up saying "Notification"
            // is far better than not arriving at all.
            ?? ($channel === 'webpush' || $channel === 'whatsapp'
                ? ($seeded[$channel] ?? ($seeded['fcm'] ?? $seeded['telegram'] ?? null))
                : null);
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
