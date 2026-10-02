<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PaymentBrand;
use App\Service\PaymentBrandPalette;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * Guards the *derivation*, where PaymentBrandTest only guards the join.
 *
 * Generating the palette moves the hex values out of the stylesheet and into a
 * formula, which trades one silent failure for another: if the formula is wrong
 * the page still renders, just with unreadable text on a brand button, and if it
 * is stale the CSS is a week behind the PHP. Neither shows up as an error. So
 * this asserts the three properties that make the generated block trustworthy:
 *
 *   1. the committed region is exactly what the current brand table derives —
 *      byte for byte, so a hand-edit inside the markers cannot survive a merge;
 *   2. every derived token keeps the brand's hue, which is the whole reason to
 *      compute a tint instead of storing one;
 *   3. the pairs that carry text clear WCAG AA contrast, so adding a brand at
 *      6pm cannot ship an unreadable button.
 *
 * {@see PaymentBrandTest} still owns the other half: that both tables exist,
 * sit outside `@layer`, and agree with the repository's method list.
 */
final class PaymentBrandPaletteTest extends Unit
{
    private const SOURCE = __DIR__ . '/../../resources/css/app.css';
    private const SCRIPT = __DIR__ . '/../../scripts/generate-pay-palette.php';

    /** WCAG AA for normal text, the floor both text pairs must clear. */
    private const AA = 4.5;

    /**
     * 8-bit rounding moves the hue of a very pale tint by a few degrees — the
     * dark `--pay-ink` values are near-grey on purpose — so the check allows
     * generous slack rather than pretending the derivation is exact.
     */
    private const HUE_TOLERANCE_DEGREES = 10.0;

    private PaymentBrandPalette $palette;

    protected function _before(): void
    {
        $this->palette = new PaymentBrandPalette();
    }

    // ─── the file must not drift from the table ─────────────────────────

    /**
     * The core guard. A brand recolour that does not regenerate the CSS leaves
     * a stale pink button next to a new PHP constant, and no test elsewhere can
     * see it: the rule is still syntactically valid and the build still passes.
     */
    public function testCommittedCssMatchesTheBrandTable(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        self::assertNotEmpty($css, 'resources/css/app.css is empty or unreadable');

        $region = $this->palette->generatedRegion();

        self::assertSame(
            1,
            substr_count($css, PaymentBrandPalette::MARKER_BEGIN),
            'Expected exactly one generated-region start marker in app.css'
        );
        self::assertSame(
            1,
            substr_count($css, PaymentBrandPalette::MARKER_END),
            'Expected exactly one generated-region end marker in app.css'
        );

        self::assertStringContainsString(
            $region,
            $css,
            "resources/css/app.css holds a stale payment palette.\n"
            . "Run: php scripts/generate-pay-palette.php\n\n"
        );
    }

    /**
     * Same guarantee from the outside: the `--check` flag CI runs must agree
     * with the file in the repository, so a build cannot regenerate around a
     * failure the unit tests would have reported.
     */
    public function testCheckFlagAgreesWithTheCommittedCss(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() is disabled, cannot run the generator');
        }

        $output = [];
        $code = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SCRIPT) . ' --check 2>&1',
            $output,
            $code
        );

        self::assertSame(
            0,
            $code,
            "palette:check failed, so the committed CSS is stale:\n" . implode("\n", $output)
        );
    }

    // ─── every brand, both themes, all five tokens ───────────────────────

    /**
     * Walks the generated region rule by rule rather than trusting one big
     * string compare, so a failure names the brand and the token instead of
     * dumping four kilobytes of CSS into the test report.
     */
    public function testEveryBrandGetsEveryTokenInBothThemes(): void
    {
        $region = $this->palette->generatedRegion();

        foreach ($this->brandColors() as $key => $color) {
            $light = $this->tokensInRule($region, '.pay-' . $key, $key);
            $dark = $this->tokensInRule($region, '.dark .pay-' . $key, $key);

            self::assertSame($this->palette->lightTokens($color), $light, '.pay-' . $key . ' light tokens');
            self::assertSame($this->palette->darkTokens($color), $dark, '.dark .pay-' . $key . ' dark tokens');
        }
    }

    /**
     * `--pay` in light mode is the brand colour verbatim, so the stylesheet can
     * never show a subtly different shade of a brand than the rest of the app.
     */
    public function testLightPayIsTheBrandColourItself(): void
    {
        foreach (PaymentBrand::BRANDS as $key => $brand) {
            self::assertSame(
                strtolower($brand['color']),
                $this->palette->lightTokens($brand['color'])['--pay'],
                'light --pay for ' . $key . ' must be the published brand colour'
            );
        }
    }

    /**
     * Nothing is shared between brands. A copied tint table is exactly the bug
     * generation exists to prevent, and it is invisible in the rendered page:
     * every card looks coloured, one of them just in the previous brand's hue.
     *
     * `--pay-on` is deliberately exempt — it is a comparison, not a tint, and
     * "white wins" is the correct answer for most brands. A test that forbade
     * it would fail the moment a fourth brand joined bKash and Nagad on white.
     */
    public function testNoTwoBrandsShareATint(): void
    {
        $brands = $this->brandColors();
        $tints = array_values(array_diff(PaymentBrandPalette::TOKENS, ['--pay-on']));

        foreach (['lightTokens', 'darkTokens'] as $method) {
            foreach ($tints as $token) {
                $byValue = [];
                foreach ($brands as $key => $color) {
                    $value = $this->palette->$method($color)[$token];
                    $clash = $byValue[$value] ?? null;

                    self::assertNull(
                        $clash,
                        sprintf('%s gave %s and %s the same %s (%s) — a copied tint, not a derived one',
                            $method, $key, (string) $clash, $token, $value)
                    );

                    $byValue[$value] = $key;
                }
            }
        }
    }

    /**
     * The two tables are separate derivations from one brand colour, so they
     * can drift apart without either looking wrong: a dark brand filling with
     * near-black and near-black text on it renders as an empty button. Asserting
     * the text colour flips with the theme catches that, and catches the
     * subtler version where someone starts deriving `--pay-on` from the *other*
     * table's `--pay`.
     */
    public function testDarkTableInvertsTheLightOnColour(): void
    {
        foreach ($this->brandColors() as $key => $color) {
            $light = $this->palette->lightTokens($color)['--pay-on'];
            $dark = $this->palette->darkTokens($color)['--pay-on'];

            self::assertNotSame(
                $light,
                $dark,
                $key . ' resolves to the same --pay-on in both themes, which means one table ignored its theme'
            );
        }
    }

    // ─── the guarantees the derivation makes ─────────────────────────────

    /**
     * A tint is only recognisable as the brand if it is the same hue. Hue is
     * the one thing carried through untouched, so this is what stops a
     * lightness tweak from quietly turning bKash pink into something else.
     */
    public function testDerivedTokensKeepTheBrandHue(): void
    {
        foreach ($this->brandColors() as $key => $color) {
            $brandHue = self::hue($color);
            self::assertNotNull($brandHue, 'brand colour ' . $color . ' is achromatic, nothing to derive');

            foreach (['lightTokens', 'darkTokens'] as $method) {
                foreach ($this->palette->$method($color) as $token => $value) {
                    $hue = self::hue($value);
                    if ($hue === null) {
                        // `--pay-on` is often plain white, which has no hue.
                        continue;
                    }

                    self::assertLessThanOrEqual(
                        self::HUE_TOLERANCE_DEGREES,
                        self::angleBetween($brandHue, $hue),
                        sprintf('%s(%s) drifted off the %s hue: %s is %s', $method, $key, $color, $token, $value)
                    );
                }
            }
        }
    }

    /**
     * `--pay-ink` is the label on the selected card, drawn on `--pay-soft`.
     * This is the pair that breaks first when a brand is added: a pale tint
     * with a mid tint on top is legible at a glance and unreadable in fact.
     */
    public function testInkIsReadableOnTheSoftFill(): void
    {
        foreach ($this->brandColors() as $key => $color) {
            foreach (['lightTokens', 'darkTokens'] as $method) {
                $tokens = $this->palette->$method($color);
                $ratio = PaymentBrandPalette::contrastRatio($tokens['--pay-ink'], $tokens['--pay-soft']);

                self::assertGreaterThanOrEqual(
                    self::AA,
                    $ratio,
                    sprintf('%s %s: --pay-ink %s on --pay-soft %s is only %.2f:1',
                        $method, $key, $tokens['--pay-ink'], $tokens['--pay-soft'], $ratio)
                );
            }
        }
    }

    /**
     * In dark mode `--pay` is the lifted fill — the dot, the ring, the progress
     * marker — and it has to read as a distinct element against `--pay-soft`.
     * Light mode's `--pay` on `--pay-soft` is a fill against a pale tint, where
     * WCAG has nothing to say; only the dark pair is a real foreground.
     */
    public function testDarkPayStandsApartFromItsOwnFill(): void
    {
        foreach ($this->brandColors() as $key => $color) {
            $tokens = $this->palette->darkTokens($color);
            $ratio = PaymentBrandPalette::contrastRatio($tokens['--pay'], $tokens['--pay-soft']);

            self::assertGreaterThanOrEqual(
                self::AA,
                $ratio,
                sprintf('dark %s: --pay %s on --pay-soft %s is only %.2f:1',
                    $key, $tokens['--pay'], $tokens['--pay-soft'], $ratio)
            );
        }
    }

    /**
     * `--pay-on` is the text on the brand button. White is the right answer for
     * essentially every brand, and the wrong one for a bright one: Nagad's
     * published #EE1C25 only reaches 4.35:1 against white and cannot be pushed
     * over 4.5 without recolouring a brand, so the derivation compares white
     * with a dark tint of the same hue instead of trusting either by default.
     *
     * The synthetic colours below are the two ends of that decision — neither
     * is a current brand, which is the point. They are what a brand added later
     * will look like, and they are the only way to test the branch without
     * waiting for a bright payment method to exist.
     */
    public function testOnColourLeavesWhiteOnlyWhenWhiteIsUnreadable(): void
    {
        $bright = $this->palette->lightTokens('#ffd400');
        self::assertNotSame('#ffffff', $bright['--pay-on'], 'white on a yellow button is unreadable');
        self::assertGreaterThanOrEqual(
            self::AA,
            PaymentBrandPalette::contrastRatio($bright['--pay-on'], $bright['--pay']),
            'the dark fallback for a bright brand must clear AA itself'
        );

        $dark = $this->palette->lightTokens('#7f1d1d');
        self::assertSame(
            '#ffffff',
            $dark['--pay-on'],
            'a dark brand should keep white text, not be handed a tinted one'
        );
        self::assertGreaterThanOrEqual(
            self::AA,
            PaymentBrandPalette::contrastRatio($dark['--pay-on'], $dark['--pay']),
            'white on a dark brand must clear AA'
        );
    }

    /**
     * `cssClass()` hands the template `pay-default` for a method it does not
     * recognise, so the unbranded rule has to come from the same constant
     * `get()` returns — otherwise an unknown method renders in a green the rest
     * of the site has never heard of.
     */
    public function testDefaultRowComesFromTheFallbackConstant(): void
    {
        $fallback = PaymentBrand::FALLBACK_COLOR;
        $brand = new PaymentBrand();

        self::assertSame(
            $fallback,
            $brand->get('a-method-nobody-has-heard-of')['color'],
            'get() and the generated .pay-default must share one colour'
        );

        $region = $this->palette->generatedRegion();
        self::assertSame(
            $this->palette->lightTokens($fallback),
            $this->tokensInRule($region, '.pay-default', 'default'),
            '.pay-default must be derived from FALLBACK_COLOR'
        );
        self::assertSame(
            $this->palette->darkTokens($fallback),
            $this->tokensInRule($region, '.dark .pay-default', 'default'),
            '.dark .pay-default must be derived from FALLBACK_COLOR'
        );
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * The brands plus the unbranded fallback, which is what the generated block
     * actually contains.
     *
     * @return array<string, string> key => base colour
     */
    private function brandColors(): array
    {
        $colors = [];
        foreach (PaymentBrand::BRANDS as $key => $brand) {
            $colors[$key] = $brand['color'];
        }
        $colors['default'] = PaymentBrand::FALLBACK_COLOR;

        return $colors;
    }

    /**
     * Token map of one rule. The negative lookbehind keeps `.pay-bkash` from
     * matching the dark rule — the same trick PaymentBrandTest uses, because
     * the dark rules are `selector { … }` blocks in their own right.
     *
     * @return array<string, string>
     */
    private function tokensInRule(string $css, string $selector, string $key): array
    {
        $pattern = '/(?<!\.dark )' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
        if (preg_match($pattern, $css, $m) !== 1) {
            self::fail(sprintf('No rule for %s in the generated region', $selector));
        }

        preg_match_all('/(--pay[a-z-]*)\s*:\s*(#[0-9a-fA-F]{6})/', $m[1], $pairs, PREG_SET_ORDER);

        $tokens = [];
        foreach ($pairs as $pair) {
            $tokens[$pair[1]] = strtolower($pair[2]);
        }

        self::assertSame(
            PaymentBrandPalette::TOKENS,
            array_keys($tokens),
            sprintf('%s must define exactly the five tokens, in order', $selector)
        );

        return $tokens;
    }

    /**
     * Hue in degrees, or null when the colour is achromatic (pure grey, or
     * white — `--pay-on` on most brands).
     */
    private static function hue(string $hex): ?float
    {
        $hex = ltrim(strtolower($hex), '#');
        [$r, $g, $b] = array_map(static fn(string $c): float => hexdec($c) / 255, str_split($hex, 2));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        if ($max - $min < 0.0001) {
            return null;
        }

        $hue = match (true) {
            $max === $r => fmod(($g - $b) / ($max - $min), 6.0),
            $max === $g => ($b - $r) / ($max - $min) + 2.0,
            default => ($r - $g) / ($max - $min) + 4.0,
        };

        return fmod($hue * 60 + 360, 360);
    }

    /** Shortest distance between two hues, since 350 and 10 are close. */
    private static function angleBetween(float $a, float $b): float
    {
        $delta = abs($a - $b) % 360;

        return $delta > 180 ? 360 - $delta : $delta;
    }
}