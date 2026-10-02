<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Telegram bot connections (admin-only per the audit). Minimal on purpose:
 * chat_id + user link + verification stamp, nothing else — no phone, no name,
 * no locale. chat_id is the addressing primitive, never proof of identity.
 */
final class BotConnectionRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** Bind a verified chat to a user. One connection per user and per chat. */
    public function connect(int $userId, int $chatId, ?string $username): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->db
            ->createCommand('SELECT [[id]] FROM {{%bot_connection}} WHERE [[chat_id]] = :c LIMIT 1')
            ->bindValue(':c', $chatId)
            ->queryScalar();

        if ($existing !== false) {
            $this->db->createCommand()->update('{{%bot_connection}}', [
                'user_id' => $userId,
                'username' => $username,
                'verified_at' => $now,
                'is_active' => 1,
                'updated_at' => $now,
            ], ['id' => (int) $existing])->execute();
            return;
        }

        $this->db->createCommand()->insert('{{%bot_connection}}', [
            'user_id' => $userId,
            'chat_id' => $chatId,
            'username' => $username,
            'verified_at' => $now,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    public function disconnect(int $userId): bool
    {
        return $this->db
            ->createCommand()
            ->delete('{{%bot_connection}}', ['user_id' => $userId])
            ->execute() > 0;
    }

    /** Deactivate a chat the bot can no longer reach (blocked / deleted). */
    public function deactivate(int $chatId): void
    {
        $this->db
            ->createCommand()
            ->update(
                '{{%bot_connection}}',
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['chat_id' => $chatId],
            )
            ->execute();
    }

    /** Active chat ids a user receives on. */
    public function activeChatIds(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[chat_id]] FROM {{%bot_connection}} WHERE [[user_id]] = :uid AND [[is_active]] = 1'
            )
            ->bindValue(':uid', $userId)
            ->queryColumn();

        return array_map('intval', $rows);
    }

    public function findByChatId(int $chatId): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%bot_connection}} WHERE [[chat_id]] = :c LIMIT 1')
            ->bindValue(':c', $chatId)
            ->queryOne();
        return $row === false ? null : $row;
    }

    /** Admin user ids that have a live bot connection — the fan-out list. */
    public function activeAdminUserIds(): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT b.[[user_id]] FROM {{%bot_connection}} b'
                . ' JOIN {{%user}} u ON u.[[id]] = b.[[user_id]]'
                . " WHERE b.[[is_active]] = 1 AND u.[[role]] IN ('admin','staff') AND u.[[status]] = 'active'"
            )
            ->queryColumn();

        return array_map('intval', $rows);
    }
}
