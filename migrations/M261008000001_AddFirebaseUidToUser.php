<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Track the Firebase UID for accounts created via Firebase Authentication.
 *
 * `firebase_uid` is a nullable string because accounts created before this
 * migration (or via the legacy password flow) do not have a Firebase UID.
 */
final class M261008000001_AddFirebaseUidToUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'firebase_uid', 'string NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%user}}', 'firebase_uid');
    }
}
