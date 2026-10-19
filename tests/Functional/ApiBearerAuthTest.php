<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\ApiAuthMiddleware;
use App\Auth\ApiTokenRepository;
use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\RoleRankRepository;
use App\Repository\UserRepository;
use App\Tests\Support\Database;
use App\Tests\Support\EnvOverride;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * The bearer-token path of the API guard — the one the Android app signs in on.
 *
 * `ApiAuthMiddleware` carries two paths: a bearer token authenticates a machine
 * client, and everything else falls through to the ordinary session guard. Only
 * the second one was covered, and the two look alike in a way that hides a
 * mistake: the constructor takes a `UserRepository` and hands it to the inner
 * session guard, which is enough for the *session* path to work, while the
 * bearer path reaches for the same repository on the middleware itself.
 *
 * Leaving it unpromoted there made the bearer path read a property that was
 * never declared — a fatal error on the first authenticated APK request, on a
 * route the browser tests never touch. This file is the guard for that: a real
 * token, minted through the real repository, has to reach the handler as the
 * account it belongs to.
 */
final class ApiBearerAuthTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private ApiTokenRepository $tokens;
    private RoleRankRepository $roleRanks;

    /** @var int[] */
    private array $userIds = [];

    private string $suffix = '';
    private array $previousEnv = [];

    protected function _before(): void
    {
        $this->db = Database::connection();
        $this->users = new UserRepository($this->db);
        $this->tokens = new ApiTokenRepository($this->db);
        $this->roleRanks = new RoleRankRepository($this->db);
        $this->suffix = 'aba' . substr(md5(uniqid('', true)), 0, 8);

        // The token hash is keyed on APP_KEY, so the test has to sign with the
        // same key the repository verifies with.
        $this->previousEnv = EnvOverride::snapshot('APP_KEY');
        EnvOverride::set('APP_KEY', 'bearer-test-key-' . $this->suffix);
    }

    protected function _after(): void
    {
        // FK order: the tokens point at the user row about to go. Every token
        // the user holds is removed, not just the access token this test
        // issued — `issue()` mints a refresh pair alongside it.
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%api_token}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];

        EnvOverride::restore($this->previousEnv);
        $this->previousEnv = [];
    }

    /**
     * The positive case: a valid token authenticates its owner and the
     * request reaches the handler with that identity on it.
     */
    public function testAValidBearerTokenAuthenticatesItsOwner(): void
    {
        $userId = $this->createUser();
        $token = $this->issueToken($userId);

        $identity = null;
        $response = $this->guard()->process(
            $this->request($token),
            $this->handlerCapturing($identity),
        );

        assertSame(200, $response->getStatusCode());
        assertNotNull($identity, 'The handler must receive the identity of the token owner.');
        assertSame($userId, $identity->id);
    }

    /**
     * A token that names nobody is refused, and it is refused explicitly: an
     * expired APK token must not quietly become a logged-in browser session.
     */
    public function testAnUnknownBearerTokenIsRefused(): void
    {
        $response = $this->guard()->process(
            $this->request('nobody.' . bin2hex(random_bytes(16))),
            $this->handlerThatMustNotRun(),
        );

        assertSame(401, $response->getStatusCode());
    }

    /** A revoked token is as good as no token at all. */
    public function testARevokedBearerTokenIsRefused(): void
    {
        $userId = $this->createUser();
        $token = $this->issueToken($userId);
        $this->tokens->revoke($token);

        $response = $this->guard()->process(
            $this->request($token),
            $this->handlerThatMustNotRun(),
        );

        assertSame(401, $response->getStatusCode());
    }

    /**
     * A trashed account's token stops working even though the token row is
     * still there: the account is gone, so the session it would open is too.
     */
    public function testATokenForADeletedAccountIsRefused(): void
    {
        $userId = $this->createUser();
        $token = $this->issueToken($userId);

        $this->db
            ->createCommand()
            ->update('{{%user}}', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $userId])
            ->execute();

        $response = $this->guard()->process(
            $this->request($token),
            $this->handlerThatMustNotRun(),
        );

        assertSame(401, $response->getStatusCode());
    }

    // ---- Helpers ------------------------------------------------------------

    private function guard(): ApiAuthMiddleware
    {
        return new ApiAuthMiddleware(
            $this->session(),
            $this->users,
            new ActivityLogRepository($this->db),
            $this->roleRanks,
            $this->urlGenerator(),
            $this->tokens,
        );
    }

    private function request(string $bearer): ServerRequest
    {
        return (new ServerRequest('GET', '/api/dashboard'))
            ->withHeader('Authorization', 'Bearer ' . $bearer);
    }

    /**
     * @param Identity|null $identity filled in by the handler
     */
    private function handlerCapturing(?Identity &$identity): RequestHandlerInterface
    {
        return new class ($identity) implements RequestHandlerInterface {
            public function __construct(private ?Identity &$identity) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                $this->identity = $request->getAttribute('identity');
                return new \Nyholm\Psr7\Response(200);
            }
        };
    }

    /** A handler that must never run: reaching it means the guard let a bad token through. */
    private function handlerThatMustNotRun(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('The guard let an invalid token reach the handler.');
            }
        };
    }

    private function createUser(): int
    {
        $id = $this->users->create([
            'username' => 'bearer-' . $this->suffix,
            'full_name' => 'Bearer Probe',
            'phone' => '018' . substr(md5($this->suffix), 0, 8),
            'email' => 'bearer-' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->userIds[] = (int) $id;

        return (int) $id;
    }

    private function issueToken(int $userId): string
    {
        $pair = $this->tokens->issue($userId, 'bearer-test');

        return (string) $pair['token'];
    }

    /** A session that is never signed in: the bearer path must not need one. */
    private function session(): SessionInterface
    {
        return new class implements SessionInterface {
            private array $data = [];

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
            public function destroy(): void { $this->data = []; }
            public function getName(): string { return 'TEST_SESSION'; }
            public function getId(): ?string { return 'bearer-test-session'; }
            public function setId(string $sessionId): void {}

            public function setFlash(string $key, mixed $value): void {}
            public function getFlash(string $key, mixed $default = null): mixed { return $default; }
            public function removeFlash(string $key): void {}
            public function getAllFlashes(): array { return []; }
            public function getFlashBag(): \Yiisoft\Session\Flash\FlashBagInterface
            {
                return new \Yiisoft\Session\Flash\FlashBag();
            }
        };
    }

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

            public function getUriPrefix(): string { return ''; }
            public function setUriPrefix(string $name): void {}
            public function setDefaultArgument(string $name, bool|float|int|string|\Stringable|null $value): void {}
        };
    }
}
