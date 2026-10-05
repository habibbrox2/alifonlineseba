<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\ReferralCode;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Thin query layer over Yii DB — all statements parameter-bound.
 *
 * Rows carry a `deleted_at` timestamp. NULL means the account is live; a
 * timestamp means it was trashed by an admin and is hidden from every lookup
 * (and from login) until it is restored. Pass $withDeleted to reach trashed
 * rows deliberately, e.g. for the admin trash list.
 */
final class UserRepository
{
    /** Paginate/list only live accounts. */
    public const DELETED_EXCLUDE = 'exclude';

    /** Paginate/list only trashed accounts. */
    public const DELETED_ONLY = 'only';

    /** Paginate/list live and trashed accounts together. */
    public const DELETED_ALL = 'all';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function findById(int $id, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM {{%user}} WHERE [[id]] = :id';
        if (!$withDeleted) {
            $sql .= ' AND [[deleted_at]] IS NULL';
        }
        $row = $this->db
            ->createCommand($sql)
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function findByIdentifier(string $identifier, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM {{%user}} WHERE ([[username]] = :v OR [[phone]] = :v)';
        if (!$withDeleted) {
            $sql .= ' AND [[deleted_at]] IS NULL';
        }
        $row = $this->db
            ->createCommand($sql . ' LIMIT 1')
            ->bindValue(':v', $identifier)
            ->queryOne();
        return $row === false ? null : $row;
    }

    /**
     * The live account that already claims a contact channel, or null.
     *
     * Used by the profile form to refuse a WhatsApp/Telegram number that belongs
     * to somebody else — notifications are routed by these columns, so two
     * accounts claiming one number would split a person's alerts between them.
     * Uniqueness is enforced here rather than by an index because a household
     * sharing a single WhatsApp line is legitimate and a UNIQUE constraint
     * would refuse the second account outright.
     */
    public function findByContact(string $channel, string $value): ?int
    {
        $column = match ($channel) {
            'whatsapp' => 'whatsapp_no',
            'telegram' => 'telegram_no',
            'phone' => 'phone',
            'email' => 'email',
            default => null,
        };
        if ($column === null || $value === '') {
            return null;
        }

        $row = $this->db
            ->createCommand(
                "SELECT [[id]] FROM {{%user}} WHERE [[{$column}]] = :v AND [[deleted_at]] IS NULL LIMIT 1"
            )
            ->bindValue(':v', $value)
            ->queryScalar();

        return $row === false || $row === null ? null : (int) $row;
    }

    /**
     * Where a user can be reached on a messaging channel, or null.
     *
     * Absent columns read as null rather than throwing: the window between
     * `git pull` and `yii migrate` must not turn every notification dispatch
     * into a 500.
     */
    public function contactOn(string $channel, int $userId): ?string
    {
        $column = match ($channel) {
            'whatsapp' => 'whatsapp_no',
            'telegram' => 'telegram_no',
            default => null,
        };
        if ($column === null) {
            return null;
        }

        try {
            $value = $this->db
                ->createCommand("SELECT [[{$column}]] FROM {{%user}} WHERE [[id]] = :id")
                ->bindValue(':id', $userId)
                ->queryScalar();
        } catch (\Throwable) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }

    /**
     * These deliberately ignore `deleted_at`: username / phone / email carry
     * UNIQUE indexes, so a trashed user still reserves theirs. Re-creating the
     * account is only possible by restoring it.
     */
    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        return $this->exists('{{%user}}', 'username', $username, $exceptId);
    }

    public function phoneExists(string $phone, ?int $exceptId = null): bool
    {
        return $this->exists('{{%user}}', 'phone', $phone, $exceptId);
    }

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%user}}', [
            'username' => $row['username'],
            'phone' => $row['phone'],
            'email' => $row['email'] ?? null,
            'password_hash' => $row['password_hash'],
            'status' => $row['status'] ?? 'active',
            'role' => $row['role'] ?? 'user',
            'balance' => $row['balance'] ?? 0,
            // Every account gets its API key on creation; rows predating the
            // column are covered lazily by ensureApiKey().
            'api_key' => self::generateApiKey(),
            // Same deal for the referral code: minted here, and lazily by
            // ensureReferralCode() for any account created before the column
            // existed and never opened the referral page.
            'referral_code' => ReferralCode::generate(),
            'referred_by' => isset($row['referred_by']) ? (int) $row['referred_by'] : null,
            'referred_at' => !empty($row['referred_by']) ? date('Y-m-d H:i:s') : null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /** AL-prefixed 8-hex key, All Seba format (AL_E5B6A404). */
    public static function generateApiKey(): string
    {
        return 'AL_' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Return the user's API key, generating and persisting one on first use.
     *
     * Accounts created before the api_key column existed have none; rather
     * than a one-off backfill migration, the first page that shows the key
     * mints it. Safe to call repeatedly — only writes when the column is NULL.
     */
    public function ensureApiKey(int $id): string
    {
        $row = $this->db
            ->createCommand('SELECT [[api_key]] FROM {{%user}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryScalar();

        if (is_string($row) && $row !== '') {
            return $row;
        }

        $key = self::generateApiKey();
        $this->db
            ->createCommand()
            ->update('{{%user}}', ['api_key' => $key, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id])
            ->execute();

        return $key;
    }

    /**
     * The user's referral code, minting and persisting one on first use.
     *
     * Mirrors ensureApiKey(): a code is generated on insert, but a lost
     * INSERT/update race (or a row that predates the column) would leave the
     * referral page with nothing to show and nothing to share, so the code is
     * also regenerated lazily here. Only writes when the column is NULL or
     * blank, so a user who already shared their code never sees it change.
     */
    public function ensureReferralCode(int $id): string
    {
        $row = $this->db
            ->createCommand('SELECT [[referral_code]] FROM {{%user}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryScalar();

        if (is_string($row) && $row !== '') {
            return $row;
        }

        $code = ReferralCode::generate();
        $this->db
            ->createCommand()
            ->update('{{%user}}', ['referral_code' => $code, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id])
            ->execute();

        return $code;
    }

    /**
     * The active user a referral code belongs to, or null.
     *
     * Soft-deleted and disabled accounts are excluded here rather than at
     * signup: a code that stops resolving the moment the account is suspended
     * is what you want, and it means one check covers every entry point.
     */
    public function findByReferralCode(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        $row = $this->db
            ->createCommand(
                'SELECT * FROM {{%user}}'
                . ' WHERE [[referral_code]] = :code AND [[deleted_at]] IS NULL AND [[status]] = :status'
                . ' AND [[role]] <> :role LIMIT 1'
            )
            ->bindValues([':code' => $code, ':status' => 'active', ':role' => 'admin'])
            ->queryOne();

        return $row === false ? null : $row;
    }

    /** The user's remaining free searches (demo freebie allowance). */
    public function freeSearches(int $id): int
    {
        $value = $this->db
            ->createCommand('SELECT [[free_searches]] FROM {{%user}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryScalar();

        return is_numeric($value) ? (int) $value : 0;
    }

    /** Atomically take one free search. Returns false when none are left. */
    public function consumeFreeSearch(int $id): bool
    {
        // The WHERE guard makes the decrement single-shot even if two requests
        // race: only one of them can move a row from 1 to 0.
        $affected = $this->db
            ->createCommand(
                'UPDATE {{%user}} SET [[free_searches]] = [[free_searches]] - 1, [[updated_at]] = :now'
                . ' WHERE [[id]] = :id AND [[free_searches]] > 0'
            )
            ->bindValues([':now' => date('Y-m-d H:i:s'), ':id' => $id])
            ->execute();

        return $affected > 0;
    }

    /**
     * Move a user to the trash. The row stays, so their balance, transactions
     * and activity log survive a restore. Returns false if they were already
     * trashed or do not exist.
     */
    public function softDelete(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%user}}',
                ['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'deleted_at' => null],
            )
            ->execute() > 0;
    }

    /**
     * Bring a trashed user back. Returns false if they were not trashed.
     *
     * The condition is a raw SQL string on purpose: the array condition
     * ['deleted_at' => ['<>', null]] does NOT compile to IS NOT NULL, it
     * expands to "deleted_at = '<>' OR deleted_at IS NULL".
     */
    public function restore(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%user}}',
                ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')],
                '[[id]] = :id AND [[deleted_at]] IS NOT NULL',
            )
            ->bindValue(':id', $id)
            ->execute() > 0;
    }

    /** Empty the trash. Returns how many users were restored. */
    public function restoreAll(): int
    {
        return $this->db
            ->createCommand()
            ->update('{{%user}}', ['deleted_at' => null], '[[deleted_at]] IS NOT NULL')
            ->execute();
    }

    public function countTrashed(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%user}} WHERE [[deleted_at]] IS NOT NULL')
            ->queryScalar();
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%user}}', $values, ['id' => $id])->execute();
    }

    public function touchLastLogin(int $id): void
    {
        $this->update($id, ['last_login_at' => date('Y-m-d H:i:s')]);
    }

    /** Atomically add a signed amount to the user's balance (negative deducts). */
    public function adjustBalance(int $id, float $delta): float
    {
        $this->db
            ->createCommand(
                'UPDATE {{%user}} SET [[balance]] = [[balance]] + :delta, [[updated_at]] = :now WHERE [[id]] = :id'
            )
            ->bindValues([
                ':delta' => $delta,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
            ])
            ->execute();

        $row = $this->db
            ->createCommand('SELECT [[balance]] FROM {{%user}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryScalar();
        return (float) ($row ?? 0);
    }

    /** Whitelisted sortable columns => SQL column expression. */
    public const SORTABLE = ['id', 'username', 'balance', 'role', 'status', 'last_login_at', 'created_at'];

    /** Role => Bengali label, for the roster and the user list. */
    public const ROLE_LABELS = [
        'superadmin' => 'সুপারএডমিন',
        'admin' => 'এডমিন',
        'staff' => 'স্টাফ',
        'user' => 'ইউজার',
    ];

    /**
     * @param string $deleted One of self::DELETED_EXCLUDE (default), DELETED_ONLY, DELETED_ALL.
     * @return array{rows: array, total: int}
     */
    public function paginate(
        int $page,
        int $perPage,
        string $q = '',
        string $sort = 'id',
        string $dir = 'desc',
        string $deleted = self::DELETED_EXCLUDE,
    ): array {
        $offset = max(0, ($page - 1) * $perPage);
        $conditions = [];
        $params = [];

        if ($deleted === self::DELETED_ONLY) {
            $conditions[] = '[[deleted_at]] IS NOT NULL';
        } elseif ($deleted !== self::DELETED_ALL) {
            $conditions[] = '[[deleted_at]] IS NULL';
        }

        if ($q !== '') {
            $conditions[] = '([[username]] LIKE :q OR [[phone]] LIKE :q OR [[email]] LIKE :q)';
            $params[':q'] = "%{$q}%";
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        [$sortCol, $dirSql] = $this->sortClause($sort, $dir, self::SORTABLE, 'id');
        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%user}} {$where}")
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                "SELECT * FROM {{%user}} {$where} ORDER BY {$sortCol} {$dirSql} LIMIT {$perPage} OFFSET {$offset}"
            )
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Build a safe "column dir" ORDER BY fragment from a whitelist.
     *
     * @param array<string> $allowed
     * @return array{0:string,1:string} quoted column + ASC/DESC
     */
    private function sortClause(string $sort, string $dir, array $allowed, string $default): array
    {
        $col = in_array($sort, $allowed, true) ? $sort : $default;
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return ['[[' . $col . ']]', $direction];
    }

    /** Live (non-trashed) user count. */
    public function countAll(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%user}} WHERE [[deleted_at]] IS NULL')
            ->queryScalar();
    }

    /**
     * Does this account still want browser push?
     *
     * Read on every send rather than cached, because the whole point of the
     * column is that turning the switch off has to take effect immediately —
     * a cached answer would keep pinging a browser whose owner just asked to
     * be left alone, which is exactly the thing that makes people revoke the
     * site permission entirely instead of using the switch.
     *
     * An unknown id answers true: a notification aimed at a row that is not
     * there (deleted account, stale queue job) has nowhere to go anyway, and
     * the alternative — answering false — would mean a mis-typed id silently
     * eats every push for that account.
     */
    public function pushEnabled(int $id): bool
    {
        $value = $this->db
            ->createCommand('SELECT [[push_enabled]] FROM {{%user}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryScalar();

        // `queryScalar()` yields false — not null — when the SELECT matched no
        // row, so a deleted account or a stale queue job reads the same as an
        // opted-in one here. Both are safe: there is nowhere for the message to
        // go either way.
        if ($value === false || $value === null) {
            return true;
        }

        // The driver hands a boolean column back as int 0/1 or as the string
        // '0'/'1' depending on emulation settings, so compare numerically
        // rather than with ===.
        return (int) $value === 1;
    }

    /**
     * The account-wide push switch, as the notification stack reads it.
     *
     * Identical to {@see pushEnabled()} except that it survives a database
     * the migration has not run against yet — the window between `git pull`
     * and `yii migrate`, where the column does not exist and every send would
     * otherwise 500 inside the queue worker. Absent column means the default,
     * which is on.
     */
    public function isPushEnabled(int $id): bool
    {
        try {
            return $this->pushEnabled($id);
        } catch (\Throwable) {
            return true;
        }
    }

    /** @see update() — this only exists so the opt-out is written the one way. */
    public function setPushEnabled(int $id, bool $enabled): void
    {
        $this->update($id, ['push_enabled' => $enabled ? 1 : 0]);
    }

    /**
     * The lowest-id active staff account, or null when there is none.
     *
     * `system.alert` needs *a* recipient user id, and it does not have one of
     * its own: an alert about the platform has no acting user. The first
     * staff account stands in, and NotificationManager's admin fan-out reaches
     * everybody else — which is why exactly one id is wanted here rather than
     * a list. Handing it a list would double-notify the first account, since
     * the fan-out loop deliberately skips the user it was dispatched for.
     *
     * Trashed and suspended staff are excluded for the same reason the alert
     * fan-out excludes them: nobody would read it.
     */
    public function firstStaffId(): ?int
    {
        $id = $this->db
            ->createCommand(
                "SELECT [[id]] FROM {{%user}} WHERE [[role]] IN ('admin','staff','superadmin')"
                . " AND [[status]] = 'active' AND [[deleted_at]] IS NULL"
                . ' ORDER BY [[id]] ASC LIMIT 1'
            )
            ->queryScalar();

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Every staff account, live and trashed, for the super-admin's roster.
     *
     * `deleted_at` is included here — unlike every other listing — because
     * restoring a trashed operator is one of the things this screen has to be
     * able to do, and a roster that hides trashed accounts makes that
     * impossible.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStaff(): array
    {
        return $this->db
            ->createCommand(
                "SELECT * FROM {{%user}} WHERE [[role]] IN ('admin','staff','superadmin')"
                . ' ORDER BY ([[role]] = \'superadmin\') DESC, [[id]] ASC',
            )
            ->queryAll();
    }

    /**
     * Whether at least one usable super-admin remains.
     *
     * Called before a role change. Demoting or trashing the last super-admin
     * would leave nobody able to appoint another one — the panel would still
     * work, but nothing could ever be paid out again. So the change is refused
     * while the answer is yes.
     */
    public function hasOtherSuperAdmin(int $exceptId): bool
    {
        $count = $this->db
            ->createCommand(
                "SELECT COUNT(*) FROM {{%user}} WHERE [[role]] = 'superadmin'"
                . ' AND [[status]] = \'active\' AND [[deleted_at]] IS NULL AND [[id]] <> :id',
            )
            ->bindValue(':id', $exceptId)
            ->queryScalar();

        return (int) $count > 0;
    }

    /**
     * Move an account between roles, or suspend it, without touching its rows.
     *
     * Role lives on the user row rather than in a permissions table because it
     * is read on every admin request; a join to find out whether somebody may
     * see the panel would be paid on every page of the admin area to answer a
     * question with four possible answers.
     *
     * @param string $role   one of admin|staff|superadmin|user
     * @param string $status one of active|suspended
     */
    public function setRole(int $id, string $role, string $status = 'active'): void
    {
        $this->update($id, ['role' => $role, 'status' => $status]);
    }

    private function exists(string $table, string $column, string $value, ?int $exceptId): bool
    {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE [[{$column}]] = :v";
        $params = [':v' => $value];
        if ($exceptId !== null) {
            $sql .= ' AND [[id]] != :id';
            $params[':id'] = $exceptId;
        }
        return (int) $this->db->createCommand($sql)->bindValues($params)->queryScalar() > 0;
    }
}
