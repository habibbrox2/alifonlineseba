<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Stores the result file an admin attaches to a finished service request
 * (the NID printout, a birth-certificate scan, a signature file, …).
 *
 * It is deliberately a near-copy of `ReceiptStorage` rather than a shared
 * base class, because the two differ in ways that matter and would leak
 * through a parent:
 *
 * - Receipts are *evidence of a payment* — anyone might want to replay them, so
 *   `ReceiptStorage` can watermark the copy it serves. A deliverable is the
 *   thing the user paid for; stamping it would corrupt the artifact they are
 *   entitled to receive in the clear.
 * - Receipt uploads come from the public and are capped tightly. Deliverables
 *   come from a trusted admin and are often multi-page PDFs, so the ceiling is
 *   higher.
 * - They live in separate directories so pruning one can never touch the other.
 *
 * The security rules are otherwise identical and are the point of the class:
 *
 * 1. Files are NEVER written inside `public/`. A government ID result sitting
 *    in the web root is reachable by anyone who guesses (or crawls) a URL, and
 *    it is only ever read back through `DeliverableAction`, which re-checks
 *    ownership.
 * 2. The client-supplied filename is never used as a path. The stored name is
 *    `random bytes + a whitelisted extension`, so `../../config/.env` and
 *    `shell.php` are both impossible. The original name is kept in the database
 *    purely as display text.
 * 3. The real MIME type is sniffed from the file contents with `finfo` rather
 *    than trusted from the browser's `Content-Type`, and the extension is
 *    derived from the sniffed type — so a `.php` upload renamed to `.jpg` is
 *    still stored under an image extension and never inside the web root.
 */
final class DeliverableStorage
{
    /**
     * Hard ceiling on a single deliverable.
     *
     * Larger than a receipt because a real result is often a multi-page PDF,
     * but still bounded: this is a scanned document, not a file transfer
     * service, and an unbounded upload is a trivial way to fill the disk.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Allowed extensions for the upload widget (client-side hint only). */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'];

    /**
     * Sniffed MIME type => extension we are willing to store it under.
     * Deliberately not the browser's Content-Type.
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    public function __construct(private readonly string $basePath) {}

    /** Absolute path of the directory holding all deliverables. */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Validate and persist an uploaded deliverable.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     *
     * @throws RuntimeException with a Bengali, admin-facing message when the
     *                           upload is unusable. The caller shows it as a
     *                           form error rather than a 500 — the file simply
     *                           did not save, and the status change the admin
     *                           was trying to do should still be possible.
     */
    public function store(UploadedFileInterface $file, int $transactionId): array
    {
        $originalName = $this->clientName($file);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->errorMessage((int) $file->getError()));
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new RuntimeException('ফাইলটি খালি। আবার চেষ্টা করুন।');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'ফাইলের সাইজ %s — সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize((int) $size),
                $this->humanSize(self::MAX_BYTES),
            ));
        }

        // A stream of length 0 that reports no error means PHP never buffered it.
        $stream = $file->getStream();
        if ($stream === null || !$stream->isReadable()) {
            throw new RuntimeException('ফাইলটি পড়া যায়নি। আবার চেষ্টা করুন।');
        }

        $mime = $this->sniffMime($stream, $file->getClientMediaType());
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('শুধু JPG, PNG, WEBP, PDF বা TXT ফরম্যাটের ফাইল গ্রহণযোগ্য।');
        }

        $directory = $this->directoryFor($transactionId);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('ফাইল সংরক্ষণের ফোল্ডার তৈরি করা যায়নি।');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . DIRECTORY_SEPARATOR . $name;

        // moveTo() goes through move_uploaded_file() for a genuine HTTP upload
        // (which refuses a path PHP never registered) and falls back to a stream
        // copy only when running under a SAPI without that check.
        try {
            $file->moveTo($target);
        } catch (\Throwable) {
            @unlink($target);
            throw new RuntimeException('ফাইলটি সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
        }

        if (!is_file($target)) {
            throw new RuntimeException('ফাইলটি সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।');
        }

        @chmod($target, 0o640);

        return [
            'path' => $this->relativeNameFor($transactionId, $name),
            'name' => $originalName,
            'mime' => $mime,
            'size' => (int) $size,
        ];
    }

    /**
     * Absolute path for a stored deliverable, or null when the relative path is
     * missing or tries to escape the base directory.
     *
     * The `..` rejection is the important part: `path` comes from the database,
     * but a defence-in-depth check here means a corrupted or hand-edited row
     * still cannot read a file outside `web/deliverables`.
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

    /**
     * Whether a deliverable is a type the browser can render inline (vs. download).
     *
     * Text is deliberately excluded: a `.txt` is attacker-influenced content and
     * `text/plain` served without a charset can be sniffed as HTML by some
     * browsers. `nosniff` plus an attachment disposition makes that a non-issue.
     */
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

    /** The stored relative path for a given request id and file name. */
    public function relativeNameFor(int $transactionId, string $name): string
    {
        return $this->directoryKey($transactionId) . '/' . $name;
    }

    /**
     * Delete a stored deliverable.
     *
     * Called when an admin replaces or removes one — the old bytes must not
     * linger, because a stale ID result sitting on disk is data about somebody
     * that nothing in the app can now reach or revoke.
     */
    public function delete(?string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);
        if ($absolute !== null) {
            @unlink($absolute);
        }

        // Tidy up the now-possibly-empty per-request directory too, so a
        // long-lived install does not accumulate one empty folder per request.
        if ($relativePath === null || !str_contains($relativePath, '/')) {
            return;
        }
        $directory = $this->basePath . DIRECTORY_SEPARATOR . dirname(str_replace('\\', '/', $relativePath));
        $real = realpath($directory);
        $realBase = realpath($this->basePath);
        if ($real === false || $realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return;
        }
        if (is_dir($real) && count((array) scandir($real)) <= 2) { // only . and ..
            @rmdir($real);
        }
    }

    /** Per-request subdirectory, so one request's files cannot be confused with another's. */
    private function directoryFor(int $transactionId): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->directoryKey($transactionId);
    }

    /**
     * Directory name for a transaction id.
     *
     * Hashed rather than the raw id so the storage layout leaks nothing about
     * volume, and stable so a deliverable is always re-filed to the same place.
     */
    private function directoryKey(int $transactionId): string
    {
        return substr(sha1('deliverable-' . $transactionId), 0, 16);
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
            return 'deliverable';
        }

        // Keep the extension off; the stored extension is derived from the MIME type.
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);

        return mb_substr($stem === '' ? 'deliverable' : $stem, 0, 80) . '.' . $this->extFromName($name);
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
                'ফাইলটি বড়। সর্বোচ্চ %s পর্যন্ত করা যাবে।',
                $this->humanSize(self::MAX_BYTES),
            ),
            UPLOAD_ERR_NO_FILE => 'ফাইলটি পাওয়া যায়নি। আবার নির্বাচন করুন।',
            UPLOAD_ERR_PARTIAL => 'ফাইলটি আংশিক আপলোড হয়েছে। আবার চেষ্টা করুন।',
            default => 'ফাইল আপলোডে সমস্যা হয়েছে। আবার চেষ্টা করুন।',
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
        $path = $root . '/web/deliverables';

        if (!is_dir($path)) {
            @mkdir($path, 0o750, true);
        }

        return new self($path);
    }
}
