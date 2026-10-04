<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\ApiTokenRepository;
use App\Notification\MessageTemplates;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use ReflectionClass;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The notification core (audit Phase 1): dispatch writes the in-app row
 * synchronously and one queue row per channel, idempotently; the worker's
 * claim/retry/dead-letter state machine moves rows through their lifecycle;
 * API tokens authenticate machines without a session.
 *
 * Throwaway rows only — removed again in _after().
 */
final class NotificationCoreTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private QueueRepository $queue;
    private NotificationManager $notify;

    /** @var int[] */
    private array $userIds = [];
    private string $startedAt = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->queue = new QueueRepository($this->db);

        $notifications = new NotificationRepository($this->db);
        $renderer = new TemplateRenderer($this->db, new SettingsRepository($this->db));
        $this->notify = new NotificationManager($notifications, $this->queue, $renderer, $this->users, $this->db);

        // Admin fan-out lands on users outside our own list (the real admin
        // account gets every broadcast), so cleanup also sweeps by time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
    }

    protected function _after(): void
    {
        // Queue rows created during THIS test, wherever they landed — the
        // deliveries must go first (FK).
        $this->db
            ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[created_at]] >= :from)')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification_queue}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();

        foreach ($this->userIds as $id) {
            // Deliveries reference queue rows (FK), so they go first.
            $this->db
                ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :u)')
                ->bindValue(':u', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%api_token}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
        // In-app fan-out rows that landed on users outside our list.
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
    }

    private function makeUser(string $tag): int
    {
        $id = $this->users->create([
            'username' => 'nt_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 8),
            'phone' => '7' . substr(md5(uniqid('', true)), 0, 9),
            'email' => null,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
        ]);
        $this->userIds[] = $id;
        return $id;
    }

    // ---- Dispatch ---------------------------------------------------------

    public function testDispatchWritesInAppRowAndChannels(): void
    {
        $userId = $this->makeUser('a');

        $this->notify->dispatch(
            NotificationEvent::TOPUP_APPROVED,
            $userId,
            ['amount' => '500.00', 'method' => 'bKash', 'reference' => 'TRX123', 'topup_id' => 42],
            '/recharge',
        );

        $row = (array) $this->db
            ->createCommand('SELECT * FROM {{%notification}} WHERE [[user_id]] = :u ORDER BY [[id]] DESC LIMIT 1')
            ->bindValue(':u', $userId)
            ->queryOne();

        assertSame('রিচার্জ অনুমোদিত — ব্যালেন্স যোগ হয়েছে', $row['title']);
        assertStringContainsString('500.00', (string) $row['message']);
        assertSame('topup.approved', (string) $row['event']);
        assertSame('/recharge', (string) $row['link']);
        assertSame('success', (string) $row['type']);

        // FCM is queued (the row is what Phase 2 consumes); the user never
        // gets telegram (admins-only, audit §11.3).
        $channels = $this->db
            ->createCommand('SELECT [[channel]] FROM {{%notification_queue}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $userId)
            ->queryColumn();
        assertContains('fcm', $channels);
        assertNotContains('telegram', $channels);
    }

    public function testDispatchIsIdempotentOnRerun(): void
    {
        $userId = $this->makeUser('b');
        $params = ['amount' => '250.00', 'method' => 'bKash', 'reference' => 'TRXDUPE', 'topup_id' => 7, 'user_id' => $userId];

        // topup.approved queues fcm for the user — the replayed business
        // action (double-clicked approve) must not enqueue a second row.
        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, $params);
        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, $params);

        $count = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification_queue}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $userId)
            ->queryScalar();
        assertSame(1, $count, 'The replayed dispatch must enqueue nothing new (dedupe key).');
    }

    public function testTopupRequestedFansOutToAdmins(): void
    {
        $adminId = $this->makeUser('adm');
        $this->db->createCommand()->update('{{%user}}', ['role' => 'admin'], ['id' => $adminId])->execute();

        $userId = $this->makeUser('payer');
        $this->notify->dispatch(NotificationEvent::TOPUP_REQUESTED, $userId, [
            'amount' => '100.00', 'reference' => 'TRXADM', 'topup_id' => 9, 'user_id' => $userId,
        ]);

        $adminNotified = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification}} WHERE [[user_id]] = :u AND [[event]] = :e')
            ->bindValues([':u' => $adminId, ':e' => NotificationEvent::TOPUP_REQUESTED])
            ->queryScalar();

        assertSame(1, $adminNotified, 'The admin must learn a payment is waiting.');
    }

    // ---- Templates --------------------------------------------------------

    public function testEveryEventChannelPairHasCopy(): void
    {
        // Every event, not just one: the channel matrix grew a column (webpush)
        // and a check that only looked at topup.approved would have called the
        // whole thing covered while three admin-only events had no copy at all.
        //
        // Read off the class rather than a hand-written list: a copied list is
        // a list that stops covering new events the day someone adds the 16th
        // constant, which is the same blind spot this test is here to close.
        $events = array_values(array_filter(
            (new ReflectionClass(NotificationEvent::class))->getConstants(),
            'is_string'
        ));
        assertGreaterThanOrEqual(15, count($events), 'The event catalogue must not silently shrink.');

        foreach ($events as $event) {
            $seeded = MessageTemplates::forEvent($event);
            foreach (NotificationEvent::spec($event)['channels'] as $channel) {
                // webpush has no copy of its own by design: TemplateRenderer
                // borrows the fcm copy, then the telegram copy for the events
                // that are admins-only (their recipients are staff, on the web).
                $template = $seeded[$channel]
                    ?? ($channel === 'webpush' ? ($seeded['fcm'] ?? $seeded['telegram'] ?? null) : null);
                assertTrue($template !== null, "{$event}/{$channel} must have copy.");
                assertTrue(
                    ($template['title'] ?? '') !== '' && ($template['body'] ?? '') !== '',
                    "{$event}/{$channel} must have words, not an empty shell."
                );
            }
        }
        $rows = MessageTemplates::seedRows();
        assertGreaterThanOrEqual(20, count($rows), 'The seed covers every pair (' . count($rows) . ' seeded).');
    }

    public function testFcmAlwaysBringsWebpushAlong(): void
    {
        // The other direction of the same trap. The copy check above only asks
        // whether a listed channel has words; it is happy about a channel list
        // that quietly never mentions the browser. `fcm` and `webpush` share an
        // audience — same people, two clients — so an event that reaches the
        // Android app and not the browser is a bug even though nothing is
        // missing a template. Admins are the motivating case: they never
        // install the APK, so a webpush gap is the only way they get told.
        //
        // The converse is deliberately not asserted: topup.requested,
        // topup.cancelled and system.alert reach staff over the browser and
        // have no fcm arm on purpose.
        foreach ((new ReflectionClass(NotificationEvent::class))->getConstants() as $event) {
            $channels = NotificationEvent::spec($event)['channels'];
            if (in_array('fcm', $channels, true)) {
                assertContains('webpush', $channels, "{$event} pushes to the app, so it must push to the browser too.");
            }
        }
    }

    public function testRendererInterpolatesPlaceholders(): void
    {
        $renderer = new TemplateRenderer($this->db, new SettingsRepository($this->db));
        $rendered = $renderer->render(NotificationEvent::TOPUP_APPROVED, 'in_app', [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'TRXXY',
        ]);

        assertStringContainsString('500.00', $rendered['body']);
        assertStringContainsString('bKash', $rendered['body']);
        assertStringContainsString('TRXXY', $rendered['body']);
        assertSame('রিচার্জ অনুমোদিত — ব্যালেন্স যোগ হয়েছে', $rendered['title']);
    }

    // ---- Queue lifecycle ---------------------------------------------------

    public function testClaimBatchMovesRowsToProcessing(): void
    {
        $userId = $this->makeUser('q');
        $id = $this->enqueueDirect($userId, 'fcm', 'TRXQ1');

        // The claim is oldest-first over the shared dev database, which may
        // hold older due rows from other tests — so keep claiming (as the cron
        // worker does tick after tick) until OUR row surfaces or the queue
        // drains, then check it left the queued state.
        $claimed = false;
        for ($tick = 0; $tick < 50; $tick++) {
            $jobs = $this->queue->claimBatch(200);
            foreach ($jobs as $job) {
                if ((int) $job['id'] === $id) {
                    assertSame('processing', (string) $job['status']);
                    $claimed = true;
                    break 2;
                }
            }
            if ($jobs === []) {
                break;
            }
        }

        assertTrue($claimed, 'Our queued row must be claimed by a batch.');
        assertSame('processing', (string) ($this->queue->findById($id) ?? [])['status']);
    }

    public function testRetryBackoffThenDeadLetter(): void
    {
        $userId = $this->makeUser('r');
        $id = $this->enqueueDirect($userId, 'fcm', 'TRXR1');
        $this->queue->claimBatch(10);

        $this->queue->markFailed((int) $id, 'FCM timeout', retryable: true, backoffBase: 60);
        $row = (array) $this->queue->findById((int) $id);
        assertSame('queued', (string) $row['status'], 'A retryable failure goes back to the queue.');
        assertSame(1, (int) $row['attempts']);

        // Exhaust the remaining attempts.
        $this->queue->claimBatch(10);
        $this->queue->markFailed((int) $id, 'again', retryable: true, backoffBase: 60);
        $this->queue->claimBatch(10);
        $this->queue->markFailed((int) $id, 'final', retryable: true, backoffBase: 60);

        $row = (array) $this->queue->findById((int) $id);
        assertSame('dead', (string) $row['status'], 'Exhausted retries dead-letter.');
        assertSame(3, (int) $row['attempts']);
    }

    public function testPermanentFailureDeadLettersImmediately(): void
    {
        $userId = $this->makeUser('p');
        $id = $this->enqueueDirect($userId, 'fcm', 'TRXP1');
        $this->queue->claimBatch(10);

        $this->queue->markFailed((int) $id, 'UNREGISTERED token', retryable: false, backoffBase: 60);

        $row = (array) $this->queue->findById((int) $id);
        assertSame('dead', (string) $row['status'], 'A dead token must never be retried.');
    }

    public function testRequeueRevivesADeadRow(): void
    {
        $userId = $this->makeUser('d');
        $id = $this->enqueueDirect($userId, 'fcm', 'TRXD1');
        $this->queue->claimBatch(10);
        $this->queue->markFailed((int) $id, 'blip', retryable: false, backoffBase: 60);
        assertSame('dead', (string) $this->queue->findById((int) $id)['status']);

        assertTrue($this->queue->requeue((int) $id));
        $row = (array) $this->queue->findById((int) $id);
        assertSame('queued', (string) $row['status']);
        assertSame(0, (int) $row['attempts'], 'The retry budget resets.');
    }

    public function testMarkSentRecordsDeliveryAndProviderId(): void
    {
        $userId = $this->makeUser('s');
        $id = $this->enqueueDirect($userId, 'fcm', 'TRXS1');
        $this->queue->claimBatch(10);

        $this->queue->markSent((int) $id, 'projects/x/messages/' . uniqid('', true), 42);

        $row = (array) $this->queue->findById((int) $id);
        assertSame('sent', (string) $row['status']);

        $delivery = (array) $this->db
            ->createCommand('SELECT * FROM {{%notification_delivery}} WHERE [[queue_id]] = :q ORDER BY [[id]] DESC LIMIT 1')
            ->bindValue(':q', $id)
            ->queryOne();
        assertSame('sent', (string) $delivery['status']);
        assertSame(42, (int) $delivery['latency_ms']);
    }

    // ---- API tokens (Phase 1.5) -------------------------------------------

    public function testTokenIssueResolveAndRevoke(): void
    {
        $userId = $this->makeUser('t');
        $tokens = new ApiTokenRepository($this->db);

        $pair = $tokens->issue($userId, 'Unit test device');
        assertTrue($pair['token'] !== '' && $pair['refresh'] !== '');

        // Plaintext must never appear in the database.
        $hashes = $this->db
            ->createCommand('SELECT [[token_hash]] FROM {{%api_token}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $userId)
            ->queryColumn();
        foreach ($hashes as $hash) {
            assertSame(false, str_contains((string) $hash, $pair['token']), 'Only hashes are stored.');
        }

        assertSame($userId, $tokens->resolve($pair['token']));

        assertTrue($tokens->revoke($pair['token']));
        assertSame(null, $tokens->resolve($pair['token']), 'A revoked token must not resolve.');
    }

    public function testTokenRejectsGarbage(): void
    {
        $tokens = new ApiTokenRepository($this->db);
        assertSame(null, $tokens->resolve('not-a-token'));
        assertSame(null, $tokens->resolve('a.b'));
        assertSame(null, $tokens->resolve('..'));
        assertSame(null, $tokens->rotate('garbage'));
    }

    public function testRotateRevokesTheOldRefreshToken(): void
    {
        $userId = $this->makeUser('o');
        $tokens = new ApiTokenRepository($this->db);
        $pair = $tokens->issue($userId);

        $rotated = $tokens->rotate($pair['refresh']);
        assertTrue($rotated !== null);
        assertSame(null, $tokens->resolve($pair['refresh']), 'Rotation must kill the presented refresh token.');
        assertSame($userId, $tokens->resolve($rotated['token']));
    }

    // ---- Helpers -----------------------------------------------------------

    private function enqueueDirect(int $userId, string $channel, string $reference): int
    {
        $id = $this->queue->enqueue([
            'event' => NotificationEvent::TOPUP_APPROVED,
            'user_id' => $userId,
            'channel' => $channel,
            'max_attempts' => 3,
            'payload' => ['title' => 't', 'body' => 'b', 'data' => ['reference' => $reference]],
            'dedupe_key' => sha1('test|' . $userId . '|' . $channel . '|' . $reference . '|' . uniqid('', true)),
        ]);
        assertSame(true, $id !== null);
        return (int) $id;
    }
}
