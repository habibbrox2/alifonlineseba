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
 * API guard, bearer-first (Phase 1.5): a valid `Authorization: Bearer` token
 * authenticates machine clients (the APK) without any session; everyone else
 * falls through to the original session path so the web app is untouched.
 */
final readonly class ApiAuthMiddleware implements MiddlewareInterface
{
    private AuthMiddleware $inner;
    private ApiTokenRepository $tokens;

    public function __construct(
        SessionInterface $session,
        UserRepository $users,
        ActivityLogRepository $logs,
        UrlGeneratorInterface $url,
        ApiTokenRepository $tokens,
    ) {
        $this->inner = new AuthMiddleware($session, $users, $logs, $url, AuthMiddleware::ROLE_USER);
        $this->tokens = $tokens;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $bearer = self::bearer($request);
        if ($bearer !== null) {
            $userId = $this->tokens->resolve($bearer);
            if ($userId !== null) {
                $row = $this->users->findById($userId);
                if ($row !== null) {
                    return $handler->handle($request->withAttribute('identity', Identity::fromRow($row)));
                }
            }
            // A presented-but-invalid token must NOT fall back to session:
            // an expired APK token silently becoming a logged-in browser
            // session would be surprising at best. Reject explicitly.
            return \App\Service\Api::unauthorized();
        }

        return $this->inner->process($request, $handler);
    }

    public static function bearer(ServerRequestInterface $request): ?string
    {
        $header = trim($request->getHeaderLine('Authorization'));
        if ($header === '' || !str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
