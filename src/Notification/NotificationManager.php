<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Push\VapidKeys;
use App\Repository\BotConnectionRepository;
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
        // Nullable so the long list of tests that hand-builds a manager keeps
        // compiling without a sixth argument; the container always supplies it.
        private readonly ?BotConnectionRepository $bots = null,
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
        //
        // WhatsApp and Telegram are *additive* here rather than fixed by the
        // event matrix: when a person has put a number on their profile they
        // have asked to be reached on it, and the promise is that they hear
        // about everything there — not that they hear about the handful of
        // events that happened to list the channel. The matrix stays the
        // floor (webpush/fcm/telegram-for-staff), the contact columns raise it.
        $channels = array_values(array_unique([...$spec['channels'], ...$this->contactChannels($userId)]));

        // Hoisted out of the loop below and computed once: it is a per-user
        // lookup, not a per-channel one.
        $recipientIsStaff = $this->isStaff($userId);

        foreach ($channels as $channel) {
            if ($channel === 'in_app') {
                continue;
            }
            if (!$this->wantsChannel($event, $channel, $userId)) {
                continue;
            }

            $channelTemplate = $this->templates->render($event, $channel, $params, $recipientIsStaff);
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
                foreach (array_values(array_unique([...array_diff($spec['channels'], ['in_app']), ...$this->contactChannels($adminId)])) as $channel) {
                    // The admin loop deliberately skips wantsChannel() — that
                    // helper exists to hold telegram *back* from users, and
                    // running it here would stop staff getting Telegram at all.
                    // What still has to apply is the webpush gate below, since
                    // an admin's own settings switch is exactly as binding as a
                    // user's.
                    if ($channel === 'webpush' && !$this->pushAllowed($adminId)) {
                        continue;
                    }
                    // Recipients here came from adminIds(), so they are staff by
                    // construction and the seeded telegram wording is the right
                    // one — the same words this loop has always sent.
                    $channelTemplate = $this->templates->render($event, $channel, $params, true);
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
     * Channels an account can be reached on, from their profile's contact
     * columns. Presence of a column *is* the consent (see the migration):
     * nobody types a WhatsApp number into their profile by accident, and a
     * separate preference row would just be a second thing to keep in sync
     * with the first.
     *
     * The two channels are handled differently because they reach people in
     * different ways:
     *
     *   whatsapp is a number — `user.whatsapp_no` is exactly the right
     *   primitive, and the Cloud API delivers to it.
     *
     *   telegram is a *chat*: the bot can only write into a conversation the
     *   person started. A `telegram_no` in the profile may be a phone number,
     *   a handle or a username, and none of those is an address the bot can
     *   use, so a Telegram fan-out needs a live bot connection and nothing
     *   less — hence the `activeChatIds()` check here.
     *
     * One method serves both loops on purpose: the user loop adds these to the
     * event matrix, the admin loop adds them to the matrix minus `in_app`, and
     * "may this person be reached here" is the same question either way.
     *
     * @return string[]
     */
    private function contactChannels(int $userId): array
    {
        $channels = [];
        if ($this->users->contactOn('whatsapp', $userId) !== null) {
            $channels[] = 'whatsapp';
        }
        if (
            $this->users->contactOn('telegram', $userId) !== null
            && $this->bots !== null
            && $this->bots->activeChatIds($userId) !== []
        ) {
            $channels[] = 'telegram';
        }
        return $channels;
    }

    /**
     * Preference + availability gate. Absence of an override row means the
     * global default (on); a channel with no live transport never queues.
     */
    private function wantsChannel(string $event, string $channel, int $userId): bool
    {
        if ($channel === 'telegram') {
            // Staff still get Telegram unconditionally — the admin fan-out
            // below skips this helper for exactly that reason. A regular user
            // gets it only by having put a number on their profile *and* the
            // bot having a chat to answer on.
            return in_array('telegram', $this->contactChannels($userId), true);
        }
        if ($channel === 'whatsapp') {
            // Opt-in by profile: a filled-in number is the recorded consent.
            // Queued even while Meta credentials are absent (same reasoning as
            // fcm below) — the row is the integration point, and re-dispatching
            // an order-completed alert after somebody wires the token up is not
            // possible.
            return $this->users->contactOn('whatsapp', $userId) !== null;
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

    /**
     * Is this account one of the people who work here?
     *
     * Used only to pick copy: the seeded `telegram` lines were written for
     * staff (see {@see TemplateRenderer::render()}), and Phase 4 opened that
     * channel to ordinary customers. An admin who is also a customer — staff
     * do place their own orders — is still staff for this purpose, so the
     * admin-facing wording is right for them.
     */
    private function isStaff(int $userId): bool
    {
        $role = $this->db
            ->createCommand(
                "SELECT [[role]] FROM {{%user}} WHERE [[id]] = :id AND [[status]] = 'active' AND [[deleted_at]] IS NULL"
            )
            ->bindValue(':id', $userId)
            ->queryScalar();

        return in_array((string) $role, ['admin', 'staff'], true);
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
