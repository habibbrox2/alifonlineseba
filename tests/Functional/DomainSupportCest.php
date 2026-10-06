<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Requirement 6: `APP_URL` is the default domain, and the site must still
 * work when reached through a subdomain or some other domain entirely.
 *
 * The two halves pull in opposite directions and both are asserted here:
 *
 *   - Every *absolute* URL the page emits — canonical, og:url, JSON-LD,
 *     sitemap — must name APP_URL no matter which host carried the request.
 *     If canonical followed the request host, one page would advertise two
 *     competing names and the canonical tag would contradict
 *     `NoIndexMiddleware`, which treats the request host as a delivery
 *     detail, not an identity.
 *   - Everything else must work on the visitor's own host: the page renders,
 *     the CSRF token is there, form actions and navigation are relative so a
 *     login on a second domain stays on that domain, and the foreign host is
 *     answered `noindex` so the copy never competes with production.
 *
 * `APP_URL` and `CANONICAL_URL` are process env, so each test sets them
 * around the request and restores them after — `Env::get()` reads live, and
 * a leak would change what every later test in the suite believes the domain
 * to be.
 */
final class DomainSupportCest
{
    private const DEFAULT_DOMAIN = 'https://allseba.online';

    /** A domain the deployment knows nothing about. */
    private const OTHER_HOST = 'https://other.example.test';

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    public function absoluteUrlsStayOnTheDefaultDomainEvenFromAnotherHost(FunctionalTester $tester): void
    {
        $this->withDomainEnv(function () use ($tester): void {
            $response = $this->send($tester, self::OTHER_HOST . '/');

            assertSame(200, $response->getStatusCode(), 'A foreign host must still get a working page.');
            $body = (string) $response->getBody();

            assertTrue(
                (bool) preg_match(
                    '/<link rel="canonical" href="' . preg_quote(self::DEFAULT_DOMAIN, '/') . '\//',
                    $body
                ),
                'Canonical must name APP_URL, not the host the request arrived on — '
                    . 'otherwise a second domain creates a second set of competing pages.',
            );
            assertStringContainsString(
                '"url": "' . self::DEFAULT_DOMAIN . '/"',
                $body,
                'The JSON-LD and og tags travel to crawlers and messengers on their own; '
                    . 'they have to resolve to the domain that actually serves the site.',
            );

            $robots = $response->getHeaderLine('X-Robots-Tag');
            assertStringContainsString(
                'noindex',
                $robots,
                'A host that is not the canonical one must never be indexed.',
            );
        });
    }

    public function onlyTheDefaultDomainIsIndexable(FunctionalTester $tester): void
    {
        $this->withDomainEnv(function () use ($tester): void {
            $home = $this->send($tester, self::DEFAULT_DOMAIN . '/');
            assertSame(200, $home->getStatusCode());
            assertSame(
                '',
                $home->getHeaderLine('X-Robots-Tag'),
                'The configured domain is the one search engines are allowed to keep.',
            );

            $beta = $this->send($tester, 'https://beta.allseba.online/');
            assertSame(200, $beta->getStatusCode());
            assertStringContainsString(
                'noindex',
                $beta->getHeaderLine('X-Robots-Tag'),
                'A staging subdomain served by the same app must answer noindex, '
                    . 'or it competes with production in the index.',
            );
        });
    }

    /**
     * The half that makes "works on another domain" mean something: the page
     * a visitor actually uses is built from relative URLs, so nothing drags
     * them back to APP_URL mid-flow.
     */
    public function theSiteIsUsableFromAHostAppUrlDoesNotKnow(FunctionalTester $tester): void
    {
        $this->withDomainEnv(function () use ($tester): void {
            $login = $this->send($tester, self::OTHER_HOST . '/login');
            assertSame(200, $login->getStatusCode());
            $body = (string) $login->getBody();

            assertStringContainsString(
                '<meta name="_csrf" content="',
                $body,
                'The token ships on every host; without it every POST would answer 422.',
            );
            assertStringContainsString(
                'action="/login"',
                $body,
                'A relative form action keeps the visitor on the domain they came in on.',
            );
            assertStringContainsString(
                'href="/register"',
                $body,
                'Navigation is relative too — a second domain must not bounce people to the default one.',
            );

            $robots = $this->send($tester, self::OTHER_HOST . '/robots.txt');
            assertSame(200, $robots->getStatusCode());
            assertStringContainsString(
                'Sitemap: ' . self::DEFAULT_DOMAIN . '/sitemap.xml',
                (string) $robots->getBody(),
                'robots.txt points crawlers at the canonical host even when served from another one.',
            );
        });
    }

    /**
     * The guard for the gap the fallback exists to close: a deployment whose
     * .env predates any APP_URL must not publish Env's built-in localhost
     * default as its canonical address — it adopts the origin it was reached
     * on instead. `IdentityViewInjection::siteUrl()` is the only consumer
     * with this escape hatch; sitemap, emails and referral links stay pinned
     * to APP_URL by design.
     */
    public function anUnconfiguredDeploymentAdoptsTheRequestOrigin(FunctionalTester $tester): void
    {
        $this->stashEnv();
        $saved = $this->previousEnv;
        unset($_ENV['APP_URL'], $_ENV['CANONICAL_URL'], $_SERVER['APP_URL'], $_SERVER['CANONICAL_URL']);

        try {
            $response = $this->send($tester, self::OTHER_HOST . '/');
            assertSame(200, $response->getStatusCode());
            assertStringContainsString(
                '<link rel="canonical" href="' . self::OTHER_HOST . '/"',
                (string) $response->getBody(),
                'With no APP_URL configured, the only address known to work is the one the '
                    . 'request used — publishing http://localhost:8080 would break every link on a real site.',
            );
        } finally {
            $this->restoreEnv($saved);
            $this->previousEnv = [];
        }
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * @param callable(): void $scenario
     */
    private function withDomainEnv(callable $scenario): void
    {
        $this->stashEnv();
        $_ENV['APP_URL'] = self::DEFAULT_DOMAIN;
        $_ENV['CANONICAL_URL'] = self::DEFAULT_DOMAIN;

        try {
            $scenario();
        } finally {
            $this->restoreEnv($this->previousEnv);
            $this->previousEnv = [];
        }
    }

    private function stashEnv(): void
    {
        foreach (['APP_URL', 'CANONICAL_URL'] as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
        }
    }

    /** @param array<string, string|null> $saved */
    private function restoreEnv(array $saved): void
    {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    private function send(FunctionalTester $tester, string $uri): ResponseInterface
    {
        $response = $tester->sendRequest(new ServerRequest(uri: $uri));

        // Each request boots a fresh runner in this process; leaving the
        // session open would hand the next one a session carrying nothing.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $response;
    }
}
