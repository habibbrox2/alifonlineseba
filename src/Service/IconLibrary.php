<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The Lucide icon set, as the admin icon picker sees it.
 *
 * The sprite (`public/assets/icons/lucide-sprite.svg`) and the search index
 * (`public/assets/icons/lucide-index.json`) are both build artefacts produced by
 * `scripts/generate-sprite.php`, and both are gitignored. So everything here has
 * to survive them being absent: an uninstalled checkout, a CLI command, a unit
 * test, or a deploy that shipped the code but ran no `npm run build`. An
 * unbuilt library returns empty and the picker degrades to a plain text box
 * rather than throwing — the field it replaces is a bare `<input>`, and that has
 * to keep working on its own.
 *
 * The two artefacts answer different questions and are deliberately not
 * collapsed into one:
 *
 *   sprite() — which names can be *drawn*. Canonical icons plus the 229
 *              deprecated aliases, because nine aliases are referenced by the
 *              existing templates.
 *   index()  — which names should be *offered*. The 1636 canonical ones with
 *              their search tags, so the grid does not show three synonyms for
 *              the same drawing and search works on meaning ("money" finding
 *              banknote) rather than on spelling alone.
 *
 * A name that appears in the index but not the sprite would render as a blank
 * box, which is why {@see missingFromSprite()} exists and is asserted on.
 */
final class IconLibrary
{
    private const SPRITE = '/assets/icons/lucide-sprite.svg';
    private const INDEX = '/assets/icons/lucide-index.json';

    /** @var array<string, true>|null */
    private ?array $spriteNames = null;

    /** @var array<string, list<string>>|null */
    private ?array $index = null;

    /**
     * Web path of the JSON index, relative to the document root.
     *
     * The `public/` prefix is a *filesystem* fact, not a URL one — the document
     * root is public/, which is why this is "/assets/..." and not
     * "/public/assets/...". TwigExtension::asset() maps the two and appends a
     * `?v=` cache buster, so this is the value to hand it, not a raw URL.
     */
    public function indexUrl(): string
    {
        return self::INDEX;
    }

    public function indexPath(): string
    {
        return $this->root() . '/public' . self::INDEX;
    }

    public function spritePath(): string
    {
        return $this->root() . '/public' . self::SPRITE;
    }

    /**
     * Every name the sprite can draw, sorted.
     *
     * @return list<string>
     */
    public function spriteNames(): array
    {
        if ($this->spriteNames === null) {
            $this->spriteNames = [];
            $sprite = $this->spritePath();
            if (is_file($sprite)) {
                $svg = (string) file_get_contents($sprite);
                // \sid= rather than <symbol id= so a stray `id` on a path inside
                // a symbol cannot inflate the list.
                if (preg_match_all('/<symbol\s+id="([A-Za-z0-9_-]+)"/', $svg, $m) > 0) {
                    $names = array_values(array_unique($m[1]));
                    sort($names);
                    $this->spriteNames = array_fill_keys($names, true);
                }
            }
        }

        $names = array_keys($this->spriteNames);
        sort($names);

        return $names;
    }

    public function isBuilt(): bool
    {
        return $this->spriteNames() !== [] && $this->index() !== [];
    }

    /**
     * Canonical names offered by the picker, sorted, with search tags.
     *
     * @return array<string, list<string>>
     */
    public function index(): array
    {
        if ($this->index === null) {
            $this->index = [];
            $file = $this->indexPath();
            if (is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);
                $icons = is_array($decoded) ? ($decoded['icons'] ?? null) : null;
                if (is_array($icons)) {
                    // The file is written by generate-sprite.php as a name=>tags
                    // map, but json_decode of an object gives an array whose keys
                    // are the names; guard anyway so a hand-edited file degrades
                    // to "no search" instead of a fatal on a list shape.
                    foreach ($icons as $name => $tags) {
                        if (is_int($name) && is_array($tags) && isset($tags['n'])) {
                            $name = (string) $tags['n'];
                            $tags = $tags['t'] ?? [];
                        }
                        if (!is_string($name) || $name === '') {
                            continue;
                        }
                        $this->index[$name] = array_values(array_filter(
                            array_map('strval', is_array($tags) ? $tags : []),
                            static fn(string $t): bool => $t !== ''
                        ));
                    }
                    ksort($this->index);
                }
            }
        }

        return $this->index;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->index());
    }

    /**
     * Deprecated Lucide aliases present in the sprite, e.g. `alert-circle`.
     *
     * Not offered by the picker — three synonyms for one drawing is clutter —
     * but shipped in the index so the browser can tell a typo from a valid
     * legacy name when an admin types one by hand.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return $this->aliases ??= $this->readAliases();
    }

    /** @var list<string>|null */
    private ?array $aliases = null;

    /**
     * @return list<string>
     */
    private function readAliases(): array
    {
        $file = $this->indexPath();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        $aliases = is_array($decoded) ? ($decoded['aliases'] ?? null) : null;
        if (!is_array($aliases)) {
            return [];
        }

        $out = array_values(array_filter(
            array_map('strval', $aliases),
            static fn(string $a): bool => $a !== ''
        ));
        sort($out);

        return $out;
    }

    /**
     * Deprecated alias => the canonical icon it renames, e.g. `grid` => `grid-3x3`.
     *
     * Only aliases with exactly one geometrically identical canonical icon are
     * mapped. The picker uses this so a stored legacy value still highlights the
     * tile it draws as — the tile title is the canonical name, so without this a
     * category saved as `grid` would open the picker with nothing selected and
     * look broken.
     *
     * @return array<string, string>
     */
    public function aliasMap(): array
    {
        if ($this->aliasMap === null) {
            $this->aliasMap = [];
            $file = $this->indexPath();
            if (is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);
                $map = is_array($decoded) ? ($decoded['aliasOf'] ?? null) : null;
                if (is_array($map)) {
                    foreach ($map as $alias => $canonical) {
                        if (is_string($alias) && is_string($canonical) && $alias !== '' && $canonical !== '') {
                            $this->aliasMap[$alias] = $canonical;
                        }
                    }
                    ksort($this->aliasMap);
                }
            }
        }

        return $this->aliasMap;
    }

    /** @var array<string, string>|null */
    private ?array $aliasMap = null;

    /**
     * Index entries with no matching `<symbol>` in the sprite.
     *
     * Empty in a healthy build. A non-empty result is the one way this feature
     * can ship a bug: the picker would happily offer a name that renders blank
     * on the storefront, with no error anywhere in the logs.
     *
     * @return list<string>
     */
    public function missingFromSprite(): array
    {
        $sprite = array_fill_keys($this->spriteNames(), true);

        return array_values(array_filter(
            $this->names(),
            static fn(string $n): bool => !isset($sprite[$n])
        ));
    }

    /**
     * Index entries with no search tags.
     *
     * Not an error — search still matches the name — but a signal that a new
     * upstream icon shipped without keywords, which quietly makes it harder to
     * find than its neighbours.
     *
     * @return list<string>
     */
    public function untagged(): array
    {
        return array_values(array_filter(
            $this->names(),
            fn(string $n): bool => ($this->index()[$n] ?? []) === []
        ));
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
