<?php

declare(strict_types=1);

/**
 * Build-time script: generates public/assets/icons/lucide-sprite.svg from the
 * `lucide-static` npm package (dev-time only; output is committed/served statically).
 *
 * Usage: php scripts/generate-sprite.php [icon-name ...]
 * Without args it builds the default curated set used by the templates.
 */

$icons = ($argv[1] ?? null) !== null ? array_slice($argv, 1) : [
    'menu', 'x', 'chevron-down', 'chevron-left', 'chevron-right', 'chevrons-up-down',
    'arrow-right', 'arrow-left', 'arrow-up', 'arrow-down', 'home', 'layers', 'layout-grid', 'grid', 'user', 'users',
    'user-circle', 'user-plus', 'bell', 'bell-off', 'wallet', 'receipt', 'shield',
    'shield-check', 'log-in', 'log-out', 'search', 'search-check', 'search-x',
    'credit-card', 'file-text', 'file-check', 'zap', 'send', 'sparkles', 'headphones',
    'check', 'check-circle', 'check-circle-2', 'check-check', 'alert-circle',
    'alert-triangle', 'info', 'inbox', 'history', 'activity', 'key-round', 'save',
    'trending-up', 'gauge', 'scroll-text', 'plus', 'filter', 'eye', 'eye-off', 'smartphone',
    'settings', 'globe', 'facebook', 'youtube', 'message-circle',
];

$packageDir = __DIR__ . '/../node_modules/lucide-static/icons';
$outFile = __DIR__ . '/../public/assets/icons/lucide-sprite.svg';

if (!is_dir($packageDir)) {
    fwrite(STDERR, "lucide-static is not installed. Run: npm i -D lucide-static\n");
    exit(1);
}

$symbols = [];
$missing = [];

foreach ($icons as $name) {
    $file = "{$packageDir}/{$name}.svg";
    if (!is_file($file)) {
        $missing[] = $name;
        continue;
    }
    $svg = file_get_contents($file);
    // Extract inner content and normalize attributes
    if (preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $svg, $m)) {
        $inner = trim($m[1]);
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
