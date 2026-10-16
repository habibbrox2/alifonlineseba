<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\TopupRepository;
use App\Service\Api;
use App\Service\ReceiptStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /api/admin/recharges/{id}/receipt — streams a stored receipt to the
 * admin app so a payment can be verified without leaving the phone.
 *
 * Mirror of App\Web\Account\ReceiptAction for the API: the files sit outside
 * the document root, so this action is the only way one ever leaves storage.
 *
 * `nosniff` is not optional here. Without it a browser may ignore the
 * `Content-Type` we send and re-interpret an uploaded file — the one way a
 * file that lives outside the web root could still turn into something the
 * browser executes.
 */
final readonly class AdminRechargeReceiptApiAction
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
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        // int-cast before anything touches storage: the id arrives from the URL.
        $id = (int) $route->getArgument('id', '0');
        $topup = $this->topups->findById($id);
        if ($topup === null) {
            return Api::fail('রশিদ পাওয়া যায়নি।', [], 404);
        }

        // Same 404 for "no receipt recorded" and "the file is gone": a row can
        // outlive its bytes on disk, and neither must look like a server fault.
        $absolute = $this->receipts->absolutePath($topup['receipt_path'] ?? null);
        if ($absolute === null) {
            return Api::fail('রশিদ ফাইলটি আর পাওয়া যাচ্ছে না।', [], 404);
        }

        $mime = trim((string) ($topup['receipt_mime'] ?? ''));
        $mime = $mime !== '' ? $mime : 'application/octet-stream';
        $downloadName = (string) ($topup['receipt_name'] ?? 'receipt');
        $disposition = $this->receipts->isViewable($mime) ? 'inline' : 'attachment';

        try {
            $stream = $this->streamFactory->createStreamFromFile($absolute);
            $size = filesize($absolute);
        } catch (\Throwable) {
            return Api::fail('রশিদ পড়া যায়নি।', [], 404);
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

    /** Keep the download name to something a header cannot be broken with. */
    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $name) ?? 'receipt';

        return mb_substr($name === '' ? 'receipt' : $name, 0, 80);
    }
}
