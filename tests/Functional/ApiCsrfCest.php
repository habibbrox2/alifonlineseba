<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * The Android admin app speaks JSON with a bearer token and holds no session
 * cookie at all, so it can never present a synchronizer CSRF token — the gate
 * recognises a machine client by its `Authorization: Bearer` header instead.
 *
 * A cross-site HTML form cannot set that header, and a cross-origin fetch
 * that tried would fail its CORS preflight (this app sends no CORS headers),
 * so the exemption cannot be reached by the forgeries CSRF exists to stop.
 * These tests hold both halves of that bargain: without the header the 422
 * still comes down, with it the request reaches the action.
 */
final class ApiCsrfCest
{
    /**
     * The classic forgery: a form post with no token and no bearer header.
     * Nothing about making machine clients' lives easier may weaken this.
     */
    public function formPostWithoutBearerIsStillRefused(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->formPost('/api/auth/refresh', []));

        assertSame(
            422,
            $response->getStatusCode(),
            'No bearer and no token: the CSRF gate must still answer 422.',
        );
    }

    /**
     * The Android login/refresh path: same endpoint, same missing token, but
     * with proof-of-origin in the header. The request has to reach the action
     * — which then refuses the bogus refresh token itself, with 401.
     */
    public function bearerHeaderSkipsCsrfAndReachesTheAction(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            $this->formPost('/api/auth/refresh', [], ['Authorization' => 'Bearer not-a-real-token']),
        );

        assertNotSame(
            422,
            $response->getStatusCode(),
            'A bearer client holds no session cookie, so there is nothing to forge against.',
        );
        assertSame(
            401,
            $response->getStatusCode(),
            'The action itself answered: invalid or expired refresh token.',
        );
    }

    /**
     * The exemption is header-shaped, not path-shaped. The push endpoint a
     * browser posts to from a public page keeps its gate — same 422 the
     * suite has always asserted for a token-less, header-less POST.
     */
    public function jsonPostWithoutBearerIsStillRefused(FunctionalTester $tester): void
    {
        $stream = new Stream();
        $stream->write('{"endpoint":"https://push.example.invalid/csrf-probe","keys":{"p256dh":"x","auth":"y"}}');

        $response = $tester->sendRequest(new ServerRequest(
            method: 'POST',
            uri: '/api/push/subscribe',
            headers: ['Content-Type' => 'application/json'],
            body: $stream,
        ));

        assertSame(
            422,
            $response->getStatusCode(),
            'A header-less POST is exactly what CSRF exists to refuse.',
        );
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    private function formPost(string $uri, array $fields, array $headers = []): ServerRequest
    {
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        return new ServerRequest(
            method: 'POST',
            uri: $uri,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers],
            parsedBody: $fields,
            body: $stream,
        );
    }
}
