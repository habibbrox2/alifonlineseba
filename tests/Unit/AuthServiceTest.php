<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\AuthThrottle;
use App\Auth\IdentityRepository;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\ReferralRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\LedgerService;
use App\Service\ReferralService;
use App\Tests\Support\TestGraph;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertArrayHasKey;

/**
 * Unit tests for {@see AuthService::login()} and {@see AuthService::register()}.
 *
 * Tests use a real database connection and a real ReferralService with all its dependencies.
 * The register tests verify validation, uniqueness, and referral handling.
 * The login tests verify credentials, throttling, and edge cases (deleted, suspended).
 */
final class AuthServiceTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private ActivityLogRepository $logs;
    private SettingsRepository $settings;
    private ReferralRepository $referralRepo;
    private AuthService $service;
    private AuthThrottle $throttle;
    private ReferralService $referrals;
    private string $testPrefix = '';

    /** @var int[] */
    private array $userIds = [];
    /** @var string[] */
    private array $usedPhones = [];
    private int $phoneSeq = 0;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->logs = new ActivityLogRepository($this->db);
        $this->settings = new SettingsRepository($this->db);
        $this->referralRepo = new ReferralRepository($this->db);

        // Use a real throttle with a file cache for integration-like behavior
        $cache = $container->get(CacheInterface::class);
        $this->throttle = new AuthThrottle($cache);

        // Build ReferralService with all its dependencies (like functional tests)
        $ledger = TestGraph::ledger($this->db, $this->users);
        $notify = TestGraph::notify($this->db, $this->users, $this->settings);

        $this->referrals = new ReferralService(
            $this->referralRepo,
            $this->users,
            $ledger,
            $this->settings,
            $notify,
            $this->logs,
            $this->db,
        );

        $this->service = new AuthService(
            new IdentityRepository($this->createTestSession(), $this->users, $this->logs),
            $this->throttle,
            $this->referrals,
            $this->users,
        );
        // Unique prefix per test instance to avoid cross-test phone collisions
        $this->testPrefix = 'authsvc' . substr(md5(uniqid('', true)), 0, 8) . '_';
    }

    protected function _after(): void
    {
        // Clean up in reverse dependency order:
        // 1. Clear referred_by on users who were referred (FK from user->user)
        // 2. Delete referral rows (FK to user)
        // 3. Delete activity_log (FK to user)
        // 4. Delete users
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->update('{{%user}}', ['referred_by' => null], ['referred_by' => $id])->execute();
        }
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%referral}}', ['referee_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%referral}}', ['referrer_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
        $this->usedPhones = [];
    }

    private function uniquePhone(): string
    {
        // Generate a valid Bangladeshi phone: 017 + 8 digits
        // Use test prefix hash + sequence for uniqueness across tests
        $this->phoneSeq++;
        // Create a suffix from prefix hash + sequence
        $hash = substr(md5($this->testPrefix), 0, 6); // 6 hex chars
        $suffix = (hexdec($hash) + $this->phoneSeq) % 100000000;
        $phone = '017' . str_pad((string) $suffix, 8, '0', STR_PAD_LEFT);
        $this->usedPhones[] = $phone;
        return $phone;
    }

    private function uniqueUsername(string $base): string
    {
        return $base . '_' . $this->testPrefix . count($this->usedPhones);
    }

    // ---- Login tests ------------------------------------------------------

    public function testLoginSucceedsWithUsername(): void
    {
        $username = $this->uniqueUsername('loginuser');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->userIds[] = $userId;

        $result = $this->service->login(['identifier' => $username, 'password' => 'pass1234'], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNull($result['message']);
    }

    public function testLoginSucceedsWithPhone(): void
    {
        $username = $this->uniqueUsername('phoneuser');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->userIds[] = $userId;

        $result = $this->service->login(['identifier' => $phone, 'password' => 'pass1234'], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNull($result['message']);
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $username = $this->uniqueUsername('wrongpass');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->userIds[] = $userId;

        $result = $this->service->login(['identifier' => $username, 'password' => 'wrongpassword'], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['message']);
        assertSame('Invalid credentials. Please check your username and password.', $result['message']);
    }

    public function testLoginFailsWithUnknownIdentifier(): void
    {
        $result = $this->service->login(['identifier' => 'nonexistentuser_' . $this->testPrefix, 'password' => 'anypassword'], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['message']);
        assertSame('Invalid credentials. Please check your username and password.', $result['message']);
    }

    public function testLoginFailsWithEmptyIdentifier(): void
    {
        $result = $this->service->login(['identifier' => '', 'password' => 'anypassword'], '127.0.0.1', 'test-agent');

        assertArrayHasKey('identifier', $result['errors']);
        assertSame('Enter your username or mobile number.', $result['errors']['identifier']);
        assertNull($result['message']);
    }

    public function testLoginFailsWithEmptyPassword(): void
    {
        $result = $this->service->login(['identifier' => 'someuser', 'password' => ''], '127.0.0.1', 'test-agent');

        assertArrayHasKey('password', $result['errors']);
        assertSame('Enter your password.', $result['errors']['password']);
        assertNull($result['message']);
    }

    public function testLoginFailsOnSuspendedAccount(): void
    {
        $username = $this->uniqueUsername('suspendeduser');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->users->update($userId, ['status' => 'suspended']);
        $this->userIds[] = $userId;

        $result = $this->service->login(['identifier' => $username, 'password' => 'pass1234'], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['message']);
        assertSame('This account has been disabled. Contact support.', $result['message']);
    }

    public function testLoginFailsOnDeletedAccount(): void
    {
        $username = $this->uniqueUsername('deleteduser');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->users->softDelete($userId);
        $this->userIds[] = $userId;

        $result = $this->service->login(['identifier' => $username, 'password' => 'pass1234'], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['message']);
        assertSame('This account has been removed. Contact support.', $result['message']);
    }

    public function testThrottleBlocksAfterMaxAttempts(): void
    {
        $username = $this->uniqueUsername('throttleuser');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->userIds[] = $userId;

        // First 5 attempts should fail with "Invalid credentials"
        for ($i = 0; $i < 5; $i++) {
            $result = $this->service->login(['identifier' => $username, 'password' => 'wrong'], '127.0.0.1', 'test-agent');
            assertSame([], $result['errors']);
            assertNotNull($result['message']);
            assertSame('Invalid credentials. Please check your username and password.', $result['message']);
        }

        // 6th attempt should be throttled
        $result = $this->service->login(['identifier' => $username, 'password' => 'wrong'], '127.0.0.1', 'test-agent');
        assertSame([], $result['errors']);
        assertNotNull($result['message']);
        assertSame('Too many failed attempts. Try again in a few minutes.', $result['message']);
    }

    public function testThrottleResetsOnSuccessfulLogin(): void
    {
        $username = $this->uniqueUsername('throttleuser2');
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($username, $phone, 'pass1234');
        $this->userIds[] = $userId;

        // 4 failed attempts
        for ($i = 0; $i < 4; $i++) {
            $this->service->login(['identifier' => $username, 'password' => 'wrong'], '127.0.0.1', 'test-agent');
        }

        // Successful login
        $result = $this->service->login(['identifier' => $username, 'password' => 'pass1234'], '127.0.0.1', 'test-agent');
        assertNull($result['message']);

        // Should be able to try again (throttle cleared)
        $result = $this->service->login(['identifier' => $username, 'password' => 'wrong'], '127.0.0.1', 'test-agent');
        assertNotNull($result['message']);
        assertSame('Invalid credentials. Please check your username and password.', $result['message']);
    }

    // ---- Register tests ---------------------------------------------------

    public function testRegisterSucceedsWithValidInput(): void
    {
        $username = $this->uniqueUsername('reguser');
        $phone = $this->uniquePhone();
        $email = $username . '@example.test';

        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => $username,
            'phone' => $phone,
            'email' => $email,
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['userId']);
        $this->userIds[] = $result['userId'];

        $row = $this->users->findById($result['userId']);
        assertNotNull($row);
        assertSame('Test User', $row['full_name']);
        assertSame($username, $row['username']);
        assertSame($phone, $row['phone']);
    }

    public function testRegisterFailsWithShortFullName(): void
    {
        $result = $this->service->register([
            'full_name' => 'A',
            'username' => $this->uniqueUsername('reguser2'),
            'phone' => $this->uniquePhone(),
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('full_name', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithShortUsername(): void
    {
        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => 'ab',
            'phone' => $this->uniquePhone(),
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('username', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithInvalidUsernameChars(): void
    {
        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => 'invalid@user',
            'phone' => $this->uniquePhone(),
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('username', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithInvalidPhone(): void
    {
        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => $this->uniqueUsername('reguser3'),
            'phone' => '1234567890',
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('phone', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithInvalidEmail(): void
    {
        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => $this->uniqueUsername('reguser4'),
            'phone' => $this->uniquePhone(),
            'email' => 'not-an-email',
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('email', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithShortPassword(): void
    {
        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => $this->uniqueUsername('reguser5'),
            'phone' => $this->uniquePhone(),
            'password' => 'short',
            'password_confirm' => 'short',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('password', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithMismatchedPasswords(): void
    {
        $result = $this->service->register([
            'full_name' => 'Test User',
            'username' => $this->uniqueUsername('reguser6'),
            'phone' => $this->uniquePhone(),
            'password' => 'ValidPass123!',
            'password_confirm' => 'DifferentPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('password_confirm', $result['errors']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithDuplicateUsername(): void
    {
        $username = $this->uniqueUsername('dupeuser');
        $userId = $this->createTestUser($username, $this->uniquePhone(), 'pass1234');
        $this->userIds[] = $userId;

        $result = $this->service->register([
            'full_name' => 'Another User',
            'username' => $username,
            'phone' => $this->uniquePhone(),
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('username', $result['errors']);
        assertSame('This username is already taken.', $result['errors']['username']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithDuplicatePhone(): void
    {
        $phone = $this->uniquePhone();
        $userId = $this->createTestUser($this->uniqueUsername('phoneuser2'), $phone, 'pass1234');
        $this->userIds[] = $userId;

        $result = $this->service->register([
            'full_name' => 'Another User',
            'username' => $this->uniqueUsername('newuser'),
            'phone' => $phone,
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('phone', $result['errors']);
        assertSame('An account with this mobile number already exists.', $result['errors']['phone']);
        assertNull($result['userId']);
    }

    public function testRegisterFailsWithDuplicateEmail(): void
    {
        $email = 'emailuser_' . $this->testPrefix . '@example.test';
        $userId = $this->createTestUser($this->uniqueUsername('emailuser'), $this->uniquePhone(), 'pass1234', $email);
        $this->userIds[] = $userId;

        $result = $this->service->register([
            'full_name' => 'Another User',
            'username' => $this->uniqueUsername('newuser2'),
            'phone' => $this->uniquePhone(),
            'email' => $email,
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], '127.0.0.1', 'test-agent');

        assertArrayHasKey('email', $result['errors']);
        assertSame('This email address is already in use.', $result['errors']['email']);
        assertNull($result['userId']);
    }

    public function testRegisterWithReferralCode(): void
    {
        $referrerUsername = $this->uniqueUsername('referrer');
        $referrerId = $this->createTestUser($referrerUsername, $this->uniquePhone(), 'pass1234');
        $referrer = $this->users->findById($referrerId);
        $referralCode = $referrer['referral_code'];
        $this->userIds[] = $referrerId;

        $username = $this->uniqueUsername('referred');
        $phone = $this->uniquePhone();

        $result = $this->service->register([
            'full_name' => 'Referred User',
            'username' => $username,
            'phone' => $phone,
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
            'referral_code' => $referralCode,
        ], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['userId']);
        $this->userIds[] = $result['userId'];

        $row = $this->users->findById($result['userId']);
        assertSame((int) $referrerId, (int) $row['referred_by']);
    }

    public function testRegisterIgnoresInvalidReferralCode(): void
    {
        $username = $this->uniqueUsername('referred2');
        $phone = $this->uniquePhone();

        $result = $this->service->register([
            'full_name' => 'Referred User',
            'username' => $username,
            'phone' => $phone,
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
            'referral_code' => 'INVALID123',
        ], '127.0.0.1', 'test-agent');

        assertSame([], $result['errors']);
        assertNotNull($result['userId']);
        $this->userIds[] = $result['userId'];

        $row = $this->users->findById($result['userId']);
        assertNull($row['referred_by']);
    }

    // ---- Helpers ----------------------------------------------------------

    private function createTestUser(string $username, string $phone, string $password, ?string $email = null): int
    {
        return $this->users->create([
            'username' => $username,
            'full_name' => 'Test User',
            'phone' => $phone,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'status' => 'active',
        ]);
    }

    private function createTestSession(): SessionInterface
    {
        return new class implements SessionInterface {
            private array $data = [];
            private array $flashes = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }

            public function isStarted(): bool { return true; }
            public function start(): void {}
            public function clear(): void { $this->data = []; }
            public function close(): void {}
            public function open(): void {}
            public function isActive(): bool { return true; }
            public function discard(): void {}
            public function all(): array { return $this->data; }
            public function pull(string $key, mixed $default = null): mixed
            {
                $value = $this->data[$key] ?? $default;
                unset($this->data[$key]);
                return $value;
            }
            public function getCookieParameters(): array { return []; }
            public function regenerateId(): void {}
            public function destroy(): void { $this->data = []; $this->flashes = []; }
            public function getName(): string { return 'TEST_SESSION'; }
            public function getId(): ?string { return 'test-session-id'; }
            public function setId(string $sessionId): void {}

            public function setFlash(string $key, mixed $value): void
            {
                $this->flashes[$key] = $value;
            }
            public function getFlash(string $key, mixed $default = null): mixed
            {
                return $this->flashes[$key] ?? $default;
            }
            public function removeFlash(string $key): void
            {
                unset($this->flashes[$key]);
            }
            public function getAllFlashes(): array
            {
                return $this->flashes;
            }
            public function getFlashBag(): \Yiisoft\Session\Flash\FlashBagInterface
            {
                return new \Yiisoft\Session\Flash\FlashBag();
            }
        };
    }
}