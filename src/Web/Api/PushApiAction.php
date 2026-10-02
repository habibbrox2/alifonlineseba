<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Notification\Push\VapidKeys;
use App\Repository\PushSubscriptionRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * The Web Push handshake, in three endpoints:
 *
 *   GET  /api/push/key          the VAPID public key to subscribe with
 *   POST /api/push/subscribe    store or refresh one Push API subscription
 *   POST /api/push/unsubscribe  deactivate one subscription
 *
 * ## Why none of this sits behind ApiAuthMiddleware
 *
 * The point of browser push is to reach the person who has *not* installed the
 * app yet — and who, on the /app page, has no reason to be signed in. The
 * subscription endpoint therefore treats identity as decoration: it is recorded
 * when present (so a later notification fans out to the account) and left null
 * when not (so broadcasts can still reach the tab). `activeAnonymous()` is
 * what reads those rows back.
 *
 * An unauthenticated write endpoint is not something to hand out lightly, so
 * the two guards here are:
 *
 *  - **CSRF.** Both POSTs go through `CsrfTokenMiddleware`, so a third-party
 *    site cannot silently subscribe or unsubscribe somebody's browser. The
 *    token is already in the page as `<meta name="_csrf">`.
 *  - **Input validation.** The endpoint is stored verbatim and is later the
 *    URL `WebPushChannel` POSTs to with a VAPID-signed body. A row edited to
 *    `http://…` would turn a subscription into a server-side request to
 *    somewhere of the row author's choosing, so the scheme is checked here, at
 *    the only point where a caller can introduce one.
 */
final readonly class PushApiAction
{
    /** Mozilla endpoints run ~190 chars; 512 leaves room for the growing ones. */
    private const MAX_ENDPOINT = 512;

    public function __construct(private PushSubscriptionRepository $subscriptions) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        return match ($route->getName()) {
            'api-push-key' => $this->key(),
            'api-push-unsubscribe' => $this->unsubscribe($request),
            default => $this->subscribe($request),
        };
    }

    /**
     * The public half of the VAPID key pair.
     *
     * Reported as `enabled: false` rather than 404 when the deployment has no
     * keys: "this server has not configured push" is a state the /app page has
     * to render differently from "this endpoint is wrong", and a 503 would make
     * the two indistinguishable from the console.
     */
    private function key(): ResponseInterface
    {
        $keys = VapidKeys::fromEnv();

        return Api::ok([
            'enabled' => $keys !== null,
            'public_key' => $keys?->publicKey(),
            'subject' => $keys?->subject(),
        ]);
    }

    /** Accept a `PushSubscription.toJSON()` body: {endpoint, keys:{p256dh,auth}}. */
    private function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        $keys = VapidKeys::fromEnv();
        if ($keys === null) {
            // Nothing would ever be delivered to this row, so storing it would
            // be worse than useless: it would show up as an active
            // subscription in the fan-out list forever.
            return Api::fail('Push notifications are not configured on this server.', [], 503);
        }

        $body = (array) $request->getParsedBody();
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        $keyData = (array) ($body['keys'] ?? []);
        $p256dh = trim((string) ($keyData['p256dh'] ?? ''));
        $auth = trim((string) ($keyData['auth'] ?? ''));

        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT) {
            return Api::fail('A push endpoint is required.', ['endpoint' => 'required'], 422);
        }
        if (!str_starts_with(strtolower($endpoint), 'https://')) {
            return Api::fail('Unsupported push endpoint.', ['endpoint' => 'must be an https URL'], 422);
        }
        // RFC 8291 fixes the key shapes: a 65-byte uncompressed P-256 point and
        // a 16-byte auth secret. Validating the decoded length (rather than the
        // base64 text) is what catches a truncated or padded key before the
        // sender fails on it hours later.
        if (strlen(self::decodeBase64Url($p256dh)) !== 65) {
            return Api::fail('Malformed push subscription keys.', ['keys.p256dh' => 'invalid'], 422);
        }
        if (strlen(self::decodeBase64Url($auth)) !== 16) {
            return Api::fail('Malformed push subscription keys.', ['keys.auth' => 'invalid'], 422);
        }

        /** @var Identity|null $identity */
        $identity = $request->getAttribute('identity');
        $userId = $identity instanceof Identity ? $identity->id : null;

        $id = $this->subscriptions->subscribe(
            $endpoint,
            $p256dh,
            $auth,
            $userId,
            trim($request->getHeaderLine('User-Agent')),
        );

        return Api::ok(
            ['id' => $id, 'user_id' => $userId, 'public_key' => $keys->publicKey()],
            'Notifications enabled.',
        );
    }

    /**
     * Deactivate by endpoint, or by id for a signed-in caller.
     *
     * The endpoint is accepted unauthenticated because the browser that holds
     * it is the browser being unsubscribed, and it is already a capability —
     * anyone able to present it can send to that subscription regardless. The
     * id form is scoped to the caller so an account cannot silence somebody
     * else's browser by counting rows.
     */
    private function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        $id = (int) ($body['id'] ?? 0);

        /** @var Identity|null $identity */
        $identity = $request->getAttribute('identity');
        $userId = $identity instanceof Identity ? $identity->id : null;

        if ($id <= 0 && $endpoint !== '') {
            $row = $this->subscriptions->findByEndpoint($endpoint);
            $id = $row === null ? 0 : (int) $row['id'];
        }

        if ($id <= 0) {
            // Already gone is the desired end state, so this is a success and
            // not an error — the client is retrying after a rotation.
            return Api::ok(['deactivated' => false], 'Nothing to unsubscribe.');
        }

        $ok = $this->subscriptions->unsubscribe($id, $userId);

        return Api::ok(['deactivated' => $ok], $ok ? 'Notifications disabled.' : 'Subscription not found.');
    }

    /** base64url → bytes, tolerating the padded and standard-alphabet variants. */
    private static function decodeBase64Url(string $value): string
    {
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4),
            false,
        );

        return $decoded === false ? '' : $decoded;
    }
}
