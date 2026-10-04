<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\AuthMiddleware;
use App\Auth\OptionalAuthMiddleware;
use App\Notification\Push\VapidKeys;
use App\Repository\ActivityLogRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Web\Api\PushApiAction;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * `/api/push/subscribe` used to store every row with `user_id = NULL`.
 *
 * The action has always read the `identity` request attribute to decide whose
 * browser it is being told about, and the route had no middleware that set it:
 * the endpoint sits outside both the `AuthMiddleware` group and the
 * `ApiAuthMiddleware` group, because a signed-out visitor on /app is the exact
 * person the push prompt is trying to reach. So the attribute was always
 * absent, and a subscription made while signed in was indistinguishable from
 * one made by a stranger — the browser got broadcasts but never the account's
 * own notifications, because `activeForUser()` never matched it.
 *
 * {@see OptionalAuthMiddleware} fixes that without guarding the endpoint:
 * it resolves the session identity and hands the request on either way.
 *
 * These tests drive the middleware and the action directly rather than through
 * HTTP. What can go wrong is a *missing attribute* and a *denied guest*, both
 * of which are decided before any socket is involved, and both of which the
 * suite can assert exactly. The live round trip was verified separately by
 * POSTing to the running server both with and without a session.
 *
 * Throwaway rows only — removed again in _after().
 */
final class PushSubscribeIdentityTest extends \Codeception\Test\Unit
{
    /** A 65-byte uncompressed P-256 point and a 16-byte auth secret, base64url. */
    private const P256DH = 'BJ1AmzbhvoOehT4bbDFRffLjMRq0KwB3viUfCbnCTL4j7hd-yCeCtJB42IF4g2HhpxtQEfMKskvdedLA0wpoBrY';
    private const AUTH = 'kw8pJcDrZ2p1AAnrMsIRkg';

    private ConnectionInterface $db;
    private UserRepository $users;
    private ActivityLogRepository $logs;
    private PushSubscriptionRepository $subs;

    /** @var int[] */
    private array $userIds = [];
    /** @var string[] */
    private array $endpoints = [];
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->logs = new ActivityLogRepository($this->db);
        $this->subs = new PushSubscriptionRepository($this->db);

        $this->loadVapidKeys();
    }

    protected function _after(): void
    {
        foreach ($this->endpoints as $endpoint) {
            $this->db->createCommand()->delete('{{%push_subscription}}', ['endpoint' => $endpoint])->execute();
        }
        $this->endpoints = [];

        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%push_subscription}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];

        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if ($this->previousEnv[$name] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $this->previousEnv[$name];
            }
        }
        $this->previousEnv = [];

        $this->db->close();
    }

    // ---- The regression itself ---------------------------------------------

    /**
     * The failure this suite exists for: a signed-in user's browser must be
     * stored against that user, not left anonymous.
     */
    public function testSignedInSubscriptionIsBoundToTheUser(): void
    {
        $userId = $this->makeUser('bound');
        $endpoint = $this->makeEndpoint();

        $response = $this->subscribeThroughMiddleware($userId, $endpoint);

        assertSame(200, $response->getStatusCode());
        assertSame(
            $userId,
            $this->storedUserId($endpoint),
            'a signed-in visitor must own their subscription row — this is the whole point of the middleware'
        );
        assertSame(
            $userId,
            $this->decoded($response)['data']['user_id'],
            'the response has to report the claim too, or the client cannot tell it worked'
        );
    }

    /**
     * Guests are the reason the route was ever outside a guard, so they have to
     * keep working — and the row has to stay honestly anonymous rather than
     * being attributed to whoever happens to hold row id 1.
     */
    public function testGuestSubscriptionSucceedsAndStaysAnonymous(): void
    {
        $endpoint = $this->makeEndpoint();

        $response = $this->subscribeThroughMiddleware(null, $endpoint);

        assertSame(200, $response->getStatusCode(), 'guarding the endpoint would delete the feature');
        assertNull($this->storedUserId($endpoint), 'a guest has no account to bind to');
        assertNull($this->decoded($response)['data']['user_id']);
    }

    /**
     * The control for the two above. Without it, the first test would also pass
     * if the middleware had been wired up so that *every* caller looked signed
     * in — which is exactly the other way to get this wrong.
     */
    public function testGuestRowIsNotClaimedByAnUnrelatedUser(): void
    {
        $guestEndpoint = $this->makeEndpoint();
        $this->subscribeThroughMiddleware(null, $guestEndpoint);

        $otherEndpoint = $this->makeEndpoint();
        $this->subscribeThroughMiddleware($this->makeUser('other'), $otherEndpoint);

        assertNull($this->storedUserId($guestEndpoint), 'one visitor claiming must not adopt another\'s browser');
        // Scoped to the two endpoints this test created on purpose. Functional tests here run against the
        // live database, so any real browser that has ever subscribed leaves a claimed row behind, and an
        // unscoped COUNT over the whole table would fail on that unrelated row instead of on this bug.
        assertSame(1, (int) $this->db
            ->createCommand(
                'SELECT COUNT(DISTINCT [[user_id]]) FROM {{%push_subscription}}'
                . ' WHERE [[user_id]] IS NOT NULL AND [[endpoint]] IN (:guest, :other)',
                [':guest' => $guestEndpoint, ':other' => $otherEndpoint]
            )
            ->queryScalar());
    }

    // ---- Self-healing for rows that already exist --------------------------

    /**
     * Why no backfill script ships with this fix.
     *
     * `push-prompt.js` re-posts the browser's current subscription on every
     * throttled page load once permission is granted, and `subscribe()` claims
     * `user_id` whenever it is handed one. So an already-broken anonymous row
     * repairs itself the first time its owner loads a page while signed in —
     * which is the only repair that can work, since the alternative is
     * guessing from endpoint patterns that no longer match anything.
     */
    public function testExistingAnonymousRowIsClaimedOnResubscribe(): void
    {
        $userId = $this->makeUser('heal');
        $endpoint = $this->makeEndpoint();

        // As it stands today in production: a row with no owner.
        $this->subs->subscribe($endpoint, self::P256DH, self::AUTH, null, 'Chrome/120');
        assertNull($this->storedUserId($endpoint));

        $this->subscribeThroughMiddleware($userId, $endpoint);

        assertSame(
            $userId,
            $this->storedUserId($endpoint),
            'the first page load after login is what has to repair the existing rows'
        );
        assertSame(
            1,
            (int) $this->db->createCommand('SELECT COUNT(*) FROM {{%push_subscription}} WHERE [[endpoint]] = :e')
                ->bindValue(':e', $endpoint)
                ->queryScalar(),
            're-subscribing must claim the row, not duplicate it'
        );
    }

    /**
     * The mirror image, and the reason the repository only writes `user_id`
     * when it has one: the same tab can be signed out again, and a signed-out
     * heartbeat must not hand the account's browser back to the anonymous pool
     * for every broadcast to reach.
     */
    public function testGuestResubscribeDoesNotStripAnExistingClaim(): void
    {
        $userId = $this->makeUser('keep');
        $endpoint = $this->makeEndpoint();
        $this->subscribeThroughMiddleware($userId, $endpoint);

        $this->subscribeThroughMiddleware(null, $endpoint);

        assertSame(
            $userId,
            $this->storedUserId($endpoint),
            'an anonymous re-subscribe must never disown a row that belongs to an account'
        );
    }

    // ---- The guard must stay a guard ---------------------------------------

    /**
     * Optional mode is a single flag on one call site. If it ever reaches the
     * real guard, /admin becomes reachable without a session — so the default
     * is asserted, not assumed.
     */
    public function testStrictModeStillDeniesGuests(): void
    {
        $reached = false;
        $guard = new AuthMiddleware($this->session(null), $this->users, $this->logs, $this->url(), 'admin', false);

        $response = $guard->process($this->pushRequest($this->makeEndpoint()), $this->reachedHandler($reached));

        assertSame(401, $response->getStatusCode(), 'AuthMiddleware must keep denying guests by default');
        assertFalse($reached, 'a denied request must never reach the handler');
    }

    /**
     * Role enforcement is independent of optional mode, and has to survive it:
     * publishing an identity is not the same thing as admitting everybody.
     */
    public function testOptionalModeStillEnforcesTheRoleWhenItHasAnIdentity(): void
    {
        $userId = $this->makeUser('plain', 'user');
        $reached = false;
        $guard = new AuthMiddleware($this->session($userId), $this->users, $this->logs, $this->url(), 'admin', true);

        $response = $guard->process($this->pushRequest($this->makeEndpoint()), $this->reachedHandler($reached));

        assertSame(403, $response->getStatusCode(), 'a signed-in non-admin still has no business in /admin');
        assertFalse($reached, 'a rejected request must never reach the handler');
    }

    /**
     * A deleted or suspended account's session is not trusted: the row is
     * dropped and the identity comes back null. Optional mode lets the request
     * continue as a guest rather than logging it out with a 401, because the
     * /app page has to keep rendering for somebody whose session is stale.
     */
    public function testStaleSessionIsDroppedAndTheRequestContinuesAsGuest(): void
    {
        $userId = $this->makeUser('stale');
        $this->db->createCommand()->update('{{%user}}', ['status' => 'inactive'], ['id' => $userId])->execute();
        $endpoint = $this->makeEndpoint();

        $session = $this->session($userId);
        $seen = null;
        $response = (new OptionalAuthMiddleware($session, $this->users, $this->logs, $this->url()))
            ->process($this->pushRequest($endpoint), $this->captureHandler($seen));

        assertSame(200, $response->getStatusCode());
        assertNull($seen, 'a suspended account is not an identity');
        assertNull($this->storedUserId($endpoint), 'a stale session must not bind a browser to a dead account');
    }

    // ---- Wiring ------------------------------------------------------------

    /**
     * The endpoint is only reachable through the new middleware because the
     * route group says so. This reads the real config file, because the bug
     * being fixed lived precisely in the gap between the route table and the
     * action.
     */
    public function testSubscribeAndUnsubscribeRoutesAreWrappedInOptionalAuth(): void
    {
        $routes = file_get_contents(codecept_root_dir() . 'config/common/routes.php');
        assertFalse(
            $routes === false,
            'config/common/routes.php must be readable — this assertion is about its contents'
        );

        assertSame(
            2,
            substr_count($routes, 'Route::post(\'/api/push/subscribe\')') + substr_count($routes, 'Route::post(\'/api/push/unsubscribe\')'),
            'both push POSTs are expected to be declared exactly once'
        );

        assertSame(
            1,
            substr_count($routes, "Route::get('/api/push/key')"),
            '/api/push/key is expected to be declared exactly once'
        );

        $block = $this->groupBlock($routes, 'middleware(OptionalAuthMiddleware::class)');
        assertTrue(
            str_contains($block, "'/api/push/subscribe'") && str_contains($block, "'/api/push/unsubscribe'"),
            'both push POSTs must sit inside the OptionalAuthMiddleware group,'
            . ' or the identity attribute is never set and every row stays anonymous'
        );

        // /api/push/key needs no identity and must stay outside: it is what a
        // signed-out tab reads, and pulling it into a session-resolving group
        // would be a step towards guarding it for no gain.
        assertFalse(
            str_contains($block, '/api/push/key'),
            '/api/push/key is a public read and must not require the identity middleware'
        );
    }

    // ---- Fixtures ----------------------------------------------------------

    private function subscribeThroughMiddleware(?int $userId, string $endpoint): ResponseInterface
    {
        $seen = null;
        $response = (new OptionalAuthMiddleware(
            $this->session($userId),
            $this->users,
            $this->logs,
            $this->url(),
        ))->process(
            $this->pushRequest($endpoint),
            new class ($this->subs, $seen) implements RequestHandlerInterface {
                private PushSubscriptionRepository $subs;

                /** @var mixed */
                private $seen;

                public function __construct(PushSubscriptionRepository $subs, &$seen)
                {
                    $this->subs = $subs;
                    $this->seen = &$seen;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->seen = $request;
                    $route = new CurrentRoute();
                    $route->setRouteWithArguments(
                        Route::post('/api/push/subscribe')->name('api-push-subscribe'),
                        [],
                    );
                    return (new PushApiAction($this->subs))($request, $route);
                }
            },
        );

        assertInstanceOf(ServerRequestInterface::class, $seen, 'the handler must actually be reached');
        return $response;
    }

    private function pushRequest(string $endpoint): ServerRequestInterface
    {
        $body = json_encode([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ]);

        return (new ServerRequest('POST', 'http://localhost/api/push/subscribe'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create((string) $body))
            ->withParsedBody(json_decode((string) $body, true));
    }

    /** @param mixed $seen */
    private function captureHandler(&$seen): RequestHandlerInterface
    {
        return new class ($seen) implements RequestHandlerInterface {
            /** @var mixed */
            private $seen;

            public function __construct(&$seen)
            {
                $this->seen = &$seen;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request->getAttribute('identity');
                return new Response(200);
            }
        };
    }

    /** @param mixed $reached set to true the moment a handler is invoked */
    private function reachedHandler(&$reached): RequestHandlerInterface
    {
        return new class ($reached) implements RequestHandlerInterface {
            /** @var mixed */
            private $reached;

            public function __construct(&$reached)
            {
                $this->reached = &$reached;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;
                return new Response(200);
            }
        };
    }

    private function session(?int $userId): SessionInterface
    {
        return new class ($userId) implements SessionInterface {
            /** @var array<string, mixed> */
            private array $data;
            private string $id = 'test-session';

            public function __construct(?int $userId)
            {
                $this->data = $userId === null ? [] : ['user_id' => $userId];
            }

            public function get(string $key, $default = null)
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, $value): void
            {
                $this->data[$key] = $value;
            }

            public function close(): void {}

            public function open(): void {}

            public function isActive(): bool
            {
                return true;
            }

            public function getId(): ?string
            {
                return $this->id;
            }

            public function setId(string $sessionId): void
            {
                $this->id = $sessionId;
            }

            public function regenerateId(): void {}

            public function discard(): void {}

            public function getName(): string
            {
                return 'PHPSESSID';
            }

            public function all(): array
            {
                return $this->data;
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }

            public function pull(string $key, $default = null)
            {
                $value = $this->get($key, $default);
                $this->remove($key);
                return $value;
            }

            public function clear(): void
            {
                $this->data = [];
            }

            public function destroy(): void
            {
                $this->data = [];
            }

            public function getCookieParameters(): array
            {
                return [];
            }
        };
    }

    private function url(): UrlGeneratorInterface
    {
        return new class () implements UrlGeneratorInterface {
            public function generate(
                string $name,
                array $arguments = [],
                array $queryParameters = [],
                ?string $hash = null
            ): string {
                return '/' . $name;
            }

            public function generateAbsolute(
                string $name,
                array $arguments = [],
                array $queryParameters = [],
                ?string $hash = null,
                ?string $scheme = null,
                ?string $host = null
            ): string {
                return 'http://localhost/' . $name;
            }

            public function generateFromCurrent(
                array $replacedArguments,
                array $queryParameters = [],
                ?string $hash = null,
                ?string $fallbackRouteName = null
            ): string {
                return 'http://localhost/';
            }

            public function getUriPrefix(): string
            {
                return '';
            }

            public function setUriPrefix(string $name): void {}

            public function setDefaultArgument(string $name, $value): void {}
        };
    }

    private function makeUser(string $tag, string $role = 'admin'): int
    {
        $id = $this->users->create([
            'username' => 'pi_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 8),
            'phone' => '7' . substr(md5(uniqid('', true)), 0, 9),
            'email' => null,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
        ]);
        $this->userIds[] = $id;
        return $id;
    }

    private function makeEndpoint(): string
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/IDENTITY' . substr(md5(uniqid('', true)), 0, 16);
        $this->endpoints[] = $endpoint;
        return $endpoint;
    }

    private function storedUserId(string $endpoint): ?int
    {
        $value = $this->db
            ->createCommand('SELECT [[user_id]] FROM {{%push_subscription}} WHERE [[endpoint]] = :e')
            ->bindValue(':e', $endpoint)
            ->queryScalar();

        return $value === null || $value === false ? null : (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * The text from `$marker` to the first `),` that closes the group call —
     * enough to see which routes a middleware wraps, without pulling in a YAML
     * parser or reimplementing the router.
     */
    private function groupBlock(string $routes, string $marker): string
    {
        $start = strpos($routes, $marker);
        assertTrue($start !== false, "expected to find '$marker' in config/common/routes.php");

        $end = strpos($routes, "\n    ),", $start);
        assertTrue($end !== false, "expected '$marker' to close with a group terminator");

        return substr($routes, $start, $end - $start);
    }

    /**
     * Web Push's behaviour *is* the presence of the credentials: with no keys
     * the action short-circuits to 503 before it ever looks at the identity,
     * and every assertion here would pass for the wrong reason.
     *
     * Only the two variables this test needs are set, and _after() puts them
     * back exactly as it found them — the suite must not inherit the whole
     * .env into $_ENV.
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
}