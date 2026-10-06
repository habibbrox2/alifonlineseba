<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ImageUploadStorage;
use App\ServiceProvider\MockNidMakeService;
use App\ServiceProvider\ServiceField;
use Codeception\Test\Unit;
use Nyholm\Psr7\UploadedFile;
use ReflectionMethod;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * An upload is stored outside the web root and never served, so its size is
 * paid for out of the disk and never bought back by speed. These tests pin
 * down what shrinking is allowed to do, because every failure mode is silent:
 *
 *  - shrink too little and a 12MP phone photo costs 2.5 MB of disk forever;
 *  - shrink too much and the ID card goes onto the print blurred, which is the
 *    one thing the user was paying for;
 *  - "optimise" something that cannot be optimised and you have turned a
 *    signature into a black rectangle, or an animated GIF into one frame;
 *  - and whatever happens, the path contract must not move. A stored name that
 *    no longer matches {@see ImageUploadStorage::isValidRelativePath()} is an
 *    image nothing can delete again.
 *
 * The quality thresholds are measured against a q100 reference resample of
 * the same fixture rather than guessed, and every fixture is generated here
 * instead of committed as a binary, so what is being measured is readable in
 * the test itself.
 */
final class ImageOptimisationTest extends Unit
{
    private string $basePath = '';
    private ImageUploadStorage $storage;

    protected function _before(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is not available, so no image can be measured.');
        }

        $this->basePath = sys_get_temp_dir() . '/image-optimisation-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0o750, true);
        $this->storage = new ImageUploadStorage($this->basePath);
    }

    protected function _after(): void
    {
        $this->rmTree($this->basePath);
    }

    // ---- The photograph users actually upload -------------------------------

    public function testALargeJpegIsScaledToTheCeilingAndShrinksByAboutHalf(): void
    {
        $bytes = $this->encode($this->cardImage(2400, 1600), 'jpg', 82);
        assertTrue(
            strlen($bytes) > 1024 * 1024,
            'The fixture has to be a photograph-sized file for this to mean anything.',
        );

        $stored = $this->storage->store($this->upload($bytes, 'nid.jpg'), 'probe');

        assertTrue($stored['optimised'], 'A 2400px photo is over the ceiling and must be touched.');
        assertSame(ImageUploadStorage::MAX_EDGE, $stored['width']);
        assertSame(1067, $stored['height'], '1600 wide keeps the 3:2 ratio of a 2400x1600 photo.');
        assertSame(strlen($bytes), $stored['original_size']);
        assertLessThan(0.75 * strlen($bytes), $stored['size']);
        assertSame($stored['size'], (int) filesize($this->absolute($stored['path'])));
    }

    public function testTheStoredJpegIsStillTheSamePictureAndNotJustASmallerOne(): void
    {
        $source = $this->cardImage(2400, 1600);
        $bytes = $this->encode($source, 'jpg', 82);

        $stored = $this->storage->store($this->upload($bytes, 'nid.jpg'), 'probe');
        $actual = (string) file_get_contents($this->absolute($stored['path']));

        // The ideal answer: the same source resampled to the ceiling and stored
        // at quality 100. Whatever the comparison finds is the cost of the
        // quality we chose, isolated from the cost of the resize.
        $ideal = imagecreatetruecolor(1600, 1067);
        imagecopyresampled($ideal, $source, 0, 0, 0, 0, 1600, 1067, 2400, 1600);
        $idealImage = $this->encode($ideal, 'jpg', 100);
        imagedestroy($ideal);

        assertLessThan(
            12,
            $this->meanChannelError($idealImage, $actual),
            'A mean error above ~12/255 would mean the picture really did degrade, '
            . 'not just lose file size.',
        );
    }

    public function testAWebpIsShrunkAndKeepsItsType(): void
    {
        $bytes = $this->encode($this->cardImage(2400, 1600), 'webp', 82);
        $stored = $this->storage->store($this->upload($bytes, 'nid.webp'), 'probe');

        assertTrue($stored['optimised']);
        assertSame(ImageUploadStorage::MAX_EDGE, $stored['width']);
        assertLessThan(0.9 * strlen($bytes), $stored['size']);

        $absolute = $this->absolute($stored['path']);
        assertSame('image/webp', (string) getimagesize($absolute)['mime']);
        assertStringEndsWith('.webp', basename($stored['path']), 'The extension still comes from the sniffed type.');
    }

    // ---- A named budget ------------------------------------------------------

    public function testAPhotoLandsInsideItsOwnBudget(): void
    {
        // A real phone photo of a card: over 2.5 MB, which is precisely what the
        // whole feature exists to deal with.
        $bytes = $this->encode($this->cardImage(3000, 2000), 'jpg', 90);
        assertGreaterThan(2 * 1024 * 1024, strlen($bytes), 'The fixture must start out over 2 MB to be meaningful.');

        $stored = $this->storage->store(
            $this->upload($bytes, 'photo.jpg'),
            'probe',
            MockNidMakeService::PHOTO_MAX_BYTES,
        );

        assertTrue($stored['optimised']);
        assertLessThanOrEqual(MockNidMakeService::PHOTO_MAX_BYTES, $stored['size'], '100 KB is a ceiling, not a hope.');
        assertSame($stored['size'], (int) filesize($this->absolute($stored['path'])));
        assertGreaterThan(
            700,
            $stored['width'],
            'Hitting the budget must not mean collapsing the photo to nothing.',
        );
    }

    public function testASignatureLandsInsideItsOwnBudget(): void
    {
        $bytes = $this->encode($this->signatureImage(2400, 600), 'png', 6);
        $stored = $this->storage->store(
            $this->upload($bytes, 'signature.png'),
            'probe',
            MockNidMakeService::SIGNATURE_MAX_BYTES,
        );

        assertLessThanOrEqual(MockNidMakeService::SIGNATURE_MAX_BYTES, $stored['size']);
        assertGreaterThan(0, $stored['width']);
    }

    public function testAnImageAlreadyInsideItsBudgetIsLeftExactlyAsItArrived(): void
    {
        $bytes = $this->encode($this->signatureImage(600, 150), 'jpg', 85);
        $stored = $this->storage->store(
            $this->upload($bytes, 'small-signature.jpg'),
            'probe',
            MockNidMakeService::SIGNATURE_MAX_BYTES,
        );

        assertFalse($stored['optimised'], 'A budget is not a reason to resample something that already fits.');
        assertSame($bytes, (string) file_get_contents($this->absolute($stored['path'])));
    }

    public function testABudgetTooSmallToReachMissesRatherThanRuinsTheFile(): void
    {
        $bytes = $this->encode($this->cardImage(2400, 1600), 'jpg', 82);
        $stored = $this->storage->store($this->upload($bytes, 'nid.jpg'), 'probe', 5 * 1024);

        // 5 KB is not reachable for this picture without destroying it. The
        // ladder is bounded, so the answer is the best it managed — still a
        // real, recognisable image — and simply over the target.
        assertTrue($stored['optimised']);
        assertGreaterThan(
            400,
            $stored['width'],
            'The walk stops at its floor; it must not keep shrinking past legibility '
            . 'just to make a number go down.',
        );
        assertSame('image/jpeg', (string) getimagesize($this->absolute($stored['path']))['mime']);
    }

    public function testTheNidMakeFieldsCarryTheirOwnBudgets(): void
    {
        $budgets = [];
        foreach ((new MockNidMakeService())->fields() as $field) {
            if ($field->type === 'image') {
                $budgets[$field->name] = $field->maxBytes;
            }
        }

        assertSame(100 * 1024, MockNidMakeService::PHOTO_MAX_BYTES);
        assertSame(40 * 1024, MockNidMakeService::SIGNATURE_MAX_BYTES);
        assertSame(
            ['photo' => 100 * 1024, 'signature' => 40 * 1024],
            $budgets,
            'The budgets have to be on the fields, or the form will not carry them to the storage.',
        );
    }

    // ---- The cases that must be left completely alone ----------------------

    public function testAnImageAlreadyUnderTheCeilingIsStoredByteForByte(): void
    {
        $bytes = $this->encode($this->cardImage(400, 300, 11), 'png', 6);

        $stored = $this->storage->store($this->upload($bytes, 'small.png'), 'probe');

        assertFalse($stored['optimised'], 'Nothing to gain by re-encoding a small image.');
        assertSame($bytes, (string) file_get_contents($this->absolute($stored['path'])));
        assertSame(400, $stored['width']);
        assertSame(300, $stored['height']);
    }

    public function testASmallImageIsNeverEnlarged(): void
    {
        $bytes = $this->encode($this->cardImage(200, 140, 3), 'png', 6);
        $stored = $this->storage->store($this->upload($bytes, 'signature.png'), 'probe');

        assertSame(200, $stored['width'], 'A 200px signature must not become a 1600px blur.');
        assertSame(140, $stored['height']);
    }

    public function testAGifIsNeverReEncodedSoAnAnimationCannotBeFlattened(): void
    {
        $bytes = $this->encode($this->cardImage(2400, 1600), 'gif');
        $stored = $this->storage->store($this->upload($bytes, 'loop.gif'), 'probe');

        // GD reads only the first frame of an animated GIF and writes one back,
        // so re-encoding would quietly turn a moving picture into a still one.
        assertFalse($stored['optimised']);
        assertSame($bytes, (string) file_get_contents($this->absolute($stored['path'])));
        assertSame(2400, $stored['width'], 'And the reported size must be the one on disk, not the one discarded.');
    }

    public function testAPhotographicPngKeepsTheOriginalWhenReEncodingWouldGrowIt(): void
    {
        // Measured: resampling a grainy PNG makes its row filters do worse, and
        // the "optimised" file comes out roughly half again as large.
        $bytes = $this->encode($this->cardImage(2400, 1600), 'png', 6);
        $stored = $this->storage->store($this->upload($bytes, 'scan.png'), 'probe');

        assertFalse($stored['optimised']);
        assertSame($bytes, (string) file_get_contents($this->absolute($stored['path'])));
    }

    // ---- Transparency: the signature failure mode --------------------------

    public function testTransparencySurvivesAResample(): void
    {
        foreach (['png', 'webp'] as $format) {
            $source = imagecreatetruecolor(2400, 1600);
            imagealphablending($source, false);
            imagesavealpha($source, true);
            imagefill($source, 0, 0, (int) imagecolorallocatealpha($source, 0, 0, 0, 127));
            imagefilledrectangle($source, 600, 500, 1800, 1100, (int) imagecolorallocate($source, 20, 20, 20));
            $bytes = $this->encode($source, $format, 82);
            imagedestroy($source);

            $stored = $this->storage->store($this->upload($bytes, 'sign.' . $format), 'probe');
            $decoded = imagecreatefromstring(
                (string) file_get_contents($this->absolute($stored['path'])),
            );
            assertInstanceOf(\GdImage::class, $decoded);

            $corner = ((int) imagecolorat($decoded, 5, 5)) >> 24;
            assertGreaterThan(
                100,
                $corner,
                sprintf(
                    'A transparent %s corner came back with alpha %d — this is what turns a '
                    . 'signature into a black rectangle.',
                    $format,
                    $corner,
                ),
            );
            imagedestroy($decoded);
        }
    }

    // ---- EXIF: the portrait phone photo -------------------------------------

    public function testAPortraitPhonePhotoIsTurnedTheRightWayUp(): void
    {
        // A 4x2 raster, red on the left and blue on the right. Reading the
        // bands back tells each of the eight orientations apart — in
        // particular 5 and 6, which look identical to anything that only
        // checks the dimensions.
        $expected = [
            1 => '4x2 B',
            2 => '4x2 R',
            3 => '4x2 R',
            4 => '4x2 B',
            5 => '2x4 BR',
            6 => '2x4 RB',
            7 => '2x4 RB',
            8 => '2x4 BR',
        ];

        $apply = new ReflectionMethod(ImageUploadStorage::class, 'applyExifOrientation');

        foreach ($expected as $orientation => $want) {
            $raster = imagecreatetruecolor(4, 2);
            imagefilledrectangle($raster, 0, 0, 1, 1, (int) imagecolorallocate($raster, 255, 0, 0));
            imagefilledrectangle($raster, 2, 0, 3, 1, (int) imagecolorallocate($raster, 0, 0, 255));

            $baked = $apply->invoke($this->storage, $raster, $orientation);
            assertInstanceOf(\GdImage::class, $baked);

            assertSame(
                $want,
                $this->bands($baked),
                sprintf('EXIF orientation %d is not applied as the spec describes.', $orientation),
            );
            imagedestroy($baked);
        }
    }

    public function testAnImageWithNoOrientationTagIsLeftAlone(): void
    {
        $read = new ReflectionMethod(ImageUploadStorage::class, 'readExifOrientation');
        $stored = $this->storage->store(
            $this->upload($this->encode($this->cardImage(400, 300), 'jpg', 82), 'plain.jpg'),
            'probe',
        );

        assertSame(
            1,
            $read->invoke($this->storage, $this->absolute($stored['path'])),
            'No tag means no rotation, never a guess.',
        );
    }

    // ---- What must not change ----------------------------------------------

    public function testShrinkingDoesNotDisturbThePathContract(): void
    {
        $bytes = $this->encode($this->cardImage(3000, 2000), 'jpg', 90);
        $stored = $this->storage->store(
            $this->upload($bytes, 'my photo!!.jpg'),
            'probe',
            MockNidMakeService::PHOTO_MAX_BYTES,
        );

        assertTrue($stored['optimised'], 'This one really was re-encoded.');
        assertTrue(
            $this->storage->isValidRelativePath($stored['path']),
            'The stored name must still be exactly one bucket and 32 hex characters: ' . $stored['path'],
        );
        assertSame(1, substr_count($stored['path'], '/'), 'One bucket directory and nothing else.');
        assertNotNull($this->storage->absolutePath($stored['path']), 'And the bytes are still reachable.');

        $this->storage->delete($stored['path']);
        assertNull($this->storage->absolutePath($stored['path']), 'And still deletable.');
    }

    public function testOptimisingNeverTurnsARejectedUploadIntoAnAcceptedOne(): void
    {
        // A PHP script wearing a .jpg name. Decoding is the risky part of this
        // feature, and it must stay behind the type check.
        $this->expectException(\RuntimeException::class);
        $this->storage->store($this->upload("<?php echo 'x'; ?>\n", 'evil.jpg'), 'probe');
    }

    public function testAnUploadWithoutAContentTypeIsStillSniffed(): void
    {
        // `getClientMediaType()` is nullable in PSR-7 and a request really can
        // omit it; the sniffed bytes, not the header, decide what this is.
        $bytes = $this->encode($this->cardImage(2400, 1600), 'jpg', 82);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $file = new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'no-type.jpg', null);

        $stored = $this->storage->store($file, 'probe');

        assertSame('image/jpeg', $stored['mime']);
        assertTrue($stored['optimised']);
    }

    // ---- The budget as it is stored on a field ------------------------------

    public function testAFieldBudgetSurvivesARoundTripThroughStoredConfiguration(): void
    {
        $field = ServiceField::fromArray([
            'name' => 'photo',
            'label' => 'আইডি ফটো',
            'type' => 'image',
            'required' => false,
            'max_bytes' => 100 * 1024,
        ]);

        assertSame(100 * 1024, $field?->maxBytes);

        $restored = ServiceField::fromConfig([
            'name' => 'photo',
            'label' => 'আইডি ফটো',
            'type' => 'image',
            'max_bytes' => $field?->toArray()['max_bytes'],
        ]);

        assertSame(100 * 1024, $restored?->maxBytes, 'An admin-edited configuration must carry the budget.');
    }

    public function testAnUnusableBudgetInConfigurationMeansNoBudgetRatherThanABadOne(): void
    {
        // This value is hand-editable JSON, so every one of these has to arrive
        // as "no target" rather than reaching `store()`.
        foreach ([null, '', 'abc', '-1', -1, 12, 0, [], 3.5, true] as $value) {
            assertNull(
                ServiceField::normaliseMaxBytes($value),
                'Unusable budget: ' . var_export($value, true),
            );
        }

        assertSame(
            ImageUploadStorage::MAX_BYTES,
            ServiceField::normaliseMaxBytes(ImageUploadStorage::MAX_BYTES * 10),
            'A field cannot ask for more than an upload is allowed to be.',
        );
    }

    // ---- Helpers -----------------------------------------------------------

    private function absolute(string $relative): string
    {
        $absolute = $this->storage->absolutePath($relative);
        assertNotNull($absolute);

        return $absolute;
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        // No declared content type: the sniffed bytes must be what decides,
        // and a null header must not be a crash.
        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, null);
    }

    /**
     * An ID-card-shaped picture: header bar, text rules, a photo box, a
     * signature squiggle and a light sensor grain. It compresses like a real
     * scan rather than like television static, which matters because that is
     * what the byte ceilings are sized against.
     */
    private function cardImage(int $width, int $height, int $seed = 7): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 245, 245, 240));
        imagefilledrectangle(
            $image,
            0,
            0,
            $width - 1,
            (int) ($height * 0.18),
            (int) imagecolorallocate($image, 13, 106, 110),
        );

        $ink = (int) imagecolorallocate($image, 30, 30, 30);
        mt_srand($seed);
        for ($row = 0; $row < 9; $row++) {
            $y = (int) ($height * 0.28) + $row * (int) ($height * 0.055);
            $length = (int) ($width * (0.30 + mt_rand(0, 45) / 100));
            imagefilledrectangle(
                $image,
                (int) ($width * 0.08),
                $y,
                (int) ($width * 0.08) + $length,
                $y + (int) ($height * 0.018),
                $ink,
            );
        }

        imagefilledrectangle(
            $image,
            (int) ($width * 0.60),
            (int) ($height * 0.30),
            (int) ($width * 0.92),
            (int) ($height * 0.72),
            (int) imagecolorallocate($image, 205, 205, 200),
        );

        $previous = null;
        for ($x = (int) ($width * 0.60); $x < (int) ($width * 0.92); $x++) {
            $y = (int) ($height * 0.82) + (int) (sin($x / 14) * $height * 0.04);
            if ($previous !== null) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }
            $previous = [$x, $y];
        }

        for ($i = 0; $i < (int) ($width * $height / 40); $i++) {
            $grain = mt_rand(0, 40);
            imagesetpixel(
                $image,
                mt_rand(0, $width - 1),
                mt_rand(0, $height - 1),
                (int) imagecolorallocate($image, $grain, $grain, $grain),
            );
        }

        return $image;
    }

    /** A pen stroke on a transparent ground, at whatever size is asked for. */
    private function signatureImage(int $width = 2400, int $height = 600): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        $ink = (int) imagecolorallocate($image, 0, 0, 0);
        $previous = null;
        for ($x = (int) ($width * 0.12); $x < (int) ($width * 0.88); $x++) {
            $y = (int) ($height / 2)
                + (int) (sin($x / 18) * $height * 0.2)
                + (int) (sin($x / 5) * ($height * 0.03));
            if ($previous !== null) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }
            $previous = [$x, $y];
        }

        return $image;
    }

    /** Encoder output as bytes, so a fixture is never written anywhere. */
    private function encode(\GdImage $image, string $format, int $quality = -1): string
    {
        $bytes = '';
        ob_start();
        try {
            match ($format) {
                'jpg' => imagejpeg($image, null, $quality),
                // PNG's argument is a compression level, not a quality: 0-9.
                'png' => imagepng($image, null, min(9, max(0, $quality))),
                'webp' => imagewebp($image, null, $quality),
                'gif' => imagegif($image),
            };
        } finally {
            $bytes = (string) ob_get_clean();
        }

        if ($bytes === '') {
            throw new \RuntimeException('The ' . $format . ' fixture could not be encoded.');
        }

        return $bytes;
    }

    /** Mean absolute difference per channel, 0-255, between two encoded images. */
    private function meanChannelError(string $expectedBytes, string $actualBytes): float
    {
        $expected = imagecreatefromstring($expectedBytes);
        $actual = imagecreatefromstring($actualBytes);

        $total = 0;
        $samples = 0;
        for ($y = 0; $y < 1067; $y += 2) {
            for ($x = 0; $x < 1600; $x += 2) {
                $a = (int) imagecolorat($expected, $x, $y);
                $b = (int) imagecolorat($actual, $x, $y);
                foreach ([16, 8, 0] as $shift) {
                    $total += abs((($a >> $shift) & 0xFF) - (($b >> $shift) & 0xFF));
                    $samples++;
                }
            }
        }

        return $total / max(1, $samples);
    }

    /** Geometry plus the colour of each horizontal band, e.g. `2x4 RB`. */
    private function bands(\GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $out = '';
        for ($y = 0; $y < $height; $y += 2) {
            $out .= (((int) imagecolorat($image, intdiv($width, 2), $y) >> 16) & 0xFF) > 128 ? 'R' : 'B';
        }

        return $width . 'x' . $height . ' ' . $out;
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->rmTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}