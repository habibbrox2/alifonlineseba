<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\DeliverableStorage;
use App\Service\ServiceRequestAdminService;
use App\Service\StatusPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/transactions/{id} — the operator's desk for one service
 * request: settle its status and hand the finished file to the user.
 *
 * Until this page existed the admin transactions list was a read-only log. That
 * is the gap this fills: the user submits a request and can start it themselves,
 * but nobody could mark it done or give them the result. The user sitting on
 * their history page would wait indefinitely for a transition that only they
 * were allowed to make.
 *
 * POST uses one endpoint with a `do` discriminator rather than separate routes,
 * because the three things an admin does here — change status, attach a file,
 * remove a file — are not independent and are usually done in one sitting. They
 * share a flash message and a redirect back here, so the operator keeps their
 * place and the state they were looking at is re-rendered fresh.
 *
 * The status form and the upload form are separate `<form>` elements on purpose.
 * An upload is a multipart body; posting a status change through it would mean
 * re-sending the file (or losing it) on every status tweak, and PHP's
 * `post_max_size` would then apply to a plain dropdown change.
 */
final readonly class AdminTransactionAction
{
    public function __construct(
        private WebViewRenderer $view,
        private TransactionRepository $transactions,
        private UserRepository $users,
        private ServiceRequestAdminService $service,
        private DeliverableStorage $deliverables,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $id = (int) $route->getArgument('id', '0');

        $row = $this->transactions->findById($id);
        if ($row === null) {
            $this->session->set('flash_error', 'সার্ভিস অনুরোধ পাওয়া যায়নি।');
            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/transactions']);
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $identity, $id);
        }

        return $this->render($row, $identity);
    }

    private function handlePost(ServerRequestInterface $request, Identity $identity, int $id): ResponseInterface
    {
        $input = (array) $request->getParsedBody();

        [$ok, $message] = match ((string) ($input['do'] ?? '')) {
            'status' => $this->service->setStatus(
                $id,
                (string) ($input['status'] ?? ''),
                $identity,
                trim((string) ($input['admin_note'] ?? '')),
            ),
            'attach' => $this->attach($request, $identity, $id),
            'detach' => $this->service->detachDeliverable($id, $identity),
            default => [false, 'অজানা অ্যাকশন।'],
        };

        $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

        // A failed action returns here rather than to the list, because the
        // operator's next move is almost always a correction on the same page.
        $target = $ok ? '/admin/transactions' : '/admin/transactions/' . $id;

        return new \Nyholm\Psr7\Response(302, ['Location' => $target]);
    }

    /**
     * Store an uploaded deliverable.
     *
     * A missing `file` key is treated as "no file chosen" rather than an error:
     * an empty file input submits with `error = UPLOAD_ERR_NO_FILE` and an empty
     * name, and turning that into a red error box for what is really a
     * mis-click is noise.
     *
     * @return array{0: bool, 1: string}
     */
    private function attach(ServerRequestInterface $request, Identity $identity, int $id): array
    {
        $file = $request->getUploadedFiles()['deliverable'] ?? null;

        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return [false, 'কোনো ফাইল নির্বাচন করা হয়নি।'];
        }

        return $this->service->attachDeliverable($id, $file, $identity);
    }

    private function render(array $row, Identity $identity): ResponseInterface
    {
        $metadata = TransactionRepository::metadata($row);
        $path = $row['deliverable_path'] ?? null;

        return $this->view->render('site/admin/transaction.twig', [
            'tx' => $row,
            'user' => $this->users->findById((int) $row['user_id']),
            'metadata' => $metadata,
            'resultEntries' => StatusPresenter::resultEntries(
                is_array($metadata['result'] ?? null) ? $metadata['result'] : null
            ),
            'statuses' => StatusPresenter::REQUEST_STATUSES,
            'hasFile' => $this->deliverables->absolutePath($path) !== null,
            'fileSize' => $row['deliverable_size'] !== null
                ? $this->deliverables->humanSize((int) $row['deliverable_size'])
                : null,
            'isImage' => $this->deliverables->isViewable((string) ($row['deliverable_mime'] ?? '')),
            'maxBytes' => DeliverableStorage::MAX_BYTES,
            'allowedExtensions' => DeliverableStorage::ALLOWED_EXTENSIONS,
            'identity' => $identity,
        ]);
    }
}
