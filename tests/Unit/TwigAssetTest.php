<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Twig\TwigExtension;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;

/**
 * The public CSS/JS/sprite live in the gitignored `public/assets` build directory.
 * A deploy swaps their contents but keeps the same path, so `asset()` has to append
 * a version query — otherwise browsers keep serving the previous build.
 */
final class TwigAssetTest extends Unit
{
    public function testExistingAssetGetsAVersionQuery(): void
    {
        $url = (new TwigExtension())->asset('/assets/css/app.css');

        assertStringStartsWith('/assets/css/app.css?v=', $url);
        assertMatchesRegularExpression('#^/assets/css/app\.css\?v=\d+$#', $url);
    }

    public function testMissingAssetIsReturnedWithoutQuery(): void
    {
        $url = (new TwigExtension())->asset('/assets/css/does-not-exist.css');

        assertSame('/assets/css/does-not-exist.css', $url);
    }
}
