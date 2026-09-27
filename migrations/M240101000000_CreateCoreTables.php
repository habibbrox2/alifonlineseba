<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Core schema: users, categories, services, transactions, activity logs, notifications.
 */
final class M240101000000_CreateCoreTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%user}}', [
            'id' => 'pk',
            'username' => 'string(64) NOT NULL',
            'phone' => 'string(20) NOT NULL',
            'email' => 'string(190) NULL',
            'password_hash' => 'string(255) NOT NULL',
            'status' => "string(16) NOT NULL DEFAULT 'active'",
            'role' => "string(16) NOT NULL DEFAULT 'user'",
            'avatar' => 'string(255) NULL',
            'balance' => "decimal(12,2) NOT NULL DEFAULT '0.00'",
            'last_login_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%user}}', 'uk_user_username', ['username'], 'UNIQUE');
        $b->createIndex('{{%user}}', 'uk_user_phone', ['phone'], 'UNIQUE');
        $b->createIndex('{{%user}}', 'uk_user_email', ['email'], 'UNIQUE');

        $b->createTable('{{%service_category}}', [
            'id' => 'pk',
            'name' => 'string(120) NOT NULL',
            'slug' => 'string(120) NOT NULL',
            'icon' => 'string(64) NULL',
            'description' => 'string(500) NULL',
            'sort_order' => "integer NOT NULL DEFAULT '0'",
            'status' => "string(16) NOT NULL DEFAULT 'active'",
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%service_category}}', 'uk_category_slug', ['slug'], 'UNIQUE');

        $b->createTable('{{%service}}', [
            'id' => 'pk',
            'category_id' => 'integer NOT NULL',
            'name' => 'string(190) NOT NULL',
            'slug' => 'string(190) NOT NULL',
            'description' => 'text NULL',
            'icon' => 'string(64) NULL',
            'badge' => 'string(64) NULL',
            'service_type' => "string(16) NOT NULL DEFAULT 'mock'",
            'price' => "decimal(10,2) NOT NULL DEFAULT '0.00'",
            'status' => "string(16) NOT NULL DEFAULT 'active'",
            'sort_order' => "integer NOT NULL DEFAULT '0'",
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%service}}', 'uk_service_slug', ['slug'], 'UNIQUE');
        $b->createIndex('{{%service}}', 'ix_service_category', ['category_id']);
        $b->addForeignKey('{{%service}}', 'fk_service_category', 'category_id', '{{%service_category}}', 'id');

        $b->createTable('{{%transaction}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            'service_id' => 'integer NULL',
            'reference' => 'string(32) NOT NULL',
            'amount' => "decimal(12,2) NOT NULL DEFAULT '0.00'",
            'status' => "string(16) NOT NULL DEFAULT 'pending'",
            'metadata' => 'json NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%transaction}}', 'uk_transaction_reference', ['reference'], 'UNIQUE');
        $b->createIndex('{{%transaction}}', 'ix_transaction_user_created', ['user_id', 'created_at']);
        $b->createIndex('{{%transaction}}', 'ix_transaction_service', ['service_id']);
        $b->addForeignKey('{{%transaction}}', 'fk_transaction_user', 'user_id', '{{%user}}', 'id');
        $b->addForeignKey('{{%transaction}}', 'fk_transaction_service', 'service_id', '{{%service}}', 'id');

        $b->createTable('{{%activity_log}}', [
            'id' => 'pk',
            'user_id' => 'integer NULL',
            'action' => 'string(64) NOT NULL',
            'description' => 'string(500) NULL',
            'ip_address' => 'string(45) NULL',
            'user_agent' => 'string(512) NULL',
            'metadata' => 'json NULL',
            'created_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%activity_log}}', 'ix_activity_user', ['user_id']);
        $b->addForeignKey('{{%activity_log}}', 'fk_activity_user', 'user_id', '{{%user}}', 'id');

        $b->createTable('{{%notification}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            'title' => 'string(190) NOT NULL',
            'message' => 'text NULL',
            'type' => "string(16) NOT NULL DEFAULT 'info'",
            'read_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%notification}}', 'ix_notification_user_read', ['user_id', 'read_at']);
        $b->addForeignKey('{{%notification}}', 'fk_notification_user', 'user_id', '{{%user}}', 'id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%notification}}');
        $b->dropTable('{{%activity_log}}');
        $b->dropTable('{{%transaction}}');
        $b->dropTable('{{%service}}');
        $b->dropTable('{{%service_category}}');
        $b->dropTable('{{%user}}');
    }
}
