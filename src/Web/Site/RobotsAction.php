<?php

declare(strict_types=1);

namespace App\Web\Site;

use App\Env;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Dynamic robots.txt.
 *
 * This used to be a static file in public/, which meant the sitemap line was
 * whatever host last deployed it happened to hardcode — and the staging copy
 * published on beta.allseba.online pointed crawlers at production's sitemap.
 * Serving it from a route (and deleting the static file, which both Apache's
 * "serve existing files directly" rule and the dev server's is_file() check
 * would otherwise answer first) keeps the single source of truth in APP_URL,
 * the same value the sitemap itself is built from.
 *
 * Deliberately host-independent; see App\Web\NoIndexMiddleware for why the
 * noindex signal lives in a response header instead of here.
 */
final readonly class RobotsAction
{
    private const DISALLOWED = [
        '/dashboard',
        '/services/',
        '/transactions',
        '/notifications',
        '/profile',
        '/admin',
        '/api/',
        '/logout',
    ];

    public function __invoke(): ResponseInterface
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');

        $body = "# TH Tools robots.txt — পাবলিক পেজ ইনডেক্স হবে, প্রাইভেট অ্যাপ নয়।\n"
            . "User-agent: *\n"
            . "Allow: /\n";

        foreach (self::DISALLOWED as $path) {
            $body .= 'Disallow: ' . $path . "\n";
        }

        $body .= "\nSitemap: {$base}/sitemap.xml\n";

        return new Response(200, ['Content-Type' => 'text/plain; charset=UTF-8'], $body);
    }
}