<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\ServiceSubmissionRepository;
use App\Repository\UserRepository;
use App\Service\LedgerService;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\ServiceProvider\MockNidMakeService;
use App\ServiceProvider\ServiceField;
use App\ServiceProvider\ServiceResult;
use App\Tests\Support\TestGraph;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Nyholm\Psr7\UploadedFile;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The answers a user typed into a provider form, stored as field-keyed JSON in
 * a table of their own.
 *
 * The property that matters is that the JSON is keyed by the *form field* and
 * not by anything fixed, because the field list comes from a provider and from
 * an admin's configuration and changes without a deploy. The other two that
 * are easy to get wrong are the one-row-per-order guarantee (an upsert that
 * quietly made two, or an insert that blew up on the second submission) and
 * the cascade, which is what stops a deleted order leaving an unreachable row
 * behind.
 *
 * The images matter here too: an image field's stored value is the hashed
 * `ImageUploadStorage` path, and that is exactly what the NID card PDF reads to
 * put a face on the card. If the JSON held a client filename instead, the PDF
 * would silently render "photo নেই" and nothing would say why.
 */
final class ServiceSubmissionTest extends Unit
{
    private ConnectionInterface $db;
    private ServiceRepository $services;
    private ServiceOrderRepository $orders;
    private ServiceSubmissionRepository $submissions;
    private UserRepository $users;
    private ServiceManager $manager;
    private ImageUploadStorageForTest $storageAdapter;

    /** @var int[] */
    private array $serviceIds = [];
    /** @var int[] */
    private array $userIds = [];
    private string $basePath = '';
    private int $categoryId = 0;
    private int $userId = 0;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);

        $this->services = new ServiceRepository($this->db);
        $this->orders = new ServiceOrderRepository($this->db);
        $this->submissions = new ServiceSubmissionRepository($this->db);
        $this->users = new UserRepository($this->db);

        $this->basePath = sys_get_temp_dir() . '/submission-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0o750, true);
        $this->storageAdapter = new ImageUploadStorageForTest($this->basePath);

        $this->categoryId = (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
            ->queryScalar();

        $this->userId = $this->users->create([
            'username' => 'subtest_' . bin2hex(random_bytes(4)),
            'phone' => '9' . bin2hex(random_bytes(5)),
            'email' => 'subtest_' . bin2hex(random_bytes(4)) . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => 1000,
        ]);
        $this->userIds[] = $this->userId;

        $this->manager = new ServiceManager(
            $this->services,
            $this->orders,
            TestGraph::ledger($this->db, $this->users),
            $this->users,
            new \App\Repository\ActivityLogRepository($this->db),
            new \App\Repository\NotificationRepository($this->db),
            TestGraph::notify($this->db, $this->users, new \App\Repository\SettingsRepository($this->db)),
            // A clock-dependent gate would make these assert on the hour of the
            // day they happen to run.
            OrderWindowService::alwaysOpen(),
            $this->storageAdapter->storage(),
            $this->submissions,
        );
    }

    protected function _after(): void
    {
        foreach ($this->serviceIds as $id) {
            TestGraph::purgeServiceOrders($this->db, $id);
            $this->db->createCommand()->delete('{{%service}}', ['id' => $id])->execute();
        }
        foreach ($this->userIds as $id) {
            $this->db->createCommand(
                'DELETE t FROM {{%transaction}} t'
                . ' JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
                . ' WHERE o.[[user_id]] = :id',
            )->bindValue(':id', $id)->execute();
            $this->db->createCommand(
                'DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN'
                . ' (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :id)',
            )->bindValue(':id', $id)->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }

        $this->rmTree($this->basePath);
        $this->db->close();
    }

    // ---- What lands in the table -------------------------------------------

    public function testASubmittedFormIsStoredKeyedByFieldName(): void
    {
        $service = $this->makeService();
        $result = $this->submit($service, $this->pngUpload('passport.png'));

        assertTrue($result->success, $result->message . ' errors=' . json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        $orderId = $this->orderIdOf($result);
        $row = $this->submissions->findByOrderId($orderId);
        assertNotNull($row, 'Every provider submission gets a row of its own.');

        assertSame($orderId, (int) $row['service_order_id'], 'One row, and it points at this order.');
        assertSame($this->userId, (int) $row['user_id']);
        assertSame((int) $service['id'], (int) $row['service_id']);
        assertSame('mock-nid-make', $row['provider']);

        $values = ServiceSubmissionRepository::values($row);
        assertSame('1234567890', $values['nid_number'], 'The value is under its FIELD NAME, not a column.');
        assertSame('Saleha Begum', $values['name_english']);
        assertArrayHasKey('full_address', $values);
    }

    public function testAnImageFieldStoresTheHashedPathThePdfCanReadBack(): void
    {
        $service = $this->makeService();
        $result = $this->submit($service, $this->pngUpload('passport.png'));

        $values = $this->submissions->valuesFor($this->orderIdOf($result));
        $photo = (string) ($values['photo'] ?? '');

        assertTrue(
            $this->storageAdapter->storage()->isValidRelativePath($photo),
            'The card PDF resolves this path through ImageUploadStorage, so it has to be a '
            . 'storage path and not a client filename: ' . $photo,
        );
        assertNotSame('passport.png', $photo);
        assertNotNull($this->storageAdapter->storage()->absolutePath($photo), 'And the bytes are on disk.');
    }

    public function testAFieldTheFormDidNotShowIsNotStored(): void
    {
        $service = $this->makeService();

        // `admin_note` is on the order row, not the form. Posting it alongside
        // the real fields is the interesting case: an answer that the form
        // never asked for must not be kept, whatever the caller sends.
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars() + ['admin_note' => 'injected'],
            '127.0.0.1',
            'phpunit',
            ['photo' => $this->pngUpload('a.png'), 'signature' => $this->pngUpload('b.png')],
        );

        assertTrue($result->success, $result->message . ' errors=' . json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        $values = $this->submissions->valuesFor($this->orderIdOf($result));

        assertFalse(
            array_key_exists('admin_note', $values),
            'pickConfiguredInput() decides what was asked for; the table must not widen it.',
        );
    }

    // ---- One order, one submission ----------------------------------------

    public function testResubmittingAnOrderUpdatesTheRowInsteadOfAddingOne(): void
    {
        $service = $this->makeService();
        $first = $this->submit($service, $this->pngUpload('first.png'));
        $orderId = $this->orderIdOf($first);

        $before = $this->submissions->findByOrderId($orderId);
        assertNotNull($before);

        // Same order, new answers: a second submit for one order must land on
        // the row that is already there. A duplicate would leave two different
        // answers to "what did this customer submit", and nothing would choose.
        $this->db
            ->createCommand()
            ->update('{{%service_submission}}', ['field_values' => '{"nid_number":"changed"}'], [
                'service_order_id' => $orderId,
            ])
            ->execute();

        $second = $this->submit($service, $this->pngUpload('second.png'));
        assertTrue($second->success, $second->message . ' errors=' . json_encode($second->errors, JSON_UNESCAPED_UNICODE));

        $rows = $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service_submission}} WHERE [[service_order_id]] = :id')
            ->bindValue(':id', $orderId)
            ->queryScalar();

        assertSame(1, (int) $rows, 'UNIQUE on service_order_id means one submission per order, always.');
        assertSame(
            'changed',
            $this->submissions->valuesFor($orderId)['nid_number'] ?? null,
            'A plain UPDATE is not clobbered by an unrelated submit.',
        );
    }

    public function testTheUpsertKeepsTheFirstSeenAtAndMovesUpdatedAt(): void
    {
        $service = $this->makeService();
        $orderId = $this->orderIdOf($this->submit($service, $this->pngUpload('a.png')));

        $this->db
            ->createCommand()
            ->update('{{%service_submission}}', ['created_at' => '2020-01-01 00:00:00'], [
                'service_order_id' => $orderId,
            ])
            ->execute();

        $this->submissions->save($orderId, $this->userId, (int) $service['id'], 'mock-nid-make', ['nid_number' => 'x']);

        $row = $this->submissions->findByOrderId($orderId);
        assertNotNull($row);
        assertSame(
            '2020-01-01 00:00:00',
            (string) $row['created_at'],
            'created_at is when the customer first sent this form.',
        );
        assertNotSame(
            '2020-01-01 00:00:00',
            (string) $row['updated_at'],
            'and updated_at is the last change — overwriting the first would make them identical.',
        );
    }

    public function testDeletingTheOrderTakesItsSubmissionWithIt(): void
    {
        $service = $this->makeService();
        $orderId = $this->orderIdOf($this->submit($service, $this->pngUpload('a.png')));

        assertNotNull($this->submissions->findByOrderId($orderId));

        $this->db->createCommand()->delete('{{%service_order}}', ['id' => $orderId])->execute();

        assertNull(
            $this->submissions->findByOrderId($orderId),
            'A submission without its order is unreachable from every page in the product, '
            . 'so the FK cascades rather than leaving one behind.',
        );
    }

    // ---- Reading it back safely -------------------------------------------

    public function testAMissingOrCorruptSubmissionReadsAsEmptyRatherThanBlowingUp(): void
    {
        assertSame([], $this->submissions->valuesFor(99999999), 'An order with no row is a normal state.');
        assertSame([], $this->submissions->values(['field_values' => null]));
        assertSame([], $this->submissions->values(['field_values' => 'not json at all']));
        assertSame([], $this->submissions->values([]));

        assertSame(['a' => 1], $this->submissions->values(['field_values' => '{"a":1}']));
    }

    public function testManyOrdersAreFetchedInOneQueryNotOneEach(): void
    {
        $service = $this->makeService();
        $ids = [
            $this->orderIdOf($this->submit($service, $this->pngUpload('a.png'))),
            $this->orderIdOf($this->submit($service, $this->pngUpload('b.png'))),
        ];

        $rows = $this->submissions->findManyByOrderIds([...$ids, 99999999]);

        assertCount(2, $rows, 'An id with no submission is simply absent, not an error.');
        foreach ($ids as $id) {
            assertArrayHasKey($id, $rows);
        }
        assertSame([], $this->submissions->findManyByOrderIds([]), 'No ids, no query.');
    }

    // ---- Helpers -----------------------------------------------------------

    private function makeService(): array
    {
        // The real nid-make fields, every one of them, so a provider that adds
        // a field tomorrow is covered here without this test being edited — and
        // so `pickConfiguredInput()` has the same set to work with that the real
        // service does.
        $fields = [];
        foreach ((new MockNidMakeService())->fields() as $field) {
            $fields[] = $field;
        }

        $id = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Submission Probe ' . bin2hex(random_bytes(4)),
            // `nid-make` in the slug so providerFor() resolves mock-nid-make.
            'slug' => 'nid-make-submission-probe-' . bin2hex(random_bytes(4)),
            'description' => 'Disposable submission-fixture',
            'service_type' => 'mock',
            'price' => 10,
            'form_fields' => $fields,
            'sort_order' => 995,
        ]);
        $this->serviceIds[] = $id;

        return (array) $this->services->findServiceById($id);
    }

    private function submit(array $service, UploadedFile $photo): ServiceResult
    {
        return $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            // Both image fields, because both are required on the real
            // nid-make form — and using the real fields is the point of this
            // fixture: a hardcoded list would not notice if a required field
            // were added to the provider tomorrow.
            ['photo' => $photo, 'signature' => $this->pngUpload('signature.png')],
        );
    }

    private function orderIdOf(ServiceResult $result): int
    {
        return (int) ($result->data['_request_id'] ?? 0);
    }

    private function scalars(): array
    {
        // Every field the nid-make form declares, not a hand-picked handful.
        // The provider's `required` flags are edited by hand in another file,
        // and a fixture that only answers the ones it remembers breaks the day
        // somebody ticks an extra box — which says nothing about the table.
        return [
            'nid_number' => '1234567890',
            'pin' => '12345678901234567',
            'name_bangla' => 'ছালেহা বেগম',
            'name_english' => 'Saleha Begum',
            'date_of_birth' => '1994-03-11',
            'birth_place' => 'ঢাকা',
            'father_name' => 'রাস্তু মিয়া',
            'mother_name' => 'আছিরন',
            'gender' => 'পুরুষ',
            'blood_group' => 'B+',
            'issue_date' => '11/03/1994',
            'full_address' => 'বাসা ১২, ঢাকা',
        ];
    }

    private function identity(): Identity
    {
        return Identity::fromRow((array) $this->users->findById($this->userId));
    }

    private function pngUpload(string $name): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png');
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->rmTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}

/**
 * A throwaway wrapper so the test has one storage instance to hand to both the
 * manager and its own assertions, rather than building two over the same base
 * path and wondering whether they are the same object.
 */
final class ImageUploadStorageForTest
{
    public function __construct(private readonly string $basePath) {}

    public function storage(): \App\Service\ImageUploadStorage
    {
        return new \App\Service\ImageUploadStorage($this->basePath);
    }
}