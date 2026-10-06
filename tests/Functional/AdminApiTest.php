<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Notification\QueueRepository;
use App\Repository\ActivityLogRepository;
use App\Repository\AdminWithdrawRepository;
use App\Repository\SettingsRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Service\AdminWithdrawService;
use App\Service\Api;
use App\Service\BulkJobService;
use App\Web\Api\Admin\AdminDashboardApiAction;
use App\Web\Api\Admin\AdminLogsApiAction;
use App\Web\Api\Admin\AdminNotificationsApiAction;
use App\Web\Api\Admin\AdminOrdersApiAction;
use App\Web\Api\Admin\AdminRechargesApiAction;
use App\Web\Api\Admin\AdminSettingsApiAction;
use App\Web\Api\Admin\AdminStaffApiAction;
use App\Web\Api\Admin\AdminTopupsApiAction;
use App\Web\Api\Admin\AdminUsersApiAction;
use App\Web\Api\Admin\AdminWithdrawsApiAction;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

/**
 * Direct action tests for the new /api/admin/* endpoints.
 *
 * These bypass the middleware stack and set the identity directly on the
 * request, which is exactly what the existing NotificationsApiTest does for
 * the public notification API. The assertions here are about what the action
 * itself returns, not about how the router assembled it.
 */
final class AdminApiTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private TopupRepository $topups;
    private ServiceOrderRepository $orders;
    private ActivityLogRepository $logs;
    private SettingsRepository $settings;
    private AdminWithdrawRepository $withdraws;
    private AdminWithdrawService $withdrawService;
    private BulkJobService $bulk;
    private QueueRepository $queue;

    private string $suffix = '';

    /** @var int[] */
    private array $userIds = [];

    /** @var int[] */
    private array $topupIds = [];

    /** @var int[] */
    private array $orderIds = [];

    /** @var int[] */
    private array $withdrawIds = [];

    /** @var array<string, array{id: int, setting_value: string, updated_by: int|null}> */
    private array $settingsSnapshot = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->topups = new TopupRepository($this->db);
        $this->orders = new ServiceOrderRepository($this->db);
        $this->logs = new ActivityLogRepository($this->db);
        $this->settings = new SettingsRepository($this->db);
        $this->withdraws = new AdminWithdrawRepository($this->db);
        $notifications = new \App\Repository\NotificationRepository($this->db);
        $queue = new QueueRepository($this->db);
        $templates = new \App\Notification\TemplateRenderer($this->db, $this->settings);
        $tx = new \App\Repository\TransactionRepository($this->db);
        $ledger = new \App\Service\LedgerService($this->db, $this->users, $tx);
        $bots = new \App\Repository\BotConnectionRepository($this->db);
        $notify = new \App\Notification\NotificationManager($notifications, $queue, $templates, $this->users, $this->db, $bots);
        $this->withdrawService = new AdminWithdrawService($this->db, $this->withdraws, $ledger, $notify, $this->logs);
        $deliverables = \App\Service\DeliverableStorage::fromProjectRoot();
        $settle = new \App\Service\ServiceRequestAdminService($this->orders, $ledger, $this->users, $deliverables, $notify, $this->logs);
        $this->bulk = new \App\Service\BulkJobService(new \App\Repository\BulkJobRepository($this->db), $settle);
        $this->queue = $queue;
        $this->services = new \App\Repository\ServiceRepository($this->db);
        $this->topupService = new \App\Service\TopupService(
            $this->topups,
            $this->users,
            $ledger,
            $notifications,
            $this->logs,
            $this->settings,
            \App\Service\ReceiptStorage::fromProjectRoot(),
            $notify,
        );

        $this->suffix = 'a' . substr(md5(uniqid('', true)), 0, 10);
    }

    protected function _after(): void
    {
        foreach ($this->orderIds as $id) {
            $this->db->createCommand()->delete('{{%service_order}}', ['id' => $id])->execute();
        }
        foreach ($this->topupIds as $id) {
            $this->db->createCommand()->delete('{{%topup_request}}', ['id' => $id])->execute();
        }
        foreach ($this->withdrawIds as $id) {
            $this->db->createCommand()->delete('{{%admin_withdraw_request}}', ['id' => $id])->execute();
        }
        // Restore settings BEFORE deleting users: putMany() writes updated_by=<test user id>
        // into site_setting (FK fk_site_setting_user -> user.id), so deleting the user first
        // would violate the foreign key on the next save's cleanup.
        $this->restoreSettings($this->settingsSnapshot);
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            // Recharge approvals credit the user, which writes ledger rows keyed by user_id;
            // withdraw decisions write rows keyed by admin_id. Clean both before dropping the user.
            $this->db->createCommand()->delete('{{%transaction}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%transaction}}', ['admin_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }

        $this->orderIds = [];
        $this->topupIds = [];
        $this->withdrawIds = [];
        $this->userIds = [];
        $this->settingsSnapshot = [];
    }

    private function makeUser(string $tag, string $role = 'user', string $status = 'active'): Identity
    {
        $id = $this->users->create([
            'username' => 'adminapiuser_' . $tag . '_' . $this->suffix,
            'phone' => '7' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'adminapi_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => $status,
            'role' => $role,
            'balance' => 0,
        ]);
        $this->userIds[] = $id;

        return Identity::fromRow((array) $this->users->findById($id));
    }

    private function makeOrder(int $userId, string $status = 'pending', array $extra = []): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%service_order}}', [
            'user_id' => $userId,
            'service_id' => 1,
            'reference' => 'ref_' . substr(md5(uniqid('', true)), 0, 8),
            'amount' => 100.0,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
            ...$extra,
        ])->execute();
        $id = (int) $this->db->getLastInsertID();
        $this->orderIds[] = $id;
        return $id;
    }

    private function makeTopup(int $userId, string $status = 'pending', array $extra = []): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%topup_request}}', [
            'user_id' => $userId,
            'amount' => 500.0,
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'reference' => 'trx_' . substr(md5(uniqid('', true)), 0, 8),
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
            ...$extra,
        ])->execute();
        $id = (int) $this->db->getLastInsertID();
        $this->topupIds[] = $id;
        return $id;
    }

    private function makeWithdraw(int $adminId, string $status = 'pending', array $extra = []): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%admin_withdraw_request}}', [
            'admin_id' => $adminId,
            'amount' => 250.0,
            'method' => 'bank',
            'account_details' => 'AC 0123456789, Functional Test Bank',
            'note' => 'functional test payout',
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
            ...$extra,
        ])->execute();
        $id = (int) $this->db->getLastInsertID();
        $this->withdrawIds[] = $id;
        return $id;
    }

    private function snapshotSettings(): void
    {
        $rows = $this->db->createCommand(
            'SELECT [[setting_key]], [[id]], [[setting_value]], [[updated_by]] FROM {{%site_setting}}'
        )->queryAll();

        $this->settingsSnapshot = [];
        foreach ($rows as $row) {
            $this->settingsSnapshot[(string) $row['setting_key']] = [
                'id' => (int) $row['id'],
                'setting_value' => (string) $row['setting_value'],
                'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            ];
        }
    }

    private function restoreSettings(array $snapshot): void
    {
        $current = $this->db->createCommand('SELECT [[setting_key]] FROM {{%site_setting}}')->queryColumn();

        foreach ($current as $key) {
            if (!isset($snapshot[$key])) {
                $this->db->createCommand()->delete('{{%site_setting}}', ['setting_key' => (string) $key])->execute();
                continue;
            }

            $row = $snapshot[$key];
            $this->db->createCommand()->update('{{%site_setting}}', [
                'setting_value' => $row['setting_value'],
                'updated_by' => $row['updated_by'],
            ], ['id' => $row['id']])->execute();
        }
    }

    /** Drive the action the way the router does, with optional route arguments. */
    private function call(Identity $user, string $method, string $uri, array $routeArgs = [], array $parsedBody = null, array $queryParams = []): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri))
            ->withAttribute('identity', $user);

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }

        $route = new CurrentRoute();
        $route->setRouteWithArguments(
            Route::methods(['GET', 'POST'], '/api/admin/dashboard')->name('api-admin-dashboard'),
            $routeArgs,
        );

        return ($this->actionForUri($uri))($request, $route);
    }

    private function actionForUri(string $uri): callable
    {
        if (preg_match('#^/api/admin/dashboard$#', $uri)) {
            return new AdminDashboardApiAction(
                $this->users,
                $this->services,
                $this->orders,
                new \App\Repository\TransactionRepository($this->db),
                $this->logs,
                $this->topups,
            );
        }
        if (preg_match('#^/api/admin/users$#', $uri)) {
            return new AdminUsersApiAction($this->users);
        }
        if (preg_match('#^/api/admin/orders$#', $uri)) {
            return new AdminOrdersApiAction($this->orders, $this->bulk);
        }
        if (preg_match('#^/api/admin/topups$#', $uri)) {
            return new AdminTopupsApiAction($this->topups);
        }
        if (preg_match('#^/api/admin/recharges/\d+$#', $uri)) {
            return new AdminRechargesApiAction($this->topups, $this->topupService);
        }
        if (preg_match('#^/api/admin/withdraws$#', $uri)) {
            return new AdminWithdrawsApiAction($this->withdraws, $this->withdrawService);
        }
        if (preg_match('#^/api/admin/withdraws/\d+$#', $uri)) {
            return new AdminWithdrawsApiAction($this->withdraws, $this->withdrawService);
        }
        if (preg_match('#^/api/admin/staff$#', $uri)) {
            return new AdminStaffApiAction($this->users);
        }
        if (preg_match('#^/api/admin/settings$#', $uri)) {
            return new AdminSettingsApiAction($this->settings);
        }
        if (preg_match('#^/api/admin/logs$#', $uri)) {
            return new AdminLogsApiAction($this->logs);
        }
        if (preg_match('#^/api/admin/notifications(/\d+/retry)?$#', $uri)) {
            return new AdminNotificationsApiAction($this->queue, $this->db);
        }

        throw new \InvalidArgumentException("No action mapped for {$uri}");
    }

    /** @return array{success: bool, message: string, data: mixed, errors: array} */
    private function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    // ---- Envelope / RBAC smoke tests --------------------------------------

    /**
     * @dataProvider rbacProvider
     */
    public function testEveryEndpointRejectsNonAdmin(string $tag, string $role, string $method, string $uri): void
    {
        $identity = $this->makeUser($tag, $role);
        $request = (new ServerRequest($method, $uri))->withAttribute('identity', $identity);
        $route = new CurrentRoute();
        $route->setRouteWithArguments(
            Route::methods(['GET', 'POST'], '/api/admin/dashboard')->name('api-admin-dashboard'),
            [],
        );
        $response = ($this->actionForUri($uri))($request, $route);
        $payload = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('You do not have permission to perform this action.', $payload['message']);
        $this->assertSame([], $payload['errors']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public function rbacProvider(): array
    {
        $tag = $this->suffix;

        // We build users dynamically in each test because `makeUser` mutates state,
        // but the data provider runs at collection time. Return tags instead.
        return [
            'user GET dashboard' => [$tag, 'user', 'GET', '/api/admin/dashboard'],
            'user POST orders' => [$tag, 'user', 'POST', '/api/admin/orders'],
            'user GET staff' => [$tag, 'user', 'GET', '/api/admin/staff'],
            'staff GET settings' => [$tag, 'staff', 'GET', '/api/admin/settings'],
            'user POST withdraws/1' => [$tag, 'user', 'POST', '/api/admin/withdraws/1'],
        ];
    }

    public function testRegularUserIsRejectedFromDashboard(): void
    {
        $user = $this->makeUser('rbac', 'user');

        $response = $this->call($user, 'GET', '/api/admin/dashboard');
        $payload = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }

    public function testAdminCanReadDashboard(): void
    {
        $user = $this->makeUser('dash', 'admin');

        $response = $this->call($user, 'GET', '/api/admin/dashboard');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('userCount', $payload['data']);
        $this->assertArrayHasKey('openOrders', $payload['data']);
        $this->assertArrayHasKey('topupStats', $payload['data']);
    }

    public function testStaffCanReadDashboard(): void
    {
        $user = $this->makeUser('dashstaff', 'staff');

        $response = $this->call($user, 'GET', '/api/admin/dashboard');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
    }

    public function testSuperAdminCanReadDashboard(): void
    {
        $user = $this->makeUser('dashsa', 'superadmin');

        $response = $this->call($user, 'GET', '/api/admin/dashboard');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
    }

    // ---- Users list and mutations ----------------------------------------

    public function testUsersListReturnsPaginationForAdmin(): void
    {
        $admin = $this->makeUser('ulist', 'admin');

        $response = $this->call($admin, 'GET', '/api/admin/users', [], null, ['page' => '1', 'perPage' => '5']);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertArrayHasKey('total', $payload['data']);
        $this->assertArrayHasKey('page', $payload['data']);
        $this->assertSame(1, $payload['data']['page']);
        $this->assertSame(15, $payload['data']['perPage']);
    }

    public function testUsersCreateRequiresFields(): void
    {
        $admin = $this->makeUser('ucreate', 'admin');

        $response = $this->call($admin, 'POST', '/api/admin/users', [], ['do' => 'create']);
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }

    public function testUsersCreateSucceedsWithValidInput(): void
    {
        $admin = $this->makeUser('ucreateok', 'admin');

        $response = $this->call($admin, 'POST', '/api/admin/users', [], [
            'do' => 'create',
            'username' => 'newadminuser_' . $this->suffix,
            'phone' => '01712345679',
            'password' => 'ValidPass123!',
            'role' => 'user',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('user_id', $payload['data']);
        // The API creates the row directly, so the harness never
        // saw it — track it here or every run after the first
        // collides on the hardcoded phone and the test leaks.
        $this->userIds[] = (int) $payload['data']['user_id'];
        $this->assertSame("ইউজার 'newadminuser_{$this->suffix}' তৈরি হয়েছে।", $payload['data']['message']);
    }

    public function testUsersToggleChangesStatus(): void
    {
        $admin = $this->makeUser('utoggle', 'admin');
        $target = $this->makeUser('utarget', 'user');

        $response = $this->call($admin, 'POST', '/api/admin/users', [], [
            'do' => 'toggle',
            'user_id' => (string) $target->id,
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('ইউজার আপডেট হয়েছে।', $payload['data']['message']);

        $row = $this->users->findById($target->id);
        $this->assertSame('disabled', $row['status']);
    }

    public function testUsersRoleChangesRole(): void
    {
        $admin = $this->makeUser('urole', 'admin');
        $target = $this->makeUser('uroletarget', 'user');

        $response = $this->call($admin, 'POST', '/api/admin/users', [], [
            'do' => 'role',
            'user_id' => (string) $target->id,
            'role' => 'staff',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);

        $row = $this->users->findById($target->id);
        $this->assertSame('staff', $row['role']);
    }

    public function testUsersResetReturnsTemporaryPassword(): void
    {
        $admin = $this->makeUser('ureset', 'admin');
        $target = $this->makeUser('uresettarget', 'user');

        $response = $this->call($admin, 'POST', '/api/admin/users', [], [
            'do' => 'reset',
            'user_id' => (string) $target->id,
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('temporary_password', $payload['data']);
        $this->assertNotEmpty($payload['data']['temporary_password']);
    }

    public function testUsersDeleteSoftDeletes(): void
    {
        $admin = $this->makeUser('udel', 'admin');
        $target = $this->makeUser('udeltarget', 'user');

        $response = $this->call($admin, 'POST', '/api/admin/users', [], [
            'do' => 'delete',
            'user_id' => (string) $target->id,
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertNotNull($this->users->findById($target->id, true)['deleted_at']);
    }

    public function testUsersRestoreBringsBack(): void
    {
        $admin = $this->makeUser('urestore', 'admin');
        $target = $this->makeUser('urestoretarget', 'user');
        $this->users->softDelete($target->id);

        $response = $this->call($admin, 'POST', '/api/admin/users', [], [
            'do' => 'restore',
            'user_id' => (string) $target->id,
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);

        $row = $this->users->findById($target->id);
        $this->assertNull($row['deleted_at']);
    }

    // ---- Orders list and bulk --------------------------------------------

    public function testOrdersListReturnsRowsForAdmin(): void
    {
        $admin = $this->makeUser('olist', 'admin');
        $this->makeOrder($admin->id, 'pending');

        $response = $this->call($admin, 'GET', '/api/admin/orders', [], null, ['page' => '1']);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertArrayHasKey('total', $payload['data']);
        $this->assertArrayHasKey('open', $payload['data']);
        $this->assertGreaterThanOrEqual(1, $payload['data']['total']);
    }

    public function testOrdersBulkStatusEnqueues(): void
    {
        $admin = $this->makeUser('obulk', 'admin');
        $orderId = $this->makeOrder($admin->id, 'pending');

        $response = $this->call($admin, 'POST', '/api/admin/orders', [], [
            'do' => 'bulk_status',
            'ids' => [(string) $orderId],
            'status' => 'processing',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('message', $payload['data']);
    }

    // ---- Topups list ----------------------------------------------------

    public function testTopupsListReturnsRowsForAdmin(): void
    {
        $admin = $this->makeUser('tlist', 'admin');
        $this->makeTopup($admin->id, 'pending');

        $response = $this->call($admin, 'GET', '/api/admin/topups', [], null, ['page' => '1']);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertArrayHasKey('total', $payload['data']);
        $this->assertArrayHasKey('page', $payload['data']);
        $this->assertSame(1, $payload['data']['page']);
        $this->assertSame(20, $payload['data']['perPage']);
    }

    // ---- Recharges detail and mutations ----------------------------------

    public function testRechargesDetailReturnsTopup(): void
    {
        $admin = $this->makeUser('rdetail', 'admin');
        $user = $this->makeUser('rtarget', 'user');
        $topupId = $this->makeTopup($user->id, 'pending');

        $response = $this->call($admin, 'GET', '/api/admin/recharges/' . $topupId, ['id' => (string) $topupId]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('topup', $payload['data']);
        $this->assertSame($topupId, (int) $payload['data']['topup']['id']);
    }

    public function testRechargesApproveChangesStatus(): void
    {
        $admin = $this->makeUser('rapprove', 'admin');
        $user = $this->makeUser('rapprovetarget', 'user');
        $topupId = $this->makeTopup($user->id, 'pending');

        $response = $this->call($admin, 'POST', '/api/admin/recharges/' . $topupId, ['id' => (string) $topupId], [
            'do' => 'approve',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('রিচার্জ অনুমোদিত হয়েছে।', $payload['data']['message']);

        $row = $this->topups->findById($topupId);
        $this->assertSame('approved', $row['status']);
    }

    public function testRechargesRejectRecordsNote(): void
    {
        $admin = $this->makeUser('rreject', 'admin');
        $user = $this->makeUser('rrejecttarget', 'user');
        $topupId = $this->makeTopup($user->id, 'pending');

        $response = $this->call($admin, 'POST', '/api/admin/recharges/' . $topupId, ['id' => (string) $topupId], [
            'do' => 'reject',
            'note' => 'Invalid receipt',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('রিচার্জ বাতিল করা হয়েছে।', $payload['data']['message']);

        $row = $this->topups->findById($topupId);
        $this->assertSame('rejected', $row['status']);
    }

    // ---- Staff list (superadmin only) ------------------------------------

    public function testStaffListIsForbiddenToAdmin(): void
    {
        $admin = $this->makeUser('staffrbac', 'admin');

        $response = $this->call($admin, 'GET', '/api/admin/staff');
        $payload = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }

    public function testStaffListReturnsRowsForSuperAdmin(): void
    {
        $sa = $this->makeUser('stafflist', 'superadmin');
        $this->makeUser('staff1', 'staff');
        $this->makeUser('staff2', 'staff');

        $response = $this->call($sa, 'GET', '/api/admin/staff');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertGreaterThanOrEqual(2, $payload['data']['total']);
    }

    // ---- Withdraws list (superadmin only) ---------------------------------

    public function testWithdrawsListIsForbiddenToAdmin(): void
    {
        $admin = $this->makeUser('wdrbac', 'admin');

        $response = $this->call($admin, 'GET', '/api/admin/withdraws');
        $payload = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }

    public function testWithdrawsListReturnsStatsForSuperAdmin(): void
    {
        $sa = $this->makeUser('wdlist', 'superadmin');

        $response = $this->call($sa, 'GET', '/api/admin/withdraws');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertArrayHasKey('stats', $payload['data']);
    }

    // ---- Withdraw decisions (POST /withdraws/{id}, superadmin only) ----

    public function testWithdrawPostIsForbiddenToAdmin(): void
    {
        $admin = $this->makeUser('wdpostrbac', 'admin');
        $owner = $this->makeUser('wdpostowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $response = $this->call($admin, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'approve',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }

    public function testWithdrawApproveDecidesPendingRequest(): void
    {
        $sa = $this->makeUser('wdapprove', 'superadmin');
        $owner = $this->makeUser('wdapproveowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'approve',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('৳250.00 উত্তোলন অনুমোদিত হয়েছে।', $payload['data']['message']);
        $this->assertSame('approved', (string) $payload['data']['withdraw']['status']);

        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('approved', (string) $row['status']);
        $this->assertSame($sa->id, (int) $row['reviewed_by']);
    }

    public function testWithdrawRejectRequiresReason(): void
    {
        $sa = $this->makeUser('wdfnnoreason', 'superadmin');
        $owner = $this->makeUser('wdfnowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'reject',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('বাতিলের একটি কারণ লিখুন।', $payload['message']);

        // A refusal must leave the request open, with the hold in place.
        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('pending', (string) $row['status']);
        $this->assertNull($row['reviewed_by']);
    }

    public function testWithdrawRejectRefundsTheHeldAmount(): void
    {
        $sa = $this->makeUser('wdrefund', 'superadmin');
        $owner = $this->makeUser('wdrefundowner', 'admin');
        // The hold left the balance at request time, so start the owner
        // where AdminWithdrawService::request() would have.
        $this->db->createCommand()->update('{{%user}}', ['balance' => 500.00], ['id' => $owner->id])->execute();
        $withdrawId = $this->makeWithdraw($owner->id);

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'reject',
            'reason' => 'account details did not match',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('উত্তোলন অনুরোধ বাতিল হয়েছে এবং টাকা ব্যালেন্সে ফিরে গেছে।', $payload['data']['message']);

        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('rejected', (string) $row['status']);
        $this->assertSame('account details did not match', (string) $row['reject_reason']);

        // The hold came back through the ledger, not a bare UPDATE.
        $ownerRow = $this->users->findById($owner->id);
        $this->assertEqualsWithDelta(750.00, (float) $ownerRow['balance'], 0.001);

        $refundCount = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%transaction}}'
            . ' WHERE [[admin_id]] = :aid AND [[type]] = :type AND [[direction]] = :dir'
        )->bindValues([
            ':aid' => $owner->id,
            ':type' => \App\Repository\TransactionRepository::TYPE_WITHDRAW_REFUND,
            ':dir' => \App\Repository\TransactionRepository::DIRECTION_CREDIT,
        ])->queryScalar();
        $this->assertSame(1, $refundCount);
    }

    public function testWithdrawSelfApprovalIsRefused(): void
    {
        $sa = $this->makeUser('wdself', 'superadmin');
        $withdrawId = $this->makeWithdraw($sa->id);

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'approve',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('নিজের উত্তোলন অনুরোধ নিজে অনুমোদন করা যায় না।', $payload['message']);

        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('pending', (string) $row['status']);
    }

    public function testWithdrawDoubleApproveIsRefused(): void
    {
        $sa = $this->makeUser('wddouble', 'superadmin');
        $owner = $this->makeUser('wddoubleowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], ['do' => 'approve']);

        // A replayed decision must not record a second reviewer.
        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], ['do' => 'approve']);
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('এই অনুরোধটি আগেই প্রক্রিয়া হয়েছে।', $payload['message']);
    }

    public function testWithdrawUnknownActionIsRefused(): void
    {
        $sa = $this->makeUser('wdunknown', 'superadmin');
        $owner = $this->makeUser('wdunknownowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'explode',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('অজানা অ্যাকশন।', $payload['message']);
    }

    public function testWithdrawMissingIdReturnsNotFound(): void
    {
        $sa = $this->makeUser('wdmissing', 'superadmin');

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/999999999', ['id' => '999999999'], [
            'do' => 'approve',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('উত্তোলন অনুরোধ পাওয়া যায়নি।', $payload['message']);
    }

    public function testWithdrawClaimThenReleaseReturnsToQueue(): void
    {
        $sa = $this->makeUser('wdclaim', 'superadmin');
        $owner = $this->makeUser('wdclaimowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $claimResponse = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'claim',
        ]);
        $claim = $this->decode($claimResponse);
        $this->assertSame(200, $claimResponse->getStatusCode());
        $this->assertTrue($claim['success']);
        $this->assertSame('অনুরোধটি আপনার নামে ধরা হয়েছে।', $claim['data']['message']);
        $this->assertSame('review', (string) $claim['data']['withdraw']['status']);

        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('review', (string) $row['status']);
        $this->assertSame($sa->id, (int) $row['claimed_by']);

        $releaseResponse = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'release',
        ]);
        $release = $this->decode($releaseResponse);
        $this->assertSame(200, $releaseResponse->getStatusCode());
        $this->assertTrue($release['success']);
        $this->assertSame('অনুরোধটি তালিকায় ফিরিয়ে দেওয়া হয়েছে।', $release['data']['message']);

        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('pending', (string) $row['status']);
        $this->assertNull($row['claimed_by']);
    }

    public function testWithdrawClaimHeldByAnotherSuperAdminIsRefused(): void
    {
        $sa1 = $this->makeUser('wdclaim1', 'superadmin');
        $sa2 = $this->makeUser('wdclaim2', 'superadmin');
        $owner = $this->makeUser('wdholdowner', 'admin');
        $withdrawId = $this->makeWithdraw($owner->id);

        $this->call($sa1, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], ['do' => 'claim']);

        $response = $this->call($sa2, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], ['do' => 'claim']);
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('এই অনুরোধটি অন্য একজন সুপারএডমিন ধরে আছেন।', $payload['message']);
    }

    public function testWithdrawRejectAfterClaimStillRefunds(): void
    {
        $sa = $this->makeUser('wdrejectclaim', 'superadmin');
        $owner = $this->makeUser('wdrejectclaimowner', 'admin');
        $this->db->createCommand()->update('{{%user}}', ['balance' => 500.00], ['id' => $owner->id])->execute();
        $withdrawId = $this->makeWithdraw($owner->id);

        $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], ['do' => 'claim']);

        $response = $this->call($sa, 'POST', '/api/admin/withdraws/' . $withdrawId, ['id' => (string) $withdrawId], [
            'do' => 'reject',
            'reason' => 'details unverifiable',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);

        $row = $this->withdraws->findById($withdrawId);
        $this->assertSame('rejected', (string) $row['status']);
        $ownerRow = $this->users->findById($owner->id);
        $this->assertEqualsWithDelta(750.00, (float) $ownerRow['balance'], 0.001);
    }

    // ---- Settings (superadmin only) --------------------------------------

    public function testSettingsReadIsForbiddenToAdmin(): void
    {
        $admin = $this->makeUser('setrbac', 'admin');

        $response = $this->call($admin, 'GET', '/api/admin/settings');
        $payload = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }

    public function testSettingsReadReturnsKeysForSuperAdmin(): void
    {
        $sa = $this->makeUser('setread', 'superadmin');

        $response = $this->call($sa, 'GET', '/api/admin/settings');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('site_tagline', $payload['data']);
        $this->assertArrayHasKey('topup_min_amount', $payload['data']);
    }

    public function testSettingsPostSavesForSuperAdmin(): void
    {
        $sa = $this->makeUser('setpost', 'superadmin');
        $this->snapshotSettings();

        // The endpoint takes the whole form the way the web desk
        // submits it — the bonus fields are required on every save,
        // so a complete body is what a real client sends.
        $response = $this->call($sa, 'POST', '/api/admin/settings', [], [
            'site_tagline' => 'admin-api-tagline',
            'topup_min_amount' => '10',
            'topup_max_amount' => '5000',
            'referrer_bonus_amount' => '50',
            'referee_bonus_amount' => '50',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('সাইট সেটিংস সংরক্ষণ হয়েছে।', $payload['message']);
        $this->assertSame('admin-api-tagline', $payload['data']['site_tagline']);
    }

    // ---- Logs list ------------------------------------------------------

    public function testLogsListReturnsRowsForAdmin(): void
    {
        $admin = $this->makeUser('loglist', 'admin');
        $this->logs->create([
            'user_id' => $admin->id,
            'action' => 'test.admin_api',
            'description' => 'AdminApiTest log',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'metadata' => ['test' => true],
        ]);

        $response = $this->call($admin, 'GET', '/api/admin/logs', [], null, ['page' => '1', 'q' => 'AdminApiTest']);
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertArrayHasKey('total', $payload['data']);
        $this->assertGreaterThanOrEqual(1, $payload['data']['total']);
    }

    // ---- Notifications list and retry -----------------------------------

    public function testAdminNotificationsListReturnsRows(): void
    {
        $admin = $this->makeUser('nlist', 'admin');

        $response = $this->call($admin, 'GET', '/api/admin/notifications');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('rows', $payload['data']);
        $this->assertArrayHasKey('stats', $payload['data']);
    }

    public function testAdminNotificationsRetryRequiresId(): void
    {
        $admin = $this->makeUser('nretry', 'admin');

        // Without {id} in the route, the action cannot find a valid retry target.
        $response = $this->call($admin, 'POST', '/api/admin/notifications');
        $payload = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }
}
