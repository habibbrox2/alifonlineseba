<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Push\VapidKeys;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The one entry point business code uses to notify. dispatch() writes the
 * in-app row (synchronous, cheap, always) and one queue row per channel
 * (best-effort, drained by the cron worker). No network I/O happens here —
 * that is the whole point: the money path must not wait on a provider.
 */
final class NotificationManager
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly QueueRepository $queue,
        private readonly TemplateRenderer $templates,
        private readonly UserRepository $users,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Notify about an event.
     *
     * @param array<string, mixed> $params template placeholders ({amount} …)
     * @param string|null $link deep-link path for the in-app row
     * @param array<string, mixed> $pushData extra FCM data payload (reference only, no ids)
     * @param string|null $adminLink deep-link for the *staff* copy of a
     * `also_admins` event, when it is not the page the subject wants. A new
     * service order lands on `/service-history` for the customer and on the
     * admin queue for staff: one URL cannot be both.
     */
    public function dispatch(
        string $event,
        int $userId,
        array $params = [],
        ?string $link = null,
        array $pushData = [],
        ?string $adminLink = null,
    ): void {
        $spec = NotificationEvent::spec($event);
        $priority = $spec['priority'];

        // In-app: synchronous, never fails the caller.
        $template = $this->templates->render($event, 'in_app', $params);
        $notificationId = null;
        try {
            $notificationId = $this->notifications->create($userId, $template['title'], $template['body'], $this->typeFor($event));
            $this->db->createCommand()->update(
                '{{%notification}}',
                ['event' => $event, 'link' => $link, 'priority' => $priority],
                ['id' => $notificationId],
            )->execute();
        } catch (\Throwable) {
            // An in-app write failure must not stop a recharge approval.
        }

        // Channel fan-out: one queue row per configured channel the user can
        // actually receive. in_app is done; the rest are queued for the worker.
        foreach ($spec['channels'] as $channel) {
            if ($channel === 'in_app') {
                continue;
            }
            if (!$this->wantsChannel($event, $channel, $userId)) {
                continue;
            }

            $channelTemplate = $this->templates->render($event, $channel, $params);
            $this->queue->enqueue([
                'event' => $event,
                'user_id' => $userId,
                'channel' => $channel,
                'notification_id' => $notificationId ?? null,
                'max_attempts' => $channel === 'whatsapp' ? 5 : (int) \App\Env::int('NOTIFY_MAX_ATTEMPTS', 3),
                'payload' => [
                    'title' => $channelTemplate['title'],
                    'body' => $channelTemplate['body'],
                    'data' => self::payloadData($pushData, $event, $link),
                    'priority' => $priority,
                ],
                'dedupe_key' => self::dedupeKey($event, $userId, $channel, $params),
            ]);
        }

        // Admin fan-out (audit: capped to staff/admin; in production prefer a
        // digest — see audit §16). `user_id` in params names the acting user so
        // admin copy can reference them; the actor themself is skipped as a
        // recipient below.
        if ($spec['also_admins']) {
            // A system alert is addressed *to* a member of staff, and the
            // caller chose that person deliberately (PushWatchCommand picks
            // firstStaffId()) precisely so somebody gets told. Filtering the
            // dispatch target out of the fan-out therefore does not merely
            // lose a duplicate, it loses the only recipient — and on a
            // single-staff box, where the target *is* the whole admin list,
            // it loses every delivery: the watcher would announce a new
            // browser to nobody, in app and over Telegram alike.
            //
            // For every other event the target is the subject rather than the
            // audience: an admin approving their own recharge must not be told
            // about it, which is the rule the self-skip was written for.
            $targetIsRecipient = $event === NotificationEvent::SYSTEM_ALERT;

            foreach ($this->adminIds() as $adminId) {
                $isTarget = $adminId === $userId;
                if ($isTarget && !$targetIsRecipient) {
                    continue; // an admin testing their own recharge pings no self
                }
                // The target's in-app row was written at the top of this
                // method. Copying it into the fan-out would put one alert in
                // their inbox twice, so only the other recipients get a copy —
                // the target still gets every queued channel below.
                if (!$isTarget) {
                    $adminTemplate = $this->templates->render($event, 'in_app', $params);
                    try {
                        $adminNotificationId = $this->notifications->create(
                            $adminId,
                            $adminTemplate['title'],
                            $adminTemplate['body'],
                            $this->typeFor($event),
                        );
                        // Stamp the event too, so the admin's row is queryable by
                        // event like the user's — without it, admin filters and
                        // the health cards silently miss every fan-out row.
                        $this->db->createCommand()->update(
                            '{{%notification}}',
                            [
                                'event' => $event,
                                'link' => $adminLink ?? $link,
                                'priority' => $priority,
                            ],
                            ['id' => $adminNotificationId],
                        )->execute();
                    } catch (\Throwable) {
                    }
                }
                foreach (array_diff($spec['channels'], ['in_app']) as $channel) {
                    // The admin loop deliberately skips wantsChannel() — that
                    // helper exists to hold telegram *back* from users, and
                    // running it here would stop staff getting Telegram at all.
                    // What still has to apply is the webpush gate below, since
                    // an admin's own settings switch is exactly as binding as a
                    // user's.
                    if ($channel === 'webpush' && !$this->pushAllowed($adminId)) {
                        continue;
                    }
                    $channelTemplate = $this->templates->render($event, $channel, $params);
                    $this->queue->enqueue([
                        'event' => $event,
                        'user_id' => $adminId,
                        'channel' => $channel,
                        'max_attempts' => (int) \App\Env::int('NOTIFY_MAX_ATTEMPTS', 3),
                        'payload' => [
                            'title' => $channelTemplate['title'],
                            'body' => $channelTemplate['body'],
                            'data' => self::payloadData($pushData, $event, $adminLink ?? $link),
                            'priority' => $priority,
                        ],
                        'dedupe_key' => self::dedupeKey($event, $adminId, $channel, $params),
                    ]);
                }
            }
        }
    }

    /**
     * The `data` object that rides along with every queued channel.
     *
     * ## Why `url` has to be here and not just in the `link` column
     *
     * `push-sw.js` has no database and no CSRF token: the only thing it can
     * read is the push body, and it reads the click target out of
     * `parsed.data.url`. An in-app row that carries a beautiful `link` is
     * therefore invisible to a person tapping the banner — the notification
     * lands, and the click opens `/`.
     *
     * `data` is the one payload shape shared by webpush and fcm, so a single
     * key here fixes both transports. `url` is omitted entirely when there is
     * no link, which is how the worker tells "no target" (fall back to `/`)
     * from "target the literal string /".
     *
     * @param array<string, mixed> $pushData
     * @return array<string, mixed>
     */
    private static function payloadData(array $pushData, string $event, ?string $link): array
    {
        $data = $pushData;
        $data['event'] = $event;
        if ($link !== null && $link !== '') {
            $data['url'] = $link;
        }

        return $data;
    }

    /**
     * Preference + availability gate. Absence of an override row means the
     * global default (on); a channel with no live transport never queues.
     */
    private function wantsChannel(string $event, string $channel, int $userId): bool
    {
        if ($channel === 'telegram') {
            // Telegram is admins-only; the admin loop handles it. Users never
            // get customer copy over a third-party chat platform.
            return false;
        }
        if ($channel === 'whatsapp') {
            // Opt-in only. Phase 5 wires channel_connection; until then, off.
            return false;
        }
        if ($channel === 'webpush') {
            return $this->pushAllowed($userId);
        }
        // fcm: queued even while credentials are absent. The worker dead-letters
        // a dormant channel once per job instead of losing the event — the row
        // is the integration point Phase 2 consumes, and re-dispatching a
        // business action after the fact is not possible.

        return true;
    }

    /**
     * Should a webpush job be queued for this account at all?
     *
     * Two conditions, and both of them are about not creating work:
     *
     *  - The account has to still want push. `WebPushChannel::send()` checks
     *    this again at send time, but a job enqueued before the user pressed
     *    "off" is already in the table by then, and re-checking at the door
     *    only stops the message, not the row.
     *  - The deployment has to have VAPID keys. Unlike fcm — which is queued
     *    even while its credentials are missing, because the row is the
     *    integration point a later phase consumes — a webpush job on a server
     *    with no keys can never be delivered by anybody, ever. Queuing it just
     *    fills `notification_queue` with rows that dead-letter on every tick.
     */
    private function pushAllowed(int $userId): bool
    {
        return $this->users->isPushEnabled($userId) && VapidKeys::fromEnv() !== null;
    }

    /** @return int[] */
    private function adminIds(): array
    {
        $rows = $this->db
            ->createCommand(
                "SELECT [[id]] FROM {{%user}} WHERE [[role]] IN ('admin','staff')"
                . " AND [[status]] = 'active' AND [[deleted_at]] IS NULL"
            )
            ->queryColumn();

        return array_map('intval', $rows);
    }

    private function typeFor(string $event): string
    {
        return match (true) {
            str_contains($event, 'approved') || str_contains($event, 'completed') => 'success',
            str_contains($event, 'rejected') || str_contains($event, 'failed') => 'danger',
            str_contains($event, 'cancelled') => 'warning',
            default => 'info',
        };
    }

    /**
     * sha1(event|user|channel|entity) — the idempotency key. Entity comes from
     * the params the caller passes (reference / topup id), so a replayed
     * approval with the same reference cannot enqueue twice.
     *
     * @param array<string, mixed> $params
     */
    private static function dedupeKey(string $event, int $userId, string $channel, array $params): string
    {
        $entity = (string) ($params['reference'] ?? '') . '|' . (string) ($params['topup_id'] ?? '')
            . '|' . (string) ($params['tx_id'] ?? '');

        return sha1($event . '|' . $userId . '|' . $channel . '|' . $entity);
    }
}
