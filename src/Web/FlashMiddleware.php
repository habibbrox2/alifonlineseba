<?php

declare(strict_types=1);

namespace App\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Exposes one-shot "flash_success" / "flash_error" / "flash_undo" session values
 * to templates.
 *
 * `flash_undo` rides the same rail as the two messages on purpose: it is the
 * other half of one of them — the way back from the batch it announces — and it
 * has the same one-request lifetime, so an Undo button cannot outlive the flash
 * it belongs to.
 */
final class FlashMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionInterface $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach (['flash_success', 'flash_error', 'flash_undo'] as $key) {
            $value = $this->session->get($key);
            if ($value !== null) {
                $this->session->remove($key);
                $request = $request->withAttribute($key, $value);
            }
        }
        return $handler->handle($request);
    }
}
