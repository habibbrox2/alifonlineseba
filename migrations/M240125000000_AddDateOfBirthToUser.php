<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * A birth date on the account itself.
 *
 * Every service form in this product asks for a date of birth, and until now
 * that value only ever existed inside one order's JSON metadata — where it is
 * reachable from a service history row but not from the account, so the person
 * could not be asked for it once and be spared typing it again on the next
 * order. This column is that one place to keep it.
 *
 * `DATE`, not `DATETIME`: a birthday is a calendar day, and a time on it would
 * imply a moment that does not exist and invite timezone bugs the first time
 * something compares it to `NOW()`.
 *
 * NULL rather than a zero date, because nobody may answer an optional question
 * and an absent answer has to stay distinguishable from a real one.
 *
 * The stored value is `Y-m-d`, like every other date in the database. The
 * person sees and types `DD-MM-YYYY`; `App\Service\ServiceDate` is the only
 * thing that converts, and the profile and admin forms render the same widget
 * (`components/date-field.twig`) the service forms use.
 */
final class M240125000000_AddDateOfBirthToUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'date_of_birth', 'date NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%user}}', 'date_of_birth');
    }
}