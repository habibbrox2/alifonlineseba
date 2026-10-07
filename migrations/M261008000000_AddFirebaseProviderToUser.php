<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Track which Firebase provider an account was created from.
 *
 * `firebase_provider` is a nullable string because:
 *
 * - accounts created before this migration (or via the legacy password flow)
 *   do not have a provider and should stay NULL rather than receive a fake
 *   value;
 * - the value is informational and is not used for authentication logic, so
 *   NULL is equivalent to "unknown/password".
 */
final class M261008000000_AddFirebaseProviderToUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'firebase_provider', 'string NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%user}}', 'firebase_provider');
    }
}
