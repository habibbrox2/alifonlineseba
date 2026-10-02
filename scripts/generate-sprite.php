<?php

declare(strict_types=1);

/**
 * Build-time script: generates public/assets/icons/lucide-sprite.svg and
 * public/assets/icons/lucide-index.json from the `lucide-static` npm package
 * (dev-time only; both outputs are gitignored build artefacts served statically).
 *
 * Usage:
 *   php scripts/generate-sprite.php                 every icon the package ships
 *   php scripts/generate-sprite.php home grid user  just those names (fast check)
 *
 * Why the whole library instead of a hand-picked set:
 *
 * The admin category/service forms let a human pick ANY icon out of 1636, and
 * that name is then rendered on the public storefront through
 * TwigExtension::icon(), which draws a `<use>` against this sprite. A curated
 * sprite would therefore mean a perfectly valid pick renders as a blank box in
 * production the moment the admin chose something outside the list — the exact
 * failure resolveIcon()'s "info" fallback exists to paper over. Shipping
 * everything removes that whole class of bug.
 *
 * It is affordable: 1865 symbols compress to ~80 KB over the wire, against ~3 KB
 * for a 69-icon set. That is one extra cached file, not a per-page cost.
 *
 * Two files, two audiences:
 *   - the sprite is loaded by <use> on every page and holds canonical icons
 *     *and* the deprecated aliases (activity-square, alert-circle, ...). Nine
 *     aliases are referenced by the existing templates, so dropping them would
 *     break pages that are working today.
 *   - the index is fetched lazily by the admin icon picker only, and holds just
 *     the 1636 canonical names with their search tags. Offering 229 synonyms in
 *     a grid would just be three ways to pick the same drawing.
 */

$packageDir = __DIR__ . '/../node_modules/lucide-static';
$iconDir = $packageDir . '/icons';
$outFile = __DIR__ . '/../public/assets/icons/lucide-sprite.svg';
$indexFile = __DIR__ . '/../public/assets/icons/lucide-index.json';

if (!is_dir($iconDir)) {
    fwrite(STDERR, "lucide-static is not installed. Run: npm i -D lucide-static\n");
    exit(1);
}

/**
 * Icons the site chrome itself draws, recorded rather than merely generated.
 *
 * This list no longer limits what the sprite contains — it is the set that
 * *must* survive a lucide major upgrade, because a rename here breaks a
 * template and resolveIcon() will quietly substitute "info". Kept as a build
 * time warning so a dropped upstream name is a build error the developer reads,
 * not a subtly wrong icon nobody notices until release.
 */
const CORE_ICONS = [
    'menu', 'x', 'chevron-down', 'chevron-left', 'chevron-right', 'chevrons-up-down',
    'arrow-right', 'arrow-left', 'arrow-up', 'arrow-down', 'home', 'layers', 'layout-grid', 'grid', 'user', 'users',
    'user-circle', 'user-plus', 'bell', 'bell-off', 'wallet', 'receipt', 'shield',
    'shield-check', 'log-in', 'log-out', 'search', 'search-check', 'search-x',
    'credit-card', 'file-text', 'file-check', 'zap', 'send', 'sparkles', 'headphones',
    'check', 'check-circle', 'check-circle-2', 'check-check', 'alert-circle',
    'alert-triangle', 'info', 'inbox', 'history', 'activity', 'key-round', 'save',
    'trending-up', 'gauge', 'scroll-text', 'plus', 'filter', 'eye', 'eye-off', 'smartphone',
    'settings', 'globe', 'facebook', 'youtube', 'message-circle',
    // Referral programme: gift for the nav badge, copy/link for sharing a code,
    // share-2 for the share row, rotate-ccw for "put a rejected one back".
    'gift', 'copy', 'link', 'share-2', 'rotate-ccw', 'x-circle', 'handshake', 'badge-check',
];

/**
 * Canonical icon names, i.e. the ones the picker offers.
 *
 * Read from tags.json rather than guessed from the icons/ directory: the
 * directory also holds 229 deprecated alias files, and Lucide's own metadata is
 * the only thing that knows which is which.
 *
 * @return array<string, list<string>> name => search tags
 */
$canonical = static function () use ($packageDir): array {
    $file = $packageDir . '/tags.json';
    if (!is_file($file)) {
        fwrite(STDERR, "lucide-static/tags.json missing — cannot tell aliases from canonical icons.\n");
        exit(1);
    }

    $tags = json_decode((string) file_get_contents($file), true);
    if (!is_array($tags)) {
        fwrite(STDERR, "lucide-static/tags.json is not valid JSON.\n");
        exit(1);
    }

    $out = [];
    foreach ($tags as $name => $list) {
        $out[(string) $name] = array_values(array_filter(
            array_map('strval', is_array($list) ? $list : []),
            static fn(string $t): bool => $t !== ''
        ));
    }
    ksort($out);

    return $out;
};

$explicit = array_slice($argv, 1);
$canonicalNames = $canonical();

if ($explicit !== []) {
    $names = $explicit;
} else {
    $files = glob($iconDir . '/*.svg') ?: [];
    $names = array_map(static fn(string $f): string => basename($f, '.svg'), $files);
    sort($names);
}

$symbols = [];
$missing = [];
$geometry = [];

foreach ($names as $name) {
    $file = $iconDir . '/' . $name . '.svg';
    if (!is_file($file)) {
        $missing[] = $name;
        continue;
    }
    $svg = file_get_contents($file);
    // Extract inner content and normalize attributes
    if (preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $svg, $m)) {
        $inner = trim($m[1]);
        // A deprecated alias ships the renamed icon's drawing verbatim, so the
        // markup inside <svg> is byte-identical to its canonical twin. That is
        // the only signal linking "grid" to "grid-3x3" — Lucide ships no alias
        // metadata — and it is what lets the picker highlight a stored alias.
        $geometry[$name] = md5($inner);
        $symbols[] = <<<SVG
  <symbol id="{$name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    {$inner}
  </symbol>
SVG;
    }
}

$sprite = "<svg xmlns=\"http://www.w3.org/2000/svg\" style=\"display:none\">\n" . implode("\n", $symbols) . "\n</svg>\n";

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0775, true);
}
file_put_contents($outFile, $sprite);

echo 'Sprite written with ' . count($symbols) . " icons.\n";
if ($missing) {
    echo "Missing icons (skipped): " . implode(', ', $missing) . "\n";
}

// The index is only meaningful for a full build; a targeted rebuild of a few
// names would otherwise write an index that disagrees with the sprite.
if ($explicit === []) {
    // The alias list is shipped alongside the canonical names so the browser
    // can tell a typo from a valid deprecated name. The picker only *offers*
    // canonical icons, but the forms accept free text and nine existing
    // templates use aliases, so the client needs the full valid set to warn
    // correctly. Without this, typing "alert-circle" (a real, rendering alias)
    // would be flagged as an unknown icon.
    $aliases = array_values(array_diff($names, array_keys($canonicalNames)));
    sort($aliases);

    // alias => canonical. Group the canonical icons by geometry first, so an
    // alias resolves in one lookup instead of 1636 string comparisons. Aliases
    // whose geometry matches zero *or more than one* canonical icon are left
    // out: unmapped is safe (the picker just shows no highlight), whereas
    // guessing would highlight the wrong drawing and quietly rename the admin's
    // icon the moment they clicked something.
    $byGeometry = [];
    foreach (array_keys($canonicalNames) as $canonicalName) {
        if (isset($geometry[$canonicalName])) {
            $byGeometry[$geometry[$canonicalName]][] = $canonicalName;
        }
    }
    $aliasOf = [];
    $ambiguous = [];
    foreach ($aliases as $alias) {
        $matches = $byGeometry[$geometry[$alias]] ?? [];
        if (count($matches) === 1) {
            $aliasOf[$alias] = $matches[0];
        } elseif (count($matches) > 1) {
            $ambiguous[] = $alias;
        }
    }
    ksort($aliasOf);

    $index = json_encode(
        ['version' => 1, 'icons' => $canonicalNames, 'aliases' => $aliases, 'aliasOf' => $aliasOf],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    file_put_contents($indexFile, (string) $index);
    echo 'Index written with ' . count($canonicalNames) . " searchable icons + "
        . count($aliases) . " aliases (" . count($aliasOf) . " mapped to a canonical icon).\n";
    if ($ambiguous !== []) {
        echo 'Ambiguous aliases (identical geometry, left unmapped): '
            . implode(', ', $ambiguous) . "\n";
    }
} else {
    echo "Targeted build — index left untouched.\n";
}

// A core icon that upstream has renamed is a silently broken template, so make
// it loud here rather than letting resolveIcon() swap in "info" at runtime.
$dropped = array_values(array_filter(
    CORE_ICONS,
    static fn(string $n): bool => !in_array($n, $names, true)
));
if ($dropped !== []) {
    fwrite(STDERR, "WARNING: core icons no longer in lucide: " . implode(', ', $dropped) . "\n");
    exit(2);
}
