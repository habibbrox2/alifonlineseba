<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Phase 1 + 1.5 of docs/notification-architecture-audit.md, in one migration:
 *
 * A. Additive event columns on {{%notification}} (the table and its UI/API stay).
 * B. The queue / delivery / device / preference / template tables the channel
 *    design needs.
 * C. bot_connection + api_token for the machine-auth the APK is blocked on.
 *
 * No existing table is replaced and no column is dropped. Every DDL step is
 * guarded: if a prior run crashed midway (the first attempt died on the
 * bot_connection bigint), re-running finishes the job instead of failing on
 * the first duplicate object.
 *
 * The charset repair below the seed is not decoration — it is what makes the
 * migration finish on a host that already has these tables. See
 * repairCharsetForExistingTables() for the deadlock it breaks.
 */
final class M240109000000_CreateNotificationInfrastructure implements RevertibleMigrationInterface
{
    /**
     * Every table gets its charset stated outright.
     *
     * Without it a table inherits whatever the database defaults to, and on the
     * cPanel host that is latin1 — which cannot represent Bengali. Seeding
     * `স্বাগতম!` then dies with "Incorrect string value ... for column title",
     * under strict SQL mode. The local MariaDB is non-strict and silently stored
     * `??????!` instead, so this only ever showed up on the real host.
     *
     * Being explicit also stops the schema from changing meaning if someone
     * alters the database default later.
     */
    private const CHARSET = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    /**
     * Every table this migration touches, for the charset repair.
     *
     * The same list M240121000000 converts, kept here as a literal rather than
     * shared: a migration has to keep working on its own years from now, and a
     * shared helper refactored in the meantime must not be able to stop a
     * production host from migrating.
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
        // ---- A. Event columns on the existing table -------------------------
        $this->safe(fn () => $b->addColumn('{{%notification}}', 'event', 'string(64) NULL'));
        $this->safe(fn () => $b->addColumn('{{%notification}}', 'link', 'string(255) NULL'));
        $this->safe(fn () => $b->addColumn('{{%notification}}', 'priority', "tinyint NOT NULL DEFAULT '5'"));
        $this->safe(fn () => $b->createIndex('{{%notification}}', 'ix_notification_user_created', ['user_id', 'created_at']));

        // ---- B. Queue + delivery + devices + prefs + templates --------------
        $this->safe(fn () => $b->createTable('{{%notification_queue}}', [
            'id' => 'pk',
            'notification_id' => 'integer NULL',
            'event' => 'string(64) NOT NULL',
            'user_id' => 'integer NULL',
            'channel' => 'string(16) NOT NULL',
            'status' => "string(16) NOT NULL DEFAULT 'queued'",
            'attempts' => "tinyint NOT NULL DEFAULT '0'",
            'max_attempts' => "tinyint NOT NULL DEFAULT '3'",
            'available_at' => 'datetime NOT NULL',
            'payload' => 'json NULL',
            'dedupe_key' => 'char(40) NOT NULL',
            'last_error' => 'string(500) NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        // The uniqueness IS the idempotency guarantee: a replayed business
        // action re-enqueues with the same key and becomes a no-op.
        $this->safe(fn () => $b->createIndex('{{%notification_queue}}', 'uk_queue_dedupe', ['dedupe_key'], 'UNIQUE'));
        // The worker's only hot query.
        $this->safe(fn () => $b->createIndex('{{%notification_queue}}', 'ix_queue_poll', ['status', 'available_at']));
        $this->safe(fn () => $b->addForeignKey('{{%notification_queue}}', 'fk_queue_user', 'user_id', '{{%user}}', 'id'));

        $this->safe(fn () => $b->createTable('{{%notification_delivery}}', [
            'id' => 'pk',
            'queue_id' => 'integer NOT NULL',
            'channel' => 'string(16) NOT NULL',
            'status' => "string(16) NOT NULL DEFAULT 'queued'",
            'attempts' => "tinyint NOT NULL DEFAULT '0'",
            'max_attempts' => "tinyint NOT NULL DEFAULT '3'",
            'last_error' => 'string(500) NULL',
            'provider_response' => 'json NULL',
            'provider_message_id' => 'string(190) NULL',
            'latency_ms' => 'integer NULL',
            'sent_at' => 'datetime NULL',
            'delivered_at' => 'datetime NULL',
            'failed_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        $this->safe(fn () => $b->createIndex('{{%notification_delivery}}', 'uk_delivery_provider_message', ['provider_message_id'], 'UNIQUE'));
        $this->safe(fn () => $b->createIndex('{{%notification_delivery}}', 'ix_delivery_status_created', ['status', 'created_at']));
        $this->safe(fn () => $b->addForeignKey('{{%notification_delivery}}', 'fk_delivery_queue', 'queue_id', '{{%notification_queue}}', 'id'));

        $this->safe(fn () => $b->createTable('{{%notification_device}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            'device_token' => 'string(512) NOT NULL',
            'platform' => "string(16) NOT NULL DEFAULT 'android'",
            'device_name' => 'string(120) NULL',
            'app_version' => 'string(32) NULL',
            'is_active' => "tinyint NOT NULL DEFAULT '1'",
            'last_seen_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        // A token is the identity of a device: unique index = idempotent upsert
        // AND the deactivation lookup in one place.
        $this->safe(fn () => $b->createIndex('{{%notification_device}}', 'uk_device_token', ['device_token'], 'UNIQUE'));
        $this->safe(fn () => $b->createIndex('{{%notification_device}}', 'ix_device_user_active', ['user_id', 'is_active']));
        $this->safe(fn () => $b->addForeignKey('{{%notification_device}}', 'fk_device_user', 'user_id', '{{%user}}', 'id'));

        $this->safe(fn () => $b->createTable('{{%notification_preference}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            'event' => 'string(64) NOT NULL',
            'channel' => 'string(16) NOT NULL',
            'enabled' => "tinyint NOT NULL DEFAULT '1'",
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        // Overrides only: no row = global default. Keeps the table tiny and
        // the global default changeable without a migration.
        $this->safe(fn () => $b->createIndex('{{%notification_preference}}', 'uk_preference_user_event_channel', ['user_id', 'event', 'channel'], 'UNIQUE'));
        $this->safe(fn () => $b->addForeignKey('{{%notification_preference}}', 'fk_preference_user', 'user_id', '{{%user}}', 'id'));

        $this->safe(fn () => $b->createTable('{{%notification_template}}', [
            'id' => 'pk',
            'event' => 'string(64) NOT NULL',
            'channel' => 'string(16) NOT NULL',
            'locale' => "string(8) NOT NULL DEFAULT 'bn'",
            'title' => 'string(190) NOT NULL',
            'body' => 'text NULL',
            'external_id' => 'string(190) NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        $this->safe(fn () => $b->createIndex('{{%notification_template}}', 'uk_template_event_channel_locale', ['event', 'channel', 'locale'], 'UNIQUE'));

        // ---- C. Machine auth + bot ------------------------------------------
        $this->safe(fn () => $b->createTable('{{%api_token}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            'token_hash' => 'char(64) NOT NULL',
            'device_label' => 'string(120) NULL',
            'expires_at' => 'datetime NULL',
            'last_used_at' => 'datetime NULL',
            'revoked_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        $this->safe(fn () => $b->createIndex('{{%api_token}}', 'uk_api_token_hash', ['token_hash'], 'UNIQUE'));
        $this->safe(fn () => $b->createIndex('{{%api_token}}', 'ix_api_token_user', ['user_id']));
        $this->safe(fn () => $b->addForeignKey('{{%api_token}}', 'fk_api_token_user', 'user_id', '{{%user}}', 'id'));

        $this->safe(fn () => $b->createTable('{{%bot_connection}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            // Telegram chat ids exceed 32-bit but are NOT the primary key —
            // bigpk here would collide with the id auto-increment (MySQL 1075).
            'chat_id' => 'bigint NOT NULL',
            'username' => 'string(64) NULL',
            'verified_at' => 'datetime NULL',
            'is_active' => "tinyint NOT NULL DEFAULT '1'",
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET));
        // chat_id is the addressing primitive, not proof of identity — one row
        // per admin Telegram account, nothing else stored.
        $this->safe(fn () => $b->createIndex('{{%bot_connection}}', 'uk_bot_chat', ['chat_id'], 'UNIQUE'));
        $this->safe(fn () => $b->createIndex('{{%bot_connection}}', 'uk_bot_user', ['user_id'], 'UNIQUE'));
        $this->safe(fn () => $b->addForeignKey('{{%bot_connection}}', 'fk_bot_user', 'user_id', '{{%user}}', 'id'));

        // ---- Repair, then seed ----------------------------------------------
        // Load-bearing order: the seed writes Bengali into notification_template.
        $this->safe(fn () => $this->repairCharsetForExistingTables($b));

        // ---- Seed the template table with the compiled-in copy --------------
        $this->safe(fn () => $this->seedTemplates($b));
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%bot_connection}}');
        $b->dropTable('{{%api_token}}');
        $b->dropTable('{{%notification_template}}');
        $b->dropTable('{{%notification_preference}}');
        $b->dropTable('{{%notification_device}}');
        $b->dropTable('{{%notification_delivery}}');
        $b->dropTable('{{%notification_queue}}');
        $b->dropIndex('{{%notification}}', 'ix_notification_user_created');
        $b->dropColumn('{{%notification}}', 'priority');
        $b->dropColumn('{{%notification}}', 'link');
        $b->dropColumn('{{%notification}}', 'event');
    }

    /**
     * Bring any of these tables that already exist into utf8mb4 before
     * anything writes Bengali into them.
     *
     * Why this has to live *here*, and not only in the later conversion
     * migration, is the shape of the failure it was written for.
     *
     * A host that ran this migration before CHARSET existed has the tables
     * already, in whatever the database default was — latin1 on cPanel. On
     * such a host `createTable` reports "table exists" (errno 1050) and is
     * skipped, so the columns stay latin1, and the seed below then dies:
     *
     *   SQLSTATE[22007] ... Incorrect string value: '\xE0\xA6\xB8...'
     *   for column `notification_template`.`title` at row 1
     *
     * `safe()` swallows duplicate-object errors, not this one, so the
     * migration aborts here — which means the conversion migration that was
     * written to repair it (M240121000000) never gets to run, on this run or
     * any future one. The host is stuck: every `yii migrate:up` dies at the
     * same line, and the repair sits behind it in the queue.
     *
     * Repairing first breaks that loop. The conversion is guarded on the
     * table's actual collation, so a fresh install (whose tables were just
     * created as utf8mb4) pays nothing.
     */
    private function repairCharsetForExistingTables(MigrationBuilder $b): void
    {
        foreach (self::TABLES as $table) {
            $db = $b->getDb();
            $name = $this->tableName($db, $table);

            $row = $db
                ->createCommand('SHOW TABLE STATUS LIKE :name')
                ->bindValue(':name', $name)
                ->queryOne();

            // queryOne() is null when the LIKE matches no table: a host that
            // has not created this one yet must not fail the migration.
            $collation = $row['Collation'] ?? null;
            if ($collation === null || str_starts_with((string) $collation, 'utf8mb4')) {
                continue;
            }

            $b->execute(sprintf(
                'ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $db->getQuoter()->quoteTableName($name),
            ));
        }
    }

    /**
     * Expand a {{%name}} token into the real, prefixed table name.
     *
     * The connection applies the configured table prefix, so the token is
     * reduced to its bare name first — asking the quoter to expand it would
     * quote the braces.
     */
    private function tableName(\Yiisoft\Db\Connection\ConnectionInterface $db, string $table): string
    {
        $bare = str_replace(['{{%', '%}}', '{{', '}}'], '', $table);

        return $db->getTablePrefix() . $bare;
    }

    private function seedTemplates(MigrationBuilder $b): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (\App\Notification\MessageTemplates::seedRows() as $row) {
            $exists = $b->getDb()
                ->createCommand(
                    'SELECT [[id]] FROM {{%notification_template}} WHERE [[event]] = :e AND [[channel]] = :c LIMIT 1'
                )
                ->bindValues([':e' => $row['event'], ':c' => $row['channel']])
                ->queryScalar();
            if ($exists !== false) {
                continue;
            }
            $b->getDb()->createCommand()->insert('{{%notification_template}}', $row + [
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        }
    }

    /**
     * Run one DDL step, skipping the "already exists" errors a crashed prior
     * run leaves behind. Any other error propagates.
     */
    private function safe(callable $step): void
    {
        try {
            $step();
        } catch (\Yiisoft\Db\Exception\Exception $e) {
            $msg = $e->getMessage();
            $already = str_contains($msg, '1060') // duplicate column
                || str_contains($msg, '1061') // duplicate key name
                || str_contains($msg, '1050') // table exists
                || str_contains($msg, '1005') // errno 121 lives inside: duplicate FK name
                || str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key')
                || str_contains($msg, 'already exists');
            if (!$already) {
                throw $e;
            }
        }
    }
}
