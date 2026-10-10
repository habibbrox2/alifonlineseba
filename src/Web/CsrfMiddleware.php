<?php

declare(strict_types=1);

namespace App\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Csrf\CsrfTokenMiddleware;

/**
 * CSRF validation for the whole site, with one path that is exempt.
 *
 * The global middleware sits *before* the router, so it cannot know which
 * route a request is headed for — every unsafe method needs a token, or it is
 * turned away with 422. That is right for a form of ours and wrong for
 * `/__/auth/*`: that route is a pass-through proxy of Firebase's own sign-in
 * handler, posted to by the Firebase SDK's popup/iframe from a page that is
 * not ours and has never seen `_csrf`. Validating it would answer 422 before
 * the request ever reached Firebase, and Firebase Authentication would fail
 * with no error anyone could read.
 *
 * Skipping it grants nothing. The proxy reads no session, writes no state and
 * stores nothing — the only thing a forged POST could do is make somebody's
 * browser fetch a Firebase page whose response it cannot read. Everything
 * else keeps the exact token check it had; this class holds no logic of its
 * own beyond "is this the proxy".
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    /** Where Firebase's handler lives, as the browser requests it. */
    private const PROXY_PREFIX = '/__/auth/';

    public function __construct(private CsrfTokenMiddleware $csrf) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_starts_with($request->getUri()->getPath(), self::PROXY_PREFIX)) {
            return $handler->handle($request);
        }

        // A `Bearer` header is proof the request did not come from a plain
        // HTML form: a cross-site form post cannot set custom headers, and a
        // cross-origin fetch that tried would fail its CORS preflight (this
        // app sends no CORS headers). Such a client has no session to forge
        // against — the Android admin app keeps no cookies at all — so there
        // is nothing for CSRF to protect and demanding a token would only
        // lock machine clients out of POST /api/auth/login forever.
        if (strncasecmp($request->getHeaderLine('Authorization'), 'Bearer ', 7) === 0) {
            return $handler->handle($request);
        }

        return $this->csrf->process($request, $handler);
    }
}
