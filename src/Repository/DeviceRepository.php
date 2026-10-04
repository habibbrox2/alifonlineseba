<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * FCM device tokens. The device_token UNIQUE index is the load-bearing
 * constraint: it makes registration an idempotent upsert AND gives token
 * deactivation a single lookup.
 */
final class DeviceRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Idempotent registration: the same token from the same user refreshes
     * last_seen_at; a token that moved devices re-points to the new owner.
     */
    public function upsert(int $userId, string $token, string $platform, ?string $name, ?string $appVersion): int
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->db
            ->createCommand('SELECT [[id]], [[user_id]] FROM {{%notification_device}} WHERE [[device_token]] = :t LIMIT 1')
            ->bindValue(':t', $token)
            ->queryOne();

        // `queryOne()` returns null for "no row", not false — see
        // PushSubscriptionRepository::subscribe() and TemplateRenderer. Testing
        // for `=== false` alone therefore never took this branch: a first-time
        // registration fell into the update below, which matched no row, wrote
        // nothing, and returned an id from a row that did not exist. The device
        // was silently never registered, so FCM could not reach a phone that
        // had just installed the app — reported as "push does not work" with
        // nothing in any log.
        if ($existing === null || $existing === false) {
            $this->db->createCommand()->insert('{{%notification_device}}', [
                'user_id' => $userId,
                'device_token' => $token,
                'platform' => in_array($platform, ['android', 'ios'], true) ? $platform : 'android',
                'device_name' => $name !== null ? mb_substr($name, 0, 120) : null,
                'app_version' => $appVersion,
                'is_active' => 1,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            return (int) $this->db->getLastInsertID();
        }

        $this->db->createCommand()->update('{{%notification_device}}', [
            'user_id' => $userId,
            'platform' => in_array($platform, ['android', 'ios'], true) ? $platform : 'android',
            'device_name' => $name !== null ? mb_substr($name, 0, 120) : null,
            'app_version' => $appVersion,
            'is_active' => 1,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $existing['id']])->execute();

        return (int) $existing['id'];
    }

    public function deactivate(int $id, int $userId): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%notification_device}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'user_id' => $userId],
            )
            ->execute() > 0;
    }

    public function deactivateForUser(int $userId): void
    {
        $this->db
            ->createCommand()
            ->update(
                '{{%notification_device}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['user_id' => $userId],
            )
            ->execute();
    }

    /** Deactivate tokens FCM reported as dead (UNREGISTERED / invalid). */
    public function deactivateTokens(array $tokens): void
    {
        if ($tokens === []) {
            return;
        }
        $list = implode(',', array_fill(0, count($tokens), '?'));
        $this->db
            ->createCommand()
            ->update(
                '{{%notification_device}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                "[[device_token]] IN ({$list})",
                array_values($tokens),
            )
            ->execute();
    }

    /** All active FCM tokens for a user — the fan-out target list. */
    public function activeTokens(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[device_token]] FROM {{%notification_device}}'
                . ' WHERE [[user_id]] = :uid AND [[is_active]] = 1'
            )
            ->bindValue(':uid', $userId)
            ->queryColumn();

        return array_map('strval', $rows);
    }

    public function forUser(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[id]], [[platform]], [[device_name]], [[app_version]], [[is_active]], [[last_seen_at]], [[created_at]]'
                . ' FROM {{%notification_device}} WHERE [[user_id]] = :uid ORDER BY [[id]] DESC'
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        return array_map(static fn (array $row): array => $row, $rows);
    }

    /** Retention: purge deactivated rows older than N days. */
    public function purgeInactive(int $days = 90): int
    {
        return $this->db
            ->createCommand(
                'DELETE FROM {{%notification_device}} WHERE [[is_active]] = 0 AND [[updated_at]] < :cutoff'
            )
            ->bindValue(':cutoff', date('Y-m-d H:i:s', time() - $days * 86400))
            ->execute();
    }
}
