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

    /** AL-prefixed 8-hex key, Alif Tools format (AL_E5B6A404). */
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
