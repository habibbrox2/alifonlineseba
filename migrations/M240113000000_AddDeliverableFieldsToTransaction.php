<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Lets an admin attach a finished result file to a service request.
 *
 * A service request lives in `transaction` — there is no separate table for
 * them — so the deliverable columns go here rather than into a new one. This
 * mirrors `M240106000000_AddReceiptFieldsToTopupRequest`: the bytes live on
 * disk OUTSIDE `public/` (`web/deliverables`) and are only ever read back
 * through an authenticated action, so the row holds the relative path plus the
 * original filename/MIME/size for display — never a client-supplied name that
 * could escape the directory.
 *
 * `deliverable_uploaded_at` is separate from `updated_at` because the history
 * page polls `updated_at` to decide whether a row changed; without its own
 * timestamp an upload would be indistinguishable from an admin nudging the
 * status.
 */
final class M240113000000_AddDeliverableFieldsToTransaction implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%transaction}}', 'deliverable_path', 'string(255) NULL');
        $b->addColumn('{{%transaction}}', 'deliverable_name', 'string(255) NULL');
        $b->addColumn('{{%transaction}}', 'deliverable_mime', 'string(100) NULL');
        $b->addColumn('{{%transaction}}', 'deliverable_size', 'integer NULL');
        $b->addColumn('{{%transaction}}', 'deliverable_uploaded_at', 'datetime NULL');

        // Who produced the file and when, so a disputed result can be traced.
        $b->addColumn('{{%transaction}}', 'deliverable_uploaded_by', 'integer NULL');

        // The admin queue sorts pending-first, then newest; a deliverable is
        // what makes a request actionable, so the index leads with the status.
        $b->createIndex('{{%transaction}}', 'ix_transaction_status_created', ['status', 'created_at']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('{{%transaction}}', 'ix_transaction_status_created');
        $b->dropColumn('{{%transaction}}', 'deliverable_uploaded_by');
        $b->dropColumn('{{%transaction}}', 'deliverable_uploaded_at');
        $b->dropColumn('{{%transaction}}', 'deliverable_size');
        $b->dropColumn('{{%transaction}}', 'deliverable_mime');
        $b->dropColumn('{{%transaction}}', 'deliverable_name');
        $b->dropColumn('{{%transaction}}', 'deliverable_path');
    }
}
