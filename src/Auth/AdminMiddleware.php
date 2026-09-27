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
 * Admin-area guard: requires an authenticated user with admin/staff role.
 */
final readonly class AdminMiddleware implements MiddlewareInterface
{
    private AuthMiddleware $inner;

    public function __construct(
        SessionInterface $session,
        UserRepository $users,
        ActivityLogRepository $logs,
        UrlGeneratorInterface $url,
    ) {
        $this->inner = new AuthMiddleware($session, $users, $logs, $url, 'admin');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->inner->process($request, $handler);
    }
}
