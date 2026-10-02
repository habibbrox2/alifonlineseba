<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;
use App\Service\PaymentBrand;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Guards the payment brand table, which is three parallel lists that must agree:
 * the PHP constants, the `.pay-*` rules in the stylesheet, and the logo files on
 * disk. Nothing in the type system connects them, and every way they can drift
 * fails silently — the page still renders, just in the wrong colours, or with an
 * initials tile where a logo should be.
 *
 * So this asserts the joins, not the values:
 *
 *   1. every brand key has a light and a dark `.pay-*` rule carrying all five
 *      tokens, outside `@layer`, in the source *and* in the built stylesheet —
 *      the same purge trap {@see AccentPaletteCssTest} documents for accents;
 *   2. the CSS `--pay` equals the PHP `color`, so a brand recolour cannot land
 *      on one side only;
 *   3. every logo named by the table is a readable, well-formed file on disk;
 *   4. the table covers {@see TopupRepository::METHODS}, the list the submit
 *      path actually validates against.
 */
final class PaymentBrandTest extends Unit
{
    /** The tokens each `.pay-*` rule must define. */
    private const TOKENS = ['--pay', '--pay-soft', '--pay-line', '--pay-ink', '--pay-on'];

    private const SOURCE = __DIR__ . '/../../resources/css/app.css';
    private const BUILT = __DIR__ . '/../../public/assets/css/app.css';
    private const PUBLIC = __DIR__ . '/../../public';

    private PaymentBrand $brands;

    protected function _before(): void
    {
        $this->brands = new PaymentBrand();
    }

    // ─── PHP ⇄ repository ───────────────────────────────────────────────

    /**
     * The table is a presentation concern layered on top of the list the submit
     * path validates against. A method that can be submitted but has no brand
     * renders as the unbranded `pay-default` green; a brand that no longer has
     * a method is dead weight nobody notices.
     */
    public function testEverySubmittableMethodHasABrand(): void
    {
        assertSame(
            [],
            $this->brands->missingFromRepository(),
            'PaymentBrand::BRANDS is missing a method that TopupRepository::METHODS accepts. '
            . 'The picker would fall back to the unbranded .pay-default tile for it.'
        );
    }

    public function testBrandTableHasNoStaleKeys(): void
    {
        $stale = array_diff($this->brands->keys(), TopupRepository::METHODS);

        assertSame(
            [],
            array_values($stale),
            'PaymentBrand::BRANDS lists a method TopupRepository::METHODS no longer accepts: '
            . implode(', ', $stale)
        );
    }

    /**
     * Labels deliberately live in SettingsRepository so the two spellings cannot
     * drift. If a brand key loses its label there, `get()` silently falls back to
     * a derived `ucfirst($key)` and the card reads "Bkash" instead of "bKash".
     */
    public function testEveryBrandKeyHasACanonicalLabel(): void
    {
        foreach ($this->brands->keys() as $key) {
            self::assertArrayHasKey(
                $key,
                SettingsRepository::METHOD_LABELS,
                "SettingsRepository::METHOD_LABELS has no entry for \"$key\", so the card "
                . 'falls back to a derived label instead of the operator\'s own spelling.'
            );
        }
    }

    // ─── view model ─────────────────────────────────────────────────────

    public function testGetReturnsACompleteViewModel(): void
    {
        foreach ($this->brands->keys() as $key) {
            $brand = $this->brands->get($key);

            self::assertArrayHasKey('key', $brand);
            self::assertArrayHasKey('label', $brand);
            self::assertArrayHasKey('css_class', $brand);
            self::assertArrayHasKey('color', $brand);
            self::assertArrayHasKey('logo', $brand);
            self::assertArrayHasKey('initials', $brand);

            assertSame($key, $brand['key']);
            assertSame('pay-' . $key, $brand['css_class']);
            assertSame(PaymentBrand::BRANDS[$key]['color'], $brand['color']);
            self::assertNotSame('', $brand['label'], "Brand \"$key\" resolved to an empty label");
            self::assertNotSame('', $brand['initials'], "Brand \"$key\" resolved to empty initials");
        }
    }

    public function testAllCoversEveryKeyInPickerOrder(): void
    {
        $all = $this->brands->all();

        assertSame($this->brands->keys(), array_keys($all), 'all() must preserve the declared order — the picker renders it as-is');
    }

    /**
     * `recharge-pay.twig` builds the key from a query parameter, so an unknown or
     * missing method must render something unbranded rather than throw a 500.
     */
    public function testUnknownKeyDegradesToTheUnbrandedFallback(): void
    {
        foreach (['nope', '', '  ', 'BKASH '] as $key) {
            $brand = $this->brands->get($key);

            if (trim($key) !== '' && strtolower(trim($key)) !== 'bkash') {
                assertSame('pay-default', $brand['css_class'], "Unknown key \"$key\" must not claim a brand class");
                self::assertNotSame('', $brand['label']);
                assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $brand['color'], "Unknown key \"$key\" must still resolve a colour");
            }
        }
    }

    /** Lookup is case- and whitespace-insensitive, because it is fed raw input. */
    public function testLookupIsNormalised(): void
    {
        $expected = $this->brands->get('bkash');

        foreach (['  BKASH', 'Bkash', "bkash\n"] as $key) {
            assertSame($expected, $this->brands->get($key), "Key \"$key\" should normalise to the bKash brand");
        }
    }

    // ─── logos on disk ──────────────────────────────────────────────────

    public function testEveryBrandLogoExistsAndIsReadable(): void
    {
        foreach (PaymentBrand::BRANDS as $key => $brand) {
            $path = $brand['logo'];
            $file = self::PUBLIC . $path;

            self::assertStringStartsWith('/assets/', $path, "Brand \"$key\" logo must be a public asset path");
            self::assertFileExists($file, "Brand \"$key\" logo is missing at $file — the picker will fall back to initials");
            assertGreaterThan(
                0,
                (int) filesize($file),
                "Brand \"$key\" logo is empty"
            );

            $svg = (string) file_get_contents($file);
            assertDoesNotMatchRegularExpression(
                '/<script\b/i',
                $svg,
                "Brand \"$key\" logo contains a script element"
            );
            self::assertNotNull(
                simplexml_load_string($svg),
                "Brand \"$key\" logo is not well-formed XML; browsers will show a broken image."
            );
        }
    }

    /**
     * The official marks are wide wordmarks — bKash 2.7:1, Nagad 3.0:1, Rocket
     * 1.6:1 — so `.pay-mark` sizes by height and lets the width follow. That only
     * works if the browser can recover the real aspect ratio, and an SVG without
     * width/height falls back to a 300×150 default: the artwork is then fitted
     * into a box of the wrong shape and renders visibly squashed.
     *
     * This is the check that catches it — the shipped bKash and Nagad files both
     * carried a stale `width="1200" height="800"` from their export tool.
     */
    public function testLogoIntrinsicSizeMatchesItsViewBox(): void
    {
        foreach (PaymentBrand::BRANDS as $key => $brand) {
            $doc = simplexml_load_string((string) file_get_contents(self::PUBLIC . $brand['logo']));
            $attrs = $doc === false ? [] : $doc->attributes() ?? [];

            self::assertNotEmpty($attrs, "Brand \"$key\" logo has no <svg> attributes");
            foreach (['width', 'height', 'viewBox'] as $attr) {
                self::assertArrayHasKey($attr, iterator_to_array($attrs), "Brand \"$key\" logo is missing $attr");
            }

            $viewBox = preg_split('/[\s,]+/', trim((string) $attrs['viewBox'])) ?: [];
            self::assertCount(4, $viewBox, "Brand \"$key\" logo has a malformed viewBox");

            $width = (float) $attrs['width'];
            $height = (float) $attrs['height'];

            assertGreaterThan(0.0, $width, "Brand \"$key\" logo has a non-positive width");
            assertGreaterThan(0.0, $height, "Brand \"$key\" logo has a non-positive height");

            // Within 0.5 user units: the viewBox is trimmed to the ink, so the
            // two are equal by construction, not merely close.
            self::assertEqualsWithDelta(
                (float) $viewBox[2],
                $width,
                0.5,
                "Brand \"$key\" logo width ($width) disagrees with its viewBox width ({$viewBox[2]}); the artwork will be squashed."
            );
            self::assertEqualsWithDelta(
                (float) $viewBox[3],
                $height,
                0.5,
                "Brand \"$key\" logo height ($height) disagrees with its viewBox height ({$viewBox[3]}); the artwork will be squashed."
            );
        }
    }

    // ─── PHP ⇄ CSS ──────────────────────────────────────────────────────

    public function testEveryBrandKeyHasLightRuleInSource(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        self::assertNotEmpty($css, 'resources/css/app.css is empty or unreadable');

        foreach ($this->brands->keys() as $key) {
            self::assertRuleDefinesTokens($css, self::lightSelector($key), $key, 'light');
        }
    }

    public function testEveryBrandKeyHasDarkRuleInSource(): void
    {
        $css = (string) file_get_contents(self::SOURCE);

        foreach ($this->brands->keys() as $key) {
            self::assertRuleDefinesTokens($css, self::darkSelector($key), $key, 'dark');
        }
    }

    /**
     * The class names are built at runtime (`pay-{{ key }}` in Twig), so
     * Tailwind's scanner never sees them and any rule inside `@layer` is purged
     * from the build without an error. See AccentPaletteCssTest for the full
     * story; this is the same guard for the payment table.
     */
    public function testPaletteRulesAreNotInsideALayer(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        $selector = self::paletteSelectorRegex();

        $offset = 0;
        while (preg_match('/@layer\b[^{]*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($css, '}', $start);
            self::assertNotSame(false, $end, 'Unclosed @layer block in app.css');

            $body = substr($css, $start, (int) $end - $start);
            assertDoesNotMatchRegularExpression(
                '/' . $selector . '\s*\{/',
                $body,
                'A .pay-* palette rule is declared inside an @layer block. Tailwind cannot see '
                . 'runtime-built class names, so a layered rule is purged from the build. '
                . 'Move the payment palette out of @layer — see the comment above it.'
            );

            $offset = (int) $end + 1;
        }
    }

    /**
     * Skipped, not failed, when `npm run build` has not run: `public/assets` is
     * gitignored, so a fresh clone legitimately has no stylesheet to inspect.
     */
    public function testEveryBrandSurvivesTheTailwindBuild(): void
    {
        if (!is_file(self::BUILT)) {
            self::markTestSkipped(
                'Built stylesheet is missing (public/assets is gitignored). '
                . 'Run `npm run build` to check the payment palette against real output.'
            );
        }

        $css = (string) file_get_contents(self::BUILT);
        self::assertNotEmpty($css, 'public/assets/css/app.css is empty');

        foreach ($this->brands->keys() as $key) {
            self::assertRuleDefinesTokens($css, self::lightSelector($key), $key, 'built light');
        }

        assertDoesNotMatchRegularExpression(
            '/@layer[^{]*\{[^}]*' . self::paletteSelectorRegex() . '\s*\{/',
            $css,
            'The built stylesheet still contains a layered .pay-* rule, which means the '
            . 'payment tokens are being purged at build time.'
        );
    }

    /**
     * The recolour that matters: the PHP table and the stylesheet hold the same
     * brand colour in two places, and a one-sided edit is invisible until someone
     * compares the rendered button against the brand guidelines.
     */
    public function testCssBaseColourMatchesThePhpTable(): void
    {
        foreach ([self::SOURCE, self::BUILT] as $path) {
            $css = (string) file_get_contents($path);
            self::assertNotEmpty($css, "$path is empty or unreadable");

            foreach (PaymentBrand::BRANDS as $key => $brand) {
                $selector = $path === self::SOURCE ? self::lightSelector($key) : self::lightSelector($key);
                self::assertSame(
                    1,
                    preg_match('/' . $selector . '\s*\{([^}]*)\}/', $css, $m),
                    "No light .pay-$key rule found in $path"
                );

                assertTrue(
                    (bool) preg_match('/(^|[\s;])--pay\s*:\s*#([0-9a-f]{6})\s*;/i', $m[1], $t),
                    "The light .pay-$key rule in $path does not define --pay as a hex colour."
                );

                self::assertSame(
                    strtolower($brand['color']),
                    strtolower('#' . $t[2]),
                    "Brand \"$key\" is #{$brand['color']} in PaymentBrand but #{$t[2]} in $path. "
                    . 'One brand colour, one place to change it.'
                );
            }
        }
    }

    /**
     * Unlike the accent palette, the `.pay-*` component rules are deliberately
     * unlayered (they have to outrank the `border-neutral-200` utility in the
     * template), so "is it at the start of a line" no longer separates palette
     * entries from components. What does separate them is the `--pay` token: only
     * a palette entry defines it, and that is exactly the property we care about.
     */
    public function testCssDeclaresNoUnknownBrandKeys(): void
    {
        $css = (string) file_get_contents(self::SOURCE);

        assertTrue(
            preg_match_all('/^\.pay-([a-z]+)\s*\{([^}]*)\}/m', $css, $m, PREG_SET_ORDER) > 0,
            'No top-level .pay-* rules found in app.css — the payment palette block moved or was deleted.'
        );

        $declared = [];
        foreach ($m as $rule) {
            if (preg_match('/(^|[\s;])--pay\s*:/', $rule[2]) === 1) {
                $declared[] = $rule[1];
            }
        }

        $known = array_merge($this->brands->keys(), ['default']);
        $unknown = array_diff(array_values(array_unique($declared)), $known);

        assertSame(
            [],
            array_values($unknown),
            'CSS defines .pay-* palette key(s) with no matching brand entry: ' . implode(', ', $unknown)
        );
    }

    /**
     * `.pay-mark` must size by height. The three official marks are wide
     * wordmarks with three different aspect ratios; a fixed square box is what
     * squashed all of them before.
     */
    public function testMarkIsSizedByHeightNotByAWidth(): void
    {
        $css = (string) file_get_contents(self::SOURCE);

        self::assertSame(
            1,
            preg_match('/\.pay-mark\s*\{([^}]*)\}/', $css, $m),
            'No .pay-mark rule in resources/css/app.css'
        );

        $body = $m[1];

        assertDoesNotMatchRegularExpression(
            '/(^|[\s;])width\s*:\s*\d/',
            $body,
            '.pay-mark sets an explicit width. The official marks are wide wordmarks of differing '
            . 'aspect ratios; size by height and let the width follow (see the rule\'s comment).'
        );
        assertMatchesRegularExpression(
            '/(^|[\s;])height\s*:\s*\d/',
            $body,
            '.pay-mark must pin a height so all three marks line up across the picker row.'
        );
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /**
     * Selector for a brand key's light rule.
     *
     * The negative lookbehind keeps it from matching the `.dark .pay-bkash { … }`
     * rule that follows in the same file, which would otherwise let a deleted
     * light rule still match. Line anchoring is not an option instead, because
     * the minified build puts every rule on one line.
     */
    private static function lightSelector(string $key): string
    {
        return '(?<!\.dark )\.pay-' . $key . '\b';
    }

    private static function darkSelector(string $key): string
    {
        return '\.dark \.pay-' . $key . '\b';
    }

    /**
     * Alternation matching exactly the brand keys, e.g. `\.pay-(?:bkash|nagad)`.
     *
     * Anchored on the key list so the component classes (`.pay-mark`,
     * `.pay-panel`, …) cannot be mistaken for palette entries.
     */
    private static function paletteSelectorRegex(): string
    {
        return '\.pay-(?:' . implode('|', array_keys(PaymentBrand::BRANDS)) . ')\b';
    }

    /**
     * @param string $selector Regex matching the selector under test, *without*
     *                         a trailing brace — this method adds `\s*\{` itself.
     */
    private static function assertRuleDefinesTokens(string $css, string $selector, string $key, string $context): void
    {
        assertTrue(
            preg_match('/' . $selector . '\s*\{([^}]*)\}/', $css, $m) === 1,
            "No CSS rule matches `$selector` — brand key \"$key\" has no $context rule. "
            . 'Add it next to the other entries in the payment palette block in resources/css/app.css.'
        );

        foreach (self::TOKENS as $token) {
            assertMatchesRegularExpression(
                '/(^|[\s;])' . preg_quote($token, '/') . '\s*:/',
                $m[1],
                "The $context rule for \"$key\" does not define $token. A missing token means a "
                . 'component falls back to the :root default and loses its brand colour.'
            );
        }
    }
}
