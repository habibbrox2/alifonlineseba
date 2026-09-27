<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Per-service form field configuration.
 *
 * Stores a JSON list of {name, required} objects (e.g. NID number, date of birth)
 * on the service row. NULL means "use the provider defaults"; an empty list means
 * "this service takes no input fields at all".
 */
final class M240104000000_AddFormFieldsToService implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%service}}', 'form_fields', 'json NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%service}}', 'form_fields');
    }
}
