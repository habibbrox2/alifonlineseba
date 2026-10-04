<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\ServiceOrderRepository;
use App\Tests\Support\TestGraph;
use App\Repository\UserRepository;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\Service\StatusPresenter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The service request lifecycle the history page drives: submit queues a
 * pending request and charges once, start completes it, cancel refunds it and
 * retry re-charges it. Ownership and the status filter are checked too, since
 * both decide what a user is allowed to see.
 *
 * Throwaway rows only, all removed again in _after().
 */
final class ServiceHistoryTest extends \Codeception\Test\Unit
{
    private const PRICE = 12.5;
    private const START_BALANCE = 100.0;

    private ConnectionInterface $db;
    private ServiceRepository $services;
    private UserRepository $users;
    private ServiceOrderRepository $orders;
    private ServiceManager $manager;

    /** @var int[] */
    private array $userIds = [];
    private int $serviceId = 0;
    private string $suffix = '';
    private int $categoryId = 0;
    private string $startedAt = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->services = new ServiceRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->orders = TestGraph::orders($this->db);
        $this->manager = new ServiceManager(
            $this->services,
            $this->orders,
            TestGraph::ledger($this->db, $this->users),
            $this->users,
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            $this->makeNotify(),
            // The default window is a real, clock-dependent gate; these tests assert
            // on order behaviour, not on the hour of the day they happen to run.
            OrderWindowService::alwaysOpen(),
        );

        // Fan-out rows land on users outside our own list (the real admin gets
        // every admin broadcast), so cleanup also sweeps by this time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
        $this->suffix = 't' . substr(md5(uniqid('', true)), 0, 10);
        $this->categoryId = (int) $this->db
            ->createCommand("SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1")
            ->queryScalar();

        $this->serviceId = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'History Probe ' . $this->suffix,
            'slug' => 'history-probe-' . $this->suffix,
            'description' => 'Disposable service-request fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => self::PRICE,
            'status' => 'active',
            'sort_order' => 999,
        ]);
    }

    protected function _after(): void
    {
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

    /**
     * The real NotificationManager fans out over the queue table; for these
     * lifecycle tests a fresh instance on the same connection is fine — the
     * rows it writes are throwaway like everything else here.
     */
    private function makeNotify(): \App\Notification\NotificationManager
    {
        return new \App\Notification\NotificationManager(
            new NotificationRepository($this->db),
            new \App\Notification\QueueRepository($this->db),
            new \App\Notification\TemplateRenderer(
                $this->db,
                new \App\Repository\SettingsRepository($this->db),
            ),
            $this->users,
            $this->db,
        );
    }

    private function makeUser(string $tag = 'a', int $freeSearches = 0): Identity
    {
        $id = $this->users->create([
            'username' => 'hist_' . $tag . '_' . $this->suffix,
            'phone' => '9' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'hist_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => self::START_BALANCE,
        ]);
        // The one-time free search is a real feature; the charging lifecycle
        // tests construct users without it so their arithmetic stays exact.
        if ($freeSearches !== 1) {
            $this->users->update($id, ['free_searches' => $freeSearches]);
        }
        $this->userIds[] = $id;
        return Identity::fromRow((array) $this->users->findById($id));
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

    // ---- Submit -----------------------------------------------------------

    public function testSubmitQueuesAPendingRequestAndChargesOnce(): void
    {
        $user = $this->makeUser('submit');
        $row = $this->submitRequest($user);

        assertSame(StatusPresenter::PENDING, (string) $row['status'], 'A fresh request is queued, not executed.');
        assertEquals(self::PRICE, (float) $row['amount']);
        assertEquals(self::START_BALANCE - self::PRICE, $this->balance($user), 'The price is deducted once.');

        $metadata = ServiceOrderRepository::metadata($row);
        assertSame('mock-nid', $metadata['provider'], 'The provider is stored so a retry can run it again.');
        assertSame(
            ['nid_number', 'date_of_birth'],
            $metadata['input_keys'],
            'Only the configured form fields are kept.',
        );
    }

    public function testSubmitRefusesWhenTheBalanceIsTooLow(): void
    {
        $user = $this->makeUser('poor');
        $this->users->update($user->id, ['balance' => 1.0]);

        $result = $this->manager->submit($this->service(), $user, ['nid_number' => '1990123456789'], '127.0.0.1', 'codecept');

        assertFalse($result->success, 'An unaffordable request must be refused.');
        assertEquals(1.0, $this->balance($user), 'A refused request must not touch the balance.');
        assertSame(0, $this->orders->statusCounts($user->id)['pending'] ?? 0);
    }

    public function testTheFirstRequestRidesTheFreeSearch(): void
    {
        $user = $this->makeUser('freebie', 1);
        $row = $this->submitRequest($user);

        assertEquals(self::START_BALANCE, $this->balance($user), 'The free search must not touch the balance.');
        assertEquals(0.0, (float) $row['amount'], 'The request is recorded at zero cost.');
        assertTrue((bool) ServiceOrderRepository::metadata($row)['free_search'], 'The request remembers it was free.');
        assertSame(0, $this->users->freeSearches($user->id), 'The allowance is spent.');

        // The second request pays normally.
        $second = $this->submitRequest($user);
        assertEquals(self::START_BALANCE - self::PRICE, $this->balance($user));
        assertFalse((bool) ServiceOrderRepository::metadata($second)['free_search']);
    }

    public function testFreeSearchIsConsumedEvenWhenTheBalanceWouldCoverIt(): void
    {
        // The freebie is use-it-or-lose-it, not balance-conditional.
        $user = $this->makeUser('rich', 1);
        $this->submitRequest($user);

        assertSame(0, $this->users->freeSearches($user->id));
        assertEquals(self::START_BALANCE, $this->balance($user));
    }

    // ---- Start ------------------------------------------------------------

    public function testStartRunsTheProviderAndStopsAtProcessing(): void
    {
        $user = $this->makeUser('start');
        $pending = $this->submitRequest($user);

        $result = $this->manager->start($pending, $user, '127.0.0.1', 'codecept');
        assertTrue($result->success, 'The provider must run: ' . $result->message);

        // `processing`, not `completed`. Completion is the admin's decision and
        // is the edge on which money changes hands, so a customer who could
        // finish their own order would close it with nobody paid and nothing
        // left for an admin to approve.
        $row = (array) $this->orders->findById((int) $pending['id']);
        assertSame(StatusPresenter::PROCESSING, (string) $row['status']);
        assertEquals(self::START_BALANCE - self::PRICE, $this->balance($user), 'Starting must not charge again.');

        $result_data = ServiceOrderRepository::metadata($row)['result'] ?? null;
        assertNotNull($result_data, 'A started order stores its result for the view panel.');
    }

    public function testStartRefusesARequestThatIsNoLongerPending(): void
    {
        $user = $this->makeUser('twice');
        $pending = $this->submitRequest($user);
        assertTrue($this->manager->start($pending, $user, '127.0.0.1', 'codecept')->success);

        $again = $this->manager->start($pending, $user, '127.0.0.1', 'codecept');
        assertFalse($again->success, 'A completed request cannot be started again.');
        assertEquals(self::START_BALANCE - self::PRICE, $this->balance($user), 'A refused start must not charge.');
    }

    // ---- Cancel -----------------------------------------------------------

    public function testCancelRefundsAndRejectsASecondCancel(): void
    {
        $user = $this->makeUser('cancel');
        $pending = $this->submitRequest($user);

        $result = $this->manager->cancel($pending, $user, '127.0.0.1', 'codecept');
        assertTrue($result->success);
        assertEquals(self::START_BALANCE, $this->balance($user), 'Cancelling gives the money back.');

        $row = (array) $this->orders->findById((int) $pending['id']);
        assertSame(StatusPresenter::CANCELLED, (string) $row['status']);

        $again = $this->manager->cancel($row, $user, '127.0.0.1', 'codecept');
        assertFalse($again->success, 'A cancelled request cannot be cancelled again.');
        assertEquals(self::START_BALANCE, $this->balance($user), 'A refused cancel must not refund twice.');
    }

    // ---- Retry ------------------------------------------------------------

    public function testRetryRechargesAndRunsACancelledRequest(): void
    {
        $user = $this->makeUser('retry');
        $pending = $this->submitRequest($user);
        $this->manager->cancel($pending, $user, '127.0.0.1', 'codecept');

        $cancelled = (array) $this->orders->findById((int) $pending['id']);
        $result = $this->manager->retry($cancelled, $user, '127.0.0.1', 'codecept');
        assertTrue($result->success, 'A cancelled request must be retryable: ' . $result->message);

        $row = (array) $this->orders->findById((int) $pending['id']);
        assertSame(StatusPresenter::PROCESSING, (string) $row['status'], 'Retry runs the provider right away.');
        assertEquals(
            self::START_BALANCE - self::PRICE,
            $this->balance($user),
            'Retrying pays the price again because cancelling had refunded it.',
        );
        assertSame(1, ServiceOrderRepository::metadata($row)['attempts'], 'The attempt counter is kept.');
    }

    public function testRetryRefusesARequestThatIsStillPending(): void
    {
        $user = $this->makeUser('retryearly');
        $pending = $this->submitRequest($user);

        $result = $this->manager->retry($pending, $user, '127.0.0.1', 'codecept');
        assertFalse($result->success, 'Only failed or cancelled requests can be retried.');
        assertSame(StatusPresenter::PENDING, (string) ((array) $this->orders->findById((int) $pending['id']))['status']);
        assertEquals(self::START_BALANCE - self::PRICE, $this->balance($user));
    }

    // ---- Listing, ownership, filters --------------------------------------

    public function testHistoryListsOnlyTheUsersOwnRequestsWithPerStatusCounts(): void
    {
        $owner = $this->makeUser('owner');
        $stranger = $this->makeUser('stranger');

        $a = $this->submitRequest($owner);
        $b = $this->submitRequest($owner);
        $this->submitRequest($stranger);
        $this->manager->cancel($b, $owner, '127.0.0.1', 'codecept');

        $listing = $this->orders->forUser($owner->id, 1, 15);
        $ids = array_map('intval', array_column($listing['rows'], 'id'));
        sort($ids);
        assertSame([(int) $a['id'], (int) $b['id']], $ids, 'Only the owner\'s own requests are listed.');
        assertSame(2, $listing['total']);

        $counts = $this->orders->statusCounts($owner->id);
        assertSame(1, $counts[StatusPresenter::PENDING] ?? 0);
        assertSame(1, $counts[StatusPresenter::CANCELLED] ?? 0);
        assertNull(
            $this->orders->findOwned((int) $a['id'], $stranger->id),
            'A request id must not resolve for anybody but its owner.',
        );
        assertNotNull($this->orders->findOwned((int) $a['id'], $owner->id));
    }

    public function testStatusFilterNarrowsTheListingAndIgnoresAnUnknownValue(): void
    {
        $user = $this->makeUser('filter');
        $a = $this->submitRequest($user);
        $b = $this->submitRequest($user);
        $this->manager->cancel($b, $user, '127.0.0.1', 'codecept');

        $pending = $this->orders->forUser($user->id, 1, 15, StatusPresenter::PENDING);
        assertSame(1, $pending['total']);
        assertSame((int) $a['id'], (int) $pending['rows'][0]['id']);

        $cancelled = $this->orders->forUser($user->id, 1, 15, StatusPresenter::CANCELLED);
        assertSame(1, $cancelled['total']);
        assertSame((int) $b['id'], (int) $cancelled['rows'][0]['id']);

        // The repository filters on whatever it is given, so the action is what
        // has to refuse an unknown value — otherwise a junk ?status= drops rows.
        assertSame(0, $this->orders->forUser($user->id, 1, 15, 'nonsense')['total']);
        assertFalse(StatusPresenter::isRequestStatus('nonsense'), 'An unknown status must not pass validation.');
        assertFalse(StatusPresenter::isRequestStatus(''), 'No filter is not a status either.');
        assertTrue(StatusPresenter::isRequestStatus(StatusPresenter::PENDING));
    }

    // ---- Action map -------------------------------------------------------

    public function testEachStatusOffersExactlyTheActionsItAllows(): void
    {
        $expected = [
            StatusPresenter::PENDING => ['start', 'cancel'],
            StatusPresenter::PROCESSING => [],
            StatusPresenter::COMPLETED => ['view'],
            StatusPresenter::FAILED => ['retry'],
            StatusPresenter::CANCELLED => ['retry'],
        ];

        foreach ($expected as $status => $keys) {
            $actions = StatusPresenter::requestActions($status);
            assertSame($keys, array_column($actions, 'key'), 'Wrong actions offered for ' . $status . '.');
            foreach ($actions as $action) {
                assertArrayHasKey('label', $action);
                assertSame($action['key'] !== 'view', $action['remote'], 'Only view is answered client-side.');
            }
        }

        assertSame([], StatusPresenter::requestActions('nonsense'), 'An unknown status offers nothing.');
    }

    public function testResultEntriesDropInternalAndEmptyValues(): void
    {
        $entries = StatusPresenter::resultEntries([
            '_demo' => true,
            '_notice' => 'ডেমো',
            'name' => 'Rahim Uddin (Demo)',
            'nid_number' => '1990123456789',
            'blood_group' => 'A+',
            'photo' => null,
        ]);

        assertSame(['name', 'nid_number', 'blood_group'], array_column($entries, 'key'));
        assertSame('নাম', $entries[0]['label'], 'Known result keys get a Bengali label.');
        assertSame('A+', $entries[2]['value']);
        assertSame([], StatusPresenter::resultEntries(null));
    }
}
