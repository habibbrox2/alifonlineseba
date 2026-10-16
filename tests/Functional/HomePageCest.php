<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class HomePageCest
{
    public function base(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/'),
        );

        assertSame(200, $response->getStatusCode());
        $body = $response->getBody()->getContents();
        // The hero highlights its second half in an accent span, so the line
        // is never contiguous in the source — both fragments must be there.
        assertStringContainsString('ডিজিটাল সেবা', $body);
        assertStringContainsString('এক ক্লিকে!', $body);
    }

    public function popularShelfCarriesNoPrice(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/'),
        );

        assertSame(200, $response->getStatusCode());
        $body = $response->getBody()->getContents();
        // The home shelf is the storefront: its cells link to the detail page
        // but draw no price — only the category catalog and the detail page
        // may quote a number.
        assertStringNotContainsString('accent-value', $body);
        assertStringContainsString('/services/view/', $body);
    }

    public function loginPage(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/login'),
        );

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('লগইন', $response->getBody()->getContents());
    }

    public function guestIsRedirectedFromDashboard(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/dashboard'),
        );

        assertSame(302, $response->getStatusCode());
        assertStringContainsString('/login', $response->getHeaderLine('Location'));
    }

    public function guestIsRedirectedFromApi(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/api/dashboard'),
        );

        assertSame(401, $response->getStatusCode());
    }

    public function errorPageRendersFriendlyView(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/non-existent-page'),
        );

        assertSame(404, $response->getStatusCode());
        $body = $response->getBody()->getContents();
        assertStringContainsString('404', $body);
        assertStringContainsString('পাওয়া যায়নি', $body);
    }
}
