<?php

declare(strict_types=1);

namespace App\Web\Site;

use App\Env;
use Psr\Http\Message\ResponseInterface;

/**
 * Static public sitemap.xml. Only indexable public pages are listed —
 * service/category pages require login (robots.txt disallows them),
 * so they are intentionally excluded.
 */
final readonly class SitemapAction
{
    public function __invoke(): ResponseInterface
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        $today = date('Y-m-d');

        $urls = [
            ['loc' => $base . '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $base . '/about', 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => $base . '/privacy', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => $base . '/terms', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => $base . '/login', 'changefreq' => 'monthly', 'priority' => '0.4'],
            ['loc' => $base . '/register', 'changefreq' => 'monthly', 'priority' => '0.4'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n"
                . '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n"
                . '    <lastmod>' . $today . "</lastmod>\n"
                . '    <changefreq>' . $url['changefreq'] . "</changefreq>\n"
                . '    <priority>' . $url['priority'] . "</priority>\n"
                . "  </url>\n";
        }
        $xml .= "</urlset>\n";

        return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'application/xml; charset=UTF-8'], $xml);
    }
}
