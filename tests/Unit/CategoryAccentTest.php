<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CategoryAccent;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The category accent drives every category-tinted colour in the UI, so its
 * resolution order has to stay stable: an admin choice wins, otherwise the
 * name/slug decides, otherwise a slug hash keeps two unconfigured categories
 * visually distinct without any backfill.
 */
final class CategoryAccentTest extends Unit
{
    public function testExplicitAccentWins(): void
    {
        $accents = new CategoryAccent();

        $key = $accents->key(['name' => 'টিন সার্টিফিকেট', 'slug' => 'tin', 'accent' => 'violet']);

        assertSame('violet', $key);
    }

    public function testUnknownExplicitAccentFallsBackToKeywords(): void
    {
        $accents = new CategoryAccent();

        $key = $accents->key(['name' => 'NID কপি', 'slug' => 'nid', 'accent' => 'not-a-colour']);

        assertSame('sky', $key);
    }

    /**
     * @dataProvider keywordProvider
     */
    public function testKeywordMatching(string $name, string $expected): void
    {
        $accents = new CategoryAccent();

        assertSame($expected, $accents->key(['name' => $name, 'slug' => 'x' . md5($name)]));
    }

    public static function keywordProvider(): array
    {
        return [
            'জাতীয় পরিচয়পত্র' => ['জাতীয় পরিচয়পত্র', 'indigo'],
            'ভোটার তালিকা যাচাই' => ['ভোটার তালিকা যাচাই', 'violet'],
            'টিন সার্টিফিকেট' => ['টিন সার্টিফিকেট', 'emerald'],
            'জন্ম নিবন্ধন' => ['জন্ম নিবন্ধন', 'rose'],
            'মোবাইল বিল পেমেন্ট' => ['মোবাইল বিল পেমেন্ট', 'teal'],
            'জমি রেজিস্ট্রেশন' => ['জমি রেজিস্ট্রেশন', 'lime'],
            'english nid copy' => ['english nid copy', 'sky'],
        ];
    }

    public function testHashFallbackIsStableAndInPalette(): void
    {
        $accents = new CategoryAccent();
        $first = $accents->key(['name' => 'একটি অচেনা বিভাগ', 'slug' => 'unknown-thing', 'accent' => 'auto']);

        assertContains($first, CategoryAccent::PALETTE);
        assertSame($first, $accents->key('unknown-thing'));
    }

    public function testPlainStringIsAcceptedAsSlug(): void
    {
        $accents = new CategoryAccent();

        assertSame('accent-teal', $accents->cssClass('grameenphone'));
    }

    public function testMissingCategoryUsesTheBrandDefault(): void
    {
        $accents = new CategoryAccent();

        assertSame(CategoryAccent::DEFAULT_ACCENT, $accents->key(null));
    }

    public function testValidityCheckGuardsThePalette(): void
    {
        $accents = new CategoryAccent();

        assertTrue($accents->isValid('rose'));
        assertFalse($accents->isValid('auto'));
        assertFalse($accents->isValid(null));
    }

    public function testOptionsCoverTheWholePaletteWithLabels(): void
    {
        $accents = new CategoryAccent();
        $options = $accents->options();

        assertSame(CategoryAccent::PALETTE, array_keys($options));
        foreach ($options as $label) {
            assertNotSame('', $label);
        }
    }

    /**
     * A palette key handed in as the category is already resolved. Re-resolving
     * it as a slug sent key('violet') through keyword/hash matching and returned
     * 'teal', which silently broke every "never reuse the category colour"
     * guard in variantKeys().
     */
    public function testPaletteKeyPassedAsCategoryIsNotReResolved(): void
    {
        $accents = new CategoryAccent();

        foreach (CategoryAccent::PALETTE as $key) {
            assertSame($key, $accents->key($key));
            assertSame('accent-' . $key, $accents->cssClass($key));
        }
    }

    public function testVariantsOfOneServiceNeverShareAnAccent(): void
    {
        $accents = new CategoryAccent();

        foreach (CategoryAccent::PALETTE as $category) {
            $variants = [];
            for ($i = 0; $i < 11; $i++) {
                $variants[] = ['label' => 'অপশন ' . $i];
            }

            $keys = $accents->variantKeys($variants, $category);

            // The category colour is held back, so only 11 hues are available.
            assertCount(11, $keys);
            assertSame($keys, array_values(array_unique($keys)));
            assertNotContains($category, $keys, 'a variant reused the category accent');
        }
    }

    public function testVariantKeepsAnExplicitAccent(): void
    {
        $accents = new CategoryAccent();

        $keys = $accents->variantKeys(
            [['label' => 'সাধারণ', 'accent' => 'rose'], ['label' => 'এক্সপ্রেস']],
            'sky'
        );

        assertSame('rose', $keys[0]);
    }

    public function testNoVariantsMeansNoAccents(): void
    {
        $accents = new CategoryAccent();

        assertSame([], $accents->variantKeys(null, 'sky'));
        assertSame([], $accents->variantKeys([], 'sky'));
    }
}
