<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Gives the recharge queue a real "somebody is on it" state.
 *
 * Until now a request went straight from `pending` to a decision, which meant
 * two admins working the same queue could both open the same TrxID and both
 * press approve. The second press was caught by the idempotency guard, but by
 * then the operator had already looked at the same receipt twice and the second
 * one bounced back as a confusing "already processed" error.
 *
 * So the queue gains a middle state:
 *
 *   pending  — nobody has looked at it yet          (অপেক্ষমাণ)
 *   review   — a named admin has claimed it          (যাচাই-ধরা)
 *   approved / rejected — decided
 *
 * The claim is recorded in `claimed_by` / `claimed_at` so the list can show
 * *who* is on a request, which is what makes two operators colliding visible
 * instead of merely harmless.
 *
 * `status` is a `varchar(16)`, so adding the value needs no ALTER — only the
 * two claim columns do.
 */
final class M240107000000_AddReviewClaimToTopupRequest implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%topup_request}}', 'claimed_by', 'integer NULL');
        $b->addColumn('{{%topup_request}}', 'claimed_at', 'datetime NULL');

        // No ON DELETE clause, matching fk_topup_reviewer: a claim is a
        // historical fact about a row, and the two keys must behave the same
        // way when a user row ever goes away.
        $b->addForeignKey('{{%topup_request}}', 'fk_topup_claimer', 'claimed_by', '{{%user}}', 'id');

        // Anything an admin had already opened (verified_at set) was, in the old
        // flow, silently "being worked on". Promote those so the new state
        // describes reality instead of restarting the queue from a blank slate.
        $b->execute(
            "UPDATE {{%topup_request}} SET [[status]] = 'review'"
            . " WHERE [[status]] = 'pending' AND [[verified_at]] IS NOT NULL",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Only un-decided claims are demoted; a finished review is left alone
        // because rolling it back to "pending" would put it back in the queue.
        $b->execute(
            "UPDATE {{%topup_request}} SET [[status]] = 'pending'"
            . " WHERE [[status]] = 'review'",
        );

        $b->dropForeignKey('{{%topup_request}}', 'fk_topup_claimer');
        $b->dropColumn('{{%topup_request}}', 'claimed_at');
        $b->dropColumn('{{%topup_request}}', 'claimed_by');
    }
}
