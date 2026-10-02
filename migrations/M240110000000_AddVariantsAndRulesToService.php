<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Per-service ordering parity with the reference site:
 *
 * - `variants`   — JSON list of purchasable variants, e.g.
 *                  [{"label":"অরিজিনাল কপি","price":45},{"label":"স্মার্ট কার্ড কপি","price":60}].
 *                  NULL/empty list means the single base `price` applies (no selector shown).
 * - `rules`      — per-service ordering rules/instructions shown next to the order form
 *                  (newlines split into bullet points in the UI).
 */
final class M240110000000_AddVariantsAndRulesToService implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%service}}', 'variants', 'json NULL');
        $b->addColumn('{{%service}}', 'rules', 'text NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%service}}', 'variants');
        $b->dropColumn('{{%service}}', 'rules');
    }
}
