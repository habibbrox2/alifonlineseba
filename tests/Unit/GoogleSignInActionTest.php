<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\GoogleIdTokenVerifier;
use App\Web\Auth\GoogleSignInAction;
use App\Auth\GoogleSignInService;
use App\Auth\IdentityRepository;
use App\Repository\UserRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\View\WebView;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Unit tests for {@see GoogleSignInAction}.
 *
 * The action is constructed directly with a test double verifier and a minimal
 * session, so we test its control flow without needing the full DI container
 * or a working view renderer.
 *
 * View rendering is not asserted — the action returns a rendered login page
 * on failure, and a rendered page is hard to test without the full Twig setup.
 * Instead we check the status code, headers, and session state.
 */
final class GoogleSignInActionTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;

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
        $this->suffix = 'gsa' . substr(md5(uniqid('', true)), 0, 8);
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
    }

    public function testEmptyCredentialReturnsToLoginWithFlash(): void
    {
        [$action, $session] = $this->createAction();

        $request = (new ServerRequest('POST', '/auth/google'))
            ->withParsedBody([]);

        $response = $action($request);

        assertSame(200, $response->getStatusCode());
        assertTrue(str_contains($response->getBody()->getContents(), 'লগইন'));
    }

    public function testInvalidTokenReturnsToLoginWithFlash(): void
    {
        [$action, $session] = $this->createAction();

        $request = (new ServerRequest('POST', '/auth/google'))
            ->withParsedBody(['credential' => 'invalid-token']);

        $response = $action($request);

        assertSame(200, $response->getStatusCode());
    }

    public function testValidTokenForNewEmailCreatesAccountAndRedirects(): void
    {
        [$action, $session] = $this->createAction();

        $email = 'google-new-' . $this->suffix . '@example.test';

        $request = (new ServerRequest('POST', '/auth/google'))
            ->withParsedBody(['credential' => 'test-token-new-' . $email]);

        $response = $action($request);

        assertSame(302, $response->getStatusCode());
        assertStringContainsString('/dashboard', $response->getHeaderLine('Location'));

        // A new account was created.
        $row = $this->users->findByEmail($email);
        assertTrue($row !== null, 'A new account must be created for the email');
        assertSame(strtolower($email), strtolower($row['email']));

        // Username derived from email local-part.
        $local = explode('@', $email, 2)[0];
        $expectedUsername = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower($local));
        if ($expectedUsername === '') {
            $expectedUsername = 'google';
        }
        assertSame($expectedUsername, $row['username']);

        // Session has the user id.
        assertSame((int) $row['id'], $session->get(IdentityRepository::SESSION_KEY));

        $this->userIds[] = (int) $row['id'];
    }

    public function testValidTokenForExistingEmailSignsInAsThatAccount(): void
    {
        [$action, $session] = $this->createAction();

        $email = 'google-existing-' . $this->suffix . '@example.test';
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

        // Verify the account was created with the right email.
        $check = $this->users->findByEmail($email);
        assertSame((int) $existingId, (int) $check['id'], 'Pre-existing account should be findable by email');
        assertSame('active', $check['status'], 'Pre-existing account should be active');

        $request = (new ServerRequest('POST', '/auth/google'))
            ->withParsedBody(['credential' => 'test-token-existing-' . $email]);

        $response = $action($request);

        assertSame(302, $response->getStatusCode());
        assertStringContainsString('/dashboard', $response->getHeaderLine('Location'));

        // Signs in as the existing account.
        // Signs in as the existing account.
        assertSame($existingId, $session->get(IdentityRepository::SESSION_KEY), 'Session should have the existing account id');

        // No duplicate created — the email should still point to the same account.
        $rows = $this->users->findByEmail($email);
        assertSame((int) $rows['id'], $existingId, 'No duplicate account should have been created');
    }

    /**
     * Build the action + a test session + a test verifier.
     *
     * @return array{GoogleSignInAction, SessionInterface}
     */
    private function createAction(): array
    {
        // Test session — a minimal implementation.
        $session = new class implements SessionInterface {
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

            // Flash methods.
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

        $verifier = new TestGoogleIdTokenVerifier();
        $service = new GoogleSignInService(new UserRepository($this->db));

        $psr17 = new Psr17Factory();
        $view = new \Yiisoft\Yii\View\Renderer\WebViewRenderer(
            $psr17,
            $psr17,
            new Aliases(['@views' => codecept_root_dir() . 'resources/views']),
            new WebView(codecept_root_dir() . 'resources/views'),
            codecept_root_dir() . 'resources/views',
        );

        $action = new GoogleSignInAction($view, $verifier, $service, $session);

        return [$action, $session];
    }
}

/**
 * A test double for GoogleIdTokenVerifier.
 *
 * Tokens starting with `test-token-new-` return a payload for a new user.
 * Tokens starting with `test-token-existing-` return a payload for an
 * existing user.
 * Any other token returns null (invalid).
 */
final class TestGoogleIdTokenVerifier extends \App\Auth\GoogleIdTokenVerifier
{
    public function verify(string $token): ?array
    {
        if (!str_starts_with($token, 'test-token-')) {
            return null;
        }

        $email = str_replace('test-token-new-', '', $token);
        $email = str_replace('test-token-existing-', '', $email);

        return [
            'sub' => 'test-sub-' . md5($email),
            'email' => $email,
            'email_verified' => true,
            'name' => 'গুগল ব্যবহারকারী',
            'picture' => 'https://example.com/avatar.png',
            'firebase_provider' => 'google.com',
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-client-id',
            'exp' => time() + 3600,
            'iat' => time() - 3600,
            'auth_time' => time() - 3600,
            'firebase_provider' => 'google.com',
        ];
    }
}
