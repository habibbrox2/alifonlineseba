<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Notification\Channel\WebPushChannel;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Notification\Push\VapidKeys;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The account-wide push switch, at both gates it has to work at.
 *
 * Turning notifications off in /profile has to stop a push twice: once before
 * a job is queued (so the queue does not fill with rows nobody will ever
 * deliver) and once at the door of `WebPushChannel::send()`, because a job
 * enqueued *before* the switch was flipped is still sitting in the table when
 * the user presses it. The admin fan-out needs the first gate too — staff work
 * from a browser, so `webpush` is on the admin channel list, and an admin's own
 * switch is exactly as binding as a user's.
 *
 * Throwaway rows only — removed again in _after().
 */
final class WebPushOptOutTest extends \Codeception\Test\Unit
{
    /**
     * A subscription whose key material cannot be used.
     *
     * The control test only has to prove the request got *past* the opt-out
     * gate and as far as encryption, and an unusable key fails exactly there —
     * deterministically, offline, with no push service involved. A valid key
     * would instead curl a real endpoint, which makes the assertion depend on
     * somebody else's network.
     */
    private const UNUSABLE_ENDPOINT = 'https://push.example.invalid/push/no-such-browser';

    private ConnectionInterface $db;
    private UserRepository $users;
    private PushSubscriptionRepository $subs;
    private WebPushChannel $channel;
    private NotificationManager $notify;

    /** @var int[] */
    private array $userIds = [];
    private string $startedAt = '';
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->subs = new PushSubscriptionRepository($this->db);
        $this->channel = new WebPushChannel($this->subs, $this->users);

        $this->notify = new NotificationManager(
            new NotificationRepository($this->db),
            new QueueRepository($this->db),
            new TemplateRenderer($this->db, new SettingsRepository($this->db)),
            $this->users,
            $this->db,
        );

        // Admin fan-out lands on users outside our own list (the real admin
        // account gets every broadcast), so cleanup also sweeps by time window.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);

        $this->loadVapidKeys();
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%push_subscription}}', ['user_id' => $id])->execute();
            $this->db
                ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :u)')
                ->bindValue(':u', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
        $this->db
            ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[created_at]] >= :from)')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification_queue}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();

        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if ($this->previousEnv[$name] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $this->previousEnv[$name];
            }
        }
        $this->previousEnv = [];
    }

    /**
     * Make the VAPID key pair visible to Env for the duration of this test.
     *
     * tests/bootstrap.php calls Environment::prepare() but deliberately never
     * loads .env: the suites run against the live development database, and
     * provider credentials are not something the whole suite should inherit.
     * Web Push is the one feature whose behaviour *is* the credentials being
     * present — with no keys, `send()` reports itself unavailable and every
     * queue gate short-circuits, so the opt-out assertions would pass for the
     * wrong reason and prove nothing.
     *
     * createArrayBacked() returns the parsed file instead of writing it into
     * $_ENV, so only the two variables this test needs are ever set, and
     * _after() puts them back exactly as it found them.
     */
    private function loadVapidKeys(): void
    {
        $this->previousEnv = [
            'VAPID_SUBJECT' => $_ENV['VAPID_SUBJECT'] ?? null,
            'VAPID_PRIVATE_KEY' => $_ENV['VAPID_PRIVATE_KEY'] ?? null,
        ];

        if (VapidKeys::fromEnv() !== null) {
            return;
        }

        /** @var array<string, string> $values */
        $values = \Dotenv\Dotenv::createArrayBacked(dirname(__DIR__, 2))->safeLoad();
        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if (isset($values[$name]) && $values[$name] !== '') {
                $_ENV[$name] = $values[$name];
            }
        }

        assertTrue(
            VapidKeys::fromEnv() !== null,
            'This test cannot assert anything about push without VAPID keys in .env.'
                . ' Generate a pair with: php scripts/generate-vapid-keys.php mailto:ops@example.com'
        );
    }

    private function makeUser(string $tag, bool $pushEnabled = true): int
    {
        $id = $this->users->create([
            'username' => 'wo_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 8),
            'phone' => '6' . substr(md5(uniqid('', true)), 0, 9),
            'email' => null,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
        ]);
        $this->users->setPushEnabled($id, $pushEnabled);
        $this->userIds[] = $id;
        return $id;
    }

    private function subscribe(int $userId, string $endpoint): void
    {
        $this->subs->subscribe($endpoint, 'bm90LWEta2V5', 'bm90LWEtc2VjcmV0MTI=', $userId, 'Chrome/120 (Windows NT 10.0)');
    }

    // ---- The gate in send() ------------------------------------------------

    /**
     * A job already in the queue when the user presses "off" must die at the
     * door. Reported as a *success*: the user asked for exactly this, and
     * retrying a job whose only purpose is to respect an opt-out is how you
     * end up with people muting the site instead.
     */
    public function testOptedOutAccountIsSkippedBySend(): void
    {
        $userId = $this->makeUser('off', false);
        $this->subscribe($userId, self::UNUSABLE_ENDPOINT);

        $result = $this->channel->send($userId, ['title' => 'হেড', 'body' => 'বডি']);

        assertTrue($result->ok, 'an opted-out account is a success, not a failure');
        assertSame('', $result->error, 'nothing may be attempted, so nothing may go wrong');
        assertFalse($result->retryable, 'an opt-out must never be retried');
    }

    /**
     * The control for the test above: the same fixture with the switch on must
     * get as far as encryption. Without it, the test above would also pass if
     * the subscription had simply gone missing.
     */
    public function testSameSubscriptionIsAttemptedWhenEnabled(): void
    {
        $userId = $this->makeUser('on');
        $this->subscribe($userId, self::UNUSABLE_ENDPOINT);

        $result = $this->channel->send($userId, ['title' => 'হেড', 'body' => 'বডি']);

        assertFalse($result->ok, 'with the switch on, this subscription must be attempted and must fail');
        assertTrue($result->retryable, 'an unusable key is the server-side kind of failure, not a dead browser');
        // send() aggregates over the fan-out, so the per-subscription reason is
        // summarised rather than surfaced — sendToSubscription() reports it in
        // full when app:webpush:check runs it for one endpoint.
        assertSame(
            'Web Push send failed for all subscriptions.',
            $result->error,
            'the send must have been attempted — that is what proves the opt-out gate, not a missing row, caused the skip'
        );
    }

    /**
     * An account with the switch off and no subscriptions at all is still a
     * success: "you have nothing to receive" is not an error.
     */
    public function testAccountWithNoSubscriptionsIsASuccess(): void
    {
        $userId = $this->makeUser('bare');

        $result = $this->channel->send($userId, ['title' => 'হেড', 'body' => 'বডি']);

        assertTrue($result->ok);
        assertSame('', $result->error);
    }

    // ---- The gate in the queue --------------------------------------------

    public function testOptedOutUserGetsNoWebpushJob(): void
    {
        $userId = $this->makeUser('qoff', false);

        $this->notify->dispatch(
            NotificationEvent::TOPUP_APPROVED,
            $userId,
            ['amount' => '500.00', 'method' => 'bKash', 'reference' => 'WO1', 'topup_id' => 31],
            '/recharge',
        );

        $channels = $this->queuedChannels($userId);
        assertNotContains('webpush', $channels, 'no job may be queued for an account that turned push off');
        assertContains('fcm', $channels, 'only webpush is gated — fcm is unaffected');
        assertTrue(
            VapidKeys::fromEnv() !== null,
            'this test needs VAPID keys configured, otherwise "no webpush" proves nothing'
        );
    }

    public function testEnabledUserGetsWebpushJob(): void
    {
        $userId = $this->makeUser('qon');

        $this->notify->dispatch(
            NotificationEvent::TOPUP_APPROVED,
            $userId,
            ['amount' => '500.00', 'method' => 'bKash', 'reference' => 'WO2', 'topup_id' => 32],
            '/recharge',
        );

        assertContains('webpush', $this->queuedChannels($userId));
    }

    /**
     * The admin arm, which is a separate code path: the admin loop
     * deliberately skips wantsChannel(), so the webpush gate has to be
     * re-applied there by hand or an admin who turned notifications off keeps
     * getting every broadcast.
     */
    public function testAdminOptOutSuppressesOnlyWebpush(): void
    {
        $adminOff = $this->makeUser('admoff', false);
        $this->db->createCommand()->update('{{%user}}', ['role' => 'admin'], ['id' => $adminOff])->execute();
        $adminOn = $this->makeUser('admon', true);
        $this->db->createCommand()->update('{{%user}}', ['role' => 'admin'], ['id' => $adminOn])->execute();
        $payer = $this->makeUser('payer');

        $this->notify->dispatch(NotificationEvent::TOPUP_REQUESTED, $payer, [
            'amount' => '100.00', 'reference' => 'WO3', 'topup_id' => 33, 'user_id' => $payer,
        ]);

        $offChannels = $this->queuedChannels($adminOff);
        assertContains('telegram', $offChannels, 'the admin fan-out must have run at all');
        assertNotContains('webpush', $offChannels, 'an admin who turned push off must not be queued for it');

        assertContains('webpush', $this->queuedChannels($adminOn), 'an admin who left push on still gets it');
        assertContains('webpush', $this->queuedChannels($payer));
    }

    // ---- Off then on again -------------------------------------------------

    /**
     * The round trip the settings page exists for. Turning the account switch
     * off deactivates every browser; turning it back on must revive the same
     * endpoints rather than making the user re-grant permission in each one,
     * and above all must not leave a duplicate row per endpoint.
     */
    public function testOffThenOnRevivesTheSameEndpoints(): void
    {
        $userId = $this->makeUser('cycle');
        $this->subscribe($userId, 'https://push.example.invalid/push/laptop');
        $this->subscribe($userId, 'https://push.example.invalid/push/phone');

        assertSame(2, $this->subs->countActiveForUser($userId));

        $dropped = $this->subs->deactivateAllForUser($userId);
        assertSame(2, $dropped, 'the account-wide switch must deactivate every browser');
        assertSame(0, $this->subs->countActiveForUser($userId));
        // The device list still shows them: "you turned this off in March" is
        // a question the settings page has to be able to answer.
        assertSame(2, count($this->subs->forUser($userId)));

        $this->users->setPushEnabled($userId, true);
        $this->subscribe($userId, 'https://push.example.invalid/push/laptop');
        $this->subscribe($userId, 'https://push.example.invalid/push/phone');

        assertSame(2, $this->subs->countActiveForUser($userId), 're-subscribing must revive, not duplicate');
        assertSame(
            2,
            (int) $this->db
                ->createCommand('SELECT COUNT(*) FROM {{%push_subscription}} WHERE [[user_id]] = :u')
                ->bindValue(':u', $userId)
                ->queryScalar(),
            'the endpoint UNIQUE index must hold across the round trip'
        );
    }

    /**
     * @return string[]
     */
    private function queuedChannels(int $userId): array
    {
        return array_map(
            'strval',
            $this->db
                ->createCommand('SELECT [[channel]] FROM {{%notification_queue}} WHERE [[user_id]] = :u')
                ->bindValue(':u', $userId)
                ->queryColumn(),
        );
    }
}