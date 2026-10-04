<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Resolves the logged-in user from session for every request and exposes it
 * via {@see CurrentRoute} attribute "identity".
 *
 * Acts as a guard: guests are redirected to /login (or get 401 JSON for API
 * requests) and users without the required role get 403. With an empty role
 * any authenticated user may proceed.
 *
 * ## Optional mode
 *
 * Some endpoints have to serve guests *and* want to know who the guest is when
 * they turn out not to be one. Web Push is the case: a signed-out visitor on
 * /app must be able to grant permission, yet the same endpoint is what binds
 * that browser to an account once they log in. Guarding it would break the
 * feature; not resolving identity at all is what made every subscription
 * anonymous (see {@see OptionalAuthMiddleware}).
 *
 * Optional mode still resolves and still publishes the attribute — it only
 * declines to deny. The handler therefore reads one shape either way, and a
 * stale session is still cleaned up rather than silently ignored.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $logs,
        private readonly UrlGeneratorInterface $url,
        private readonly string $requiredRole = '',
        private readonly bool $optional = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->session->get('user_id');
        $identity = null;

        if ($userId !== null) {
            // findById() hides trashed accounts, so deleting a user logs their
            // live session out on the very next request.
            $row = $this->users->findById((int) $userId);
            if ($row !== null && $row['status'] === 'active') {
                $identity = Identity::fromRow($row);
            } else {
                $this->session->remove('user_id');
                $this->session->regenerateID();
            }
        }

        $request = $request->withAttribute('identity', $identity);

        if ($identity === null) {
            // `identity` is already on the request above (as null), so the
            // optional path hands the handler exactly the same attribute it
            // would have seen had the caller been signed in all along.
            return $this->optional ? $handler->handle($request) : $this->deny($request, 401);
        }

        if ($this->requiredRole === 'admin' && !$identity->canAccessAdmin()) {
            return $this->deny($request, 403);
        }

        return $handler->handle($request);
    }

    private function deny(ServerRequestInterface $request, int $code): ResponseInterface
    {
        $wantsJson = str_starts_with($request->getUri()->getPath(), '/api/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($wantsJson) {
            $payload = [
                'success' => false,
                'message' => $code === 401 ? 'Authentication required.' : 'You do not have permission to perform this action.',
                'data' => null,
                'errors' => [],
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            return new \Nyholm\Psr7\Response($code, ['Content-Type' => 'application/json; charset=UTF-8'], $json);
        }

        if ($code === 401) {
            $this->logs->create([
                'action' => 'auth.redirect',
                'description' => 'Guest redirected to login from ' . $request->getUri()->getPath(),
                'ip_address' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ]);
            $loginUrl = $this->url->generate('login');
            return new \Nyholm\Psr7\Response(302, ['Location' => $loginUrl]);
        }

        return new \Nyholm\Psr7\Response(403, ['Content-Type' => 'text/html; charset=UTF-8'],
            '<!DOCTYPE html><html lang="bn"><head><meta charset="UTF-8"><title>403</title></head>'
            . '<body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center">'
            . '<div><h1 style="font-size:3rem;color:#0d8f68">403</h1><p>আপনার এই পেজ দেখার অনুমতি নেই।</p>'
            . '<p><a href="/" style="color:#0d8f68">← হোমে ফিরে যান</a></p></div></body></html>');
    }
}
