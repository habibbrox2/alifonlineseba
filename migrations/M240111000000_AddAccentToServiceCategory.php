<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Per-category theme accent.
 *
 * `accent` holds a palette key (see App\Service\CategoryAccent::PALETTE) that
 * drives the card, chip, icon and button colours of every service inside the
 * category. NULL means "not chosen by an admin" — the resolver then falls back
 * to keyword matching on the name/slug and finally to a stable slug hash, so
 * existing categories get distinct accents without any backfill.
 */
final class M240111000000_AddAccentToServiceCategory implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%service_category}}', 'accent', "string(16) NULL DEFAULT 'auto'");
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%service_category}}', 'accent');
    }
}
