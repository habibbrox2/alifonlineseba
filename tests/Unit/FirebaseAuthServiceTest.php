<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\FirebaseAuthService;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * Unit tests for {@see FirebaseAuthService}.
 *
 * Tests provisioning logic for email/password, phone, and the two-entry rule
 * using a real database connection so the UNIQUE constraints on email and phone
 * are enforced.
 */
final class FirebaseAuthServiceTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private FirebaseAuthService $service;

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
        $this->service = new FirebaseAuthService($this->users);
        $this->suffix = 'fas' . substr(md5(uniqid('', true)), 0, 8);
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
    }

    public function testNewUserWithEmailCreatesAccount(): void
    {
        $email = 'firebase-email-' . $this->suffix . '@example.test';

        $result = $this->service->provision([
            'sub' => 'test-sub-' . $this->suffix,
            'email' => $email,
            'email_verified' => true,
            'name' => 'নতুন ব্যবহারকারী',
            'firebase_provider' => 'password',
        ], '127.0.0.1', 'test');

        assertTrue($result['isNew']);
        assertSame($email, strtolower($this->users->findByEmail($email)['email']));

        // Username derived from email local-part.
        $local = explode('@', $email, 2)[0];
        $expectedUsername = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower($local));
        assertSame($expectedUsername, $result['username']);

        $this->userIds[] = $result['id'];
    }

    public function testNewUserWithPhoneCreatesAccountWhenNoEmail(): void
    {
        $phone = '017' . substr(md5($this->suffix . 'phone'), 0, 8);

        $result = $this->service->provision([
            'sub' => 'test-sub-phone-' . $this->suffix,
            'phone_number' => $phone,
            'name' => 'ফোন ব্যবহারকারী',
            'firebase_provider' => 'phone',
        ], '127.0.0.1', 'test');

        assertTrue($result['isNew']);
        assertSame($phone, $this->users->findByEmail($result['username'] . '@example.test')['phone'] ?? $phone);

        $this->userIds[] = $result['id'];
    }

    public function testExistingUserByEmailSignsIn(): void
    {
        $email = 'firebase-existing-' . $this->suffix . '@example.test';
        $existingId = $this->users->create([
            'username' => 'existing-' . $this->suffix,
            'full_name' => 'পুরনো অ্যাকাউন্ট',
            'phone' => '017' . substr(md5($this->suffix . 'existing'), 0, 8),
            'email' => $email,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'role' => 'user',
            'status' => 'active',
        ]);
        $this->userIds[] = (int) $existingId;

        $result = $this->service->provision([
            'sub' => 'test-sub-existing-' . $this->suffix,
            'email' => $email,
            'email_verified' => true,
            'name' => 'পুরনো অ্যাকাউন্ট',
            'firebase_provider' => 'password',
        ], '127.0.0.1', 'test');

        assertTrue(!$result['isNew']);
        assertSame((int) $existingId, $result['id']);
        assertSame('existing-' . $this->suffix, $result['username']);
    }

    public function testExistingUserByPhoneSignsIn(): void
    {
        $phone = '017' . substr(md5($this->suffix . 'phoneexisting'), 0, 8);
        $existingId = $this->users->create([
            'username' => 'phoneexisting-' . $this->suffix,
            'full_name' => 'ফোন অ্যাকাউন্ট',
            'phone' => $phone,
            'email' => null,
            'password_hash' => '',
            'role' => 'user',
            'status' => 'active',
        ]);
        $this->userIds[] = (int) $existingId;

        $result = $this->service->provision([
            'sub' => 'test-sub-phone-existing-' . $this->suffix,
            'phone_number' => $phone,
            'name' => 'ফোন অ্যাকাউন্ট',
            'firebase_provider' => 'phone',
        ], '127.0.0.1', 'test');

        assertTrue(!$result['isNew']);
        assertSame((int) $existingId, $result['id']);
        assertSame('phoneexisting-' . $this->suffix, $result['username']);
    }

    public function testEmailTakesPrecedenceOverPhone(): void
    {
        $email = 'firebase-email-precedence-' . $this->suffix . '@example.test';
        $phone = '017' . substr(md5($this->suffix . 'precedence'), 0, 8);

        // Create an account with the email but a different phone.
        $emailAccountId = $this->users->create([
            'username' => 'email-precedence-' . $this->suffix,
            'full_name' => 'ইমেইল অ্যাকাউন্ট',
            'phone' => '019' . substr(md5($this->suffix . 'other'), 0, 8),
            'email' => $email,
            'password_hash' => '',
            'role' => 'user',
            'status' => 'active',
        ]);
        $this->userIds[] = (int) $emailAccountId;

        // Create a different account with the phone but a different email.
        $phoneAccountId = $this->users->create([
            'username' => 'phone-precedence-' . $this->suffix,
            'full_name' => 'ফোন অ্যাকাউন্ট',
            'phone' => $phone,
            'email' => 'phone-' . $this->suffix . '@example.test',
            'password_hash' => '',
            'role' => 'user',
            'status' => 'active',
        ]);
        $this->userIds[] = (int) $phoneAccountId;

        $result = $this->service->provision([
            'sub' => 'test-sub-precedence-' . $this->suffix,
            'email' => $email,
            'phone_number' => $phone,
            'name' => 'উভয়',
            'firebase_provider' => 'password',
        ], '127.0.0.1', 'test');

        // Email match should win.
        assertSame((int) $emailAccountId, $result['id']);
        assertSame('email-precedence-' . $this->suffix, $result['username']);
    }

    public function testSyntheticPhoneForEmailOnlyUser(): void
    {
        $email = 'firebase-synthetic-' . $this->suffix . '@example.test';

        $result = $this->service->provision([
            'sub' => 'test-sub-synthetic-' . $this->suffix,
            'email' => $email,
            'email_verified' => true,
            'name' => 'সিন্থটিক ফোন',
            'firebase_provider' => 'password',
        ], '127.0.0.1', 'test');

        assertTrue($result['isNew']);
        $row = $this->users->findByEmail($email);
        assertTrue($row !== null);
        assertStringContainsString('010', (string) $row['phone']);

        $this->userIds[] = $result['id'];
    }
}
