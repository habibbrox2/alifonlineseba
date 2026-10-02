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
 */
final class M240109000000_CreateNotificationInfrastructure implements RevertibleMigrationInterface
{
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
        ]));
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
        ]));
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
        ]));
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
        ]));
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
        ]));
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
        ]));
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
        ]));
        // chat_id is the addressing primitive, not proof of identity — one row
        // per admin Telegram account, nothing else stored.
        $this->safe(fn () => $b->createIndex('{{%bot_connection}}', 'uk_bot_chat', ['chat_id'], 'UNIQUE'));
        $this->safe(fn () => $b->createIndex('{{%bot_connection}}', 'uk_bot_user', ['user_id'], 'UNIQUE'));
        $this->safe(fn () => $b->addForeignKey('{{%bot_connection}}', 'fk_bot_user', 'user_id', '{{%user}}', 'id'));

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
