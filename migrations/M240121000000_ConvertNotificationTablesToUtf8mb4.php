<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Converts the notification tables to utf8mb4 where they already exist in the
 * wrong charset.
 *
 * M240109 originally created these tables without naming a charset, so they
 * inherited the database default. On the cPanel host that default is latin1,
 * which cannot represent Bengali, and seeding the compiled-in templates dies
 * with:
 *
 *   SQLSTATE[22007] ... Incorrect string value: '\xE0\xA6\xB8...'
 *   for column `notification_template`.`title`
 *
 * M240109 now names utf8mb4 when it creates a table, which fixes every fresh
 * install — but it cannot reach a table that already exists. A re-run skips
 * `createTable` as "table exists", leaving the latin1 columns exactly where
 * they are, which is why the production deploy kept failing on the seed after
 * that fix went in. This migration performs the conversion the create path can
 * no longer perform.
 *
 * `CONVERT TO CHARACTER SET` rewrites every text column and re-encodes its
 * contents. Tables caught before any Bengali was written convert cleanly; text
 * already mangled to `?` by a non-strict host is not recoverable here, which is
 * why seedTemplates leaves existing rows alone rather than reseeding over them.
 */
final class M240121000000_ConvertNotificationTablesToUtf8mb4 implements RevertibleMigrationInterface
{
    /**
     * Every table M240109 creates, plus the pre-existing notification table —
     * the whole Bengali-facing surface of that migration.
     *
     * @var string[]
     */
    private const TABLES = [
        '{{%notification_queue}}',
        '{{%notification_delivery}}',
        '{{%notification_device}}',
        '{{%notification_preference}}',
        '{{%notification_template}}',
        '{{%api_token}}',
        '{{%bot_connection}}',
        '{{%notification}}',
    ];

    public function up(MigrationBuilder $b): void
    {
        foreach (self::TABLES as $table) {
            $this->convert($b, $table);
        }
    }

    public function down(MigrationBuilder $b): void
    {
        // Deliberately not reversible. Converting utf8mb4 back to latin1 would
        // destroy the Bengali content, and "reverting" a repair that protects
        // data is worse than leaving the migration one-way.
    }

    /**
     * Convert one table to utf8mb4 if it exists and is not already utf8mb4.
     *
     * Both conditions are checked before the statement rather than caught
     * afterwards: a table M240109 has not created yet must not fail this
     * migration, and neither must one that is already correct.
     */
    private function convert(MigrationBuilder $b, string $table): void
    {
        $db = $b->getDb();
        $name = $this->tableName($db, $table);

        $collation = $db
            ->createCommand('SHOW TABLE STATUS LIKE :name')
            ->bindValue(':name', $name)
            ->queryScalar();

        // queryScalar() is false when the LIKE matches nothing.
        if ($collation === false || $collation === null) {
            return;
        }

        $collation = (string) $collation;
        if (str_starts_with($collation, 'utf8mb4')) {
            return;
        }

        $b->execute(sprintf(
            'ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $db->getQuoter()->quoteTableName($name),
        ));
    }

    /**
     * Expand a {{%name}} token into the real table name.
     *
     * The connection applies the configured table prefix, so the token has to be
     * reduced to its bare name first — asking the quoter to expand it would
     * quote the braces.
     */
    private function tableName(\Yiisoft\Db\Connection\ConnectionInterface $db, string $table): string
    {
        $bare = str_replace(['{{%', '%}}', '{{', '}}'], '', $table);

        return $db->getTablePrefix() . $bare;
    }
}