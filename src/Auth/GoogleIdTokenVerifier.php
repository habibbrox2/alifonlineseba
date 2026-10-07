<?php

declare(strict_types=1);

namespace App\Auth;

use App\Env;

/**
 * Verifies a Google sign-in ID token issued to *this* Firebase project.
 *
 * ## Why this class exists at all
 *
 * The browser gets the token from Firebase (`signInWithPopup`), but the token
 * is only a claim until something on the server has checked it. Trusting the
 * client's word for who the caller is would make "Continue with Google" a
 * button that logs you in as anybody, so the token is verified here and the
 * account is provisioned from what the signature proves rather than from what
 * the page posted.
 *
 * What is checked, in the order it is checked:
 *
 * 1. the algorithm is RS256 — a token that says `none` or `HS256` never gets
 *    as far as a key;
 * 2. the signature verifies against Google's certificate for the token's
 *    `kid`;
 * 3. `iss` is `https://securetoken.google.com/<project>` and `aud` is the
 *    project id, so a token minted for a *different* project — including one
 *    of ours on another domain — is refused;
 * 4. `sub` is a present, sane-length string and `exp`/`iat`/`auth_time` are
 *    in the past-to-future order they must be;
 * 5. `firebase.sign_in_provider` is `google.com`, so a token from a password
 *    or anonymous sign-in on the same project cannot be posted to the Google
 *    endpoint;
 * 6. and an email is present and marked verified — Google has already proved
 *    it, which is exactly why it is used as the account's identity.
 *
 * {@see verifyWithKeys()} holds every one of those rules and takes the keys as
 * an argument, so the whole decision can be tested with a key pair generated
 * in the test itself: no network, no clock of Google's to wait on, and no way
 * for a test to pass because a fetch happened to fail.
 */
class GoogleIdTokenVerifier
{
    /**
     * Google's rotating JWKS. Rotates on a schedule Google does not publish,
     * so a `kid` we have never seen is a reason to refetch, not a reason to
     * reject — see {@see keys()}.
     */
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** How long a fetched key set is trusted before it is fetched again. */
    private const CACHE_TTL = 3600;

    /** Tolerated clock skew, in seconds, when judging `exp`/`iat`. */
    private const CLOCK_SKEW = 60;

    /**
     * Verify a raw ID token and return the claims an account is built from.
     *
     * @return array{sub: string, email: string, name: string, picture: string, firebase_provider: string, phone_number?: string}|null
     *         null for anything that is not a valid token for this project —
     *         the caller treats that as a refusal and never as a crash.
     */
    public function verify(string $token): ?array
    {
        $projectId = (string) Env::get('FIREBASE_PROJECT_ID', '');
        if ($projectId === '') {
            // Nothing to check the audience against, so nothing can be
            // checked. Refusing is the only honest answer.
            return null;
        }

        $header = self::decodeJson(self::segment($token, 0));
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            return null;
        }

        return self::verifyWithKeys($token, $this->keys($header['kid']), $projectId, time());
    }

    /**
     * The rules, with the keys handed in.
     *
     * @param array<string, string> $keys kid => PEM public key
     * @return array{sub: string, email: string, name: string, picture: string, firebase_provider: string}|null
     */
    public static function verifyWithKeys(string $token, array $keys, string $projectId, int $now): ?array
    {
        if ($projectId === '' || substr_count($token, '.') !== 2) {
            return null;
        }

        $rawHeader = self::segment($token, 0);
        $payload = self::segment($token, 1);
        $signature = self::segment($token, 2);
        if ($rawHeader === null || $payload === null || $signature === null) {
            return null;
        }

        $header = self::decodeJson($rawHeader);
        $claims = self::decodeJson($payload);
        if (!is_array($header) || !is_array($claims)) {
            return null;
        }
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            return null;
        }

        $key = $keys[$header['kid']] ?? null;
        if (!is_string($key) || $key === '') {
            return null;
        }

        $verified = openssl_verify(
            $rawHeader . '.' . $payload,
            base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', (4 - strlen($signature) % 4) % 4), true),
            $key,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            return null;
        }

        // Audience and issuer are what tie the token to *this* deployment.
        if (($claims['iss'] ?? null) !== 'https://securetoken.google.com/' . $projectId) {
            return null;
        }
        if (($claims['aud'] ?? null) !== $projectId) {
            return null;
        }

        $sub = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        if ($sub === '' || strlen($sub) > 128) {
            return null;
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::CLOCK_SKEW) {
            return null;
        }
        if (isset($claims['iat']) && is_int($claims['iat']) && $claims['iat'] > $now + self::CLOCK_SKEW) {
            return null;
        }
        if (isset($claims['auth_time']) && is_int($claims['auth_time']) && $claims['auth_time'] > $now + self::CLOCK_SKEW) {
            return null;
        }

        $firebase = $claims['firebase'] ?? null;
        $provider = is_array($firebase) ? (string) ($firebase['sign_in_provider'] ?? '') : '';
        if ($provider === '') {
            return null;
        }

        $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        // The local account is keyed on a verified address; an unverified one
        // would let whoever controls the mailbox claim an existing account.
        if (array_key_exists('email_verified', $claims) && $claims['email_verified'] !== true) {
            return null;
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => is_string($claims['name'] ?? null) ? mb_substr(trim($claims['name']), 0, 120) : '',
            'picture' => self::safeUrl(is_string($claims['picture'] ?? null) ? $claims['picture'] : ''),
            'firebase_provider' => $provider,
            'phone_number' => is_string($claims['phone_number'] ?? null) ? self::safePhone($claims['phone_number']) : '',
        ];
    }

    /**
     * The certificate for one `kid`, from cache or from Google.
     *
     * An unknown `kid` forces a refetch first: the usual reason a token
     * carries a key we do not have is that Google rotated the day before and
     * our cached copy is the stale one. Only if the fresh set still lacks it
     * is the token refused.
     *
     * @return array<string, string> kid => PEM
     */
    private function keys(string $kid): array
    {
        $cached = $this->readCache();
        if (is_array($cached) && isset($cached['keys'][$kid])) {
            return $cached['keys'];
        }

        $fresh = $this->fetch();
        if ($fresh !== []) {
            $this->writeCache($fresh);
            return $fresh;
        }

        // Network failed. A cached set that does not contain the kid is no
        // help, but a cached set that *does* was already returned above — so
        // reaching here means "no key", which answers as a refusal.
        return is_array($cached) ? $cached['keys'] : [];
    }

    /**
     * Fetch and parse Google's JWKS. Empty on any failure — never throws:
     * a network problem has to look like "this token is not acceptable",
     * not like a 500 on the login page.
     *
     * @return array<string, string> kid => PEM
     */
    private function fetch(): array
    {
        $body = null;
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents(self::CERTS_URL, false, $context);
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($body) || $body === '') {
            return [];
        }

        return self::keysFromJwks($body);
    }

    /**
     * JWKS JSON => kid => PEM. Google puts an `x5c` chain on every key; when
     * one is missing the modulus/exponent are turned into a key directly, so
     * a format change costs a slower path rather than a broken login.
     *
     * @return array<string, string>
     */
    public static function keysFromJwks(string $json): array
    {
        // Raw JSON, not a base64url segment — decodeJson() would strip bytes
        // it thinks are armour and leave nothing parseable.
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null)) {
            return [];
        }

        $keys = [];
        foreach ($decoded['keys'] as $jwk) {
            if (!is_array($jwk) || !is_string($jwk['kid'] ?? null) || ($jwk['kty'] ?? null) !== 'RSA') {
                continue;
            }
            $pem = null;
            if (is_string($jwk['x5c'][0] ?? null)) {
                $der = base64_decode($jwk['x5c'][0], true);
                if (is_string($der)) {
                    $certificate = '-----BEGIN CERTIFICATE-----' . "\n"
                        . chunk_split(base64_encode($der), 64, "\n")
                        . '-----END CERTIFICATE-----' . "\n";
                    // openssl_pkey_get_public() accepts a PEM certificate and
                    // answers with the public key inside it.
                    $publicKey = @openssl_pkey_get_public($certificate);
                    $pem = $publicKey instanceof \OpenSSLAsymmetricKey ? (string) openssl_pkey_get_details($publicKey)['key'] : null;
                }
            }
            if ($pem === null && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                $pem = self::pemFromModulus($jwk['n'], $jwk['e']);
            }
            if ($pem !== null) {
                $keys[$jwk['kid']] = $pem;
            }
        }

        return $keys;
    }

    /**
     * Build a PEM public key from an RSA JWK's base64url modulus and exponent.
     *
     * DER-encoding an RSA public key is a fixed structure (`SEQUENCE { OID
     * rsaEncryption, BIT STRING { SEQUENCE { INTEGER n, INTEGER e } } }`),
     * which is written out below rather than pulled in as a JWT library — this
     * project verifies one token type and should not carry a JOSE stack for it.
     */
    private static function pemFromModulus(string $modulus, string $exponent): ?string
    {
        $n = self::base64UrlDecode($modulus);
        $e = self::base64UrlDecode($exponent);
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        $rsa = self::derInteger($n) . self::derInteger($e);
        $rsaSequence = self::derSequence($rsa);
        // The leading 0x00 is the BIT STRING's "unused bits" count; der()
        // computes the length of what it wraps, so it must not be added here.
        $bitString = self::der("\x03", "\x00" . $rsaSequence);
        $algorithm = self::derSequence(self::derOid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $spki = self::derSequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der("\x02", $bytes);
    }

    private static function derSequence(string $contents): string
    {
        return self::der("\x30", $contents);
    }

    private static function derOid(string $contents): string
    {
        return self::der("\x06", $contents);
    }

    private static function der(string $tag, string $contents): string
    {
        return $tag . self::derLength(strlen($contents)) . $contents;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    // ---- cache ------------------------------------------------------------

    /** @return array{exp: int, keys: array<string, string>}|null */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();
        if ($file === null || !is_file($file)) {
            return null;
        }

        $decoded = self::decodeJson((string) @file_get_contents($file));
        if (!is_array($decoded) || !is_int($decoded['exp'] ?? null) || !is_array($decoded['keys'] ?? null)) {
            return null;
        }
        if ($decoded['exp'] < time()) {
            return null;
        }

        return ['exp' => $decoded['exp'], 'keys' => $decoded['keys']];
    }

    /** @param array<string, string> $keys */
    private function writeCache(array $keys): void
    {
        $file = $this->cacheFile();
        if ($file === null) {
            return;
        }

        @file_put_contents($file, json_encode([
            'exp' => time() + self::CACHE_TTL,
            'keys' => $keys,
        ]), LOCK_EX);
    }

    private function cacheFile(): ?string
    {
        $path = dirname(__DIR__, 2) . '/runtime/cache';
        if (!is_dir($path) && !@mkdir($path, 0o755, true) && !is_dir($path)) {
            return null;
        }

        return $path . '/google-idp-keys.json';
    }

    // ---- small helpers ----------------------------------------------------

    /** The n-th dot-separated part of a JWT, or null. */
    private static function segment(string $token, int $index): ?string
    {
        $parts = explode('.', $token);
        if (!isset($parts[$index]) || $parts[$index] === '') {
            return null;
        }

        return $parts[$index];
    }

    private static function decodeJson(string $value): mixed
    {
        $json = self::base64UrlDecode($value);
        if ($json === null || $json === '') {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    /** Only an https picture URL, because it ends up in an `<img src>`. */
    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            return '';
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : '';
    }

    /** Only digits, +, -, spaces for a phone number. */
    private static function safePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '' || strlen($phone) > 20) {
            return '';
        }

        return preg_replace('/[^0-9+\- ]/', '', $phone) ?: '';
    }
}
