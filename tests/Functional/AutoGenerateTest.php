<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\ServiceSubmissionRepository;
use App\Repository\UserRepository;
use App\Service\DeliverableStorage;
use App\Service\ImageUploadStorage;
use App\Service\NidMakePdfRenderer;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\Service\StatusPresenter;
use App\ServiceProvider\MockNidMakeService;
use App\ServiceProvider\ServiceResult;
use App\Tests\Support\TestGraph;
use Codeception\Test\Unit;
use Nyholm\Psr7\UploadedFile;
use Twig\Environment as TwigEnvironment;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * nid-make is the one service in the catalogue that builds its own output.
 *
 * Everything else is a question about a third party, so a human has to answer
 * it before the customer is charged. nid-make is the customer's own form typeset
 * onto a PDF — there is nothing for an operator to judge — so the order must
 * never land in the admin queue and must come back finished on its own.
 *
 * The three things worth pinning down here are that the order is born
 * `processing` with nothing for an operator to do, that it stays there long
 * enough for the customer to actually see it, and that the file it turns into
 * is a real PDF with the customer's own photo in it. The last one is the
 * interesting failure mode: a card that renders but comes out blank looks
 * exactly like a success to every status check in the product.
 */
final class AutoGenerateTest extends Unit
{
    private ConnectionInterface $db;
    private ServiceRepository $services;
    private ServiceOrderRepository $orders;
    private UserRepository $users;
    private ServiceManager $manager;
    private ServiceSubmissionRepository $submissions;
    private ImageUploadStorage $images;
    private DeliverableStorage $deliverables;
    private NidMakePdfRenderer $cards;

    /** @var int[] */
    private array $serviceIds = [];
    /** @var int[] */
    private array $userIds = [];
    private string $imagePath = '';
    private string $deliverablePath = '';
    private int $categoryId = 0;
    private int $userId = 0;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            array_merge(
                require codecept_root_dir() . 'config/common/di/db.php',
                require codecept_root_dir() . 'config/common/di/twig.php',
            ),
        ));
        $this->db = $container->get(ConnectionInterface::class);

        $this->services = new ServiceRepository($this->db);
        $this->orders = new ServiceOrderRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->submissions = new ServiceSubmissionRepository($this->db);

        $suffix = bin2hex(random_bytes(6));
        $this->imagePath = sys_get_temp_dir() . '/autogen-img-' . $suffix;
        $this->deliverablePath = sys_get_temp_dir() . '/autogen-out-' . $suffix;
        mkdir($this->imagePath, 0o750, true);
        mkdir($this->deliverablePath, 0o750, true);

        $this->images = new ImageUploadStorage($this->imagePath);
        $this->deliverables = new DeliverableStorage($this->deliverablePath);
        $this->cards = new NidMakePdfRenderer($container->get(TwigEnvironment::class), $this->images);

        $this->categoryId = (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
            ->queryScalar();

        $this->userId = $this->users->create([
            'username' => 'autogen_' . bin2hex(random_bytes(4)),
            'phone' => '9' . bin2hex(random_bytes(5)),
            'email' => 'autogen_' . bin2hex(random_bytes(4)) . '@example.test',
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
            new ActivityLogRepository($this->db),
            new NotificationRepository($this->db),
            TestGraph::notify($this->db, $this->users, new SettingsRepository($this->db)),
            OrderWindowService::alwaysOpen(),
            $this->images,
            $this->submissions,
            $this->cards,
            $this->deliverables,
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

        $this->rmTree($this->imagePath);
        $this->rmTree($this->deliverablePath);
        $this->db->close();
    }

    // ---- Born processing, never queued for an operator ---------------------

    public function testAnAutoOrderIsBornProcessingWithNothingForAnAdminToDo(): void
    {
        $orderId = $this->submitNidMake();

        $row = $this->orders->findById($orderId);
        assertNotNull($row);
        $metadata = ServiceOrderRepository::metadata($row);

        assertSame(
            StatusPresenter::PROCESSING,
            (string) $row['status'],
            'pending IS the admin work queue. An order that builds its own output must not land there.',
        );
        assertTrue($metadata['auto_generate'] ?? false, 'The row records that it settles itself.');
        assertTrue(
            ($metadata['auto_started_at'] ?? 0) > 0,
            'The clock has to start at submission, or the delay is measured from whenever the user next looked.',
        );
        assertNull($row['deliverable_path'], 'Nothing to download yet — the card has not been built.');
    }

    public function testTheDelayIsLeftAloneSoTheCustomerSeesSomethingHappen(): void
    {
        $orderId = $this->submitNidMake();

        $this->manager->settleAutoOrders([$this->orders->findById($orderId)]);

        $row = $this->orders->findById($orderId);
        assertSame(
            StatusPresenter::PROCESSING,
            (string) $row['status'],
            'Settling on the same tick it was submitted would make the "processing" state a lie.',
        );
        assertNull($row['deliverable_path']);
        assertSame([], $this->storedFiles(), 'And it must not have written a file it then hid.');
    }

    // ---- The card ----------------------------------------------------------

    public function testOnceTheDelayHasPassedTheOrderIsFinishedWithARealPdfOnDisk(): void
    {
        $orderId = $this->submitNidMake();
        $this->ageByDelay($orderId);

        $this->manager->settleAutoOrders([$this->orders->findById($orderId)]);

        $row = $this->orders->findById($orderId);
        assertSame(StatusPresenter::COMPLETED, (string) $row['status']);
        assertNotNull($row['deliverable_path'], 'A completed auto order is only useful if the file is attached.');
        assertSame('application/pdf', (string) $row['deliverable_mime']);

        $bytes = (string) file_get_contents((string) $this->deliverables->absolutePath($row['deliverable_path']));
        assertStringStartsWith('%PDF-', $bytes, 'The deliverable must be the PDF, not a renamed HTML page.');
        assertTrue(strlen($bytes) > 3000, 'A card with a typeset form and two images is not ' . strlen($bytes) . ' bytes.');
    }

    public function testTheCardCarriesTheUploadedPhotoNotAnEmptyBox(): void
    {
        $orderId = $this->submitNidMake();
        $values = $this->submissions->valuesFor($orderId);

        $photo = (string) ($values['photo'] ?? '');
        assertTrue($photo !== '', 'The fixture submitted a photo; the stored JSON must name it.');
        assertNotNull($this->images->absolutePath($photo), 'And it must still be resolvable from the DB value alone.');

        $pdf = $this->cards->render($values, ['reference' => 'PROBE-1', 'generated_at' => '01/01/2026 12:00 AM']);
        assertStringContainsString(
            '/Image',
            $pdf,
            'mPDF writes an image XObject for every <img> it loads, so its absence means the card shipped '
            . 'with an empty box where the face should be — the one failure that looks like success.',
        );
        assertStringStartsWith('%PDF-', $pdf);
    }

    public function testAMissingPhotoRendersThePlaceholderInsteadOfBreaking(): void
    {
        $bare = $this->cards->render(['nid_number' => '1234567890', 'photo' => '']);
        $withPhoto = $this->cards->render(['nid_number' => '1234567890', 'photo' => $this->storedPhoto()]);

        assertStringStartsWith('%PDF-', $bare, 'A customer with no photo is a normal order, not an error.');
        assertTrue(
            strlen($bare) < strlen($withPhoto),
            'The photo has to make the file measurably bigger, otherwise the previous test only proves '
            . 'mPDF wrote an /Image entry — which it writes for the ProcSet even with no image at all.',
        );
    }

    /** A photo written straight into the image bucket and returned as its stored path. */
    private function storedPhoto(): string
    {
        return $this->images->store($this->pngUpload('probe.png'))['path'];
    }

    public function testACardThatWillNotBuildSaysSoOnTheOrderInsteadOfFailingSilently(): void
    {
        $orderId = $this->submitNidMake();
        // A pin of the wrong length is the one thing MockNidMakeService
        // rejects, and it survives submit() because the order is born without
        // running the provider — so this is the realistic way a card fails.
        $this->corruptPin($orderId, '123');
        $this->ageByDelay($orderId);

        $this->manager->settleAutoOrders([$this->orders->findById($orderId)]);

        $row = $this->orders->findById($orderId);
        $metadata = ServiceOrderRepository::metadata($row);

        assertSame(
            StatusPresenter::PROCESSING,
            (string) $row['status'],
            'A bad answer is worth another attempt, not a terminal failure the customer did not cause.',
        );
        assertTrue(
            isset($metadata['auto_error']) && $metadata['auto_error'] !== '',
            'An order that never finishes looks identical to one that is merely still going unless the '
            . 'reason is written down; the next tick would otherwise retry the same failure for ever.',
        );
    }

    // ---- Nothing else is touched -------------------------------------------

    public function testAServiceThatNeedsAHumanIsNeverSettledAutomatically(): void
    {
        // `mock-nid-service` — the NID *lookup* provider, which answers a
        // question about a third party and therefore genuinely needs approval.
        $service = $this->makeService('mock-nid-service-lookup-probe-');
        $result = $this->manager->submit($service, $this->identity(), $this->scalars(), '127.0.0.1', 'phpunit', []);
        assertTrue($result->success, $result->message);

        $orderId = (int) $result->data['_request_id'];
        $row = $this->orders->findById($orderId);
        assertSame(StatusPresenter::PENDING, (string) $row['status']);
        assertSame(
            false,
            ServiceOrderRepository::metadata($row)['auto_generate'] ?? false,
            'The flag is the only thing settleAutoOrders keys off, so it has to be written explicitly.',
        );

        $this->ageByDelay($orderId);
        $this->manager->settleAutoOrders([$this->orders->findById($orderId)]);

        $row = $this->orders->findById($orderId);
        assertSame(StatusPresenter::PENDING, (string) $row['status'], 'Still waiting for an operator.');
        assertNull($row['deliverable_path'], 'And no card was built behind the admin’s back.');
    }

    public function testAPollThatArrivesTwiceLeavesOneCardNotTwo(): void
    {
        $orderId = $this->submitNidMake();
        $this->ageByDelay($orderId);

        // Two tabs, one order, both reading `processing` before either writes.
        $stale = $this->orders->findById($orderId);
        $this->manager->settleAutoOrders([$stale]);
        $this->manager->settleAutoOrders([$stale]);

        $row = $this->orders->findById($orderId);
        assertSame(StatusPresenter::COMPLETED, (string) $row['status']);
        assertCount(
            1,
            $this->storedFiles(),
            'The conditional UPDATE means the loser discards its card; two files would mean one is '
            . 'unreachable and nobody could ever delete it.',
        );
    }

    public function testSettlingIsSafeForRowsThatHaveNothingToDoWithIt(): void
    {
        $this->submitNidMake();

        $this->manager->settleAutoOrders([
            ['id' => 0, 'status' => StatusPresenter::PROCESSING, 'metadata' => null],
            ['id' => 99999999, 'status' => StatusPresenter::PROCESSING, 'metadata' => null],
            ['id' => 5, 'status' => StatusPresenter::COMPLETED, 'metadata' => null],
        ]);
        $this->manager->settleAutoOrders([]);

        assertTrue(true, 'No id, a missing row and a finished order are all skipped, not fatal.');
    }

    // ---- Helpers -----------------------------------------------------------

    private function submitNidMake(): int
    {
        $service = $this->makeService('nid-make-autogen-probe-');
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            ['photo' => $this->pngUpload('photo.png'), 'signature' => $this->pngUpload('signature.png')],
        );

        assertTrue($result->success, $result->message . ' errors=' . json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        return (int) $result->data['_request_id'];
    }

    /**
     * @param string $slugPrefix must contain `nid-make` or `nid-service` so
     *                           providerFor() resolves the right mock
     */
    private function makeService(string $slugPrefix): array
    {
        $fields = str_contains($slugPrefix, 'nid-make')
            ? (new MockNidMakeService())->fields()
            : [];

        $id = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'AutoGenerate Probe ' . bin2hex(random_bytes(4)),
            'slug' => $slugPrefix . bin2hex(random_bytes(4)),
            'description' => 'Disposable auto-generate fixture',
            'service_type' => 'mock',
            'price' => 10,
            'form_fields' => $fields,
            'sort_order' => 996,
        ]);
        $this->serviceIds[] = $id;

        return (array) $this->services->findServiceById($id);
    }

    /** Replace the stored pin with one the provider will reject. */
    private function corruptPin(int $orderId, string $pin): void
    {
        $metadata = ServiceOrderRepository::metadata($this->orders->findById($orderId));
        $metadata['input']['pin'] = $pin;
        $this->db
            ->createCommand()
            ->update('{{%service_order}}', [
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ], ['id' => $orderId])
            ->execute();
    }

    /** Push `auto_started_at` back so the delay has already elapsed. */
    private function ageByDelay(int $orderId): void
    {
        $row = $this->orders->findById($orderId);
        $metadata = ServiceOrderRepository::metadata($row);
        $metadata['auto_started_at'] = time() - (ServiceManager::AUTO_SETTLE_DELAY_SECONDS + 1);
        $this->db
            ->createCommand()
            ->update('{{%service_order}}', [
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ], ['id' => $orderId])
            ->execute();
    }

    /** @return string[] absolute paths of everything under the deliverable root */
    private function storedFiles(): array
    {
        $found = [];
        $walk = function (string $dir) use (&$walk, &$found): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $child = $dir . '/' . $entry;
                is_dir($child) ? $walk($child) : $found[] = $child;
            }
        };
        $walk($this->deliverablePath);

        return $found;
    }

    private function identity(): Identity
    {
        return Identity::fromRow((array) $this->users->findById($this->userId));
    }

    private function scalars(): array
    {
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