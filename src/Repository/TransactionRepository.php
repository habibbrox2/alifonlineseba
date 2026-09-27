<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class TransactionRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%transaction}}', [
            'user_id' => (int) $row['user_id'],
            'service_id' => isset($row['service_id']) ? (int) $row['service_id'] : null,
            'reference' => $row['reference'],
            'amount' => $row['amount'],
            'status' => $row['status'] ?? 'pending',
            'metadata' => isset($row['metadata']) ? json_encode($row['metadata'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%transaction}}', $values, ['id' => $id])->execute();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%transaction}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function forUser(int $userId, int $page, int $perPage, string $status = ''): array
    {
        $where = '{{%transaction}}.[[user_id]] = :uid';
        $params = [':uid' => $userId];
        if ($status !== '') {
            $where .= ' AND {{%transaction}}.[[status]] = :st';
            $params[':st'] = $status;
        }
        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /** Whitelisted sortable columns => SQL column expression. */
    public const SORTABLE = [
        'id' => '{{%transaction}}.[[id]]',
        'reference' => '{{%transaction}}.[[reference]]',
        'amount' => '{{%transaction}}.[[amount]]',
        'status' => '{{%transaction}}.[[status]]',
        'created_at' => '{{%transaction}}.[[created_at]]',
        'username' => '{{%user}}.[[username]]',
    ];

    public function all(int $page, int $perPage, string $status = '', string $q = '', string $sort = 'id', string $dir = 'desc'): array
    {
        $where = '1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND {{%transaction}}.[[status]] = :st';
            $params[':st'] = $status;
        }
        if ($q !== '') {
            $where .= ' AND ({{%transaction}}.[[reference]] LIKE :q OR {{%user}}.[[username]] LIKE :q OR {{%user}}.[[phone]] LIKE :q)';
            $params[':q'] = "%{$q}%";
        }
        return $this->pagedJoin($where, $params, $page, $perPage, $sort, $dir);
    }

    private function pagedJoin(string $where, array $params, int $page, int $perPage, string $sort = 'id', string $dir = 'desc'): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%transaction}} LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%transaction}}.[[user_id]] WHERE ' . $where
            )
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                'SELECT {{%transaction}}.*, {{%service}}.[[name]] AS service_name, {{%user}}.[[username]], {{%user}}.[[phone]]'
                . ' FROM {{%transaction}}'
                . ' LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%transaction}}.[[user_id]]'
                . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%transaction}}.[[service_id]]'
                . ' WHERE ' . $where
                . ' ORDER BY ' . $this->sortClause($sort, $dir)
                . " LIMIT {$perPage} OFFSET {$offset}"
            )
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Build a safe "expr dir" ORDER BY fragment from the SORTABLE whitelist.
     */
    private function sortClause(string $sort, string $dir): string
    {
        $col = self::SORTABLE[$sort] ?? self::SORTABLE['id'];
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return "{$col} {$direction}";
    }

    public function statsForUser(int $userId): array
    {
        return $this->stats('[[user_id]] = :uid', [':uid' => $userId]);
    }

    public function statsAll(): array
    {
        return $this->stats('1=1', []);
    }

    private function stats(string $where, array $params): array
    {
        $row = $this->db
            ->createCommand(
                "SELECT COUNT(*) AS total, COALESCE(SUM([[amount]]),0) AS amount FROM {{%transaction}} WHERE {$where}"
            )
            ->bindValues($params)
            ->queryOne();
        return [
            'total' => (int) ($row['total'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }
}
