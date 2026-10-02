<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * QR Code Model 2 encoder that renders straight to an inline SVG path.
 *
 * Why this exists instead of a library: the Bangla QR on the recharge page has to
 * be printed twice — a thumb-sized inline copy and a full-screen one the user can
 * hold up to a scanner — and it has to be a self-contained element so the second
 * copy is the same bytes as the first. Any renderer we could pull in would add a
 * dependency and, for the client-side half, either a CDN or a second copy of the
 * encoding rules to keep in step with this one. So the encoder lives here, and
 * `QrEncoderTest` pins the module matrix against an independent implementation.
 *
 * Scope is deliberately narrow: byte mode, one segment, versions 1-40, the four
 * error-correction levels. Byte mode costs a few bits versus numeric/alphanumeric,
 * but the payload is a JSON document that is never all digits, so the saving
 * would be theoretical. Automatic mask selection is *not* narrow — a symbol
 * scanned through a phone camera off a screen needs the mask that produces the
 * fewest finder-like false positives, so all eight masks are built and scored by
 * the ISO/IEC 18004 penalty rules rather than letting one be assumed.
 *
 * The tables below are the ones from ISO/IEC 18004 tables 13-22; index 0 of each
 * row is a sentinel for "version 0", which does not exist.
 */
final class QrEncoder
{
    public const ECC_LOW = 'L';
    public const ECC_MEDIUM = 'M';
    public const ECC_QUARTILE = 'Q';
    public const ECC_HIGH = 'H';

    /** Position of each level in the ISO/IEC 18004 tables below. */
    private const ECC_ORDINAL = [
        self::ECC_LOW => 0,
        self::ECC_MEDIUM => 1,
        self::ECC_QUARTILE => 2,
        self::ECC_HIGH => 3,
    ];

    /** Two-bit level code written into the format information. */
    private const ECC_FORMAT_BITS = [
        self::ECC_LOW => 1,
        self::ECC_MEDIUM => 0,
        self::ECC_QUARTILE => 3,
        self::ECC_HIGH => 2,
    ];

    /** ISO/IEC 18004 table 13-22: error-correction codewords per block, indexed [level][version]. */
    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** ISO/IEC 18004 tables 13-22: number of error-correction blocks, indexed [level][version]. */
    private const ECC_NUM_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** Penalty weights from ISO/IEC 18004 section 8.8.2, used to pick a mask. */
    private const PENALTY_N1 = 3;
    private const PENALTY_N2 = 3;
    private const PENALTY_N3 = 40;
    private const PENALTY_N4 = 10;

    /** The lightest QR that scans at arm's length through a phone camera. */
    private const BYTE_MODE_INDICATOR = 0b0100;

    private int $version;
    private int $size;
    private string $ecc;
    private int $mask;

    /** @var array<int, array<int, bool>> True = dark module, indexed [y][x]. */
    private array $modules;

    /** @var array<int, array<int, bool>> Modules that carry format/timing data and must not be masked. */
    private array $isFunction;

    /**
     * Encode a byte-mode QR symbol, choosing the smallest version and the
     * best-scoring mask.
     *
     * @param string $data Raw bytes. Callers pass UTF-8; non-UTF-8 input is encoded as-is.
     * @throws InvalidArgumentException If the error-correction level is unknown or the data will not fit.
     */
    public static function encode(string $data, string $ecc = self::ECC_MEDIUM): self
    {
        if (!isset(self::ECC_ORDINAL[$ecc])) {
            throw new InvalidArgumentException('Unknown error correction level: ' . $ecc);
        }

        $qr = new self();
        $qr->ecc = $ecc;

        $version = $qr->smallestVersion($data, $ecc);
        $qr->version = $version;
        $codewords = $qr->padToCodewords($qr->dataBits($data, $version));
        $qr->size = $qr->version * 4 + 17;
        $qr->modules = array_fill(0, $qr->size, array_fill(0, $qr->size, false));
        $qr->isFunction = array_fill(0, $qr->size, array_fill(0, $qr->size, false));

        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($codewords));

        // Build every mask, score it, then rebuild the winner. Scoring needs the
        // format bits in place because the penalty rules look at the whole
        // symbol, so this cannot be done by trial on the data bits alone.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($candidate = 0; $candidate < 8; $candidate++) {
            $qr->applyMask($candidate);
            $qr->drawFormatBits($candidate);
            $penalty = $qr->penaltyScore();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
            $qr->applyMask($candidate); // XOR is its own inverse, so this unmasks.
        }

        $qr->mask = (int) $best;
        $qr->applyMask($qr->mask);
        $qr->drawFormatBits($qr->mask);

        return $qr;
    }

    private function __construct()
    {
    }

    public function version(): int
    {
        return $this->version;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** Mask pattern chosen by the penalty rules, 0-7. Recorded in the format information. */
    public function mask(): int
    {
        return $this->mask;
    }

    public function errorCorrectionLevel(): string
    {
        return $this->ecc;
    }

    /** True when the module at (x, y) is dark. Coordinates are in modules, origin top-left. */
    public function module(int $x, int $y): bool
    {
        return $this->modules[$y][$x] ?? false;
    }

    /**
     * Render as a standalone SVG.
     *
     * The whole symbol is one `<path>` of horizontal runs rather than one
     * `<rect>` per dark module: a version-4 symbol is 33x33 = 1089 modules, and
     * the rect-per-module form was ~60 KB of markup for a graphic that covers a
     * few hundred screen pixels. Runs collapse that to roughly a tenth and let
     * the browser rasterise one shape instead of a thousand.
     *
     * @param int $border Quiet zone in modules. The spec requires at least 4.
     */
    public function toSvg(int $border = 4, int $scale = 8, string $foreground = '#000000', string $background = '#ffffff'): string
    {
        $side = ($this->size + $border * 2) * $scale;
        $span = $this->size + $border * 2;

        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            $x = 0;
            while ($x < $this->size) {
                if (!$this->modules[$y][$x]) {
                    $x++;
                    continue;
                }
                $runStart = $x;
                while ($x < $this->size && $this->modules[$y][$x]) {
                    $x++;
                }
                $runLength = $x - $runStart;
                $path .= 'M' . ($runStart + $border) . ' ' . ($y + $border)
                    . 'd' . $runLength . 'v1h-' . $runLength . 'z';
            }
        }

        // The background is explicit rather than left to the page: a scanner needs
        // a light quiet zone, and on a dark-mode page an inherited background
        // would make the symbol unscannable.
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            . 'role="img" aria-hidden="true" focusable="false" shape-rendering="crispEdges">'
            . '<rect width="%d" height="%d" fill="%s"/><path fill="%s" d="%s"/></svg>',
            $side,
            $side,
            $span,
            $span,
            $span,
            $span,
            $background,
            $foreground,
            $path
        );
    }

    /**
     * Render the module matrix as text, one character per module.
     *
     * This exists so a failing case can be pasted into a decoder and read
     * directly. `QrEncoderTest` also asserts against it.
     */
    public function toText(): string
    {
        $rows = [];
        for ($y = 0; $y < $this->size; $y++) {
            $row = '';
            for ($x = 0; $x < $this->size; $x++) {
                $row .= $this->modules[$y][$x] ? '#' : '.';
            }
            $rows[] = $row;
        }
        return implode("\n", $rows);
    }

    // ---- Data segment ----

    /**
     * Serialise the payload as a single byte-mode segment, without padding.
     *
     * @return array<int, int> One entry per bit, most significant first.
     */
    private function dataBits(string $data, int $version): array
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        $bits = [];
        self::appendBits($bits, self::BYTE_MODE_INDICATOR, 4);
        self::appendBits($bits, count($bytes), self::byteCharCountBits($version));
        foreach ($bytes as $byte) {
            self::appendBits($bits, $byte, 8);
        }
        return $bits;
    }

    /** Byte mode counts characters in 8 bits up to version 9, 16 bits from version 10. */
    private static function byteCharCountBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    /** @param array<int, int> $bits */
    private static function appendBits(array &$bits, int $value, int $count): void
    {
        for ($i = $count - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * Smallest version whose data capacity holds the segment.
     *
     * The character-count field grows from 8 to 16 bits at version 10, so a
     * version is not simply "more bits than the last one" — each candidate is
     * costed with its own header width rather than the previous one's.
     *
     * @throws InvalidArgumentException If no version up to 40 fits.
     */
    private function smallestVersion(string $data, string $ecc): int
    {
        $used = 4 + self::byteCharCountBits(1) + strlen($data) * 8;
        for ($version = 1; $version <= 40; $version++) {
            $used = 4 + self::byteCharCountBits($version) + strlen($data) * 8;
            if ($used <= self::numDataCodewords($version, $ecc) * 8) {
                return $version;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Data needs %d bits; a version 40 %s QR code holds %d.',
            strlen($data) * 8 + 20,
            $ecc,
            self::numDataCodewords(40, $ecc) * 8
        ));
    }

    /**
     * Append the terminator, pad to a byte boundary, then fill to capacity with
     * the two alternating pad codewords the standard mandates.
     *
     * @param array<int, int> $bits
     * @return array<int, int> Data codewords as integers, 0-255.
     */
    private function padToCodewords(array $bits): array
    {
        $capacityBits = $this->numDataCodewords($this->version, $this->ecc) * 8;

        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        for ($padByte = 0xEC; count($bits) < $capacityBits; $padByte = $padByte === 0xEC ? 0x11 : 0xEC) {
            self::appendBits($bits, $padByte, 8);
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }
        return $codewords;
    }

    // ---- Drawing ----

    /** Timing patterns, finder patterns, alignment patterns, and the two format copies. */
    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }

        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        $positions = $this->alignmentPatternPositions();
        $numAlign = count($positions);
        $skip = [[0, 0], [0, $numAlign - 1], [$numAlign - 1, 0]];
        foreach ($positions as $i => $x) {
            foreach ($positions as $j => $y) {
                if (!in_array([$i, $j], $skip, true)) {
                    $this->drawAlignmentPattern($x, $y);
                }
            }
        }

        // A placeholder value; the real mask is decided after the data is placed.
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    /**
     * Centres of the alignment patterns on one axis, ascending.
     *
     * @return array<int, int>
     */
    private function alignmentPatternPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $numAlign = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;

        // Version 32 is a special case in the standard's own tables: the step
        // works out to 27, which would place a pattern outside the symbol.
        if ($this->version === 32) {
            $step = 26;
        }

        $positions = [];
        for ($i = 0; $i < $numAlign - 1; $i++) {
            $positions[] = $this->size - 7 - $i * $step;
        }
        $positions[] = 6;

        return array_reverse($positions);
    }

    /** 7x7 finder plus its separator, centred on (x, y). May hang off the edge. */
    private function drawFinderPattern(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $distance = max(abs($dx), abs($dy));
                    $this->setFunctionModule($xx, $yy, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    /** 5x5 alignment pattern centred on (x, y). Always in bounds. */
    private function drawAlignmentPattern(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function setFunctionModule(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    /**
     * The two copies of the format information, carrying the level and mask.
     *
     * Redundant on purpose: a symbol scanned off a screen often loses a corner,
     * and the copy in the other corner is what saves the decode.
     */
    private function drawFormatBits(int $mask): void
    {
        $data = self::ECC_FORMAT_BITS[$this->ecc] << 3 | $mask;
        $error = $data;
        for ($i = 0; $i < 10; $i++) {
            $error = ($error << 1) ^ (($error >> 9) * 0x537);
        }
        $bits = (($data << 10) | $error) ^ 0x5412;

        for ($i = 0; $i < 6; $i++) {
            $this->setFunctionModule(8, $i, self::getBit($bits, $i) === 1);
        }
        $this->setFunctionModule(8, 7, self::getBit($bits, 6) === 1);
        $this->setFunctionModule(8, 8, self::getBit($bits, 7) === 1);
        $this->setFunctionModule(7, 8, self::getBit($bits, 8) === 1);
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::getBit($bits, $i) === 1);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, self::getBit($bits, $i) === 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, self::getBit($bits, $i) === 1);
        }
        $this->setFunctionModule(8, $this->size - 8, true); // Always dark.
    }

    /** The two copies of the version information. Only version 7 and up carry it. */
    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $error = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $error = ($error << 1) ^ (($error >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $error;

        for ($i = 0; $i < 18; $i++) {
            $bit = self::getBit($bits, $i) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $bit);
            $this->setFunctionModule($b, $a, $bit);
        }
    }

    /**
     * Lay the interleaved codewords into the data region in the zigzag order the
     * standard prescribes: two-module columns, alternating upward and downward,
     * stepping over the vertical timing column.
     *
     * @param array<int, int> $data
     */
    private function drawCodewords(array $data): void
    {
        $bitIndex = 0;
        $totalBits = count($data) * 8;

        for ($column = $this->size - 1; $column > 0; $column -= 2) {
            // Step over the vertical timing pattern column. This is kept in its
            // own variable rather than adjusted in place: the loop counter has to
            // keep stepping by two, or the columns after the timing column are
            // silently skipped.
            $right = $column <= 6 ? $column - 1 : $column;
            $upward = (($right + 1) & 2) === 0;
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $bitIndex < $totalBits) {
                        $this->modules[$y][$x] = self::getBit($data[$bitIndex >> 3], 7 - ($bitIndex & 7)) === 1;
                        $bitIndex++;
                    }
                    // Any remainder bits stay light, as the constructor left them.
                }
            }
        }
    }

    private static function getBit(int $value, int $index): int
    {
        return ($value >> $index) & 1;
    }

    // ---- Masking ----

    /**
     * XOR the chosen mask pattern over the data modules.
     *
     * Function modules are skipped: their values are fixed by the standard and
     * re-drawing them masked would corrupt the timing patterns the decoder
     * relies on. Because this is XOR, calling it twice with the same pattern
     * restores the original.
     */
    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (!$this->isFunction[$y][$x] && self::maskApplies($mask, $x, $y)) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The eight patterns from ISO/IEC 18004 table 10; a module is masked where this is zero. */
    private static function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
            default => throw new InvalidArgumentException('Mask value out of range: ' . $mask),
        };
    }

    /**
     * Penalty score from ISO/IEC 18004 section 8.8.2; lower is better.
     *
     * The four rules target the ways a camera misreads a symbol: long runs of
     * one colour (N1), solid 2x2 blocks that look like an alignment pattern
     * (N2), the finder-like 1:1:3:1:1 sequence appearing where it should not
     * (N3), and a symbol that is too light or too dark overall (N4).
     */
    private function penaltyScore(): int
    {
        $result = 0;
        $size = $this->size;

        for ($y = 0; $y < $size; $y++) {
            $row = $this->modules[$y];
            $result += $this->penaltyScanLine($row);
        }
        for ($x = 0; $x < $size; $x++) {
            $column = [];
            for ($y = 0; $y < $size; $y++) {
                $column[] = $this->modules[$y][$x];
            }
            $result += $this->penaltyScanLine($column);
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if (
                    $this->modules[$y][$x] === $this->modules[$y][$x + 1]
                    && $this->modules[$y][$x] === $this->modules[$y + 1][$x]
                    && $this->modules[$y][$x] === $this->modules[$y + 1][$x + 1]
                ) {
                    $result += self::PENALTY_N2;
                }
            }
        }

        $dark = 0;
        foreach ($this->modules as $row) {
            foreach ($row as $module) {
                if ($module) {
                    $dark++;
                }
            }
        }
        // Smallest k for which the dark share falls inside 45-5k% .. 55+5k%.
        $total = $size * $size;
        $steps = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $result += $steps * self::PENALTY_N4;

        return $result;
    }

    /**
     * N1 and N3 along one row or column.
     *
     * N3 needs the seven most recent run lengths to spot the 1:1:3:1:1 core
     * together with its light margins, and those margins run off the edge of
     * the symbol, so the virtual light border on either side has to be folded
     * into the history or a genuine finder pattern at the edge scores nothing.
     *
     * @param array<int, bool> $line
     */
    private function penaltyScanLine(array $line): int
    {
        $result = 0;
        $runColor = false;
        $runLength = 0;
        $history = [0, 0, 0, 0, 0, 0, 0];

        foreach ($line as $module) {
            if ($module === $runColor) {
                $runLength++;
                if ($runLength === 5) {
                    $result += self::PENALTY_N1;
                } elseif ($runLength > 5) {
                    $result += 1;
                }
            } else {
                $history = $this->penaltyAddHistory($runLength, $history);
                if ($runColor === false) {
                    $result += $this->penaltyCountPatterns($history) * self::PENALTY_N3;
                }
                $runColor = $module;
                $runLength = 1;
            }
        }

        return $result + $this->penaltyTerminateAndCount($runColor, $runLength, $history) * self::PENALTY_N3;
    }

    /** @param array<int, int> $history Most recent run length first. */
    private function penaltyAddHistory(int $runLength, array $history): array
    {
        if ($history[0] === 0) {
            $runLength += $this->size; // The symbol is ringed by a light border.
        }
        array_unshift($history, $runLength);

        return array_slice($history, 0, 7);
    }

    /**
     * Close off the final run of a line before counting its patterns.
     *
     * @param array<int, int> $history
     */
    private function penaltyTerminateAndCount(bool $runColor, int $runLength, array $history): int
    {
        if ($runColor) {
            $history = $this->penaltyAddHistory($runLength, $history);
            $runLength = 0;
        }
        $history = $this->penaltyAddHistory($runLength + $this->size, $history);

        return $this->penaltyCountPatterns($history);
    }

    /**
     * The N3 count for one position: 0, 1, or 2. Only meaningful immediately
     * after a light run has been recorded.
     *
     * @param array<int, int> $history
     */
    private function penaltyCountPatterns(array $history): int
    {
        $n = $history[1];
        $core = $n > 0
            && $history[2] === $n
            && $history[4] === $n
            && $history[5] === $n
            && $history[3] === $n * 3;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0)
            + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }

    /**
     * Generator polynomial for `degree` error-correction codewords, stored
     * highest power first with the implicit leading 1x^degree dropped.
     *
     * @return array<int, int>
     */
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree - 1, 0);
        $result[$degree - 1] = 1; // The monomial x^0.
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::reedSolomonMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::reedSolomonMultiply($root, 0x02);
        }

        return $result;
    }

    /**
     * Polynomial remainder — the error-correction codewords for one block.
     *
     * @param array<int, int> $data
     * @param array<int, int> $divisor
     * @return array<int, int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::reedSolomonMultiply($coefficient, $factor);
            }
        }
        return $result;
    }

    /** Multiply two field elements modulo the QR field's reduction polynomial. */
    private static function reedSolomonMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    /**
     * Split the data into blocks, give each its own correction codewords, then
     * interleave.
     *
     * Interleaving is the point of the whole exercise: a phone camera scanning a
     * screen at an angle damages one *region* of the symbol, and interleaving
     * spreads what remains of a single block evenly across the codeword stream,
     * so the decoder can still correct it. Concatenating the blocks instead
     * would leave the damaged corner with no recoverable copy at all.
     *
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $ordinal = self::ECC_ORDINAL[$this->ecc];
        $numBlocks = self::ECC_NUM_BLOCKS[$ordinal][$this->version];
        $eccPerBlock = self::ECC_CODEWORDS_PER_BLOCK[$ordinal][$this->version];
        $rawCodewords = intdiv($this->numRawDataModules($this->version), 8);

        // Blocks differ in length by at most one codeword; the short ones come
        // first and get a placeholder during interleaving.
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);
        $placeholderIndex = $shortBlockLen - $eccPerBlock;

        $divisor = self::reedSolomonDivisor($eccPerBlock);
        $blocks = [];
        $offset = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $length = $shortBlockLen - $eccPerBlock + ($i < $numShortBlocks ? 0 : 1);
            $blockData = array_slice($data, $offset, $length);
            $offset += $length;
            $ecc = self::reedSolomonRemainder($blockData, $divisor);
            if ($i < $numShortBlocks) {
                $blockData[] = 0; // Placeholder, so every block ends up the same length.
            }
            $blocks[] = array_merge($blockData, $ecc);
        }

        $result = [];
        $blockLength = $shortBlockLen + 1;
        for ($i = 0; $i < $blockLength; $i++) {
            foreach ($blocks as $j => $block) {
                // Skip the placeholder held by the short blocks.
                if ($i !== $placeholderIndex || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Data modules available for codewords in a version, remainder bits included.
     *
     * Derived from the function-pattern geometry rather than tabulated: the
     * standard's own expression is
     * `(16v + 128)v + 64`, minus the alignment pattern modules, minus the two
     * version-information blocks from version 7 on. A lookup table here would be
     * 41 more rows to keep in step with the sizes above.
     */
    public static function numRawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    /** Data codewords in a version/level pair, with the remainder bits discarded. */
    public static function numDataCodewords(int $version, string $ecc): int
    {
        if (!isset(self::ECC_ORDINAL[$ecc]) || $version < 1 || $version > 40) {
            throw new InvalidArgumentException('Bad version/level combination.');
        }
        $ordinal = self::ECC_ORDINAL[$ecc];

        return intdiv(self::numRawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ordinal][$version] * self::ECC_NUM_BLOCKS[$ordinal][$version];
    }
}