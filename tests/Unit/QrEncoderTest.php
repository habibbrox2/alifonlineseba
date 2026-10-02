<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\QrEncoder;
use Codeception\Test\Unit;
use InvalidArgumentException;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Pins the QR symbol the app hands to a customer's banking app.
 *
 * A QR code is the one artefact in this codebase where a plausible-looking
 * mistake is invisible until a real phone camera refuses to scan it, and it
 * always fails in front of a customer at the till. So the matrices below are
 * not "expected" values invented alongside the code: each one was produced by
 * this encoder and then checked against an unrelated encoder (byte-mode
 * matrix-for-matrix at a pinned mask, versions 1 to 22) and decoded back to its
 * original payload by a third-party decoder. Any edit that perturbs a module,
 * a mask choice, a pad byte or the interleaving shows up here as a diff.
 *
 * The format-information test decodes the 15 BCH-protected bits back out of the
 * grid on its own, so the check does not borrow the encoder's own helper and
 * therefore cannot agree with a broken one.
 */
final class QrEncoderTest extends Unit
{
    /**
     * Byte-mode payload sizes and the symbol each one was verified to need, as
     * [ecc => [version => payload bytes]]. These are answers from an unrelated
     * encoder over a sweep of every length from 1 to 40 plus the boundaries
     * above, not capacities this project worked out for itself — 212 of them
     * agreed, and these are the ones kept as a compact regression net.
     */
    private const VERSION_PICKS = [
        'L' => [1 => 17, 2 => 32, 3 => 53, 4 => 78, 5 => 106, 6 => 122, 7 => 154, 8 => 180, 9 => 213, 10 => 271],
        'M' => [1 => 14, 2 => 26, 3 => 40, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 9 => 180, 10 => 213],
        'Q' => [1 => 11, 2 => 20, 3 => 32, 4 => 40, 5 => 53, 6 => 62, 7 => 84, 8 => 106, 9 => 122],
        'H' => [1 => 7, 2 => 14, 3 => 24, 4 => 34, 5 => 40, 6 => 53, 7 => 62, 8 => 84, 10 => 106],
    ];

    /**
     * The verified symbols: ASCII, Bangla UTF-8, a version boundary, and the
     * shape of payload this app actually sends.
     *
     * @dataProvider symbolProvider
     * @param array<int, string> $rows
     */
    public function testProducesTheVerifiedModuleGrid(string $text, string $ecc, int $version, int $mask, array $rows): void
    {
        $qr = QrEncoder::encode($text, $ecc);

        assertSame($version, $qr->version());
        assertSame($mask, $qr->mask());
        assertSame($version * 4 + 17, $qr->size());
        assertSame(implode("\n", $rows), $qr->toText());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: int, 4: array<int, string>}>
     */
    public static function symbolProvider(): iterable
    {
        yield 'ascii' => [
            'HELLO WORLD',
            'M',
            1,
            4,
            [
                '#######.##..#.#######',
                '#.....#....#..#.....#',
                '#.###.#..#.#..#.###.#',
                '#.###.#.#..#..#.###.#',
                '#.###.#.###.#.#.###.#',
                '#.....#.#..#..#.....#',
                '#######.#.#.#.#######',
                '........#..##........',
                '#...#.######.#####..#',
                '...#....#.###....####',
                '..######..##.##.#..#.',
                '#####...##...#.......',
                '#####.#.#.#.#.##..##.',
                '........#.#.####.#.##',
                '#######.###.#.#.##.#.',
                '#.....#..#.###.##..##',
                '#.###.#.##.#.##...##.',
                '#.###.#..#..#...##.##',
                '#.###.#..###...###...',
                '#.....#....#.#.......',
                '#######.#########.#.#',
            ],
        ];
        yield 'bangla' => [
            'বাংলা কোড',
            'M',
            2,
            4,
            [
                '#######.##.#.###..#######',
                '#.....#...#.#..#..#.....#',
                '#.###.#...#..#.##.#.###.#',
                '#.###.#.##.##..##.#.###.#',
                '#.###.#.#.####.##.#.###.#',
                '#.....#.####.#.#..#.....#',
                '#######.#.#.#.#.#.#######',
                '........#.#..####........',
                '#...#.####.#.....#####..#',
                '#####..##.##.###.....##..',
                '###..##.#......###..#####',
                '.##.##.####.##...#.....##',
                '##...###.##.#.###..##.###',
                '##...#.#.##..###...#.....',
                '....####..#..##.#...#####',
                '..##...#.#..###..###.#.#.',
                '#####.##.#.####.#####.#.#',
                '........#.#.#.#.#...##...',
                '#######.#..###..#.#.##..#',
                '#.....#..###.####...##...',
                '#.###.#.###.#..########..',
                '#.###.#..######....#.####',
                '#.###.#...#....##.#.##.#.',
                '#.....#..##.##.#.#.....#.',
                '#######.###.#.###..##.###',
            ],
        ];
        yield 'a full version 2 at medium correction' => [
            'TTTTTTTTTTTTTTTTTTTTTTTTTT',
            'M',
            2,
            1,
            [
                '#######.#....#.#..#######',
                '#.....#..#.#..#...#.....#',
                '#.###.#.#..#.###..#.###.#',
                '#.###.#...#.#.#.#.#.###.#',
                '#.###.#..#.###.#..#.###.#',
                '#.....#.#.#.#.#...#.....#',
                '#######.#.#.#.#.#.#######',
                '..........##....#........',
                '#.#...##.##..#.#...#..#.#',
                '...#.#..#.#.#.#.##.#.#.#.',
                '..######.#..##.##.#####.#',
                '#.##.#.#....#...#..#.#...',
                '.#.##.#..#..##.#..#.#.#.#',
                '.##.....##....#.##.#.#.#.',
                '##.######..###.##.#####.#',
                '..##.....#..#...#..#.#...',
                '##.#..###.##.#.######.#.#',
                '........#...#.###...##.#.',
                '#######.##.###.##.#.###.#',
                '#.....#.....#..##...##..#',
                '#.###.#...##.#.######.##.',
                '#.###.#...#.#.#..#.#.#.#.',
                '#.###.#.######..#.#.#####',
                '#.....#...#.#..#.#...#...',
                '#######.##.#.#..###.#.#.#',
            ],
        ];
        yield 'the merchant payload this app sends' => [
            '{"tid":"7E1C9A20","date":"2026-10-03T10:15:00+06:00","type":"merchantpay","mobile":"01700000000","sender":"DIGITALSHEBA","mdc":"digital-sheba","mu":"BDT","amount":"1250.00"}',
            'M',
            9,
            5,
            [
                '#######...#.###.##.##.###....###..##..#...#...#######',
                '#.....#.#.###.#####....###.#....#.#.#####.##..#.....#',
                '#.###.#.#.#.####.#..##..#.....###......##..#..#.###.#',
                '#.###.#.##......#...#.#...##....#.#.###.#.#.#.#.###.#',
                '#.###.#....#.#.##.###########.#.#......#.##...#.###.#',
                '#.....#....##..#..#.##..#...#....##..####.#...#.....#',
                '#######.#.#.#.#.#.#.#.#.#.#.#.#.#.#.#.#.#.#.#.#######',
                '........##.##.#.##.#....#...#...#..#...####..........',
                '#.....#.#....#..#......########..#..#..#.#...##..###.',
                '.##.#...####.#.##.#..#..#.#..##.#.#...#.#####.#.###..',
                '.###.##.###..#.#...#.#....#..#....#.####..#..####..#.',
                '....#...####..##.##...#.#..#.#..#.##...##.#.#...#..##',
                '..##.###.##...#..#...###....##..##.##.######..#.#..#.',
                '...##.......#...#.##.##.#...#.###...#...#.##.......##',
                '...######.#.#...##...#.#.##..#.#.##.#####.....#.#.#..',
                '##..##....#.##.#...########.......##..#.####.#..#.###',
                '##..#####.###......#.#....##.....####.#..####.###.#.#',
                '#.#......###.#.#.#.#..##.####.#......#.#..#..#..#.#..',
                '###.#.####.#...#####.....#.###.##..###.#.#.###..#.#.#',
                '#.#.##.#######...###..#.##....####...#..###......#...',
                '#.#...#.####.#.#..#...###.##.....#..##...####..##....',
                '.#.#...###.#..#..####.#.#.##.##..##.#######.#######..',
                '..##.##..####..##.....#.###......###..#.#.#.##.#.#.#.',
                '.#####.#...####.######.#.#..##..#.##....##.....#...#.',
                '.#.#######..#.####.##############.####.###########.#.',
                '.##.#...#.##....#.#######...#.##.#......#.###...#..#.',
                '...##.#.##...###..##.#.##.#.#..#..#.####....#.#.#.#..',
                '.##.#...#.##....####..###...####..#...##.##.#...####.',
                '..########.#.#...#..#...#####.#..#..#..#.##.########.',
                '....#...#..##.####.#..##..#..###...#....#.#.#.#.#..##',
                '#.##.##..##..##...#..#.#...#.#..#..###.##..##.##..#.#',
                '.#...#..#...#..###....#...#...#.#..#.#.##...#......#.',
                '#....##.##......#.#.##...###..##.##.####.#..##...##.#',
                '..##...#.#..##.###.##..#..##..#####.#.#...###..###...',
                '.##...####....#..##.##...#.###..###.###..#...#.##....',
                '..#.##.#######..##.#..#...##..###....####.#####.....#',
                '##.#.#####..##.#..#########..#.##.###.####.#..##.#.##',
                '####.#.###...####.....##..#...#.....##.##.#..#..##...',
                '###..##..#..##.#...#.#....#.##....##..##.#.###...##..',
                '...###.#......#...##.##.#.####....#..#....####...##..',
                '.##..###...#..###..#.#...#...##.....#.#......#.##..#.',
                '.#..#..#.#.....###.###.##.##.###.#...#.#.###..###..##',
                '##.######.#.#.#.###....#..#..#..#..##..####.#.#.##..#',
                '.##....###..#.#.#.#...........#.#.......#.##..#....#.',
                '...#..##..#.#...#.#..#.######..#.#..#..#....########.',
                '........##.##..#..#.#..##...###.###.#.#####.#...####.',
                '#######...#....##..#..#.#.#.##.#.####.##...##.#.#..#.',
                '#.....#..#####..#..#..#.#...#.###.......#.#.#...##...',
                '#.###.#..#....#..###...######.#####.##.##.#.######.##',
                '#.###.#........#.#..#..###..###.#...#...####.##.....#',
                '#.###.#..#....#####.....#.##......###.###.##.#.##..##',
                '#.....#..#...##.#...###.......#..###..##..#.###...#.#',
                '#######.#.#....#.##....##..#..##.#..#..#..##....###..',
            ],
        ];
    }

    /**
     * The symbol must be the smallest one that fits, or a short payload would
     * print a needlessly dense code that scanners struggle with.
     */
    public function testSymbolSizeMatchesAnIndependentEncoder(): void
    {
        foreach (self::VERSION_PICKS as $ecc => $byVersion) {
            foreach ($byVersion as $version => $length) {
                assertSame(
                    $version,
                    QrEncoder::encode(str_repeat('A', $length), $ecc)->version(),
                    "$ecc, $length bytes"
                );
            }
        }
    }

    /**
     * A longer payload can never need a smaller symbol. A capacity table with a
     * wrong entry still returns *a* version, so this is what catches the table
     * wobbling rather than merely drifting.
     */
    public function testSymbolNeverShrinksAsThePayloadGrows(): void
    {
        foreach (['L', 'M', 'Q', 'H'] as $ecc) {
            $previous = 0;
            for ($length = 1; $length <= 40; $length++) {
                $version = QrEncoder::encode(str_repeat('A', $length), $ecc)->version();
                assertGreaterThanOrEqual($previous, $version, "$ecc, $length bytes");
                $previous = $version;
            }
        }
    }

    /**
     * The character-count field widens from 8 to 16 bits at version 10, so a
     * payload that just crossed into the two-byte-count range must still be
     * costed with the new header width rather than the old one.
     */
    public function testVersionTenWidensTheCharacterCountField(): void
    {
        // 271 bytes is the v10-L ceiling and needs 16 count bits to describe.
        $exact = QrEncoder::encode(str_repeat('A', 271), 'L');
        assertSame(10, $exact->version());
        assertGreaterThan(10, QrEncoder::encode(str_repeat('A', 272), 'L')->version());
    }

    public function testRejectsAnUnknownErrorCorrectionLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown error correction level: X');

        QrEncoder::encode('hello', 'X');
    }

    /**
     * Version 40 is the ceiling of the specification, so the encoder has to
     * refuse rather than emit a symbol no scanner would accept.
     */
    public function testRejectsAPayloadLargerThanVersionForty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a version 40 L QR code holds');

        QrEncoder::encode(str_repeat('A', 2954), 'L');
    }

    /**
     * The format information is what tells a scanner the level and the mask;
     * if it disagreed with the symbol, the code would decode to noise.
     *
     * @dataProvider symbolProvider
     * @param array<int, string> $rows
     */
    public function testFormatInformationAgreesWithTheSymbol(
        string $text,
        string $ecc,
        int $version,
        int $mask,
        array $rows
    ): void {
        $qr = QrEncoder::encode($text, $ecc);

        [$level, $announcedMask] = $this->decodeFormatInformation($qr);

        assertSame($ecc, $level);
        assertSame($mask, $announcedMask);
    }

    /**
     * Reads the 15 format bits back out of the grid and unmasks them, using the
     * BCH format of ISO/IEC 18004 clause 9.1 rather than the encoder's own
     * drawing routine.
     *
     * @return array{0: string, 1: int}
     */
    private function decodeFormatInformation(QrEncoder $qr): array
    {
        $size = $qr->size();
        $positions = [];
        for ($bit = 0; $bit <= 5; $bit++) {
            $positions[$bit] = [8, $bit];
        }
        $positions[6] = [8, 7];
        $positions[7] = [8, 8];
        $positions[8] = [7, 8];
        for ($bit = 9; $bit < 15; $bit++) {
            $positions[$bit] = [14 - $bit, 8];
        }

        $value = 0;
        for ($bit = 0; $bit < 15; $bit++) {
            [$x, $y] = $positions[$bit];
            if ($qr->module($x, $y)) {
                $value |= 1 << $bit;
            }
        }

        $data = $value ^ 0x5412;
        $levels = ['M' => 0, 'L' => 1, 'H' => 2, 'Q' => 3];
        $level = array_search(($data >> 13) & 3, $levels, true);

        // The second copy of the format information, placed along the opposite
        // edge, must carry the same 15 bits.
        for ($bit = 0; $bit < 8; $bit++) {
            if ($qr->module($size - 1 - $bit, 8) !== ((($value >> $bit) & 1) === 1)) {
                $this->fail('The two copies of the format information disagree at bit ' . $bit);
            }
        }
        for ($bit = 8; $bit < 15; $bit++) {
            if ($qr->module(8, $size - 15 + $bit) !== ((($value >> $bit) & 1) === 1)) {
                $this->fail('The two copies of the format information disagree at bit ' . $bit);
            }
        }

        return [$level, ($data >> 10) & 7];
    }

    /**
     * The SVG goes straight into a template, so it has to stand on its own: no
     * external stylesheet or font, an explicit light quiet zone for the scanner,
     * and dimensions that agree with the viewBox so it scales to any card.
     */
    public function testSvgIsSelfContained(): void
    {
        $qr = QrEncoder::encode('https://example.test/pay/7E1C9A20', 'M');
        $svg = $qr->toSvg();

        assertSame(1, substr_count($svg, '<svg'));
        assertSame(1, substr_count($svg, '<path'));
        assertSame(1, substr_count($svg, '<rect'));
        assertStringNotContainsString('<script', $svg);
        assertStringNotContainsString('http://www.w3.org/1999/xlink', $svg);
        assertStringNotContainsString('url(', $svg);
        assertStringNotContainsString('<image', $svg);
        assertStringNotContainsString('<text', $svg);
        assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        assertStringContainsString('shape-rendering="crispEdges"', $svg);
        assertMatchesRegularExpression(
            '/^<svg [^>]*width="(\d+)" height="\1" viewBox="0 0 (\d+) \2"/',
            $svg
        );

        // The default four-module quiet zone on each side, drawn at 8x.
        $span = $qr->size() + 8;
        assertSame(
            1,
            preg_match(
                '/viewBox="0 0 ' . $span . ' ' . $span . '"/',
                $svg
            ),
            'the quiet zone must be inside the viewBox'
        );
    }

    /**
     * The border and scale are presentation, not data: changing them must not
     * change which modules are drawn, only how big the symbol comes out.
     */
    public function testBorderAndScaleOnlyAffectTheSizeOfTheDrawing(): void
    {
        $qr = QrEncoder::encode('INV-000123', 'M');

        preg_match('/<path fill="#000000" d="([^"]*)"\/>/', $qr->toSvg(2, 3), $small);
        preg_match('/<path fill="#000000" d="([^"]*)"\/>/', $qr->toSvg(6, 12), $large);

        assertNotSame('', $small[1] ?? '');
        // Same modules, shifted by the wider quiet zone: every run moves by the
        // four extra border modules, nothing else changes.
        assertSame(self::shiftPath($small[1], 4), $large[1]);

        assertStringContainsString('width="' . ($qr->size() + 4) * 3 . '"', $qr->toSvg(2, 3));
        assertStringContainsString('width="' . ($qr->size() + 12) * 12 . '"', $qr->toSvg(6, 12));
    }

    /**
     * Moves every coordinate in a run-length path by a fixed distance, to show
     * that a wider border shifts the drawing and never redraws it.
     */
    private static function shiftPath(string $path, int $by): string
    {
        return preg_replace_callback(
            '/M(\d+) (\d+)d(\d+)/',
            static fn (array $m): string => 'M' . ((int) $m[1] + $by) . ' ' . ((int) $m[2] + $by) . 'd' . $m[3],
            $path
        ) ?? $path;
    }

    /**
     * The same recharge must always print the same symbol: a customer comparing
     * two screenshots of one invoice should see one code, and a cache keyed on
     * the payload has to be stable.
     */
    public function testTheSameInputAlwaysProducesTheSameSymbol(): void
    {
        $first = QrEncoder::encode('{"tid":"7E1C9A20","amount":"1250.00"}', 'M');
        $second = QrEncoder::encode('{"tid":"7E1C9A20","amount":"1250.00"}', 'M');

        assertSame($first->toText(), $second->toText());
        assertSame($first->mask(), $second->mask());
        assertSame($first->toSvg(), $second->toSvg());
    }

    /**
     * The grid is the contract with the scanner: square, one row per module,
     * and only the two characters the fixtures use.
     */
    public function testTextGridIsSquareAndUsesOnlyTheDocumentedCharacters(): void
    {
        $qr = QrEncoder::encode(str_repeat('digital-sheba', 12), 'Q');
        $rows = explode("\n", $qr->toText());

        assertCount($qr->size(), $rows);
        foreach ($rows as $row) {
            assertSame($qr->size(), strlen($row));
            assertSame(0, preg_match('/[^#.]/', $row));
        }

        // The three finder patterns are the parts a scanner locks onto first.
        foreach ([[0, 0], [$qr->size() - 7, 0], [0, $qr->size() - 7]] as $origin) {
            [$left, $top] = $origin;
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $ring = $x === 0 || $x === 6 || $y === 0 || $y === 6;
                    $core = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    assertSame(
                        $ring || $core,
                        $qr->module($left + $x, $top + $y),
                        'finder pattern at ' . $left . ',' . $top
                    );
                }
            }
        }
    }

    }