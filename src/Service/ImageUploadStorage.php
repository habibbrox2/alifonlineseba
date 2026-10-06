<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Stores an ordinary user-supplied image (an avatar, a product photo, a
 * screenshot) outside the web root and never serves it back over HTTP.
 *
 * It deliberately reuses the security model established by
 * {@see DeliverableStorage} and {@see ReceiptStorage}, and is kept separate
 * from them for the same reasons those two are separate: the three hold
 * different classes of file, and a shared parent would leak the differences
 * (a receipt can be watermarked, a deliverable must not be, and this upload
 * is never displayed at all).
 *
 * The rules, which are the point of the class:
 *
 * 1. Images ONLY. The allowlist is keyed on the *sniffed* MIME type, so a
 *    `.php` or `.html` file renamed to `.png` is refused — the extension the
 *    bytes are stored under is derived from what the bytes actually are.
 * 2. Never inside `public/`. The base path lives under `web/`, one level
 *    outside the document root, so the image is not reachable by a guessed
 *    URL, a crawler, or a misconfigured vhost.
 * 3. The client filename is never used as a path. The stored name is
 *    `random bytes + a whitelisted extension`, so traversal and
 *    double-extension tricks are both impossible. The original name is kept* only as display text, stripped of its extension.
 * 4. Hard size ceiling, checked against the *stream* length as well as the
 *     reported size, so a lying `Content-Length` cannot slip past it.
 *  5. Shrunk, never padded. An image bigger than {@see MAX_EDGE} on its
 *     longest edge is resampled down and re-encoded; a caller that names a
 *     byte target gets the image walked down a resolution/quality ladder
 *     until it fits. Anything already small enough is stored byte-for-byte.
 *     See {@see optimise()} for why, and for the formats that opt out.
 *  6. No `absolutePath()` for serving. This class has no method that hands a
 *     caller a path to read back through the web server — the stored image is
 *     available to the owning process only. That is the difference from
 *     `DeliverableStorage`, which must expose a read path to show a paid file.
 *
 * It is a general-purpose utility: it knows nothing about any particular
 * feature, form, or workflow, and is safe to use from any non-identity
 * context (product images, avatars, attachments, screenshots).
 */
final class ImageUploadStorage
{
    /**
     * Hard ceiling on what a single image upload may *arrive* as.
     *
     * This is deliberately not the same number as what ends up stored, and the
     * distinction is the whole point. An ordinary phone photo of an ID card is
     * 2.5-4 MB, so a ceiling set at what we wanted to *keep* would refuse
     * exactly the uploads this class exists to handle — the conversion would
     * never run. So the ceiling is set at what we are willing to *receive*,
     * and the stored size is brought down afterwards: to the field's own budget
     * where one is configured, and otherwise to the {@see MAX_EDGE} resample.
     *
     * Nothing large is ever kept: the bytes are written, shrunk in place and
     * only the shrunken ones survive, so a 12 MB upload costs 12 MB of
     * transient disk and then a few tens of kilobytes. {@see MAX_PIXELS} is
     * what actually bounds the work — a decompression bomb is refused at the
     * decode step rather than allocated.
     */
    public const MAX_BYTES = 12 * 1024 * 1024;

    /**
     * Longest edge, in pixels, that a stored image may have.
     *
     * Sized for what the pixels are actually *for*: an ID photo or a
     * signature has to stay legible in a printed NID card, and 1600px on the
     * long edge prints sharp at 300dpi across a 5.5" card while being roughly
     * a sixteenth of the pixels of a modern phone photo. Nothing here needs
     * more, so nothing is kept at more.
     */
    public const MAX_EDGE = 1600;

    /**
     * Below this, the uploaded bytes are kept untouched.
     *
     * A small image is not what fills a disk, and re-encoding one can only
     * trade away quality for a few percent. The work is not worth doing.
     */
    private const MIN_BYTES_TO_TOUCH = 120 * 1024;

    /** JPEG re-encode quality. High: this is an identity document, not a thumbnail. */
    private const JPEG_QUALITY = 88;

    /** WebP re-encode quality. */
    private const WEBP_QUALITY = 86;

    /** PNG stores losslessly, so only the compression level is meaningful. */
    private const PNG_COMPRESSION = 9;

    /**
     * The smallest edge the ladder will produce.
     *
     * Below roughly this an ID photo stops being usable at all, so a target
     * that demands it is not honoured — see {@see bestEncode()}.
     */
    private const MIN_EDGE = 480;

    /**
     * Longest edges the ladder walks down when a byte target is named.
     *
     * Resolution goes before quality, because on an identity document a blurry
     * edge is a permanent defect while a slightly soft gradient is not — and
     * because the print size is small enough that 1000px already oversamples
     * it. The walk stops at {@see MIN_EDGE}, so a target that cannot be met
     * is missed rather than met by ruining the file.
     */
    private const EDGE_LADDER = [self::MAX_EDGE, 1280, 1024, 820, 660, 540, self::MIN_EDGE];

    /**
     * Quality steps tried at each rung of the ladder, before dropping further.
     *
     * Three is enough: it resolves "slightly too big" without walking a fine
     * grained dial, and it keeps a bounded number of encodes per upload.
     */
    private const QUALITY_STEPS = 3;

    /**
     * Refusal threshold for decoding, in pixels.
     *
     * A decompression bomb is a few kilobytes of JPEG that asks GD for
     * gigabytes of bitmap. Anything past this is stored as uploaded rather
     * than decoded, so a hostile image cannot turn an upload into an OOM.
     */
    private const MAX_PIXELS = 16_000_000;

    /** Accepted for the `accept` attribute of the upload widget. Client-side only. */
    public const ACCEPT_ATTRIBUTE = 'image/jpeg,image/png,image/webp,image/gif';

    /**
     * Sniffed MIME type => extension we are willing to store it under.
     * Deliberately not the browser's Content-Type.
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(private readonly string $basePath) {}

    /** Absolute path of the directory holding every stored image. */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Validate and persist an uploaded image.
     *
     * @param int|null $targetBytes When given, the image is walked down a
     *                               resolution/quality ladder until it fits
     *                               in this many bytes — or until doing so
     *                               would cost more legibility than the bytes
     *                               are worth, in which case the best attempt
     *                               is stored and simply misses the target.
     *
     * @return array{
     *     path: string, name: string, mime: string,
     *     size: int, original_size: int, width: int, height: int, optimised: bool
     * } `size` is what is on disk after optimisation; `original_size` is what
     *     the client sent, so the difference is the saving that was made.
     *
     * @throws RuntimeException with a Bengali, user-facing message when the
     *                           upload is unusable. The caller turns this into
     *                           a form error rather than a 500 — the file
     *                           simply did not save.
     */
    public function store(
        UploadedFileInterface $file,
        string $bucket = 'default',
        ?int $targetBytes = null,
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->errorMessage((int) $file->getError()));
        }

        $stream = $file->getStream();
        if ($stream === null || !$stream->isReadable()) {
            throw new RuntimeException('ছবিটি পড়া যায়নি। আবার চেষ্টা করুন।');
        }

        // Measure the stream, not just the reported size: `getSize()` can be
        // null or can disagree with reality, and this ceiling is the thing
        // standing between a bad request and a full disk.
        $size = $stream->getSize();
        if ($size === null) {
            $size = $this->measureStream($stream);
        }
        if ($size <= 0) {
            throw new RuntimeException('ছবিটি খালি। আবার চেষ্টা করুন।');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'ছবির সাইজ %s — সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize((int) $size),
                $this->humanSize(self::MAX_BYTES),
            ));
        }

        $reported = $file->getSize();
        if ($reported !== null && $reported > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'ছবির সাইজ %s — সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize((int) $reported),
                $this->humanSize(self::MAX_BYTES),
            ));
        }

        // `getClientMediaType()` and `getClientFilename()` are both nullable in
        // PSR-7, and a request that omits Content-Type really does arrive with
        // null — so neither is allowed anywhere near a `string` parameter.
        $mime = $this->sniffMime($stream, $file->getClientMediaType());
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('শুধু JPG, PNG, WEBP বা GIF ছবি গ্রহণযোগ্য।');
        }

        $directory = $this->directoryFor($bucket);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('ছবি সংরক্ষণের ফোল্ডার তৈরি করা যায়নি।');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . DIRECTORY_SEPARATOR . $name;

        // moveTo() goes through move_uploaded_file() for a genuine HTTP upload
        // (which refuses a path PHP never registered) and falls back to a
        // stream copy only under a SAPI without that check.
        try {
            $file->moveTo($target);
        } catch (\Throwable) {
            @unlink($target);
            throw new RuntimeException('ছবিটি সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
        }

        if (!is_file($target)) {
            throw new RuntimeException('ছবিটি সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
        }

        // A file that exceeded the ceiling on disk but slipped past the stream
        // length above is removed here rather than left behind.
        $onDisk = (int) @filesize($target);
        if ($onDisk <= 0 || $onDisk > self::MAX_BYTES) {
            @unlink($target);
            throw new RuntimeException('ছবিটি গ্রহণযোগ্য নয়। আবার চেষ্টা করুন।');
        }

        @chmod($target, 0o640);

        // Shrinking happens after the file is safely written and its type is
        // proven, so a decode failure can only ever leave the original bytes
        // in place — an optimisation must never be able to reject an upload
        // that storage already accepted.
        $result = $this->optimise($target, $mime, $targetBytes);
        @chmod($target, 0o640);

        return [
            'path' => $this->relativeNameFor($bucket, $name),
            'name' => $this->clientName($file),
            'mime' => $mime,
            'size' => (int) @filesize($target),
            'original_size' => $onDisk,
            'width' => $result['width'],
            'height' => $result['height'],
            'optimised' => $result['changed'],
        ];
    }

    /**
     * Shrink a stored image in place, if that is both safe and worth doing.
     *
     * Without a target the rule is deliberately narrow: only an image whose
     * longest edge exceeds {@see MAX_EDGE} is resampled, and only then is it
     * re-encoded. That is the one change that buys a lot of bytes for no
     * visible quality, because resampling throws away high-frequency detail
     * the encoder can then represent in a fraction of the space — whereas
     * re-encoding an already-small image is pure loss.
     *
     * With a target the rule becomes {@see bestEncode()}'s: walk down until it
     * fits. Either way the common cases are honest:
     *
     *  - a 12MP phone photo becomes ~1600px wide and a fraction of its former
     *    size, looking the same in every place it is ever displayed;
     *  - a 400x300 scan is stored exactly as it arrived;
     *  - an image is never enlarged, so a 200x140 signature is not inflated
     *    into a blurry 1600px rectangle;
     *  - and nothing is ever stored *larger* than what the client sent.
     *
     * Every failure path — no GD, a format GD cannot read, an animated GIF,
     * a decompression bomb, a re-encode that happens to come out *larger* —
     * leaves the file exactly as it was. The stored name, extension and
     * {@see isValidRelativePath()} contract are untouched by all of this: the
     * re-encoded bytes go back under the same hashed name.
     *
     * @return array{width: int, height: int, changed: bool}
     */
    private function optimise(string $absolute, string $mime, ?int $targetBytes): array
    {
        $dimensions = @getimagesize($absolute);
        $width = is_array($dimensions) ? (int) $dimensions[0] : 0;
        $height = is_array($dimensions) ? (int) $dimensions[1] : 0;
        $untouched = ['width' => $width, 'height' => $height, 'changed' => false];

        // GD decodes only the first frame of an animated GIF and writes one
        // back, so re-encoding would silently turn a moving picture into a
        // still one. A GIF is also already palette-compressed; there is
        // nothing here worth trading for.
        if ($mime === 'image/gif' || $this->encoderFor($mime) === null) {
            return $untouched;
        }

        $originalBytes = (int) @filesize($absolute);

        // A caller that names a target replaces the generic "already small
        // enough" floor with its own, so a 90 KB signature scan is still worth
        // taking down to 40 KB even though the generic rule would skip it.
        // The pixel ceiling still applies either way: an image that is both
        // inside its budget and inside the ceiling has nothing to gain, and
        // resampling it anyway would only cost resolution.
        $withinCeiling = max($width, $height) <= self::MAX_EDGE;
        if ($targetBytes !== null) {
            if ($originalBytes <= $targetBytes && $withinCeiling) {
                return $untouched;
            }
        } elseif ($originalBytes < self::MIN_BYTES_TO_TOUCH || $withinCeiling) {
            return $untouched;
        }

        // Refuse before decoding, not after: reading the header is cheap and
        // the whole point is to never allocate the bitmap in the first place.
        if ($width <= 0 || $height <= 0 || !$this->canDecode($width * $height)) {
            return $untouched;
        }

        $bytes = @file_get_contents($absolute);
        if (!is_string($bytes) || $bytes === '') {
            return $untouched;
        }

        $image = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$image instanceof \GdImage) {
            return $untouched;
        }

        try {
            $image = $this->applyExifOrientation($image, $this->readExifOrientation($absolute));
            $candidate = $this->bestEncode($image, $mime, $targetBytes);
        } finally {
            imagedestroy($image);
        }

        // The encoder is not obliged to beat the original. It very much does
        // not, for a grainy PNG: resampling decorrelates the grain, PNG's row
        // filters then do worse, and the "optimised" file can come out half
        // again as large. Keeping the client's bytes is then the better of the
        // two, and the dimensions reported back must describe what is really
        // on disk rather than the picture that was thrown away.
        if ($candidate === null || $candidate['size'] >= $originalBytes) {
            return ['width' => $width, 'height' => $height, 'changed' => false];
        }

        if (@file_put_contents($absolute, $candidate['bytes'], LOCK_EX) !== $candidate['size']) {
            return ['width' => $width, 'height' => $height, 'changed' => false];
        }

        // The caller reports the stored size by measuring the file, and PHP
        // caches stat results for a path it has already seen this request —
        // so without this the reported size would be the one we replaced.
        clearstatcache(true, $absolute);

        return ['width' => $candidate['width'], 'height' => $candidate['height'], 'changed' => true];
    }

    /**
     * The smallest file this image can be turned into, within reason.
     *
     * Two regimes, and which one applies is the caller's decision:
     *
     *  - no target: one shot at {@see MAX_EDGE} and the default quality. The
     *    point of that shot is the resample, not the re-compression, so the
     *    quality stays high and nothing else is tried.
     *  - a target: walk {@see EDGE_LADDER} from the top, and at each rung try
     *    a few quality steps before dropping further, stopping at the first
     *    attempt that fits. Resolution is spent before quality because on a
     *    document a soft edge is a defect and a soft gradient is not.
     *
     * The walk is bounded on purpose. It refuses to go below {@see MIN_EDGE},
     * so a target too small to reach without ruining the picture is *missed*
     * rather than met — the smallest attempt is returned and the caller stores
     * that. An ID photo is worth more than the bytes it saves, and quietly
     * destroying the thing the user paid for to fix a disk figure would be the
     * worse failure.
     *
     * @return array{bytes: string, size: int, width: int, height: int}|null
     */
    private function bestEncode(\GdImage $source, string $mime, ?int $targetBytes): ?array
    {
        $longest = max(imagesx($source), imagesy($source));
        $edges = $targetBytes === null
            ? [min(self::MAX_EDGE, $longest)]
            : array_values(array_filter(
                self::EDGE_LADDER,
                static fn (int $edge): bool => $edge < $longest,
            ));
        if ($edges === []) {
            // Already at or below the smallest rung, so there is nowhere to
            // walk — but quality is still worth trying if a target was named
            // and the bytes are over it.
            $edges = [$longest];
        }

        $best = null;
        $qualities = $this->qualityLadder($mime);
        if ($targetBytes === null) {
            // No target means no searching: the win being chased is the
            // resample, and the quality is there to be spent on it. Walking
            // the ladder here would hand back a smaller file nobody asked for
            // at a quality nobody agreed to.
            $qualities = [reset($qualities)];
        }

        foreach ($edges as $edge) {
            $scaled = $this->scaleToEdge($source, $edge, $mime);
            if ($scaled === null) {
                continue;
            }

            try {
                foreach ($qualities as $quality) {
                    $encoded = $this->encode($scaled, $mime, $quality);
                    if ($encoded === null || $encoded === '') {
                        continue;
                    }

                    $size = strlen($encoded);
                    if ($best === null || $size < $best['size']) {
                        $best = [
                            'bytes' => $encoded,
                            'size' => $size,
                            'width' => imagesx($scaled),
                            'height' => imagesy($scaled),
                        ];
                    }

                    // Fits. Every attempt before this one was larger than the
                    // target and therefore larger than this one, so this is
                    // both the first fit and the smallest.
                    if ($targetBytes !== null && $size <= $targetBytes) {
                        return $best;
                    }
                }
            } finally {
                imagedestroy($scaled);
            }
        }

        return $best;
    }

    /**
     * A truecolor copy of the source with the given longest edge.
     *
     * Always a NEW bitmap, even when no resample is needed: the caller frees
     * what comes back, and handing back the source would free it twice.
     */
    private function scaleToEdge(\GdImage $source, int $edge, string $mime): ?\GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1.0, $edge / max($sourceWidth, $sourceHeight));

        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $scaled = @imagecreatetruecolor($width, $height);
        if (!$scaled instanceof \GdImage) {
            return null;
        }

        try {
            $this->keepAlpha($scaled, $mime);
            // Alphablending off is what makes a resample of a transparent PNG
            // keep its transparency instead of averaging it into whatever was
            // underneath.
            imagecopyresampled(
                $scaled,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight,
            );
        } catch (\Throwable) {
            imagedestroy($scaled);

            return null;
        }

        return $scaled;
    }

    /**
     * Quality settings to try at one rung of the ladder, best first.
     *
     * PNG has no quality dial — it is lossless, and the level only trades CPU
     * for bytes — so it gets exactly one step and the ladder is carried by
     * resolution alone.
     *
     * @return int[]
     */
    private function qualityLadder(string $mime): array
    {
        $best = match ($mime) {
            'image/jpeg', 'image/pjpeg' => self::JPEG_QUALITY,
            'image/webp' => self::WEBP_QUALITY,
            default => self::PNG_COMPRESSION,
        };
        if ($best === self::PNG_COMPRESSION) {
            return [self::PNG_COMPRESSION];
        }

        $steps = [$best];
        for ($i = 1; $i < self::QUALITY_STEPS; $i++) {
            $steps[] = max(40, $best - $i * 14);
        }

        return $steps;
    }

    /**
     * The EXIF orientation of a stored file, or 1 when there is nothing to say.
     *
     * Deliberately forgiving: a missing extension, a truncated tag or a file
     * GD can still decode are all reasons to return 1, never to fail the
     * upload.
     */
    private function readExifOrientation(string $absolute): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($absolute);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Rotate a photo the way its EXIF tag says it should be seen.
     *
     * Re-encoding drops the metadata, and the orientation tag is the one piece
     * of metadata that changes how the picture *looks*: without this, every
     * portrait photo taken on a phone — which is most of what gets uploaded
     * here — would come back on its side. So the rotation is baked into the
     * pixels before anything else happens, which also means every viewer
     * agrees on the answer instead of only the ones honouring EXIF.
     */
    private function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if ($orientation < 2 || $orientation > 8) {
            return $image;
        }

        // 5 and 7 are the two mirror-and-rotate combinations; the mirror is
        // applied first because that is the order the EXIF spec describes.
        if ($orientation === 5 || $orientation === 7) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = @imagerotate($image, $angle, $this->transparentColour());
            if ($rotated instanceof \GdImage) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        if ($orientation === 2) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    /**
     * A fully transparent colour identifier for `imagerotate()`.
     *
     * It has to come from an image rather than from `imagecolorallocatealpha()`
     * on the image being rotated, because GD indexes colours per image — and
     * the corners a rotation uncovers must not be filled with opaque black.
     */
    private function transparentColour(): int
    {
        $swatch = imagecreatetruecolor(1, 1);
        $colour = (int) imagecolorallocatealpha($swatch, 0, 0, 0, 127);
        imagedestroy($swatch);

        return $colour;
    }

    /**
     * Prepare an image for the encoder we are about to use.
     *
     * PNG and WebP can carry an alpha channel and JPEG cannot, so a JPEG goes
     * the other way: alpha blending on, alpha saving off. Getting this wrong
     * on a transparent PNG is what turns a signature into a black rectangle.
     */
    private function keepAlpha(\GdImage $image, string $mime): void
    {
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }

        if ($mime === 'image/jpeg' || $mime === 'image/pjpeg') {
            imagealphablending($image, true);
            imagesavealpha($image, false);
            return;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    /** The GD encoder for a sniffed MIME type, or null when there is none. */
    private function encoderFor(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/pjpeg' => function_exists('imagejpeg') ? 'imagejpeg' : null,
            'image/png' => function_exists('imagepng') ? 'imagepng' : null,
            'image/webp' => function_exists('imagewebp') ? 'imagewebp' : null,
            default => null,
        };
    }

    /**
     * Re-encode to bytes, without ever naming a second file.
     *
     * Encoding straight into memory rather than to a `.tmp` beside the upload
     * is deliberate: a temporary file in a hashed bucket directory is an
     * orphan nothing would ever collect, so there is not one. The result is
     * written over the original only after it has been shown to be smaller.
     */
    private function encode(\GdImage $image, string $mime, int $quality): ?string
    {
        $encoder = $this->encoderFor($mime);
        if ($encoder === null) {
            return null;
        }

        $this->keepAlpha($image, $mime);

        $level = ob_get_level();
        $ok = false;
        ob_start();
        try {
            $ok = match ($mime) {
                'image/jpeg', 'image/pjpeg' => imagejpeg($image, null, $quality),
                'image/png' => imagepng($image, null, $quality),
                'image/webp' => imagewebp($image, null, $quality),
                default => false,
            };
        } finally {
            // Closed even when the encoder throws, so a failed optimisation
            // cannot swallow the page's own output buffer.
            $bytes = $ok ? (string) ob_get_contents() : '';
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $bytes === '' ? null : $bytes;
    }

    /**
     * Whether a bitmap this size is safe to open at all.
     *
     * The static cap bounds the worst case on an unlimited server; the
     * `memory_limit` check is what actually matters on the small shared
     * hosting this can end up on, where a legitimate 16MP photo would be
     * refused by the process rather than by us. Either way the upload is
     * still accepted and stored — it is only the shrinking that is skipped.
     */
    private function canDecode(int $pixels): bool
    {
        if ($pixels > self::MAX_PIXELS) {
            return false;
        }

        $limit = self::memoryLimitBytes();
        if ($limit === null) {
            return true;
        }

        // GD holds about four bytes per pixel for the decoded bitmap and a
        // comparable amount again in working copies while resampling.
        return memory_get_usage(true) + ($pixels * 4) * 2 < $limit;
    }

    /** `memory_limit` in bytes, or null when it is unlimited or unreadable. */
    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Whether a stored path is well-formed — used to validate a value coming
     * back from the database before anything is deleted.
     *
     * This is deliberately NOT a read path. There is no method here that
     * returns a servable location for the bytes, so an image stored by this
     * class cannot be turned into a public URL by mistake.
     */
    public function isValidRelativePath(?string $relativePath): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $relativePath);

        return !str_contains($normalized, '..')
            && !str_starts_with($normalized, '/')
            && (bool) preg_match('#^[a-z0-9_-]+/[a-f0-9]{32}\.[a-z0-9]+$#', $normalized);
    }

    /**
     * Absolute path for a stored image — for the OWNING PROCESS only.
     *
     * This is not a web-serve path. It exists so a background job or a CLI
     * command can read or move a file it owns; there is deliberately no
     * corresponding action that echoes it into a response. The `..` rejection
     * plus the `realpath()` containment check mean a corrupted or hand-edited
     * database row still cannot read a file outside the base directory.
     */
    public function absolutePath(?string $relativePath): ?string
    {
        if (!$this->isValidRelativePath($relativePath)) {
            return null;
        }

        $absolute = $this->basePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, (string) $relativePath);
        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            return null;
        }

        $realBase = realpath($this->basePath);
        if ($realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * Remove a stored image.
     *
     * Called when an upload is replaced or abandoned — the old bytes must not
     * linger, since the storage layout is hashed and nothing else would ever
     * reach them again.
     */
    public function delete(?string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);
        if ($absolute === null) {
            return;
        }

        @unlink($absolute);

        $directory = dirname($absolute);
        $realBase = realpath($this->basePath);
        if (is_dir($directory) && $realBase !== false
            && str_starts_with((string) realpath($directory), $realBase . DIRECTORY_SEPARATOR)
            && count((array) scandir($directory)) <= 2) { // only . and ..
            @rmdir($directory);
        }
    }

    public function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** The stored relative path for a bucket and file name. */
    public function relativeNameFor(string $bucket, string $name): string
    {
        return $this->bucketKey($bucket) . '/' . $name;
    }

    /**
     * Per-bucket subdirectory, so one feature's uploads cannot be confused
     * with another's and pruning one can never touch the other.
     */
    private function directoryFor(string $bucket): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->bucketKey($bucket);
    }

    /**
     * Directory name for a bucket.
     *
     * Hashed rather than the raw name so a caller-supplied bucket cannot shape
     * the path, and so the storage layout leaks nothing about how many buckets
     * or uploads exist. Stable, so an image is always re-filed to one place.
     */
    private function bucketKey(string $bucket): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $bucket) ?? '';
        if ($safe === '') {
            $safe = 'default';
        }

        return substr(sha1('image-upload-' . $safe), 0, 16);
    }

    /**
     * Fallback length measurement for a stream that reports no size.
     *
     * Reads in chunks up to one byte past the ceiling so an oversized file is
     * detected without buffering all of it into memory.
     */
    private function measureStream(StreamInterface $stream): int
    {
        $total = 0;
        $stream->rewind();
        while (!$stream->eof() && $total <= self::MAX_BYTES) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $total += strlen($chunk);
        }

        return $total;
    }

    /**
     * Sniff the real content type from the first bytes of the file.
     *
     * The browser's `Content-Type` is only a fallback for shared hosting with
     * no `fileinfo` extension — and even then it is checked against the
     * allowlist, so a `text/x-php` upload is refused either way.
     */
    private function sniffMime(StreamInterface $stream, ?string $clientMediaType = null): string
    {
        $stream->rewind();

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $head = (string) $stream->read(4096);
                $detected = @finfo_buffer($finfo, $head);
                @finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return strtolower(trim(explode(';', $detected)[0]));
                }
            }
        }

        return strtolower(trim(explode(';', (string) $clientMediaType)[0]));
    }

    /** Client filename, reduced to safe display text with the extension dropped. */
    private function clientName(UploadedFileInterface $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientFilename()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            return 'image';
        }

        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $stem = trim($stem);

        return mb_substr($stem === '' ? 'image' : $stem, 0, 80);
    }

    private function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'ছবিটি বড়। সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize(self::MAX_BYTES),
            ),
            UPLOAD_ERR_NO_FILE => 'ছবিটি পাওয়া যায়নি। আবার নির্বাচন করুন।',
            UPLOAD_ERR_PARTIAL => 'ছবিটি আংশিক আপলোড হয়েছে। আবার চেষ্টা করুন।',
            default => 'ছবি আপলোডে সমস্যা হয়েছে। আবার চেষ্টা করুন।',
        };
    }

    /**
     * Factory used by the DI container. The base path is derived from the
     * project root so it works the same whether the app runs from the repo
     * (development) or a deployment directory (production).
     */
    public static function fromProjectRoot(): self
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/web/image-uploads';

        if (!is_dir($path)) {
            @mkdir($path, 0o750, true);
        }

        return new self($path);
    }
}
