<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Service\ImageUploadStorage;
use App\Service\LedgerService;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\ServiceProvider\MockNidMakeService;
use App\ServiceProvider\ServiceField;
use App\ServiceProvider\ServiceResult;
use App\Tests\Support\TestGraph;
use Codeception\Test\Unit;
use Nyholm\Psr7\UploadedFile;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * An `image` field is answered by a file, not by a posted value, so it cannot
 * ride the scalar path every other field takes. These tests cover the three
 * places that difference is load-bearing:
 *
 *  1. the required check must not read an image field out of the parsed body,
 *     or every image form is unsubmittable;
 *  2. the upload must actually be written by `ImageUploadStorage`, keyed by a
 *     name the client cannot choose;
 *  3. a refused upload must not leave the accepted ones behind — the storage
 *     layout is hashed, so an orphan there would never be found again.
 *
 * The tests use a temporary base directory for the storage and delete it in
 * `_after()`, because the real one is the user's dev upload folder.
 */
final class ImageFieldTest extends Unit
{
    private ConnectionInterface $db;
    private ServiceRepository $services;
    private ServiceOrderRepository $orders;
    private UserRepository $users;
    private LedgerService $ledger;
    private ServiceManager $manager;
    private ImageUploadStorage $storage;

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
        $this->users = new UserRepository($this->db);

        $this->basePath = sys_get_temp_dir() . '/image-field-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0o750, true);
        $this->storage = new ImageUploadStorage($this->basePath);

        $this->categoryId = (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_category}} ORDER BY [[id]] ASC LIMIT 1')
            ->queryScalar();

        $this->userId = $this->users->create([
            'username' => 'imgtest_' . bin2hex(random_bytes(4)),
            'phone' => '9' . bin2hex(random_bytes(5)),
            'email' => 'imgtest_' . bin2hex(random_bytes(4)) . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => 1000,
        ]);
        $this->userIds[] = $this->userId;

        $this->ledger = TestGraph::ledger($this->db, $this->users);
        $this->orders = TestGraph::orders($this->db);

        $this->manager = new ServiceManager(
            $this->services,
            $this->orders,
            $this->ledger,
            $this->users,
            new \App\Repository\ActivityLogRepository($this->db),
            new \App\Repository\NotificationRepository($this->db),
            TestGraph::notify($this->db, $this->users, new \App\Repository\SettingsRepository($this->db)),
            // The default window is a real, clock-dependent gate; these tests assert
            // on storage behaviour, not on the hour of the day they happen to run.
            OrderWindowService::alwaysOpen(),
            $this->storage,
        );
    }

    protected function _after(): void
    {
        foreach ($this->serviceIds as $id) {
            TestGraph::purgeServiceOrders($this->db, $id);
            $this->db->createCommand()->delete('{{%service}}', ['id' => $id])->execute();
        }
        foreach ($this->userIds as $id) {
            // Ledger rows point at the order that moved the money, so they have to
            // go before the order does or the FK refuses the rest of the sweep.
            $this->db->createCommand(
                'DELETE t FROM {{%transaction}} t'
                . ' JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
                . ' WHERE o.[[user_id]] = :id',
            )
                ->bindValue(':id', $id)
                ->execute();
            // submit() fans notifications out to the customer and the admin, so
            // the queue and its deliveries reference this user too.
            $this->db->createCommand(
                'DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN'
                . ' (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :id)',
            )
                ->bindValue(':id', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }

        $this->rmTree($this->basePath);
        $this->db->close();
    }

    // ---- The type itself ---------------------------------------------------

    public function testImageIsAWhitelistedFieldType(): void
    {
        assertTrue(in_array('image', ServiceField::TYPES, true), 'The image type must be renderable.');
        assertSame('image', ServiceField::normaliseType('IMAGE'), 'Case is normalised, not rejected.');
        assertSame('text', ServiceField::normaliseType('script'), 'And anything unknown still falls back to text.');
    }

    public function testTheNidMakeServiceOffersTwoImageFields(): void
    {
        $service = $this->makeService(['photo', 'signature']);
        $images = array_values(array_filter(
            $this->manager->fieldsFor($service),
            static fn (ServiceField $f): bool => $f->type === 'image',
        ));

        assertCount(2, $images, 'nid-make declares a photo and a signature.');
        assertSame(['photo', 'signature'], array_map(static fn (ServiceField $f): string => $f->name, $images));
    }

    // ---- The required check ------------------------------------------------

    public function testAMissingRequiredImageIsReportedRatherThanPassed(): void
    {
        $service = $this->makeService(['photo']);
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
        );

        assertFalse($result->success);
        assertArrayHasKey('photo', $result->errors);
        assertStringContainsString('আবশ্যক', (string) $result->errors['photo']);
        assertSame(0, $this->orderCount(), 'A refused form must not open an order.');
    }

    public function testAnImageFieldIsNotReadOutOfThePostedBody(): void
    {
        $service = $this->makeService(['photo']);

        // A crafted POST that stuffs a path into the body must not satisfy the
        // field: the only way in is a real upload the storage accepted.
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars() + ['photo' => 'order-x/abc.png'],
            '127.0.0.1',
            'phpunit',
        );

        assertFalse($result->success, 'A hand-written value is not an upload.');
        assertArrayHasKey('photo', $result->errors);
    }

    // ---- Storing -----------------------------------------------------------

    public function testAnUploadedImageIsStoredAndTheOrderCarriesItsPath(): void
    {
        $service = $this->makeService(['photo', 'signature']);

        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            [
                'photo' => $this->pngUpload('my-photo.png'),
                'signature' => $this->pngUpload('sign.png'),
            ],
        );

        assertTrue($result->success, $result->message);

        $order = $this->orderOf($result);
        $input = (array) (ServiceOrderRepository::metadata($order)['input'] ?? []);

        assertArrayHasKey('photo', $input);
        assertArrayHasKey('signature', $input);

        $photoPath = (string) $input['photo'];
        assertTrue(
            $this->storage->isValidRelativePath($photoPath),
            'The stored value must be a storage path, not the client filename: ' . $photoPath,
        );
        assertNotSame('my-photo.png', $photoPath, 'The client filename must never become the stored name.');
        assertNotNull($this->storage->absolutePath($photoPath), 'And the bytes must actually be on disk.');
    }

    public function testAFileNamedForAnUnconfiguredFieldIsIgnored(): void
    {
        $service = $this->makeService([]);

        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            ['photo' => $this->pngUpload('sneaky.png')],
        );

        assertTrue($result->success, $result->message);
        $input = (array) (ServiceOrderRepository::metadata($this->orderOf($result))['input'] ?? []);
        assertFalse(array_key_exists('photo', $input), 'A field the form does not show must not be stored.');
    }

    public function testANonImageIsRefusedAndNothingIsWritten(): void
    {
        $service = $this->makeService(['photo', 'signature']);

        // photo is a real PNG; signature is a PHP script wearing a .png name.
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            [
                'photo' => $this->pngUpload('ok.png'),
                'signature' => $this->textUpload('evil.png'),
            ],
        );

        assertFalse($result->success);
        assertArrayHasKey('signature', $result->errors);
        assertSame(
            0,
            $this->storedFileCount(),
            'The PNG that arrived first must be rolled back with the rest — a rejected '
            . 'form cannot leave a file behind that nothing will ever find.',
        );
        assertSame(0, $this->orderCount());
    }

    public function testTheProviderReadsTheStoredPathBackAsAFileName(): void
    {
        $service = $this->makeService(['photo']);
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            ['photo' => $this->pngUpload('passport.png')],
        );
        assertTrue($result->success, $result->message);

        $input = (array) (ServiceOrderRepository::metadata($this->orderOf($result))['input'] ?? []);

        $executed = $this->manager->providerFor($service)?->execute($input);
        assertTrue($executed instanceof ServiceResult && $executed->success);
        $name = (string) ($executed->data['photo'] ?? '');
        assertSame(
            basename((string) $input['photo']),
            $name,
            'The result card shows the file name, never the whole storage path.',
        );
    }

    // ---- The budget the field carries --------------------------------------

    public function testTheStoredImagesHonourTheBudgetTheirFieldCarries(): void
    {
        $service = $this->makeServiceFromNidMake();

        // Both fixtures start out far over their budget — the photo at over
        // 2.5 MB, which is what an ordinary phone produces for a card — so
        // this only passes if the budget reaches the storage through the form.
        $result = $this->manager->submit(
            $service,
            $this->identity(),
            $this->scalars(),
            '127.0.0.1',
            'phpunit',
            [
                'photo' => $this->upload($this->cardJpeg(3000, 2000), 'photo.jpg', 'image/jpeg'),
                'signature' => $this->upload($this->signaturePng(2400, 600), 'sign.png', 'image/png'),
            ],
        );

        assertTrue($result->success, $result->message);
        $input = (array) (ServiceOrderRepository::metadata($this->orderOf($result))['input'] ?? []);

        $budgets = [
            'photo' => MockNidMakeService::PHOTO_MAX_BYTES,
            'signature' => MockNidMakeService::SIGNATURE_MAX_BYTES,
        ];

        foreach ($budgets as $field => $budget) {
            $absolute = $this->storage->absolutePath((string) ($input[$field] ?? ''));
            assertNotNull($absolute, 'The ' . $field . ' must really be on disk: ' . var_export($input[$field] ?? null, true));

            $size = (int) filesize($absolute);
            assertLessThanOrEqual(
                $budget,
                $size,
                sprintf(
                    'The nid-make %s is stored at %.1f KB, over its %d KB budget.',
                    $field,
                    $size / 1024,
                    $budget / 1024,
                ),
            );
        }
    }

    // ---- Helpers -----------------------------------------------------------

    /** @param string[] $imageFields */
    private function makeService(array $imageFields): array
    {
        $fields = [new ServiceField('nid_number', 'আইডি নম্বর', 'text', true)];
        foreach ($imageFields as $name) {
            $fields[] = new ServiceField($name, $name, 'image', true);
        }

        // The slug carries `nid-make` so `providerFor()` resolves mock-nid-make, which
        // is the provider under test — the routing is by slug substring, and a
        // probe named `image-probe-*` would fall through to the plain NID lookup.
        $id = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Image Probe ' . bin2hex(random_bytes(4)),
            'slug' => 'nid-make-image-probe-' . bin2hex(random_bytes(4)),
            'description' => 'Disposable image-field fixture',
            'service_type' => 'mock',
            'price' => 10,
            'form_fields' => $fields,
            'sort_order' => 997,
        ]);
        $this->serviceIds[] = $id;

        return (array) $this->services->findServiceById($id);
    }

    /**
     * A probe service carrying the REAL nid-make image fields.
     *
     * Taking the fields from the provider rather than re-declaring them is the
     * point: a budget that only exists in a test would pass here and never
     * reach a user.
     *
     * @param string[] $imageFields
     */
    private function makeServiceFromNidMake(array $imageFields = ['photo', 'signature']): array
    {
        $fields = [new ServiceField('nid_number', 'আইডি নম্বর', 'text', true)];
        foreach ((new MockNidMakeService())->fields() as $field) {
            if ($field->type === 'image' && in_array($field->name, $imageFields, true)) {
                $fields[] = $field;
            }
        }

        $id = $this->services->createService([
            'category_id' => $this->categoryId,
            'name' => 'Budget Probe ' . bin2hex(random_bytes(4)),
            'slug' => 'nid-make-budget-probe-' . bin2hex(random_bytes(4)),
            'description' => 'Disposable image-budget fixture',
            'service_type' => 'mock',
            'price' => 10,
            'form_fields' => $fields,
            'sort_order' => 996,
        ]);
        $this->serviceIds[] = $id;

        return (array) $this->services->findServiceById($id);
    }

    /**
     * An ID-card-shaped picture with enough detail that re-encoding it at a
     * lower quality actually costs bytes — a flat rectangle would fit any
     * budget at any size and prove nothing.
     */
    private function cardJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 245, 245, 240));
        imagefilledrectangle($image, 0, 0, $width - 1, (int) ($height * 0.18), (int) imagecolorallocate($image, 13, 106, 110));

        $ink = (int) imagecolorallocate($image, 30, 30, 30);
        mt_srand(7);
        for ($row = 0; $row < 9; $row++) {
            $y = (int) ($height * 0.28) + $row * (int) ($height * 0.055);
            $length = (int) ($width * (0.30 + mt_rand(0, 45) / 100));
            imagefilledrectangle(
                $image,
                (int) ($width * 0.08),
                $y,
                (int) ($width * 0.08) + $length,
                $y + (int) ($height * 0.018),
                $ink,
            );
        }
        for ($i = 0; $i < (int) ($width * $height / 40); $i++) {
            $grain = mt_rand(0, 40);
            imagesetpixel(
                $image,
                mt_rand(0, $width - 1),
                mt_rand(0, $height - 1),
                (int) imagecolorallocate($image, $grain, $grain, $grain),
            );
        }

        $bytes = $this->encode($image, 'jpg');
        imagedestroy($image);

        return $bytes;
    }

    /** A pen stroke on a transparent ground, like a scanned signature. */
    private function signaturePng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        $ink = (int) imagecolorallocate($image, 0, 0, 0);
        $previous = null;
        for ($x = (int) ($width * 0.12); $x < (int) ($width * 0.88); $x++) {
            $y = (int) ($height / 2) + (int) (sin($x / 18) * $height * 0.2);
            if ($previous !== null) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }
            $previous = [$x, $y];
        }

        $bytes = $this->encode($image, 'png');
        imagedestroy($image);

        return $bytes;
    }

    private function encode(\GdImage $image, string $format): string
    {
        $bytes = '';
        ob_start();
        try {
            $format === 'jpg'
                ? imagejpeg($image, null, 90)
                : imagepng($image, null, 6);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $bytes;
    }

    private function scalars(): array
    {
        return ['nid_number' => '1234567890'];
    }

    private function identity(): Identity
    {
        return Identity::fromRow((array) $this->users->findById($this->userId));
    }

    /** The stored order row for a successful submit, by the id it reported. */
    private function orderOf(ServiceResult $result): array
    {
        return (array) $this->orders->findById((int) ($result->data['_request_id'] ?? 0));
    }

    private function upload(string $bytes, string $name, string $declaredMime): UploadedFile
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, $declaredMime);
    }

    /** A valid 1x1 PNG, so the upload passes the real finfo MIME sniff. */
    private function pngUpload(string $name = 'photo.png'): UploadedFile
    {
        return $this->upload(self::pngBytes(), $name, 'image/png');
    }

    /** A PHP script wearing a .png name — the upload this must refuse. */
    private function textUpload(string $name = 'evil.png'): UploadedFile
    {
        return $this->upload("<?php echo 'x'; ?>\n", $name, 'image/png');
    }

    /** @see AdminServiceRequestTest — a 1x1 transparent PNG. */
    private static function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    private function orderCount(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service_order}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $this->userId)
            ->queryScalar();
    }

    private function storedFileCount(): int
    {
        $count = 0;
        foreach (glob($this->basePath . '/*/*') ?: [] as $file) {
            if (is_file($file)) {
                $count++;
            }
        }

        return $count;
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