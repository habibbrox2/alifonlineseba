<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\GoogleIdTokenVerifier;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The rules that decide who "Continue with Google" is allowed to sign in as.
 *
 * Every token here is minted by the test from a throwaway key pair stored in
 * `tests/Support/Data/google-id-token-fixture.php` and handed to
 * `verifyWithKeys()` directly — so what is being proved is the decision, not
 * that a network fetch happened to succeed. A regression that loosened the
 * audience check, accepted an expired token or let a password-token through
 * the Google endpoint fails here, offline.
 *
 * The keys come from a fixture rather than `openssl_pkey_new()` because key
 * *generation* needs an openssl.cnf the test environment does not ship;
 * signing and verifying with an existing PEM needs nothing but the key.
 */
final class GoogleIdTokenVerifierTest extends \Codeception\Test\Unit
{
    private const PROJECT = 'al-onlinesheba';
    private const KID = 'test-key-1';

    private string $privateKey;
    private string $publicKey;
    /** @var array{private: string, public: string, certificate: string, private_other: string, public_other: string} */
    private array $fixture;

    protected function _before(): void
    {
        $fixture = require dirname(__DIR__) . '/Support/Data/google-id-token-fixture.php';
        assertTrue(is_array($fixture), 'The key fixture is missing, so no token can be minted.');
        foreach (['private', 'public', 'certificate', 'private_other', 'public_other'] as $part) {
            assertTrue(is_string($fixture[$part] ?? null) && $fixture[$part] !== '', "Fixture part {$part} is missing.");
        }

        $this->fixture = $fixture;
        $this->privateKey = $fixture['private'];
        $this->publicKey = $fixture['public'];
    }

    public function testAWellFormedTokenForThisProjectIsAccepted(): void
    {
        $claims = GoogleIdTokenVerifier::verifyWithKeys(
            $this->token(),
            [self::KID => $this->publicKey],
            self::PROJECT,
            self::now(),
        );

        assertNotNull($claims, 'A freshly signed token for this project must be accepted.');
        assertSame('sub-1234567890', $claims['sub']);
        assertSame('rahim@example.com', $claims['email']);
        assertSame('Rahim Uddin', $claims['name']);
        assertSame('https://example.com/photo.png', $claims['picture']);
        assertSame('google.com', $claims['firebase_provider']);
    }

    public function testATokenMintedForAnotherProjectIsRefused(): void
    {
        // Same key, same everything — except the audience names a different
        // Firebase project. Accepting it would mean any project the operator
        // has ever owned could sign people into this one.
        $claims = GoogleIdTokenVerifier::verifyWithKeys(
            $this->token(['aud' => 'someone-elses-project']),
            [self::KID => $this->publicKey],
            self::PROJECT,
            self::now(),
        );

        assertNull($claims, 'A token for another audience must never identify an account.');
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $now = self::now();
        $claims = GoogleIdTokenVerifier::verifyWithKeys(
            $this->token(['exp' => $now - 3600, 'iat' => $now - 7200, 'auth_time' => $now - 7200]),
            [self::KID => $this->publicKey],
            self::PROJECT,
            $now,
        );

        assertNull($claims, 'A replayable token that has expired is not evidence of anything.');
    }

    public function testTheClockSkewWindowIsNotAWideOpenDoor(): void
    {
        $now = self::now();
        // Inside the tolerated skew: accepted (a slightly slow clock must not
        // lock everybody out).
        assertNotNull(
            GoogleIdTokenVerifier::verifyWithKeys(
                $this->token(['exp' => $now - 30]),
                [self::KID => $this->publicKey],
                self::PROJECT,
                $now,
            ),
        );
        // Well past it: refused.
        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys(
                $this->token(['exp' => $now - 300]),
                [self::KID => $this->publicKey],
                self::PROJECT,
                $now,
            ),
        );
    }

    public function testATokenFromAPasswordProviderIsAcceptedWhenVerified(): void
    {
        // The backend now accepts any Firebase provider ID token as long as
        // the project, signature and verified email are valid.
        $claims = GoogleIdTokenVerifier::verifyWithKeys(
            $this->token(['firebase' => ['sign_in_provider' => 'password']]),
            [self::KID => $this->publicKey],
            self::PROJECT,
            self::now(),
        );

        assertNotNull($claims, 'A verified Firebase token from any provider must identify an account.');
        assertSame('password', $claims['firebase_provider']);
    }

    public function testATokenSignedWithAnotherKeyIsRefused(): void
    {
        // The second fixture key stands in for anyone who managed to get a
        // certificate published under a `kid` we would accept.
        $forged = $this->token([], self::KID, $this->fixture['private_other']);

        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys($forged, [self::KID => $this->publicKey], self::PROJECT, self::now()),
            'A signature that does not match the published key is the whole definition of a forgery.',
        );
    }

    public function testAnUnknownKeyIdIsRefused(): void
    {
        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys(
                $this->token([], 'rotated-away'),
                [self::KID => $this->publicKey],
                self::PROJECT,
                self::now(),
            ),
            'A kid we do not hold is an unverifiable claim, not a pass.',
        );
    }

    public function testAnAlgNoneTokenIsRefused(): void
    {
        $unsigned = self::b64(json_encode(['alg' => 'none', 'kid' => self::KID], JSON_THROW_ON_ERROR))
            . '.' . self::b64(json_encode($this->claims(), JSON_THROW_ON_ERROR)) . '.';

        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys($unsigned, [self::KID => $this->publicKey], self::PROJECT, self::now()),
        );
    }

    public function testATamperedPayloadIsRefused(): void
    {
        [$header, , $signature] = explode('.', $this->token());
        $tampered = $header . '.' . self::b64(json_encode(
            array_merge($this->claims(), ['email' => 'someone-else@example.com']),
            JSON_THROW_ON_ERROR,
        )) . '.' . $signature;

        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys($tampered, [self::KID => $this->publicKey], self::PROJECT, self::now()),
            'Changing the address after signing is exactly the attack this check exists for.',
        );
    }

    public function testAnUnverifiedEmailIsRefused(): void
    {
        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys(
                $this->token(['email_verified' => false]),
                [self::KID => $this->publicKey],
                self::PROJECT,
                self::now(),
            ),
            'The email names the account, so it has to be one Google actually proved.',
        );
    }

    public function testAnEmptySubjectIsRefused(): void
    {
        assertNull(
            GoogleIdTokenVerifier::verifyWithKeys(
                $this->token(['sub' => '']),
                [self::KID => $this->publicKey],
                self::PROJECT,
                self::now(),
            ),
        );
    }

    /**
     * The JWKS path — and with it the hand-rolled DER of `pemFromModulus()` —
     * is what production actually runs, so it has to be proved too: a modulus
     * and exponent must become a key that verifies.
     */
    public function testAKeySetBuiltFromModulusAndExponentVerifies(): void
    {
        $key = openssl_pkey_get_private($this->privateKey);
        assertTrue($key !== false, 'The fixture private key could not be parsed.');
        $details = openssl_pkey_get_details($key);
        assertArrayHasKey('rsa', $details);

        $jwks = json_encode(['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ]]], JSON_THROW_ON_ERROR);

        $keys = GoogleIdTokenVerifier::keysFromJwks($jwks);
        assertArrayHasKey(self::KID, $keys, 'The JWK must be turned into a usable key.');

        assertNotNull(
            GoogleIdTokenVerifier::verifyWithKeys($this->token(), $keys, self::PROJECT, self::now()),
            'A key parsed from n/e must verify exactly like the PEM it was built from.',
        );
    }

    public function testAKeySetBuiltFromAnX5cChainVerifies(): void
    {
        $certificate = $this->fixture['certificate'];
        $jwks = json_encode(['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'x5c' => [$certificate],
        ]]], JSON_THROW_ON_ERROR);

        $keys = GoogleIdTokenVerifier::keysFromJwks($jwks);
        assertArrayHasKey(self::KID, $keys);

        assertNotNull(
            GoogleIdTokenVerifier::verifyWithKeys($this->token(), $keys, self::PROJECT, self::now()),
            'The x5c branch is what Google actually sends today.',
        );
    }

    public function testGarbageIsRefusedWithoutComplaint(): void
    {
        foreach (['', 'not-a-token', 'a.b.c', str_repeat('x.', 3)] as $broken) {
            assertNull(
                GoogleIdTokenVerifier::verifyWithKeys($broken, [self::KID => $this->publicKey], self::PROJECT, self::now()),
                "Input {$broken} must read as “no”, never as an error.",
            );
        }
    }

    // ---- fixtures ---------------------------------------------------------

    private function claims(array $overrides = []): array
    {
        $now = self::now();

        return array_merge([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT,
            'aud' => self::PROJECT,
            'auth_time' => $now - 5,
            'user_id' => 'sub-1234567890',
            'sub' => 'sub-1234567890',
            'iat' => $now - 10,
            'exp' => $now + 3600,
            'email' => 'rahim@example.com',
            'email_verified' => true,
            'name' => 'Rahim Uddin',
            'picture' => 'https://example.com/photo.png',
            'firebase' => [
                'identities' => ['google.com' => ['rahim@example.com']],
                'sign_in_provider' => 'google.com',
            ],
        ], $overrides);
    }

    /** A signed token, optionally with overridden claims or another key. */
    private function token(array $claimOverrides = [], string $kid = self::KID, $privateKey = null): string
    {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], JSON_THROW_ON_ERROR);
        $payload = json_encode($this->claims($claimOverrides), JSON_THROW_ON_ERROR);
        $unsigned = self::b64($header) . '.' . self::b64($payload);

        $signature = '';
        assertTrue(openssl_sign($unsigned, $signature, $privateKey ?? $this->privateKey, OPENSSL_ALGO_SHA256));

        return $unsigned . '.' . self::b64($signature);
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function now(): int
    {
        return time();
    }
}
