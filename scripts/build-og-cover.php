#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Render the social share card (public/assets/img/og-cover.png).
 *
 * ## Why a script
 *
 * This file is a PNG, and a PNG cannot be edited when the brand changes: the
 * old wordmark was baked into the bitmap, so renaming the site left WhatsApp
 * and Facebook sharing a card for a product that no longer existed by that
 * name. Rendering it instead means the card and the logo cannot disagree.
 *
 * ## Where the logo comes from
 *
 * Requiring scripts/build-brand-icons.php gives this script the same geometry
 * and the same renderMark() the app icons use, so the card carries the real
 * mark rather than a second drawing of it.
 *
 * ## Fonts
 *
 * The wordmark is real text laid out with a TrueType font, the same decision
 * the SVG lockups make: hand-outlining a wordmark is how the previous lockup
 * shipped as "ATOLoi". The font list below is searched in order and the script
 * refuses to write a card with a missing font rather than silently falling
 * back to a bitmap font at the wrong size.
 *
 *     php scripts/build-og-cover.php
 */

require __DIR__ . '/build-brand-icons.php';

const CARD_W = 1200;
const CARD_H = 630;

/** Card background, top and bottom: primary-900 through primary-700. */
const CARD_TOP = '#052b22';
const CARD_BOTTOM = '#0b6e51';

const COVER_PATH = __DIR__ . '/../public/assets/img/og-cover.png';

/**
 * The first TrueType font that exists on this machine.
 *
 * @param list<string> $candidates
 */
function pickFont(array $candidates): string
{
    foreach ($candidates as $font) {
        if (is_file($font)) {
            return $font;
        }
    }

    throw new RuntimeException(
        'No usable TrueType font found. Install one of: ' . implode(', ', $candidates),
    );
}

/** Bold for the wordmark, semibold for the tagline, regular for the service list. */
function fonts(): array
{
    $dirs = [
        'C:/Windows/Fonts/',
        '/usr/share/fonts/truetype/dejavu/',
        '/usr/share/fonts/TTF/',
        '/System/Library/Fonts/Supplemental/',
    ];

    $find = static function (array $names) use ($dirs): string {
        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                if (is_file($dir . $name)) {
                    return $dir . $name;
                }
            }
        }

        throw new RuntimeException('None of ' . implode(', ', $names) . ' found in ' . implode(', ', $dirs));
    };

    return [
        'bold' => $find(['segoeuib.ttf', 'arialbd.ttf', 'calibrib.ttf', 'DejaVuSans-Bold.ttf']),
        'semibold' => $find(['seguisb.ttf', 'segoeuib.ttf', 'arialbd.ttf', 'DejaVuSans-Bold.ttf']),
        'regular' => $find(['segoeui.ttf', 'arial.ttf', 'calibri.ttf', 'DejaVuSans.ttf']),
    ];
}

/**
 * Draw text with its own top-left at ($x, $y), so callers position by the
 * box they see rather than by a baseline they have to remember the metrics of.
 */
function drawText(\GdImage $image, string $text, string $font, int $size, array $rgb, int $x, int $y, float $tracking = 0.0): void
{
    $box = imageftbbox($size, 0.0, $font, $text);
    $baseline = $y - $box[1];

    if ($tracking === 0.0) {
        imagettftext($image, $size, 0.0, $x, $baseline, (int) ($rgb[0] << 16 | $rgb[1] << 8 | $rgb[2]), $font, $text);
        return;
    }

    // Letter-spaced runs, one glyph at a time: the tagline is set wide on
    // purpose, and imagettftext has no tracking parameter.
    $cursor = (float) $x;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $glyph = $text[$i];
        imagettftext($image, $size, 0.0, (int) $cursor, $baseline, (int) ($rgb[0] << 16 | $rgb[1] << 8 | $rgb[2]), $font, $glyph);
        $cursor += imageftbbox($size, 0.0, $font, $glyph)[2] - imageftbbox($size, 0.0, $font, $glyph)[0] + $tracking;
    }
}

/** Width of a run, in pixels, including tracking. */
function textWidth(string $text, string $font, int $size, float $tracking = 0.0): float
{
    if ($tracking === 0.0) {
        $box = imageftbbox($size, 0.0, $font, $text);

        return (float) ($box[2] - $box[0]);
    }

    $width = 0.0;
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $box = imageftbbox($size, 0.0, $font, $text[$i]);
        $width += ($box[2] - $box[0]) + $tracking;
    }

    return $width - $tracking;
}

$font = fonts();

$card = imagecreatetruecolor(CARD_W, CARD_H);
imagealphablending($card, true);

// Vertical gradient, one row at a time.
[$tr, $tg, $tb] = hexToRgb(CARD_TOP);
[$br, $bg, $bb] = hexToRgb(CARD_BOTTOM);
for ($y = 0; $y < CARD_H; $y++) {
    $t = $y / (CARD_H - 1);
    $row = imagecreatetruecolor(CARD_W, 1);
    imagefilledrectangle($row, 0, 0, CARD_W - 1, 0, (int) (
        (int) round($tr + ($br - $tr) * $t) << 16
        | (int) round($tg + ($bg - $tg) * $t) << 8
        | (int) round($tb + ($bb - $tb) * $t)
    ));
    imagecopy($card, $row, 0, $y, 0, 0, CARD_W, 1);
    imagedestroy($row);
}

// A soft glow behind the lockup, so the mark sits in light rather than on a
// flat field. Concentric translucent ellipses on the card itself are cheaper
// than a real blur and indistinguishable at this size; alpha 2 is about the
// weakest blend GD will honour without it vanishing.
for ($step = 18; $step >= 1; $step--) {
    imagefilledellipse(
        $card,
        (int) (CARD_W / 2),
        268,
        620 + $step * 46,
        300 + $step * 22,
        (int) (2 << 24 | 0xFF << 16 | 0xFF << 8 | 0xFF),
    );
}

// The mark, at the same geometry as the app icons.
$markSize = 132;
$mark = renderMark($markSize);

$wordmark = 'All Seba';
$wordmarkSize = 86;
$wordmarkWidth = textWidth($wordmark, $font['bold'], $wordmarkSize);
$gap = 30;

$groupWidth = $markSize + $gap + $wordmarkWidth;
$groupX = (int) round((CARD_W - $groupWidth) / 2);
$groupY = 190;

imagecopy($card, $mark, $groupX, $groupY, 0, 0, $markSize, $markSize);
imagedestroy($mark);

// Wordmark, vertically centred on the mark rather than sat on its baseline.
$wordBox = imageftbbox($wordmarkSize, 0.0, $font['bold'], $wordmark);
$textHeight = $wordBox[1] - $wordBox[7];
drawText(
    $card,
    $wordmark,
    $font['bold'],
    $wordmarkSize,
    hexToRgb(TICK),
    $groupX + $markSize + $gap,
    $groupY + (int) round(($markSize - $textHeight) / 2),
);

// Gold rule, then the tagline, then the service list.
$ruleY = $groupY + $markSize + 62;
$rule = imagecreatetruecolor(112, 6);
imagefilledrectangle($rule, 0, 0, 111, 5, (int) (hexToRgb(BOLT)[0] << 16 | hexToRgb(BOLT)[1] << 8 | hexToRgb(BOLT)[2]));
imagecopy($card, $rule, (int) round((CARD_W - 112) / 2), $ruleY, 0, 0, 112, 6);
imagedestroy($rule);

$tagline = 'Instant Digital Services Hub';
drawText(
    $card,
    $tagline,
    $font['semibold'],
    36,
    hexToRgb(TICK),
    (int) round((CARD_W - textWidth($tagline, $font['semibold'], 36)) / 2),
    $ruleY + 74,
);

$services = 'NID  ·  Voter  ·  Birth Certificate  ·  Mobile Banking';
drawText(
    $card,
    $services,
    $font['regular'],
    26,
    [216, 226, 222],
    (int) round((CARD_W - textWidth($services, $font['regular'], 26)) / 2),
    $ruleY + 126,
);

// Gold rule along the bottom edge, echoing the accent in the mark.
$bottom = imagecreatetruecolor(CARD_W, 8);
imagefilledrectangle($bottom, 0, 0, CARD_W - 1, 7, (int) (hexToRgb(BOLT)[0] << 16 | hexToRgb(BOLT)[1] << 8 | hexToRgb(BOLT)[2]));
imagecopy($card, $bottom, 0, CARD_H - 8, 0, 0, CARD_W, 8);
imagedestroy($bottom);

$dir = dirname(COVER_PATH);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create ' . $dir);
}

$tmp = COVER_PATH . '.tmp';
if (!imagepng($card, $tmp)) {
    throw new RuntimeException('Failed to encode the share card');
}
imagedestroy($card);
rename($tmp, COVER_PATH);

printf("  %-52s %6.1f KB\n", COVER_PATH, (int) filesize(COVER_PATH) / 1024);
echo "Done. The card and the app icons now share one mark.\n";