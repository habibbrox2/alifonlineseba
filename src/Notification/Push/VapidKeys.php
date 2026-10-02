<?php

declare(strict_types=1);

namespace App\Notification\Push;

use App\Env;

/**
 * The application's VAPID (RFC 8292) key pair, used to authenticate Web Push
 * requests.
 *
 * ## Why this class builds DER by hand
 *
 * Two environment constraints shape everything here:
 *
 * 1. `openssl_pkey_new()` fails on this host — OpenSSL is configured without a
 *    config file, so any runtime key generation dies with
 *    "configuration file routines::no such file". Key generation has to happen
 *    out of process (`openssl ecparam -genkey -name prime256v1`).
 * 2. The VAPID private key has to live in `.env` as a single line. A PEM has
 *    newlines, which no dotenv parser preserves.
 *
 * So `.env` stores the *raw 32-byte P-256 scalar*, base64url encoded, and this
 * class wraps it in a SEC 1 `EC PRIVATE KEY` structure at load time. PEM input
 * is still accepted, because pasting a generated PEM is the path most people
 * will actually take and failing on it would be gratuitous.
 *
 * ## Signature format
 *
 * VAPID wants raw `r || s` (64 bytes), but `openssl_sign()` emits the ASN.1
 * `SEQUENCE { INTEGER r, INTEGER s }` form. `signatureToRaw()` converts between
 * them. The leading-zero trimming is the fiddly part: DER integers are signed,
 * so an r or s whose top bit is set gets a `0x00` prefix that must be stripped
 * to get back to a fixed-width 32-byte coordinate.
 */
final class VapidKeys
{
    private const OID_PRIME256V1 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    private function __construct(
        private readonly string $privatePem,
        private readonly string $publicKeyB64,   // base64url, 65-byte point
        private readonly string $subject,
    ) {}

    /**
     * Build from the environment, or null when Web Push is not configured.
     *
     * Returning null rather than throwing is deliberate: the channel treats
     * "no VAPID keys" the same as "no push service configured" and reports
     * itself unavailable, so the rest of the notification stack keeps working
     * on a deployment that has not set this up yet.
     */
    public static function fromEnv(): ?self
    {
        $private = trim((string) Env::get('VAPID_PRIVATE_KEY', ''));
        $subject = trim((string) Env::get('VAPID_SUBJECT', ''));
        if ($private === '' || $subject === '') {
            return null;
        }
        return self::fromPrivateKey($private, $subject);
    }

    /**
     * @param string $privateKey PEM body, or a base64url/base64 32-byte scalar.
     */
    public static function fromPrivateKey(string $privateKey, string $subject): ?self
    {
        $key = trim($privateKey);
        // A quoted PEM in a dotenv file keeps its headers; a raw scalar does not.
        $pem = str_contains($key, '-----BEGIN') ? self::normalisePem($key) : null;

        if ($pem === null) {
            $scalar = self::base64UrlDecode($key);
            if (strlen($scalar) !== 32) {
                return null;
            }
            $pem = self::pem('EC PRIVATE KEY', self::sec1Der($scalar));
        }

        $resource = openssl_pkey_get_private($pem);
        if ($resource === false) {
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        $point = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!is_string($point) || !is_string($y) || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            return null;
        }

        return new self(
            $pem,
            self::base64UrlEncode("\x04" . $point . $y),
            $subject,
        );
    }

    /** The uncompressed public point, base64url — this is the VAPID `k` param. */
    public function publicKey(): string
    {
        return $this->publicKeyB64;
    }

    /**
     * The VAPID `sub` claim, which must be a `mailto:` or `https:` URI. This
     * class does not validate it because the push service is the authority on
     * what it accepts and a hard failure here would be harder to debug than a
     * 403 from the service.
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * Build the short-lived ES256 JWT that authorises a push request.
     *
     * The push service rejects `exp` more than 24 hours out, so the window is
     * capped at 12: long enough that a clock skew between here and the service
     * cannot invalidate it, short enough to stay well inside the limit.
     */
    public function jwt(string $audience, ?int $now = null): string
    {
        $now ??= time();
        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = [
            'aud' => $audience,
            'exp' => $now + 43200,
            'sub' => $this->subject,
        ];

        $signingInput = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

        $signed = '';
        if (!openssl_sign($signingInput, $signed, $this->privatePem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID signing failed.');
        }

        return $signingInput . '.' . self::base64UrlEncode($this->signatureToRaw($signed));
    }

    /**
     * The `Authorization: vapid t=<jwt>, k=<public key>` header value.
     */
    public function authorizationHeader(string $audience, ?int $now = null): string
    {
        return 'vapid t=' . $this->jwt($audience, $now) . ', k=' . $this->publicKeyB64;
    }

    /**
     * Convert a DER ECDSA signature into the fixed-width `r || s` form.
     *
     * The reverse direction (parsing) is deliberately absent: nothing in this
     * application verifies its own signatures, and a parser that is never
     * exercised is a liability.
     */
    private function signatureToRaw(string $der): string
    {
        $offset = 0;
        if ($der[0] !== "\x30") {
            throw new \RuntimeException('Malformed ECDSA signature.');
        }
        $offset = 1;
        // Length is a single byte for a 64-byte P-256 signature (total ~70).
        if ((ord($der[1]) & 0x80) !== 0) {
            throw new \RuntimeException('Unexpected long-form DER length.');
        }
        $offset = 2;

        $coordinates = [];
        for ($i = 0; $i < 2; $i++) {
            if ($der[$offset] !== "\x02") {
                throw new \RuntimeException('Malformed ECDSA signature.');
            }
            $len = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $len);
            // Strip the sign byte DER prepends to keep the INTEGER positive,
            // then left-pad back to a full 32-byte coordinate.
            $value = ltrim($value, "\x00");
            $value = str_pad($value, 32, "\x00", STR_PAD_LEFT);
            $coordinates[] = $value;
            $offset += 2 + $len;
        }

        return $coordinates[0] . $coordinates[1];
    }

    /**
     * SEC 1 `ECPrivateKey` (RFC 5915) holding only the scalar.
     *
     * The optional `[1] publicKey` field is left out on purpose: OpenSSL
     * recomputes the curve point from the scalar when it loads the key, and
     * shipping a second copy in `.env` would only create a way for the two to
     * disagree. Deriving it here also means `openssl_pkey_get_details()` is the
     * single source of truth for the public key.
     */
    private static function sec1Der(string $scalar32): string
    {
        return self::tlv("\x30",
            self::tlv("\x02", "\x01")                  // version 1
            . self::tlv("\x04", $scalar32)             // privateKey
            . self::tlv("\xa0", self::OID_PRIME256V1)); // [0] parameters
    }

    private static function tlv(string $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            $encodedLength = chr($length);
        } else {
            $bytes = ltrim(pack('N', $length), "\x00");
            $encodedLength = chr(0x80 | strlen($bytes)) . $bytes;
        }
        return $tag . $encodedLength . $content;
    }

    /**
     * Accept a PEM that lost its newlines to `.env` quoting.
     *
     * A single-line PEM is unusable as-is, so the body is re-wrapped to the
     * conventional 64-character lines. This is the shape people hit when they
     * paste a generated key into a dotenv value by hand.
     */
    private static function normalisePem(string $key): string
    {
        if (!str_contains($key, '-----BEGIN')) {
            return '';
        }
        if (str_contains($key, "\n")) {
            return $key;
        }
        preg_match('/-----BEGIN [^-]+-----(.*?)-----END [^-]+-----/s', $key, $m);
        $body = preg_replace('/\s+/', '', $m[1] ?? '') ?? '';
        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(
            strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4),
            true,
        );
        return $decoded === false ? '' : $decoded;
    }
}
