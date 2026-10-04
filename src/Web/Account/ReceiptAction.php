<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\TopupRepository;
use App\Service\ReceiptStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /recharge/receipt/{id} — streams a stored receipt back to the browser.
 *
 * This action is the *only* way a receipt ever leaves `web/receipts`. Because
 * the directory sits outside the document root, there is no URL to guess and
 * no static handler that could serve one, so authorisation is enforced here:
 * the owner may see their own, and admins/staff may see any (that is what
 * makes manual verification possible at all).
 *
 * `nosniff` is not optional here. Without it a browser may ignore the
 * `Content-Type` we send and re-interpret an uploaded file — the one way a
 * file that lives outside the web root could still turn into something the
 * browser executes.
 */
final readonly class ReceiptAction
{
    public function __construct(
        private TopupRepository $topups,
        private ReceiptStorage $receipts,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $id = (int) $route->getArgument('id', '0');

        $topup = $this->topups->findById($id);
        $isOwner = $topup !== null && (int) $topup['user_id'] === $identity->id;

        // Same response for "not yours" and "does not exist" so the endpoint
        // cannot be used to probe which top-up ids exist.
        if ($topup === null || (!$isOwner && !$identity->canAccessAdmin())) {
            return $this->textResponse(404, 'রশিদ পাওয়া যায়নি।');
        }

        $absolute = $this->receipts->absolutePath($topup['receipt_path'] ?? null);
        if ($absolute === null) {
            return $this->textResponse(404, 'রশিদ ফাইলটি আর পাওয়া যাচ্ছে না।');
        }

        $mime = (string) ($topup['receipt_mime'] ?? 'application/octet-stream');
        $downloadName = (string) ($topup['receipt_name'] ?? 'receipt');
        $disposition = $this->receipts->isViewable($mime) ? 'inline' : 'attachment';

        // Serve a stamped copy of image receipts. The stored original is
        // untouched — this only rewrites what goes over the wire, so a
        // screenshot lifted out of this page still identifies the request it
        // belongs to when it is pasted somewhere else. `watermark()` returns
        // null for PDFs, missing GD or any failure to render, and the original
        // is then served exactly as before.
        if ($this->receipts->isViewable($mime)) {
            $stamped = $this->receipts->watermark(
                $absolute,
                $this->watermarkLabel($topup, $identity),
            );
            if ($stamped !== null) {
                $absolute = $stamped;
            }
        }

        // A missing/unreadable file throws; turn that into the same 404 rather
        // than a 500, so a pruned file never looks like a server fault.
        try {
            $stream = $this->streamFactory->createStreamFromFile($absolute);
            $size = filesize($absolute);
        } catch (\Throwable) {
            return $this->textResponse(404, 'রশিদ পড়া যায়নি।');
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) ($size === false ? 0 : $size))
            ->withHeader(
                'Content-Disposition',
                sprintf('%s; filename="%s"', $disposition, $this->safeFilename($downloadName)),
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    private function textResponse(int $status, string $message): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($message));
    }

    /** Keep the download name to something a header cannot be broken with. */
    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $name) ?? 'receipt';

        return mb_substr($name === '' ? 'receipt' : $name, 0, 80);
    }

    /**
     * Text burned into the served copy.
     *
     * Latin-only, ASCII, and short on purpose: GD's bundled bitmap font cannot
     * render Bengali, and the stamp has to survive a screenshot of a screenshot.
     * The request id plus the TrxID is the part that matters — it is what makes
     * a lifted screenshot traceable back to the request it came from.
     */
    private function watermarkLabel(array $topup, Identity $identity): string
    {
        $parts = ['ALL SEBA', 'TOPUP #' . (int) $topup['id']];

        $reference = strtoupper(trim((string) ($topup['reference'] ?? '')));
        if ($reference !== '' && preg_match('/^[A-Z0-9\-]{4,64}$/', $reference) === 1) {
            $parts[] = $reference;
        }

        // Stamped from the request's own submission time, not `now()`: the
        // label is part of the cache key in effect (the cached file is reused
        // until the original changes), so a clock-derived value would either
        // mint a new file per request or leave a stale date on screen.
        $parts[] = 'SUBMITTED ' . date('Y-m-d H:i', strtotime((string) ($topup['created_at'] ?? '')) ?: time());
        $parts[] = $identity->canAccessAdmin() ? 'ADMIN COPY' : 'USER COPY';

        return implode(' | ', $parts);
    }
}
