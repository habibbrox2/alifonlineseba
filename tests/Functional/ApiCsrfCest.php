<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

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
     * The login form is where a bare 422 hurts most.
     *
     * A form post whose session has been rotated out from under it — a tab left
     * open across a later login, a logout, a double submit — used to draw a blank
     * page whose only symptom was a status in a console nobody opens. The gate
     * still refuses the request with the same 422; what changed is that the
     * refusal now says what happened and how to get past it.
     */
    public function aFailedBrowserFormPostGetsAPageTheVisitorCanActOn(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->formPost('/login', [
            'identifier' => '01700000000',
            'password' => 'wrongpass123',
        ]));
        $body = (string) $response->getBody();

        assertSame(
            422,
            $response->getStatusCode(),
            'The gate must keep refusing a form post that carries no valid token.',
        );
        assertStringContainsString(
            'সেশনের মেয়াদ শেষ হয়ে গেছে',
            $body,
            'A refused form post has to say why, or the visitor is left staring at a blank page.',
        );
        assertStringContainsString(
            'href="/login"',
            $body,
            'The one action that clears this is reopening the page: it mints a token '
                . 'matching the session the browser now holds.',
        );
        assertSame(
            'no-store',
            $response->getHeaderLine('Cache-Control'),
            'The page must not be cached, or a retry would be handed the same dead token.',
        );
    }

    /**
     * The handler explains the failure; it does not act on the request.
     *
     * Re-rendering the target with a fresh token would let through the forgeries
     * CSRF exists to stop, and reflecting the fields would paint an attacker's
     * payload into a page the victim is looking at.
     */
    public function theFailurePageEchoesNeitherTheBodyNorAnInjectedQuery(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->formPost(
            '/login?next=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
            ['identifier' => '<script>alert(2)</script>'],
        ));
        $body = (string) $response->getBody();

        assertStringNotContainsString(
            '<script>alert(2)</script>',
            $body,
            'Submitted fields must never be written back into the refusal.',
        );
        assertStringNotContainsString(
            '<script>alert(1)</script>',
            $body,
            'The reload link is built from the request URI, so it has to be escaped.',
        );
        assertStringContainsString(
            'href="/login?next=%3Cscript%3Ealert(1)%3C%2Fscript%3E"',
            $body,
            'An escaped link still reloads the page the form was on.',
        );
    }

    /**
     * A machine client gets the same envelope as every other API response, so it
     * can read the refusal instead of handing a parser an empty body.
     */
    public function aFailedApiPostGetsTheJsonEnvelope(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->formPost('/api/auth/refresh', []));
        $body = (string) $response->getBody();

        assertSame(422, $response->getStatusCode(), 'The gate still refuses.');
        assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            'An API caller must not be handed an HTML page to parse.',
        );
        assertSame(
            ['success' => false, 'data' => [], 'errors' => []],
            array_intersect_key(
                json_decode($body, true),
                ['success' => null, 'data' => null, 'errors' => null],
            ),
            'The envelope has to match the one every other API response uses.',
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
