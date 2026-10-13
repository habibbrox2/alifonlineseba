<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Persistent role ranks from the role_rank table.
 * Falls back to Identity::DEFAULT_ROLE_RANK when table is empty or row missing.
 */
final class RoleRankRepository
{
    /** Default role ranks matching Identity::ROLE_RANK */
    public const DEFAULT_ROLE_RANK = [
        'superadmin' => 100,
        'admin' => 50,
        'staff' => 25,
        'user' => 10,
    ];

    private const TABLE = '{{%role_rank}}';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, int> role => rank */
    public function all(): array
    {
        $values = self::DEFAULT_ROLE_RANK;

        try {
            $rows = $this->db
                ->createCommand('SELECT [[role]], [[rank]] FROM ' . self::TABLE)
                ->queryAll();
        } catch (\Throwable) {
            // Every authenticated request reads the ranks, so a deployment that
            // ships the code before the migration would otherwise turn the whole
            // site — including /login — into a 500. Defaults are the safe answer.
            return $values;
        }
        foreach ($rows as $row) {
            $role = (string) $row['role'];
            $rank = (int) $row['rank'];
            if (array_key_exists($role, self::DEFAULT_ROLE_RANK)) {
                $values[$role] = $rank;
            }
        }

        return $values;
    }

    /**
     * Update multiple role ranks in a single transaction.
     * @param array<string, int> $ranks
     */
    public function updateMany(array $ranks, ?int $updatedBy): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->transaction(static function () use ($ranks, $updatedBy, $now): void {
            foreach ($ranks as $role => $rank) {
                if (!array_key_exists($role, self::DEFAULT_ROLE_RANK)) {
                    continue;
                }
                $exists = $this->db
                    ->createCommand('SELECT [[id]] FROM ' . self::TABLE . ' WHERE [[role]] = :r LIMIT 1')
                    ->bindValue(':r', $role)
                    ->queryScalar();

                if ($exists !== false) {
                    $this->db->createCommand()->update(self::TABLE, [
                        'rank' => $rank,
                        'updated_by' => $updatedBy,
                        'updated_at' => $now,
                    ], ['id' => (int) $exists])->execute();
                } else {
                    $this->db->createCommand()->insert(self::TABLE, [
                        'role' => $role,
                        'rank' => $rank,
                        'updated_by' => $updatedBy,
                        'updated_at' => $now,
                    ])->execute();
                }
            }
        });
    }

    /** Reset all ranks to defaults by deleting custom rows. */
    public function reset(): void
    {
        $this->db->createCommand()->delete(self::TABLE)->execute();
        foreach (self::DEFAULT_ROLE_RANK as $role => $rank) {
            $this->db->createCommand()->insert(self::TABLE, [
                'role' => $role,
                'rank' => $rank,
                'updated_by' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();
        }
    }

    /** Delete all rows from the role_rank table. */
    public function deleteAll(): void
    {
        $this->db->createCommand()->delete(self::TABLE)->execute();
    }

    /** Create a new role rank row. */
    public function create(string $role, int $rank, ?int $updatedBy = null): void
    {
        $this->db->createCommand()->insert(self::TABLE, [
            'role' => $role,
            'rank' => $rank,
            'updated_by' => $updatedBy,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();
    }

    /** Update an existing role rank. */
    public function update(string $role, int $rank, ?int $updatedBy = null): void
    {
        $exists = $this->db
            ->createCommand('SELECT [[id]] FROM ' . self::TABLE . ' WHERE [[role]] = :r LIMIT 1')
            ->bindValue(':r', $role)
            ->queryScalar();

        if ($exists !== false) {
            $this->db->createCommand()->update(self::TABLE, [
                'rank' => $rank,
                'updated_by' => $updatedBy,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $exists])->execute();
        }
    }

    /** @return array<string, array{role: string, rank: int, updated_by: int|null, updated_at: string}> */
    public function allDetailed(): array
    {
        $rows = $this->db
            ->createCommand('SELECT [[role]], [[rank]], [[updated_by]], [[updated_at]] FROM ' . self::TABLE)
            ->queryAll();
        $result = [];
        foreach ($rows as $row) {
            $role = (string) $row['role'];
            $result[$role] = [
                'role' => $role,
                'rank' => (int) $row['rank'],
                'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $result;
    }
}