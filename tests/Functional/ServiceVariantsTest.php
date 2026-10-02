<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\ServiceManager;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Reference-parity ordering features: per-service purchasable variants
 * (original vs smart-card style pricing), the per-service rules block and the
 * per-service order history under the form.
 *
 * Throwaway rows only — removed again in _after().
 */
final class ServiceVariantsTest extends \Codeception\Test\Unit
{
    private const PRICE = 100.0;

    private ConnectionInterface $db;
    private ServiceRepository $services;
    private UserRepository $users;
    private ServiceManager $manager;

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
        $this->services = new ServiceRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->manager = new ServiceManager(
            $this->services,
            new TransactionRepository($this->db),
            $this->users,
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            new \App\Notification\NotificationManager(
                new NotificationRepository($this->db),
                new \App\Notification\QueueRepository($this->db),
                new \App\Notification\TemplateRenderer(
                    $this->db,
                    new \App\Repository\SettingsRepository($this->db),
                ),
                $this->users,
                $this->db,
            ),
        );

        // Fan-out rows land on users outside our own list (the real admin gets
        // every admin broadcast), so cleanup also sweeps by this time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
        $this->suffix = 'v' . substr(md5(uniqid('', true)), 0, 10);
        $categoryId = (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
            ->queryScalar();
        $this->serviceId = $this->services->createService([
            'category_id' => $categoryId,
            'name' => 'Variants Probe ' . $this->suffix,
            'slug' => 'variants-probe-' . $this->suffix,
            'description' => 'Disposable variant fixture ' . $this->suffix,
            'service_type' => 'mock',
            'price' => self::PRICE,
            'variants' => [
                ['label' => 'অরিজিনাল কপি', 'price' => 45],
                ['label' => 'স্মার্ট কার্ড কপি', 'price' => 60],
            ],
            'rules' => "প্রথম নিয়ম।\nদ্বিতীয় নিয়ম।",
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
            ->createCommand('DELETE FROM {{%notification_queue}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();

        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%transaction}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->db->createCommand()->delete('{{%transaction}}', ['service_id' => $this->serviceId])->execute();
        $this->db->createCommand()->delete('{{%service}}', ['id' => $this->serviceId])->execute();
        $this->userIds = [];
        // In-app fan-out rows that landed on users outside our list.
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
    }

    private function makeUser(float $balance = 500.0): Identity
    {
        $id = $this->users->create([
            'username' => 'var_' . $this->suffix . '_' . count($this->userIds),
            'phone' => '6' . substr(md5(uniqid('', true)), 0, 9),
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

    private function service(): array
    {
        return (array) $this->services->findServiceById($this->serviceId);
    }

    public function testVariantsParseAndRoundTrip(): void
    {
        $variants = ServiceManager::variantsFor($this->service());
        assertSame(
            [['label' => 'অরিজিনাল কপি', 'price' => 45.0], ['label' => 'স্মার্ট কার্ড কপি', 'price' => 60.0]],
            $variants,
            'Stored JSON must round-trip into sanitised rows.',
        );

        // A service without variants hides the selector entirely.
        $plain = ['variants' => null, 'price' => 12.0];
        assertSame([], ServiceManager::variantsFor($plain));
        $junk = ['variants' => 'not-json', 'price' => 12.0];
        assertSame([], ServiceManager::variantsFor($junk));
        $blankLabel = ['variants' => [['label' => '  ', 'price' => 5]], 'price' => 12.0];
        assertSame([], ServiceManager::variantsFor($blankLabel));
        $negative = ['variants' => [['label' => 'x', 'price' => -3]], 'price' => 12.0];
        assertSame([], ServiceManager::variantsFor($negative));
    }

    public function testVariantSelectionDrivesTheChargedPrice(): void
    {
        $user = $this->makeUser();
        $service = $this->service();

        // No variant submitted → base price.
        $result = $this->manager->submit($service, $user, ['nid_number' => '1990123456789', 'date_of_birth' => '1990-05-04'], '127.0.0.1', 'codecept');
        assertTrue($result->success, (string) $result->message);
        $tx = (array) $this->db
            ->createCommand('SELECT [[amount]], [[metadata]] FROM {{%transaction}} WHERE [[user_id]] = :u ORDER BY [[id]] DESC LIMIT 1')
            ->bindValue(':u', $user->id)
            ->queryOne();
        assertSame(self::PRICE, (float) $tx['amount'], 'Without a variant the base price applies.');

        // v1 → স্মার্ট কার্ড কপি at ৳60.
        $result = $this->manager->submit($service, $user, ['nid_number' => '1990123456789', 'date_of_birth' => '1990-05-04', 'variant' => 'v1'], '127.0.0.1', 'codecept');
        assertTrue($result->success, (string) $result->message);
        $tx = (array) $this->db
            ->createCommand('SELECT [[amount]], [[metadata]] FROM {{%transaction}} WHERE [[user_id]] = :u ORDER BY [[id]] DESC LIMIT 1')
            ->bindValue(':u', $user->id)
            ->queryOne();
        assertSame(60.0, (float) $tx['amount'], 'The variant price is what gets charged.');
        $meta = TransactionRepository::metadata($tx);
        assertSame('স্মার্ট কার্ড কপি', $meta['variant'], 'The chosen variant label is recorded.');

        // An unknown variant id must not charge anything at all.
        $balanceBefore = (float) ((array) $this->users->findById($user->id))['balance'];
        $result = $this->manager->submit($service, $user, ['nid_number' => '1990123456789', 'date_of_birth' => '1990-05-04', 'variant' => 'v99'], '127.0.0.1', 'codecept');
        assertTrue($result->success, 'An unknown variant falls back to base pricing.');
        assertSame(
            $balanceBefore - self::PRICE,
            (float) ((array) $this->users->findById($user->id))['balance'],
            'Fallback charges the base price, nothing else.',
        );
    }

    public function testRulesSplitIntoLines(): void
    {
        $rules = ServiceManager::rulesFor($this->service());
        assertSame(['প্রথম নিয়ম।', 'দ্বিতীয় নিয়ম।'], $rules);

        assertSame([], ServiceManager::rulesFor(['rules' => null]));
        assertSame([], ServiceManager::rulesFor(['rules' => "   \n  \n"]));
        assertSame(['একটাই নিয়ম'], ServiceManager::rulesFor(['rules' => '  একটাই নিয়ম  ']));
    }

    public function testPerServiceHistoryListsOnlyThatService(): void
    {
        $user = $this->makeUser();
        $service = $this->service();
        $this->manager->submit($service, $user, ['nid_number' => '1990123456789', 'date_of_birth' => '1990-05-04'], '127.0.0.1', 'codecept');

        $history = (new TransactionRepository($this->db))->forUserByService($user->id, $this->serviceId);
        assertSame(1, $history['total'], 'Only this service\'s own orders are listed.');
        assertSame(self::PRICE, (float) $history['rows'][0]['amount'], 'Base price, since no variant was sent.');

        // A second service's orders must not leak into the first's table.
        $categoryId = (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
            ->queryScalar();
        $otherId = $this->services->createService([
            'category_id' => $categoryId,
            'name' => 'Other Probe ' . $this->suffix,
            'slug' => 'other-probe-' . $this->suffix,
            'service_type' => 'mock',
            'price' => 1.0,
            'status' => 'active',
        ]);
        try {
            $this->manager->submit(
                (array) $this->services->findServiceById($otherId),
                $user,
                ['nid_number' => '1990123456789', 'date_of_birth' => '1990-05-04'],
                '127.0.0.1',
                'codecept',
            );
            $history = (new TransactionRepository($this->db))->forUserByService($user->id, $this->serviceId);
            assertSame(1, $history['total'], 'The per-service table stays scoped to its own service.');
            assertSame(2, (new TransactionRepository($this->db))->forUser($user->id, 1, 10)['total']);
        } finally {
            $this->db->createCommand()->delete('{{%transaction}}', ['service_id' => $otherId])->execute();
            $this->db->createCommand()->delete('{{%service}}', ['id' => $otherId])->execute();
        }
    }
}
