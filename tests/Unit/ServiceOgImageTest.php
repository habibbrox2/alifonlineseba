<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Twig\TwigExtension;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The per-service share card is a *file on disk*, not something Twig can
 * fabricate, so the template and the generator can drift apart silently: add a
 * service, ship the code, forget to run the generator, and every share of that
 * service points at a 404 — which Telegram renders as a blank grey box rather
 * than falling back to anything.
 *
 * These tests pin the fallback and, just as importantly, the pixel size that
 * travels with the path. Declaring a 1200x600 banner as 630 tall does not
 * error; it just shifts the whole preview, because crawlers lay the card out
 * from those numbers before the image loads.
 */
final class ServiceOgImageTest extends Unit
{
    private const COVER = ['path' => '/assets/img/og-cover.png', 'width' => 1200, 'height' => 630];

    public function testReturnsTheGeneratedBannerWithItsRealSize(): void
    {
        $ext = new TwigExtension();

        // A slug whose banner really is on disk (the generator writes one per
        // active service). Skipped rather than failed if the build artefacts are
        // absent, since a fresh checkout has not run the generator yet.
        $banner = $ext->serviceOgImage(['slug' => 'official-server-copy']);

        if ($banner === self::COVER) {
            $this->markTestSkipped('OG banners not generated; run scripts/generate-service-og-image.py');
        }

        assertSame('/assets/img/og/service-official-server-copy.png', $banner['path']);
        assertSame(1200, $banner['width']);
        assertSame(600, $banner['height']);
    }

    public function testFallsBackToTheSiteCoverWhenNoBannerExists(): void
    {
        $ext = new TwigExtension();

        assertSame(self::COVER, $ext->serviceOgImage(['slug' => 'no-such-service']));
    }

    /**
     * ServiceDetailAction hands the template a repository array; other actions
     * pass hydrated objects. Twig resolves `.slug` against either, so the helper
     * has to as well — a signature that assumed one of them 500s the service
     * page, which is exactly what happened the first time round.
     */
    public function testAcceptsBothArraysAndObjects(): void
    {
        $ext = new TwigExtension();

        assertSame(
            $ext->serviceOgImage(['slug' => 'official-server-copy']),
            $ext->serviceOgImage((object) ['slug' => 'official-server-copy']),
        );
    }

    /**
     * The slug is concatenated into a filesystem path. A traversal sequence must
     * not be able to reach outside public/assets/img/og — and must not be
     * reported as a generated banner just because some file matched.
     */
    public function testRejectsSlugsThatAreNotPlainPathSegments(): void
    {
        $ext = new TwigExtension();

        foreach (['../../config/common', 'a/b', '..', 'A Service', '', 'x.png'] as $slug) {
            assertSame(self::COVER, $ext->serviceOgImage(['slug' => $slug]), $slug);
        }
    }

    public function testHandlesMissingSlug(): void
    {
        $ext = new TwigExtension();

        assertSame(self::COVER, $ext->serviceOgImage([]));
        assertSame(self::COVER, $ext->serviceOgImage((object) []));
    }
}
