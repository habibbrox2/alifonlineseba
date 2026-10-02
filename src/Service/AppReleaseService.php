<?php

declare(strict_types=1);

namespace App\Service;

use App\Env;
use App\Repository\AppReleaseRepository;

/**
 * Resolves the published APK to bytes on disk, and decides what an installed
 * client should be told about updating.
 *
 * ## Why the bytes live outside the web root
 *
 * An APK is an installable application. A file served from inside `public/` is
 * reachable by anyone who guesses its name, with no record of who downloaded
 * it, and the path is a permanent part of the site's shape. Keeping them in
 * `web/releases/` (gitignored, outside the document root) means the only way
 * to obtain the bytes is the action that checks the release is published —
 * `ApkDownloadAction`. This mirrors `DeliverableStorage` and `ReceiptStorage`,
 * which exist for the same reason.
 *
 * ## Why `apk_path` is resolved rather than trusted
 *
 * `app_release.apk_path` is operator input. `absolutePath()` treats it as
 * untrusted: it rejects absolute paths, `..` traversal, and anything that
 * escapes the releases directory after normalisation. A row edited to
 * `../../../config/.env` returns null rather than a path — the download then
 * 404s, which is the correct outcome for a request that should never have been
 * possible.
 *
 * ## Version semantics
 *
 * `version_code` is the monotonic integer Android compares against
 * `BuildConfig.VERSION_CODE`; `version_name` is the human string ("1.2.0") and
 * is never used for comparison. `min_version_code` is a floor: a client below
 * it is told the update is *required* rather than merely available, which is
 * what lets a release retire a build that can no longer talk to the API.
 */
final readonly class AppReleaseService
{
    /**
     * APKs well past the Play Store's 100 MB limit are not something this
     * feature should try to serve; a row claiming one is a mistake worth
     * refusing rather than a file worth streaming.
     */
    private const MAX_APK_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private AppReleaseRepository $releases,
        private string $basePath,
    ) {}

    /**
     * Built through the DI container rather than autowired, because the
     * releases directory is a filesystem path the container cannot infer. The
     * repository itself still autowires — see config/common/di/services.php.
     */
    public static function fromProjectRoot(AppReleaseRepository $releases): self
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/web/releases';

        if (!is_dir($path)) {
            @mkdir($path, 0o750, true);
        }

        return new self($releases, $path);
    }

    /**
     * The published release, or null when there is nothing to offer.
     *
     * A published row whose file has been pruned (or whose path is unusable)
     * is treated as "no release" rather than as a 500 on the /app page: the
     * download link is the only thing it feeds, and a dead link is worse than
     * an honest "not available yet".
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $release = $this->releases->published();
        if ($release === null) {
            return null;
        }

        $absolute = $this->absolutePath((string) $release['apk_path']);
        if ($absolute === null || !is_file($absolute) || !is_readable($absolute)) {
            return null;
        }

        $size = (int) filesize($absolute);
        if ($size <= 0 || $size > self::MAX_APK_BYTES) {
            return null;
        }

        $release['apk_size'] = $size;
        $release['sha256'] = $this->checksum($absolute, (string) $release['sha256']);

        return $release;
    }

    /**
     * Turn a stored `apk_path` into an absolute path, or null when it is not
     * a path we are willing to open.
     *
     * The checks, in order:
     *  1. Not absolute — a leading `/` or a Windows drive letter is refused
     *     outright, so a row can never point at the filesystem root.
     *  2. No `..` segment, and no NUL byte (which truncates in the C layer
     *     below PHP on some platforms).
     *  3. The normalised result still sits under the releases directory. This
     *     is the check that actually matters; the first two just make the
     *     intent obvious at the call site.
     */
    public function absolutePath(string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }
        if (str_starts_with($relative, '/') || str_starts_with($relative, '\\')) {
            return null;
        }
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $relative) === 1) {
            return null;
        }

        $segments = preg_split('#[\\\\/]+#', $relative) ?: [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        $base = rtrim($this->basePath, '/\\');
        $candidate = $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);

        // realpath() collapses the traversal for us; comparing the prefix is
        // the authoritative check because it happens after resolution.
        $realBase = realpath($base);
        $realCandidate = realpath($candidate);
        if ($realBase === false || $realCandidate === false) {
            return null;
        }
        $realBase = rtrim(str_replace('\\', '/', $realBase), '/');
        $realCandidate = str_replace('\\', '/', $realCandidate);
        if ($realCandidate !== $realBase && !str_starts_with($realCandidate, $realBase . '/')) {
            return null;
        }

        return $realCandidate;
    }

    /**
     * What `GET /api/app/version` answers.
     *
     * `update_available` is what the app acts on; `update_required` is true
     * when the client is below the published floor. The payload is
     * deliberately complete enough that the app needs no second request to
     * decide, and omits nothing that would let a client reconstruct server
     * state it has no business knowing.
     *
     * @return array<string, mixed>
     */
    public function versionPayload(int $clientVersionCode, ?string $platform = null): array
    {
        $release = $this->current();
        $minVersion = $this->releases->minVersionCode();

        if ($release === null) {
            return [
                'version_code' => 0,
                'version_name' => '',
                'min_version_code' => $minVersion,
                'update_available' => false,
                'update_required' => false,
                'size_bytes' => 0,
                'sha256' => '',
                'release_notes' => null,
                'download_url' => null,
                'published_at' => null,
            ];
        }

        $latest = (int) $release['version_code'];
        $available = $clientVersionCode > 0 && $clientVersionCode < $latest;

        return [
            'version_code' => $latest,
            'version_name' => (string) $release['version_name'],
            'min_version_code' => (int) $release['min_version_code'],
            'update_available' => $available,
            'update_required' => $available && $clientVersionCode < (int) $release['min_version_code'],
            'size_bytes' => (int) $release['apk_size'],
            // The hash travels so a client that downloaded the bytes can prove
            // they are the ones this manifest signed off, rather than trusting
            // whatever a redirect served.
            'sha256' => (string) $release['sha256'],
            'release_notes' => $release['release_notes'] !== null ? (string) $release['release_notes'] : null,
            'download_url' => '/app/apk',
            'published_at' => $release['published_at'] !== null ? (string) $release['published_at'] : null,
        ];
    }

    /**
     * Hash the APK on disk, falling back to the recorded digest.
     *
     * The recorded value is what the manifest was signed off with; recomputing
     * on every read of /app would hash several megabytes on every page load
     * for a value that cannot have changed. It is only recomputed when the
     * stored one is missing or malformed, so a hand-inserted row still works.
     */
    private function checksum(string $absolute, string $recorded): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $recorded) === 1) {
            return $recorded;
        }

        $hash = @hash_file('sha256', $absolute);

        return is_string($hash) ? $hash : '';
    }

    /** Whether downloads are enabled at all (an operator switch). */
    public function downloadsEnabled(): bool
    {
        return Env::bool('APP_DOWNLOADS_ENABLED', true);
    }
}
