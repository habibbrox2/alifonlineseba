<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Role ranks table: stores custom role hierarchy ranks set by superadmin.
 * Default ranks are in Identity::ROLE_RANK; this table allows persistent overrides.
 */
final class M261009000000_CreateRoleRankTable implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%role_rank}}', [
            'id' => 'pk',
            'role' => 'string(16) NOT NULL',
            'rank' => 'integer NOT NULL',
            'updated_by' => 'integer NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%role_rank}}', 'ux_role_rank_role', ['role'], 'UNIQUE');
        $b->addForeignKey('{{%role_rank}}', 'fk_role_rank_user', 'updated_by', '{{%user}}', 'id');

        // Insert default ranks
        $defaults = [
            ['role' => 'superadmin', 'rank' => 100],
            ['role' => 'admin', 'rank' => 50],
            ['role' => 'staff', 'rank' => 25],
            ['role' => 'user', 'rank' => 10],
        ];
        foreach ($defaults as $row) {
            $b->insert('{{%role_rank}}', array_merge($row, [
                'updated_by' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
        }
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%role_rank}}');
    }
}