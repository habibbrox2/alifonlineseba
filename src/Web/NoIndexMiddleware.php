<?php

declare(strict_types=1);

namespace App\Web;

use App\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps non-canonical hosts out of search indexes.
 *
 * The app is published from more than one hostname: production on
 * allseba.online, and a beta copy behind a Cloudflare tunnel on
 * beta.allseba.online with APP_URL repointed at itself. That is exactly what
 * APP_URL should be on the copy — its sitemap, canonical tags, APK deep links
 * and notification links have to name the copy or they break — but it also
 * means the one piece of information that identifies the real site cannot come
 * from APP_URL. Hence CANONICAL_URL, which names the host that may be indexed
 * and defaults to APP_URL when unset.
 *
 * A header is used rather than a <meta> tag because it reaches crawlers on
 * every response — HTML, JSON, the sitemap, error pages — and cannot be lost by
 * a template that forgot to set the block. It costs nothing on the canonical
 * host, where no header is added at all, so production behaviour is unchanged.
 *
 * Deliberately NOT mirrored in robots.txt. A `Disallow: /` derived from the
 * same comparison would, on a misconfigured CANONICAL_URL, tell crawlers to
 * drop production itself; a header that is merely wrong wastes nothing.
 */
final class NoIndexMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->isCanonical($request)) {
            return $response;
        }

        return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * True when the request arrived on the one host meant to be indexed.
     *
     * Fails towards "indexable": with no canonical URL configured there is
     * nothing to compare against, and guessing would risk either deindexing
     * production or, worse, silently indexing a staging copy.
     */
    private function isCanonical(ServerRequestInterface $request): bool
    {
        $canonical = (string) Env::get('CANONICAL_URL');
        if ($canonical === '') {
            $canonical = (string) Env::get('APP_URL');
        }

        $canonicalHost = parse_url($canonical, PHP_URL_HOST);
        $requestHost = $request->getUri()->getHost();

        if (!is_string($canonicalHost) || $canonicalHost === '' || $requestHost === '') {
            return true;
        }

        // Ports are ignored on purpose: beta.allseba.online:443 and
        // beta.allseba.online are the same site.
        return strtolower($canonicalHost) === strtolower($requestHost);
    }
}