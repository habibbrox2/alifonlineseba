<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The admin bulk-job queue, drained by `app:bulk:work` (cron), for the same
 * no-resident-worker reason the notification queue is a table and not a daemon:
 * shared hosting has nowhere to keep a process alive between requests.
 *
 * The claim follows `NotificationQueueRepository`: select the candidate, then
 * flip it with a status-guarded UPDATE, so two overlapping ticks cannot both
 * believe they own the same job. What this queue adds is the reclaim — a job
 * whose worker was killed mid-chunk is still `processing`, and without the
 * reclaim it would sit there forever while its cursor quietly stops moving.
 */
final class BulkJobRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Record a batch as work to do. The caller has already validated the
     * payload — this only makes it durable.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $kind, array $payload, ?int $requestedBy, string $requestedByName, int $total, int $chunkSize): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%admin_bulk_job}}', [
            'kind' => $kind,
            'status' => 'queued',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'requested_by' => $requestedBy,
            'requested_by_name' => $requestedByName,
            'total' => $total,
            'chunk_size' => max(1, $chunkSize),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    /**
     * Take the next job: the oldest queued one, or a `processing` row abandoned
     * long enough ago to be assumed dead.
     *
     * The reclaim threshold has to clear a single chunk's worth of work with
     * room to spare — a job whose last progress write is older than that is
     * not a slow chunk, it is a worker that is gone.
     */
    public function claimNext(int $staleAfterSeconds = 300): ?array
    {
        $staleBefore = date('Y-m-d H:i:s', time() - max(30, $staleAfterSeconds));

        $id = $this->db
            ->createCommand(
                "SELECT [[id]] FROM {{%admin_bulk_job}}"
                . " WHERE [[status]] = 'queued' OR ([[status]] = 'processing' AND [[updated_at]] < :stale)"
                . ' ORDER BY [[id]] ASC LIMIT 1'
            )
            ->bindValue(':stale', $staleBefore)
            ->queryScalar();

        if ($id === false || $id === null) {
            return null;
        }

        $id = (int) $id;
        $affected = $this->db
            ->createCommand(
                "UPDATE {{%admin_bulk_job}} SET [[status]] = 'processing', [[attempts]] = [[attempts]] + 1,"
                . ' [[started_at]] = :now, [[updated_at]] = :now'
                . " WHERE [[id]] = :id AND ([[status]] = 'queued' OR [[status]] = 'processing')"
            )
            ->bindValue(':now', date('Y-m-d H:i:s'))
            ->bindValue(':id', $id)
            ->execute();

        // Lost the race to another tick: it owns the row, so this one moves on.
        if ($affected === 0) {
            return null;
        }

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%admin_bulk_job}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * Checkpoint one finished chunk.
     *
     * Written after every chunk rather than at the end: `cursor` is the resume
     * point, so a job that dies without it replays work it already did.
     */
    public function saveProgress(int $id, int $cursor, int $processed, int $applied, int $unchanged, int $skipped, int $progress, string $message = ''): void
    {
        $values = [
            'cursor' => $cursor,
            'processed' => $processed,
            'applied' => $applied,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'progress' => max(0, min(100, $progress)),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($message !== '') {
            $values['message'] = mb_substr($message, 0, 500);
        }

        $this->db
            ->createCommand()
            ->update('{{%admin_bulk_job}}', $values, ['id' => $id, 'status' => 'processing'])
            ->execute();
    }

    public function markDone(int $id, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%admin_bulk_job}}', [
            'status' => 'done',
            'progress' => 100,
            'message' => mb_substr($message, 0, 500),
            'last_error' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id])->execute();
    }

    public function markFailed(int $id, string $error): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%admin_bulk_job}}', [
            'status' => 'failed',
            'message' => mb_substr($error, 0, 500),
            'last_error' => mb_substr($error, 0, 500),
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id])->execute();
    }

    /**
     * A failed job, back in line — with its counters and cursor intact, since
     * the work it did not get to is still undone.
     */
    public function requeue(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update('{{%admin_bulk_job}}', [
                'status' => 'queued',
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id, 'status' => 'failed'])
            ->execute() > 0;
    }

    /**
     * An operator's recent jobs, newest first, for the progress panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentFor(?int $requestedBy, int $limit = 5): array
    {
        $limit = max(1, $limit);

        if ($requestedBy === null) {
            // Staff with no own jobs still see the queue: a batch they did not
            // request can still be the one sitting in front of them.
            return $this->db
                ->createCommand('SELECT * FROM {{%admin_bulk_job}} ORDER BY [[id]] DESC LIMIT ' . $limit)
                ->queryAll();
        }

        return $this->db
            ->createCommand('SELECT * FROM {{%admin_bulk_job}} WHERE [[requested_by]] = :uid ORDER BY [[id]] DESC LIMIT ' . $limit)
            ->bindValue(':uid', $requestedBy)
            ->queryAll();
    }

    /** How many jobs are still outstanding — the admin dashboard's queue depth. */
    public function depth(): int
    {
        return (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%admin_bulk_job}} WHERE [[status]] IN ('queued', 'processing')")
            ->queryScalar();
    }
}
