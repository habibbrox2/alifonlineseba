<?php

declare(strict_types=1);

namespace App\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rewrites the request URI scheme from X-Forwarded-Proto.
 *
 * Behind a TLS-terminating reverse proxy (Cloudflare tunnel) the origin only ever
 * sees plain HTTP, so Yii believes the connection is insecure and refuses to set
 * `Secure` cookies. The header is only honoured when the immediate peer is loopback,
 * so a direct external request cannot spoof it.
 */
final class ForwardedProtoMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($this->rewrite($request));
    }

    private function rewrite(ServerRequestInterface $request): ServerRequestInterface
    {
        $uri = $request->getUri();
        $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));

        if ($proto !== 'https' || $uri->getScheme() === 'https' || !$this->isLoopback($request)) {
            return $request;
        }

        return $request->withUri($uri->withScheme('https'));
    }

    private function isLoopback(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? null;

        return is_string($remote) && in_array($remote, ['127.0.0.1', '::1'], true);
    }
}
