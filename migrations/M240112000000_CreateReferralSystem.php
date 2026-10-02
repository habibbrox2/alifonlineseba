<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Referral ("বন্ধুকে রেফার করে বোনাস") system.
 *
 * Two halves:
 *
 * - `{{%user}}` gains `referral_code` (the shareable handle) and
 *   `referred_by` / `referred_at` (who invited this account, and when).
 *   The self-reference lives on the user row rather than only in
 *   `{{%referral}}` because "one account can only ever be referred once" is a
 *   property of the account, and a UNIQUE index on `referral.referee_id` can
 *   express it in the database rather than in application code.
 *
 * - `{{%referral}}` is the ledger row: it records the invite, the status, the
 *   recharge that triggered the payout, and how much each side was paid. It is
 *   kept even after the payout so the money movement stays auditable, exactly
 *   like `{{%transaction}}` and `{{%topup_request}}`.
 *
 * `referral_code` is backfilled for existing accounts rather than left NULL:
 * every user has to be able to share a link the moment the feature switches on,
 * and a code that only appears on the next registration would silently exclude
 * everyone who signed up before the migration. `UserRepository::ensureReferralCode()`
 * covers any row that still ends up empty.
 */
final class M240112000000_CreateReferralSystem implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'referral_code', 'string(16) NULL');
        $b->addColumn('{{%user}}', 'referred_by', 'integer NULL');
        $b->addColumn('{{%user}}', 'referred_at', 'datetime NULL');
        $b->createIndex('{{%user}}', 'ux_user_referral_code', 'referral_code', 'UNIQUE');
        $b->addForeignKey('{{%user}}', 'fk_user_referrer', 'referred_by', '{{%user}}', 'id');

        $b->createTable('{{%referral}}', [
            'id' => 'pk',
            'referrer_id' => 'integer NOT NULL',
            'referee_id' => 'integer NOT NULL',
            'code' => 'string(16) NOT NULL',
            // pending  = friend signed up, waiting for their first recharge
            // paid     = both bonuses credited
            // rejected = admin voided it (self-referral, abuse, refund)
            'status' => "string(16) NOT NULL DEFAULT 'pending'",
            // The approved top-up that triggered the payout, and what it was for.
            'trigger_topup_id' => 'integer NULL',
            'trigger_amount' => 'decimal(12,2) NULL',
            'referrer_amount' => "decimal(12,2) NOT NULL DEFAULT '0.00'",
            'referee_amount' => "decimal(12,2) NOT NULL DEFAULT '0.00'",
            // One transaction row per credited side, so the ledger is symmetrical.
            'referrer_transaction_id' => 'integer NULL',
            'referee_transaction_id' => 'integer NULL',
            'reviewed_by' => 'integer NULL',
            'reviewed_at' => 'datetime NULL',
            'admin_note' => 'string(500) NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%referral}}', 'ux_referral_referee', 'referee_id', 'UNIQUE');
        $b->createIndex('{{%referral}}', 'ix_referral_referrer', 'referrer_id');
        $b->createIndex('{{%referral}}', 'ix_referral_status', 'status');
        $b->createIndex('{{%referral}}', 'ix_referral_code', 'code');
        $b->addForeignKey('{{%referral}}', 'fk_referral_referrer', 'referrer_id', '{{%user}}', 'id');
        $b->addForeignKey('{{%referral}}', 'fk_referral_referee', 'referee_id', '{{%user}}', 'id');
        $b->addForeignKey('{{%referral}}', 'fk_referral_topup', 'trigger_topup_id', '{{%topup_request}}', 'id');
        $b->addForeignKey('{{%referral}}', 'fk_referral_reviewer', 'reviewed_by', '{{%user}}', 'id');
        $b->addForeignKey('{{%referral}}', 'fk_referral_tx_referrer', 'referrer_transaction_id', '{{%transaction}}', 'id');
        $b->addForeignKey('{{%referral}}', 'fk_referral_tx_referee', 'referee_transaction_id', '{{%transaction}}', 'id');

        $this->backfillCodes($b);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%referral}}');
        $b->dropForeignKey('{{%user}}', 'fk_user_referrer');
        $b->dropIndex('{{%user}}', 'ux_user_referral_code');
        $b->dropColumn('{{%user}}', 'referred_at');
        $b->dropColumn('{{%user}}', 'referred_by');
        $b->dropColumn('{{%user}}', 'referral_code');
    }

    /**
     * Give every existing account a code, walking the ids rather than issuing
     * random ones in a loop: a collision under the UNIQUE index would abort the
     * whole migration, whereas a deterministic id-derived code can be retried
     * after a salted suffix with no risk of a half-populated column.
     *
     * The alphabet is inlined rather than shared with the app's generator on
     * purpose — a migration has to keep working unchanged even if the runtime
     * generator is later retuned, and old migrations that reach back into
     * `src/` are a trap.
     */
    private function backfillCodes(MigrationBuilder $b): void
    {
        // No 0/O/1/I/L: these codes get read aloud, typed from a screenshot and
        // re-keyed into a URL, so every ambiguous glyph is a support ticket.
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $size = strlen($alphabet);
        $db = $b->getDb();
        $ids = $db->createCommand('SELECT [[id]] FROM {{%user}} ORDER BY [[id]]')->queryColumn();

        foreach ($ids as $id) {
            $code = '';
            // 8^6-ish space from a per-account hash: collisions are the one
            // failure mode that would abort the migration, so the loop retries
            // with a salt rather than assuming the first draw is free.
            for ($salt = 0; $salt < 5; $salt++) {
                $hash = hash('sha256', 'alif-referral:' . $id . ':' . $salt);
                $code = '';
                for ($i = 0; $i < 6; $i++) {
                    $code .= $alphabet[hexdec(substr($hash, $i * 2, 2)) % $size];
                }
                $taken = $db
                    ->createCommand('SELECT COUNT(*) FROM {{%user}} WHERE [[referral_code]] = :c')
                    ->bindValue(':c', $code)
                    ->queryScalar();
                if ((int) $taken === 0) {
                    break;
                }
            }

            $b->update('{{%user}}', ['referral_code' => $code], ['id' => (int) $id]);
        }
    }
}
