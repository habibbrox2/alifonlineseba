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
 * Admin-area API guard, bearer-first: a valid `Authorization: Bearer`
 * token authenticates the admin Android app without any session; everyone
 * else falls through to the original session path so the web panel is
 * untouched.
 *
 * Same security contract as {@see ApiAuthMiddleware}:
 *
 * - A presented-but-invalid token must NOT fall back to session. An
 *   expired app token silently becoming a logged-in browser session
 *   would be surprising at best — reject explicitly.
 * - A valid token owned by a non-admin account is 403, not 401: the
 *   credential is real, the authority is not.
 *
 * Role authority is re-read from the live row (the token only proves
 * *who* the caller is), so an account demoted minutes ago loses API
 * access on the next request even though their token is still valid.
 */
final readonly class AdminApiMiddleware implements MiddlewareInterface
{
    private AdminMiddleware $inner;
    private ApiTokenRepository $tokens;

    public function __construct(
        SessionInterface $session,
        UserRepository $users,
        ActivityLogRepository $logs,
        UrlGeneratorInterface $url,
        ApiTokenRepository $tokens,
    ) {
        $this->inner = new AdminMiddleware($session, $users, $logs, $url);
        $this->tokens = $tokens;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $bearer = ApiAuthMiddleware::bearer($request);
        if ($bearer !== null) {
            $userId = $this->tokens->resolve($bearer);
            if ($userId !== null) {
                $row = $this->users->findById($userId);
                // resolve() already hid trashed/inactive accounts; the
                // status check is the belt to those suspenders, and it
                // is what the session path checks too.
                if ($row !== null && $row['status'] === 'active') {
                    $identity = Identity::fromRow($row);
                    if (!$identity->canAccessAdmin()) {
                        return \App\Service\Api::forbidden();
                    }

                    return $handler->handle($request->withAttribute('identity', $identity));
                }
            }

            return \App\Service\Api::unauthorized();
        }

        return $this->inner->process($request, $handler);
    }
}
