<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class NotificationRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(int $userId, string $title, string $message, string $type = 'info'): int
    {
        $this->db->createCommand()->insert('{{%notification}}', [
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function forUser(int $userId, int $page, int $perPage, bool $unreadOnly = false): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = '[[user_id]] = :uid' . ($unreadOnly ? ' AND [[read_at]] IS NULL' : '');
        $params = [':uid' => $userId];
        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%notification}} WHERE {$where}")
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand("SELECT * FROM {{%notification}} WHERE {$where} ORDER BY [[id]] DESC LIMIT {$perPage} OFFSET {$offset}")
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification}} WHERE [[user_id]] = :uid AND [[read_at]] IS NULL')
            ->bindValue(':uid', $userId)
            ->queryScalar();
    }

    public function markRead(int $id, int $userId): void
    {
        $this->db
            ->createCommand()
            ->update('{{%notification}}', ['read_at' => date('Y-m-d H:i:s')], ['user_id' => $userId, 'id' => $id, 'read_at' => null])
            ->execute();
    }

    public function markAllRead(int $userId): void
    {
        $this->db
            ->createCommand()
            ->update('{{%notification}}', ['read_at' => date('Y-m-d H:i:s')], ['user_id' => $userId, 'read_at' => null])
            ->execute();
    }
}
