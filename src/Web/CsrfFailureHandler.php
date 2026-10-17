<?php

declare(strict_types=1);

namespace App\Web;

use App\Service\Api;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function htmlspecialchars;

/**
 * Answers a request the CSRF gate refused, in place of the bare 422 the stock
 * middleware writes.
 *
 * `CsrfTokenMiddleware` builds that response itself whenever no failure handler
 * is supplied: a status line and no body at all. To a machine client that is
 * merely blunt. On the login form it is a dead end — the visitor sees a blank
 * page, and the only evidence that anything went wrong is a 422 in a console
 * they may never think to open.
 *
 * The common cause is not an attack at all. The token is minted into the session
 * that rendered the form, so any later login, logout or double submit rotates
 * the session out from under a tab that is still open, and the form posts a
 * token for a session the browser no longer holds. That is what this page is for.
 *
 * What it deliberately does not do is pass the request on. Re-rendering the
 * target with a fresh token would let through the very forgeries CSRF exists to
 * stop, and echoing the submitted fields would reflect an attacker's payload
 * back at a browser that rendered them. It says what happened and offers a
 * reload, which mints a token matching the session the browser now holds. The
 * status stays 422: the gate still refuses exactly what it refused before.
 */
final class CsrfFailureHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            return Api::fail('Session expired. Reload the page and try again.', [], 422);
        }

        return new Response(
            422,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
            $this->page($this->reloadTarget($request)),
        );
    }

    /** The same test {@see \App\Auth\AuthMiddleware} uses to pick a client. */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getUri()->getPath(), '/api/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * Where "try again" points.
     *
     * Path and query only, never the scheme or host the request arrived on: a
     * server-built URI's path always begins with `/`, so the link cannot be
     * turned into another origin by a forged request line.
     */
    private function reloadTarget(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $target = $uri->getPath();

        if ($uri->getQuery() !== '') {
            $target .= '?' . $uri->getQuery();
        }

        return htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Hand-rolled rather than a Twig page, for the reason the 403 in
     * {@see \App\Auth\AuthMiddleware} is: this runs on the failure path, where a
     * template error would replace a readable message with a second failure.
     */
    private function page(string $target): string
    {
        return '<!DOCTYPE html><html lang="bn"><head><meta charset="UTF-8"><meta name="robots" content="noindex, nofollow">'
            . '<title>সেশন শেষ হয়ে গেছে</title></head>'
            . '<body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center">'
            . '<div>'
            . '<h1 style="font-size:3rem;color:#0d8f68">422</h1>'
            . '<p style="font-size:1.125rem">সেশনের মেয়াদ শেষ হয়ে গেছে।</p>'
            . '<p style="color:#666">পেজটি আবার খুলে আবার চেষ্টা করুন।</p>'
            . '<p style="margin-top:1.5rem"><a href="' . $target . '" style="color:#0d8f68;font-weight:600">আবার চেষ্টা করুন</a></p>'
            . '<p style="margin-top:0.5rem"><a href="/" style="color:#0d8f68">&larr; হোমে ফিরে যান</a></p>'
            . '</div></body></html>';
    }
}
