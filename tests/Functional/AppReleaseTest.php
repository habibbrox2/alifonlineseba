<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\AppReleaseRepository;
use App\Service\AppReleaseService;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The APK manifest, and the one guarantee /app makes: it renders.
 *
 * Every test here starts from an empty `app_release` table, which is the state
 * a fresh install is actually in — and the state that used to take the site
 * down. `queryOne()` answers null for an empty result, so a repository that
 * compared against `false` handed callers an empty array, which sailed past
 * every `=== null` guard and only failed further in, as an undefined-key
 * error on a page the whole site advertises.
 */
final class AppReleaseTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private AppReleaseRepository $releases;
    private AppReleaseService $service;
    private string $releaseDir;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->releases = new AppReleaseRepository($this->db);

        // A throwaway directory, so the tests never read or delete the real
        // APK an operator has uploaded to web/releases.
        $this->releaseDir = sys_get_temp_dir() . '/sheba-app-release-test-' . getmypid();
        if (!is_dir($this->releaseDir)) {
            mkdir($this->releaseDir, 0o750, true);
        }
        $this->service = new AppReleaseService($this->releases, $this->releaseDir);
    }

    protected function _after(): void
    {
        try {
            $this->db->createCommand()->delete('{{%app_release}}')->execute();
            foreach (glob($this->releaseDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->releaseDir);
        } finally {
            // _before() builds a fresh container, and so a fresh PDO
            // connection, for every test. Without this the suite leaks one
            // connection per test method and the rest of the run dies on "Too
            // many connections" once the count passes max_connections. The
            // finally matters: a test that fails above must not leak either.
            $this->db->close();
        }
    }

    // ---------------------------------------------------------------------
    // "No row" is null — the regression this suite exists for.
    // ---------------------------------------------------------------------

    public function testPublishedIsNullWhenNothingIsPublished(): void
    {
        assertNull($this->releases->published());
    }

    public function testLatestIsNullWhenTableIsEmpty(): void
    {
        assertNull($this->releases->latest());
    }

    public function testMinVersionCodeIsZeroWithNoPublishedRow(): void
    {
        assertSame(0, $this->releases->minVersionCode());
    }

    public function testCurrentIsNullWithNoRelease(): void
    {
        assertNull($this->service->current());
    }

    public function testVersionPayloadDegradesToNoRelease(): void
    {
        $payload = $this->service->versionPayload(12);

        assertSame(0, $payload['version_code']);
        assertSame('', $payload['version_name']);
        assertSame(0, $payload['min_version_code']);
        assertFalse($payload['update_available']);
        assertFalse($payload['update_required']);
        assertSame(0, $payload['size_bytes']);
        assertSame('', $payload['sha256']);
        assertNull($payload['release_notes']);
        assertNull($payload['download_url']);
        assertNull($payload['published_at']);
    }

    // ---------------------------------------------------------------------
    // publish() — upsert, and the switch to the new release.
    // ---------------------------------------------------------------------

    public function testPublishInsertsAVersionThatDoesNotExistYet(): void
    {
        $id = $this->releases->publish($this->releaseData(5, '1.0.0'));

        assertGreaterThan(0, $id, 'A new version_code must be inserted, not silently skipped.');

        $row = $this->releases->latest();
        assertNotNull($row);
        assertSame(5, (int) $row['version_code']);
        assertSame('1.0.0', $row['version_name']);
        assertSame(1, (int) $row['is_published']);
        assertNotNull($row['published_at']);
    }

    public function testPublishUpdatesAnExistingVersionCode(): void
    {
        $first = $this->releases->publish($this->releaseData(5, '1.0.0'));

        // Republishing the same version code must edit the row, not collide
        // with the unique index on version_code.
        $this->releases->publish(array_replace($this->releaseData(5, '1.0.1'), ['release_notes' => 'নতুন নোট']));

        $row = $this->releases->latest();
        assertNotNull($row);
        assertSame($first, (int) $row['id']);
        assertSame('1.0.1', $row['version_name']);
        assertSame('নতুন নোট', $row['release_notes']);
    }

    public function testPublishDemotesTheReleaseItReplaces(): void
    {
        $this->releases->publish($this->releaseData(5, '1.0.0'));
        $this->releases->publish($this->releaseData(6, '1.1.0'));

        $published = $this->releases->published();
        assertNotNull($published);
        assertSame(6, (int) $published['version_code'], 'Readers always see the newest.');

        $count = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%app_release}} WHERE [[is_published]] = 1'
        )->queryScalar();
        assertSame(1, $count, 'Exactly one release may be published at a time.');
    }

    public function testUnpublishWithdrawsTheRelease(): void
    {
        $id = $this->releases->publish($this->releaseData(5, '1.0.0'));

        assertTrue($this->releases->unpublish($id));
        assertNull($this->releases->published());
        assertNull($this->service->current());
    }

    public function testUnpublishLeavesAnAlreadyWithdrawnRowAlone(): void
    {
        $id = $this->releases->publish($this->releaseData(5, '1.0.0'));
        $this->releases->unpublish($id);

        assertFalse($this->releases->unpublish($id));
    }

    // ---------------------------------------------------------------------
    // current() — resolving the published row to bytes on disk.
    // ---------------------------------------------------------------------

    public function testCurrentResolvesThePublishedApk(): void
    {
        $bytes = 'PK' . str_repeat('a', 2048);
        file_put_contents($this->releaseDir . '/app-1.0.0.apk', $bytes);
        $this->releases->publish($this->releaseData(5, '1.0.0'));

        $release = $this->service->current();
        assertNotNull($release);
        assertSame(5, (int) $release['version_code']);
        assertSame(strlen($bytes), (int) $release['apk_size']);
        assertSame(hash('sha256', $bytes), $release['sha256']);
    }

    public function testCurrentKeepsARecordedDigestInsteadOfRehashing(): void
    {
        file_put_contents($this->releaseDir . '/app-1.0.0.apk', 'PK' . str_repeat('a', 512));
        $recorded = str_repeat('b', 64);
        $this->releases->publish(array_replace($this->releaseData(5, '1.0.0'), ['sha256' => $recorded]));

        $release = $this->service->current();
        assertNotNull($release);
        assertSame($recorded, $release['sha256'], 'A well-formed recorded hash is signed off, not recomputed.');
    }

    public function testCurrentRecomputesAMalformedDigest(): void
    {
        file_put_contents($this->releaseDir . '/app-1.0.0.apk', 'PK' . str_repeat('c', 128));
        $this->releases->publish(array_replace($this->releaseData(5, '1.0.0'), ['sha256' => 'not-a-hash']));

        $release = $this->service->current();
        assertNotNull($release);
        assertSame(hash('sha256', file_get_contents($this->releaseDir . '/app-1.0.0.apk')), $release['sha256']);
    }

    public function testCurrentIsNullWhenTheApkHasBeenPruned(): void
    {
        // A published row whose file is gone is "no release", never a 500.
        $this->releases->publish($this->releaseData(5, '1.0.0'));

        assertNull($this->service->current());
    }

    public function testCurrentIsNullWhenTheApkIsEmpty(): void
    {
        file_put_contents($this->releaseDir . '/app-1.0.0.apk', '');
        $this->releases->publish($this->releaseData(5, '1.0.0'));

        assertNull($this->service->current());
    }

    public function testCurrentIsNullForARowPointingOutsideTheReleasesDirectory(): void
    {
        $this->releases->publish(array_replace($this->releaseData(5, '1.0.0'), ['apk_path' => '../../.env']));

        assertNull($this->service->current());
    }

    public function testCurrentIsNullWhenThePathColumnIsBlank(): void
    {
        $this->releases->publish(array_replace($this->releaseData(5, '1.0.0'), ['apk_path' => '']));

        assertNull($this->service->current());
    }

    // ---------------------------------------------------------------------
    // versionPayload() — what the installed client is told.
    // ---------------------------------------------------------------------

    public function testVersionPayloadOffersAnUpdateToAnOlderClient(): void
    {
        $bytes = 'PK' . str_repeat('d', 256);
        file_put_contents($this->releaseDir . '/app-1.1.0.apk', $bytes);
        $this->releases->publish(array_replace($this->releaseData(6, '1.1.0'), ['min_version_code' => 5]));

        $payload = $this->service->versionPayload(5);

        assertSame(6, $payload['version_code']);
        assertTrue($payload['update_available']);
        assertFalse($payload['update_required'], 'A client at the floor is offered, not forced.');
        assertSame('/app/apk', $payload['download_url']);
        assertSame(strlen($bytes), $payload['size_bytes']);
    }

    public function testVersionPayloadRequiresAnUpdateBelowTheFloor(): void
    {
        file_put_contents($this->releaseDir . '/app-1.1.0.apk', 'PK' . str_repeat('e', 256));
        $this->releases->publish(array_replace($this->releaseData(6, '1.1.0'), ['min_version_code' => 6]));

        assertTrue($this->service->versionPayload(3)['update_required']);
    }

    public function testVersionPayloadTellsAnUpToDateClientNothingIsWaiting(): void
    {
        file_put_contents($this->releaseDir . '/app-1.1.0.apk', 'PK' . str_repeat('f', 256));
        $this->releases->publish(array_replace($this->releaseData(6, '1.1.0'), ['min_version_code' => 5]));

        $payload = $this->service->versionPayload(6);
        assertFalse($payload['update_available']);
        assertFalse($payload['update_required']);
    }

    public function testVersionPayloadIgnoresANonsenseClientVersion(): void
    {
        file_put_contents($this->releaseDir . '/app-1.1.0.apk', 'PK' . str_repeat('g', 256));
        $this->releases->publish(array_replace($this->releaseData(6, '1.1.0'), ['min_version_code' => 5]));

        assertFalse($this->service->versionPayload(0)['update_available']);
        assertFalse($this->service->versionPayload(-1)['update_available']);
    }

    // ---------------------------------------------------------------------
    // absolutePath() — the traversal guard on operator input.
    // ---------------------------------------------------------------------

    public function testAbsolutePathResolvesInsideTheDirectory(): void
    {
        file_put_contents($this->releaseDir . '/app.apk', 'PK');

        $resolved = $this->service->absolutePath('app.apk');

        assertNotNull($resolved);
        // absolutePath() normalises to forward slashes so the prefix
        // comparison is separator-independent; compare on the same footing.
        assertSame(
            str_replace('\\', '/', (string) realpath($this->releaseDir . '/app.apk')),
            $resolved,
        );
    }

    /**
     * @dataProvider refusedPaths
     */
    public function testAbsolutePathRefusesPathsOutsideTheDirectory(string $path): void
    {
        assertNull($this->service->absolutePath($path));
    }

    public static function refusedPaths(): iterable
    {
        yield 'parent traversal' => ['../.env'];
        yield 'nested traversal' => ['nested/../../../.env'];
        yield 'unix absolute' => ['/etc/passwd'];
        yield 'windows absolute' => ['C:\\Windows\\System32\\config\\SAM'];
        yield 'unc path' => ['\\\\server\\share\\file'];
        yield 'nul byte' => ["app.apk\0.png"];
        yield 'empty' => ['   '];
    }

    // ---------------------------------------------------------------------

    private function releaseData(int $versionCode, string $versionName): array
    {
        return [
            'version_code' => $versionCode,
            'version_name' => $versionName,
            'apk_path' => 'app-' . $versionName . '.apk',
            'apk_size' => 0,
            'sha256' => '',
            'min_version_code' => 0,
            'release_notes' => null,
        ];
    }
}
