<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;

final class SeoCest
{
    public function robotsTxtListsSitemap(WebTester $I): void
    {
        $I->amOnPage('/robots.txt');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('Sitemap:');
        $I->seeInSource('Disallow: /dashboard');
        $I->seeInSource('Disallow: /admin');
    }

    public function sitemapXmlIsWellFormed(WebTester $I): void
    {
        $I->amOnPage('/sitemap.xml');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
        $I->seeInSource('<loc>');
        // Private pages must never appear in the sitemap.
        $I->dontSeeInSource('/dashboard');
        $I->dontSeeInSource('/admin');
    }

    public function homeHasCoreMetaTags(WebTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->seeElement('meta[name="description"]');
        $I->seeElement('meta[name="robots"][content="index, follow"]');
        $I->seeElement('link[rel="canonical"]');
        $I->seeElement('meta[property="og:title"]');
        $I->seeElement('meta[name="twitter:card"]');
        $I->seeElement('script[type="application/ld+json"]');
    }

    public function notFoundPageIsNoindex(WebTester $I): void
    {
        $I->amOnPage('/no-such-page-xyz');
        $I->seeResponseCodeIs(404);
        $I->seeElement('meta[name="robots"][content*="noindex"]');
    }
}
