<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Balance top-up requests: users request, admin approves/rejects.
 */
final class M240102000000_CreateTopupRequests implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%topup_request}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            'amount' => "decimal(12,2) NOT NULL",
            'method' => "string(32) NOT NULL DEFAULT 'bkash'",
            'sender_number' => 'string(64) NULL',
            'reference' => 'string(64) NULL',
            'note' => 'string(500) NULL',
            'status' => "string(16) NOT NULL DEFAULT 'pending'",
            'reviewed_by' => 'integer NULL',
            'reviewed_at' => 'datetime NULL',
            'transaction_id' => 'integer NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%topup_request}}', 'ix_topup_user', ['user_id']);
        $b->createIndex('{{%topup_request}}', 'ix_topup_status', ['status']);
        $b->addForeignKey('{{%topup_request}}', 'fk_topup_user', 'user_id', '{{%user}}', 'id');
        $b->addForeignKey('{{%topup_request}}', 'fk_topup_reviewer', 'reviewed_by', '{{%user}}', 'id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%topup_request}}');
    }
}
