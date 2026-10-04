<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\IdentityRepository;
use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Tests\Support\TestGraph;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Soft delete (deleted_at) + restore for services and users.
 *
 * Every case creates uniquely named throwaway rows and removes them again, so
 * the suite is safe to run against a real database.
 */
final class SoftDeleteTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private ServiceRepository $services;
    private UserRepository $users;

    /** @var int[] */
    private array $serviceIds = [];
    /** @var int[] */
    private array $userIds = [];
    private string $suffix = '';
    private int $categoryId = 0;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->services = new ServiceRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->suffix = 't' . substr(md5(uniqid('', true)), 0, 10);
        $this->categoryId = (int) $this->db
            ->createCommand("SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1")
            ->queryScalar();
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            // activity_log.user_id is a FK, so the child rows go first.
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        foreach ($this->serviceIds as $id) {
            TestGraph::purgeServiceOrders($this->db, $id);
            $this->db->createCommand()->delete('{{%service}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
        $this->serviceIds = [];
    }

    private function makeService(string $tag = 'a'): int
    {
        $id = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Trash Probe ' . $tag . ' ' . $this->suffix,
            'slug' => 'trash-probe-' . $tag . '-' . $this->suffix,
            'description' => 'Disposable soft-delete fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => 10,
            'status' => 'active',
            'sort_order' => 999,
        ]);
        $this->serviceIds[] = $id;
        return $id;
    }

    private function makeUser(string $tag = 'a', string $password = 'Str0ng!pass'): int
    {
        $id = $this->users->create([
            'username' => 'trash_' . $tag . '_' . $this->suffix,
            'phone' => '9' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'trash_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => 0,
        ]);
        $this->userIds[] = $id;
        return $id;
    }

    // ---- Services ---------------------------------------------------------

    public function testTrashedServiceIsHiddenFromPublicLookups(): void
    {
        $id = $this->makeService('hide');
        $slug = (string) $this->services->findServiceById($id)['slug'];

        assertNotNull($this->services->findServiceById($id));
        assertTrue($this->services->softDelete($id));

        assertNull($this->services->findServiceById($id), 'findServiceById must hide a trashed service.');
        assertNull($this->services->findServiceBySlug($slug), 'findServiceBySlug must hide a trashed service.');

        $listed = array_map('intval', array_column($this->services->servicesByCategory($this->categoryId), 'id'));
        assertNotContains($id, $listed, 'Category listing must skip trashed services.');

        $search = array_map('intval', array_column($this->services->searchServices('Trash Probe ' . $this->suffix), 'id'));
        assertNotContains($id, $search, 'Search must skip trashed services.');
    }

    public function testAdminListStillSeesTrashedServiceLast(): void
    {
        $id = $this->makeService('admin');
        assertTrue($this->services->softDelete($id));

        $rows = $this->services->allServicesAdmin();
        $ids = array_map('intval', array_column($rows, 'id'));
        assertContains($id, $ids, 'The admin list must show trashed services so they can be restored.');

        $trashed = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[id]] = :id AND [[deleted_at]] IS NOT NULL')
            ->bindValue(':id', $id)
            ->queryScalar();
        assertSame(1, $trashed, 'The fixture must really be in the trash.');

        // Once a trashed row appears, no live row may follow it.
        $seenTrashed = false;
        foreach ($rows as $row) {
            if ($row['deleted_at'] !== null) {
                $seenTrashed = true;
            } elseif ($seenTrashed) {
                $this->fail('A live service was listed after a trashed one.');
            }
        }
        assertTrue($seenTrashed);
    }

    public function testTrashedServiceIsStillReadableWithDeletedFlag(): void
    {
        $id = $this->makeService('flag');
        $slug = (string) $this->services->findServiceById($id)['slug'];
        assertTrue($this->services->softDelete($id));

        assertNotNull($this->services->findServiceById($id, true));
        assertNotNull($this->services->findServiceBySlug($slug, true), 'The slug stays reserved while trashed.');
    }

    public function testRestoreBringsServiceBack(): void
    {
        $id = $this->makeService('restore');
        $slug = (string) $this->services->findServiceById($id)['slug'];

        assertTrue($this->services->softDelete($id));
        assertFalse($this->services->softDelete($id), 'Deleting an already trashed service is a no-op.');

        assertTrue($this->services->restore($id));
        assertFalse($this->services->restore($id), 'Restoring a live service is a no-op.');

        assertNotNull($this->services->findServiceById($id));
        assertNotNull($this->services->findServiceBySlug($slug));
        assertNull($this->services->findServiceById($id)['deleted_at']);
    }

    public function testRestoreAllEmptiesTheTrash(): void
    {
        $a = $this->makeService('bulk-a');
        $b = $this->makeService('bulk-b');
        assertTrue($this->services->softDelete($a));
        assertTrue($this->services->softDelete($b));

        assertGreaterThanOrEqual(2, $this->services->countTrashed());
        $restored = $this->services->restoreAll();

        assertGreaterThanOrEqual(2, $restored);
        assertSame(0, $this->services->countTrashed(), 'restoreAll must empty the trash.');
    }

    public function testPurgeOnlyRunsOnTrashedServiceAndKeepsHistory(): void
    {
        $id = $this->makeService('purge');

        assertSame(0, $this->services->purgeService($id), 'A live service cannot be purged.');
        assertNotNull($this->services->findServiceById($id));

        assertTrue($this->services->softDelete($id));
        $this->db->createCommand()->insert('{{%service_order}}', [
            'user_id' => $this->makeUser('purge'),
            'service_id' => $id,
            'reference' => 'TR' . strtoupper($this->suffix),
            'amount' => 10,
            'status' => 'completed',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();
        assertSame(1, $this->services->orderCount($id));

        assertSame(1, $this->services->purgeService($id));
        assertNull($this->services->findServiceById($id, true), 'A purged service is gone for good.');

        $detached = $this->db
            ->createCommand('SELECT [[service_id]] FROM {{%service_order}} WHERE [[reference]] = :r')
            ->bindValue(':r', 'TR' . strtoupper($this->suffix))
            ->queryScalar();
        assertNull($detached, 'Purge must detach transactions instead of deleting history.');
    }

    // ---- Users ------------------------------------------------------------

    public function testTrashedUserIsHiddenButKeepsTheirUsernameReserved(): void
    {
        $id = $this->makeUser('hide');
        $username = (string) $this->users->findById($id)['username'];

        assertTrue($this->users->softDelete($id));

        assertNull($this->users->findById($id));
        assertNull($this->users->findByIdentifier($username));
        assertNotNull($this->users->findById($id, true));
        assertNotNull($this->users->findByIdentifier($username, true));
        assertTrue(
            $this->users->usernameExists($username),
            'A trashed user keeps its username so a restore cannot collide.',
        );
    }

    public function testTrashedUserCannotLogIn(): void
    {
        $password = 'Tr0sh!secret';
        $id = $this->makeUser('login', $password);
        $username = (string) $this->users->findById($id)['username'];
        assertTrue($this->users->softDelete($id));

        $identity = new IdentityRepository(
            $this->createMock(SessionInterface::class),
            $this->users,
            new ActivityLogRepository($this->db),
        );

        assertSame(
            'This account has been removed. Contact support.',
            $identity->login($username, $password, '127.0.0.1', 'codecept'),
            'A trashed user must be refused even with the right password.',
        );
    }

    public function testRestoredUserCanLogInAgain(): void
    {
        $password = 'Tr0sh!secret';
        $id = $this->makeUser('relogin', $password);
        $username = (string) $this->users->findById($id)['username'];

        assertTrue($this->users->softDelete($id));
        assertTrue($this->users->restore($id));

        $identity = new IdentityRepository(
            $this->createMock(SessionInterface::class),
            $this->users,
            new ActivityLogRepository($this->db),
        );

        $error = $identity->login($username, $password, '127.0.0.1', 'codecept');

        // The mock session accepts everything, so a successful login returns null.
        assertNull($error, 'A restored user must be able to sign in again.');
    }

    public function testSoftDeleteAndRestoreReportTheStateChange(): void
    {
        $id = $this->makeUser('states');

        assertFalse($this->users->softDelete(99999999), 'An unknown id cannot be trashed.');
        assertFalse($this->users->restore(99999999), 'An unknown id cannot be restored.');

        assertTrue($this->users->softDelete($id), 'A live user can be trashed.');
        assertFalse($this->users->softDelete($id), 'Trashing twice reports no change.');
        assertTrue($this->users->restore($id), 'A trashed user can be restored.');
        assertFalse($this->users->restore($id), 'Restoring a live user reports no change.');
    }

    // ---- Listing ----------------------------------------------------------

    public function testPaginateFiltersMatchTheTrashState(): void
    {
        $liveId = $this->makeUser('live');
        $trashedId = $this->makeUser('trashed');
        $needle = $this->suffix;
        assertTrue($this->users->softDelete($trashedId));

        $ids = static fn (array $result): array => array_map('intval', array_column($result['rows'], 'id'));

        $active = $this->users->paginate(1, 50, $needle, 'id', 'asc', UserRepository::DELETED_EXCLUDE);
        assertContains($liveId, $ids($active));
        assertNotContains($trashedId, $ids($active), 'DELETED_EXCLUDE hides trashed users.');

        $only = $this->users->paginate(1, 50, $needle, 'id', 'asc', UserRepository::DELETED_ONLY);
        assertSame([$trashedId], $ids($only), 'DELETED_ONLY returns only trashed users.');

        $all = $this->users->paginate(1, 50, $needle, 'id', 'asc', UserRepository::DELETED_ALL);
        assertContains($liveId, $ids($all));
        assertContains($trashedId, $ids($all), 'DELETED_ALL returns both.');

        $unknown = $this->users->paginate(1, 50, $needle, 'id', 'asc', 'nonsense');
        assertSame($ids($active), $ids($unknown), 'An unknown filter falls back to hiding trashed users.');
    }

    public function testCountersAgreeWithTheTrashState(): void
    {
        $before = $this->users->countAll();
        $trashedBefore = $this->users->countTrashed();

        $id = $this->makeUser('count');
        assertSame($before + 1, $this->users->countAll(), 'countAll counts live users only.');
        assertSame($trashedBefore, $this->users->countTrashed());

        assertTrue($this->users->softDelete($id));
        assertSame($before, $this->users->countAll(), 'A trashed user leaves countAll.');
        assertSame($trashedBefore + 1, $this->users->countTrashed());

        assertTrue($this->users->restore($id));
        assertSame($before + 1, $this->users->countAll());
        assertSame($trashedBefore, $this->users->countTrashed());
    }

    public function testRestoreAllUsersEmptiesTheTrash(): void
    {
        $a = $this->makeUser('bulk-a');
        $b = $this->makeUser('bulk-b');
        $trashedBefore = $this->users->countTrashed();
        assertTrue($this->users->softDelete($a));
        assertTrue($this->users->softDelete($b));

        $restored = $this->users->restoreAll();

        assertGreaterThanOrEqual(2, $restored);
        assertSame($trashedBefore, $this->users->countTrashed());
        assertNotNull($this->users->findById($a));
        assertNotNull($this->users->findById($b));
    }

    public function testTrashedServiceCountMatchesTheDatabase(): void
    {
        $before = $this->services->countTrashed();
        $id = $this->makeService('count');
        assertSame($before, $this->services->countTrashed(), 'A live service is not counted as trashed.');

        assertTrue($this->services->softDelete($id));
        assertSame($before + 1, $this->services->countTrashed());

        assertTrue($this->services->restore($id));
        assertSame($before, $this->services->countTrashed());
    }
}
