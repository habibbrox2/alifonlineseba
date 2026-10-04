<?php

declare(strict_types=1);

namespace App\Service;

use App\Env;

/**
 * The Digital Asset Links statement a Trusted Web Activity has to be able to
 * fetch before it is allowed to claim this origin.
 *
 * ## Why this file has to exist at all
 *
 * A TWA looks like a plain full-screen app, but it is really your website
 * rendered in a real Chrome. The only thing stopping any random APK from
 * doing the same — and showing your domain in a convincing address-less
 * window, so its owner can collect your users' cookies — is
 * `/.well-known/assetlinks.json`. Android fetches that file, finds the app's
 * signing certificate listed, and only then trusts the origin.
 *
 * Get it wrong and the failure is quiet and confusing rather than loud: the
 * app launches, but in a Custom Tab with a visible browser bar, or with a
 * "this site isn't verified" interstitial. Push still works. It just looks
 * like a website in an app, which defeats the point of shipping an app at all.
 *
 * ## Why the origin is configured, not derived from the request
 *
 * It would be easy to build the statement from the incoming `Host` header.
 * That would be a security hole: the header is attacker-controlled, so anyone
 * could ask for `/.well-known/assetlinks.json` with a bogus Host and get a
 * valid-looking statement for an origin they do not control. An association
 * document must always be about the one canonical origin, so it comes from
 * configuration and is validated to be a bare `https://host` — no path, no
 * trailing slash, no query, no credentials, nothing `http`.
 *
 * ## Why the fingerprints are one compound env var
 *
 * A shipped app usually has two certificates: the debug one used by whoever
 * runs `assembleDebug`, and the release one signed with the operator's
 * keystore. Both must be listed or the build that is not published will show
 * the browser bar. Rather than invent a parallel set of variables, the format
 * is the one Bubblewrap itself prints:
 *
 *     TWA_FINGERPRINTS=online.broxlab.aliftools@SHA256:AB:CD:…,online.broxlab.aliftools@SHA256:11:22:…
 *
 * Several `package@fingerprint` pairs, separated by commas or newlines, with
 * the same package allowed more than once. They are regrouped into one target
 * per package, which is the shape Google actually documents.
 */
final readonly class TwaAssetLinks
{
    /**
     * The only relation a TWA needs. `handle_all_urls` is what lets the app
     * open every path on the site, not just `/`.
     */
    private const RELATION = 'delegate_permission/common.handle_all_urls';

    /**
     * Android package names: dot-separated segments, each starting with a
     * letter. Deliberately strict — a typo in a fingerprint list should be
     * dropped, not served to Google's verifier as a statement it rejects.
     */
    private const PACKAGE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/';

    /**
     * @param string   $origin     Canonical `https://host`, or '' when unset/invalid.
     * @param string[] $statements Raw `package => [fingerprint, …]` pairs, already
     *                             normalised, possibly empty.
     */
    public function __construct(
        private string $origin,
        private array $statements,
    ) {}

    /**
     * Built through the DI container rather than autowired, because the
     * origin and the certificates are operator configuration the container
     * cannot infer. See config/common/di/services.php.
     */
    public static function fromEnv(): self
    {
        return new self(
            self::normaliseOrigin((string) Env::get('TWA_ORIGIN', '')),
            self::parse((string) Env::get('TWA_FINGERPRINTS', '')),
        );
    }

    /**
     * Whether there is anything worth serving.
     *
     * An origin with no certificates is not a TWA that fails to verify; it is
     * no TWA at all, and the route answers 404 rather than publishing an
     * empty `[]` that Google's verifier reads as a deliberate "no app".
     */
    public function isConfigured(): bool
    {
        return $this->origin !== '' && $this->statements !== [];
    }

    /**
     * The document, in the exact shape Bubblewrap tells you to paste.
     *
     * Sorted so the bytes are stable between requests: the route may be
     * cached, and a statement that reorders itself whenever the environment
     * string is re-parsed would defeat that for no benefit.
     *
     * @return array<int, array{relation: string[], target: array<string, mixed>}>
     */
    public function json(): array
    {
        $packages = array_keys($this->statements);
        sort($packages);

        $document = [];
        foreach ($packages as $package) {
            $fingerprints = $this->statements[$package];
            sort($fingerprints);

            $document[] = [
                'relation' => [self::RELATION],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => $package,
                    'sha256_cert_fingerprints' => $fingerprints,
                ],
            ];
        }

        return $document;
    }

    /** The configured origin, or '' when unset or rejected. */
    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * Reduce a configured origin to bare `https://host`, or '' if it is not one.
     *
     * Everything that is not an https origin with no path is discarded rather
     * than repaired. An assetlinks file is a security document; guessing at
     * what the operator meant is how a site ends up asserting an association
     * for the wrong domain.
     */
    private static function normaliseOrigin(string $raw): string
    {
        $origin = trim($raw);
        if ($origin === '' || !str_starts_with(strtolower($origin), 'https://')) {
            return '';
        }

        $parts = parse_url($origin);
        if ($parts === false || ($parts['host'] ?? '') === '' || ($parts['user'] ?? '') !== '') {
            return '';
        }
        // A path, query or fragment means this is a URL, not an origin.
        foreach (['path', 'query', 'fragment'] as $component) {
            if (isset($parts[$component]) && $parts[$component] !== '' && $parts[$component] !== '/') {
                return '';
            }
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return 'https://' . $host . $port;
    }

    /**
     * Parse the compound `package@fingerprint` list.
     *
     * Unparseable entries are dropped silently, because the alternative — a
     * command that dies on a stray newline in `.env` — teaches operators to
     * avoid running it. `app:twa:fingerprints` reports what survived, so a
     * dropped entry is visible rather than merely absent.
     *
     * @return array<string, string[]>
     */
    private static function parse(string $raw): array
    {
        $statements = [];

        $tokens = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            $parts = explode('@', $token, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $package = trim($parts[0]);
            if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
                continue;
            }

            $fingerprint = self::normaliseFingerprint($parts[1]);
            if ($fingerprint === null) {
                continue;
            }

            $statements[$package][] = $fingerprint;
        }

        // The same certificate listed twice is harmless but noise, and a
        // duplicate target entry is something the verifier may reject.
        foreach ($statements as $package => $fingerprints) {
            $statements[$package] = array_values(array_unique($fingerprints));
        }

        return $statements;
    }

    /**
     * Accept the several shapes a fingerprint arrives in, emit the canonical one.
     *
     * Google's tooling prints `SHA256:AA:BB:…`; `keytool` prints the same
     * thing; a user pasting from a wiki often strips both the prefix and the
     * colons. All of them are the same certificate, and rejecting two of the
     * three would just make people go find the one the tool wanted.
     */
    private static function normaliseFingerprint(string $raw): ?string
    {
        $value = strtoupper(trim($raw));
        if (str_starts_with($value, 'SHA256:')) {
            $value = substr($value, 7);
        }
        $value = str_replace(':', '', $value);

        // SHA-256 is always 64 hex characters, and it is the only algorithm
        // Android accepts here.
        if (preg_match('/^[0-9A-F]{64}$/', $value) !== 1) {
            return null;
        }

        return implode(':', str_split($value, 2));
    }
}
