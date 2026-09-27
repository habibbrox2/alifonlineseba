<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class ActivityLogRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): void
    {
        $this->db->createCommand()->insert('{{%activity_log}}', [
            'user_id' => $row['user_id'] ?? null,
            'action' => $row['action'],
            'description' => $row['description'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'metadata' => isset($row['metadata']) ? json_encode($row['metadata'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ])->execute();
    }

    public function forUser(int $userId, int $page, int $perPage): array
    {
        return $this->paged('[[user_id]] = :uid', [':uid' => $userId], $page, $perPage);
    }

    public function all(int $page, int $perPage, string $q = ''): array
    {
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where = '[[action]] LIKE :q OR [[description]] LIKE :q OR [[ip_address]] LIKE :q';
            $params[':q'] = "%{$q}%";
        }
        return $this->paged($where, $params, $page, $perPage);
    }

    private function paged(string $where, array $params, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%activity_log}} WHERE {$where}")
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand("SELECT * FROM {{%activity_log}} WHERE {$where} ORDER BY [[id]] DESC LIMIT {$perPage} OFFSET {$offset}")
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }
}
