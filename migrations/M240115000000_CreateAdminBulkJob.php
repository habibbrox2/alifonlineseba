<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The durable queue behind the admin bulk-action bar.
 *
 * A batch settle used to run inside the POST that submitted it: one request
 * walking up to a hundred rows, each with its own refund, notification and
 * activity log. That has two failure modes that a queue removes outright —
 * the web server's request timeout can cut the batch in half (the operator
 * sees a 504 and has no idea which half moved), and a single slow row holds
 * the whole connection open. So the request now only writes a row here and
 * returns; `app:bulk:work` does the walking, a chunk at a time.
 *
 * The columns that make that resumable rather than merely restartable:
 *
 * - `cursor` is how far into the payload's id list the job has got, written
 *   after every chunk. A worker killed by a deploy comes back to the next
 *   chunk instead of the top.
 * - the four counters are kept as it goes, so a half-done job still reports
 *   something honest instead of resetting to zero when it is picked up again.
 * - `progress` is derived from `processed / total` and stored, because the
 *   page the operator is watching reads it on every poll rather than counting
 *   a payload it does not have.
 * - `attempts` plus the stale-`processing` reclaim are what stop a job whose
 *   worker was killed mid-chunk from sitting in `processing` forever.
 *
 * Re-running a chunk is safe rather than merely unlikely: settling an order
 * that is already in the target status is a counted no-op, so a chunk lost
 * between its writes is re-applied as "unchanged" and never paid twice.
 */
final class M240115000000_CreateAdminBulkJob implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%admin_bulk_job}}', [
            'id' => 'pk',
            // 'settle_status' today. Stored rather than implied so the second
            // bar action is a peer row, not a branch on the table's existence.
            'kind' => "string(32) NOT NULL DEFAULT 'settle_status'",
            'status' => "string(16) NOT NULL DEFAULT 'queued'",
            // {ids: int[], status: string} — the selection as submitted, so
            // the job settles exactly what was ticked rather than whatever the
            // list looks like when the worker eventually gets to it.
            'payload' => 'json NULL',
            // The operator on whose authority the rows move, denormalised
            // because the audit log entry must survive the account going away.
            'requested_by' => 'integer NULL',
            'requested_by_name' => 'string(64) NULL',
            'total' => 'integer NOT NULL DEFAULT 0',
            'processed' => 'integer NOT NULL DEFAULT 0',
            'applied' => 'integer NOT NULL DEFAULT 0',
            'unchanged' => 'integer NOT NULL DEFAULT 0',
            'skipped' => 'integer NOT NULL DEFAULT 0',
            // Ids consumed so far: the resume point.
            'cursor' => 'integer NOT NULL DEFAULT 0',
            'chunk_size' => 'integer NOT NULL DEFAULT 25',
            'progress' => 'tinyint NOT NULL DEFAULT 0',
            'message' => 'string(500) NULL',
            'attempts' => 'integer NOT NULL DEFAULT 0',
            'last_error' => 'string(500) NULL',
            'created_at' => 'datetime NOT NULL',
            'started_at' => 'datetime NULL',
            'finished_at' => 'datetime NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        // The worker's only hot query, and the one that has to be index-only:
        // it runs this on every cron tick whether or not there is work.
        $b->createIndex('{{%admin_bulk_job}}', 'ix_bulk_job_poll', ['status', 'id']);
        // The progress panel: one operator's recent jobs, newest first.
        $b->createIndex('{{%admin_bulk_job}}', 'ix_bulk_job_requester', ['requested_by', 'id']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%admin_bulk_job}}');
    }
}
