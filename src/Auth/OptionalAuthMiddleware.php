<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Publishes the session identity without ever denying the request.
 *
 * ## Why this exists
 *
 * `PushApiAction` reads the `identity` attribute to decide whether to store
 * `user_id` on a browser subscription, and it has always read it. The route
 * simply never had a middleware to *set* it: `/api/push/subscribe` sits outside
 * both the `AuthMiddleware` group and the `ApiAuthMiddleware` group, because
 * a signed-out visitor on /app is the exact person the prompt is trying to
 * reach. So the attribute was always absent, `$identity instanceof Identity`
 * was always false, and every row in `push_subscription` was stored with
 * `user_id = NULL` — including rows created by a signed-in admin on another
 * page of the site.
 *
 * That is worse than it looks. `activeForUser()` is the per-account fan-out
 * list, so a browser that *was* signed in received only broadcasts
 * (`activeAnonymous()`), never the account notifications it had opted into.
 *
 * ## Why not just add AuthMiddleware
 *
 * Because it denies guests, and that would remove the feature. The endpoint has
 * to accept an anonymous browser and *additionally* claim the endpoint for an
 * account when there is one.
 *
 * ## Why this is a wrapper and not a flag on the route
 *
 * `AdminMiddleware` sets the precedent: a route group names a concrete class
 * the container can autowire, and that class builds the `AuthMiddleware` it
 * needs. A `bool` constructor argument on `AuthMiddleware` itself would be
 * unautowirable, so the flag stays a constructor detail of the inner guard.
 *
 * ## Self-healing
 *
 * No backfill is needed. `push-prompt.js` re-posts the browser's current
 * subscription on (throttled) page loads for anyone whose permission is
 * already granted, and `PushSubscriptionRepository::subscribe()` claims
 * `user_id` whenever one is supplied. So the first page load a signed-in user
 * makes after this ships binds their existing anonymous rows — which is how
 * the rows that already exist get fixed rather than merely avoided.
 */
final readonly class OptionalAuthMiddleware implements MiddlewareInterface
{
    private AuthMiddleware $inner;

    public function __construct(
        SessionInterface $session,
        UserRepository $users,
        ActivityLogRepository $logs,
        UrlGeneratorInterface $url,
    ) {
        $this->inner = new AuthMiddleware($session, $users, $logs, $url, '', true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->inner->process($request, $handler);
    }
}