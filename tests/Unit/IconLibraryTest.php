<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\IconLibrary;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Guards the Lucide icon library and the picker built on top of it.
 *
 * The sprite and the JSON index are build artefacts, so the two halves of this
 * feature can drift apart in ways nothing else notices:
 *
 *   1. the index is what the picker *offers* and the sprite is what it can
 *      *draw*. A name offered but not drawable is the worst possible failure —
 *      the admin picks it, it is saved, and the storefront renders a blank box
 *      with no error in any log;
 *   2. every literal `icon('…')` in a template has to survive a lucide
 *      dependency bump, which is the only way an existing page can lose its
 *      glyph (TwigExtension::icon() falls back to `info`, so it degrades
 *      silently to the wrong icon);
 *   3. the picker's class names are applied at runtime, so `@layer` would purge
 *      them — the same trap {@see AccentPaletteCssTest} documents for accents.
 *
 * Artefact-dependent assertions skip rather than fail: `public/assets` is
 * gitignored, and a checkout that never ran `npm run build` is legitimate.
 */
final class IconLibraryTest extends Unit
{
    private const SOURCE = __DIR__ . '/../../resources/css/app.css';
    private const BUILT = __DIR__ . '/../../public/assets/css/app.css';
    private const VIEWS = __DIR__ . '/../../resources/views';
    private const PICKER = __DIR__ . '/../../resources/views/components/icon-picker.twig';

    /** Classes the picker needs from the stylesheet; none appear in a template as literals. */
    private const PICKER_CLASSES = [
        '.icon-picker',
        '.icon-picker-field',
        '.icon-picker-preview',
        '.icon-picker-preview-glyph',
        '.icon-picker-toggle',
        '.icon-picker-input--invalid',
        '.icon-picker-warning',
        '.icon-picker-panel',
        '.icon-picker-search',
        '.icon-picker-search-glyph',
        '.icon-picker-search-input',
        '.icon-picker-status',
        '.icon-picker-status--error',
        '.icon-picker-grid',
        '.icon-tile',
        '.icon-tile-glyph',
        '.icon-picker-footer',
        '.icon-picker-count',
        '.icon-picker-count-rest',
        '.icon-picker-more',
    ];

    private IconLibrary $icons;

    protected function _before(): void
    {
        $this->icons = new IconLibrary();
        if (!$this->icons->isBuilt()) {
            self::markTestSkipped(
                'Lucide sprite/index are missing (public/assets is gitignored). '
                . 'Run `npm run build` to check the icon library against real output.'
            );
        }
    }

    // ─── Index ⇄ sprite ────────────────────────────────────────────────

    /**
     * Canonical icons and deprecated aliases must tile the sprite exactly.
     *
     * Two failures hide here. A name in both lists ships twice and offers the
     * admin a duplicate; a name in neither means the picker would reject a
     * perfectly good name typed by hand as a typo. Checking the union covers
     * both directions in one assertion.
     */
    public function testIndexAndAliasesPartitionTheSprite(): void
    {
        $index = $this->icons->names();
        $aliases = $this->icons->aliases();
        $sprite = $this->icons->spriteNames();

        assertGreaterThan(1000, count($index), 'The index should hold the whole Lucide library, not a curated subset.');
        assertGreaterThan(0, count($aliases), 'Lucide ships deprecated aliases; none were found in the index.');
        assertSame(count($sprite), count($index) + count($aliases), 'sprite count !== index + alias count.');

        $both = array_values(array_intersect($index, $aliases));
        assertSame([], $both, 'Name(s) in both the index and the alias list: ' . implode(', ', $both));

        $union = array_unique(array_merge($index, $aliases));
        sort($union);
        $expected = $sprite;
        sort($expected);
        assertSame($expected, array_values($union), 'Index + aliases do not match the sprite exactly.');
    }

    /** The picker offers a name the sprite cannot draw → blank box in production. */
    public function testEveryIndexEntryIsInTheSprite(): void
    {
        assertSame([], $this->icons->missingFromSprite(), 'An icon offered by the picker has no <symbol> to draw.');
    }

    /**
     * Every deprecated alias resolves to a canonical icon the picker can show.
     *
     * Tiles are titled with canonical names only, so the alias map is the sole
     * reason a category saved as `grid` (Lucide renamed it `grid-3x3`) opens
     * with its tile highlighted instead of looking empty. Two failure modes:
     * an unmapped alias leaves a stored legacy value invisible, and a target
     * that is not itself an index entry points the highlight at a tile that
     * does not exist.
     */
    public function testEveryAliasMapsToACanonicalIndexEntry(): void
    {
        $aliases = $this->icons->aliases();
        $map = $this->icons->aliasMap();
        $index = array_fill_keys($this->icons->names(), true);

        if ($aliases === []) {
            $this->markTestSkipped('Lucide icon build artefacts are absent; nothing to map.');
        }

        assertSame([], array_diff(array_keys($map), $aliases), 'aliasMap() has keys that are not real aliases.');
        assertSame([], array_diff($map, $this->icons->names()), 'aliasMap() points at a name the picker never offers.');

        $unmapped = array_values(array_diff($aliases, array_keys($map)));
        assertSame([], $unmapped, 'Alias(es) with no canonical target, so a stored value would not highlight: ' . implode(', ', $unmapped));
    }

    /**
     * The nine aliases the existing templates call by name must stay usable.
     *
     * generate-sprite.php already fails the build if one drops out of the
     * sprite. This asserts the weaker half: they resolve, so an admin editing
     * such a row sees a highlighted tile instead of a blank picker.
     */
    public function testTemplateAliasesResolveToCanonicalIcons(): void
    {
        $map = $this->icons->aliasMap();
        if ($map === []) {
            $this->markTestSkipped('Lucide icon build artefacts are absent; nothing to map.');
        }

        foreach (['home', 'grid', 'user-circle', 'check-circle', 'check-circle-2', 'alert-circle', 'alert-triangle', 'filter', 'x-circle'] as $alias) {
            assertTrue(
                isset($map[$alias]),
                sprintf('Template-used alias "%s" has no canonical target.', $alias)
            );
            assertTrue(
                in_array($map[$alias], $this->icons->names(), true),
                sprintf('Alias "%s" maps to "%s", which is not an icon the picker offers.', $alias, $map[$alias])
            );
        }
    }

    /**
     * Search tags come from lucide-static's tags.json.
     *
     * Not cosmetic: the placeholder invites someone to search by meaning
     * ("money"), and without tags those searches only work if the word happens
     * to be in the name.
     */
    public function testEveryIndexEntryHasSearchTags(): void
    {
        assertSame([], $this->icons->untagged(), 'Index entries with no search tags — check tags.json still parses.');
    }

    // ─── Templates ⇄ sprite ─────────────────────────────────────────────

    /**
     * Every icon name hard-coded in a template must be drawable.
     *
     * A lucide upgrade can drop or rename an icon, and `icon()` answers an
     * unknown name with `info` rather than throwing — so a rename shows up as a
     * generic icon in a place the design cared about, with no error anywhere.
     * Both quote styles are scanned, and only the first argument is read: the
     * second one is a Tailwind class string.
     */
    public function testEveryTemplateIconNameIsInTheSprite(): void
    {
        $literals = self::templateIconLiterals();
        assertTrue($literals !== [], 'The scan found no icon() calls at all — the extractor is broken.');

        $sprite = array_fill_keys($this->icons->spriteNames(), true);
        $missing = [];

        foreach ($literals as $name => $file) {
            if (!isset($sprite[$name])) {
                $missing[] = "$name ($file)";
            }
        }

        assertSame([], $missing, 'Template(s) reference icon names with no <symbol> in the sprite.');
    }

    /** Scans resources/views for the first argument of every `icon(...)` call. */
    private static function templateIconLiterals(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $path = $file->getPathname();
            $twig = (string) file_get_contents($path);

            // `\bicon\(` rather than `icon(` so iconPicker( and icon_index_url(
            // are not mistaken for the Twig filter.
            if (preg_match_all('/\bicon\(/', $twig, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($m[0] as [$hit, $at]) {
                $args = self::callArguments($twig, (int) $at + strlen($hit));
                if ($args === null || $args === '') {
                    continue;
                }
                $first = self::firstArgument($args);
                if (preg_match_all("/'([a-z0-9][a-z0-9-]*)'/i", $first, $names) > 0) {
                    foreach ($names[1] as $name) {
                        $out[$name] ??= basename($path);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The balanced text between `(` at `$open` and its matching `)`.
     *
     * Returns null on an unbalanced call, which is a template bug the extractor
     * cannot recover from — better to read nothing than to read to EOF and
     * report every icon in the file as used by the broken line.
     */
    private static function callArguments(string $source, int $open): ?string
    {
        $depth = 1;
        $len = strlen($source);
        for ($i = $open; $i < $len; $i++) {
            $c = $source[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    return substr($source, $open, $i - $open);
                }
            }
        }

        return null;
    }

    /** The first comma-separated argument, ignoring commas inside quotes or parens. */
    private static function firstArgument(string $args): string
    {
        $depth = 0;
        $quote = null;
        $len = strlen($args);
        for ($i = 0; $i < $len; $i++) {
            $c = $args[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                return substr($args, 0, $i);
            }
        }

        return $args;
    }

    // ─── Picker CSS ⇄ Tailwind purge ───────────────────────────────────

    /**
     * Every class the picker markup uses has a rule, and none of them sit
     * inside `@layer`.
     *
     * The component binds most of these with `x-bind:class` or writes them
     * from JS, so Tailwind's scanner never sees them in a template and any
     * layered rule is silently dropped from the build. A missing rule is not an
     * error — the picker just loses its borders and its selected-tile highlight
     * — which is why it has to be asserted against the stylesheet.
     */
    public function testPickerClassesAreDeclaredOutsideAnyLayer(): void
    {
        $css = (string) file_get_contents(self::SOURCE);
        self::assertNotEmpty($css, 'resources/css/app.css is empty or unreadable');

        $layerBodies = self::layerBodies($css);
        $insideLayers = implode("\n", $layerBodies);

        foreach (self::PICKER_CLASSES as $class) {
            // Negative lookahead rather than \b: `.icon-picker` and
            // `.icon-picker-field` differ by a hyphen, which is a word boundary,
            // so \b would let the wrapper class be "found" by a rule that is
            // actually styling something else.
            $selector = '/' . preg_quote($class, '/') . '(?![-\w])[^{]*\{/';

            assertMatchesRegularExpression(
                $selector,
                $css,
                "No CSS rule for `$class` — the picker markup references a class the stylesheet does not define."
            );
            assertDoesNotMatchRegularExpression(
                $selector,
                $insideLayers,
                "`$class` is declared inside an @layer block. Runtime class names are invisible to "
                . 'Tailwind\'s scanner, so the rule is purged from the build — move the picker CSS out of @layer.'
            );
        }
    }

    /** The `is-active` highlight only exists as a compound selector, so check it separately. */
    public function testSelectedTileHighlightSurvivesTheBuild(): void
    {
        if (!is_file(self::BUILT)) {
            self::markTestSkipped('Built stylesheet is missing (public/assets is gitignored). Run `npm run build`.');
        }

        $built = (string) file_get_contents(self::BUILT);
        assertMatchesRegularExpression(
            '/\.icon-tile\.is-active\s*\{[^}]*--ip-accent/',
            $built,
            'The selected-tile highlight is missing from the built stylesheet — is-active is applied by '
            . 'Alpine at runtime, so a layered or missing rule means no tile ever looks selected.'
        );
    }

    /** Bodies of every top-level `@layer … { … }` block. */
    private static function layerBodies(string $css): array
    {
        $bodies = [];
        $offset = 0;
        while (preg_match('/@layer\b[^{]*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($css, '}', $start);
            self::assertNotSame(false, $end, 'Unclosed @layer block in app.css');

            $bodies[] = substr($css, $start, (int) $end - $start);
            $offset = (int) $end + 1;
        }

        return $bodies;
    }

    /**
     * The component must degrade to a plain text box when the library is absent.
     *
     * The template hides the script tag and the panel behind `icon_index_url()`
     * being empty, which only works if the `<input>` carrying the real `name`
     * is outside every `x-if`.
     */
    public function testPickerInputIsNotBehindACondition(): void
    {
        $twig = (string) file_get_contents(self::PICKER);
        $inputAt = strpos($twig, 'name="{{ pickerName }}"');
        self::assertNotSame(false, $inputAt, 'The picker no longer renders a named input.');

        $guard = strpos($twig, '{% if');
        assertNotSame(false, $guard, 'The component has no build guard at all.');
        assertTrue(
            $guard < $inputAt,
            'The input renders inside a Twig conditional — if the sprite is missing the field would '
            . 'disappear and the form would submit no icon at all.'
        );

        assertMatchesRegularExpression(
            '/x-model="value"/',
            $twig,
            'The input is no longer bound to the picker value, so clicking a tile would not set the field.'
        );
    }
}
