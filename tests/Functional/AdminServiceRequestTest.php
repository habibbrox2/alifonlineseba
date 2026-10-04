<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\SettingsRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\TransactionRepository;
use App\Tests\Support\TestGraph;
use App\Repository\UserRepository;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Service\DeliverableStorage;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\Service\ServiceRequestAdminService;
use App\Service\StatusPresenter;
use App\Web\Account\DeliverableAction;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertStringStartsNotWith;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * The admin side of a service order: the queue lists every request a user has
 * made, the operator settles it, and a manual result is handed over as a file
 * the user can then download.
 *
 * The assertions concentrate on the parts that move money or leak it:
 *
 * - a settle to failed/cancelled refunds the held amount, and doing it twice
 *   does not pay the user twice;
 * - an upload lands *outside* the document root, under a generated name, with
 *   the extension taken from the sniffed content rather than the client's file
 *   name;
 * - replacing or removing a deliverable actually deletes the old bytes, since a
 *   government ID left on disk is data about somebody that nothing can revoke;
 * - the download endpoint answers the owner and an admin, and gives a stranger
 *   the same 404 as a request that does not exist.
 *
 * Throwaway rows and files only, all removed again in _after().
 */
final class AdminServiceRequestTest extends \Codeception\Test\Unit
{
    private const PRICE = 12.5;
    private const START_BALANCE = 100.0;

    /** The recharge amount `makeTopUp()` credits. */
    private const TOPUP = 75.0;

    /** Smallest thing finfo still identifies as a real PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private static ?string $pngBytes = null;

    private ConnectionInterface $db;
    private ServiceRepository $services;
    private UserRepository $users;
    private ServiceOrderRepository $orders;
    private \App\Service\LedgerService $ledger;
    private TransactionRepository $ledgerRepository;
    private ServiceManager $manager;
    private ServiceRequestAdminService $admin;
    private DeliverableStorage $storage;

    /** @var int[] */
    private array $userIds = [];
    private int $serviceId = 0;
    private string $suffix = '';
    private string $startedAt = '';
    private int $categoryId = 0;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->services = new ServiceRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->orders = TestGraph::orders($this->db);
        $this->ledger = TestGraph::ledger($this->db, $this->users);
        $this->ledgerRepository = TestGraph::ledgerRepository($this->db);

        $notify = new NotificationManager(
            new NotificationRepository($this->db),
            new QueueRepository($this->db),
            new TemplateRenderer($this->db, new SettingsRepository($this->db)),
            $this->users,
            $this->db,
        );

        $this->manager = new ServiceManager(
            $this->services,
            $this->orders,
            $this->ledger,
            $this->users,
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            $notify,
            // The default window is a real, clock-dependent gate; these tests assert
            // on order behaviour, not on the hour of the day they happen to run.
            OrderWindowService::alwaysOpen(),
        );

        // A real storage instance on the real base path, not a temp dir: the
        // "written outside the document root" property is the thing under test,
        // and it is only true of the path production actually uses.
        $this->storage = DeliverableStorage::fromProjectRoot();

        $this->admin = new ServiceRequestAdminService(
            $this->orders,
            $this->ledger,
            $this->users,
            $this->storage,
            $notify,
            new ActivityLogRepository($this->db),
        );

        // Fan-out rows land on users outside our own list (the real admin gets
        // every admin broadcast), so cleanup also sweeps by this time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
        $this->suffix = 'a' . substr(md5(uniqid('', true)), 0, 10);
        $this->categoryId = (int) $this->db
            ->createCommand("SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1")
            ->queryScalar();

        $this->serviceId = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Admin Desk Probe ' . $this->suffix,
            'slug' => 'admin-desk-probe-' . $this->suffix,
            'description' => 'Disposable service-request fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => self::PRICE,
            'status' => 'active',
            'sort_order' => 999,
        ]);
    }

    protected function _after(): void
    {
        // Any file this test attached, by row or by path, so a failing
        // assertion cannot leave a fixture document on disk.
        foreach ($this->userIds as $id) {
            foreach ((array) $this->db
                ->createCommand('SELECT [[deliverable_path]] FROM {{%transaction}} WHERE [[user_id]] = :u')
                ->bindValue(':u', $id)
                ->queryColumn() as $path) {
                $this->storage->delete((string) $path);
            }
        }

        // Queue rows created during THIS test, wherever they landed — the
        // deliveries must go first (FK).
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
            // Ledger rows pointing at this user's orders have to go *before* the
            // orders: a ledger entry references the order that moved the money,
            // and the FK refuses the other order. Deleting by join rather than by
            // id list, because the ids are not known here.
            TestGraph::purgeUser($this->db, $id);
            // Deliveries reference queue rows (FK), which reference the user —
            // both must go before the user row or the delete is refused.
            $this->db
                ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :u)')
                ->bindValue(':u', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        TestGraph::purgeServiceOrders($this->db, $this->serviceId);
        $this->db->createCommand()->delete('{{%service}}', ['id' => $this->serviceId])->execute();
        $this->userIds = [];
        // In-app fan-out rows that landed on users outside our list.
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
    }

    /** Real PNG bytes — MIME sniffing works on content, not on a file name. */
    private static function pngBytes(): string
    {
        if (self::$pngBytes === null) {
            $decoded = base64_decode(self::PNG_BASE64, true);
            if ($decoded === false) {
                throw new \RuntimeException('PNG fixture must be valid base64');
            }
            self::$pngBytes = $decoded;
        }

        return self::$pngBytes;
    }

    /**
     * A real NotificationManager fans out over the queue table; for these
     * tests a fresh instance on the same connection is fine — the rows it
     * writes are throwaway like everything else here.
     */
    private function makeUser(string $tag = 'a', string $role = 'user'): Identity
    {
        $id = $this->users->create([
            'username' => 'adm_' . $tag . '_' . $this->suffix,
            'phone' => '7' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'adm_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
            'balance' => self::START_BALANCE,
        ]);
        // The one-time free search is a real feature; these tests construct users
        // without it so the charge/refund arithmetic stays exact.
        $this->users->update($id, ['free_searches' => 0]);
        $this->userIds[] = $id;
        return Identity::fromRow((array) $this->users->findById($id));
    }

    private function upload(string $bytes, string $name, string $declaredMime): UploadedFile
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, $declaredMime);
    }

    /** A valid 1x1 PNG, so the upload passes the real finfo MIME sniff. */
    private function pngUpload(string $name = 'result.png'): UploadedFile
    {
        return $this->upload(self::pngBytes(), $name, 'image/png');
    }

    /** @return array<string, mixed> the stored service row */
    private function service(): array
    {
        return (array) $this->services->findServiceById($this->serviceId);
    }

    private function balance(Identity $user): float
    {
        return (float) ((array) $this->users->findById($user->id))['balance'];
    }

    /** Queue a pending request for a user with a valid NID payload. */
    private function submitRequest(Identity $user): array
    {
        $result = $this->manager->submit(
            $this->service(),
            $user,
            ['nid_number' => '1990123456789', 'date_of_birth' => '1990-05-04'],
            '127.0.0.1',
            'codecept',
        );
        assertTrue($result->success, 'A valid submission must be accepted: ' . $result->message);

        return (array) $this->orders->findById((int) $result->data['_request_id']);
    }

    private function row(int $id): array
    {
        $row = $this->orders->findById($id);
        assertNotNull($row, 'The fixture request must still exist.');
        return $row;
    }

    // ---- Settling the status ---------------------------------------------

    public function testAdminCompletesAPendingRequestWithoutTouchingTheBalance(): void
    {
        $user = $this->makeUser('settle');
        $admin = $this->makeUser('admin', 'admin');
        $pending = $this->submitRequest($user);

        $result = $this->admin->setStatus((int) $pending['id'], StatusPresenter::COMPLETED, $admin, 'ডিসপ্যাচড');

        assertTrue($result[0], $result[1]);
        $row = $this->row((int) $pending['id']);
        assertSame(StatusPresenter::COMPLETED, (string) $row['status']);
        assertEquals(
            self::START_BALANCE - self::PRICE,
            $this->balance($user),
            'Completing a request delivers what was already charged; it must not refund or re-charge.',
        );
        assertSame(
            'ডিসপ্যাচড',
            (string) ($row['admin_note'] ?? ''),
            'The operator note is a column of its own, so the queue can filter on it.',
        );
    }

    public function testAnUnknownStatusIsRefusedAndTheRowIsLeftAlone(): void
    {
        $user = $this->makeUser('junk');
        $admin = $this->makeUser('admin', 'admin');
        $pending = $this->submitRequest($user);

        $result = $this->admin->setStatus((int) $pending['id'], 'nonsense', $admin);

        assertFalse($result[0], 'A status outside the request set must not be accepted.');
        assertSame(
            StatusPresenter::PENDING,
            (string) $this->row((int) $pending['id'])['status'],
            'A refused settle must not move the request.',
        );
    }

    public function testSettlingToTheSameStatusChangesNothingAndRefundsNothing(): void
    {
        $user = $this->makeUser('noop');
        $admin = $this->makeUser('admin', 'admin');
        $pending = $this->submitRequest($user);
        $id = (int) $pending['id'];

        assertTrue($this->admin->setStatus($id, StatusPresenter::FAILED, $admin)[0]);
        $afterFail = $this->balance($user);

        $again = $this->admin->setStatus($id, StatusPresenter::FAILED, $admin);

        assertTrue($again[0], 'A no-op is not an error, just nothing to do.');
        assertEquals($afterFail, $this->balance($user), 'Re-settling to the same status must not refund twice.');
    }

    // ---- The refund guard -------------------------------------------------

    public function testFailingAProcessingRequestRefundsTheHeldAmount(): void
    {
        $user = $this->makeUser('refund');
        $admin = $this->makeUser('admin', 'admin');
        $pending = $this->submitRequest($user);
        $id = (int) $pending['id'];

        assertTrue($this->admin->setStatus($id, StatusPresenter::PROCESSING, $admin)[0]);
        assertEquals(self::START_BALANCE - self::PRICE, $this->balance($user), 'Processing does not move money.');

        assertTrue($this->admin->setStatus($id, StatusPresenter::FAILED, $admin)[0]);

        assertEquals(
            self::START_BALANCE,
            $this->balance($user),
            'A request that failed gives the held money back.',
        );
        assertSame(StatusPresenter::FAILED, (string) $this->row($id)['status']);
    }

    public function testAnAlreadyRefundedRequestIsNotRefundedAgain(): void
    {
        $user = $this->makeUser('twice');
        $admin = $this->makeUser('admin', 'admin');
        $pending = $this->submitRequest($user);
        $id = (int) $pending['id'];

        assertTrue($this->admin->setStatus($id, StatusPresenter::CANCELLED, $admin)[0]);
        assertEquals(self::START_BALANCE, $this->balance($user), 'Cancelling refunds once.');

        // cancelled -> failed is a real transition, not a no-op, so the guard has
        // to hold on the *from* status: the money left the platform when it was
        // cancelled and must not leave again here.
        assertTrue($this->admin->setStatus($id, StatusPresenter::FAILED, $admin)[0]);

        assertEquals(
            self::START_BALANCE,
            $this->balance($user),
            'Only a still-held request refunds; an already-settled one must not pay out twice.',
        );
    }

    /**
     * Approving is the payout, so a completed order has already paid an admin
     * and cannot afterwards be relabelled as failed.
     *
     * The old shape of this test let the second transition succeed and simply
     * skipped the refund. That is the worse outcome: the user is told their
     * delivered work did not happen while the operator keeps the cash, and the
     * books show money paid for a `failed` order. Refusing the transition is
     * the honest answer — reversing a payout is a superadmin action, not a
     * dropdown.
     */
    public function testCompletingThenFailingIsRefusedBecauseTheMoneyIsAlreadyPaid(): void
    {
        $user = $this->makeUser('delivered');
        $admin = $this->makeUser('admin', 'admin');
        $pending = $this->submitRequest($user);
        $id = (int) $pending['id'];

        assertTrue($this->admin->setStatus($id, StatusPresenter::COMPLETED, $admin)[0]);

        [$ok, $message] = $this->admin->setStatus($id, StatusPresenter::FAILED, $admin);

        assertFalse($ok, 'A completed order has paid its operator; failing it now must be refused.');
        assertStringContainsString('এডমিনকে', $message, 'The reason has to name the money, not just say "closed".');
        assertSame(
            StatusPresenter::COMPLETED,
            (string) $this->row($id)['status'],
            'A refused transition must leave the status alone.',
        );
        assertEquals(
            self::START_BALANCE - self::PRICE,
            $this->balance($user),
            'Work that was delivered and paid for is not refunded by a later status change.',
        );
        assertEquals(
            self::START_BALANCE + self::PRICE,
            (float) ((array) $this->users->findById($admin->id))['balance'],
            'The operator keeps the payout; a refused transition must not claw it back either.',
        );
    }

    // ---- The bulk settle --------------------------------------------------

    /**
     * A recharge row for the same user: same table, NULL service_id, already
     * paid. It is what the bulk bar must never touch.
     *
     * @return array<string, mixed>
     */
    /**
     * A recharge for this user, written through the real ledger gateway.
     *
     * Deliberately not a bare INSERT: a hand-made row would only prove that a
     * hand-made row stays where it was put, and the property under test is
     * that the *production* path puts recharges in the ledger and nowhere else.
     *
     * @return array<string, mixed> the ledger row
     */
    private function makeTopUp(Identity $user, string $tag): array
    {
        $this->ledger->creditUser($user->id, self::TOPUP, [
            'type' => TransactionRepository::TYPE_TOPUP,
            'description' => 'Fixture recharge ' . $tag,
        ]);

        return (array) $this->ledgerRepository->findById(
            (int) $this->db->createCommand('SELECT MAX([[id]]) FROM {{%transaction}}')->queryScalar(),
        );
    }

    /** @return array<string, mixed> */
    private function lastNotification(Identity $user, string $event): array
    {
        return (array) $this->db
            ->createCommand('SELECT [[title]], [[message]] FROM {{%notification}} WHERE [[user_id]] = :u AND [[event]] = :e ORDER BY [[id]] DESC LIMIT 1')
            ->bindValues([':u' => $user->id, ':e' => $event])
            ->queryOne();
    }

    public function testABulkSettleMovesEverySelectedRequestAndRefundsEachHeldAmount(): void
    {
        $admin = $this->makeUser('admin', 'admin');
        $first = $this->makeUser('bulk1');
        $second = $this->makeUser('bulk2');
        $third = $this->makeUser('bulk3');
        $ids = [
            (int) $this->submitRequest($first)['id'],
            (int) $this->submitRequest($second)['id'],
            (int) $this->submitRequest($third)['id'],
        ];

        $result = $this->admin->settleMany($ids, StatusPresenter::FAILED, $admin);

        assertTrue($result['ok'], $result['message']);
        assertSame(3, $result['applied']);
        assertSame(0, $result['unchanged']);
        assertSame(0, $result['skipped']);

        foreach ($ids as $id) {
            assertSame(
                StatusPresenter::FAILED,
                (string) $this->row($id)['status'],
                'Every ticked order must move — a partial settle is the one outcome an operator cannot detect.',
            );
        }

        // Each row goes through setStatus(), so each one refunds on its own
        // terms and tells its own owner. Doing it in one submission must not
        // quietly collapse twenty users into one notification, or one refund.
        foreach ([$first, $second, $third] as $user) {
            assertEquals(
                self::START_BALANCE,
                $this->balance($user),
                'A request settled in bulk still refunds the held amount exactly once.',
            );
            assertNotSame(
                '',
                (string) ($this->lastNotification($user, NotificationEvent::SERVICE_REQUEST_FAILED)['title'] ?? ''),
                'A bulk settle must still tell each user their own request failed.',
            );
        }
    }

    public function testABulkSettleNeverTouchesARechargeIdInTheSameSelection(): void
    {
        $admin = $this->makeUser('admin', 'admin');
        $user = $this->makeUser('topup');
        $id = (int) $this->submitRequest($user)['id'];
        $topUp = $this->makeTopUp($user, 'bulk');
        $topUpId = (int) $topUp['id'];

        // Deliberately selected together. A recharge is no longer a row in the
        // order table at all, so the guard this used to need (a NULL service_id
        // meaning "not an order") is gone — and so is the hazard it guarded
        // against. The id below is a *ledger* id, and it must simply not
        // resolve to an order, rather than resolving to whatever order happens
        // to share the number.
        $result = $this->admin->settleMany([$id, $topUpId], StatusPresenter::FAILED, $admin);

        assertTrue($result['ok'], 'The service order in the selection still settles.');
        assertSame(1, $result['applied']);
        assertSame(1, $result['skipped'], 'A ledger id is not an order id and must be reported as skipped.');
        assertSame(StatusPresenter::FAILED, (string) $this->row($id)['status']);

        assertNull(
            $this->orders->findById($topUpId),
            'A recharge must not be reachable as an order — otherwise approving it would pay it twice.',
        );
        assertEquals(
            self::START_BALANCE + self::TOPUP,
            $this->balance($user),
            'Refunding a top-up would credit money this path never debited.'
                . ' The starting balance plus the recharge is the whole truth here:'
                . ' the order was debited then refunded, and the recharge was only ever credited.',
        );
    }

    public function testABulkSettleCountsRowsThatWereAlreadyInTheTargetStatus(): void
    {
        $admin = $this->makeUser('admin', 'admin');
        $user = $this->makeUser('unchanged');
        $already = (int) $this->submitRequest($user)['id'];
        $pending = (int) $this->submitRequest($user)['id'];

        assertTrue($this->admin->setStatus($already, StatusPresenter::COMPLETED, $admin)[0]);

        $result = $this->admin->settleMany([$already, $pending], StatusPresenter::COMPLETED, $admin);

        assertTrue($result['ok'], $result['message']);
        assertSame(1, $result['applied'], 'Only the request that was still open moved.');
        assertSame(1, $result['unchanged'], 'A row already in the target status is counted, not re-settled.');
        assertSame(StatusPresenter::COMPLETED, (string) $this->row($pending)['status']);
    }

    public function testABulkSettleRefusesAnUnknownStatusWithoutMovingAnything(): void
    {
        $admin = $this->makeUser('admin', 'admin');
        $user = $this->makeUser('badstatus');
        $id = (int) $this->submitRequest($user)['id'];

        $result = $this->admin->settleMany([$id], 'nonsense', $admin);

        assertFalse($result['ok']);
        assertSame(0, $result['applied']);
        assertSame(
            StatusPresenter::PENDING,
            (string) $this->row($id)['status'],
            'One bad status refuses the whole submission — it must never land halfway through a batch.',
        );
    }

    public function testABulkSettleIgnoresJunkAndMissingIds(): void
    {
        $admin = $this->makeUser('admin', 'admin');
        $user = $this->makeUser('junkids');
        $id = (int) $this->submitRequest($user)['id'];

        // What a hand-made POST can put in `ids[]`: a non-numeric string, a
        // zero, a negative, an id nobody has, and the same id twice.
        $result = $this->admin->settleMany(
            [$id, (string) $id, 'abc', 0, -7, 999999999],
            StatusPresenter::COMPLETED,
            $admin,
        );

        assertTrue($result['ok'], $result['message']);
        assertSame(1, $result['applied'], 'The one real order settles once, however many times it was sent.');
        // 'abc', 0 and -7 are not ids at all — they are dropped before the
        // batch is even read, and the repeated $id collapses into one lookup.
        // Only a well-formed id that no row answers to is worth reporting.
        assertSame(1, $result['skipped'], 'Only a real id with no row behind it is skipped, not the junk around it.');
        assertSame(StatusPresenter::COMPLETED, (string) $this->row($id)['status']);
        assertEquals(
            self::START_BALANCE - self::PRICE,
            $this->balance($user),
            'A completed request delivers what was charged; nothing is refunded.',
        );
    }

    public function testABulkSettleOfNothingIsRefusedRatherThanReportedAsDone(): void
    {
        $admin = $this->makeUser('admin', 'admin');
        $user = $this->makeUser('emptybulk');
        $id = (int) $this->submitRequest($user)['id'];

        $result = $this->admin->settleMany([], StatusPresenter::COMPLETED, $admin);

        assertFalse($result['ok'], 'An empty submission is not a successful one.');
        assertSame(0, $result['applied']);
        assertSame(StatusPresenter::PENDING, (string) $this->row($id)['status']);
    }

    // ---- The deliverable --------------------------------------------------

    public function testAnUploadedResultLandsOutsideTheDocumentRootUnderAGeneratedName(): void
    {
        $user = $this->makeUser('file');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        $result = $this->admin->attachDeliverable($id, $this->pngUpload('my result.png'), $admin);
        assertTrue($result[0], $result[1]);

        $row = $this->row($id);
        $path = (string) $row['deliverable_path'];
        assertStringStartsNotWith('my', $path, 'The client file name is never used as the path.');
        assertStringEndsWith('.png', $path);
        assertSame('my result.png', (string) $row['deliverable_name'], 'The original name is kept for display only.');
        assertSame('image/png', (string) $row['deliverable_mime']);
        assertSame(strlen(self::pngBytes()), (int) $row['deliverable_size']);
        assertSame($admin->id, (int) $row['deliverable_uploaded_by'], 'The upload is attributed for audit.');

        $absolute = $this->storage->absolutePath($path);
        assertNotNull($absolute, 'The stored file must be readable back.');

        $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
        if ($publicRoot !== false) {
            assertFalse(
                str_starts_with($absolute, $publicRoot . DIRECTORY_SEPARATOR),
                'A government ID result must never be written inside the document root.',
            );
        }
    }

    public function testAPhpUploadRenamedToPdfIsRefused(): void
    {
        $user = $this->makeUser('php');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        $result = $this->admin->attachDeliverable(
            $id,
            $this->upload("<?php echo 'pwned';", 'invoice.pdf', 'application/pdf'),
            $admin,
        );

        assertFalse($result[0], 'A script is not a document, whatever it is called.');
        assertNull(
            $this->row($id)['deliverable_path'] ?? null,
            'A refused upload must not record a deliverable.',
        );
    }

    public function testAnEmptyUploadIsRefused(): void
    {
        $user = $this->makeUser('empty');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        $result = $this->admin->attachDeliverable($id, $this->upload('', 'blank.pdf', 'application/pdf'), $admin);

        assertFalse($result[0], 'A zero-byte file is not a deliverable.');
        assertNull($this->row($id)['deliverable_path'] ?? null);
    }

    public function testReplacingAFileDeletesTheOldBytes(): void
    {
        $user = $this->makeUser('replace');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        assertTrue($this->admin->attachDeliverable($id, $this->pngUpload('first.png'), $admin)[0]);
        $first = (string) $this->row($id)['deliverable_path'];
        $firstAbsolute = (string) $this->storage->absolutePath($first);
        assertTrue(is_file($firstAbsolute));

        assertTrue($this->admin->attachDeliverable($id, $this->pngUpload('second.png'), $admin)[0]);
        $second = (string) $this->row($id)['deliverable_path'];

        assertNotNull($this->storage->absolutePath($second), 'The replacement is on disk.');
        assertFalse(
            is_file($firstAbsolute),
            'The superseded file must be deleted, not orphaned: a stale ID result nothing can revoke is still data about somebody.',
        );
    }

    public function testDetachClearsTheRowAndTheBytes(): void
    {
        $user = $this->makeUser('detach');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        assertTrue($this->admin->attachDeliverable($id, $this->pngUpload('wrong-scan.png'), $admin)[0]);
        $absolute = (string) $this->storage->absolutePath((string) $this->row($id)['deliverable_path']);

        $result = $this->admin->detachDeliverable($id, $admin);

        assertTrue($result[0], $result[1]);
        $row = $this->row($id);
        assertNull($row['deliverable_path'] ?? null);
        assertNull($row['deliverable_name'] ?? null);
        assertNull($row['deliverable_mime'] ?? null);
        assertFalse(is_file($absolute), 'Removing the file must remove the bytes.');

        $again = $this->admin->detachDeliverable($id, $admin);
        assertFalse($again[0], 'There is nothing left to remove.');
    }

    public function testDetachOnARequestThatNeverHadAFileSaysSo(): void
    {
        $user = $this->makeUser('nofile');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        $result = $this->admin->detachDeliverable($id, $admin);

        assertFalse($result[0], 'Removing a file that was never there is not a silent success.');
    }

    public function testAttachingToAMissingRequestIsRefused(): void
    {
        $admin = $this->makeUser('admin', 'admin');

        $result = $this->admin->attachDeliverable(999999999, $this->pngUpload(), $admin);

        assertFalse($result[0], 'An upload against a request that does not exist must not be stored.');
    }

    public function testTheFileReadyNotificationActuallyCarriesWords(): void
    {
        $user = $this->makeUser('told');
        $admin = $this->makeUser('admin', 'admin');
        $row = $this->submitRequest($user);
        $id = (int) $row['id'];

        assertTrue($this->admin->attachDeliverable($id, $this->pngUpload(), $admin)[0]);

        // service_request.file has no row in notification_template (it postdates
        // the seed), so this copy comes from MessageTemplates via the fallback.
        // TemplateRenderer used to mistake queryOne()'s null for a row and hand
        // back two empty strings, which silenced the fallback — the user would
        // have been told nothing at all, in the one notification that announces
        // the file they paid for.
        $stored = (array) $this->db
            ->createCommand("SELECT [[title]], [[message]] FROM {{%notification}} WHERE [[user_id]] = :u AND [[event]] = :e ORDER BY [[id]] DESC LIMIT 1")
            ->bindValues([':u' => $user->id, ':e' => NotificationEvent::SERVICE_REQUEST_FILE])
            ->queryOne();

        $title = (string) ($stored['title'] ?? '');
        assertNotSame('', $title, 'A notification with no title is indistinguishable from no notification.');
        assertStringContainsString('ফাইল', $title, 'The copy must say it is the file that is ready.');
        assertStringContainsString((string) $row['reference'], (string) ($stored['message'] ?? ''), 'The reference lets the user find the right order.');
    }

    // ---- The download endpoint -------------------------------------------

    public function testTheOwnerDownloadsTheirResultAndAStrangerCannot(): void
    {
        $user = $this->makeUser('owner');
        $stranger = $this->makeUser('stranger');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        assertTrue($this->admin->attachDeliverable($id, $this->pngUpload('result.png'), $admin)[0]);

        $response = $this->serve($user, $id);
        assertSame(200, $response->getStatusCode());
        assertSame('image/png', $response->getHeaderLine('Content-Type'));
        assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), 'nosniff is not optional.');
        assertSame('private, no-store', $response->getHeaderLine('Cache-Control'), 'An ID result must not sit in a shared cache.');
        assertStringContainsString('result.png', $response->getHeaderLine('Content-Disposition'));
        assertSame(self::pngBytes(), (string) $response->getBody(), 'The file is served verbatim — no watermark on somebody\'s document.');

        assertSame(200, $this->serve($admin, $id)->getStatusCode(), 'An admin may open any result to check it.');

        // The same answer for "not yours" and "does not exist", so the endpoint
        // cannot be used to confirm which service-request ids are real.
        $strangerResponse = $this->serve($stranger, $id);
        $missingResponse = $this->serve($stranger, 999999999);
        assertSame(404, $strangerResponse->getStatusCode());
        assertSame(404, $missingResponse->getStatusCode());
        assertSame((string) $strangerResponse->getBody(), (string) $missingResponse->getBody());
    }

    public function testAnImageIsServedInlineAndAPdfIsForcedToDownload(): void
    {
        $user = $this->makeUser('disposition');
        $admin = $this->makeUser('admin', 'admin');
        $id = (int) $this->submitRequest($user)['id'];

        assertTrue($this->admin->attachDeliverable($id, $this->pngUpload(), $admin)[0]);
        assertStringStartsWith('inline', $this->serve($user, $id)->getHeaderLine('Content-Disposition'));

        // A minimal but real PDF header, so finfo identifies it.
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        assertTrue($this->admin->attachDeliverable($id, $this->upload($pdf, 'result.pdf', 'application/pdf'), $admin)[0]);

        $response = $this->serve($user, $id);
        assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        assertStringStartsWith('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    public function testARequestWithNoFileIsATwoFiftyFourNotACrash(): void
    {
        $user = $this->makeUser('noresult');
        $id = (int) $this->submitRequest($user)['id'];

        assertSame(404, $this->serve($user, $id)->getStatusCode());
    }

    // ---- The queue the operator works from -------------------------------

    public function testTheServiceOrderQueueHoldsOrdersOnlyAndCountsTheHeldOnes(): void
    {
        $user = $this->makeUser('queue');
        $id = (int) $this->submitRequest($user)['id'];

        // A recharge is a ledger entry, not a queue row. It is written through
        // the real gateway so the row below is one the production path would
        // have written — inserting a bare row here would only prove that a
        // hand-made row stays where it was put.
        $this->ledger->creditUser($user->id, 75.0, [
            'type' => TransactionRepository::TYPE_TOPUP,
            'description' => 'Test recharge',
        ]);

        $queue = $this->orders->adminList(1, 15, '', '', 'id', 'desc');
        $ids = array_map('intval', array_column($queue['rows'], 'id'));
        assertTrue(in_array($id, $ids, true), 'The order must appear in the order queue.');
        assertFalse(
            in_array('TOPUP', array_map('strval', array_column($queue['rows'], 'service_name', null)), true),
            'The order queue carries no recharge rows at all.',
        );

        $open = $this->orders->openOrders();
        assertGreaterThan(0, $open['count'], 'The dashboard badge counts the held orders.');
        assertEquals(
            self::PRICE,
            (float) $this->db
                ->createCommand('SELECT COALESCE(SUM([[amount]]),0) FROM {{%service_order}} WHERE [[id]] = :id')
                ->bindValue(':id', $id)
                ->queryScalar(),
            'The order is still held at its full price.',
        );

        // Once settled it is no longer work in progress.
        assertTrue($this->admin->setStatus($id, StatusPresenter::COMPLETED, $this->makeUser('admin', 'admin'))[0]);
        $after = $this->orders->openOrders();
        assertSame(
            (int) $this->db
                ->createCommand("SELECT COUNT(*) FROM {{%service_order}} WHERE [[status]] IN ('pending','review')")
                ->queryScalar(),
            $after['count'],
            'Approving an order takes it off the open-orders count.',
        );
    }

    /** Drive DeliverableAction directly — it only needs the identity attribute. */
    private function serve(Identity $identity, int $transactionId): \Psr\Http\Message\ResponseInterface
    {
        $psr17 = new Psr17Factory();
        $action = new DeliverableAction($this->orders, $this->storage, $psr17, $psr17);

        $request = (new ServerRequest('GET', '/service-requests/' . $transactionId . '/file'))
            ->withAttribute('identity', $identity);

        $route = new CurrentRoute();
        $route->setRouteWithArguments(
            Route::get('/service-requests/{id}/file')->name('service-request-file'),
            ['id' => (string) $transactionId],
        );

        return $action($request, $route);
    }
}
