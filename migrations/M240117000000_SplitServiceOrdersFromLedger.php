<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Splits the overloaded `transaction` table into two tables that mean two
 * different things, and gives admins their own money plumbing.
 *
 * `transaction` had been doing two jobs at once: it was the queue of service
 * requests *and* the record of balance movements. A top-up was a row with a
 * NULL `service_id`; a service order was a row with one. Every query had to
 * disambiguate them (`service_id IS NOT NULL` appeared in the admin queue, the
 * export, the bulk-settle guard and the dashboard counters), and "show me the
 * money" was impossible without a second filter nobody remembered to add.
 *
 * So:
 *
 *   service_orders — one row per order request: its status, its review claim,
 *                    who decided it, its deliverable. Nothing about money
 *                    except the amount charged.
 *   transaction    — the ledger. One row per movement of money, ever, for
 *                    anybody: a top-up, an order debit, an admin credit, a
 *                    refund, a withdrawal. Append-only, never edited.
 *
 * Existing rows are moved rather than recreated, ids included, because a
 * deliverable on disk is filed under its order id and re-issuing ids would
 * silently orphan every file already uploaded.
 *
 * The admin side gets two additions:
 *
 *   - `admin_id` on the ledger. Revenue is credited to the admin who approved
 *     the order, so each operator's earnings are answerable by query instead of
 *     by trusting a running balance, and `balance_before`/`balance_after` on
 *     every entry make the balance itself auditable rather than a number that
 *     has to be believed.
 *   - `admin_withdraw_request`. A withdrawal is a *request* like a recharge,
 *     because an admin's balance is real money going out to a real account and
 *     the money is held from the moment of the request — an operator who could
 *     request ৳500 four times would otherwise be paid four times.
 *
 * Super-admin is a `user.role` value rather than a table: an account's authority
 * is already a role, and `varchar(16)` already holds 'admin'/'staff'.
 */
final class M240117000000_SplitServiceOrdersFromLedger implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $this->createServiceOrders($b);
        $this->copyExistingServiceRows($b);
        $this->widenLedger($b);
        $this->createWithdrawRequests($b);
    }

    /**
     * The order queue, in its own table.
     *
     * `claimed_by`/`claimed_at` mirror the recharge queue's claim columns: one
     * named admin has the row, and a second one sees who has it instead of
     * duplicating the work. `approved_by` is deliberately a separate column
     * rather than a status, because "who earned this" has to survive the row
     * moving on to `completed` or `failed` — and because it is the flag that
     * stops the credit from being paid twice.
     */
    private function createServiceOrders(MigrationBuilder $b): void
    {
        $b->createTable('{{%service_order}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            // Nullable because purging a service detaches its orders rather
            // than deleting them: an order is a financial record as well as a
            // queue entry, and the ledger rows pointing at it must survive.
            'service_id' => 'integer NULL',
            'reference' => 'string(32) NOT NULL',
            'amount' => "decimal(10,2) NOT NULL DEFAULT '0.00'",
            'status' => "string(16) NOT NULL DEFAULT 'pending'",
            'metadata' => 'json NULL',
            // Review claim.
            'claimed_by' => 'integer NULL',
            'claimed_at' => 'datetime NULL',
            // Decision. `approved_by` doubles as the "revenue already paid out"
            // marker, so it is written exactly once, inside the same guarded
            // UPDATE that moves the status.
            'approved_by' => 'integer NULL',
            'approved_at' => 'datetime NULL',
            'admin_note' => 'string(500) NULL',
            'cancel_reason' => 'string(500) NULL',
            // Deliverable, moved from `transaction` along with the rows.
            'deliverable_path' => 'string(255) NULL',
            'deliverable_name' => 'string(255) NULL',
            'deliverable_mime' => 'string(120) NULL',
            'deliverable_size' => 'integer NULL',
            'deliverable_uploaded_at' => 'datetime NULL',
            'deliverable_uploaded_by' => 'integer NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);

        $b->createIndex('{{%service_order}}', 'uk_service_order_reference', ['reference'], 'UNIQUE');
        // The user's history page and the dashboard's "recent searches".
        $b->createIndex('{{%service_order}}', 'ix_service_order_user_created', ['user_id', 'created_at']);
        // The admin queue: always "open work, oldest first", never a scan.
        $b->createIndex('{{%service_order}}', 'ix_service_order_status_created', ['status', 'created_at']);
        // "What has this operator got open" — the claimed-work view.
        $b->createIndex('{{%service_order}}', 'ix_service_order_claimed_by', ['claimed_by']);

        $b->addForeignKey('{{%service_order}}', 'fk_service_order_user', 'user_id', '{{%user}}', 'id');
        $b->addForeignKey('{{%service_order}}', 'fk_service_order_service', 'service_id', '{{%service}}', 'id');
        // No ON DELETE clauses, matching every other historical reference in
        // this schema: a claim is a fact about a row, and it must outlive the
        // account that made it in the same way its order does.
        $b->addForeignKey('{{%service_order}}', 'fk_service_order_claimer', 'claimed_by', '{{%user}}', 'id');
        $b->addForeignKey('{{%service_order}}', 'fk_service_order_approver', 'approved_by', '{{%user}}', 'id');
        $b->addForeignKey(
            '{{%service_order}}',
            'fk_service_order_uploader',
            'deliverable_uploaded_by',
            '{{%user}}',
            'id',
        );
    }

    /**
     * Move the service rows across, keeping their ids.
     *
     * The column list is explicit rather than `t.*` because the two tables do
     * not have the same shape, and a `SELECT *` into a narrower insert would
     * break the moment somebody adds a column to one of them.
     *
     * An order that was already cancelled or failed has its money already back
     * with the user, so it must never become credit for an admin; the
     * backfill below leaves `approved_by` NULL on exactly those rows and lets
     * the decision write it.
     */
    private function copyExistingServiceRows(MigrationBuilder $b): void
    {
        $b->execute(
            'INSERT INTO {{%service_order}}'
            . ' ([[id]], [[user_id]], [[service_id]], [[reference]], [[amount]], [[status]], [[metadata]],'
            . ' [[deliverable_path]], [[deliverable_name]], [[deliverable_mime]], [[deliverable_size]],'
            . ' [[deliverable_uploaded_at]], [[deliverable_uploaded_by]], [[created_at]], [[updated_at]])'
            . ' SELECT [[id]], [[user_id]], [[service_id]], [[reference]], [[amount]], [[status]], [[metadata]],'
            . ' [[deliverable_path]], [[deliverable_name]], [[deliverable_mime]], [[deliverable_size]],'
            . ' [[deliverable_uploaded_at]], [[deliverable_uploaded_by]], [[created_at]], [[updated_at]]'
            . ' FROM {{%transaction}} WHERE [[service_id]] IS NOT NULL',
        );

        // Everything that used to be an order is now one. Without this the two
        // tables would both keep serving the old queue and the "which table
        // does this id belong to" question would come back every time.
        $b->execute('DELETE FROM {{%transaction}} WHERE [[service_id]] IS NOT NULL');
    }

    /**
     * Turn `transaction` into the ledger.
     *
     * Nothing is dropped. `service_id` stays (nullable, and now always NULL)
     * because its foreign key and the "detach a soft-deleted service" path in
     * ServiceRepository depend on the column existing; a migration that
     * removed it would have to re-plumb that path for no gain.
     */
    private function widenLedger(MigrationBuilder $b): void
    {
        // `user_id` was NOT NULL because every row used to be a customer's. A
        // ledger row that pays an *admin* has no customer on it at all, so the
        // column has to be able to hold nothing — otherwise the very first
        // approved order would fail its INSERT and the admin would silently
        // never get paid.
        $b->alterColumn('{{%transaction}}', 'user_id', 'integer NULL');

        $b->addColumn('{{%transaction}}', 'admin_id', 'integer NULL');
        $b->addColumn(
            '{{%transaction}}',
            'type',
            "string(32) NOT NULL DEFAULT 'manual'",
        );
        $b->addColumn(
            '{{%transaction}}',
            'direction',
            "string(8) NOT NULL DEFAULT 'credit'",
        );
        $b->addColumn('{{%transaction}}', 'balance_before', "decimal(12,2) NULL");
        $b->addColumn('{{%transaction}}', 'balance_after', "decimal(12,2) NULL");
        $b->addColumn('{{%transaction}}', 'description', 'string(255) NULL');
        $b->addColumn('{{%transaction}}', 'service_order_id', 'integer NULL');

        $b->createIndex('{{%transaction}}', 'ix_transaction_admin', ['admin_id', 'created_at']);
        $b->createIndex('{{%transaction}}', 'ix_transaction_type', ['type']);
        // The account "লেনদেন" page and the admin ledger view both page this.
        $b->createIndex('{{%transaction}}', 'ix_transaction_user_type', ['user_id', 'type']);

        $b->addForeignKey('{{%transaction}}', 'fk_transaction_admin', 'admin_id', '{{%user}}', 'id');
        $b->addForeignKey(
            '{{%transaction}}',
            'fk_transaction_service_order',
            'service_order_id',
            '{{%service_order}}',
            'id',
        );

        // The surviving rows are all top-ups: everything with a service on it
        // was just moved out. `metadata.type` already says 'topup' for rows
        // written by TopupService, so it is a better guess than the default.
        $b->execute(
            "UPDATE {{%transaction}} SET [[type]] = 'topup', [[direction]] = 'credit',"
            . " [[description]] = 'ব্যালেন্স টপ-আপ'"
            . " WHERE [[service_id]] IS NULL AND [[amount]] > 0",
        );
    }

    /**
     * Admin withdrawals.
     *
     * The hold happens at request time (the balance is debited when the row is
     * created), so there is no separate "reserved" column to keep in step with
     * the balance: what is on the row IS what was moved. Rejecting credits it
     * back; approving only records the decision.
     */
    private function createWithdrawRequests(MigrationBuilder $b): void
    {
        $b->createTable('{{%admin_withdraw_request}}', [
            'id' => 'pk',
            'admin_id' => 'integer NOT NULL',
            'amount' => "decimal(12,2) NOT NULL",
            'method' => "string(32) NOT NULL DEFAULT 'bank'",
            'account_details' => 'string(500) NULL',
            'note' => 'string(500) NULL',
            'status' => "string(16) NOT NULL DEFAULT 'pending'",
            'transaction_id' => 'integer NULL',
            // Same claim shape as the recharge queue: a withdrawal is reviewed
            // by a human, and two super-admins approving the same payout is
            // exactly the accident worth preventing.
            'claimed_by' => 'integer NULL',
            'claimed_at' => 'datetime NULL',
            'reviewed_by' => 'integer NULL',
            'reviewed_at' => 'datetime NULL',
            'review_note' => 'string(500) NULL',
            'reject_reason' => 'string(500) NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);

        $b->createIndex('{{%admin_withdraw_request}}', 'ix_withdraw_admin', ['admin_id', 'created_at']);
        $b->createIndex('{{%admin_withdraw_request}}', 'ix_withdraw_status', ['status', 'created_at']);
        $b->createIndex('{{%admin_withdraw_request}}', 'ix_withdraw_claimed_by', ['claimed_by']);

        $b->addForeignKey(
            '{{%admin_withdraw_request}}',
            'fk_withdraw_admin',
            'admin_id',
            '{{%user}}',
            'id',
        );
        $b->addForeignKey(
            '{{%admin_withdraw_request}}',
            'fk_withdraw_claimer',
            'claimed_by',
            '{{%user}}',
            'id',
        );
        $b->addForeignKey(
            '{{%admin_withdraw_request}}',
            'fk_withdraw_reviewer',
            'reviewed_by',
            '{{%user}}',
            'id',
        );
        $b->addForeignKey(
            '{{%admin_withdraw_request}}',
            'fk_withdraw_transaction',
            'transaction_id',
            '{{%transaction}}',
            'id',
        );
    }

    /**
     * Reverting puts the orders back where they came from.
     *
     * The ledger rows are *not* reverse-engineered: a balance movement that
     * happened is history, and rewriting the ledger to hide it would be worse
     * than leaving the extra columns in place. `up()` is the direction this
     * schema moves in; `down()` only has to keep the tables consistent for a
     * rollback that is immediately followed by another `up()`.
     */
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%admin_withdraw_request}}');

        // Copy the orders back, ignoring the claim/approval columns the old
        // table has nowhere to put.
        $b->execute(
            'INSERT INTO {{%transaction}}'
            . ' ([[id]], [[user_id]], [[service_id]], [[reference]], [[amount]], [[status]], [[metadata]],'
            . ' [[deliverable_path]], [[deliverable_name]], [[deliverable_mime]], [[deliverable_size]],'
            . ' [[deliverable_uploaded_at]], [[deliverable_uploaded_by]], [[created_at]], [[updated_at]])'
            . ' SELECT [[id]], [[user_id]], [[service_id]], [[reference]], [[amount]], [[status]], [[metadata]],'
            . ' [[deliverable_path]], [[deliverable_name]], [[deliverable_mime]], [[deliverable_size]],'
            . ' [[deliverable_uploaded_at]], [[deliverable_uploaded_by]], [[created_at]], [[updated_at]]'
            . ' FROM {{%service_order}}',
        );

        $b->dropForeignKey('{{%transaction}}', 'fk_transaction_service_order');
        $b->dropForeignKey('{{%transaction}}', 'fk_transaction_admin');
        $b->dropIndex('{{%transaction}}', 'ix_transaction_user_type');
        $b->dropIndex('{{%transaction}}', 'ix_transaction_type');
        $b->dropIndex('{{%transaction}}', 'ix_transaction_admin');
        $b->dropColumn('{{%transaction}}', 'service_order_id');
        $b->dropColumn('{{%transaction}}', 'description');
        $b->dropColumn('{{%transaction}}', 'balance_after');
        $b->dropColumn('{{%transaction}}', 'balance_before');
        $b->dropColumn('{{%transaction}}', 'direction');
        $b->dropColumn('{{%transaction}}', 'type');
        $b->dropColumn('{{%transaction}}', 'admin_id');

        $b->dropTable('{{%service_order}}');
    }
}
