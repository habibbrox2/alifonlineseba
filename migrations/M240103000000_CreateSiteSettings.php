<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Site settings: admin-managed key/value store powering site info,
 * social links and footer content on public pages.
 */
final class M240103000000_CreateSiteSettings implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%site_setting}}', [
            'id' => 'pk',
            'setting_key' => 'string(64) NOT NULL',
            'setting_value' => 'string(500) NOT NULL DEFAULT \'\'',
            'updated_by' => 'integer NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%site_setting}}', 'ux_site_setting_key', 'setting_key', 'UNIQUE');
        $b->addForeignKey('{{%site_setting}}', 'fk_site_setting_user', 'updated_by', '{{%user}}', 'id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%site_setting}}');
    }
}
