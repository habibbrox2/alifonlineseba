<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Aligns the user table with the reference site's account model.
 *
 * - `api_key`    — a per-user "API User Key" (AL_XXXXXXXX) shown on the
 *                  recharge and profile pages. Generated lazily on first
 *                  render so accounts created before this migration do not
 *                  need a backfill.
 * - `free_searches` — the demo's free-search allowance (reference shows
 *                  "ফ্রি সার্চ বাকি 1 টি"); a service submit consumes one
 *                  before charging when the user has never paid for anything.
 */
final class M240108000000_AddApiKeyAndFreeSearchesToUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'api_key', 'string(32) NULL');
        $b->addColumn('{{%user}}', 'free_searches', "integer NOT NULL DEFAULT '1'");

        // One key per user; the lookup on generation is by user id anyway,
        // but a unique index keeps the invariant enforceable.
        $b->createIndex('{{%user}}', 'uk_user_api_key', ['api_key'], 'UNIQUE');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('{{%user}}', 'uk_user_api_key');
        $b->dropColumn('{{%user}}', 'free_searches');
        $b->dropColumn('{{%user}}', 'api_key');
    }
}
