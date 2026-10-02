<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CategoryAccent;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The accent palette has a footgun that no PHP test can see on its own.
 *
 * The class names are assembled at runtime — `accent-{{ key }}` in Twig,
 * `'accent-' + x` in Alpine — so Tailwind's content scanner never finds them in
 * a template. Any rule declared inside `@layer` is therefore purged, and the
 * result is not an error: the stylesheet still builds, the page still renders,
 * and every card quietly falls back to the `:root` emerald. That is exactly how
 * eleven of twelve palette rules once disappeared.
 *
 * So this test asserts against the *built* stylesheet, not the source, and it
 * pins the two things that keep silently regressing:
 *
 *   1. every key in CategoryAccent::PALETTE has a rule defining all five tokens
 *      (and the dark table has the same coverage);
 *   2. those rules are not inside `@layer`, in the source or the build output.
 */
final class AccentPaletteCssTest extends Unit
{
    /** The tokens each `.accent-*` rule must define. */
    private const TOKENS = ['--accent', '--accent-soft', '--accent-line', '--accent-ink', '--accent-on'];

    private const SOURCE = __DIR__ . '/../../resources/css/app.css';
    private const BUILT = __DIR__ . '/../../public/assets/css/app.css';

    public function testEveryPaletteKeyHasLightRuleInSource(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        self::assertNotEmpty($css, 'resources/css/app.css is empty or unreadable');

        foreach (CategoryAccent::PALETTE as $key) {
            self::assertRuleDefinesTokens($css, self::lightSelector($key), $key, 'light');
        }
    }

    public function testEveryPaletteKeyHasDarkRuleInSource(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        self::assertNotEmpty($css, 'resources/css/app.css is empty or unreadable');

        foreach (CategoryAccent::PALETTE as $key) {
            self::assertRuleDefinesTokens($css, self::darkSelector($key), $key, 'dark');
        }
    }

    public function testPaletteRulesAreNotInsideALayer(): void
    {
        $css = (string) file_get_contents(self::SOURCE);

        // A rule counts as layered when it sits between `@layer ... {` and the
        // matching `}`. Both are top-level here, so a cheap but sufficient check
        // is that no `@layer` block swallows any accent selector.
        //
        // Only palette keys are checked: `.accent-tile`, `.accent-label` and the
        // other component classes live in @layer on purpose — they are ordinary
        // static selectors that Tailwind's scanner does find in the templates.
        $paletteSelector = self::paletteSelectorRegex();
        $offset = 0;
        while (preg_match('/@layer\b[^{]*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($css, '}', $start);
            self::assertNotSame(false, $end, 'Unclosed @layer block in app.css');

            $body = substr($css, $start, (int) $end - $start);
            assertDoesNotMatchRegularExpression(
                '/' . $paletteSelector . '\s*\{/',
                $body,
                'A palette rule is declared inside an @layer block. Tailwind cannot '
                . 'see runtime-built class names, so a layered rule is purged from the '
                . 'build. Move the palette out of @layer — see the comment above it.'
            );

            $offset = (int) $end + 1;
        }
    }

    /**
     * The assertion that actually guards the build artefact.
     *
     * Skipped, not failed, when `npm run build` has not run: `public/assets` is
     * gitignored, so a fresh clone legitimately has no stylesheet to inspect.
     */
    public function testEveryPaletteKeySurvivesTheTailwindBuild(): void
    {
        if (!is_file(self::BUILT)) {
            self::markTestSkipped(
                'Built stylesheet is missing (public/assets is gitignored). '
                . 'Run `npm run build` to check the palette against real output.'
            );
        }

        $css = (string) file_get_contents(self::BUILT);
        self::assertNotEmpty($css, 'public/assets/css/app.css is empty');

        foreach (CategoryAccent::PALETTE as $key) {
            self::assertRuleDefinesTokens($css, self::lightSelector($key), $key, 'built light');
        }
        foreach (CategoryAccent::PALETTE as $key) {
            self::assertRuleDefinesTokens($css, self::darkSelector($key), $key, 'built dark');
        }

        assertDoesNotMatchRegularExpression(
            '/@layer[^{]*\{[^}]*' . self::paletteSelectorRegex() . '\s*\{/',
            $css,
            'The built stylesheet still contains a layered palette rule, which means '
            . 'the accent tokens are being purged at build time.'
        );
    }

    /**
     * Selector for a palette key's light rule.
     *
     * The negative lookbehind is load-bearing: without it the pattern also
     * matches the `.dark .accent-amber { … }` rule that follows in the same
     * file, so deleting a light rule would still find a "match" and the test
     * would pass — verified by actually deleting a rule and watching the suite
     * stay green. Line-start anchoring is not an option instead, because the
     * minified build puts every rule on one line.
     */
    private static function lightSelector(string $key): string
    {
        return '(?<!\.dark )\.accent-' . $key . '\b';
    }

    /**
     * Selector for a palette key's dark rule. Deliberately built on its own
     * rather than by prefixing `lightSelector()` — the lookbehind has to sit
     * immediately before the class, and prefixing would push it after the
     * `.dark ` that makes the rule matchable in the first place.
     */
    private static function darkSelector(string $key): string
    {
        return '\.dark \.accent-' . $key . '\b';
    }

    /**
     * Alternation matching exactly the palette keys, e.g. `\.accent-(emerald|sky)`.
     *
     * Anchored on the key list so the component classes (`.accent-tile`,
     * `.accent-label`, …) cannot be mistaken for palette entries.
     */
    private static function paletteSelectorRegex(): string
    {
        return '\.accent-(?:' . implode('|', CategoryAccent::PALETTE) . ')\b';
    }

    /**
     * A palette key with no CSS rule is the exact failure this guards, so the
     * test also fails when the PHP and CSS tables have drifted apart in size.
     */
    public function testCssDeclaresNoUnknownAccentKeys(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        self::assertTrue(
            preg_match_all('/^\.accent-([a-z]+)\s*\{/m', $css, $m) > 0,
            'No top-level .accent-* rules found in app.css — the palette block moved or was deleted.'
        );

        $declared = array_values(array_unique($m[1]));
        $unknown = array_diff($declared, CategoryAccent::PALETTE);

        assertTrue(
            $unknown === [],
            'CSS defines accent key(s) with no matching palette entry: ' . implode(', ', $unknown)
        );
    }

    /**
     * @param string $css Stylesheet source or built output.
     * @param string $selector Regex matching the selector under test, *without*
     *                         a trailing brace — this method adds `\s*\{` itself.
     */
    private static function assertRuleDefinesTokens(string $css, string $selector, string $key, string $context): void
    {
        $matched = preg_match('/' . $selector . '\s*\{([^}]*)\}/', $css, $m) === 1;
        assertTrue(
            $matched,
            "No CSS rule matches `$selector` — palette key \"$key\" has no $context rule. "
            . 'Add it next to the other entries in the accent palette block in resources/css/app.css.'
        );

        foreach (self::TOKENS as $token) {
            assertMatchesRegularExpression(
                '/(^|[\s;])' . preg_quote($token, '/') . '\s*:/',
                $m[1],
                "The $context rule for \"$key\" does not define $token. A missing token means "
                . 'a component falls back to the :root default and loses its accent.'
            );
        }
    }
}
