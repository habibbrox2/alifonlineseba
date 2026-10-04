<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\TwaAssetLinks;
use App\Web\Site\AssetLinksAction;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The Digital Asset Links document a Trusted Web Activity depends on.
 *
 * ## Why this file is fussier than it looks
 *
 * The failure mode it guards against is silent. An app with a missing or
 * wrong statement still launches, still talks to the API, and still receives
 * push — it just opens in a Custom Tab with a browser bar, which looks like a
 * website in an app rather than an app. Nothing logs an error, nothing 500s,
 * and the only symptom is that people uninstall.
 *
 * So the assertions here are about the *document*, not about the page: the
 * exact JSON shape Google's tooling prints, the exact relation string, and
 * above all the two things that silently do nothing —
 *
 *  - an origin derived from the request's `Host` header (attacker-controlled,
 *    and the only version of this that would be a real vulnerability), and
 *  - an empty `[]` served while unconfigured, which reads to the verifier as
 *    a deliberate "no app may ever claim this origin".
 */
final class AssetLinksTest extends \Codeception\Test\Unit
{
    private const ORIGIN = 'https://allseba.online';
    private const PACKAGE = 'online.broxlab.aliftools';
    private const DEBUG_CERT = '49:E8:F2:51:71:61:36:8A:B1:6E:E8:9B:B9:B2:0E:7C:52:55:52:BF:8C:88:98:0B:8C:CE:71:11:4E:7E:14:A2';
    private const RELEASE_CERT = 'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99';

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function _before(): void
    {
        foreach (['TWA_ORIGIN', 'TWA_FINGERPRINTS'] as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
        }
    }

    protected function _after(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    /**
     * @param array<string, mixed> $env
     */
    private function configure(array $env = []): TwaAssetLinks
    {
        $merged = $env + [
            'TWA_ORIGIN' => self::ORIGIN,
            'TWA_FINGERPRINTS' => self::PACKAGE . '@SHA256:' . self::DEBUG_CERT,
        ];

        foreach ($merged as $name => $value) {
            $_ENV[$name] = (string) $value;
        }

        return TwaAssetLinks::fromEnv();
    }

    private function respond(TwaAssetLinks $links): \Psr\Http\Message\ResponseInterface
    {
        return (new AssetLinksAction($links, new ResponseFactory(), new StreamFactory()))();
    }

    // ---- The document -----------------------------------------------------

    public function testTheDocumentIsExactlyTheShapeGoogleDocuments(): void
    {
        $response = $this->respond($this->configure());

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        assertSame(
            [
                [
                    'relation' => ['delegate_permission/common.handle_all_urls'],
                    'target' => [
                        'namespace' => 'android_app',
                        'package_name' => self::PACKAGE,
                        'sha256_cert_fingerprints' => [self::DEBUG_CERT],
                    ],
                ],
            ],
            $body,
            'A verifier reads these exact keys; a renamed one is an app that will not verify.'
        );
    }

    public function testDebugAndReleaseCertificatesShareOneTarget(): void
    {
        // The shape people get wrong: two statements for the same package is
        // not a debug-plus-release list, it is two apps claiming one origin.
        $links = $this->configure([
            'TWA_FINGERPRINTS' => self::PACKAGE . '@SHA256:' . self::DEBUG_CERT
                . ',' . self::PACKAGE . '@SHA256:' . self::RELEASE_CERT,
        ]);

        $body = $links->json();
        assertCount(1, $body, 'One package is one target, however many certificates it has.');
        assertSame(
            [self::DEBUG_CERT, self::RELEASE_CERT],
            $body[0]['target']['sha256_cert_fingerprints'],
        );
    }

    public function testTheSameCertificateListedTwiceCollapses(): void
    {
        $links = $this->configure([
            'TWA_FINGERPRINTS' => self::PACKAGE . '@SHA256:' . self::DEBUG_CERT
                . ',' . self::PACKAGE . '@' . self::DEBUG_CERT,
        ]);

        assertCount(1, $links->json()[0]['target']['sha256_cert_fingerprints']);
    }

    public function testTheDocumentIsStableBetweenReads(): void
    {
        $links = $this->configure([
            'TWA_FINGERPRINTS' => self::PACKAGE . '@SHA256:' . self::RELEASE_CERT
                . ',' . self::PACKAGE . '@SHA256:' . self::DEBUG_CERT,
        ]);

        assertSame(
            json_encode($links->json()),
            json_encode($links->json()),
            'The route is cacheable, so the bytes must not reorder themselves between requests.'
        );
    }

    // ---- Everything a fingerprint actually arrives as ---------------------

    /**
     * @dataProvider fingerprintShapeProvider
     */
    public function testAFingerprintIsAcceptedInEveryShapeItArrives(string $written, string $expected): void
    {
        $links = $this->configure(['TWA_FINGERPRINTS' => self::PACKAGE . '@' . $written]);

        assertTrue($links->isConfigured(), 'Rejected: ' . $written);
        assertSame([$expected], $links->json()[0]['target']['sha256_cert_fingerprints']);
    }

    public static function fingerprintShapeProvider(): iterable
    {
        $bare = '49e8f2517161368ab16ee89bb9b20e7c525552bf8c88980b8cce71114e7e14a2';
        $upper = '49:E8:F2:51:71:61:36:8A:B1:6E:E8:9B:B9:B2:0E:7C:52:55:52:BF:8C:88:98:0B:8C:CE:71:11:4E:7E:14:A2';

        // What Google's own tooling prints.
        yield 'keytool, prefixed' => ['SHA256:' . $upper, $upper];
        yield 'keytool, lowercased prefix' => ['sha256:' . $upper, $upper];
        // What someone gets after stripping the colons off a wiki entry.
        yield 'no separators, lowercase' => [$bare, $upper];
        yield 'no separators, uppercase' => [strtoupper($bare), $upper];
    }

    // ---- What must be thrown away -----------------------------------------

    /**
     * @dataProvider rejectedFingerprintProvider
     */
    public function testSomethingThatIsNotACertificateIsDropped(string $written): void
    {
        $links = $this->configure(['TWA_FINGERPRINTS' => self::PACKAGE . '@' . $written]);

        assertFalse($links->isConfigured(), 'Accepted a non-certificate: ' . $written);
    }

    public static function rejectedFingerprintProvider(): iterable
    {
        yield 'too short' => ['SHA256:49:E8:F2'];
        yield 'not hex' => ['SHA256:' . str_repeat('ZZ', 32)];
        // SHA-1 is what keytool printed in old build guides; Android rejects it.
        yield 'wrong algorithm length' => ['SHA1:' . str_repeat('AB', 20)];
        yield 'empty' => [''];
    }

    public function testJunkIsDroppedWithoutTakingTheValidEntryWithIt(): void
    {
        // A stray newline in .env is the ordinary way this list gets broken,
        // and a command that died on one would teach operators to avoid it.
        $links = $this->configure([
            'TWA_FINGERPRINTS' => "not-a-pair\n"
                . "missing_at_sign\n"
                . 'nodots@SHA256:' . self::DEBUG_CERT . "\n"
                . self::PACKAGE . '@SHA256:' . self::DEBUG_CERT,
        ]);

        assertTrue($links->isConfigured());
        assertSame(
            [self::DEBUG_CERT],
            $links->json()[0]['target']['sha256_cert_fingerprints'],
            'One bad line must not cost the good one on the next.'
        );
    }

    // ---- The origin -------------------------------------------------------

    /**
     * @dataProvider rejectedOriginProvider
     */
    public function testTheOriginMustBeABareHttpsOrigin(string $origin): void
    {
        $links = $this->configure(['TWA_ORIGIN' => $origin]);

        assertFalse(
            $links->isConfigured(),
            'Accepted an origin that is not a bare https origin: ' . $origin
        );
        assertSame('', $links->origin());
    }

    public static function rejectedOriginProvider(): iterable
    {
        yield 'plain http' => ['http://allseba.online'];
        yield 'a path' => ['https://allseba.online/admin'];
        yield 'a trailing slash is fine, a subdirectory is not' => ['https://allseba.online/app'];
        yield 'credentials' => ['https://user:pw@allseba.online'];
        yield 'a query' => ['https://allseba.online/?a=1'];
        yield 'empty' => [''];
    }

    public function testATrailingSlashIsNormalisedRatherThanRejected(): void
    {
        $links = $this->configure(['TWA_ORIGIN' => 'https://allseba.online/']);

        assertTrue($links->isConfigured());
        assertSame(self::ORIGIN, $links->origin(), 'The origin is normalised, not echoed back verbatim.');
    }

    // ---- Unconfigured -----------------------------------------------------

    public function testAnUnconfiguredOriginDoesNotClaimAnyApp(): void
    {
        $response = $this->respond($this->configure([
            'TWA_ORIGIN' => '',
            'TWA_FINGERPRINTS' => '',
        ]));

        assertSame(404, $response->getStatusCode());

        $body = (string) $response->getBody();
        assertFalse(
            json_decode($body, true) === [],
            'A bare [] is a valid answer meaning "no app may claim this origin" —'
                . ' an installed app would be refused outright. 404 means "not set up yet".'
        );
        assertStringContainsString('app:twa:fingerprints', $body, 'The 404 should say how to fix itself.');
    }

    public function testAnOriginWithNoCertificatesIsAlsoUnconfigured(): void
    {
        // Half-configured is the state an operator is most likely to leave the
        // site in, and it must not publish a certificate-less claim.
        assertFalse($this->configure(['TWA_FINGERPRINTS' => ''])->isConfigured());
    }

    public function testTheActionIsNotGivenTheRequestToBuildAnOriginFrom(): void
    {
        // Structural, not behavioural: the action has no request parameter at
        // all, so the Host header cannot reach it. This is the assertion that
        // keeps it that way — adding a ServerRequestInterface parameter is
        // how an attacker-controlled origin would get in.
        $parameters = (new \ReflectionMethod(AssetLinksAction::class, '__invoke'))->getParameters();

        assertSame([], $parameters, 'AssetLinksAction must not be handed the request.');
    }

    public function testTheVerifiedRelationIsTheOneThatCoversEveryPath(): void
    {
        $body = $this->configure()->json();

        assertNotNull($body[0]['relation'] ?? null);
        assertStringContainsString(
            'delegate_permission/common.handle_all_urls',
            $body[0]['relation'][0],
            'A narrower relation would verify the app but block /service-history and /admin.',
        );
        assertStringNotContainsString('launch', (string) $body[0]['relation'][0]);
    }
}
