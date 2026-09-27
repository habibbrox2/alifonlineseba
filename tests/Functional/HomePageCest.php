<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class HomePageCest
{
    public function base(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(uri: '/'),
        );

        assertSame(200, $response->getStatusCode());
        $body = $response->getBody()->getContents();
        assertStringContainsString(
            'ডিজিটাল সেবা এক ক্লিকে!',
            $body,
        );
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
