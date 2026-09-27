<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Soft delete (trash) support for services and users.
 *
 * A NULL `deleted_at` means the row is live; a timestamp means it has been
 * trashed and can be restored. Every read path filters on this column, so a
 * trashed row disappears from the site without losing its transactions,
 * balance or slug reservation.
 */
final class M240105000000_AddDeletedAtToServiceAndUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%service}}', 'deleted_at', 'datetime NULL');
        $b->addColumn('{{%user}}', 'deleted_at', 'datetime NULL');

        $b->createIndex('{{%service}}', 'ix_service_deleted', ['deleted_at']);
        $b->createIndex('{{%user}}', 'ix_user_deleted', ['deleted_at']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('{{%service}}', 'ix_service_deleted');
        $b->dropIndex('{{%user}}', 'ix_user_deleted');

        $b->dropColumn('{{%service}}', 'deleted_at');
        $b->dropColumn('{{%user}}', 'deleted_at');
    }
}
