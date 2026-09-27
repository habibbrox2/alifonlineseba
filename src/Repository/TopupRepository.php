<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class TopupRepository
{
    public const METHODS = ['bkash', 'nagad', 'rocket'];

    public const STATUSES = ['pending', 'approved', 'rejected'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%topup_request}}', [
            'user_id' => (int) $row['user_id'],
            'amount' => $row['amount'],
            'method' => (string) $row['method'],
            'sender_number' => $row['sender_number'] ?? null,
            'reference' => $row['reference'] ?? null,
            'note' => $row['note'] ?? null,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%topup_request}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%topup_request}}', $values, ['id' => $id])->execute();
    }

    public function forUser(int $userId, int $page = 1, int $perPage = 5): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $params = [':uid' => $userId];
        $total = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%topup_request}} WHERE [[user_id]] = :uid')
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                'SELECT * FROM {{%topup_request}} WHERE [[user_id]] = :uid ORDER BY [[id]] DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
            )
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }

    public function pendingCount(): int
    {
        return (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%topup_request}} WHERE [[status]] = 'pending'")
            ->queryScalar();
    }

    public function adminList(int $page, int $perPage, string $status = ''): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = '1=1';
        $params = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where = '[[status]] = :st';
            $params[':st'] = $status;
        }
        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%topup_request}} WHERE {$where}")
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                'SELECT t.*, u.[[username]], u.[[phone]]'
                . ' FROM {{%topup_request}} t'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = t.[[user_id]]'
                . " WHERE {$where} ORDER BY (t.[[status]] = 'pending') DESC, t.[[id]] DESC"
                . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
            )
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }

    public function stats(): array
    {
        $row = $this->db
            ->createCommand(
                "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN [[status]] = 'pending' THEN 1 ELSE 0 END), 0) AS pending"
                . " FROM {{%topup_request}}"
            )
            ->queryOne();
        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }
}
