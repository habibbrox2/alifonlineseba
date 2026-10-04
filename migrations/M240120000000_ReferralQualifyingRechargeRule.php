<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Referral bonus after N qualifying recharges, not after the first one.
 *
 * The old rule paid on the referee's first approved recharge, which paid a
 * ৳50 bonus for a ৳50 top-up — a signup bonus dressed as a referral reward.
 * The rule this migration installs requires `required_count` approved
 * recharges of at least `min_amount` each, and `ReferralService::onTopupApproved()`
 * is what advances and settles it.
 *
 * Both numbers are snapshotted onto the referral row at signup rather than read
 * from `site_setting` at payout time, so an operator changing the rule later
 * cannot retroactively alter the terms of a referral that is already running.
 * That is the whole reason these columns exist instead of a lookup.
 *
 * Implementation notes in docs/referral-qualifying-recharge-rule.md.
 */
final class M240120000000_ReferralQualifyingRechargeRule implements RevertibleMigrationInterface
{
    /**
     * The two settings the rule reads, seeded so a fresh install has the
     * documented defaults in the admin form rather than blank inputs.
     *
     * The column defaults below are the same numbers: a referral created
     * between this migration and the first admin save must not be governed by
     * something the settings table has never heard of.
     *
     * @var array<string, string>
     */
    private const SETTINGS = [
        'referral_required_recharges' => '5',
        'referral_min_qualifying_recharge' => '100',
    ];

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%referral}}', 'required_count', 'integer NOT NULL DEFAULT 5');
        $b->addColumn('{{%referral}}', 'completed_count', 'integer NOT NULL DEFAULT 0');
        $b->addColumn('{{%referral}}', 'min_amount', "decimal(12,2) NOT NULL DEFAULT '100.00'");
        $b->addColumn('{{%referral}}', 'qualified_at', 'datetime NULL');
        $b->addColumn('{{%referral}}', 'paid_referrer_amount', "decimal(12,2) NOT NULL DEFAULT '0.00'");
        $b->addColumn('{{%referral}}', 'paid_referee_amount', "decimal(12,2) NOT NULL DEFAULT '0.00'");

        // The admin list sorts and filters on progress, which is `status`
        // narrowed by the two counters — an index on the counters alone would
        // not narrow to the pending rows that list is mostly made of.
        $b->createIndex(
            '{{%referral}}',
            'ix_referral_progress',
            ['status', 'completed_count', 'required_count'],
        );

        $this->seedSettings($b);
        $this->backfillPaidReferrals($b);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('{{%referral}}', 'ix_referral_progress');
        $b->dropColumn('{{%referral}}', 'paid_referee_amount');
        $b->dropColumn('{{%referral}}', 'paid_referrer_amount');
        $b->dropColumn('{{%referral}}', 'qualified_at');
        $b->dropColumn('{{%referral}}', 'min_amount');
        $b->dropColumn('{{%referral}}', 'completed_count');
        $b->dropColumn('{{%referral}}', 'required_count');

        // The two setting rows are deliberately left behind: they are ordinary
        // operator-owned configuration, an install that has migrated up and
        // back down may still want the values an operator has since tuned.
    }

    /**
     * Write each default only if the key is absent.
     *
     * `INSERT … SELECT … WHERE NOT EXISTS` rather than an upsert: an operator
     * who has already set `referral_required_recharges` to 12 must not have it
     * silently reset to 5 by a re-run, and this form is safe to repeat.
     */
    private function seedSettings(MigrationBuilder $b): void
    {
        $db = $b->getDb();
        $now = date('Y-m-d H:i:s');

        foreach (self::SETTINGS as $key => $value) {
            $db->createCommand(
                'INSERT INTO {{%site_setting}} ([[setting_key]], [[setting_value]], [[updated_at]])'
                . ' SELECT :key, :value, :now'
                . ' WHERE NOT EXISTS (SELECT 1 FROM {{%site_setting}} WHERE [[setting_key]] = :key)'
            )->bindValues([':key' => $key, ':value' => $value, ':now' => $now])->execute();
        }
    }

    /**
     * Referrals already paid under the old rule have, by definition, met the
     * new one — they were settled on a recharge.
     *
     * Left at `0 / NULL` they would be reported as incomplete progress on a
     * row that has already moved money twice, which is both wrong and the sort
     * of thing an operator escalates as missing money. No balance or ledger
     * row is touched: this only describes what already happened.
     */
    private function backfillPaidReferrals(MigrationBuilder $b): void
    {
        $b->execute(
            'UPDATE {{%referral}} SET [[completed_count]] = [[required_count]],'
            . ' [[qualified_at]] = [[updated_at]] WHERE [[status]] = \'paid\''
        );
    }
}