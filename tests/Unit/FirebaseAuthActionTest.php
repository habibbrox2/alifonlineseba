<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\Database;
use App\Auth\FirebaseAuthService;
use App\Auth\FirebaseIdTokenVerifier;
use App\Auth\IdentityRepository;
use App\Repository\UserRepository;
use App\Web\Auth\FirebaseAuthAction;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\View\WebView;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Unit tests for {@see FirebaseAuthAction}.
 *
 * The action is constructed directly with a test double verifier and a minimal
 * session, so we test its control flow without needing the full DI container
 * or a working view renderer.
 */
final class FirebaseAuthActionTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;

    /** @var int[] */
    private array $userIds = [];
    private string $suffix = '';

    protected function _before(): void
    {
        $this->db = Database::connection();
        $this->users = new UserRepository($this->db);
        $this->suffix = 'faa' . substr(md5(uniqid('', true)), 0, 8);
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

        $request = (new ServerRequest('POST', '/auth/firebase'))
            ->withParsedBody([]);

        $response = $action($request);

        assertSame(200, $response->getStatusCode());
        assertTrue(str_contains($response->getBody()->getContents(), 'লগইন'));
    }

    public function testInvalidTokenReturnsToLoginWithFlash(): void
    {
        [$action, $session] = $this->createAction();

        $request = (new ServerRequest('POST', '/auth/firebase'))
            ->withParsedBody(['credential' => 'invalid-token']);

        $response = $action($request);

        assertSame(200, $response->getStatusCode());
    }

    /**
     * The browser posts `_forward_flash` when the sign-in fails on its side, so
     * the server can hand the reason to the login page as a flash banner.
     *
     * Two things have to hold for that banner to exist at all: the redirect
     * has to name the login route, and the flash has to survive the request
     * that set it. FlashMiddleware reads the value on the *next* request (the
     * redirect) and removes it there — so removing it here, in the same
     * request, deleted it before the redirect could ever show it, and the
     * visitor saw a plain login page with no reason on it.
     */
    public function testForwardedFlashIsKeptForTheLoginPageToRender(): void
    {
        [$action, $session] = $this->createAction();

        $request = (new ServerRequest('POST', '/auth/firebase'))
            ->withParsedBody([
                'credential' => '',
                '_forward_flash' => '1',
                'message' => 'সাইন ইন করা যায়নি। আবার চেষ্টা করুন।',
            ]);

        $response = $action($request);

        assertSame(302, $response->getStatusCode());
        assertStringContainsString('/login', $response->getHeaderLine('Location'));
        assertSame(
            'সাইন ইন করা যায়নি। আবার চেষ্টা করুন।',
            $session->get('flash_error'),
            'The flash must still be in the session: FlashMiddleware consumes it on the redirect.',
        );
    }

    /** A missing flash would mean the banner is never rendered. */
    public function testForwardedFlashDefaultsToAGenericMessage(): void
    {
        [$action, $session] = $this->createAction();

        $request = (new ServerRequest('POST', '/auth/firebase'))
            ->withParsedBody(['credential' => '', '_forward_flash' => '1']);

        $response = $action($request);

        assertSame(302, $response->getStatusCode());
        assertSame('সাইন ইন করা যায়নি। আবার চেষ্টা করুন।', $session->get('flash_error'));
    }

    public function testValidTokenForNewEmailCreatesAccountAndRedirects(): void
    {
        [$action, $session] = $this->createAction();

        $email = 'firebase-new-' . $this->suffix . '@example.test';

        $request = (new ServerRequest('POST', '/auth/firebase'))
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
            $expectedUsername = 'firebase';
        }
        assertSame($expectedUsername, $row['username']);

        // Session has the user id.
        assertSame((int) $row['id'], $session->get(IdentityRepository::SESSION_KEY));

        $this->userIds[] = (int) $row['id'];
    }

    public function testValidTokenForExistingEmailSignsInAsThatAccount(): void
    {
        [$action, $session] = $this->createAction();

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

        // Verify the account was created with the right email.
        $check = $this->users->findByEmail($email);
        assertSame((int) $existingId, (int) $check['id'], 'Pre-existing account should be findable by email');
        assertSame('active', $check['status'], 'Pre-existing account should be active');

        $request = (new ServerRequest('POST', '/auth/firebase'))
            ->withParsedBody(['credential' => 'test-token-existing-' . $email]);

        $response = $action($request);

        assertSame(302, $response->getStatusCode());
        assertStringContainsString('/dashboard', $response->getHeaderLine('Location'));

        // Signs in as the existing account.
        assertSame($existingId, $session->get(IdentityRepository::SESSION_KEY), 'Session should have the existing account id');

        // No duplicate created — the email should still point to the same account.
        $rows = $this->users->findByEmail($email);
        assertSame((int) $rows['id'], $existingId, 'No duplicate account should have been created');
    }

    /**
     * Build the action + a test session + a test verifier.
     *
     * @return array{FirebaseAuthAction, SessionInterface}
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

        $verifier = new TestFirebaseIdTokenVerifier();
        $service = new FirebaseAuthService(new UserRepository($this->db));

        $psr17 = new Psr17Factory();
        $view = new WebViewRenderer(
            $psr17,
            $psr17,
            new Aliases(['@views' => codecept_root_dir() . 'resources/views']),
            new WebView(codecept_root_dir() . 'resources/views'),
            codecept_root_dir() . 'resources/views',
        );

        $action = new FirebaseAuthAction($view, $verifier, $service, $session, $this->urlGenerator());

        return [$action, $session];
    }

    /**
     * A URL generator that answers the route names this action uses.
     *
     * The action redirects to the `login` route when it forwards a flash, so
     * the double has to know that name — a generator that threw on an unknown
     * route would turn a missing dependency into a test failure that reads
     * like a routing bug.
     */
    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            public function generate(
                string $name,
                array $arguments = [],
                array $queryParameters = [],
                ?string $hash = null,
            ): string {
                return '/' . $name;
            }

            public function generateAbsolute(
                string $name,
                array $arguments = [],
                array $queryParameters = [],
                ?string $hash = null,
                ?string $scheme = null,
                ?string $host = null,
            ): string {
                return '/' . $name;
            }

            public function generateFromCurrent(
                array $replacedArguments = [],
                array $queryParameters = [],
                ?string $hash = null,
                ?string $fallbackRouteName = null,
            ): string {
                return '/';
            }

            public function getUriPrefix(): string
            {
                return '';
            }

            public function setUriPrefix(string $name): void {}

            public function setDefaultArgument(string $name, bool|float|int|string|\Stringable|null $value): void {}
        };
    }
}

/**
 * A test double for FirebaseIdTokenVerifier used by FirebaseAuthActionTest.
 *
 * Tokens starting with `test-token-new-` return a payload for a new user.
 * Tokens starting with `test-token-existing-` return a payload for an
 * existing user.
 * Any other token returns null (invalid).
 */
final class TestFirebaseIdTokenVerifier extends FirebaseIdTokenVerifier
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
            'name' => 'ফায়ারবেস ব্যবহারকারী',
            'picture' => 'https://example.com/avatar.png',
            'firebase_provider' => 'password',
            'iss' => 'https://securetoken.google.com/test-project',
            'aud' => 'test-project',
            'exp' => time() + 3600,
            'iat' => time() - 3600,
            'auth_time' => time() - 3600,
        ];
    }
}
