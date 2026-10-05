<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The signed-out pages have to say what just happened.
 *
 * Password recovery answers with a redirect and a one-shot flash, so the
 * banner lives on the page the redirect lands on. `layouts/auth.twig`
 * rendered no flash at all, which made the whole recovery flow silent: the
 * visitor was told to check an inbox and shown nothing, and on the OTP
 * channels was dropped onto a form that could not explain itself.
 *
 * The email branch is the sharpest case, because it redirects back to the
 * form it was submitted from — the one place "send again" is a single click
 * away — so it is asserted here end to end.
 */
final class FlashMessageCest
{
    public function emailResetFlashRendersOnThePageItReturnsTo(FunctionalTester $tester): void
    {
        [$cookie, $token] = $this->openForm($tester);

        $posted = $this->post($tester, $cookie, [
            '_csrf' => $token,
            'identifier' => 'no-such-account-for-flash-test',
            'channel' => 'email',
        ]);

        assertSame(302, $posted->getStatusCode(), 'The reset request should redirect, not re-render.');
        assertStringContainsString(
            '/forgot-password',
            $posted->getHeaderLine('Location'),
            'The emailed link is the continuation for that channel; bouncing to '
            . '/reset-password instead replaces "check your inbox" with a dead end.',
        );

        $landed = $this->visit($tester, $cookie);

        assertSame(200, $landed->getStatusCode());
        $html = (string) $landed->getBody();

        assertStringContainsString('alert-success', $html, 'The flash banner did not render on the auth layout.');
        assertStringContainsString('অ্যাকাউন্ট থাকলে পাসওয়ার্ড রিসেট লিংক', $html);
        // Still on the form, so "it did not arrive, send again" is one click.
        assertStringContainsString('name="identifier"', $html);
    }

    /**
     * One-shot means one-shot: the flash is spent by the first page that reads
     * it, so a refresh must not resurrect it.
     */
    public function flashIsReadExactlyOnce(FunctionalTester $tester): void
    {
        [$cookie, $token] = $this->openForm($tester);

        $this->post($tester, $cookie, [
            '_csrf' => $token,
            'identifier' => 'no-such-account-for-flash-test',
            'channel' => 'email',
        ]);

        $first = $this->visit($tester, $cookie);
        assertStringContainsString('alert-success', (string) $first->getBody());

        $second = $this->visit($tester, $cookie);
        assertSame(200, $second->getStatusCode());
        assertSame(
            0,
            preg_match('/alert-success/', (string) $second->getBody()),
            'The flash survived a second read; it is supposed to last one request.',
        );
    }

    /**
     * Opens the recovery form the way a browser does and hands back what the
     * next request needs: the session cookies and a token minted inside them.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function openForm(FunctionalTester $tester): array
    {
        $response = $this->send($tester, new ServerRequest(uri: '/forgot-password'));

        assertSame(200, $response->getStatusCode());

        $cookie = $this->sessionCookies($response->getHeaderLine('Set-Cookie'));
        assertNotEmpty($cookie, 'No session cookie was issued, so no request can be made as the same visitor.');

        $found = preg_match('/name="_csrf" value="([^"]+)"/', (string) $response->getBody(), $m);
        assertSame(1, $found, 'The recovery form must carry a CSRF token.');

        return [$cookie, $m[1]];
    }

    /**
     * @param array<string, string> $cookie
     */
    private function visit(FunctionalTester $tester, array $cookie): ResponseInterface
    {
        return $this->send(
            $tester,
            new ServerRequest(uri: '/forgot-password', cookieParams: $cookie),
        );
    }

    /**
     * A form post, built the way a browser's arrives.
     *
     * Two things a hand-built PSR-7 request has to supply itself, because
     * normally the SAPI does: `parsedBody` — which both the CSRF middleware
     * and the action read, so without it the post comes back a bare 422 —
     * and the cookies as `cookieParams`, which is where the session middleware
     * looks (`Yiisoft\Session\SessionMiddleware::getSessionIdFromRequest()`),
     * not in a `Cookie` header.
     *
     * The body is a stream rather than the encoded string, because
     * `HttpSoft\Message\ServerRequest` reads a string body as a *file path*.
     *
     * @param array<string, string> $cookie
     * @param array<string, string> $fields
     */
    private function post(FunctionalTester $tester, array $cookie, array $fields): ResponseInterface
    {
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        return $this->send(
            $tester,
            new ServerRequest(
                uri: '/forgot-password',
                method: 'POST',
                headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
                cookieParams: $cookie,
                parsedBody: $fields,
                body: $stream,
            ),
        );
    }

    private function send(FunctionalTester $tester, ServerRequest $request): ResponseInterface
    {
        $response = $tester->sendRequest($request);

        // Each sendRequest() boots a fresh application runner inside this same
        // PHP process. Without closing the session in between, the next request
        // inherits the one still open here and the cookie arrives carrying
        // nothing — CSRF included.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $response;
    }

    /**
     * Turns a Set-Cookie header line into the request-side cookie array.
     *
     * Attributes (Domain, Path, HttpOnly) are not request data and must not be
     * replayed, so only the name=value pairs survive.
     *
     * @return array<string, string>
     */
    private function sessionCookies(string $setCookie): array
    {
        $cookies = [];

        foreach (preg_split('/,(?=\s*[A-Za-z0-9_]+=)/', $setCookie) ?: [] as $chunk) {
            $pair = strtok(trim($chunk), ';');
            if ($pair === false || !str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            if (str_contains($name, 'SESSION')) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }
}
