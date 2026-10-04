<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionRepository;
use App\Tests\Support\TestGraph;
use App\Repository\UserRepository;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The order window, end to end: what the admin panel saves, what the gate reads
 * back, and what happens to a user who orders in the wrong hour.
 *
 * The window is a real, clock-dependent gate, so the tests pin it to a range
 * built around the current Dhaka time instead of a fixed 08:00–22:00 — the
 * property under test is "this hour is excluded", not "3 AM is excluded".
 *
 * The `site_setting` rows are real site configuration, so _after() puts back
 * exactly what was there, including deleting rows that did not exist.
 */
final class OrderWindowTest extends \Codeception\Test\Unit
{
    private const KEYS = ['order_window_enabled', 'order_window_start', 'order_window_end'];

    private ConnectionInterface $db;
    private SettingsRepository $settings;
    private UserRepository $users;
    private \Closure $managerFor;

    /** @var int[] */
    private array $userIds = [];

    /** @var array<string, array|null> key => the row as it was, null when absent */
    private array $before = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->settings = new SettingsRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->managerFor = fn (OrderWindowService $window): ServiceManager => new ServiceManager(
            new ServiceRepository($this->db),
            TestGraph::orders($this->db),
            TestGraph::ledger($this->db, $this->users),
            $this->users,
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            new \App\Notification\NotificationManager(
                new NotificationRepository($this->db),
                new \App\Notification\QueueRepository($this->db),
                new \App\Notification\TemplateRenderer($this->db, $this->settings),
                $this->users,
                $this->db,
            ),
            $window,
        );

        foreach (self::KEYS as $key) {
            // queryOne() answers null for "no row" — comparing against false here
            // would make every key look absent and the restore would delete live
            // settings. This is the same trap as elsewhere in this repo.
            $this->before[$key] = $this->db
                ->createCommand('SELECT [[id]], [[setting_value]], [[updated_by]] FROM {{%site_setting}} WHERE [[setting_key]] = :k LIMIT 1')
                ->bindValue(':k', $key)
                ->queryOne();
        }
    }

    protected function _after(): void
    {
        foreach ($this->before as $key => $row) {
            if ($row === null) {
                $this->db->createCommand()->delete('{{%site_setting}}', ['setting_key' => $key])->execute();
            } else {
                $this->db->createCommand()->update('{{%site_setting}}', [
                    'setting_value' => (string) $row['setting_value'],
                    'updated_by' => $row['updated_by'],
                ], ['id' => (int) $row['id']])->execute();
            }
        }

        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];

        $this->db->close();
    }

    public function testWhatTheAdminSavesIsWhatTheGateReads(): void
    {
        [$closedStart, $closedEnd] = $this->awayFromNow();
        [$openStart, $openEnd] = $this->aroundNow();

        $this->saveWindow('1', $closedStart, $closedEnd);
        assertFalse(
            OrderWindowService::fromSettings($this->settings)->isOpen(),
            'a window saved as excluding this hour must read as closed',
        );

        $this->saveWindow('1', $openStart, $openEnd);
        assertTrue(
            OrderWindowService::fromSettings($this->settings)->isOpen(),
            'a window saved as covering this hour must read as open',
        );

        // The toggle has to beat the times, or "open 24/7" is unreachable.
        $this->saveWindow('0', $closedStart, $closedEnd);
        $window = OrderWindowService::fromSettings($this->settings);
        assertFalse($window->isEnabled());
        assertTrue($window->isOpen(), 'turning the gate off must reopen intake whatever the times say');
    }

    public function testAnOrderOutsideTheWindowIsRefusedAndChargesNothing(): void
    {
        [$start, $end] = $this->awayFromNow();
        $manager = ($this->managerFor)(OrderWindowService::between($start, $end));
        $user = $this->makeUser();
        $before = (float) $user->balance;

        $result = $manager->submit($this->stubService(), $user, [], '127.0.0.1', 'codecept');

        assertFalse($result->success, 'an order placed in a closed window must be refused');
        assertStringContainsString('অর্ডার বন্ধ', (string) $result->message, 'the refusal must say why, not just fail');
        assertStringContainsString('প্রতিদিন', (string) $result->message, 'and must name the hours intake reopens');

        assertSame($before, (float) $this->users->findById($user->id)['balance'], 'a refused order must not debit the wallet');
        assertSame('0', (string) $this->countRows('{{%transaction}}', $user->id), 'no request row may be created');
        assertSame('0', (string) $this->countRows('{{%activity_log}}', $user->id), 'and nothing is logged as a submission');
    }

    public function testTheWindowIsCheckedBeforeTheOrderItself(): void
    {
        // Same order, two hours of the day. Outside the window the user is told
        // the shop is shut; inside it they are told the truth about their order.
        // Pinning both proves the gate is the first thing submit() consults and
        // that it is not a by-product of some other refusal.
        [$closedStart, $closedEnd] = $this->awayFromNow();
        $closed = ($this->managerFor)(OrderWindowService::between($closedStart, $closedEnd))
            ->submit($this->stubService(), $this->makeUser(), [], '127.0.0.1', 'codecept');
        assertStringContainsString('অর্ডার বন্ধ', (string) $closed->message);

        [$openStart, $openEnd] = $this->aroundNow();
        $open = ($this->managerFor)(OrderWindowService::between($openStart, $openEnd))
            ->submit($this->stubService(), $this->makeUser(), [], '127.0.0.1', 'codecept');
        assertStringContainsString('not available', (string) $open->message, 'inside the window the order itself is what gets judged');
    }

    public function testTheSiteDefaultIsEnforcedWhenNoRowHasEverBeenSaved(): void
    {
        foreach (self::KEYS as $key) {
            $this->db->createCommand()->delete('{{%site_setting}}', ['setting_key' => $key])->execute();
        }

        $window = OrderWindowService::fromSettings($this->settings);

        // Nothing saved: the repository's own defaults decide, which is what
        // makes "সকাল ৮ থেকে রাত ১০" true on a site the operator has never
        // configured. At 03:00 Dhaka — where the suite is most likely to run —
        // that has to be a closed shop.
        $dhaka = new \DateTimeImmutable('now', new \DateTimeZone(OrderWindowService::TIMEZONE));
        $expected = (int) $dhaka->format('G') >= 8 && (int) $dhaka->format('G') < 22;
        assertSame($expected, $window->isOpen(), 'the unsaved default is 08:00–22:00 Dhaka time');
    }

    /** @return array{0: string, 1: string} a two-hour window covering this moment */
    private function aroundNow(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(OrderWindowService::TIMEZONE));

        return [$now->modify('-1 hour')->format('H:i'), $now->modify('+1 hour')->format('H:i')];
    }

    /** @return array{0: string, 1: string} a one-hour window that misses this moment */
    private function awayFromNow(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(OrderWindowService::TIMEZONE));

        return [$now->modify('+2 hours')->format('H:i'), $now->modify('+3 hours')->format('H:i')];
    }

    private function saveWindow(string $enabled, string $start, string $end): void
    {
        $this->settings->putMany([
            'order_window_enabled' => $enabled,
            'order_window_start' => $start,
            'order_window_end' => $end,
        ], null);
    }

    /**
     * A service row the window check never needs to look at: submit() consults
     * the window first, and a non-mock service_type with no fields is refused
     * immediately after that. Keeping it a bare array avoids a service fixture
     * whose only job would be to be ignored.
     */
    private function stubService(): array
    {
        return ['id' => 0, 'name' => 'Order Window Probe', 'slug' => 'order-window-probe', 'service_type' => 'manual', 'price' => 25.0, 'status' => 'active'];
    }

    private function makeUser(float $balance = 500.0): Identity
    {
        $id = $this->users->create([
            'username' => 'owin_' . substr(md5(uniqid('', true)), 0, 12),
            'phone' => '5' . substr(md5(uniqid('', true)), 0, 9),
            'email' => null,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => $balance,
        ]);
        $this->users->update($id, ['free_searches' => 0]);
        $this->userIds[] = $id;

        return Identity::fromRow((array) $this->users->findById($id));
    }

    private function countRows(string $table, int $userId): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE [[user_id]] = :id')
            ->bindValue(':id', $userId)
            ->queryScalar();
    }
}
