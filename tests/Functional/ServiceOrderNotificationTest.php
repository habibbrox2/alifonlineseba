<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Notification\Push\VapidKeys;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\SettingsRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\TransactionRepository;
use App\Tests\Support\TestGraph;
use App\Repository\UserRepository;
use App\Service\DeliverableStorage;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\Service\ServiceRequestAdminService;
use App\Service\StatusPresenter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Who hears about a service order, and — the part that is easy to get wrong —
 * *where* each copy lands.
 *
 * One event, two audiences, two different landing pages. The customer wants
 * the row that was just created; staff want the queue entry that needs a
 * decision, pre-filtered to this reference. `dispatch()` therefore takes a
 * sixth argument, the admin link, which overrides the customer's link for the
 * fan-out copies only.
 *
 * The `link` column is not the whole story, and that is why these tests read
 * the queue as well: `push-sw.js` has no database and no session, so the only
 * thing it can read is the push body, and it takes the click target from
 * `parsed.data.url`. An admin notification with a perfect `link` and no
 * `data.url` would land and then open `/` — which for staff is a page with
 * nothing on it. Asserting only the in-app row would have passed the whole
 * time that bug was live.
 *
 * Deliberately *not* re-tested here: balances, refunds and bulk settle
 * ([AdminServiceRequestTest](AdminServiceRequestTest.php)) and the channel
 * matrix in general ([NotificationCoreTest](NotificationCoreTest.php)). This
 * file is about targeting and the link.
 *
 * Throwaway rows only, all removed again in _after().
 */
final class ServiceOrderNotificationTest extends \Codeception\Test\Unit
{
    private const PRICE = 12.5;
    private const START_BALANCE = 100.0;

    private ConnectionInterface $db;
    private ServiceRepository $services;
    private UserRepository $users;
    private TransactionRepository $transactions;
    private ServiceOrderRepository $orders;
    private \App\Service\LedgerService $ledger;
    private ServiceManager $manager;
    private ServiceRequestAdminService $desk;

    /** @var int[] */
    private array $userIds = [];
    private int $serviceId = 0;
    private string $suffix = '';
    private int $categoryId = 0;
    private string $startedAt = '';
    /** @var array<string, string|null> */
    private array $previousEnv = [];

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
        $this->transactions = TestGraph::ledgerRepository($this->db);

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
            // on who is told where, not on the hour of the day they happen to run.
            OrderWindowService::alwaysOpen(),
        );

        $this->desk = new ServiceRequestAdminService(
            $this->orders,
            $this->ledger,
            $this->users,
            DeliverableStorage::fromProjectRoot(),
            $notify,
            new ActivityLogRepository($this->db),
        );

        // Fan-out rows land on users outside our own list (the real admin gets
        // every admin broadcast), so cleanup also sweeps by this time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
        $this->suffix = 'n' . substr(md5(uniqid('', true)), 0, 10);
        $this->categoryId = (int) $this->db
            ->createCommand("SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1")
            ->queryScalar();

        // The webpush row is behind NotificationManager::pushAllowed(), which
        // requires a key pair. tests/bootstrap.php deliberately never loads
        // .env, so without this the push assertions would pass for the wrong
        // reason — "no webpush" proves nothing when the absence is the keys.
        $this->loadVapidKeys();

        $this->serviceId = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Notify Probe ' . $this->suffix,
            'slug' => 'notify-probe-' . $this->suffix,
            'description' => 'Disposable service-request fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => self::PRICE,
            'status' => 'active',
            'sort_order' => 999,
        ]);
    }

    protected function _after(): void
    {
        // Deliveries reference queue rows (FK), which reference the users.
        $this->db
            ->createCommand('DELETE nd FROM {{%notification_delivery}} nd JOIN {{%notification_queue}} q ON q.id = nd.queue_id WHERE q.created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification_queue}} WHERE created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        // In-app fan-out rows that landed on users outside our own list.
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();

        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            // Ledger rows pointing at this user's orders have to go *before* the
            // orders: a ledger entry references the order that moved the money,
            // and the FK refuses the other order. Deleting by join rather than by
            // id list, because the ids are not known here.
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db
                ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :u)')
                ->bindValue(':u', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];

        TestGraph::purgeServiceOrders($this->db, $this->serviceId);
        $this->db->createCommand()->delete('{{%service}}', ['id' => $this->serviceId])->execute();

        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if ($this->previousEnv[$name] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $this->previousEnv[$name];
            }
        }
        $this->previousEnv = [];
    }

    /**
     * Make the VAPID key pair visible to Env for the duration of this test.
     *
     * `createArrayBacked()` returns the parsed file instead of writing it into
     * $_ENV, so only the two variables these tests need are ever set, and
     * _after() puts them back exactly as it found them.
     *
     * @see WebPushOptOutTest for the same helper and the longer version of this note
     */
    private function loadVapidKeys(): void
    {
        $this->previousEnv = [
            'VAPID_SUBJECT' => $_ENV['VAPID_SUBJECT'] ?? null,
            'VAPID_PRIVATE_KEY' => $_ENV['VAPID_PRIVATE_KEY'] ?? null,
        ];

        if (VapidKeys::fromEnv() !== null) {
            return;
        }

        /** @var array<string, string> $values */
        $values = \Dotenv\Dotenv::createArrayBacked(dirname(__DIR__, 2))->safeLoad();
        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if (isset($values[$name]) && $values[$name] !== '') {
                $_ENV[$name] = $values[$name];
            }
        }

        assertTrue(
            VapidKeys::fromEnv() !== null,
            'This test cannot assert anything about push without VAPID keys in .env.'
                . ' Generate a pair with: php scripts/generate-vapid-keys.php mailto:ops@example.com'
        );
    }

    private function makeUser(string $tag = 'a', string $role = 'user'): Identity
    {
        $id = $this->users->create([
            'username' => 'son_' . $tag . '_' . $this->suffix,
            'phone' => '6' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'son_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
            'balance' => self::START_BALANCE,
        ]);
        // The one-time free search is a real feature; these tests construct
        // users without it so any charge arithmetic stays exact.
        $this->users->update($id, ['free_searches' => 0]);
        $this->userIds[] = $id;
        return Identity::fromRow((array) $this->users->findById($id));
    }

    /** @return array<string, mixed> the stored service row */
    private function service(): array
    {
        return (array) $this->services->findServiceById($this->serviceId);
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

    /**
     * A completed recharge, in the same table with no service on it.
     *
     * The point is that it is *indistinguishable* from the order except by
     * `service_id`, which is what `kind=service` filters on.
     *
     * @return array<string, mixed>
     */
    /**
     * A recharge, written through the real ledger gateway.
     *
     * A recharge is a ledger entry and nothing else now, so a fixture that
     * hand-wrote a row into the order table would be asserting against a
     * shape the product can no longer produce.
     *
     * @return array<string, mixed> the ledger row
     */
    private function makeTopUp(Identity $user, string $tag): array
    {
        $this->ledger->creditUser($user->id, 75.0, [
            'type' => TransactionRepository::TYPE_TOPUP,
            'description' => 'Fixture recharge ' . $tag,
        ]);

        return (array) $this->transactions->findById(
            (int) $this->db->createCommand('SELECT MAX([[id]]) FROM {{%transaction}}')->queryScalar(),
        );
    }

    /**
     * The newest in-app row this account was given for an event.
     *
     * @return array<string, mixed>
     */
    private function notification(int $userId, string $event): array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%notification}} WHERE [[user_id]] = :u AND [[event]] = :e ORDER BY [[id]] DESC LIMIT 1')
            ->bindValues([':u' => $userId, ':e' => $event])
            ->queryOne();
        assertNotNull($row, 'No ' . $event . ' notification reached account ' . $userId . '.');

        return (array) $row;
    }

    /**
     * The queued job for one channel, or null when there is none.
     *
     * Null is the interesting value: several tests below exist to prove a row
     * is *absent* for a particular audience.
     *
     * @return array<string, mixed>|null
     */
    private function queued(int $userId, string $event, string $channel): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%notification_queue}} WHERE [[user_id]] = :u AND [[event]] = :e AND [[channel]] = :c ORDER BY [[id]] DESC LIMIT 1')
            ->bindValues([':u' => $userId, ':e' => $event, ':c' => $channel])
            ->queryOne();

        return $row === null ? null : (array) $row;
    }

    /** @return string[] the channels queued for an event, for this account */
    private function channels(int $userId, string $event): array
    {
        $rows = $this->db
            ->createCommand('SELECT [[channel]] FROM {{%notification_queue}} WHERE [[user_id]] = :u AND [[event]] = :e')
            ->bindValues([':u' => $userId, ':e' => $event])
            ->queryColumn();

        return array_map('strval', (array) $rows);
    }

    /**
     * Where a queued push would take this person if they tapped it.
     *
     * Read from `payload.data.url`, which is the key `push-sw.js` actually
     * reads — the `link` column is invisible to the service worker, so a
     * notification can be filed perfectly and still open the wrong page.
     */
    private function clickTarget(int $userId, string $event, string $channel = 'webpush'): ?string
    {
        $row = $this->queued($userId, $event, $channel);
        if ($row === null) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);
        assertTrue(is_array($payload), 'A queued payload must be a JSON object.');

        $data = $payload['data'] ?? null;
        assertTrue(is_array($data), 'A queued payload must carry a data object.');

        $url = $data['url'] ?? null;
        assertTrue($url === null || is_string($url), 'data.url must be a string when present.');

        return $url;
    }

    /** The admin landing page this order's notification is expected to carry. */
    private function expectedAdminLink(string $reference): string
    {
        return ServiceManager::ADMIN_QUEUE . '?q=' . rawurlencode($reference);
    }

    // ---- The order reaches the staff desk ---------------------------------

    public function testTheAdminIsToldWhenAServiceIsOrdered(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        $row = $this->notification($admin->id, NotificationEvent::SERVICE_REQUEST_CREATED);

        assertSame('info', (string) $row['type']);
        assertSame(
            $this->expectedAdminLink((string) $order['reference']),
            (string) $row['link'],
            'Staff must land on the queue pre-filtered to this order, not on the customer history page.'
        );
    }

    public function testTheAdminPushClickLandsOnTheFilteredQueue(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        $target = $this->clickTarget($admin->id, NotificationEvent::SERVICE_REQUEST_CREATED);

        assertNotNull($target, 'The admin must have a webpush job for a new order.');
        assertSame(
            $this->expectedAdminLink((string) $order['reference']),
            $target,
            'push-sw.js reads data.url, so the queue link has to be in the payload —'
                . ' a correct link column alone would open / for a member of staff.'
        );
    }

    public function testTheCustomerKeepsTheirOwnHistoryPage(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $this->submitRequest($customer);

        $event = NotificationEvent::SERVICE_REQUEST_CREATED;

        assertSame('/service-history', (string) $this->notification($customer->id, $event)['link']);
        assertSame('/service-history', $this->clickTarget($customer->id, $event));

        // Same event, same moment, two destinations — and the admin copy is the
        // one that was overridden, not the customer's.
        assertNotContains('/service-history', [$this->clickTarget($admin->id, $event)]);
    }

    public function testTheAdminLinkIsAUrlTheQueuePageActuallyHonours(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);
        $topUp = $this->makeTopUp($customer, 'deep');

        $link = (string) $this->notification($admin->id, NotificationEvent::SERVICE_REQUEST_CREATED)['link'];

        // Round-trip it the way AdminOrdersAction::state() does, so this fails
        // if the link ever stops being a query string the page reads.
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        assertSame((string) $order['reference'], $query['q'] ?? null, 'q must search for this reference.');

        $data = $this->orders->adminList(
            1,
            20,
            '',
            (string) ($query['q'] ?? ''),
            'id',
            'desc',
        );

        assertSame(1, (int) $data['total'], 'The deep link must find exactly the order it names.');
        assertSame((string) $order['reference'], (string) $data['rows'][0]['reference']);

        // The recharge is not merely filtered out of this queue — it is not in
        // it at all. It is a ledger entry, and the queue only holds orders. The
        // old `kind=service` flag existed to separate two row types sharing one
        // table; the split makes the separation structural, and this asserts
        // that an order page cannot surface somebody's payment by accident.
        $onTheQueue = array_column(
            $this->orders->adminList(1, 20, '', '', 'id', 'desc')['rows'],
            'reference',
        );
        assertNotContains(
            (string) $topUp['reference'],
            $onTheQueue,
            'An order queue must never show a recharge — approving a recharge as an order would pay it twice.',
        );
    }

    public function testTheAdminKeepsTelegramWhileTheCustomerDoesNot(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $this->submitRequest($customer);

        $event = NotificationEvent::SERVICE_REQUEST_CREATED;

        // Telegram is admins-only, and that is enforced by wantsChannel() on the
        // *customer* path. The admin fan-out loop deliberately never calls it,
        // so staff are not caught by a rule written about users.
        assertContains('telegram', $this->channels($admin->id, $event));
        assertNotContains('telegram', $this->channels($customer->id, $event));
    }

    public function testAnAdminWhoTurnedPushOffStillGetsTheInAppCopy(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $this->users->setPushEnabled($admin->id, false);
        $this->submitRequest($customer);

        $event = NotificationEvent::SERVICE_REQUEST_CREATED;

        // The admin loop skips wantsChannel() but must not skip the webpush
        // gate: an admin's own opt-out binds exactly as a user's does.
        assertNull(
            $this->queued($admin->id, $event, 'webpush'),
            'An admin with push off must not get a webpush job.'
        );
        assertNotNull(
            $this->notification($admin->id, $event),
            'Opting out of push is not opting out of being told.'
        );
    }

    // ---- A status change reaches the customer ----------------------------

    public function testAStatusChangeReachesTheCustomerOnTheirHistoryPage(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        [$ok, $message] = $this->desk->setStatus(
            (int) $order['id'],
            StatusPresenter::PROCESSING,
            $admin,
            'কাজ শুরু হয়েছে',
        );
        assertTrue($ok, $message);

        $event = NotificationEvent::SERVICE_REQUEST_PROCESSING;
        $row = $this->notification($customer->id, $event);

        assertSame('/service-history', (string) $row['link']);
        assertSame('/service-history', $this->clickTarget($customer->id, $event));
        assertSame('info', (string) $row['type'], 'processing is a plain in_app type');

        // The admin who pressed the button is not the audience for the outcome.
        assertSame([], $this->channels($admin->id, $event), 'Settling a request is not news to the operator.');
    }

    /**
     * @dataProvider statusChangeProvider
     */
    public function testEveryStatusTheDeskCanReachAnnouncesItselfToTheCustomer(
        string $status,
        string $event,
    ): void {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        [$ok, $message] = $this->desk->setStatus((int) $order['id'], $status, $admin);
        assertTrue($ok, $message);

        $row = $this->notification($customer->id, $event);
        assertSame('/service-history', (string) $row['link']);
        assertSame('/service-history', $this->clickTarget($customer->id, $event));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statusChangeProvider(): array
    {
        return [
            'processing' => [StatusPresenter::PROCESSING, NotificationEvent::SERVICE_REQUEST_PROCESSING],
            'completed' => [StatusPresenter::COMPLETED, NotificationEvent::SERVICE_REQUEST_COMPLETED],
            'failed' => [StatusPresenter::FAILED, NotificationEvent::SERVICE_REQUEST_FAILED],
            'cancelled' => [StatusPresenter::CANCELLED, NotificationEvent::SERVICE_REQUEST_CANCELLED],
        ];
    }

    /**
     * A status `announce()` does not map must produce no notification.
     *
     * `setStatus()` now refuses anything but `processing` on its non-money path,
     * so `pending` is no longer reachable through it — an approved order cannot
     * be walked back, which is correct now that approval pays an operator. The
 * *rejection* is therefore what carries the guarantee this test is about: a
 * status change that did not happen must not announce that it did. Asserting
 * on the notification list keeps that property under test regardless of which
 * statuses the method happens to accept.
     */
    public function testPendingIsNotSomethingToAnnounce(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        // Settle it, then try to walk it back to pending. announce() maps four
        // statuses and returns null for anything else, so a re-queue must be
        // silent — a notification for a no-op trains people to ignore them.
        assertTrue($this->desk->setStatus((int) $order['id'], StatusPresenter::COMPLETED, $admin)[0]);
        assertFalse(
            $this->desk->setStatus((int) $order['id'], StatusPresenter::PENDING, $admin)[0],
            'Walking an approved order back to pending is not a transition the desk offers.',
        );

        $events = $this->db
            ->createCommand('SELECT [[event]] FROM {{%notification}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $customer->id)
            ->queryColumn();

        assertEquals(
            [
                NotificationEvent::SERVICE_REQUEST_CREATED,
                NotificationEvent::SERVICE_REQUEST_COMPLETED,
            ],
            array_map('strval', (array) $events),
            'Only the create and the completion are news; going back to pending is not.'
        );
    }

    public function testARejectedStatusChangeTellsNobodyAnything(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        [$ok] = $this->desk->setStatus((int) $order['id'], 'nonsense', $admin);
        assertTrue(!$ok, 'An unknown status must be refused.');

        $events = array_map('strval', (array) $this->db
            ->createCommand('SELECT [[event]] FROM {{%notification}} WHERE [[user_id]] = :u AND [[event]] <> :created')
            ->bindValues([':u' => $customer->id, ':created' => NotificationEvent::SERVICE_REQUEST_CREATED])
            ->queryColumn());
        assertEquals([], $events, 'A refused change must leave no trace in the inbox.');
    }

    // ---- The payload shape both audiences depend on ----------------------

    public function testEveryQueuedPushCarriesTheEventAndTheClickTarget(): void
    {
        $customer = $this->makeUser('cust');
        $admin = $this->makeUser('boss', 'admin');
        $order = $this->submitRequest($customer);

        foreach ([$customer->id, $admin->id] as $userId) {
            $row = $this->queued($userId, NotificationEvent::SERVICE_REQUEST_CREATED, 'webpush');
            assertNotNull($row, 'Both audiences get a webpush job.');

            $data = json_decode((string) $row['payload'], true)['data'] ?? [];
            assertTrue(is_array($data), 'A queued payload must carry a data object.');

            // `event` is what lets a client route one push handler instead of
            // guessing from the copy, and `url` is the whole click target.
            assertArrayHasKey('event', $data);
            assertArrayHasKey('url', $data);
            assertSame(NotificationEvent::SERVICE_REQUEST_CREATED, $data['event']);
            assertSame((string) $order['reference'], (string) ($data['reference'] ?? ''));
        }
    }
}
