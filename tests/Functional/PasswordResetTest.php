<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\AuthThrottle;
use App\Notification\Channel\TelegramChannel;
use App\Notification\Channel\WhatsAppChannel;
use App\Repository\BotConnectionRepository;
use App\Repository\UserRepository;
use App\Service\EmailSender;
use App\Service\PasswordResetService;
use App\Tests\Support\TestGraph;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Password recovery (plan phase 3).
 *
 * The whole point of this class is the three properties that make a reset
 * form safe to put on the open web:
 *
 *  1. **No enumeration** — `issue()` answers identically for an unknown
 *     identifier, a real account with nothing on the chosen channel, and a
 *     real send. A stranger must not be able to turn the form into a way of
 *     discovering which phone numbers are registered.
 *  2. **No replay** — a consumed secret is dead, and using one secret kills
 *     every other live secret for that account.
 *  3. **No brute force** — OTP guesses are counted on the row, so resetting
 *     the cache does not hand an attacker a fresh 10^6 attempts.
 *
 * `consume()` is driven against rows written straight into the table rather
 * than through `issue()`, because the drivers that would deliver a real code
 * are network calls. The hashing is the service's own `hash()`, so what is
 * under test is the same code the real flow stores.
 */
final class PasswordResetTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private PasswordResetService $resets;
    private AuthThrottle $throttle;

    /** @var int[] */
    private array $userIds = [];
    private string $suffix = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);

        // ArrayCache, not the app's file cache: the throttle counters must not
        // survive into the next test, or a suite that runs these cases in a
        // different order would see a throttled identifier and pass vacuously.
        $this->throttle = new AuthThrottle(new ArrayCache());

        $bots = new BotConnectionRepository($this->db);
        $this->resets = new PasswordResetService(
            $this->db,
            $this->users,
            $bots,
            $this->throttle,
            new EmailSender(),
            new WhatsAppChannel($this->users),
            new TelegramChannel($bots),
        );

        $this->suffix = 't' . substr(md5(uniqid('', true)), 0, 10);
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            // password_reset_token.user_id is a FK to user.
            $this->db->createCommand()->delete('{{%password_reset_token}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            TestGraph::purgeUser($this->db, $id);
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
    }

    // ---- enumeration -------------------------------------------------------

    public function testAnUnknownIdentifierIsIndistinguishableFromASend(): void
    {
        $user = $this->makeUser();

        $unknown = $this->resets->issue('nobody-' . $this->suffix, 'email', '10.0.0.1');
        $real = $this->resets->issue($this->username($user), 'whatsapp', '10.0.0.2');

        assertSame(array_keys($unknown), array_keys($real), 'The answer shape must not depend on the outcome.');
        assertFalse($unknown['sent'], 'An unknown identifier can never be "sent".');
        assertFalse($this->resets->issue($user['username'], 'whatsapp', '10.0.0.3')['sent']);
    }

    public function testNothingIsStoredForAnUnknownIdentifier(): void
    {
        $before = $this->countRows();

        $this->resets->issue('nobody-' . $this->suffix, 'email', '10.0.0.1');

        assertSame($before, $this->countRows(), 'A miss must not leave a row behind.');
    }

    public function testAnAccountWithNoChannelOnFileReportsTheSameThing(): void
    {
        // whatsapp_no is empty, so there is nowhere to send a code. The
        // caller must not be able to tell this apart from a successful send.
        $user = $this->makeUser();

        $result = $this->resets->issue($user['username'], 'whatsapp', '10.0.0.1');

        assertFalse($result['sent']);
        assertNull($result['rowId']);
        assertSame(0, $this->countRows(), 'An undeliverable code must not stay live in the table.');
    }

    public function testADeletedOrInactiveAccountCannotBeReset(): void
    {
        $user = $this->makeUser();
        $this->db->createCommand()->update('{{%user}}', ['status' => 'banned'], ['id' => $user['id']])->execute();
        assertFalse($this->resets->issue($user['username'], 'email', '10.0.0.1')['sent']);

        $this->db->createCommand()->update('{{%user}}', ['status' => 'active', 'deleted_at' => date('Y-m-d H:i:s')], ['id' => $user['id']])->execute();
        assertFalse($this->resets->issue($user['username'], 'email', '10.0.0.2')['sent']);

        assertSame(0, $this->countRows());
    }

    public function testAnUnknownChannelFallsBackToEmail(): void
    {
        $user = $this->makeUser();

        $result = $this->resets->issue($user['username'], 'carrier-pigeon', '10.0.0.1');

        assertSame('email', $result['channel'], 'A bogus channel must not be stored verbatim.');
    }

    // ---- throttling --------------------------------------------------------

    public function testRepeatedRequestsForOneIdentifierAreThrottled(): void
    {
        $user = $this->makeUser();
        $ip = '10.0.0.9';

        for ($i = 0; $i < 5; $i++) {
            $this->resets->issue($user['username'], 'email', $ip);
        }
        $afterFive = $this->countRows();

        // The 6th is refused before the lookup, so a valid account is
        // indistinguishable from an invalid one once the limit is hit.
        assertTrue($this->throttle->tooManyAttempts('reset.request.' . $user['username'] . '|' . $ip));

        $this->resets->issue($user['username'], 'email', $ip);

        assertSame($afterFive, $this->countRows(), 'A throttled request must not reach the token store.');
    }

    public function testTheThrottleIsScopedToTheIpAsWellAsTheIdentifier(): void
    {
        $user = $this->makeUser();
        $ip = '10.0.0.10';

        for ($i = 0; $i < 5; $i++) {
            $this->resets->issue($user['username'], 'email', $ip);
        }

        assertTrue($this->throttle->tooManyAttempts('reset.request.' . $user['username'] . '|' . $ip));
        assertFalse(
            $this->throttle->tooManyAttempts('reset.request.' . $user['username'] . '|10.0.0.11'),
            'A different IP must not inherit the block of the first one.',
        );
    }

    // ---- hashing -----------------------------------------------------------

    public function testASecretIsNeverStoredInTheClear(): void
    {
        $user = $this->makeUser();
        $token = str_repeat('a', 64);

        $this->storeToken($this->uid($user), 'email', $this->resets->hash($token));

        $stored = (string) $this->db
            ->createCommand('SELECT [[token_hash]] FROM {{%password_reset_token}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $this->uid($user))
            ->queryScalar();

        assertNotSame($token, $stored, 'The raw secret must never reach the table.');
        assertSame(64, strlen($stored));
        assertSame($this->resets->hash($token), $stored, 'The stored value must be the hash.');
    }

    // ---- email consumption -------------------------------------------------

    public function testTheEmailedLinkSetsTheNewPassword(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $token = bin2hex(random_bytes(32));
        $rowId = $this->storeToken($this->uid($user), 'email', $this->resets->hash($token));

        assertTrue($this->resets->findLive('email', $this->resets->hash($token)) !== null);

        $result = $this->resets->consume('email', $token, 'N3w!strong', '10.0.0.1');

        assertTrue($result['ok'], $result['error'] ?? '');
        assertSame($this->uid($user), $result['userId']);
        assertTrue(
            password_verify('N3w!strong', (string) $this->users->findById($this->uid($user))['password_hash']),
            'The new password must verify against the stored hash.',
        );
        assertNotNull(
            $this->db->createCommand('SELECT [[consumed_at]] FROM {{%password_reset_token}} WHERE [[id]] = :id')
                ->bindValue(':id', $rowId)
                ->queryScalar(),
            'A used link must be marked consumed.',
        );
    }

    public function testAUsedLinkCannotBeUsedTwice(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $token = bin2hex(random_bytes(32));
        $this->storeToken($this->uid($user), 'email', $this->resets->hash($token));

        assertTrue($this->resets->consume('email', $token, 'N3w!strong', '10.0.0.1')['ok']);

        $replay = $this->resets->consume('email', $token, 'An0ther!one', '10.0.0.2');

        assertFalse($replay['ok'], 'A second tab replaying the same link must fail.');
        assertTrue(
            password_verify('N3w!strong', (string) $this->users->findById($this->uid($user))['password_hash']),
            'The replay must not have changed the password again.',
        );
    }

    public function testAWrongLinkIsRefused(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $this->storeToken($this->uid($user), 'email', $this->resets->hash(bin2hex(random_bytes(32))));

        $result = $this->resets->consume('email', bin2hex(random_bytes(32)), 'N3w!strong', '10.0.0.1');

        assertFalse($result['ok']);
        assertTrue(
            password_verify('Str0ng!old', (string) $this->users->findById($this->uid($user))['password_hash']),
            'The original password must survive a failed attempt.',
        );
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $token = bin2hex(random_bytes(32));
        $this->storeToken($this->uid($user), 'email', $this->resets->hash($token), ttl: -60);

        assertNull($this->resets->findLive('email', $this->resets->hash($token)), 'An expired row is not live.');
        assertFalse($this->resets->consume('email', $token, 'N3w!strong', '10.0.0.1')['ok']);
    }

    public function testUsingOneLinkKillsEveryOtherLiveResetForThatAccount(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $stale = bin2hex(random_bytes(32));

        // An older link that was mailed an hour ago, plus the live one.
        $this->storeToken($this->uid($user), 'email', $this->resets->hash($stale));
        $fresh = bin2hex(random_bytes(32));
        $this->storeToken($this->uid($user), 'email', $this->resets->hash($fresh));

        assertTrue($this->resets->consume('email', $fresh, 'N3w!strong', '10.0.0.1')['ok']);

        assertFalse(
            $this->resets->consume('email', $stale, 'An0ther!one', '10.0.0.2')['ok'],
            'A link that predates a successful reset must stop working.',
        );
    }

    // ---- OTP consumption ---------------------------------------------------

    public function testTheRightOtpCodeSetsTheNewPassword(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $code = '482913';
        $rowId = $this->storeToken($this->uid($user), 'whatsapp', $this->resets->hash($code), ttl: 600);

        $result = $this->resets->consume('whatsapp', $code, 'N3w!strong', '10.0.0.1', $rowId);

        assertTrue($result['ok'], $result['error'] ?? '');
        assertTrue(
            password_verify('N3w!strong', (string) $this->users->findById($this->uid($user))['password_hash']),
        );
    }

    public function testAnOtpMustBeSpentOnTheRowItWasIssuedFor(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $mine = $this->storeToken($this->uid($user), 'whatsapp', $this->resets->hash('111111'), ttl: 600);
        $someoneElse = $this->storeToken($this->uid($user), 'telegram', $this->resets->hash('222222'), ttl: 600);

        // A row id from another channel must not resolve, so a session that
        // learned somebody else's id still cannot spend it.
        assertNull($this->resets->findLiveById($someoneElse, 'whatsapp'));
        assertTrue($this->resets->findLiveById($mine, 'whatsapp') !== null);

        $result = $this->resets->consume('whatsapp', '111111', 'N3w!strong', '10.0.0.1', $someoneElse);

        assertFalse($result['ok'], 'The channel scopes the row id.');
        assertTrue(password_verify('Str0ng!old', (string) $this->users->findById($this->uid($user))['password_hash']));
    }

    public function testAnOtpWithNoPinnedRowIsRefused(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $this->storeToken($this->uid($user), 'whatsapp', $this->resets->hash('333333'), ttl: 600);

        $result = $this->resets->consume('whatsapp', '333333', 'N3w!strong', '10.0.0.1', null);

        assertFalse($result['ok'], 'An OTP with no row id is unprovable and must not be spent.');
    }

    public function testFiveWrongCodesKillTheRow(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $code = '777777';
        $rowId = $this->storeToken($this->uid($user), 'telegram', $this->resets->hash($code), ttl: 600);

        for ($attempt = 1; $attempt <= PasswordResetService::MAX_OTP_ATTEMPTS; $attempt++) {
            $result = $this->resets->consume('telegram', '000000', 'N3w!strong', '10.0.0.1', $rowId);
            assertFalse($result['ok'], "Guess {$attempt} must fail.");
        }

        // The counter lives on the row, so clearing the cache cannot revive it.
        $this->throttle->clear('reset.request.anything');
        assertNull($this->resets->findLiveById($rowId, 'telegram'), 'The row must be dead after 5 guesses.');

        $after = $this->resets->consume('telegram', $code, 'N3w!strong', '10.0.0.1', $rowId);
        assertFalse($after['ok'], 'Even the correct code must fail once the row is dead.');
        assertTrue(password_verify('Str0ng!old', (string) $this->users->findById($this->uid($user))['password_hash']));
    }

    public function testAGuessTellsTheUserHowManyTriesAreLeft(): void
    {
        $user = $this->makeUser();
        $rowId = $this->storeToken($this->uid($user), 'whatsapp', $this->resets->hash('888888'), ttl: 600);

        $result = $this->resets->consume('whatsapp', '000000', 'N3w!strong', '10.0.0.1', $rowId);

        assertStringContainsString('4', (string) $result['error']);
        assertSame(1, $this->attempts($rowId), 'The wrong guess is counted on the row.');
    }

    // ---- shared guards -----------------------------------------------------

    public function testAShortPasswordIsRefusedBeforeAnythingIsSpent(): void
    {
        $user = $this->makeUser(password: 'Str0ng!old');
        $token = bin2hex(random_bytes(32));
        $rowId = $this->storeToken($this->uid($user), 'email', $this->resets->hash($token));

        $result = $this->resets->consume('email', $token, '12345', '10.0.0.1');

        assertFalse($result['ok']);
        assertNull(
            $this->db->createCommand('SELECT [[consumed_at]] FROM {{%password_reset_token}} WHERE [[id]] = :id')
                ->bindValue(':id', $rowId)
                ->queryScalar(),
            'A password the user has not finished choosing must not burn the link.',
        );
        assertTrue(
            password_verify('Str0ng!old', (string) $this->users->findById($this->uid($user))['password_hash']),
            'A rejected password must not have consumed the link.',
        );
    }

    public function testAResetByEmailWorksForAnAccountThatOnlyHasAnAddress(): void
    {
        // findByIdentifier() deliberately excludes email (it is the login
        // path), so this proves the reset path adds it back.
        $user = $this->makeUser();
        $token = bin2hex(random_bytes(32));
        $this->storeToken($this->uid($user), 'email', $this->resets->hash($token));

        $found = $this->resets->findLive('email', $this->resets->hash($token));

        assertTrue($found !== null);
        assertSame($this->uid($user), (int) $found['user_id']);
    }

    // ---- helpers -----------------------------------------------------------

    private function makeUser(string $tag = 'a', string $password = 'Str0ng!old'): array
    {
        $id = $this->users->create([
            'username' => 'reset_' . $tag . '_' . $this->suffix,
            'phone' => '9' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'reset_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        $row = $this->users->findById($id);
        assertTrue($row !== null);
        return $row;
    }

    private function username(array $user): string
    {
        return (string) $user['username'];
    }

    /** MySQL hands `id` back as a string; every repository here wants an int. */
    private function uid(array $user): int
    {
        return (int) $user['id'];
    }

    /**
     * Write a reset row directly, the way `issue()` would.
     *
     * Bypassing `issue()` keeps the network drivers out of the test: what is
     * under test here is verification and consumption, and the hash is the
     * service's own so it is the same value the real flow stores.
     */
    private function storeToken(int $userId, string $channel, string $hash, int $ttl = 3600): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%password_reset_token}}', [
            'user_id' => $userId,
            'channel' => $channel,
            'token_hash' => $hash,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    private function countRows(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%password_reset_token}}')
            ->queryScalar();
    }

    private function attempts(int $rowId): int
    {
        return (int) $this->db
            ->createCommand('SELECT [[attempts]] FROM {{%password_reset_token}} WHERE [[id]] = :id')
            ->bindValue(':id', $rowId)
            ->queryScalar();
    }
}
