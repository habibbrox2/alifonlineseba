<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Stores recharge receipt uploads.
 *
 * Two rules drive the whole design:
 *
 * 1. Receipts are NEVER written inside `public/`. A payment screenshot sitting
 *    in the web root is reachable by anyone who guesses (or crawls) a URL, and
 *    a bKash/Nagad receipt is exactly the sort of thing that must not be
 *    public. Files land in `web/receipts/`, outside the document root, and are
 *    read back only through `ReceiptAction`, which re-checks ownership.
 *
 * 2. The client-supplied filename is never used as a path. The stored name is
 *    `random bytes + a whitelisted extension`, so `../../config/.env` and
 *    `shell.php` are both impossible. The original name is kept in the
 *    database purely as display text.
 *
 * The real MIME type is sniffed from the file contents with `finfo` rather
 * than trusted from the browser's `Content-Type`, and the extension is derived
 * from the sniffed type, so a `.php` upload renamed to `.jpg` is still stored
 * under an image extension — and, crucially, never inside the web root.
 *
 * Image receipts are additionally served through `watermark()`, which renders a
 * stamped *copy*. The original is never modified: it is the evidence, and a
 * burned-in watermark could not be undone.
 */
final class ReceiptStorage
{
    /** Hard ceiling on a single receipt. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Allowed extensions for the upload widget (client-side hint only). */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /**
     * Sniffed MIME type => extension we are willing to store it under.
     * Deliberately not the browser's Content-Type.
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private readonly string $basePath) {}

    /** Absolute path of the directory holding all receipts. */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Validate and persist an uploaded receipt.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     *
     * @throws RuntimeException with a Bengali, user-facing message when the
     *                           upload is unusable. The caller shows it as a field error.
     */
    public function store(UploadedFileInterface $file, int $topupId): array
    {
        $originalName = $this->clientName($file);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->errorMessage((int) $file->getError()));
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new RuntimeException('রশিদ ফাইলটি খালি। আবার চেষ্টা করুন।');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'রশিদ ফাইলের সাইজ %s — সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize((int) $size),
                $this->humanSize(self::MAX_BYTES),
            ));
        }

        // A stream of length 0 that reports no error means PHP never buffered it.
        $stream = $file->getStream();
        if ($stream === null || !$stream->isReadable()) {
            throw new RuntimeException('রশিদ ফাইলটি পড়া যায়নি। আবার চেষ্টা করুন।');
        }

        $mime = $this->sniffMime($stream, $file->getClientMediaType());
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('শুধু JPG, PNG, WEBP বা PDF ফরম্যাটের রশিদ গ্রহণযোগ্য।');
        }

        $directory = $this->directoryFor($topupId);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('রশিদ সংরক্ষণের ফোল্ডার তৈরি করা যায়নি।');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . DIRECTORY_SEPARATOR . $name;

        // moveTo() goes through move_uploaded_file() for a genuine HTTP upload
        // (which refuses a path PHP never registered) and falls back to a
        // stream copy only when running under a SAPI without that check.
        try {
            $file->moveTo($target);
        } catch (\Throwable) {
            @unlink($target);
            throw new RuntimeException('রশিদ ফাইলটি সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
        }

        if (!is_file($target)) {
            throw new RuntimeException('রশিদ ফাইলটি সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
        }

        @chmod($target, 0o640);

        return [
            'path' => $this->relativeNameFor($topupId, $name),
            'name' => $originalName,
            'mime' => $mime,
            'size' => (int) $size,
        ];
    }

    /**
     * Absolute path for a stored receipt, or null when the relative path is
     * missing or tries to escape the base directory.
     *
     * The `..` rejection is the important part: `path` comes from the database,
     * but a defence-in-depth check here means a corrupted or hand-edited row
     * still cannot read a file outside `web/receipts`.
     */
    public function absolutePath(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $relativePath);
        if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            return null;
        }

        $absolute = $this->basePath . DIRECTORY_SEPARATOR . ltrim($normalized, '/');
        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            return null;
        }

        // realpath() has resolved every symlink; require it to still be under our base.
        $realBase = realpath($this->basePath);
        if ($realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /** Whether a receipt is a type the browser can render inline (vs. download). */
    public function isViewable(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    public function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /**
     * The stored relative path for a given request id and file name.
     *
     * Public because the caller needs it to re-file a receipt once the row
     * (and therefore the real top-up id) exists — see `relocate()`.
     */
    public function relativeNameFor(int $topupId, string $name): string
    {
        return $this->directoryKey($topupId) . '/' . $name;
    }

    /**
     * Move a stored receipt under the directory of its real top-up id.
     *
     * `store()` has to write the file before the DB row exists, so it files
     * under a placeholder id; once the insert returns the real id we move the
     * file and hand back the corrected relative path so the caller can persist
     * it. Returns the original path on any failure — the file stays readable
     * exactly where it is, so a relocation problem can never lose a receipt.
     */
    public function relocate(string $relativePath, int $topupId): string
    {
        $from = $this->absolutePath($relativePath);
        if ($from === null) {
            return $relativePath;
        }

        $target = $this->relativeNameFor($topupId, basename($from));
        if ($target === $relativePath) {
            return $relativePath; // already filed correctly
        }

        $to = $this->basePath . DIRECTORY_SEPARATOR . $target;
        $directory = dirname($to);
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            return $relativePath;
        }

        return @rename($from, $to) ? $target : $relativePath;
    }

    /** Delete a stored receipt. Used when a submission is abandoned. */
    public function delete(?string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);
        if ($absolute === null) {
            return;
        }

        // Derived watermark copies are never in the database, so they have to be
        // collected by pattern — otherwise every purged receipt leaks an image,
        // and there is one file per distinct stamp label.
        foreach ($this->watermarkCachePaths($absolute) as $watermark) {
            @unlink($watermark);
        }

        @unlink($absolute);
    }

    /**
     * A watermarked *copy* of a stored image receipt, built on demand and cached
     * beside the original. Returns null when the file cannot be watermarked
     * (PDF, or GD missing) and the original should be served untouched.
     *
     * Why a copy and not the file itself:
     *
     * - The upload is evidence. If we burned a watermark into it, every
     *   screenshot a user submits would be permanently degraded, and re-deriving
     *   the pristine image from a stamped one is impossible.
     * - The watermark is only useful to the person looking *at* the receipt. The
     *   owner seeing their own submission watermarked adds nothing.
     *
     * What it is for is copy-paste: a payment screenshot is trivial to lift out
     * of a chat and replay as somebody else's submission. A diagonal stamp
     * carrying the request id and the viewing context makes that replay
     * self-identifying to whoever opens it next.
     *
     * The text is deliberately Latin-only. GD's bundled bitmap font has no
     * Bengali glyphs, and depending on the host TTF availability we cannot
     * promise a Bengali font is present — a watermark that renders as a row of
     * empty boxes on a Linux box would be worse than an English one.
     */
    public function watermark(string $absolutePath, string $label): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecopy') || $label === '') {
            return null;
        }

        $cached = $this->watermarkCachePath($absolutePath, $label);
        if ($cached !== null && is_file($cached)) {
            return $cached;
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        unset($bytes);
        if ($source === false) {
            return null; // not a raster image (PDF, or a format GD lacks)
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            if ($width < 1 || $height < 1) {
                return null;
            }

            $this->stamp($source, $width, $height, $label);
            $encoded = $this->encode($source, $absolutePath);
        } finally {
            imagedestroy($source);
        }

        if ($encoded === null || $cached === null) {
            return null;
        }

        // Write via a temp file + rename so a concurrent request never reads a
        // half-written image (imagepng to the final path would be visible).
        $temporary = $cached . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $encoded) === false) {
            @unlink($temporary);
            return null;
        }
        if (!@rename($temporary, $cached)) {
            @unlink($temporary);
            return null;
        }
        @chmod($cached, 0o640);

        return $cached;
    }

    /**
     * The path of the derived watermark for a given original and stamp label.
     *
     * Derived from the original's own name plus a hash of the label rather than
     * stored, so it can be found again for deletion without adding another
     * column to the row. The `wm-` prefix makes it obvious in a directory
     * listing that this file is a by-product and never an upload.
     *
     * The label is part of the filename because the stamp legitimately differs
     * per viewer (an admin's copy says so, the owner's does not). Without the
     * hash in the name, whichever viewer rendered first would be served to
     * everybody after them.
     */
    private function watermarkCachePath(string $absolutePath, string $label): ?string
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        return $this->watermarkPrefix($absolutePath) . substr(sha1($label), 0, 8) . '.' . $extension;
    }

    /**
     * Every derived watermark that exists for a given original.
     *
     * @return string[]
     */
    private function watermarkCachePaths(string $absolutePath): array
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [];
        }

        $matches = glob($this->watermarkPrefix($absolutePath) . '*.' . $extension);

        return $matches === false ? [] : $matches;
    }

    /**
     * Common prefix of every watermark file derived from one original.
     *
     * The `wm-` prefix and the `.wm.` infix make these unmistakable in a
     * directory listing: a by-product, never an upload.
     */
    private function watermarkPrefix(string $absolutePath): string
    {
        return dirname($absolutePath) . DIRECTORY_SEPARATOR
            . 'wm-' . pathinfo($absolutePath, PATHINFO_FILENAME) . '.wm.';
    }

    /**
     * Draw the stamp: a repeating diagonal band plus a solid corner bar that
     * names the request, so the evidence is attributed even if the image is
     * cropped down to the middle.
     */
    private function stamp(\GdImage $image, int $width, int $height, string $label): void
    {
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $bandColor = imagecolorallocatealpha($image, 255, 255, 255, 78);
        $inkColor = imagecolorallocatealpha($image, 120, 120, 120, 55);

        // Tiled diagonal band across the whole image. Stepped by the text width
        // so successive lines do not overlap into an unreadable block.
        $font = 5; // bundled 9x15 bitmap — no font file needed
        $textWidth = imagefontwidth($font) * strlen($label);
        $textHeight = imagefontheight($font);

        $diagonal = (int) ceil(sqrt($width ** 2 + $height ** 2));
        $stepX = max($textWidth + 40, 120);

        for ($x = -$diagonal; $x < $diagonal + $width; $x += $stepX) {
            for ($offset = -$diagonal; $offset < $diagonal + $height; $offset += 60) {
                imageline($image, $x, $offset, $x + $height, $offset + $height, $bandColor);
                imagestring(
                    $image,
                    $font,
                    $x,
                    $offset + (int) (($height - $textWidth) / 2),
                    $label,
                    $inkColor,
                );
            }
        }

        // Corner bar, always inside the image even for a very small receipt.
        $barHeight = $textHeight + 8;
        $barWidth = min($width, max($textWidth + 12, 60));
        imagefilledrectangle($image, 0, 0, $barWidth, $barHeight, $bandColor);
        imagestring($image, $font, 6, 4, $label, $inkColor);
    }

    /**
     * Encode the stamped image, keeping the original format so the response's
     * `Content-Type` stays honest.
     */
    private function encode(\GdImage $image, string $absolutePath): ?string
    {
        ob_start();
        $ok = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => imagejpeg($image, null, 82),
            'webp' => function_exists('imagewebp') ? imagewebp($image, null, 82) : false,
            default => imagepng($image, null, 6),
        };
        $encoded = $ok ? (string) ob_get_contents() : false;
        ob_end_clean();

        return $encoded === false || $encoded === '' ? null : $encoded;
    }

    /** Per-request subdirectory, so one request's files cannot be confused with another's. */
    private function directoryFor(int $topupId): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->directoryKey($topupId);
    }

    /**
     * Directory name for a top-up id.
     *
     * Hashed rather than the raw id so the storage layout leaks nothing about
     * volume, and stable so a receipt is always re-filed to the same place.
     */
    private function directoryKey(int $topupId): string
    {
        return substr(sha1('topup-' . $topupId), 0, 16);
    }

    /**
     * Sniff the real content type from the first bytes of the file.
     *
     * The browser's `Content-Type` is only a fallback for shared hosting with
     * no `fileinfo` extension — and even then it is checked against the
     * allowlist, so a `text/x-php` upload is refused either way.
     */
    private function sniffMime(StreamInterface $stream, string $clientMediaType = ''): string
    {
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

        return strtolower(trim(explode(';', $clientMediaType)[0]));
    }

    /** Client filename, reduced to something safe to echo back as text. */
    private function clientName(UploadedFileInterface $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientFilename()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            return 'receipt';
        }

        // Keep the extension off; the stored extension is derived from the MIME type.
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);

        return mb_substr($stem === '' ? 'receipt' : $stem, 0, 80) . '.' . $this->extFromName($name);
    }

    private function extFromName(string $name): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return 'dat';
        }
        $ext = strtolower(substr($name, $dot + 1));
        return in_array($ext, self::ALLOWED_EXTENSIONS, true) ? $ext : 'dat';
    }

    private function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'রশিদ ফাইলটি বড়। সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize(self::MAX_BYTES),
            ),
            UPLOAD_ERR_NO_FILE => 'রশিদ ফাইলটি পাওয়া যায়নি। আবার নির্বাচন করুন।',
            UPLOAD_ERR_PARTIAL => 'রশিদ ফাইলটি আংশিক আপলোড হয়েছে। আবার চেষ্টা করুন।',
            default => 'রশিদ আপলোডে সমস্যা হয়েছে। আবার চেষ্টা করুন।',
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
        $path = $root . '/web/receipts';

        if (!is_dir($path)) {
            @mkdir($path, 0o750, true);
        }

        return new self($path);
    }
}
