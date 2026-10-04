<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Tests\Support\TestGraph;
use App\Service\ReceiptStorage;
use App\Service\TopupService;
use App\Web\Account\ReceiptAction;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsNotWith;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * The recharge lifecycle the feature is built around: a user submits payment
 * details plus a receipt, an admin claims it, verifies it manually, and
 * approval credits the balance.
 *
 * The assertions concentrate on the parts that move money or leak money:
 * duplicate TrxIDs, a second concurrent request, double-crediting on replay,
 * a reject without a reason, and whether one user can read another's receipt.
 * The "receipt is stored outside the document root" property is asserted too,
 * because everything else in ReceiptStorage trusts that single fact — as is the
 * reverse of it, that watermarking rewrites only the served copy.
 *
 * Throwaway rows only, all removed again in _after().
 */
final class RechargeTest extends \Codeception\Test\Unit
{
    private const START_BALANCE = 200.0;
    private const AMOUNT = 500.0;

    /** Smallest thing finfo still identifies as a real PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private static ?string $pngBytes = null;

    /** Real PNG bytes — MIME sniffing works on content, not on a file name. */
    private static function pngBytes(): string
    {
        if (self::$pngBytes === null) {
            $decoded = base64_decode(self::PNG_BASE64, true);
            if ($decoded === false) {
                throw new \RuntimeException('PNG fixture must be valid base64');
            }
            self::$pngBytes = $decoded;
        }

        return self::$pngBytes;
    }

    private ConnectionInterface $db;
    private UserRepository $users;
    private TopupRepository $topups;
    private TopupService $service;
    private ReceiptStorage $receipts;

    /** @var int[] */
    private array $userIds = [];
    /** @var int[] */
    private array $topupIds = [];
    private string $suffix = '';
    private string $startedAt = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->topups = new TopupRepository($this->db);
        $this->receipts = ReceiptStorage::fromProjectRoot();
        $this->service = new TopupService(
            $this->topups,
            $this->users,
            TestGraph::ledger($this->db, $this->users),
            new NotificationRepository($this->db),
            new ActivityLogRepository($this->db),
            new SettingsRepository($this->db),
            $this->receipts,
            new \App\Notification\NotificationManager(
                new NotificationRepository($this->db),
                new \App\Notification\QueueRepository($this->db),
                new \App\Notification\TemplateRenderer(
                    $this->db,
                    new SettingsRepository($this->db),
                ),
                $this->users,
                $this->db,
            ),
        );

        // Fan-out rows land on users outside our own list (the real admin gets
        // every admin broadcast), so cleanup also sweeps by this time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
        $this->suffix = 'r' . substr(md5(uniqid('', true)), 0, 10);
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

        foreach ($this->topupIds as $id) {
            $row = $this->topups->findById($id);
            if ($row !== null) {
                $this->receipts->delete($row['receipt_path'] ?? null);
            }
        }
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%topup_request}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
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
        // In-app fan-out rows that landed on users outside our list.
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE created_at >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->topupIds = [];
        $this->userIds = [];
    }

    private function makeUser(string $tag = 'a', string $role = 'user'): Identity
    {
        $id = $this->users->create([
            'username' => 'rc_' . $tag . '_' . $this->suffix,
            'phone' => '8' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'rc_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
            'balance' => self::START_BALANCE,
        ]);
        $this->userIds[] = $id;
        return Identity::fromRow((array) $this->users->findById($id));
    }

    /** A valid 1x1 PNG, so the upload passes the real finfo MIME sniff. */
    private function receiptUpload(string $name = 'receipt.png'): UploadedFile
    {
        $bytes = self::pngBytes();

        return new UploadedFile(
            $this->writeStream($bytes),
            strlen($bytes),
            UPLOAD_ERR_OK,
            $name,
            'image/png',
        );
    }

    private function upload(string $bytes, string $name, string $declaredMime): UploadedFile
    {
        return new UploadedFile(
            $this->writeStream($bytes),
            strlen($bytes),
            UPLOAD_ERR_OK,
            $name,
            $declaredMime,
        );
    }

    /**
     * A PNG big enough for a stamp to actually land on it.
     *
     * The 1x1 fixture is ideal for exercising the MIME sniff, but every pixel of
     * a stamp falls outside it — so on that image "was it stamped?" and "do the
     * two labels differ?" are both unanswerable. Only the watermark assertions
     * need a canvas; everything else stays on the tiny one.
     */
    private function visibleUpload(int $width = 200, int $height = 100): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 245));
        imagestring($image, 5, 4, 4, 'ORIGINAL RECEIPT', imagecolorallocate($image, 20, 20, 20));

        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $this->upload($bytes, 'receipt.png', 'image/png');
    }

    /** @return resource */
    private function writeStream(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    /** @return array<string,string> */
    private function payload(string $ref, array $overrides = []): array
    {
        return array_merge([
            'amount' => (string) self::AMOUNT,
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'sender_name' => 'Test Sender',
            'reference' => $ref,
            'note' => '',
        ], $overrides);
    }

    private function ref(string $tag): string
    {
        return 'TRX' . strtoupper($this->suffix) . $tag;
    }

    private function balance(Identity $user): float
    {
        return (float) ((array) $this->users->findById($user->id))['balance'];
    }

    /** The newest notification row for a user, as an array. */
    private function latestNotification(Identity $user): array
    {
        $rows = (new NotificationRepository($this->db))->forUser($user->id, 1, 1)['rows'];
        $this->assertNotEmpty($rows, 'A decision must reach the user');

        return (array) $rows[0];
    }

    /** Submit a valid request and return the stored row. */
    private function submit(Identity $user, string $tag, array $overrides = [], ?UploadedFile $receipt = null): array
    {
        [$ok, $message, $errors] = $this->service->request(
            $user->id,
            $this->payload($this->ref($tag), $overrides),
            $receipt ?? $this->receiptUpload(),
        );
        $this->assertTrue($ok, 'Request must be accepted, got: ' . $message . ' ' . json_encode($errors));

        $row = (array) $this->topups->forUser($user->id, 1, 1)['rows'][0];
        $this->topupIds[] = (int) $row['id'];
        return $row;
    }

    // ---- Upload + storage -------------------------------------------------

    public function testRequestWithReceiptStoresFileOutsideWebRoot(): void
    {
        $user = $this->makeUser();
        $row = $this->submit($user, 'A');

        $this->assertSame('pending', $row['status']);
        $this->assertNotNull($row['receipt_path'], 'A receipt was uploaded, so a path must be stored');
        $this->assertSame('receipt.png', $row['receipt_name'], 'The original name is kept for display only');
        $this->assertSame('image/png', $row['receipt_mime'], 'MIME must be sniffed, not trusted');
        $this->assertGreaterThan(0, (int) $row['receipt_size']);

        $absolute = $this->receipts->absolutePath((string) $row['receipt_path']);
        $this->assertNotNull($absolute, 'The stored file must be readable back');
        $this->assertTrue(is_file($absolute));

        // The whole point of the design: not reachable by a static handler.
        $webRoot = realpath(codecept_root_dir() . 'public');
        $this->assertStringStartsWith(
            realpath(codecept_root_dir() . 'web') . DIRECTORY_SEPARATOR,
            realpath(dirname($absolute)) . DIRECTORY_SEPARATOR,
        );
        $this->assertStringStartsNotWith($webRoot . DIRECTORY_SEPARATOR, realpath(dirname($absolute)) . DIRECTORY_SEPARATOR);
    }

    public function testReceiptIsReFiledUnderItsOwnRequestDirectory(): void
    {
        $user = $this->makeUser();
        $row = $this->submit($user, 'A');

        // store() files under a placeholder id; after the insert the path must
        // point at the real id's directory, otherwise one request's directory
        // is a de-facto shared bucket.
        $this->assertStringStartsWith(
            substr(sha1('topup-' . (int) $row['id']), 0, 16) . '/',
            (string) $row['receipt_path'],
        );
    }

    public function testMissingReceiptIsRejectedWhenRequired(): void
    {
        $user = $this->makeUser();

        [$ok, , $errors] = $this->service->request($user->id, $this->payload($this->ref('A')));

        $this->assertFalse($ok);
        $this->assertArrayHasKey('receipt', $errors);
    }

    public function testNonImageUploadIsRefused(): void
    {
        $user = $this->makeUser();

        $php = $this->upload('<?php echo "pwned"; ?>', 'payload.php', 'image/png'); // MIME deliberately lying

        [$ok, , $errors] = $this->service->request($user->id, $this->payload($this->ref('A')), $php);

        $this->assertFalse($ok);
        $this->assertArrayHasKey('receipt', $errors);
        $this->assertSame([], $this->topups->forUser($user->id, 1, 5)['rows'], 'No row may be written');
    }

    // ---- Validation guards -----------------------------------------------

    public function testDuplicateTransactionIdIsRefused(): void
    {
        $user = $this->makeUser();
        $this->submit($user, 'A');

        [$ok, , $errors] = $this->service->request(
            $user->id,
            $this->payload($this->ref('A')),
            $this->receiptUpload(),
        );

        $this->assertFalse($ok, 'The same TrxID must not be accepted twice');
        $this->assertArrayHasKey('reference', $errors);
    }

    public function testSecondPendingRequestIsBlocked(): void
    {
        $user = $this->makeUser();
        $this->submit($user, 'A');

        [$ok, , $errors] = $this->service->request(
            $user->id,
            $this->payload($this->ref('B')),
            $this->receiptUpload(),
        );

        $this->assertFalse($ok, 'Only one open request per user at a time');
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testAmountOutsideLimitsIsRefused(): void
    {
        $user = $this->makeUser();

        [$ok, , $errors] = $this->service->request(
            $user->id,
            $this->payload($this->ref('A'), ['amount' => '1']),
            $this->receiptUpload(),
        );

        $this->assertFalse($ok);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testMalformedWalletNumberIsRefused(): void
    {
        $user = $this->makeUser();

        [$ok, , $errors] = $this->service->request(
            $user->id,
            $this->payload($this->ref('A'), ['sender_number' => '12345']),
            $this->receiptUpload(),
        );

        $this->assertFalse($ok);
        $this->assertArrayHasKey('sender_number', $errors);
    }

    // ---- Cancel -----------------------------------------------------------

    public function testUserCancelsOwnPendingRequest(): void
    {
        $user = $this->makeUser();
        $row = $this->submit($user, 'A');

        [$ok, $message] = $this->service->cancel((int) $row['id'], $user->id);

        $this->assertTrue($ok, $message);
        $this->assertSame('rejected', (string) $this->topups->findById((int) $row['id'])['status']);

        // Cancelling frees the queue, so a fresh request is allowed again.
        [$again] = $this->service->request($user->id, $this->payload($this->ref('B')), $this->receiptUpload());
        $this->assertTrue($again);
        $this->topupIds[] = (int) $this->topups->forUser($user->id, 1, 1, 'pending')['rows'][0]['id'];
    }

    public function testUserCannotCancelAnotherUsersRequest(): void
    {
        $owner = $this->makeUser('a');
        $other = $this->makeUser('b');
        $row = $this->submit($owner, 'A');

        [$ok] = $this->service->cancel((int) $row['id'], $other->id);

        $this->assertFalse($ok, 'Ownership must be enforced');
        $this->assertSame('pending', (string) $this->topups->findById((int) $row['id'])['status']);
    }

    // ---- Review -----------------------------------------------------------

    public function testApproveCreditsBalanceExactlyOnce(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');

        $before = $this->balance($user);

        [$ok, $message] = $this->service->approve((int) $row['id'], $admin->id);
        $this->assertTrue($ok, $message);
        $this->assertEquals($before + self::AMOUNT, $this->balance($user), 'Balance must rise by exactly the amount');

        // Replaying the same approval must be a no-op, not a second credit.
        [$okAgain, $replayMessage] = $this->service->approve((int) $row['id'], $admin->id);
        $this->assertFalse($okAgain, 'A replayed approval must be refused: ' . $replayMessage);
        $this->assertEquals($before + self::AMOUNT, $this->balance($user), 'Balance must not move twice');

        $approved = (array) $this->topups->findById((int) $row['id']);
        $this->assertSame('approved', $approved['status']);
        $this->assertNotNull($approved['transaction_id'], 'A transaction must link back to the request');
    }

    public function testApproveRecordsATransaction(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');

        $this->service->approve((int) $row['id'], $admin->id);

        $approved = (array) $this->topups->findById((int) $row['id']);
        $tx = $this->db->createCommand(
            'SELECT [[reference]], [[amount]], [[status]], [[type]], [[metadata]] FROM {{%transaction}} WHERE [[id]] = :id',
        )
            ->bindValue(':id', (int) $approved['transaction_id'])
            ->queryOne();

        $this->assertNotNull($tx);
        $this->assertEquals(self::AMOUNT, (float) $tx['amount']);
        $this->assertSame('completed', $tx['status']);
        // `type` is a column of its own now, not a key inside `metadata`: it is
        // what the ledger page filters and sums by, so it has to be indexable.
        $this->assertSame(TransactionRepository::TYPE_TOPUP, (string) $tx['type']);
        $this->assertStringContainsString(
            (string) ($tx['metadata'] !== null ? 'topup_id' : ''),
            (string) $tx['metadata'],
            'The metadata keeps the receipt details the audit needs.',
        );
    }

    public function testRejectRequiresAReason(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');

        [$ok, $message] = $this->service->reject((int) $row['id'], $admin->id, '   ');

        $this->assertFalse($ok, 'A reject without a reason is refused: ' . $message);
        $this->assertSame('pending', (string) $this->topups->findById((int) $row['id'])['status'], 'Must stay pending');
    }

    public function testRejectStoresReasonAndLeavesBalanceAlone(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');

        [$ok, $message] = $this->service->reject((int) $row['id'], $admin->id, 'রশিদের ছবি অস্পষ্ট');

        $this->assertTrue($ok, $message);
        $rejected = (array) $this->topups->findById((int) $row['id']);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('রশিদের ছবি অস্পষ্ট', $rejected['reject_reason']);
        $this->assertEquals(self::START_BALANCE, $this->balance($user), 'A rejected request never credits');
    }

    public function testApprovedRequestCannotBeCancelledByItsOwner(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $this->service->approve((int) $row['id'], $admin->id);

        [$ok] = $this->service->cancel((int) $row['id'], $user->id);

        $this->assertFalse($ok, 'Approved money movements stay in the audit trail');
    }

    public function testClaimMovesPendingIntoReviewAndStampsTheReviewer(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');

        $claimedAt = $this->service->claim((int) $row['id'], $admin->id);

        $this->assertNotNull($claimedAt, 'A pending request is claimable');
        $claimed = (array) $this->topups->findById((int) $row['id']);
        $this->assertSame('review', $claimed['status']);
        $this->assertEquals($admin->id, (int) $claimed['claimed_by']);
        $this->assertSame($claimedAt, (string) $claimed['claimed_at']);
    }

    public function testClaimIsRefusedOnceTheRequestLeftTheQueue(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $this->service->claim((int) $row['id'], $admin->id);

        // A second reviewer opening the same page must not overwrite the claim.
        $this->assertNull($this->service->claim((int) $row['id'], $admin->id));
        $this->assertNull($this->service->claim(99999999, $admin->id), 'A missing id is not claimable');
    }

    public function testDashboardQueueCountTracksUnresolvedRequestsOnly(): void
    {
        // The admin dashboard card counts the whole table, so assert deltas from
        // a baseline rather than absolute numbers — the development database may
        // already hold requests this test knows nothing about.
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $before = $this->topups->stats();
        $beforeCount = $this->topups->unresolvedCount();

        $row = $this->submit($user, 'A');

        $pending = $this->topups->stats();
        $this->assertSame($beforeCount + 1, $this->topups->unresolvedCount());
        $this->assertSame($before['pending'] + 1, $pending['pending']);
        $this->assertSame($before['unresolved'] + 1, $pending['unresolved']);
        $this->assertEquals(
            $before['unresolved_amount'] + (float) $row['amount'],
            $pending['unresolved_amount'],
            'The card shows the money still waiting, not just the number of requests',
        );

        // Claiming moves it between the two open buckets without letting it off
        // the card — it is still unresolved money.
        $this->service->claim((int) $row['id'], $admin->id);
        $claimed = $this->topups->stats();
        $this->assertSame($before['pending'], $claimed['pending']);
        $this->assertSame($before['review'] + 1, $claimed['review']);
        $this->assertSame($before['unresolved'] + 1, $claimed['unresolved']);
        $this->assertSame($beforeCount + 1, $this->topups->unresolvedCount());

        // Only a decision clears it.
        $this->service->approve((int) $row['id'], $admin->id, '');
        $approved = $this->topups->stats();
        $this->assertSame($beforeCount, $this->topups->unresolvedCount());
        $this->assertSame($before['unresolved'], $approved['unresolved']);
        $this->assertEquals($before['unresolved_amount'], $approved['unresolved_amount']);
    }

    public function testReleasePutsAClaimedRequestBackInTheQueue(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $this->service->claim((int) $row['id'], $admin->id);

        [$ok, $message] = $this->service->release((int) $row['id'], $admin->id);

        $this->assertTrue($ok, $message);
        $released = (array) $this->topups->findById((int) $row['id']);
        $this->assertSame('pending', $released['status']);
        $this->assertNull($released['claimed_by'], 'The claim is cleared, not just the status');
        $this->assertNull($released['claimed_at']);
    }

    public function testReleaseOnlyAppliesToAClaimedRequest(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');

        [$ok] = $this->service->release((int) $row['id'], $admin->id);

        $this->assertFalse($ok, 'A request nobody claimed is already in the queue');
        $this->assertSame('pending', (string) $this->topups->findById((int) $row['id'])['status']);
    }

    public function testApproveAcceptsAClaimedRequest(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $this->service->claim((int) $row['id'], $admin->id);

        [$ok, $message] = $this->service->approve((int) $row['id'], $admin->id);

        $this->assertTrue($ok, 'review is still an open status: ' . $message);
        $this->assertEquals(self::START_BALANCE + self::AMOUNT, $this->balance($user));
        $approved = (array) $this->topups->findById((int) $row['id']);
        $this->assertSame('approved', $approved['status']);
        $this->assertEquals($admin->id, (int) $approved['claimed_by'], 'The claimer stays on the record');
    }

    public function testRejectAcceptsAClaimedRequest(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $this->service->claim((int) $row['id'], $admin->id);

        [$ok, $message] = $this->service->reject((int) $row['id'], $admin->id, 'রশিদের ছবি অস্পষ্ট');

        $this->assertTrue($ok, $message);
        $this->assertEquals(self::START_BALANCE, $this->balance($user), 'A rejected request never credits');
        $this->assertSame('rejected', (string) $this->topups->findById((int) $row['id'])['status']);
    }

    public function testAClaimedRequestStillBlocksASecondSubmission(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $this->service->claim((int) $row['id'], $admin->id);

        [$ok, , $errors] = $this->service->request(
            $user->id,
            $this->payload($this->ref('B')),
            $this->receiptUpload(),
        );

        $this->assertFalse($ok, 'A request under review is still an open request');
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testTheDecisionIsReportedToTheUser(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');

        $approved = $this->submit($user, 'A');
        $this->service->approve((int) $approved['id'], $admin->id);
        $notice = $this->latestNotification($user);
        $this->assertStringContainsString('রিচার্জ অনুমোদিত', (string) $notice['title']);
        $this->assertStringContainsString($this->ref('A'), (string) $notice['message'], 'The TrxID must be quotable back to support');

        $rejected = $this->submit($user, 'B');
        $this->service->reject((int) $rejected['id'], $admin->id, 'রশিদের ছবি অস্পষ্ট');
        $notice = $this->latestNotification($user);
        $this->assertStringContainsString('রিচার্জ অনুরোধ বাতিল', (string) $notice['title']);
        $this->assertStringContainsString($this->ref('B'), (string) $notice['message']);
        $this->assertStringContainsString('রশিদের ছবি অস্পষ্ট', (string) $notice['message'], 'The reason is what the user acts on');
    }

    public function testBulkApproveReportsMixedResults(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $first = $this->submit($user, 'A');

        $this->service->approve((int) $first['id'], $admin->id);
        $second = $this->submit($user, 'B');

        $result = $this->service->approveMany([(int) $first['id'], (int) $second['id']], $admin->id);

        // The already-approved row is reported as a failure, the pending one goes through.
        $this->assertSame(1, $result['approved']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['messages']);
    }

    // ---- Watermark --------------------------------------------------------

    public function testWatermarkWritesACopyAndLeavesTheOriginalAlone(): void
    {
        $row = $this->submit($this->makeUser(), 'A', [], $this->visibleUpload());
        $absolute = (string) $this->receipts->absolutePath((string) $row['receipt_path']);
        $originalBytes = (string) file_get_contents($absolute);

        $stamped = $this->receipts->watermark($absolute, 'ALL SEBA | TOPUP #1');

        $this->assertNotNull($stamped, 'A PNG receipt is watermarked');
        $this->assertNotSame($absolute, $stamped, 'The stamp goes on a copy, never on the evidence');
        $this->assertSame($originalBytes, (string) file_get_contents($absolute), 'The stored original is byte-identical');
        $this->assertNotSame($originalBytes, (string) file_get_contents($stamped), 'The copy really is stamped');
        $this->assertNotFalse(
            @imagecreatefromstring((string) file_get_contents($stamped)),
            'The stamped copy must still be a decodable image',
        );
    }

    public function testWatermarkIsCachedPerLabel(): void
    {
        $row = $this->submit($this->makeUser(), 'A', [], $this->visibleUpload());
        $absolute = (string) $this->receipts->absolutePath((string) $row['receipt_path']);

        $first = (string) $this->receipts->watermark($absolute, 'LABEL A');
        $again = (string) $this->receipts->watermark($absolute, 'LABEL A');
        $other = (string) $this->receipts->watermark($absolute, 'LABEL B');

        $this->assertSame($first, $again, 'The same label reuses the cached copy');
        $this->assertNotSame($first, $other, 'A different label gets its own copy');
    }

    public function testWatermarkDeclinesAFileItCannotDecode(): void
    {
        $probe = codecept_root_dir() . 'runtime/watermark-probe.txt';
        file_put_contents($probe, 'not an image');

        try {
            $this->assertNull($this->receipts->watermark($probe, 'LABEL'), 'A PDF or text file is served as-is');
        } finally {
            @unlink($probe);
        }
    }

    public function testDeleteRemovesDerivedWatermarkCopiesToo(): void
    {
        $row = $this->submit($this->makeUser(), 'A', [], $this->visibleUpload());
        $absolute = (string) $this->receipts->absolutePath((string) $row['receipt_path']);
        $userCopy = (string) $this->receipts->watermark($absolute, 'LABEL USER');
        $adminCopy = (string) $this->receipts->watermark($absolute, 'LABEL ADMIN');

        $this->receipts->delete((string) $row['receipt_path']);

        // The derived copies are in no database row, so a purge that only
        // unlinked the original would silently leak the stamped images.
        $this->assertFileDoesNotExist($absolute);
        $this->assertFileDoesNotExist($userCopy);
        $this->assertFileDoesNotExist($adminCopy);
    }

    public function testServedReceiptIsTheWatermarkedCopy(): void
    {
        $owner = $this->makeUser('a');
        $row = $this->submit($owner, 'A', [], $this->visibleUpload());
        $absolute = (string) $this->receipts->absolutePath((string) $row['receipt_path']);

        $response = $this->serve($owner, (int) $row['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'), 'Still a PNG, so the browser can show it');
        $this->assertNotSame((string) file_get_contents($absolute), (string) $response->getBody());
        $this->assertNotEmpty(
            glob(dirname($absolute) . '/wm-*.wm.*.png') ?: [],
            'A derived copy is cached beside the original',
        );
    }

    public function testOwnerAndAdminReceiveDistinctStamps(): void
    {
        $owner = $this->makeUser('a');
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($owner, 'A', [], $this->visibleUpload());
        $absolute = (string) $this->receipts->absolutePath((string) $row['receipt_path']);

        $ownerResponse = $this->serve($owner, (int) $row['id']);
        $adminResponse = $this->serve($admin, (int) $row['id']);

        $this->assertNotSame(
            (string) $ownerResponse->getBody(),
            (string) $adminResponse->getBody(),
            'The stamp records which side of the desk the copy left',
        );
        $this->assertCount(
            2,
            glob(dirname($absolute) . '/wm-*.wm.*.png') ?: [],
            'One cached copy per distinct label',
        );
    }

    // ---- Receipt access control ------------------------------------------

    public function testOwnerCanReadOwnReceiptAndAnotherUserCannot(): void
    {
        $owner = $this->makeUser('a');
        $other = $this->makeUser('b');
        $row = $this->submit($owner, 'A');
        $id = (int) $row['id'];

        $ownerResponse = $this->serve($owner, $id);
        $this->assertSame(200, $ownerResponse->getStatusCode());
        $this->assertSame('image/png', $ownerResponse->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $ownerResponse->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', $ownerResponse->getHeaderLine('Content-Disposition'));
        $this->assertSame('private, no-store', $ownerResponse->getHeaderLine('Cache-Control'));
        // Served bytes are the watermarked copy, so the length must track the
        // body actually written rather than the size of the stored original.
        $this->assertSame(
            (string) strlen((string) $ownerResponse->getBody()),
            $ownerResponse->getHeaderLine('Content-Length'),
        );

        // A non-owner gets exactly the same 404 as a missing id, so the endpoint
        // cannot be used to discover which top-up ids exist.
        $this->assertSame(404, $this->serve($other, $id)->getStatusCode());
        $this->assertSame(404, $this->serve($other, 99999999)->getStatusCode());
        $this->assertSame(
            (string) $this->serve($other, $id)->getBody(),
            (string) $this->serve($other, 99999999)->getBody(),
        );
    }

    public function testAdminCanReadAnyReceipt(): void
    {
        $owner = $this->makeUser('a');
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($owner, 'A');

        $this->assertSame(200, $this->serve($admin, (int) $row['id'])->getStatusCode());
    }

    public function testPlainUserIsNotTreatedAsAdmin(): void
    {
        $owner = $this->makeUser('a');
        $other = $this->makeUser('b');
        $row = $this->submit($owner, 'A');

        $this->assertFalse($other->canAccessAdmin());
        $this->assertSame(404, $this->serve($other, (int) $row['id'])->getStatusCode());
    }

    public function testMissingReceiptFileYields404NotAnError(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('adm', 'admin');
        $row = $this->submit($user, 'A');
        $id = (int) $row['id'];

        $this->service->purgeReceipt($id);

        $this->assertSame(404, $this->serve($user, $id)->getStatusCode());
    }

    public function testAbsolutePathRefusesTraversal(): void
    {
        $this->assertNull($this->receipts->absolutePath('../../.env'));
        $this->assertNull($this->receipts->absolutePath('/etc/passwd'));
        $this->assertNull($this->receipts->absolutePath(null));
        $this->assertNull($this->receipts->absolutePath(''));
    }

    /** Drive ReceiptAction directly — it only needs the identity attribute. */
    private function serve(Identity $identity, int $topupId): \Psr\Http\Message\ResponseInterface
    {
        $psr17 = new Psr17Factory();
        $action = new ReceiptAction($this->topups, $this->receipts, $psr17, $psr17);

        $request = (new ServerRequest('GET', '/recharge/receipt/' . $topupId))
            ->withAttribute('identity', $identity);

        $route = $this->currentRoute($topupId);

        return $action($request, $route);
    }

    private function currentRoute(int $topupId): CurrentRoute
    {
        $route = new CurrentRoute();
        $route->setRouteWithArguments(
            Route::get('/recharge/receipt/{id}')->name('recharge-receipt'),
            ['id' => (string) $topupId],
        );

        return $route;
    }
}
