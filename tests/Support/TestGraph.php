<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\ServiceOrderRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\LedgerService;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Shared wiring for the functional tests.
 *
 * The services under test take their collaborators by constructor and these
 * tests build them by hand, which is deliberate — an autowired service in a
 * test proves less than one whose every dependency is visible at the call site.
 *
 * The cost is that a dependency appearing in three constructors has to be
 * written out in every test that needs it, and a constructor change then breaks
 * eight files instead of one. These factories are the middle ground: the wiring
 * is still explicit at each call site, but it is written once and the tests say
 * *which* graph they want rather than re-listing it.
 */
final class TestGraph
{
    /**
     * The ledger gateway — the only thing allowed to move a balance.
     *
     * @param ConnectionInterface $db
     * @param UserRepository      $users the same repository the caller passes to
     *                                the service under test, so both see one view
     *                                of the account
     */
    public static function ledger(ConnectionInterface $db, UserRepository $users): LedgerService
    {
        return new LedgerService($db, $users, new TransactionRepository($db));
    }

    public static function orders(ConnectionInterface $db): ServiceOrderRepository
    {
        return new ServiceOrderRepository($db);
    }

    public static function ledgerRepository(ConnectionInterface $db): TransactionRepository
    {
        return new TransactionRepository($db);
    }

    /**
     * The notification stack, wired against a real database.
     *
     * Shares `$users` with the caller for the same reason `ledger()` does.
     */
    public static function notify(
        ConnectionInterface $db,
        UserRepository $users,
        SettingsRepository $settings,
    ): NotificationManager {
        return new NotificationManager(
            new \App\Repository\NotificationRepository($db),
            new QueueRepository($db),
            new TemplateRenderer($db, $settings),
            $users,
            $db,
        );
    }

    /**
     * Delete everything a test account accumulated, in an order the foreign
     * keys accept, then the account itself.
     *
     * The ordering is the whole reason this is a helper rather than four
     * `delete()` calls in each suite. Every one of these tables points at
     * another one of them — an order is claimed and approved *by* a user, and a
     * ledger entry points at the order that moved the money — so a cleanup that
     * is merely "close enough" either throws mid-way and leaves the account
     * behind, or silently skips a table and the next run inherits the debris.
     * The latter is worse: it is how a queue test ends up asserting against
     * 200 rows somebody else's failed run left in the table.
     *
     * Only touches rows belonging to this one account.
     */
    public static function purgeUser(ConnectionInterface $db, int $userId): void
    {
        // Ledger rows that reference this account's *orders* have to go before
        // the orders, and are found by join rather than by an id list, because
        // the ids are not known here.
        $db->createCommand(
            'DELETE t FROM {{%transaction}} t'
            . ' JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
            . ' WHERE o.[[user_id]] = :id',
        )->bindValue(':id', $userId)->execute();

        // Withdrawals reference the requester, the reviewer, the claimer and
        // the ledger entry. Detach the people columns, drop the rest.
        $db->createCommand()->update(
            '{{%admin_withdraw_request}}',
            ['claimed_by' => null, 'reviewed_by' => null, 'transaction_id' => null],
            ['admin_id' => $userId],
        )->execute();
        $db->createCommand()->update(
            '{{%admin_withdraw_request}}',
            ['claimed_by' => null],
            ['reviewed_by' => $userId],
        )->execute();
        $db->createCommand()->delete('{{%admin_withdraw_request}}', ['admin_id' => $userId])->execute();

        // The order's three "a staff member did this" columns.
        foreach (['claimed_by', 'approved_by', 'deliverable_uploaded_by'] as $column) {
            $db->createCommand()->update('{{%service_order}}', [$column => null], [$column => $userId])
                ->execute();
        }

        $db->createCommand()->delete('{{%service_order}}', ['user_id' => $userId])->execute();
        $db->createCommand()->delete('{{%transaction}}', ['user_id' => $userId])->execute();
        $db->createCommand()->delete('{{%transaction}}', ['admin_id' => $userId])->execute();
        $db->createCommand()->delete('{{%activity_log}}', ['user_id' => $userId])->execute();
        $db->createCommand()->delete('{{%notification}}', ['user_id' => $userId])->execute();
        $db->createCommand()->delete('{{%user}}', ['id' => $userId])->execute();
    }

    /**
     * Remove every order on a service fixture, plus the ledger rows that
     * pointed at them, so the service row itself can go.
     */
    public static function purgeServiceOrders(ConnectionInterface $db, int $serviceId): void
    {
        $db->createCommand(
            'DELETE t FROM {{%transaction}} t'
            . ' JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
            . ' WHERE o.[[service_id]] = :sid',
        )->bindValue(':sid', $serviceId)->execute();

        $db->createCommand()->delete('{{%service_order}}', ['service_id' => $serviceId])->execute();
        $db->createCommand()->delete('{{%transaction}}', ['service_id' => $serviceId])->execute();
    }
}
