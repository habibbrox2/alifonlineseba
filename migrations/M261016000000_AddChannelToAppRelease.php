<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Adds a `channel` column to `app_release` so two release streams can coexist:
 * the public TWA builds and the native Android admin builds.
 *
 * The column defaults to `twa`, so every row written before this migration
 * stays on the public channel and existing readers see no change.
 */
final class M261016000000_AddChannelToAppRelease implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $this->safe(fn () => $b->addColumn('{{%app_release}}', 'channel', 'string(16) NOT NULL DEFAULT \'twa\''));

        // The old unique index was on `version_code` only, which would block the
        // same version number being published in two channels. Replace it with a
        // channel-scoped one so identical versions can coexist across streams.
        $this->safe(fn () => $b->dropIndex('{{%app_release}}', 'ux_app_release_version_code'));
        $this->safe(fn () => $b->createIndex('{{%app_release}}', 'ux_app_release_channel_version', ['channel', 'version_code'], 'UNIQUE'));
    }

    public function down(MigrationBuilder $b): void
    {
        $this->safe(fn () => $b->dropIndex('{{%app_release}}', 'ux_app_release_channel_version'));
        $this->safe(fn () => $b->createIndex('{{%app_release}}', 'ux_app_release_version_code', 'version_code', 'UNIQUE'));
        $this->safe(fn () => $b->dropColumn('{{%app_release}}', 'channel'));
    }

    private function safe(callable $step): void
    {
        try {
            $step();
        } catch (\Yiisoft\Db\Exception\Exception $e) {
            $msg = $e->getMessage();
            $already = str_contains($msg, '1060')
                || str_contains($msg, '1061')
                || str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key')
                || str_contains($msg, 'already exists')
                || str_contains($msg, "can't DROP");
            if (!$already) {
                throw $e;
            }
        }
    }
}
