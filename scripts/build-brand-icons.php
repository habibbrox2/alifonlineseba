#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Render every raster brand icon from the same geometry as the SVG logos.
 *
 * ## Why this exists
 *
 * The mark is defined once, here, as coordinates on a 64x64 grid, and every
 * PNG and the ICO are drawn from those numbers. There is no ImageMagick,
 * Inkscape or rsvg on this box, so the alternative was hand-editing eight
 * PNGs, which is how an app icon ends up subtly different from the logo in
 * the header. If the mark changes, change it here and in
 * public/assets/brand/logo-mark.svg, then run:
 *
 *     php scripts/build-brand-icons.php
 *
 * ## How it draws
 *
 * Analytic coverage rather than a drawing library. Every shape is solved for
 * per scanline: the tile's corner arc, the bolt's polygon crossings, and each
 * tick segment's distance to the line. The span interiors are filled by
 * imagefilledrectangle() and only the pixels that straddle an edge are
 * blended by hand, then the whole thing is box-filtered down from 4x to the
 * target size. That last step is the antialiasing, which is why there are no
 * smoothing settings anywhere in here and why the result does not depend on a
 * tool being installed.
 *
 * Row-based rather than per-pixel is a deliberate correction: the first
 * version walked all four million samples of a 512px icon at 4x and took
 * minutes per icon. This one is well under a second.
 *
 * ## What it writes
 *
 * public/favicon.ico                     16/32/48, PNG-compressed entries
 * public/apple-touch-icon.png           180, opaque, no transparency
 * public/icon-192.png, public/icon-512.png            any-purpose
 * public/icon-maskable-192.png, -512.png             maskable, full bleed
 *
 * Requiring this file instead of running it gives the geometry and renderMark()
 * to scripts/build-og-cover.php; see the guard at the bottom.
 * android/.../mipmap-*\/ic_launcher.png                legacy launcher icons
 */

const GRID = 64;

/** Rounded-square tile: primary-900, the colour the whole UI is built on. */
const TILE = '#083f31';

/** Bolt: accent-500. */
const BOLT = '#f5b301';

/** Tick: white. */
const TICK = '#ffffff';

/** Bolt outline on the 64x64 grid. Four points, no curves, sharp at any size. */
const BOLT_POINTS = [
    [34.0, 7.0],
    [17.0, 37.0],
    [27.0, 37.0],
    [24.0, 57.0],
    [43.0, 27.0],
    [32.0, 27.0],
];

/** Tick polyline: start, elbow, end. Matches the SVG path exactly. */
const TICK_POINTS = [
    [39.0, 46.0],
    [45.5, 52.0],
    [56.0, 36.0],
];

/** Halo stroke width, then the white stroke on top. */
const TICK_HALO_WIDTH = 11.0;
const TICK_WIDTH = 7.0;

const TILE_RADIUS = 14.0;

/** Maskable: artwork confined to the inner 80% circle. */
const MASKABLE_SCALE = 0.8;

/** Supersampling factor for the analytic coverage pass. */
const SS = 4;

/**
 * @return array{0:int,1:int,2:int} RGB for a #rrggbb string.
 */
function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Blend one horizontal span of a colour into the row being built.
 *
 * The interior goes in with a C-level fill; only the one or two pixels that
 * straddle each edge are blended by hand. That split is the whole reason
 * this is row-based.
 */
function blendSpan(\GdImage $image, int $size, int $py, float $x0, float $x1, array $rgb): void
{
    if ($x1 <= $x0) {
        return;
    }

    $x0 = max(0.0, $x0);
    $x1 = min((float) $size, $x1);
    if ($x1 <= $x0) {
        return;
    }

    $first = (int) ceil($x0 - 0.5);
    $last = (int) floor($x1 - 0.5);

    // GD stores alpha in the top byte on a 7-bit scale where 0 is opaque and
    // 127 is invisible. Writing 255 there (the "full" value on a 32-bit
    // channel) reads back as 127 and the whole icon comes out transparent,
    // which is why the top byte is left at zero rather than filled in.
    $solid = (int) ($rgb[0] << 16 | $rgb[1] << 8 | $rgb[2]);

    if ($last >= $first) {
        imagefilledrectangle($image, $first, $py, $last, $py, $solid);
    }

    // The partial pixels at each end, where the analytic edge cuts a sample.
    $ends = [
        [$first - 1, $x0, true],
        [$last + 1, $x1, false],
    ];

    foreach ($ends as [$column, $edge, $isStart]) {
        if ($column < 0 || $column >= $size) {
            continue;
        }

        $centre = $column + 0.5;
        $coverage = $isStart
            ? max(0.0, min(1.0, $edge - $centre + 0.5))
            : max(0.0, min(1.0, $centre - $edge + 0.5));

        if ($coverage <= 0.0) {
            continue;
        }

        $under = imagecolorat($image, $column, $py);
        $r = (int) round((($under >> 16) & 0xFF) * (1 - $coverage) + $rgb[0] * $coverage);
        $g = (int) round((($under >> 8) & 0xFF) * (1 - $coverage) + $rgb[1] * $coverage);
        $b = (int) round(($under & 0xFF) * (1 - $coverage) + $rgb[2] * $coverage);

        imagesetpixel($image, $column, $py, (int) ($r << 16 | $g << 8 | $b));
    }
}

/**
 * Spans a polygon covers on one scanline, in pixels.
 *
 * @param list<array{0:float,1:float}> $points
 * @return list<array{0:float,1:float}>
 */
function polygonSpans(array $points, float $y, float $step, float $scale, float $offset): array
{
    $crossings = [];
    $count = count($points);

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        [$xi, $yi] = $points[$i];
        [$xj, $yj] = $points[$j];

        if (($yi > $y) === ($yj > $y)) {
            continue;
        }

        $x = ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi;
        $crossings[] = (($x - $offset) / $scale) / $step;
    }

    sort($crossings);

    $spans = [];
    for ($i = 0, $n = count($crossings) - 1; $i < $n; $i += 2) {
        $spans[] = [$crossings[$i], $crossings[$i + 1]];
    }

    return $spans;
}

/**
 * The span one stroked segment covers on a scanline, or null where it misses.
 *
 * A stroked segment is a capsule, and a horizontal line cuts a capsule in one
 * interval whose half-width is (halfWidth * length) / |dy|. Solving the
 * point-to-line distance for x gives that directly, and the same formula
 * covers the round caps: past the end of the segment the nearest point is the
 * endpoint, and the distance is still the distance to the infinite line, so
 * the span simply stops at the cap radius.
 *
 * @param list<array{0:float,1:float}> $points
 * @return array{0:float,1:float}|null
 */
function segmentSpan(
    array $points,
    int $index,
    float $y,
    float $step,
    float $width,
    float $scale,
    float $offset,
): ?array {
    [$ax, $ay] = $points[$index];
    [$bx, $by] = $points[$index + 1];

    $dy = $by - $ay;
    if (abs($dy) < 1e-9) {
        return null;
    }

    $t = ($y - $ay) / $dy;
    if ($t < 0.0 || $t > 1.0) {
        // Past the end of the segment, so the only thing left is the cap, and
        // it exists only within half a stroke of the endpoint.
        $endY = $t < 0.0 ? $ay : $by;
        if (abs($y - $endY) > $width / 2) {
            return null;
        }
        $t = $t < 0.0 ? 0.0 : 1.0;
    }

    $x = $ax + $t * ($bx - $ax);

    $length = sqrt(($bx - $ax) ** 2 + $dy ** 2);
    $half = $width * $length / abs($dy) / 2;

    return [
        ((($x - $half) - $offset) / $scale) / $step,
        ((($x + $half) - $offset) / $scale) / $step,
    ];
}

/**
 * Draw the mark into a GD image of the given pixel size.
 *
 * @param bool $maskable Full-bleed background and the artwork inside the
 *                       inner 80% circle, which is what a launcher mask needs.
 * @param bool $opaque   Flatten onto the tile colour instead of keeping the
 *                       corners transparent. iOS does not honour alpha on a
 *                       touch icon and draws black where it is missing.
 */
function renderMark(int $size, bool $maskable = false, bool $opaque = false): \GdImage
{
    $hi = $size * SS;
    $image = imagecreatetruecolor($hi, $hi);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    // Alpha 127 is fully transparent on GD's 7-bit scale. Every pixel is
    // overwritten below; this only defines the starting canvas.
    imagefill($image, 0, 0, 127 << 24);

    $tile = hexToRgb(TILE);
    $bolt = hexToRgb(BOLT);
    $tick = hexToRgb(TICK);

    // Maskable bleeds to the edge; the rest is a rounded square.
    $radiusPx = $maskable ? 0.0 : TILE_RADIUS / (GRID / $hi);
    // Maskable shrinks the artwork into the safe zone.
    $scale = $maskable ? MASKABLE_SCALE : 1.0;
    $offset = (1.0 - $scale) * GRID / 2;
    $step = GRID / $hi;

    for ($py = 0; $py < $hi; $py++) {
        $y = ($py + 0.5) * $step;
        $yPx = $py + 0.5;

        // Layer 1: the tile. On a rounded square the inset of the left and
        // right edge at this y is the corner arc, solved exactly.
        $inset = 0.0;
        if ($radiusPx > 0.0) {
            $fromEdge = $yPx < $radiusPx ? $radiusPx - $yPx : ($yPx > $hi - $radiusPx ? $yPx - ($hi - $radiusPx) : null);
            if ($fromEdge !== null) {
                $inset = $radiusPx - sqrt(max(0.0, $radiusPx * $radiusPx - $fromEdge * $fromEdge));
            }
        }

        blendSpan($image, $hi, $py, $inset, $hi - $inset, $tile);

        // Layer 2: the bolt, solid over the tile.
        foreach (polygonSpans(BOLT_POINTS, $y, $step, $scale, $offset) as [$x0, $x1]) {
            blendSpan($image, $hi, $py, $x0, $x1, $bolt);
        }

        // Layers 3 and 4: the tick halo, then the white tick over it.
        foreach ([TICK_HALO_WIDTH => $tile, TICK_WIDTH => $tick] as $width => $colour) {
            foreach ([0, 1] as $segment) {
                $span = segmentSpan(TICK_POINTS, $segment, $y, $step, $width * $scale, $scale, $offset);
                if ($span !== null) {
                    blendSpan($image, $hi, $py, $span[0], $span[1], $colour);
                }
            }
        }
    }

    // Box-filter down to the target size. This is the antialiasing.
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $image, 0, 0, 0, 0, $size, $size, $hi, $hi);
    imagedestroy($image);

    if ($opaque) {
        // Composite onto the tile so no pixel is left half transparent.
        $flat = imagecreatetruecolor($size, $size);
        $base = (int) ($tile[0] << 16 | $tile[1] << 8 | $tile[2]);
        imagefilledrectangle($flat, 0, 0, $size - 1, $size - 1, $base);
        imagealphablending($flat, true);
        imagecopy($flat, $out, 0, 0, 0, 0, $size, $size);
        imagedestroy($out);
        $out = $flat;
    }

    imagesavealpha($out, true);

    return $out;
}

/**
 * Write a PNG through a temporary file, so a failed encode cannot leave a
 * truncated icon where a working one used to be.
 */
function writePng(\GdImage $image, string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create ' . $dir);
    }

    $tmp = $path . '.tmp';
    if (!imagepng($image, $tmp)) {
        throw new RuntimeException('Failed to encode ' . $path);
    }
    imagedestroy($image);

    if (!rename($tmp, $path)) {
        throw new RuntimeException('Failed to move ' . $tmp . ' into place');
    }

    printf("  %-52s %6.1f KB\n", $path, (int) filesize($path) / 1024);
}

/**
 * Write a multi-size ICO.
 *
 * Windows and every current browser accept PNG payloads inside an ICO, which
 * is the only way to get a clean 48px entry without hand-encoding BMPs.
 *
 * @param list<array{0:int, 1:\GdImage}> $entries size, image
 */
function writeIco(array $entries, string $path): void
{
    $payloads = [];
    foreach ($entries as $index => [$size, $image]) {
        $tmp = tempnam(sys_get_temp_dir(), 'ico');
        if (!imagepng($image, $tmp)) {
            throw new RuntimeException('Failed to encode an ICO entry');
        }
        imagedestroy($image);
        $payloads[$index] = (string) file_get_contents($tmp);
        unlink($tmp);
    }

    $count = count($entries);
    $header = pack('vvv', 0, 1, $count);
    $offset = 6 + 16 * $count;
    $directory = '';
    $body = '';

    foreach ($entries as $index => [$size, $_]) {
        $length = strlen($payloads[$index]);
        // Width and height are single bytes and 0 means 256, so a 48px entry
        // is just 48. Nothing here is 256, but the rule is worth stating.
        $directory .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $length, $offset);
        $body .= $payloads[$index];
        $offset += $length;
    }

    $tmp = $path . '.tmp';
    file_put_contents($tmp, $header . $directory . $body);
    rename($tmp, $path);

    printf("  %-52s %6.1f KB\n", $path, (int) filesize($path) / 1024);
}

// ---- Run -------------------------------------------------------------------
//
// Guarded so another script can require this file purely for the mark: the
// share-card builder (scripts/build-og-cover.php) draws its logo with the same
// renderMark() and the same constants rather than keeping a second copy of the
// geometry, which is how a logo ends up meaning two things.

if (PHP_SAPI !== 'cli' || realpath($argv[0] ?? '') === __FILE__) {
    // ---- Run -------------------------------------------------------------------

    echo "Rendering the All Seba mark from the shared geometry\n";

    writePng(renderMark(180, false, true), __DIR__ . '/../public/apple-touch-icon.png');
    writePng(renderMark(192), __DIR__ . '/../public/icon-192.png');
    writePng(renderMark(512), __DIR__ . '/../public/icon-512.png');
    writePng(renderMark(192, true), __DIR__ . '/../public/icon-maskable-192.png');
    writePng(renderMark(512, true), __DIR__ . '/../public/icon-maskable-512.png');

    foreach (['mdpi' => 48, 'hdpi' => 72, 'xhdpi' => 96, 'xxhdpi' => 144, 'xxxhdpi' => 192] as $density => $size) {
        writePng(renderMark($size), __DIR__ . '/../android/app/src/main/res/mipmap-' . $density . '/ic_launcher.png');
    }

    writeIco([
        [16, renderMark(16)],
        [32, renderMark(32)],
        [48, renderMark(48)],
    ], __DIR__ . '/../public/favicon.ico');

    echo "Done. The mark itself lives in public/assets/brand/logo-mark.svg.\n";
}
