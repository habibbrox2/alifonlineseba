<?php

declare(strict_types=1);

namespace App\Notification;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The database-backed queue drained by `app:notification:work` (cron), per the
 * audit's no-resident-worker constraint for shared hosting.
 *
 * Enqueue is idempotent by dedupe_key (UNIQUE index): a replayed business
 * action cannot double-send.
 */
final class QueueRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Insert a job if the dedupe key is new. Returns the row id, or null when
     * an identical job already exists (the idempotency branch).
     */
    public function enqueue(array $row): ?int
    {
        $now = date('Y-m-d H:i:s');
        $values = [
            'notification_id' => $row['notification_id'] ?? null,
            'event' => (string) $row['event'],
            'user_id' => $row['user_id'] ?? null,
            'channel' => (string) $row['channel'],
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => (int) ($row['max_attempts'] ?? 3),
            'available_at' => $row['available_at'] ?? $now,
            'payload' => json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE),
            'dedupe_key' => (string) $row['dedupe_key'],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $this->db->createCommand()->insert('{{%notification_queue}}', $values)->execute();
        } catch (\Yiisoft\Db\Exception\IntegrityException) {
            return null; // duplicate dedupe_key — already queued
        }

        return (int) $this->db->getLastInsertID();
    }

    /**
     * Claim a batch of due jobs. Two overlapping cron ticks must not both run
     * the same row, so the claim is an atomic UPDATE guarded by status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimBatch(int $limit): array
    {
        $now = date('Y-m-d H:i:s');
        // Oldest first; per-row priority is honoured by the small batch size
        // (200/tick) and the 1-minute cron — a dedicated priority column on the
        // queue would need a denormalised copy of the event spec per row.
        $ids = $this->db
            ->createCommand(
                "SELECT [[id]] FROM {{%notification_queue}}"
                . " WHERE [[status]] = 'queued' AND [[available_at]] <= :now"
                . ' ORDER BY [[id]] ASC LIMIT ' . (int) $limit
            )
            ->bindValue(':now', $now)
            ->queryColumn();

        if ($ids === []) {
            return [];
        }

        // Mark as processing first; the workers then read exactly what they own.
        $idList = implode(',', array_map('intval', $ids));
        $this->db->createCommand(
            "UPDATE {{%notification_queue}} SET [[status]] = 'processing', [[updated_at]] = :now"
            . " WHERE [[id]] IN ({$idList}) AND [[status]] = 'queued'"
        )
            ->bindValue(':now', $now)
            ->execute();

        return $this->db
            ->createCommand(
                "SELECT * FROM {{%notification_queue}}"
                . " WHERE [[id]] IN ({$idList}) AND [[status]] = 'processing' ORDER BY [[id]] ASC"
            )
            ->queryAll();
    }

    public function markSent(int $id, string $providerMessageId = '', int $latencyMs = 0): void
    {
        $this->db->createCommand()->update('{{%notification_queue}}', [
            'status' => 'sent',
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();

        $this->recordDelivery($id, 'sent', $providerMessageId, $latencyMs);
    }

    /**
     * Record a failure, schedule the retry with exponential backoff, or dead-letter.
     */
    public function markFailed(int $id, string $error, bool $retryable, int $backoffBase = 60): void
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->findById($id);
        if ($row === null) {
            return;
        }

        $attempts = (int) $row['attempts'] + 1;
        $max = (int) $row['max_attempts'];

        // Permanent failures (bad token, 4xx) and exhausted retries go straight
        // to dead — a poison row must never retry forever.
        if (!$retryable || $attempts >= $max) {
            $this->db->createCommand()->update('{{%notification_queue}}', [
                'status' => 'dead',
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 500),
                'updated_at' => $now,
            ], ['id' => $id])->execute();
        } else {
            $delay = $backoffBase * (2 ** ($attempts - 1));
            // Jitter so a provider outage does not produce a thundering herd.
            $delay += random_int(0, (int) ($delay * 0.2));
            $available = date('Y-m-d H:i:s', time() + $delay);

            $this->db->createCommand()->update('{{%notification_queue}}', [
                'status' => 'queued',
                'attempts' => $attempts,
                'available_at' => $available,
                'last_error' => mb_substr($error, 0, 500),
                'updated_at' => $now,
            ], ['id' => $id])->execute();
        }

        $this->recordDelivery($id, $retryable ? 'failed' : 'dead', '', 0, $error);
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%notification_queue}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    /** Re-queue a dead job by hand (admin retry button). */
    public function requeue(int $id): bool
    {
        $affected = $this->db
            ->createCommand()
            ->update(
                '{{%notification_queue}}',
                [
                    'status' => 'queued',
                    'attempts' => 0,
                    'available_at' => date('Y-m-d H:i:s'),
                    'last_error' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                ['id' => $id, 'status' => 'dead'],
            )
            ->execute();

        return $affected > 0;
    }

    public function stats(): array
    {
        $rows = $this->db
            ->createCommand('SELECT [[status]], COUNT(*) AS c FROM {{%notification_queue}} GROUP BY [[status]]')
            ->queryAll();

        $stats = ['queued' => 0, 'processing' => 0, 'sent' => 0, 'dead' => 0];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['c'];
        }
        $stats['depth'] = $stats['queued'] + $stats['processing'];
        return $stats;
    }

    /** Retention: purge sent rows after N days, dead rows after 30. */
    public function purge(int $retentionDays = 7, int $deadDays = 30): int
    {
        $total = 0;
        $total += $this->db
            ->createCommand(
                "DELETE FROM {{%notification_queue}} WHERE [[status]] = 'sent'"
                . ' AND [[updated_at]] < :cutoff'
            )
            ->bindValue(':cutoff', date('Y-m-d H:i:s', time() - $retentionDays * 86400))
            ->execute();
        $total += $this->db
            ->createCommand(
                "DELETE FROM {{%notification_queue}} WHERE [[status]] = 'dead'"
                . ' AND [[updated_at]] < :cutoff'
            )
            ->bindValue(':cutoff', date('Y-m-d H:i:s', time() - $deadDays * 86400))
            ->execute();

        return $total;
    }

    private function recordDelivery(int $queueId, string $status, string $providerMessageId, int $latencyMs, string $error = ''): void
    {
        $now = date('Y-m-d H:i:s');
        $queue = $this->findById($queueId);
        if ($queue === null) {
            return;
        }

        $values = [
            'queue_id' => $queueId,
            'channel' => (string) $queue['channel'],
            'status' => $status,
            'attempts' => (int) $queue['attempts'] + ($status === 'failed' || $status === 'dead' ? 1 : 0),
            'max_attempts' => (int) $queue['max_attempts'],
            'last_error' => $error !== '' ? mb_substr($error, 0, 500) : null,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'latency_ms' => $latencyMs > 0 ? $latencyMs : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $values['sent_at'] = $status === 'sent' ? $now : null;
        $values['failed_at'] = $status === 'failed' || $status === 'dead' ? $now : null;

        $this->db->createCommand()->insert('{{%notification_delivery}}', $values)->execute();
    }
}
