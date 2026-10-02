<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Extends `topup_request` from a bare "amount + method" note into a real
 * manual-verification workflow.
 *
 * Receipts are stored on disk OUTSIDE `public/` (`web/receipts`) and are only
 * ever read back through an authenticated action, so this table holds the
 * relative path plus the original filename/MIME/size for display and audit —
 * never a user-supplied name that could escape the directory.
 */
final class M240106000000_AddReceiptFieldsToTopupRequest implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%topup_request}}', 'receipt_path', 'string(255) NULL');
        $b->addColumn('{{%topup_request}}', 'receipt_name', 'string(255) NULL');
        $b->addColumn('{{%topup_request}}', 'receipt_mime', 'string(100) NULL');
        $b->addColumn('{{%topup_request}}', 'receipt_size', 'integer NULL');

        // Why the reviewer said no. Shown to the user, so a rejection is never silent.
        $b->addColumn('{{%topup_request}}', 'reject_reason', 'string(500) NULL');

        // Reviewer-only note, never shown to the user.
        $b->addColumn('{{%topup_request}}', 'admin_note', 'string(500) NULL');

        // Set the moment an admin opens the request with a receipt, i.e. "seen", not "approved".
        $b->addColumn('{{%topup_request}}', 'verified_at', 'datetime NULL');

        $b->addColumn('{{%topup_request}}', 'sender_name', 'string(100) NULL');

        // Duplicate TrxID lookup goes through this index on every submission.
        $b->addIndex('{{%topup_request}}', 'ix_topup_reference', ['reference']);

        // Admin list defaults to pending-first, then newest.
        $b->addIndex('{{%topup_request}}', 'ix_topup_status_created', ['status', 'created_at']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('{{%topup_request}}', 'ix_topup_status_created');
        $b->dropIndex('{{%topup_request}}', 'ix_topup_reference');
        $b->dropColumn('{{%topup_request}}', 'sender_name');
        $b->dropColumn('{{%topup_request}}', 'verified_at');
        $b->dropColumn('{{%topup_request}}', 'admin_note');
        $b->dropColumn('{{%topup_request}}', 'reject_reason');
        $b->dropColumn('{{%topup_request}}', 'receipt_size');
        $b->dropColumn('{{%topup_request}}', 'receipt_mime');
        $b->dropColumn('{{%topup_request}}', 'receipt_name');
        $b->dropColumn('{{%topup_request}}', 'receipt_path');
    }
}
