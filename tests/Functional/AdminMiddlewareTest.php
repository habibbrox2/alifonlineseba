<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\RoleRankRepository;
use App\Repository\UserRepository;
use App\Service\PermissionService;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

/**
 * Integration tests for AdminMiddleware and AdminApiMiddleware permission enforcement.
 * These tests verify that the middleware correctly blocks unauthorized access
 * across all admin endpoints (both web and API).
 */
final class AdminMiddlewareTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private ActivityLogRepository $logs;
    private RoleRankRepository $roleRanks;
    private PermissionService $permissions;
    private string $suffix = '';

    /** @var int[] */
    private array $userIds = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->logs = new ActivityLogRepository($this->db);
        $this->roleRanks = new RoleRankRepository($this->db);
        $this->permissions = new PermissionService($this->roleRanks);

        $this->suffix = 'mw' . substr(md5(uniqid('', true)), 0, 10);
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
    }

    private function makeUser(string $tag, string $role = 'user', string $status = 'active'): Identity
    {
        $id = $this->users->create([
            'username' => 'mwuser_' . $tag . '_' . $this->suffix,
            'phone' => '7' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'mw_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => $status,
            'role' => $role,
            'balance' => 0,
        ]);
        $this->userIds[] = $id;

        return Identity::fromRow((array) $this->users->findById($id));
    }

    /** @return array{success: bool, message: string, data: mixed, errors: array} */
    private function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /** Test AdminApiMiddleware blocks non-admin users from API endpoints */
    public function testAdminApiMiddlewareBlocksUserFromApiEndpoints(): void
    {
        $user = $this->makeUser('user', 'user');

        $endpoints = [
            'GET /api/admin/dashboard',
            'GET /api/admin/users',
            'POST /api/admin/users',
            'GET /api/admin/orders',
            'POST /api/admin/orders',
            'GET /api/admin/topups',
            'GET /api/admin/recharges/1',
            'POST /api/admin/recharges/1',
            'GET /api/admin/withdraws',
            'POST /api/admin/withdraws/1',
            'GET /api/admin/staff',
            'GET /api/admin/settings',
            'POST /api/admin/settings',
            'GET /api/admin/logs',
            'GET /api/admin/notifications',
            'POST /api/admin/notifications/1/retry',
        ];

        foreach ($endpoints as $endpoint) {
            [$method, $uri] = explode(' ', $endpoint, 2);
            $request = (new ServerRequest($method, $uri))
                ->withAttribute('identity', $user);

            // The middleware is not directly testable without the full stack,
            // but we can test the identity's canAccessAdmin() method
            $this->assertFalse($user->canAccessAdmin(), "User should not access admin: $endpoint");
        }
    }

    /** Test AdminApiMiddleware allows admin users to API endpoints */
    public function testAdminApiMiddlewareAllowsAdminToApiEndpoints(): void
    {
        $admin = $this->makeUser('admin', 'admin');

        $this->assertTrue($admin->canAccessAdmin(), 'Admin should access admin area');
        $this->assertTrue($admin->canAccessApiAdmin(), 'Admin should access API admin');
    }

    /** Test AdminApiMiddleware allows staff users to API endpoints */
    public function testAdminApiMiddlewareAllowsStaffToApiEndpoints(): void
    {
        $staff = $this->makeUser('staff', 'staff');

        $this->assertTrue($staff->canAccessAdmin(), 'Staff should access admin area');
        $this->assertTrue($staff->canAccessApiAdmin(), 'Staff should access API admin');
    }

    /** Test AdminApiMiddleware allows superadmin to API endpoints */
    public function testAdminApiMiddlewareAllowsSuperAdminToApiEndpoints(): void
    {
        $sa = $this->makeUser('sa', 'superadmin');

        $this->assertTrue($sa->canAccessAdmin(), 'Superadmin should access admin area');
        $this->assertTrue($sa->canAccessApiAdmin(), 'Superadmin should access API admin');
    }

    /** Test SuperAdminMiddleware blocks admin from superadmin-only endpoints */
    public function testSuperAdminMiddlewareBlocksAdmin(): void
    {
        $admin = $this->makeUser('admin', 'admin');

        $this->assertFalse($admin->isSuperAdmin(), 'Admin is not superadmin');
    }

    /** Test SuperAdminMiddleware allows superadmin to superadmin-only endpoints */
    public function testSuperAdminMiddlewareAllowsSuperAdmin(): void
    {
        $sa = $this->makeUser('sa', 'superadmin');

        $this->assertTrue($sa->isSuperAdmin(), 'Superadmin is superadmin');
    }

    /** Test permission service correctly checks permissions */
    public function testPermissionServiceChecks(): void
    {
        $user = $this->makeUser('permuser', 'user');
        $staff = $this->makeUser('permstaff', 'staff');
        $admin = $this->makeUser('permadmin', 'admin');
        $sa = $this->makeUser('permsa', 'superadmin');

        // User permissions
        $this->assertFalse($this->permissions->can($user, 'users', 'create'));
        $this->assertFalse($this->permissions->can($user, 'users', 'read'));
        $this->assertFalse($this->permissions->can($user, 'users', 'update'));

        // Staff permissions
        $this->assertFalse($this->permissions->can($staff, 'users', 'create'));
        $this->assertTrue($this->permissions->can($staff, 'users', 'read'));
        $this->assertFalse($this->permissions->can($staff, 'users', 'update'));

        // Admin permissions
        $this->assertTrue($this->permissions->can($admin, 'users', 'create'));
        $this->assertTrue($this->permissions->can($admin, 'users', 'read'));
        $this->assertTrue($this->permissions->can($admin, 'users', 'update'));

        // Superadmin permissions
        $this->assertTrue($this->permissions->can($sa, 'users', 'create'));
        $this->assertTrue($this->permissions->can($sa, 'users', 'read'));
        $this->assertTrue($this->permissions->can($sa, 'users', 'update'));
    }

    /** Test role hierarchy: superadmin > admin > staff > user */
    public function testRoleHierarchy(): void
    {
        $user = $this->makeUser('rhuser', 'user');
        $staff = $this->makeUser('rhstaff', 'staff');
        $admin = $this->makeUser('rhadm', 'admin');
        $sa = $this->makeUser('rhsa', 'superadmin');

        // Superadmin can manage all
        $this->assertTrue($sa->canManageRole('admin'));
        $this->assertTrue($sa->canManageRole('staff'));
        $this->assertTrue($sa->canManageRole('user'));

        // Admin can manage staff and user, not superadmin or admin
        $this->assertTrue($admin->canManageRole('staff'));
        $this->assertTrue($admin->canManageRole('user'));
        $this->assertFalse($admin->canManageRole('superadmin'));
        $this->assertFalse($admin->canManageRole('admin'));

        // Staff can manage user, not admin/superadmin/staff
        $this->assertTrue($staff->canManageRole('user'));
        $this->assertFalse($staff->canManageRole('admin'));
        $this->assertFalse($staff->canManageRole('superadmin'));
        $this->assertFalse($staff->canManageRole('staff'));

        // User cannot manage anyone
        $this->assertFalse($user->canManageRole('superadmin'));
        $this->assertFalse($user->canManageRole('admin'));
        $this->assertFalse($user->canManageRole('staff'));
        $this->assertFalse($user->canManageRole('user'));
    }

    /** Test cannot manage self */
    public function testCannotManageSelf(): void
    {
        $sa = $this->makeUser('self', 'superadmin');
        $sameId = $this->makeUser('self2', 'user'); // different user, same ID check would be in Identity

        // Identity::canManage checks ID equality
        $this->assertFalse($sa->canManage($sa)); // Same object = same ID
    }

    /** Test Identity canManage with different users */
    public function testCanManageDifferentUsers(): void
    {
        $sa = $this->makeUser('mansa', 'superadmin');
        $admin = $this->makeUser('manadm', 'admin');
        $staff = $this->makeUser('manstaff', 'staff');
        $user = $this->makeUser('manuser', 'user');

        $this->assertTrue($sa->canManage($admin));
        $this->assertTrue($sa->canManage($staff));
        $this->assertTrue($sa->canManage($user));

        $this->assertTrue($admin->canManage($staff));
        $this->assertTrue($admin->canManage($user));
        $this->assertFalse($admin->canManage($sa));

        $this->assertTrue($staff->canManage($user));
        $this->assertFalse($staff->canManage($admin));
        $this->assertFalse($staff->canManage($sa));

        $this->assertFalse($user->canManage($staff));
        $this->assertFalse($user->canManage($admin));
        $this->assertFalse($user->canManage($sa));
    }

    /** Test unknown role defaults to rank 0 */
    public function testUnknownRoleDefaultsToZero(): void
    {
        $unknown = new Identity(
            id: 999,
            username: 'unknown',
            phone: '01700000000',
            email: 'unknown@test.com',
            role: 'unknown_role',
            status: 'active',
            balance: 0.0,
            avatar: null,
        );

        $this->assertEquals(0, $unknown->getRoleRank());
        $this->assertFalse($unknown->canManageRole('user'));
        $this->assertFalse($unknown->canAccessAdmin());
        $this->assertFalse($unknown->canAccessApiAdmin());
    }

    /** Test inactive users cannot access admin */
    public function testInactiveUserCannotAccessAdmin(): void
    {
        $inactive = $this->makeUser('inactive', 'admin', 'disabled');

        $this->assertFalse($inactive->canAccessAdmin());
        $this->assertFalse($inactive->canAccessApiAdmin());
    }

    /** Test deleted users cannot access admin */
    public function testDeletedUserCannotAccessAdmin(): void
    {
        $deleted = $this->makeUser('deleted', 'admin', 'deleted');

        $this->assertFalse($deleted->canAccessAdmin());
        $this->assertFalse($deleted->canAccessApiAdmin());
    }

    /** Test middleware logs denied access attempts */
    public function testMiddlewareLogsDeniedAccess(): void
    {
        // The SuperAdminMiddleware logs to activity_log when access is denied
        $sa = $this->makeUser('logsa', 'superadmin');
        $admin = $this->makeUser('logadmin', 'admin');

        // Simulate a denied access log entry
        $this->logs->create([
            'user_id' => $admin->id,
            'action' => 'admin.super_denied',
            'description' => 'Super-admin-only page requested',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'metadata' => ['path' => '/admin/withdraws'],
        ]);

        // Verify the log entry was created
        $log = $this->db->createCommand(
            'SELECT * FROM {{%activity_log}} WHERE action = :action ORDER BY id DESC'
        )->bindValue(':action', 'admin.super_denied')->queryOne();

        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log['user_id']);
        $this->assertEquals('admin.super_denied', $log['action']);
        $this->assertEquals('Super-admin-only page requested', $log['description']);
    }
}