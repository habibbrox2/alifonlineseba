<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ServiceOrderRepository;
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
 * GET|POST /admin/orders/{id} — the operator's desk for one order.
 *
 * **Opening this page claims the order.** That is the whole locking mechanism,
 * and it is here rather than behind a button because opening the desk *is* the
 * act of reviewing it: by the time an admin has the customer's input on screen
 * they are working on it. A separate "ধরুন" button would only add a click
 * between two operators starting the same row at the same moment — which is
 * exactly the collision the claim exists to prevent.
 *
 * What the page then shows depends on who is reading it, and the difference is
 * the feature:
 *
 * - *Your* order: the full decision set — approve (which pays you), reject
 *   (which refunds the customer), mark processing, hand over a file.
 * - *Somebody else's* order: read-only, with their name on it. You may look at
 *   it, you may not decide it, and the buttons are simply absent rather than
 *   present-and-failing — a disabled approve on another admin's order teaches
 *   operators to click things that do not work.
 *
 * A claim that has gone stale (the holder closed their laptop) is taken over
 * automatically after {@see ServiceOrderRepository::claimOrder()}'s cutoff, so
 * a crashed operator does not strand a paid order in `review` forever.
 *
 * POST uses one endpoint with a `do` discriminator because the four things an
 * admin does here are not independent and are usually done in one sitting.
 *
 * The status form and the upload form are separate `<form>` elements on
 * purpose. An upload is a multipart body; posting a status change through it
 * would mean re-sending the file (or losing it) on every status tweak, and
 * PHP's `post_max_size` would then apply to a plain dropdown change.
 */
final readonly class AdminOrderAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceOrderRepository $orders,
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

        $row = $this->orders->findById($id);
        if ($row === null) {
            $this->session->set('flash_error', 'সার্ভিস অর্ডার পাওয়া যায়নি।');

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/orders']);
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $identity, $id);
        }

        // Reading the row is the work. Take the claim before rendering so the
        // *next* admin to open this URL sees it as taken.
        $this->service->claimOnOpen($id, $identity);
        $row = $this->orders->findById($id) ?? $row;

        return $this->render($row, $identity);
    }

    private function handlePost(ServerRequestInterface $request, Identity $identity, int $id): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        $note = trim((string) ($input['admin_note'] ?? ''));

        [$ok, $message] = match ((string) ($input['do'] ?? '')) {
            'approve' => $this->service->approve($id, $identity, $note),
            'reject' => $this->service->refund(
                $id,
                $identity,
                (string) ($input['status'] ?? StatusPresenter::CANCELLED),
                trim((string) ($input['reason'] ?? '')),
                $note,
            ),
            'release' => $this->service->release($id, $identity),
            'status' => $this->service->setStatus($id, (string) ($input['status'] ?? ''), $identity, $note),
            'attach' => $this->attach($request, $identity, $id),
            'detach' => $this->service->detachDeliverable($id, $identity),
            default => [false, 'অজানা অ্যাকশন।'],
        };

        $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

        // A failed action returns here rather than to the list, because the
        // operator's next move is almost always a correction on the same page.
        $target = $ok ? '/admin/orders' : '/admin/orders/' . $id;

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
        $metadata = ServiceOrderRepository::metadata($row);
        $claimedBy = $row['claimed_by'] === null ? null : (int) $row['claimed_by'];

        return $this->view->render('site/admin/order.twig', [
            'order' => $row,
            'user' => $this->users->findById((int) $row['user_id']),
            'metadata' => $metadata,
            'resultEntries' => StatusPresenter::resultEntries(
                is_array($metadata['result'] ?? null) ? $metadata['result'] : null,
            ),
            'statuses' => StatusPresenter::REQUEST_STATUSES,
            // The single decision that moves money. Everything else on this
            // page is either information or the evidence for this.
            'canDecide' => $claimedBy === null || $claimedBy === $identity->id,
            'claimer' => $claimedBy === null ? null : $this->users->findById($claimedBy),
            'approver' => $row['approved_by'] === null
                ? null
                : $this->users->findById((int) $row['approved_by']),
            'isOpen' => in_array(
                (string) $row['status'],
                ServiceOrderRepository::OPEN_STATUSES,
                true,
            ),
            'hasFile' => $this->deliverables->absolutePath($row['deliverable_path'] ?? null) !== null,
            'fileSize' => $row['deliverable_size'] !== null
                ? $this->deliverables->humanSize((int) $row['deliverable_size'])
                : null,
            'isImage' => $this->deliverables->isViewable((string) ($row['deliverable_mime'] ?? '')),
            'maxBytes' => DeliverableStorage::MAX_BYTES,
            'allowedExtensions' => DeliverableStorage::ALLOWED_EXTENSIONS,
            // A leading dot is required per the HTML spec for extension tokens;
            // browsers silently ignore a bare `pdf` and offer every file type.
            'acceptTypes' => '.' . implode(',.', DeliverableStorage::ALLOWED_EXTENSIONS),
            'identity' => $identity,
        ]);
    }
}
