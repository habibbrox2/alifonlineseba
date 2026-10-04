<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
use App\Service\DeliverableStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /service-requests/{id}/file — streams the deliverable an admin attached
 * to a service request.
 *
 * This action is the *only* way a deliverable ever leaves `web/deliverables`.
 * Because the directory sits outside the document root there is no URL to guess
 * and no static handler that could serve one, so authorisation is enforced
 * here: the owner may download their own, and admins/staff any (that is what
 * makes checking a submitted file possible at all).
 *
 * Unlike a receipt, a deliverable is the thing the user paid for — an NID
 * result, a certificate. It is served verbatim: no watermark, no re-encoding.
 * Putting a stamp across somebody's government document to make it
 * self-identifying in a screenshot would be the wrong trade here; the file is
 * meant to be forwarded to whoever the user needs to show it to.
 *
 * `nosniff` is not optional. Without it a browser may ignore the `Content-Type`
 * we send and re-interpret the file — the one way a file living outside the web
 * root could still turn into something the browser executes.
 */
final readonly class DeliverableAction
{
    public function __construct(
        private ServiceOrderRepository $orders,
        private DeliverableStorage $deliverables,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $id = (int) $route->getArgument('id', '0');

        $row = $this->orders->findById($id);
        $isOwner = $row !== null && (int) $row['user_id'] === $identity->id;

        // Same response for "not yours" and "does not exist" so the endpoint
        // cannot be used to probe which service-request ids exist.
        if ($row === null || (!$isOwner && !$identity->canAccessAdmin())) {
            return $this->textResponse(404, 'ফাইল পাওয়া যায়নি।');
        }

        $absolute = $this->deliverables->absolutePath($row['deliverable_path'] ?? null);
        if ($absolute === null) {
            return $this->textResponse(404, 'ফাইলটি আর পাওয়া যাচ্ছে না।');
        }

        $mime = (string) ($row['deliverable_mime'] ?? 'application/octet-stream');
        $downloadName = (string) ($row['deliverable_name'] ?? 'deliverable');

        // Images are safe to render in place; everything else is forced to
        // download, so a `text/plain` result can never be sniffed into markup.
        $disposition = $this->deliverables->isViewable($mime) ? 'inline' : 'attachment';

        // A missing/unreadable file throws; turn that into the same 404 rather
        // than a 500, so a pruned file never looks like a server fault.
        try {
            $stream = $this->streamFactory->createStreamFromFile($absolute);
            $size = filesize($absolute);
        } catch (\Throwable) {
            return $this->textResponse(404, 'ফাইল পড়া যায়নি।');
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
        $name = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $name) ?? 'deliverable';

        return mb_substr($name === '' ? 'deliverable' : $name, 0, 80);
    }
}
