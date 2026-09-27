<?php

declare(strict_types=1);

namespace App\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Exposes one-shot "flash_success" / "flash_error" session values to templates.
 */
final class FlashMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionInterface $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach (['flash_success', 'flash_error'] as $key) {
            $value = $this->session->get($key);
            if ($value !== null) {
                $this->session->remove($key);
                $request = $request->withAttribute($key, $value);
            }
        }
        return $handler->handle($request);
    }
}
