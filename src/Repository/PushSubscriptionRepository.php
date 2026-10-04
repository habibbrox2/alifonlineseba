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

        // `AbstractCommand::queryOne()` is typed `?array` — an empty result set
        // comes back as null and has never come back as false. Comparing
        // against `false` here sent every first-time subscriber down the update
        // branch with $existing === null, which is a 500 at `(int) $existing['id']`
        // and no row written at all. See AppReleaseRepository for the same note.
        if ($existing === null) {
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

    /**
     * Turn every one of a user's browsers off in a single statement.
     *
     * This is what the account-wide switch calls, and it is why the setting is
     * a real off switch rather than a flag that merely hides notifications:
     * `send()` re-checks the flag too, but deactivating the rows means a
     * browser that is still holding a live subscription stops being a target
     * for a broadcast and stops showing up as "connected" everywhere else.
     *
     * @return int rows actually changed
     */
    public function deactivateAllForUser(int $userId): int
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%push_subscription}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['user_id' => $userId, 'is_active' => 1],
            )
            ->execute();
    }

    /**
     * Every browser one account has ever connected, newest first, for the
     * device list on the settings page.
     *
     * Deactivated rows are included on purpose. "You turned this off on your
     * phone in March" is exactly the question a person is looking at the list
     * to answer, so hiding the row would make the list lie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 20): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[id]], [[user_agent]], [[is_active]], [[last_seen_at]], [[created_at]]'
                . ' FROM {{%push_subscription}} WHERE [[user_id]] = :uid'
                . ' ORDER BY [[is_active]] DESC, [[created_at]] DESC LIMIT ' . max(1, $limit)
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        return array_map(
            static fn (array $row): array => $row + ['label' => self::describeBrowser((string) ($row['user_agent'] ?? ''))],
            array_map(static fn ($row): array => (array) $row, $rows),
        );
    }

    /**
     * "Chrome · Windows" out of a raw User-Agent, for the device list.
     *
     * A raw UA is forty words of gibberish that identifies nothing and changes
     * every few months; two short labels are enough for somebody to recognise
     * "that's my laptop". Unknown agents degrade to a single honest word rather
     * than an empty cell, because an empty row in a device list reads as a bug.
     */
    public static function describeBrowser(string $userAgent): string
    {
        if (trim($userAgent) === '') {
            return 'অজানা ব্রাউজার';
        }

        $browser = 'ব্রাউজার';
        foreach ([
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Chrome' => 'Chrome',
            'Firefox' => 'Firefox',
            'Safari' => 'Safari',
        ] as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                $browser = $label;
                break;
            }
        }

        $os = 'অজানা';
        foreach ([
            'Windows' => 'Windows',
            'Android' => 'Android',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Mac OS' => 'Mac',
            'Linux' => 'Linux',
        ] as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                $os = $label;
                break;
            }
        }

        return $browser . ' · ' . $os;
    }

    /**
     * Row counts for `app:webpush:check`.
     *
     * An operator's first question is "are there any browsers at all?", and
     * the split matters more than the total: owned rows are the ones the
     * per-account switch governs, anonymous ones are the /app banner, and
     * inactive rows are the tombstones the settings page still lists.
     *
     * One SELECT with SUM(CASE...) rather than three COUNT queries — the
     * table is small, but a check command should not feel like a report.
     *
     * @return array{total: int, active: int, owned: int, anonymous: int, inactive: int}
     */
    public function summary(): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT COUNT(*) AS [[total]],'
                . ' SUM(CASE WHEN [[is_active]] = 1 THEN 1 ELSE 0 END) AS [[active]],'
                . ' SUM(CASE WHEN [[is_active]] = 1 AND [[user_id]] IS NOT NULL THEN 1 ELSE 0 END) AS [[owned]],'
                . ' SUM(CASE WHEN [[is_active]] = 1 AND [[user_id]] IS NULL THEN 1 ELSE 0 END) AS [[anonymous]]'
                . ' FROM {{%push_subscription}}'
            )
            ->queryOne() ?? [];

        // SUM() over an empty table is NULL, not 0 — hence the casts.
        $active = (int) ($row['active'] ?? 0);
        $owned = (int) ($row['owned'] ?? 0);
        $total = (int) ($row['total'] ?? 0);

        return [
            'total' => $total,
            'active' => $active,
            'owned' => $owned,
            'anonymous' => (int) ($row['anonymous'] ?? 0),
            'inactive' => max(0, $total - $active),
        ];
    }

    /**
     * The stored keys behind one endpoint, or null when there is no active row
     * for it. Used by `app:webpush:check --endpoint=…`, which cannot encrypt
     * for a browser whose p256dh/auth it does not hold.
     *
     * @return array<string, mixed>|null
     */
    public function activeByEndpoint(string $endpoint): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT [[id]], [[user_id]], [[endpoint]], [[p256dh]], [[auth]]'
                . ' FROM {{%push_subscription}} WHERE [[endpoint]] = :e AND [[is_active]] = 1 LIMIT 1'
            )
            ->bindValue(':e', $endpoint)
            ->queryOne();

        return $row === null ? null : (array) $row;
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
     * Every row, keyed by id, for `app:webpush:watch`.
     *
     * This is the one reader that wants the whole table including the inactive
     * tombstones, and it is a different question from anything else here: the
     * watcher is not looking for subscriptions to push *to*, it is looking for
     * rows whose state just changed, so it has to see a browser go away as
     * well as arrive. `is_active` and `user_id` come along because those two
     * columns are the entire difference between "a browser subscribed" and "the
     * same browser re-posted its subscription again on this page load".
     *
     * No key material: the watcher prints browser, host and owner, and has no
     * use for p256dh/auth.
     *
     * `is_active` comes back as the comparison `= 1` rather than as the bare
     * column because a `bit(1)` arrives over the wire as whatever the driver
     * feels like — 1, '1', or the raw byte 0x01, all of which cast differently
     * in PHP. The server does the comparison, exactly as summary() does, and
     * the watcher gets an ordinary integer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForWatching(): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[id]], [[user_id]], [[endpoint]], [[user_agent]], ([[is_active]] = 1) AS [[active]], [[created_at]]'
                . ' FROM {{%push_subscription}}'
            )
            ->queryAll();

        $byId = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
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
