<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\ActivityLogRepository;
use App\Repository\BulkJobRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\BulkJobService;
use App\Service\OrderExportService;
use App\Service\ServiceManager;
use App\Service\ServiceRequestAdminService;
use App\Service\StatusPresenter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * The batch half of the admin bulk bar: a settle that happens in a queued job
 * rather than in the request that submitted it, and the CSV that goes out
 * without a job at all.
 *
 * The Web Cest proves the markup; this proves the two claims the markup makes.
 *
 * Chunking and the cursor are only worth having because of what they promise
 * when a worker dies, and that promise has a money consequence: a chunk whose
 * rows moved but whose checkpoint did not land gets replayed, and every row in
 * it is then *already* in the target status. So the tests below are arranged
 * around the replay rather than around the happy path — the happy path is one
 * `settleMany` call in a trench coat, and it is the crash that the cursor
 * exists for. Each of the three ways a worker can stop (finish, lose the row
 * mid-batch, be killed before the write lands) is exercised separately, because
 * "resumes" is not one behaviour but three.
 *
 * Throwaway rows only, all removed again in _after().
 */
final class BulkJobSettleTest extends \Codeception\Test\Unit
{
    private const PRICE = 12.5;
    private const START_BALANCE = 200.0;

    /** Two rows per chunk, so a five-order batch cannot land on a boundary. */
    private const CHUNK = 2;

    private const ORDER_COUNT = 5;

    private ConnectionInterface $db;
    private UserRepository $users;
    private TransactionRepository $transactions;
    private ServiceRepository $services;
    private ServiceManager $manager;
    private ServiceRequestAdminService $admin;
    private BulkJobRepository $jobs;
    private BulkJobService $bulk;
    private OrderExportService $exporter;

    /** @var int[] */
    private array $userIds = [];
    private int $serviceId = 0;
    private string $suffix = '';
    private string $startedAt = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->transactions = new TransactionRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->jobs = new BulkJobRepository($this->db);

        $notify = new NotificationManager(
            new NotificationRepository($this->db),
            new QueueRepository($this->db),
            new TemplateRenderer($this->db, new SettingsRepository($this->db)),
            $this->users,
            $this->db,
        );

        $this->manager = new ServiceManager(
            $this->services,
            $this->transactions,
            $this->users,
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            $notify,
        );

        $this->admin = new ServiceRequestAdminService(
            $this->transactions,
            $this->users,
            \App\Service\DeliverableStorage::fromProjectRoot(),
            $notify,
            new ActivityLogRepository($this->db),
        );

        $this->bulk = new BulkJobService($this->jobs, $this->admin);
        $this->exporter = new OrderExportService($this->transactions);

        // `claimNext()` is a plain FIFO over the whole queue — it takes no
        // selector, because a worker has no business knowing which batch it is
        // about to take. A test therefore cannot claim "around" a row somebody
        // else left, and this suite shares one queue with the Web Cest and with
        // any cron ticking against the development database. The queue is
        // scratch space here: emptied at both ends so a run that was killed
        // mid-test cannot hand the next one a job of its own.
        $this->db->createCommand('DELETE FROM {{%admin_bulk_job}}')->execute();

        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
        $this->suffix = 'b' . substr(md5(uniqid('', true)), 0, 10);
        $this->serviceId = $this->services->createService([
            'category_id' => (int) $this->db
                ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
                ->queryScalar(),
            // A comma and a quote in the name, so every export assertion here is
            // also an assertion that the record quoting is RFC 4180 and not a
            // naive join. Bengali service names contain commas in practice.
            'name' => 'Bulk probe, "chunked" ' . $this->suffix,
            'slug' => 'bulk-probe-' . $this->suffix,
            'description' => 'Disposable bulk-settle fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => self::PRICE,
            'status' => 'active',
            'sort_order' => 999,
        ]);
    }

    protected function _after(): void
    {
        $this->db->createCommand('DELETE FROM {{%admin_bulk_job}}')->execute();

        $this->db
            ->createCommand('DELETE nd FROM {{%notification_delivery}} nd JOIN {{%notification_queue}} q ON q.id = nd.queue_id WHERE q.created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification_queue}} WHERE created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();

        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%transaction}}', ['user_id' => $id])->execute();
            $this->db
                ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :u)')
                ->bindValue(':u', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->db->createCommand()->delete('{{%transaction}}', ['service_id' => $this->serviceId])->execute();
        $this->db->createCommand()->delete('{{%service}}', ['id' => $this->serviceId])->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->userIds = [];
    }

    // ---- What the web request actually does -------------------------------

    public function testSubmittingABarWritesAJobAndChangesNoOrder(): void
    {
        $user = $this->makeUser('q');
        $admin = $this->makeUser('admin', 'admin');
        $orders = $this->submitOrders($user, 3);

        $result = $this->bulk->enqueueSettle($orders, StatusPresenter::PROCESSING, $admin);

        assertTrue($result['ok'], $result['message']);

        $job = $this->jobRow($result['jobId']);
        assertSame('queued', (string) $job['status'], 'The request must not settle anything itself, only queue it.');
        assertSame(0, (int) $job['cursor'], 'A fresh job has done nothing.');
        assertSame(0, (int) $job['progress'], 'And so has made no progress to report.');
        assertSame(3, (int) $job['total'], 'The panel counts the batch from the row, so the total has to be there.');
        assertSame(
            $admin->username,
            (string) $job['requested_by_name'],
            'The row names its requester, so the panel can answer "was this mine?" without a second lookup.',
        );

        foreach ($orders as $id) {
            assertSame(
                StatusPresenter::PENDING,
                (string) $this->row($id)['status'],
                'Queuing is not settling: the order has to still be pending before any worker runs.',
            );
        }
    }

    public function testTheBarRefusesExactlyWhatTheInlineSettleRefused(): void
    {
        $user = $this->makeUser('refuse');
        $admin = $this->makeUser('admin', 'admin');
        $orders = $this->submitOrders($user, 2);

        $badStatus = $this->bulk->enqueueSettle($orders, 'nonsense', $admin);
        assertFalse($badStatus['ok'], 'A status outside the request set must not become a job.');
        assertSame(0, $badStatus['jobId']);

        // Junk from a crafted POST: no ids, only zeros, and only strings.
        foreach ([[], [0, -1], ['abc', ''], [0, 0]] as $junk) {
            $empty = $this->bulk->enqueueSettle($junk, StatusPresenter::COMPLETED, $admin);
            assertFalse($empty['ok'], 'A selection with nothing selectable in it must be refused at the door.');
        }

        assertSame(
            0,
            $this->countJobs(),
            'A refused submission leaves no job behind, or the worker would later drain a batch nobody asked for.',
        );
    }

    public function testTheSelectionIsCappedAtTheSameLimitTheInlineSettleUsed(): void
    {
        $admin = $this->makeUser('admin', 'admin');

        // Ids that need not exist: this is about the cap, not the rows.
        $many = range(1, ServiceRequestAdminService::BULK_LIMIT + 25);
        $result = $this->bulk->enqueueSettle($many, StatusPresenter::COMPLETED, $admin);

        assertTrue($result['ok'], $result['message']);
        assertSame(
            ServiceRequestAdminService::BULK_LIMIT,
            (int) $this->jobRow($result['jobId'])['total'],
            'A crafted POST must not queue a longer batch than the bar can produce.',
        );
    }

    // ---- The three ways a worker stops ------------------------------------

    public function testAWorkerThatFinishesLeavesEveryOrderSettledAndTheJobDone(): void
    {
        $user = $this->makeUser('done');
        $admin = $this->makeUser('admin', 'admin');
        $orders = $this->submitOrders($user, self::ORDER_COUNT);

        $jobId = $this->queue($orders, StatusPresenter::COMPLETED, $admin, self::CHUNK);
        $message = $this->bulk->run($this->claim($jobId));

        foreach ($orders as $id) {
            assertSame(
                StatusPresenter::COMPLETED,
                (string) $this->row($id)['status'],
                'A completed batch is the one thing the bar is allowed to do.',
            );
        }

        $job = $this->jobRow($jobId);
        assertSame('done', (string) $job['status']);
        assertSame(100, (int) $job['progress'], 'A finished job reports a full bar, not a spinner.');
        assertSame(self::ORDER_COUNT, (int) $job['processed']);
        assertSame(self::ORDER_COUNT, (int) $job['applied']);
        assertStringContainsString((string) self::ORDER_COUNT, $message, 'The summary leads with how many orders actually moved.');
        assertStringContainsString(
            StatusPresenter::label(StatusPresenter::COMPLETED),
            $message,
            'And names the status they moved to, in the label the page itself uses.',
        );
    }

    public function testAWorkerThatLosesTheRowMidBatchCheckpointsWhatItDidAndClaimsNothing(): void
    {
        $user = $this->makeUser('lost');
        $admin = $this->makeUser('admin', 'admin');
        $orders = $this->submitOrders($user, self::ORDER_COUNT);

        $jobId = $this->queue($orders, StatusPresenter::COMPLETED, $admin, self::CHUNK);
        $claimed = $this->claim($jobId);

        // Another tick reclaimed the job while this worker held a snapshot of
        // the row: exactly the state `run()` re-reads after each chunk to
        // discover it no longer owns the batch.
        $this->db
            ->createCommand()->update('{{%admin_bulk_job}}', ['attempts' => (int) $claimed['attempts'] + 1], ['id' => $jobId])
            ->execute();

        $message = $this->bulk->run($claimed);

        $job = $this->jobRow($jobId);
        assertSame(
            self::CHUNK,
            (int) $job['cursor'],
            'The cursor must land on exactly one chunk, which is the only evidence that the loop checkpoints per chunk.',
        );
        assertSame(self::CHUNK, (int) $job['applied']);
        assertStringContainsString('অন্য কর্মী', $message, 'The worker has to say it stood down rather than vanish.');

        // The job is not done: the other worker is still on it, and marking it
        // finished would report work as settled that nobody has finished.
        assertSame('processing', (string) $job['status'], 'A job handed back mid-flight must not be marked done.');
        assertSame(
            40,
            (int) $job['progress'],
            'The panel still shows the two-fifths that really finished, rather than rounding a partial batch up to done.',
        );

        $settled = 0;
        foreach ($orders as $id) {
            if ((string) $this->row($id)['status'] === StatusPresenter::COMPLETED) {
                $settled++;
            }
        }
        assertSame(self::CHUNK, $settled, 'Only the one finished chunk may have moved.');
    }

    public function testAResumedBatchOnlyTouchesTheOrdersItHasNotAlreadySettled(): void
    {
        $user = $this->makeUser('resume');
        $admin = $this->makeUser('admin', 'admin');
        $orders = $this->submitOrders($user, self::ORDER_COUNT);

        $jobId = $this->queue($orders, StatusPresenter::COMPLETED, $admin, self::CHUNK);
        $this->claim($jobId);

        // Two full chunks land and checkpoint, and then the worker is killed.
        // The cursor is where the last checkpoint left it — four of five — and
        // the row is backdated so the next tick reads it as abandoned rather
        // than live, which is the only way a killed worker is ever picked up.
        $this->admin->settleMany(array_slice($orders, 0, self::CHUNK * 2), StatusPresenter::COMPLETED, $admin);
        $this->jobs->saveProgress($jobId, 4, 4, 4, 0, 0, 80, '4/5');
        $this->db
            ->createCommand()
            ->update('{{%admin_bulk_job}}', ['updated_at' => date('Y-m-d H:i:s', time() - 600)], ['id' => $jobId])
            ->execute();

        // A resume is a *second* claim, not a second run over the first
        // snapshot: `run()` takes its cursor from the row it is handed, so the
        // worker that resumes has to read the row fresh, and the reclaim is
        // what makes that read see a moved cursor rather than a live claim.
        $resumed = $this->claim($jobId);
        assertSame(
            4,
            (int) $resumed['cursor'],
            'The reclaiming worker resumes from the cursor the dead one checkpointed, not from the top of the batch.',
        );

        $this->bulk->run($resumed);

        foreach ($orders as $id) {
            assertSame(
                StatusPresenter::COMPLETED,
                (string) $this->row($id)['status'],
                'A resumed batch settles the remainder and leaves the rest where the dead worker left them.',
            );
        }

        $job = $this->jobRow($jobId);
        assertSame('done', (string) $job['status']);
        assertSame(
            self::ORDER_COUNT,
            (int) $job['processed'],
            'The batch is whole again: the four the cursor already covered plus the one this run finished.',
        );
        assertSame(
            self::ORDER_COUNT,
            (int) $job['applied'],
            'Each of the five orders is counted exactly once across both workers, so the summary cannot claim ten changes to five orders.',
        );
        assertSame(
            0,
            (int) $job['unchanged'],
            'Nothing behind the cursor was re-read, so the four already settled cannot reappear as no-ops on top of the applied count.',
        );
        assertEquals(
            self::START_BALANCE - self::PRICE * self::ORDER_COUNT,
            $this->balance($user),
            'Completing moves no money, so the charge from submission is the only thing that may have changed.',
        );
    }

    public function testACheckpointLostToACrashReplaysAsUnchangedAndRefundsNothingTwice(): void
    {
        $user = $this->makeUser('replay');
        $admin = $this->makeUser('admin', 'admin');
        $orders = $this->submitOrders($user, self::ORDER_COUNT);

        // Failing an order refunds what was held on submission, so a double
        // refund is the one thing in this file that would actually cost money.
        $this->admin->settleMany(array_slice($orders, 0, 2), StatusPresenter::FAILED, $admin);
        $afterFirstPass = $this->balance($user);

        // The job is now replayed from a cursor of zero with two rows already in
        // the target status — a chunk that moved and lost its checkpoint.
        $jobId = $this->queue($orders, StatusPresenter::FAILED, $admin, self::CHUNK);
        $this->bulk->run($this->claim($jobId));

        $job = $this->jobRow($jobId);
        assertSame('done', (string) $job['status']);
        assertSame(
            2,
            (int) $job['unchanged'],
            'A replayed row that is already settled is `unchanged`, which is what makes resuming safe.',
        );
        assertSame(
            self::ORDER_COUNT - 2,
            (int) $job['applied'],
            'Only the rows that genuinely moved this pass may count as applied.',
        );

        // The two pre-failed rows were refunded once, when first failed; the
        // replay must add nothing, so the balance is where the first pass left it
        // plus a refund for each of the remaining three.
        assertEquals(
            $afterFirstPass + self::PRICE * (self::ORDER_COUNT - 2),
            $this->balance($user),
            'The replay refunded the two already-failed orders a second time.',
        );
        assertStringContainsString(
            'আগেই',
            (string) $job['message'],
            'The operator is told the batch was partly a no-op rather than being shown a count that overstates the work.',
        );
    }

    public function testAJobWhosePayloadTheEnqueueWouldNotHaveWrittenFailsLoudly(): void
    {
        $jobId = $this->jobs->enqueue(
            'settle_status',
            ['ids' => [1, 2], 'status' => 'nonsense'],
            0,
            'probe',
            2,
            self::CHUNK,
        );

        $message = $this->bulk->run($this->claim($jobId));

        assertSame('failed', (string) $this->jobRow($jobId)['status'], 'A job that cannot run must not sit in the queue for a worker to retry forever.');
        assertStringContainsString('অবস্থা', $message);
    }

    // ---- The export, which needs no job at all ----------------------------

    public function testTheExportKeepsTheSelectionInTheOrderItWasTicked(): void
    {
        $user = $this->makeUser('csv');
        $this->submitOrders($user, 3);
        $orders = $this->ordersFor($user);
        assertCount(3, $orders);

        // A hand-made list, not an id range: the operator ticked them in an
        // order that means something to them and a spreadsheet must keep it.
        $ticked = [$orders[2], $orders[0], $orders[1]];
        $result = $this->exporter->csv($ticked);

        assertSame(3, $result['exported']);
        $rows = $this->csvRows($result['body']);
        assertSame('রেফারেন্স', $rows[0][0], 'The file opens on labelled columns, not column letters.');
        assertCount(4, $rows, 'A header and three orders.');

        foreach ($ticked as $i => $id) {
            assertSame(
                (string) $this->row($id)['reference'],
                $rows[$i + 1][0],
                'Row N of the file must be the Nth order ticked.',
            );
        }
    }

    public function testTheFileIsExcelProof(): void
    {
        $user = $this->makeUser('bom');
        $this->submitOrders($user, 1);
        $orders = $this->ordersFor($user);

        $result = $this->exporter->csv($orders);

        assertStringStartsWith("\xEF\xBB\xBF", $result['body'], 'Without the BOM Excel on Windows reads this file as the system codepage and every Bengali name becomes mojibake.');
        assertStringEndsWith("\r\n", $result['body'], 'RFC 4180 ends a record with CRLF.');
        assertStringContainsString("\r\n", $result['body'], 'Records are separated by CRLF, so a spreadsheet does not fold the file into one column.');

        $rows = $this->csvRows($result['body']);
        assertSame('Bulk probe, "chunked" ' . $this->suffix, $rows[1][4], 'A comma and a quote in the service name survive because the record is quoted and the quote doubled.');
        assertSame(number_format(self::PRICE, 2, '.', ''), $rows[1][5], 'The amount is a fixed two-decimal number, not a float dump, so the column sums in Excel.');
        assertSame(
            StatusPresenter::label(StatusPresenter::PENDING),
            $rows[1][6],
            'The status cell is the operator-facing label, because the file is read by a person.',
        );
    }

    public function testATopUpIsTypedAsARechargeAndAnIdThatNoLongerExistsIsSimplyAbsent(): void
    {
        $user = $this->makeUser('mix');
        $this->submitOrders($user, 1);
        $orders = $this->ordersFor($user);

        // The one shape the order page never shows: a row sharing the table
        // with a null service_id, which is what makes a transaction a top-up.
        $topUpId = (int) $this->transactions->create([
            'user_id' => $user->id,
            'service_id' => null,
            'reference' => 'RC' . strtoupper($this->suffix),
            'amount' => 50.0,
            'status' => 'completed',
            'metadata' => ['method' => 'test'],
        ]);

        $result = $this->exporter->csv([$topUpId, $orders[0], 999999999]);

        assertSame(2, $result['exported'], 'A ticked id deleted a moment ago is absent from the file, not an error and not a blank row.');
        $rows = $this->csvRows($result['body']);
        assertSame('রিচার্জ', $rows[1][7], 'A null service_id IS the top-up, so the file must not call it a service.');
        assertSame('সার্ভিস', $rows[2][7]);
        assertCount(3, $rows, 'The vanished id contributes no row at all.');
    }

    // ---- Fixtures ---------------------------------------------------------

    private function makeUser(string $tag = 'a', string $role = 'user'): Identity
    {
        $id = $this->users->create([
            'username' => 'bulk_' . $tag . '_' . $this->suffix,
            'phone' => '7' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'bulk_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
            'balance' => self::START_BALANCE,
        ]);
        // The one-time free search is a real feature; these tests construct
        // users without it so the charge/refund arithmetic stays exact.
        $this->users->update($id, ['free_searches' => 0]);
        $this->userIds[] = $id;

        return Identity::fromRow((array) $this->users->findById($id));
    }

    /** @return int[] the ids of $count freshly placed pending orders */
    private function submitOrders(Identity $user, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $result = $this->manager->submit(
                (array) $this->services->findServiceById($this->serviceId),
                $user,
                ['nid_number' => '19901234567' . $i, 'date_of_birth' => '1990-05-04'],
                '127.0.0.1',
                'codecept',
            );
            assertTrue($result->success, 'A valid submission must be accepted: ' . $result->message);
            $ids[] = (int) $result->data['_request_id'];
        }

        return $ids;
    }

    /** @return int[] this user's orders, oldest first */
    private function ordersFor(Identity $user): array
    {
        return array_map(
            'intval',
            (array) $this->db
                ->createCommand('SELECT [[id]] FROM {{%transaction}} WHERE [[user_id]] = :u ORDER BY [[id]] ASC')
                ->bindValue(':u', $user->id)
                ->queryColumn(),
        );
    }

    private function queue(array $ids, string $status, Identity $admin, int $chunkSize): int
    {
        $result = $this->bulk->enqueueSettle($ids, $status, $admin);
        assertTrue($result['ok'], $result['message']);

        // The chunk size is a worker concern, so it is written the way the
        // worker will read it rather than forced in afterwards.
        $this->db
            ->createCommand()->update('{{%admin_bulk_job}}', ['chunk_size' => $chunkSize], ['id' => $result['jobId']])
            ->execute();

        return $result['jobId'];
    }

    /** @return array<string, mixed> the claimed row, as the worker receives it */
    private function claim(int $jobId, int $staleAfter = 300): array
    {
        $claimed = $this->jobs->claimNext($staleAfter);
        assertNotNull($claimed, 'The job must be claimable by the worker.');
        assertSame($jobId, (int) $claimed['id'], 'Claimed out of turn: another test left a job in the queue.');
        assertSame('processing', (string) $claimed['status']);
        assertGreaterThan(0, (int) $claimed['attempts'], 'A claim counts the attempt, which is what the reclaim later compares against.');

        return $claimed;
    }

    /** @return array<string, mixed> */
    private function jobRow(int $id): array
    {
        $row = $this->jobs->findById($id);
        assertNotNull($row, 'The job row must still exist.');

        return $row;
    }

    private function countJobs(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%admin_bulk_job}}')
            ->queryScalar();
    }

    private function row(int $id): array
    {
        $row = $this->transactions->findById($id);
        assertNotNull($row, 'The fixture order must still exist.');

        return $row;
    }

    private function balance(Identity $user): float
    {
        return (float) ((array) $this->users->findById($user->id))['balance'];
    }

    /**
     * The file parsed back into records, the way a spreadsheet would.
     *
     * Deliberately a real RFC 4180 reader rather than a `str_getcsv` per line:
     * a quoted field containing CRLF is exactly what the quoting exists for, so
     * a line-splitting parser would agree with a broken implementation.
     *
     * @return array<int, array<int, string>>
     */
    private function csvRows(string $body): array
    {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];
        while (            ($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row !== [null] || $rows !== []) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }
}
