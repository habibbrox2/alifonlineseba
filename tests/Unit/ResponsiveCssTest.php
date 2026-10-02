<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\fail;

/**
 * The phone layout is carried by one small block in app.css plus the
 * `tap-target` / `tap-row` class sprinkled through the templates, and none of
 * it fails loudly when it disappears:
 *
 *   - `.tap-target` and `.tap-row` are referenced from Twig, so Tailwind's
 *     scanner does find them and they will build.
 *   - `.text-[11px]` is different. It is an *arbitrary value* generated from the
 *     templates, and the rule that floors it is a hand-written selector naming
 *     nothing Tailwind can scan for. Nothing references it, so moving the block
 *     inside `@layer components` would make the override lose to the generated
 *     utility in `@layer utilities` and silently put 11px metadata back on every
 *     phone — with a clean build and no error anywhere.
 *
 * That is the same footgun AccentPaletteCssTest guards for the accent palette,
 * so these assertions target the built stylesheet as well as the source.
 */
final class ResponsiveCssTest extends Unit
{
    /**
     * Selectors that must stay in the phone media query, and — checked against
     * the *built* stylesheet only — the declarations they must expand to. The
     * source spells `.tap-row` with `@apply`, which Tailwind has not run yet, so
     * `min-h-[2.5rem]` is still two tokens there and `min-height:2.5rem` is not.
     */
    private const MOBILE_SELECTORS = ['.tap-target', '.tap-row', '.text-\[11px\]'];

    private const BUILT_DECLARATIONS = [
        '.tap-target' => ['min-height:2.5rem', 'min-width:2.5rem', 'display:inline-flex'],
        '.tap-row' => ['min-height:2.5rem', 'display:flex'],
        '.text-\[11px\]' => ['font-size:0.75rem'],
    ];

    private const SOURCE = __DIR__ . '/../../resources/css/app.css';
    private const BUILT = __DIR__ . '/../../public/assets/css/app.css';

    public function testSourceDeclaresTapRulesInTheMobileBlock(): void
    {
        $block = $this->squash($this->mobileBlock(
            $this->read(self::SOURCE, 'resources/css/app.css'),
            'app.css'
        ));

        foreach (self::MOBILE_SELECTORS as $selector) {
            assertStringContainsString(
                $this->squash($selector) . '{',
                $block,
                sprintf('`%s` is missing from the mobile media query in app.css.', $selector)
            );
        }
    }

    /**
     * The regression that motivated this file: the floor only beats the
     * generated utility while it is unlayered.
     */
    public function testArbitrarySmallTextFloorIsNotInsideALayer(): void
    {
        // Comments are stripped first: the block documenting the palette talks
        // about "@layer" in prose, and a naive scan would pair that mention with
        // whatever brace came next.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::SOURCE, 'resources/css/app.css'));
        $needle = $this->squash('.text-\[11px\]');

        $offset = 0;
        while (preg_match('/@layer\b[^{]*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($css, '}', $start);
            assertNotFalse($end, 'Unclosed @layer block in app.css.');

            $body = substr($css, $start, $end - $start);
            assertDoesNotMatchRegularExpression(
                '/' . preg_quote($needle, '/') . '/',
                $body,
                'The .text-[11px] floor is declared inside an @layer block. Tailwind emits the '
                . 'generated utility into @layer utilities, and a layered rule of the same '
                . 'specificity always loses to it — 11px text would return to phones with no '
                . 'build error to explain it. Keep the rule unlayered.'
            );

            $offset = $end + 1;
        }
    }

    /**
     * Skipped, not failed, when `npm run build` has not run: public/assets is
     * gitignored, so a fresh clone legitimately has no stylesheet to inspect.
     */
    public function testMobileRulesSurviveTheTailwindBuild(): void
    {
        if (!is_file(self::BUILT)) {
            self::markTestSkipped(
                'Built stylesheet is missing (public/assets is gitignored). '
                . 'Run `npm run build` to check the mobile rules against real output.'
            );
        }

        $block = $this->squash($this->mobileBlock(
            $this->read(self::BUILT, 'public/assets/css/app.css'),
            'the built stylesheet'
        ));

        foreach (self::BUILT_DECLARATIONS as $selector => $declarations) {
            $at = strpos($block, $selector . '{');
            assertNotFalse(
                $at,
                sprintf('`%s` is missing from the mobile media query in the built stylesheet.', $selector)
            );

            $close = strpos($block, '}', $at);
            assertNotFalse($close, sprintf('Unclosed rule for `%s` in the built stylesheet.', $selector));

            $body = substr($block, $at + strlen($selector) + 1, $close - $at - strlen($selector) - 1);
            foreach ($declarations as $declaration) {
                assertStringContainsString(
                    $this->dropLeadingZeros($declaration),
                    $this->dropLeadingZeros($body),
                    sprintf('`%s` must expand to `%s` in the built stylesheet.', $selector, $declaration)
                );
            }
        }
    }

    private function read(string $path, string $label): string
    {
        $css = (string) file_get_contents($path);
        self::assertNotEmpty($css, sprintf('%s is empty or unreadable.', $label));

        return $css;
    }

    /**
     * Returns the body of the phone media query, brace-matched.
     *
     * The source writes the breakpoint as `(max-width: 639.98px)` with a space
     * and the minified build drops it, so the lookup tolerates both.
     */
    private function mobileBlock(string $css, string $label): string
    {
        $matched = preg_match(
            '/@media\s*\(max-width:\s*639\.98px\)\s*\{/',
            $css,
            $m,
            PREG_OFFSET_CAPTURE
        );
        self::assertSame(1, $matched, sprintf('No `max-width: 639.98px` media query in %s.', $label));

        $from = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        for ($i = $from, $length = strlen($css); $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return substr($css, $from, $i - $from);
            }
        }

        fail(sprintf('Unclosed media query in %s.', $label));
    }

    /**
     * Whitespace-insensitive, because the source and the minified build spell
     * the same declaration `min-height: 2.5rem` and `min-height:2.5rem`.
     */
    private function squash(string $css): string
    {
        return (string) preg_replace('/\s+/', '', $css);
    }

    /**
     * The minifier also drops leading zeros (`0.75rem` becomes `.75rem`), so the
     * two spellings are compared after normalisation instead of pinning the
     * build tool's exact output.
     */
    private function dropLeadingZeros(string $css): string
    {
        return (string) preg_replace('/(?<=[:;(])0+\./', '.', $css);
    }
}
