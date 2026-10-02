<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Browser Web Push subscriptions.
 *
 * The UNIQUE index on `endpoint` is the load-bearing constraint, exactly as the
 * device_token index is for FCM: the Push API hands out a brand new endpoint
 * every time a user grants permission (and often a new one on every page load,
 * across profiles and private windows), so subscribe() has to be an upsert or
 * the table fills with rows that will never receive anything.
 *
 * `user_id` is nullable because a logged-out visitor can grant notification
 * permission before they have an account — that is the whole point of the
 * banner on /app, since the visitor is exactly the person who does not have
 * the Android app yet.
 */
final class PushSubscriptionRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Idempotent registration.
     *
     * Passing null for $userId stores an anonymous subscription; calling this
     * again once the visitor logs in claims the same endpoint for that account,
     * which is what makes a notification sent after login reach the tab they
     * already had open.
     *
     * @param string|null $p256dh base64url uncompressed P-256 point (65 bytes)
     * @param string|null $auth    base64url 16-byte auth secret
     */
    public function subscribe(
        string $endpoint,
        ?string $p256dh,
        ?string $auth,
        ?int $userId = null,
        ?string $userAgent = null,
    ): int {
        $now = date('Y-m-d H:i:s');
        $existing = $this->db
            ->createCommand('SELECT [[id]], [[p256dh]], [[auth]], [[user_id]] FROM {{%push_subscription}} WHERE [[endpoint]] = :e LIMIT 1')
            ->bindValue(':e', $endpoint)
            ->queryOne();

        if ($existing === false) {
            $this->db->createCommand()->insert('{{%push_subscription}}', [
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'p256dh' => (string) $p256dh,
                'auth' => (string) $auth,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'is_active' => 1,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            return (int) $this->db->getLastInsertID();
        }

        // The browser regenerates its key pair on every subscribe(), so the
        // stored keys are stale even when the endpoint looks familiar. Only
        // overwrite them when the caller actually supplied new ones — a
        // login-claim must not blank out keys that are still good.
        $update = [
            'is_active' => 1,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
        if ($p256dh !== null && $auth !== null) {
            $update['p256dh'] = $p256dh;
            $update['auth'] = $auth;
        }
        if ($userId !== null) {
            $update['user_id'] = $userId;
        }
        if ($userAgent !== null) {
            $update['user_agent'] = mb_substr($userAgent, 0, 255);
        }

        $this->db->createCommand()
            ->update('{{%push_subscription}}', $update, ['id' => (int) $existing['id']])
            ->execute();

        return (int) $existing['id'];
    }

    /**
     * Deactivate one subscription the caller owns. Used by the unsubscribe
     * endpoint; scoping by user_id is what stops one account from silencing
     * another's browser by guessing an id.
     */
    public function unsubscribe(int $id, ?int $userId = null): bool
    {
        $condition = ['id' => $id];
        if ($userId !== null) {
            $condition['user_id'] = $userId;
        }
        return $this->db
            ->createCommand()
            ->update(
                '{{%push_subscription}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                $condition,
            )
            ->execute() > 0;
    }

    /**
     * Retire subscriptions the push service reported as gone (404/410).
     * Deactivating rather than deleting keeps the audit trail and lets a user
     * who re-grants permission get the same row back.
     *
     * @param int[] $ids
     */
    public function deactivateIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $list = implode(',', array_fill(0, count($ids), '?'));
        $this->db
            ->createCommand()
            ->update(
                '{{%push_subscription}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                "[[id]] IN ({$list})",
                array_values($ids),
            )
            ->execute();
    }

    /**
     * Every active subscription of one user — the fan-out target list.
     *
     * The key material is returned because the channel needs it to encrypt;
     * that is unavoidable for Web Push, and the rows never leave the server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForUser(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[id]], [[endpoint]], [[p256dh]], [[auth]] FROM {{%push_subscription}}'
                . ' WHERE [[user_id]] = :uid AND [[is_active]] = 1'
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        return array_map(static fn (array $row): array => $row, $rows);
    }

    /**
     * Active subscriptions with no owner.
     *
     * Reached only by broadcasts — the `/app` page tells a signed-out visitor
     * their browser is now watching, so an announcement is not a lie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeAnonymous(): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[id]], [[endpoint]], [[p256dh]], [[auth]] FROM {{%push_subscription}}'
                . ' WHERE [[user_id]] IS NULL AND [[is_active]] = 1'
            )
            ->queryAll();

        return array_map(static fn (array $row): array => $row, $rows);
    }

    /** Active subscription count for one user — shown on the /app page. */
    public function countActiveForUser(int $userId): int
    {
        return (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%push_subscription}} WHERE [[user_id]] = :uid AND [[is_active]] = 1'
            )
            ->bindValue(':uid', $userId)
            ->queryScalar();
    }

    public function findByEndpoint(string $endpoint): ?array
    {
        $row = $this->db
            ->createCommand('SELECT [[id]], [[user_id]], [[is_active]] FROM {{%push_subscription}} WHERE [[endpoint]] = :e LIMIT 1')
            ->bindValue(':e', $endpoint)
            ->queryOne();

        return $row === null ? null : (array) $row;
    }

    /**
     * Retention: drop rows that have been inactive long enough that the browser
     * certainly is not coming back. Deactivated rows are kept for a while so
     * that a user toggling the permission off and on again does not lose their
     * claim on the endpoint.
     */
    public function purgeInactive(int $days = 90): int
    {
        return $this->db
            ->createCommand('DELETE FROM {{%push_subscription}} WHERE [[is_active]] = 0 AND [[updated_at]] < :cutoff')
            ->bindValue(':cutoff', date('Y-m-d H:i:s', time() - $days * 86400))
            ->execute();
    }
}
