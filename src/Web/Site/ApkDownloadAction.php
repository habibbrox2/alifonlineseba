<?php

declare(strict_types=1);

namespace App\Web\Site;

use App\Service\AppReleaseService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * GET /app/apk — streams the published APK.
 *
 * ## Why this is an action and not a file in `public/`
 *
 * The bytes live in `web/releases/`, outside the document root, exactly as
 * `web/deliverables/` does for a user's uploaded NID printout. That is the whole
 * point: an APK in the web root would have a stable, guessable, shareable URL
 * that bypasses every check below — including the operator's off switch and
 * the release's minimum-version floor.
 *
 * ## Why the response headers are this particular way
 *
 *  - `application/vnd.android.package-archive` is what makes Android's
 *    installer treat the response as an installable package rather than a file
 *    to save. Getting it wrong produces a download that cannot be opened.
 *  - `nosniff` closes the gap where a browser ignores that Content-Type and
 *    re-interprets the bytes — the only remaining route from "file outside the
 *    web root" to "something the browser executes".
 *  - `Content-Disposition: attachment` means a browser on a desktop saves the
 *    file instead of trying to render it.
 *  - `X-Apk-Sha256` is not decoration: the in-app updater hashes what it
 *    downloaded and compares it against the value the version endpoint gave
 *    it, which closes the gap between "the server said download this" and
 *    "these are the bytes the server meant".
 *
 * ## Why an absent release is a 404, not a 503
 *
 * `current()` conflates three ordinary states — nothing published, the file
 * pruned, the path unusable — into null on purpose. All three mean the same
 * thing to a client: there is nothing here right now, try later. Distinguishing
 * them would leak operator state to anyone hitting the URL.
 */
final readonly class ApkDownloadAction
{
    public function __construct(
        private AppReleaseService $app,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if (!$this->app->downloadsEnabled()) {
            return $this->textResponse(404, 'The Android app is not available for download right now.');
        }

        $release = $this->app->current();
        if ($release === null) {
            return $this->textResponse(404, 'No Android release is published right now.');
        }

        // `current()` already resolved and vetted the path; resolving it again
        // is what turns the vetted value into a stream handle, and it is the
        // only way to get one without trusting the row a second time.
        $absolute = $this->app->absolutePath((string) $release['apk_path']);
        if ($absolute === null || !is_readable($absolute)) {
            return $this->textResponse(404, 'No Android release is published right now.');
        }

        try {
            $stream = $this->streamFactory->createStreamFromFile($absolute);
            $size = filesize($absolute);
        } catch (\Throwable) {
            // A file that disappeared between the check and the open is still
            // "no release", never a 500 on the marketing page's main link.
            return $this->textResponse(404, 'No Android release is published right now.');
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/vnd.android.package-archive')
            ->withHeader('Content-Length', (string) ($size === false ? 0 : $size))
            ->withHeader(
                'Content-Disposition',
                sprintf('attachment; filename="%s"', $this->filename($release)),
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Apk-Sha256', (string) $release['sha256'])
            // Short cache only. A release can be withdrawn at any moment, and a
            // cached body would keep serving an APK the operator has retired.
            ->withHeader('Cache-Control', 'public, max-age=600, must-revalidate')
            ->withBody($stream);
    }

    /**
     * A filename a `Content-Disposition` header cannot be broken out of.
     *
     * Built from the version rather than `apk_path`: an operator-supplied path
     * is not a filename, and putting one in a response header is a header
     * injection waiting to happen.
     */
    private function filename(array $release): string
    {
        $name = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '',
            (string) $release['version_name'],
        ) ?: 'app';

        return 'alif-tools-' . $name . '.apk';
    }

    private function textResponse(int $status, string $message): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streamFactory->createStream($message));
    }
}
