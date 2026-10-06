<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Service\ServiceBulkAction;
use App\Tests\Support\TestGraph;
use App\Web\Admin\AdminServicesAction;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The services page's bulk bar: one submission, a whole selection.
 *
 * `ServiceBulkAction` deliberately duplicates the single-row guard rules from
 * `AdminServicesAction` rather than sharing them (see the note on the class).
 * Two copies of a rule is only defensible while a test asserts both sides, and
 * that test is this file: below, each rule is checked against the bulk path,
 * and `SoftDeleteTest` plus the single-row action's own tests check it against
 * the one-at-a-time path. If one side is changed and this file is not, the
 * disagreement shows up here as a failing assertion rather than as a service
 * that silently reactivates itself out of the trash.
 *
 * The batch is also the only thing standing between a typo and forty rows of
 * data, so the refusals matter as much as the actions: an unknown `bulk_action`
 * has to stop dead rather than fall through to a default, and an empty or
 * garbage selection has to be refused rather than guessed at.
 *
 * Throwaway services only, all removed again in `_after()`.
 */
final class AdminServicesBulkTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private ServiceRepository $services;
    private ActivityLogRepository $logs;
    private UserRepository $users;
    private ServiceBulkAction $bulk;

    /** @var int[] */
    private array $serviceIds = [];
    /** @var int[] */
    private array $userIds = [];
    /** @var int[] */
    private array $logIds = [];
    private string $suffix = '';
    private string $startedAt = '';
    private int $categoryId = 0;
    private int $adminId = 0;
    /** @var string[] Slug prefixes of rows inserted in bulk, swept in bulk. */
    private array $bulkSlugPrefixes = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->services = new ServiceRepository($this->db);
        $this->logs = new ActivityLogRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->bulk = new ServiceBulkAction($this->services, $this->logs);

        $this->suffix = 'k' . substr(md5(uniqid('', true)), 0, 10);
        // One second of slack: the log rows are stamped with a whole second, so
        // a batch logged in the same second the suite started would otherwise
        // fall outside the window `_after()` sweeps.
        $this->startedAt = date('Y-m-d H:i:s', time() - 5);
        $this->categoryId = (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
            ->queryScalar();

        // A real admin id, so the audit row is joined to a real user the way a
        // real submission's would be — `user_id` is a FK and a NULL here would
        // quietly pass for a batch nobody was accountable for.
        $this->adminId = $this->users->create([
            'username' => 'bulk_admin_' . $this->suffix,
            'phone' => '9' . substr(md5($this->suffix . 'adm'), 0, 9),
            'email' => 'bulk_admin_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'admin',
            'balance' => 0,
        ]);
        $this->userIds[] = $this->adminId;
    }

    protected function _after(): void
    {
        // The log rows are the batch's audit trail, so they are swept by
        // timestamp rather than by id: `user_id` is nullable, and a batch run
        // with no admin (the tests that pass null) would survive an id sweep.
        $this->db
            ->createCommand('DELETE FROM {{%activity_log}} WHERE [[created_at]] >= :from AND [[action]] LIKE :a')
            ->bindValue(':from', $this->startedAt)
            ->bindValue(':a', 'admin.service.bulk_%')
            ->execute();

        foreach ($this->serviceIds as $id) {
            TestGraph::purgeServiceOrders($this->db, $id);
            $this->db->createCommand()->delete('{{%service}}', ['id' => $id])->execute();
        }
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            // By user as well as by service: a purge detaches the order's
            // `service_id` to NULL and leaves the row behind, so the service
            // sweep above no longer finds it and `user` cannot be deleted
            // while its order still points at it.
            // Ledger rows pointing at this user's orders first: an entry
            // references the order that moved the money, so the FK refuses the
            // other order.
            $this->db
                ->createCommand(
                    'DELETE t FROM {{%transaction}} t'
                    . ' JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
                    . ' WHERE o.[[user_id]] = :id',
                )
                ->bindValue(':id', $id)
                ->execute();
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        // Bulk-inserted rows are swept by their slug prefix rather than by id:
        // the truncation test makes a thousand, and deleting them one at a
        // time would cost more than making them did.
        foreach ($this->bulkSlugPrefixes as $prefix) {
            $this->db
                ->createCommand(
                    'DELETE t FROM {{%transaction}} t'
                    . ' JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
                    . ' WHERE o.[[service_id]] IN'
                    . ' (SELECT [[id]] FROM {{%service}} WHERE [[slug]] LIKE :p)',
                )
                ->bindValue(':p', $prefix . '%')
                ->execute();
            $this->db
                ->createCommand('DELETE FROM {{%service_order}} WHERE [[service_id]] IN'
                    . ' (SELECT [[id]] FROM {{%service}} WHERE [[slug]] LIKE :p)')
                ->bindValue(':p', $prefix . '%')
                ->execute();
            $this->db
                ->createCommand('DELETE FROM {{%service}} WHERE [[slug]] LIKE :p')
                ->bindValue(':p', $prefix . '%')
                ->execute();
        }
        $this->bulkSlugPrefixes = [];
        $this->userIds = [];
        $this->serviceIds = [];

        $this->db->close();
    }

    private function makeService(string $tag, string $status = 'active'): int
    {
        $id = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Bulk Probe ' . $tag . ' ' . $this->suffix,
            'slug' => 'bulk-probe-' . $tag . '-' . $this->suffix,
            'description' => 'Disposable bulk-action fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => 10,
            'status' => $status,
            'sort_order' => 999,
        ]);
        $this->serviceIds[] = $id;
        return $id;
    }

    /**
     * Many rows in one statement, for the tests that need more than a handful.
     *
     * Only for the filter ceiling: every other behaviour here is about a
     * handful of rows the admin could see at once, and `FILTERED_LIMIT + 1` is
     * the one place where "all matching" has to be counted rather than listed.
     *
     * @return string the slug prefix, which is also the term to filter on
     */
    private function makeManyServices(string $prefix, int $count, string $status = 'inactive'): string
    {
        $slugPrefix = 'bulk-fill-' . $prefix;
        $this->bulkSlugPrefixes[] = $slugPrefix;

        $now = date('Y-m-d H:i:s');
        $values = [];
        $binds = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = sprintf(
                '(%d, :name%d, :slug%d, \'mock\', \'10.00\', :status, 998, :now, :now)',
                $this->categoryId,
                $i,
                $i,
            );
            $binds[':name' . $i] = 'Bulk Fill ' . $prefix . ' ' . $i;
            $binds[':slug' . $i] = $slugPrefix . '-' . $i;
        }
        $binds[':status'] = $status;
        $binds[':now'] = $now;

        $this->db
            ->createCommand(
                'INSERT INTO {{%service}} (category_id, name, slug, service_type, price, status,'
                . ' sort_order, created_at, updated_at) VALUES ' . implode(', ', $values),
            )
            ->bindValues($binds)
            ->execute();

        return $prefix;
    }

    private function statusOf(int $id): ?string
    {
        $row = $this->db
            ->createCommand('SELECT [[status]] FROM {{%service}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : (string) $row['status'];
    }

    private function isTrashed(int $id): bool
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[id]] = :id AND [[deleted_at]] IS NOT NULL')
            ->bindValue(':id', $id)
            ->queryScalar() === 1;
    }

    /** A transaction row hanging off a service, so `kept` has something to count. */
    private function makeTransaction(int $serviceId, string $tag): int
    {
        $userId = $this->users->create([
            'username' => 'bulk_buyer_' . $tag . '_' . $this->suffix,
            'phone' => '9' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'bulk_buyer_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => 0,
        ]);
        $this->userIds[] = $userId;

        $this->db->createCommand()->insert('{{%service_order}}', [
            'user_id' => $userId,
            'service_id' => $serviceId,
            'reference' => 'BULK-' . $tag . '-' . $this->suffix,
            'status' => 'completed',
            'amount' => 50.0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    /**
     * Log rows this suite created, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bulkLogs(): array
    {
        // Scoped to this run's window, for the reason `_after()` gives when it
        // sweeps: `user_id` is nullable, so the rows cannot be found by admin.
        // Reading the whole table instead would tie the suite to whatever the
        // dev database happens to hold — a single bulk batch run by hand in the
        // admin UI is enough to make every count below off by that many, which
        // is a failure about the fixture rather than about the code.
        $rows = $this->db
            ->createCommand(
                'SELECT [[id]], [[user_id]], [[action]], [[description]], [[metadata]] FROM {{%activity_log}}'
                . ' WHERE [[action]] LIKE :a AND [[created_at]] >= :from ORDER BY [[id]] DESC',
            )
            ->bindValue(':a', 'admin.service.bulk_%')
            ->bindValue(':from', $this->startedAt)
            ->queryAll();
        assertIsArray($rows);
        return $rows;
    }

    // ---- Status switching --------------------------------------------------

    public function testDeactivateThenActivateFlipsEveryRowInTheSelection(): void
    {
        $a = $this->makeService('off_a');
        $b = $this->makeService('off_b');

        $off = $this->bulk->run([$a, $b], 'deactivate', $this->adminId);
        assertTrue($off['ok']);
        assertSame(2, $off['applied']);
        assertSame(0, $off['unchanged']);
        assertSame(0, $off['skipped']);
        assertSame('inactive', $this->statusOf($a));
        assertSame('inactive', $this->statusOf($b));

        $on = $this->bulk->run([$a, $b], 'activate', $this->adminId);
        assertTrue($on['ok']);
        assertSame(2, $on['applied']);
        assertSame('active', $this->statusOf($a));
        assertSame('active', $this->statusOf($b));
    }

    public function testARowAlreadyInTheTargetStatusIsReportedNotRewritten(): void
    {
        $already = $this->makeService('same', 'inactive');
        $moved = $this->makeService('moves', 'active');

        $result = $this->bulk->run([$already, $moved], 'deactivate', $this->adminId);

        assertTrue($result['ok'], 'One row moved, so the batch counts as done.');
        assertSame(1, $result['applied']);
        assertSame(1, $result['unchanged']);
        assertStringContainsString('আগেই এই অবস্থায় ছিল', $result['message']);
        assertSame('inactive', $this->statusOf($already));
    }

    public function testANothingToDoBatchIsNotOkSoTheFlashIsAnError(): void
    {
        $id = $this->makeService('noop', 'inactive');

        $result = $this->bulk->run([$id], 'deactivate', $this->adminId);

        assertFalse($result['ok'], 'An operator must be able to tell "done" from "nothing happened".');
        assertSame(0, $result['applied']);
        assertSame(1, $result['unchanged']);
        assertStringContainsString('কোনো সার্ভিসের অবস্থা বদলায়নি', $result['message']);
    }

    // ---- The trash boundary ------------------------------------------------

    public function testATrashedServiceIsSkippedByBothStatusActions(): void
    {
        $trashed = $this->makeService('gone');
        assertTrue($this->services->softDelete($trashed));

        foreach (['activate', 'deactivate'] as $action) {
            $result = $this->bulk->run([$trashed], $action, $this->adminId);

            assertFalse($result['ok'], $action . ' must not claim a trashed service is on the site.');
            assertSame(0, $result['applied']);
            assertSame(1, $result['skipped']);
            assertStringContainsString('বাদ পড়েছে', $result['message']);
            assertTrue($this->isTrashed($trashed), $action . ' must not restore the row as a side effect.');
        }
    }

    public function testATrashedServiceIsUnchangedByTrashNotSkipped(): void
    {
        $trashed = $this->makeService('gone2');
        assertTrue($this->services->softDelete($trashed));

        $result = $this->bulk->run([$trashed], 'trash', $this->adminId);

        assertSame(0, $result['applied']);
        assertSame(1, $result['unchanged'], 'It is already in the trash — that is a no-op, not a refusal.');
    }

    public function testTrashThenRestoreBringsTheWholeSelectionBack(): void
    {
        $a = $this->makeService('rt_a');
        $b = $this->makeService('rt_b');

        $trashed = $this->bulk->run([$a, $b], 'trash', $this->adminId);
        assertTrue($trashed['ok']);
        assertSame(2, $trashed['applied']);
        assertTrue($this->isTrashed($a));
        assertTrue($this->isTrashed($b));

        $restored = $this->bulk->run([$a, $b], 'restore', $this->adminId);
        assertTrue($restored['ok']);
        assertSame(2, $restored['applied']);
        assertFalse($this->isTrashed($a));
        assertFalse($this->isTrashed($b));
    }

    public function testRestoreLeavesALiveServiceAlone(): void
    {
        $live = $this->makeService('live');

        $result = $this->bulk->run([$live], 'restore', $this->adminId);

        assertSame(0, $result['applied']);
        assertSame(1, $result['unchanged']);
        assertFalse($this->isTrashed($live));
    }

    public function testPurgeOnlyReachesTrashedRows(): void
    {
        $live = $this->makeService('purge_live');
        $trashed = $this->makeService('purge_gone');
        assertTrue($this->services->softDelete($trashed));

        $result = $this->bulk->run([$live, $trashed], 'purge', $this->adminId);

        assertSame(1, $result['applied'], 'A live service must survive a purge batch.');
        assertSame(1, $result['skipped']);

        assertSame(1, (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[id]] = :id')
            ->bindValue(':id', $live)
            ->queryScalar(), 'The live row must still exist.');

        // The trashed one is gone, so it cannot be counted in serviceIds for the
        // cleanup delete — dropping it keeps `_after()`'s delete a no-op.
        $this->serviceIds = array_values(array_filter(
            $this->serviceIds,
            static fn (int $id): bool => $id !== $trashed,
        ));
    }

    public function testPurgeKeepsTheOrderHistoryAndReportsHowMany(): void
    {
        $trashed = $this->makeService('purge_kept');
        $orderId = $this->makeTransaction($trashed, 'p1');
        assertTrue($this->services->softDelete($trashed));

        $result = $this->bulk->run([$trashed], 'purge', $this->adminId);

        assertSame(1, $result['applied']);
        assertSame(1, $result['kept']);
        // The surviving rows are orders, not ledger entries: the split moved the
        // order itself into `service_order` and the ledger now points at it, so
        // the count and the wording both talk about the order.
        assertStringContainsString('অর্ডার রেকর্ড সংরক্ষিত', $result['message']);

        $row = $this->db
            ->createCommand('SELECT [[service_id]] FROM {{%service_order}} WHERE [[id]] = :id')
            ->bindValue(':id', $orderId)
            ->queryOne();
        assertIsArray($row);
        assertNull($row['service_id'], 'Purge detaches the order rather than deleting it.');

        $this->serviceIds = array_values(array_filter(
            $this->serviceIds,
            static fn (int $id): bool => $id !== $trashed,
        ));
    }

    public function testTrashReportsTheOrdersThatSurvivedIt(): void
    {
        $id = $this->makeService('trash_kept');
        $this->makeTransaction($id, 't1');

        $result = $this->bulk->run([$id], 'trash', $this->adminId);

        assertSame(1, $result['kept']);
        assertStringContainsString('অক্ষত আছে', $result['message']);
    }

    // ---- Refusals and normalisation ---------------------------------------

    public function testAnUnknownActionIsRefusedRatherThanDefaulted(): void
    {
        $id = $this->makeService('unknown');

        $result = $this->bulk->run([$id], 'explode', $this->adminId);

        assertFalse($result['ok']);
        assertSame(0, $result['applied']);
        assertStringContainsString('অজানা অ্যাকশন', $result['message']);
        assertSame('active', $this->statusOf($id), 'A typo must not fall through into a default action.');
        assertCount(0, $this->bulkLogs(), 'A refused action must not write an audit row.');
    }

    public function testIsActionMatchesTheListTheBarRendersFrom(): void
    {
        foreach (ServiceBulkAction::ACTIONS as $action) {
            assertTrue(ServiceBulkAction::isAction($action), $action . ' is offered in the bar.');
        }
        assertFalse(ServiceBulkAction::isAction(''));
        assertFalse(ServiceBulkAction::isAction('delete'));
        assertFalse(ServiceBulkAction::isAction('DEACTIVATE'), 'The check is case-sensitive on purpose.');
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        foreach ([[], [0], [-1], ['', '  '], ['0abc']] as $garbage) {
            $result = $this->bulk->run($garbage, 'deactivate', $this->adminId);
            assertFalse($result['ok'], 'Nothing usable was submitted.');
            assertSame(0, $result['applied']);
            assertStringContainsString('নির্বাচন করা হয়নি', $result['message']);
        }
        assertCount(0, $this->bulkLogs());
    }

    public function testDuplicateAndStringIdsActOnceEach(): void
    {
        $a = $this->makeService('dup_a');
        $b = $this->makeService('dup_b', 'inactive');

        // The browser posts strings; a hand-written POST can repeat a box. Both
        // must land on the same set of rows the checkbox list means.
        $result = $this->bulk->run([(string) $a, (string) $a, (string) $b, $a . 'junk'], 'deactivate', $this->adminId);

        assertSame(1, $result['applied'], 'The repeated id is one row, so it counts once.');
        assertSame(1, $result['unchanged']);
        assertSame('inactive', $this->statusOf($a));
    }

    public function testAnIdThatIsNotAServiceIsSkippedAndReported(): void
    {
        $id = $this->makeService('ghost');
        $missing = 999999999;

        $result = $this->bulk->run([$id, $missing], 'deactivate', $this->adminId);

        assertTrue($result['ok']);
        assertSame(1, $result['applied']);
        assertSame(1, $result['skipped'], 'A missing row has to be visible, not silently dropped.');
        assertStringContainsString('বাদ পড়েছে', $result['message']);
    }

    public function testASelectionIsCappedSoACraftedPostCannotFanOut(): void
    {
        assertSame(200, ServiceBulkAction::BULK_LIMIT);

        // Throwaway rows, and that is the whole point of this rewrite.
        //
        // It used to submit `range(1, BULK_LIMIT + 50)` — which on a populated
        // database *is* the live catalog — to a `deactivate` batch, and then
        // asserted only on counts. So every run of this suite switched off every
        // real service in the shop and stayed green: the test proved the cap by
        // taking the shop down. Nothing in `_after()` swept it, because the rows
        // were not the suite's.
        //
        // The cap is a property of `run()`, so it holds for any id; what the
        // count has to be made of is a real row, and `makeManyServices` builds
        // those for exactly this and is swept by slug prefix.
        $prefix = $this->makeManyServices('cap' . $this->suffix, ServiceBulkAction::BULK_LIMIT + 50, 'active');

        $ids = $this->db
            ->createCommand('SELECT [[id]] FROM {{%service}} WHERE [[slug]] LIKE :p ORDER BY [[id]] ASC')
            ->bindValue(':p', 'bulk-fill-' . $prefix . '-%')
            ->queryColumn();

        $result = $this->bulk->run($ids, 'deactivate', $this->adminId);

        // A five-hundred-row fan-out is the thing this number exists to stop, so
        // the run has to stop at the cap with real rows on both sides of it.
        $seen = $result['applied'] + $result['unchanged'] + $result['skipped'];
        assertSame(ServiceBulkAction::BULK_LIMIT, $seen);
        assertSame(ServiceBulkAction::BULK_LIMIT, $result['applied'], 'The cap counts real rows it acted on.');

        $leftActive = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[slug]] LIKE :p AND [[status]] = :s')
            ->bindValue(':p', 'bulk-fill-' . $prefix . '-%')
            ->bindValue(':s', 'active')
            ->queryScalar();
        assertSame(
            50,
            $leftActive,
            'The rows past the cap are left alone rather than quietly deactivated.',
        );
    }

    // ---- The audit trail ---------------------------------------------------

    public function testOneBatchWritesOneLogRowNotOnePerService(): void
    {
        $ids = [
            $this->makeService('log_a'),
            $this->makeService('log_b'),
            $this->makeService('log_c', 'inactive'),
        ];

        $this->bulk->run($ids, 'deactivate', $this->adminId);

        $rows = $this->bulkLogs();
        assertCount(1, $rows, 'A nine-row batch is one decision and must read that way.');

        $row = $rows[0];
        assertSame('admin.service.bulk_deactivated', $row['action']);
        assertSame($this->adminId, (int) $row['user_id']);

        $metadata = json_decode((string) $row['metadata'], true);
        assertIsArray($metadata);
        assertSame('deactivate', $metadata['bulk_action']);
        assertSame(2, $metadata['count'], 'The applied count, not the submitted one.');
        assertSame(1, $metadata['unchanged']);
        assertSame(0, $metadata['skipped']);
        assertSame([$ids[0], $ids[1]], $metadata['service_ids'], 'Only the rows that actually moved.');
        assertNotContains($ids[2], $metadata['service_ids']);
        assertStringContainsString('2 service(s)', (string) $row['description']);
    }

    public function testEveryActionNamesItselfInTheAuditTrail(): void
    {
        $live = $this->makeService('names_live');
        $tossed = $this->makeService('names_tossed');
        $gone = $this->makeService('names_gone');

        // Five batches, one per action, so the five names below have to come
        // from five distinct rows — a name that collapsed two actions into one
        // (the `trashd` this replaced) would show up here as four.
        $this->bulk->run([$live], 'deactivate', $this->adminId);
        $this->bulk->run([$live], 'activate', $this->adminId);
        $this->bulk->run([$tossed], 'trash', $this->adminId);
        $this->bulk->run([$tossed], 'restore', $this->adminId);
        $this->bulk->run([$gone], 'trash', $this->adminId);
        $this->bulk->run([$gone], 'purge', null);

        // Purge is a real delete, so the row is already gone by the time
        // `_after()` runs; dropping it keeps the cleanup delete a no-op.
        $this->serviceIds = array_values(array_filter(
            $this->serviceIds,
            static fn (int $id): bool => $id !== $gone,
        ));

        $actions = array_column($this->bulkLogs(), 'action');
        foreach (['deactivated', 'activated', 'trashed', 'restored', 'purged'] as $expected) {
            assertContains('admin.service.bulk_' . $expected, $actions);
        }
        assertCount(6, $actions, 'Five of the names, over six batches — trash is run twice.');

        // The last batch is the purge, and it is logged against no user: the
        // bar is an authenticated page, so a NULL here means somebody reached
        // the service without going through the action.
        $purged = $this->bulkLogs()[0];
        assertSame('admin.service.bulk_purged', $purged['action']);
        assertNull($purged['user_id']);
    }

    public function testABatchThatMovedNothingWritesNoLog(): void
    {
        $id = $this->makeService('silent', 'inactive');

        $this->bulk->run([$id], 'deactivate', $this->adminId);

        assertCount(0, $this->bulkLogs(), 'An audit trail of nothing-happened rows is noise, not history.');
    }

    public function testReplayingABatchIsSafeAndOnlyReportsWhatIsLeft(): void
    {
        // The property the whole service is built on: a response lost mid-batch
        // can simply be submitted again, because every row is idempotent.
        $a = $this->makeService('replay_a');
        $b = $this->makeService('replay_b');

        $first = $this->bulk->run([$a, $b], 'deactivate', $this->adminId);
        $second = $this->bulk->run([$a, $b], 'deactivate', $this->adminId);

        assertSame(2, $first['applied']);
        assertSame(0, $second['applied']);
        assertSame(2, $second['unchanged']);
        assertFalse($second['ok']);
        assertCount(1, $this->bulkLogs(), 'The replay moved nothing, so it adds no history.');
    }

    // ---- Selecting by filter rather than by tick ---------------------------

    public function testAFilteredBatchActsOnTheFilterAndNothingElse(): void
    {
        // Both rows are inactive, so either one being left alone proves the
        // filter narrowed the batch rather than the action being a no-op.
        $hit = $this->makeService('flt_hit', 'inactive');
        $missed = $this->makeService('flt_missed', 'inactive');

        $result = $this->bulk->runFiltered(
            ['q' => 'Bulk Probe flt_hit ' . $this->suffix],
            'activate',
            $this->adminId,
        );

        assertTrue($result['ok']);
        assertSame(1, $result['applied']);
        assertSame('active', $this->statusOf($hit));
        assertSame('inactive', $this->statusOf($missed), 'A row the filter did not match is not acted on.');

        $metadata = json_decode((string) $this->bulkLogs()[0]['metadata'], true);
        assertIsArray($metadata);
        assertSame('filtered', $metadata['scope'], 'The audit trail has to say which scope this was.');
        assertSame([$hit], $metadata['service_ids']);
    }

    public function testAnUntruncatedFilteredBatchReportsHowManyTheFilterMatched(): void
    {
        $this->makeService('scope_a');
        $this->makeService('scope_b');
        $this->makeService('scope_c');

        $result = $this->bulk->runFiltered(['q' => $this->suffix], 'deactivate', $this->adminId);

        assertSame(3, $result['applied']);
        assertStringContainsString('3', $result['message'], 'The count the filter matched belongs in the flash.');

        $metadata = json_decode((string) $this->bulkLogs()[0]['metadata'], true);
        assertIsArray($metadata);
        assertSame(3, $metadata['matched']);
        assertFalse(isset($metadata['truncated']), 'A clean batch is not dressed up as a truncated one.');
    }

    public function testAFilterTooBigToApplySaysSoInsteadOfLookingClean(): void
    {
        // The dangerous version of this feature is a filter that matches more
        // than the ceiling and reports a tidy number anyway: the admin reads
        // "200 done", believes the cleanup is finished, and closes the tab.
        $prefix = $this->makeManyServices('fill' . $this->suffix, ServiceBulkAction::FILTERED_LIMIT + 1);

        $result = $this->bulk->runFiltered(['q' => $prefix], 'activate', $this->adminId);

        assertTrue($result['ok']);
        assertSame(ServiceBulkAction::FILTERED_LIMIT, $result['applied']);
        assertStringContainsString(
            (string) (ServiceBulkAction::FILTERED_LIMIT + 1),
            $result['message'],
            'The flash has to say how many matched, not only how many moved.',
        );
        assertStringContainsString((string) ServiceBulkAction::FILTERED_LIMIT, $result['message']);

        $leftBehind = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[slug]] LIKE :p AND [[status]] = :s')
            ->bindValue(':p', 'bulk-fill-' . $prefix . '-%')
            ->bindValue(':s', 'inactive')
            ->queryScalar();
        assertSame(1, $leftBehind, 'The row past the ceiling was left alone rather than acted on in secret.');

        $metadata = json_decode((string) $this->bulkLogs()[0]['metadata'], true);
        assertIsArray($metadata);
        assertTrue($metadata['truncated'], 'Truncation is a fact in the audit trail, not a sentence in a flash.');
        assertSame(ServiceBulkAction::FILTERED_LIMIT + 1, $metadata['matched']);
    }

    public function testTheFilteredScopeRefusesToRunWithoutAFilter(): void
    {
        // The reason the filter is checked in the service rather than trusted
        // from the caller: with no filter the scope resolves to the whole
        // table, and for purge that is every service in the shop.
        $gone = $this->makeService('refuse');
        $this->bulk->run([$gone], 'trash', $this->adminId);

        $result = $this->bulk->runFiltered([], 'purge', $this->adminId);

        assertFalse($result['ok']);
        assertSame(0, $result['applied']);
        assertSame(
            1,
            (int) $this->db
                ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[id]] = :id')
                ->bindValue(':id', $gone)
                ->queryScalar(),
            'An unfiltered "everything matching" must not become a purge of the whole shop.',
        );
        assertNull($result['undo']);
        assertCount(1, $this->bulkLogs(), 'A batch that refused is not a batch.');
    }

    public function testTheNoFilterCaseHasToBeWrittenDownExplicitly(): void
    {
        // `trashed=exclude` is what the bar posts when nothing is narrowed, and
        // it is a filter field like any other — so it has to read as *no*
        // filter, or every page load would light "select all matching" up over
        // the entire table.
        assertFalse(ServiceBulkAction::isFilterSet(['trashed' => ServiceRepository::DELETED_EXCLUDE]));

        $result = $this->bulk->runFiltered(
            ['trashed' => ServiceRepository::DELETED_EXCLUDE],
            'purge',
            $this->adminId,
        );

        assertFalse($result['ok']);
        assertSame(0, $result['applied']);
    }

    public function testWhatCountsAsAFilter(): void
    {
        assertFalse(ServiceBulkAction::isFilterSet([]));
        assertFalse(ServiceBulkAction::isFilterSet(['q' => '']));
        assertFalse(ServiceBulkAction::isFilterSet(['q' => '   ']), 'Whitespace is not a search.');
        assertFalse(ServiceBulkAction::isFilterSet(['status' => 'deleted']), 'Not a status the list understands.');
        assertFalse(ServiceBulkAction::isFilterSet(['status' => 'ON']), 'And it is compared exactly, not loosely.');
        assertFalse(ServiceBulkAction::isFilterSet(['category' => '-4']));
        assertFalse(ServiceBulkAction::isFilterSet(['category' => 'abc']));

        assertTrue(ServiceBulkAction::isFilterSet(['q' => 'topup']));
        assertTrue(ServiceBulkAction::isFilterSet(['q' => '   padded   ']));
        assertTrue(ServiceBulkAction::isFilterSet(['category' => '7']));
        assertTrue(ServiceBulkAction::isFilterSet(['status' => 'active']));
        assertTrue(ServiceBulkAction::isFilterSet(['status' => 'inactive']));
        assertTrue(ServiceBulkAction::isFilterSet(['trashed' => ServiceRepository::DELETED_ONLY]));
        assertTrue(ServiceBulkAction::isFilterSet(['trashed' => ServiceRepository::DELETED_ALL]));
    }

    public function testTheFilteredScopeStillRefusesAnUnknownAction(): void
    {
        // Checked before the filter, and deliberately: an action nobody
        // recognises must not be rescued by there happening to be a filter.
        $result = $this->bulk->runFiltered(['q' => $this->suffix], 'detonate', $this->adminId);

        assertFalse($result['ok']);
        assertSame(0, $result['applied']);
        assertCount(0, $this->bulkLogs());
    }

    // ---- Undo --------------------------------------------------------------

    public function testUndoingABulkTrashBringsBackEveryRowItMoved(): void
    {
        $a = $this->makeService('undo_a');
        $b = $this->makeService('undo_b');

        $batch = $this->bulk->run([$a, $b], 'trash', $this->adminId);
        assertSame(2, $batch['applied']);
        assertTrue($this->isTrashed($a));
        assertTrue($this->isTrashed($b));

        $undo = $this->bulk->undo($batch['undo'], $this->adminId);

        assertTrue($undo['ok']);
        assertSame(2, $undo['restored']);
        assertSame(0, $undo['failed']);
        assertFalse($this->isTrashed($a));
        assertFalse($this->isTrashed($b));

        $log = $this->bulkLogs()[0];
        assertSame('admin.service.bulk_undo', $log['action']);
        assertSame($this->adminId, (int) $log['user_id'], 'An undo is somebody\'s decision too.');

        $metadata = json_decode((string) $log['metadata'], true);
        assertIsArray($metadata);
        assertSame('trash', $metadata['bulk_action']);
        assertSame(2, $metadata['restored']);
        assertSame([$a, $b], $metadata['service_ids']);
    }

    public function testUndoingABatchResurrectsOnlyTheRowsItMoved(): void
    {
        // The snapshot is the space to put your hands back, not a trash bin.
        // A row the batch reported as already-there was not its doing to undo,
        // and for purge that difference is the whole safety argument.
        $already = $this->makeService('undo_pre');
        $moved = $this->makeService('undo_new');
        $this->bulk->run([$already], 'trash', $this->adminId);

        $batch = $this->bulk->run([$already, $moved], 'trash', $this->adminId);
        assertSame(1, $batch['applied']);
        assertSame(1, $batch['unchanged']);

        $undo = $this->bulk->undo($batch['undo'], $this->adminId);

        assertSame(1, $undo['restored']);
        assertFalse($this->isTrashed($moved));
        assertTrue($this->isTrashed($already), 'A row the batch never touched stays where it was.');
    }

    public function testTheUndoSnapshotIsSpentOnceItHasBeenUsed(): void
    {
        // One-shot by construction: the caller pulls the snapshot out of the
        // session whatever happens here, so the service's job is to refuse to
        // act twice rather than to know what it has already done.
        $id = $this->makeService('undo_once');
        $batch = $this->bulk->run([$id], 'trash', $this->adminId);
        $snapshot = $batch['undo'];
        assertIsArray($snapshot);

        assertSame(1, $this->bulk->undo($snapshot, $this->adminId)['restored']);

        $second = $this->bulk->undo($snapshot, $this->adminId);
        assertFalse($second['ok']);
        assertSame(0, $second['restored']);
        assertSame(1, $second['failed'], 'The replayed undo reports the row it could not restore, not a second restore.');
    }

    public function testUndoingABulkPurgeBringsBackTheRowAndItsOrdersWithoutPublishingIt(): void
    {
        $id = $this->makeService('undo_purge');
        $transactionId = $this->makeTransaction($id, 'undo_purge');
        $this->bulk->run([$id], 'trash', $this->adminId);

        $batch = $this->bulk->run([$id], 'purge', null);
        assertSame(1, $batch['applied']);
        assertNull($this->services->findServiceById($id, true), 'A purge is a real delete.');

        $undo = $this->bulk->undo($batch['undo'], $this->adminId);

        assertTrue($undo['ok']);
        assertSame(1, $undo['restored']);
        assertSame(0, $undo['failed']);

        $restored = $this->services->findServiceById($id, true);
        assertIsArray($restored);
        assertNotNull($restored['deleted_at'], 'Undo undoes; it does not publish a service nobody reviewed.');
        assertTrue($this->isTrashed($id));
        assertSame('Bulk Probe undo_purge ' . $this->suffix, (string) $restored['name']);
        assertSame('10.00', (string) $restored['price'], 'A decimal that made a detour through the session is still a decimal.');

        assertSame(
            [$transactionId],
            $this->services->orderIdsFor($id),
            'A service restored with its orders orphaned would be a restore that reported success and lost data.',
        );

        $metadata = json_decode((string) $this->bulkLogs()[0]['metadata'], true);
        assertIsArray($metadata);
        assertSame('purge', $metadata['bulk_action']);
        assertSame(1, $metadata['reattached_transactions']);
        assertSame([$id], $metadata['service_ids']);
    }

    public function testAnUndoNeverOverwritesAServiceThatHasSinceTakenTheId(): void
    {
        $id = $this->makeService('undo_taken');
        $this->bulk->run([$id], 'trash', $this->adminId);
        $snapshot = $this->bulk->run([$id], 'purge', null)['undo'];
        assertIsArray($snapshot);

        // AUTO_INCREMENT will not hand the same id back on its own, so the
        // collision is written rather than waited for.
        $squatter = $this->makeService('undo_squatter');
        $this->db->createCommand()->update('{{%service}}', ['id' => $id], ['id' => $squatter])->execute();

        $undo = $this->bulk->undo($snapshot, $this->adminId);

        assertFalse($undo['ok']);
        assertSame(0, $undo['restored']);
        assertSame(1, $undo['failed']);
        assertSame(
            'Bulk Probe undo_squatter ' . $this->suffix,
            (string) $this->services->findServiceById($id, true)['name'],
            'The row holding the id is left exactly as it was.',
        );
    }

    public function testOnlyTheDestructiveActionsCarryASnapshot(): void
    {
        // A status switch is its own undo, so a snapshot would only be a second
        // copy of the ids to keep in a session for no benefit.
        $id = $this->makeService('snap_a');

        assertNull($this->bulk->run([$id], 'deactivate', $this->adminId)['undo']);
        assertNull($this->bulk->run([$id], 'activate', $this->adminId)['undo']);

        $trashed = $this->bulk->run([$id], 'trash', $this->adminId);
        assertIsArray($trashed['undo']);
        assertSame('trash', $trashed['undo']['action']);
        assertSame([$id], $trashed['undo']['ids']);

        assertNull(
            $this->bulk->run([$id], 'restore', $this->adminId)['undo'],
            'Restoring a row undoes a trash that has already been undone.',
        );
    }

    public function testABatchThatMovedNothingOffersNothingToUndo(): void
    {
        $id = $this->makeService('snap_empty', 'inactive');

        $batch = $this->bulk->run([$id], 'deactivate', $this->adminId);

        assertFalse($batch['ok']);
        assertNull($batch['undo']);
        assertFalse($this->bulk->undo($batch['undo'], $this->adminId)['ok']);
    }

    public function testUndoWithNothingToUndoReportsItRatherThanGuessing(): void
    {
        $nothing = $this->bulk->undo(null, $this->adminId);
        assertFalse($nothing['ok']);
        assertSame(0, $nothing['restored']);
        assertSame(0, $nothing['failed']);

        $junk = $this->bulk->undo(['action' => 'deactivate', 'ids' => []], $this->adminId);
        assertFalse($junk['ok']);
        assertSame(0, $junk['restored']);

        assertCount(0, $this->bulkLogs(), 'An undo that did nothing is not history.');
    }

    // ---- The filter the two sides agree on ---------------------------------

    public function testTheListCountsEveryMatchEvenWhenItOnlyRendersSome(): void
    {
        $this->makeService('cnt_a');
        $this->makeService('cnt_b');
        $this->makeService('cnt_c');

        $all = $this->services->adminFiltered(['q' => $this->suffix]);
        assertCount(3, $all['rows']);
        assertSame(3, $all['total']);

        $capped = $this->services->adminFiltered(['q' => $this->suffix], 2);
        assertCount(2, $capped['rows']);
        assertCount(2, $capped['ids']);
        assertSame(3, $capped['total'], 'A capped caller still has to be able to say the cap hid something.');
        assertSame(array_slice($all['ids'], 0, 2), $capped['ids'], 'The cap takes a prefix of the order the list renders in.');
    }

    public function testTheTrashFilterAndTheBarUseOneSpellingOfDeleted(): void
    {
        $gone = $this->makeService('voc_gone');
        $live = $this->makeService('voc_live');
        $this->bulk->run([$gone], 'trash', $this->adminId);

        $only = $this->services->adminFiltered(
            ['q' => $this->suffix, 'trashed' => ServiceRepository::DELETED_ONLY],
        );
        assertSame([$gone], $only['ids']);

        $exclude = $this->services->adminFiltered(
            ['q' => $this->suffix, 'trashed' => ServiceRepository::DELETED_EXCLUDE],
        );
        assertSame([$live], $exclude['ids']);

        // `all` is both, live first — the same order the page draws them in.
        assertSame(
            [$live, $gone],
            $this->services->adminFiltered(
                ['q' => $this->suffix, 'trashed' => ServiceRepository::DELETED_ALL],
            )['ids'],
        );
    }

    public function testAFilteredBatchReadsTheSameRowsTheListDrew(): void
    {
        // The bar resolves its scope through the same repository call the view
        // renders from. If the two ever disagreed, the bar would be able to act
        // on rows the admin cannot see — the one thing it must never do.
        $hit = $this->makeService('same_hit', 'inactive');
        $missed = $this->makeService('same_missed', 'inactive');
        $filter = ['q' => 'Bulk Probe same_missed ' . $this->suffix, 'status' => 'inactive'];

        $shown = $this->services->adminFiltered($filter);
        assertSame([$missed], $shown['ids']);

        $result = $this->bulk->runFiltered($filter, 'activate', $this->adminId);

        assertSame(1, $result['applied']);
        assertSame('active', $this->statusOf($missed));
        assertSame('inactive', $this->statusOf($hit));
    }

    // ---- The filter state the page and the redirect agree on --------------

    public function testTheFilterBarKeepsOnlyWhatTheRepositoryUnderstands(): void
    {
        assertSame(
            ['q' => '', 'category' => 0, 'status' => '', 'trashed' => ServiceRepository::DELETED_EXCLUDE],
            AdminServicesAction::state([]),
            'No filter is a state, not an absence of one.',
        );

        $clamped = AdminServicesAction::state([
            'q' => '  topup  ',
            'category' => '-4',
            'status' => 'deleted',
            'trashed' => 'yesterday',
        ]);
        assertSame('', $clamped['status'], 'A status the list cannot filter on would match everything.');
        assertSame(ServiceRepository::DELETED_EXCLUDE, $clamped['trashed'], 'And so would a trash value it cannot read.');
        assertSame(0, $clamped['category']);
        assertSame('topup', $clamped['q'], 'Trimmed, because the same term is matched as a LIKE.');

        assertSame('inactive', AdminServicesAction::state(['status' => 'inactive'])['status']);
        assertSame(ServiceRepository::DELETED_ONLY, AdminServicesAction::state(['trashed' => 'only'])['trashed']);
        assertSame(ServiceRepository::DELETED_ALL, AdminServicesAction::state(['trashed' => 'all'])['trashed']);
    }

    public function testAClampedFilterIsAFixedPoint(): void
    {
        // The redirect after a bulk action rebuilds its query string from a
        // state, and the page then reads that query string back. If clamping
        // were not idempotent, the second pass could produce a different filter
        // from the one the batch actually ran against.
        foreach ($this->filterCandidates() as $params) {
            $state = AdminServicesAction::state($params);
            assertSame($state, AdminServicesAction::state($state), 'Not a fixed point: ' . json_encode($params));
        }

        assertSame(
            190,
            mb_strlen(AdminServicesAction::state(['q' => str_repeat('a', 300)])['q']),
            'A search term long enough to choke the index is trimmed to a length that will not.',
        );
    }

    public function testTheBulkFormSaysWhereToComeBackTo(): void
    {
        assertSame(
            ['q' => 'topup', 'category' => 9, 'status' => 'inactive', 'trashed' => 'only'],
            AdminServicesAction::stateFromInput([
                'return_q' => '  topup  ',
                'return_category' => '9',
                'return_status' => 'inactive',
                'return_trashed' => 'only',
            ]),
            'Named return_* because the same form carries ids[], bulk_action and bulk_scope.',
        );

        assertSame(
            AdminServicesAction::state([]),
            AdminServicesAction::stateFromInput([]),
            'A form posted without them returns to the unfiltered list, not to a broken filter.',
        );
        assertSame('', AdminServicesAction::stateFromInput(['return_status' => 'deleted'])['status']);
        assertSame(0, AdminServicesAction::stateFromInput(['return_category' => 'abc'])['category']);
        assertSame(ServiceRepository::DELETED_EXCLUDE, AdminServicesAction::stateFromInput([])['trashed']);
    }

    public function testAFilterThePageClampedIsStillAFilterTheBarCanSee(): void
    {
        // The bar's own check is an independent one on untrusted input, so the
        // two are asserted to agree rather than one being derived from the
        // other — a shared normaliser would just be the same bug in two places.
        foreach ($this->filterCandidates() as $params) {
            $state = AdminServicesAction::state($params);
            assertSame(
                ServiceBulkAction::isFilterSet($params),
                ServiceBulkAction::isFilterSet($state),
                'Disagreement over ' . json_encode($params),
            );
        }
    }

    /**
     * Filter states worth an opinion, each in and out of the vocabulary.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterCandidates(): array
    {
        return [
            [],
            ['q' => ''],
            ['q' => '   '],
            ['q' => 'topup'],
            ['q' => '  padded  '],
            ['trashed' => 'exclude'],
            ['trashed' => 'only'],
            ['trashed' => 'all'],
            ['trashed' => 'ONLY'],
            ['trashed' => 'yesterday'],
            ['status' => 'active'],
            ['status' => 'inactive'],
            ['status' => 'deleted'],
            ['status' => 'ON'],
            ['status' => ''],
            ['category' => '3'],
            ['category' => '-3'],
            ['category' => 'abc'],
            ['category' => '0'],
            ['q' => 'abc', 'status' => 'inactive', 'trashed' => 'all'],
            ['trashed' => 'only', 'status' => 'inactive'],
        ];
    }
}
