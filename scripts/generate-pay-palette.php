<?php

declare(strict_types=1);

/**
 * Build-time script: writes the payment brand palette in resources/css/app.css
 * from App\Service\PaymentBrand::BRANDS, so a brand colour is stored exactly
 * once — in PHP.
 *
 * Usage:
 *   php scripts/generate-pay-palette.php           rewrite the generated region
 *   php scripts/generate-pay-palette.php --check   fail if the file is stale
 *
 * Wired into `npm run build` (before Tailwind, which then compiles the fresh
 * tokens into public/assets/css/app.css) and into `npm run watch`, and
 * available as `composer palette` / `composer palette:check`.
 *
 * Why a script and not just a constant with the colours written out by hand:
 * the palette is five custom properties per brand per theme, and all of them
 * except the base colour are just the brand hue at a fixed lightness. Written
 * by hand they are twenty hex values with no relationship to
 * PaymentBrand::BRANDS, so a recolour lands on one side only and the button
 * next to it is still last season's pink — silently, because the page renders
 * perfectly either way. PaymentBrandPaletteTest fails on the mismatch, and
 * `--check` fails without touching the working tree, which is what CI wants.
 *
 * Only the region between the two markers is touched. The prose above it — why
 * the palette is unlayered, why the dark table exists — is the part a human has
 * to keep writing, and a generator that rewrote the whole file would take it.
 *
 * No app bootstrap: the palette is pure presentation and holds no state, so it
 * only needs Composer's autoloader. That keeps this runnable without a database
 * or an environment file, which is what lets it sit in front of the CSS build.
 */

use App\Service\PaymentBrand;
use App\Service\PaymentBrandPalette;

require __DIR__ . '/../vendor/autoload.php';

$check = in_array('--check', array_slice($argv, 1), true);

$file = __DIR__ . '/../resources/css/app.css';
$css = is_file($file) ? (string) file_get_contents($file) : '';

if ($css === '') {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}

$begin = strpos($css, PaymentBrandPalette::MARKER_BEGIN);
$end = strpos($css, PaymentBrandPalette::MARKER_END);

if ($begin === false || $end === false || $end < $begin) {
    fwrite(
        STDERR,
        "The payment palette markers are missing from resources/css/app.css.\n"
        . 'Expected, in this order:' . "\n"
        . '  ' . PaymentBrandPalette::MARKER_BEGIN . "\n"
        . '  ...generated rules...' . "\n"
        . '  ' . PaymentBrandPalette::MARKER_END . "\n"
    );
    exit(1);
}

$end += strlen(PaymentBrandPalette::MARKER_END);

// The end marker's own line terminator belongs to the region: generatedRegion()
// ends with a newline, so keeping the file's too would append a blank line on
// every run. A generator that grows the file each time it runs is a generator
// whose --check fails on a tree it produced itself.
if (($css[$end] ?? '') === "\n") {
    $end++;
}

$palette = new PaymentBrandPalette();
$generated = $palette->generatedRegion();

// Everything up to the marker line, and the newline that ends it, stays; so
// does everything from the newline after the end marker. Replacing the whole
// span including both marker lines is what makes the region re-generatable —
// a hand-edit inside it is overwritten on the next build, which is the deal.
$updated = substr($css, 0, $begin) . $generated . substr($css, $end);

if ($updated === $css) {
    echo "Payment palette is up to date ("
        . count(PaymentBrand::BRANDS) . ' brands + default, light and dark).' . "\n";
    exit(0);
}

if ($check) {
    fwrite(
        STDERR,
        "resources/css/app.css holds a payment palette that no longer matches "
        . "PaymentBrand::BRANDS.\nRun: php scripts/generate-pay-palette.php\n\n"
    );
    fwrite(STDERR, diffLines($css, $updated));
    exit(1);
}

if (file_put_contents($file, $updated) === false) {
    fwrite(STDERR, "Failed to write $file\n");
    exit(1);
}

echo 'Payment palette regenerated from PaymentBrand::BRANDS (' . count(PaymentBrand::BRANDS) . " brands + default).\n";

/**
 * Minimal line diff, so `--check` shows what actually moved instead of asking
 * the reader to diff the file by hand. Only the lines that differ are printed,
 * which is four rules per brand rather than the whole stylesheet.
 */
function diffLines(string $before, string $after): string
{
    $old = explode("\n", $before);
    $new = explode("\n", $after);
    $common = 0;
    $max = min(count($old), count($new));
    while ($common < $max && $old[$common] === $new[$common]) {
        $common++;
    }

    $tail = 0;
    while (
        $tail < count($old) - $common
        && $tail < count($new) - $common
        && $old[count($old) - 1 - $tail] === $new[count($new) - 1 - $tail]
    ) {
        $tail++;
    }

    $out = '';
    foreach (array_slice($old, $common, count($old) - $common - $tail) as $line) {
        $out .= '- ' . $line . "\n";
    }
    foreach (array_slice($new, $common, count($new) - $common - $tail) as $line) {
        $out .= '+ ' . $line . "\n";
    }

    return $out === '' ? "(no line changes)\n" : $out;
}
