<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Service\ReceiptStorage;
use App\Service\TopupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/recharges/{id} — the reviewer's desk for a single request.
 *
 * The queue (`/admin/topups`) shows *that* a payment is waiting; this page is
 * where the actual decision is made, because approving a recharge is only
 * legitimate once a human has compared the TrxID and amount against the
 * payment receipt.
 *
 * Opening the page is therefore itself an act of work: it moves the request
 * from `pending` to `review` and records who took it, so a request that was
 * approved without ever being opened here is a process failure worth being able
 * to detect, and a second admin sees the claim instead of re-verifying the same
 * screenshot. `release` hands it back when the verifier turns out to be the
 * wrong person for it.
 */
final readonly class AdminRechargeAction
{
    public function __construct(
        private WebViewRenderer $view,
        private TopupService $service,
        private TopupRepository $topups,
        private UserRepository $users,
        private ReceiptStorage $receipts,
        private SettingsRepository $settings,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $id = (int) $route->getArgument('id', '0');

        $topup = $this->topups->findById($id);
        if ($topup === null) {
            $this->session->set('flash_error', 'রিচার্জ অনুরোধ পাওয়া যায়নি।');
            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/topups']);
        }

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $note = trim((string) ($input['admin_note'] ?? ''));

            [$ok, $message] = match ((string) ($input['do'] ?? '')) {
                'approve' => $this->service->approve($id, $identity->id, $note),
                'reject' => $this->service->reject(
                    $id,
                    $identity->id,
                    trim((string) ($input['reason'] ?? '')),
                    $note,
                ),
                'release' => $this->service->release($id, $identity->id),
                default => [false, 'অজানা অ্যাকশন।'],
            };

            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);
            // A failed decision (e.g. a reject with no reason) has to come back
            // here with the form still filled in, not bounce to the queue.
            $target = $ok ? '/admin/topups' : '/admin/recharges/' . $id;
            return new \Nyholm\Psr7\Response(302, ['Location' => $target]);
        }

        // The reviewer is looking at the evidence right now — take the request
        // out of the unclaimed pile and put their name on it.
        if ($this->service->claim($id, $identity->id) !== null) {
            $topup = $this->topups->findById($id) ?? $topup;
        }

        return $this->view->render('site/admin/recharge.twig', [
            'topup' => $topup,
            'user' => $this->users->findById((int) $topup['user_id']),
            'method' => SettingsRepository::METHOD_LABELS[$topup['method']] ?? (string) $topup['method'],
            'hasReceipt' => $this->receipts->absolutePath($topup['receipt_path'] ?? null) !== null,
            'receiptSize' => $topup['receipt_size'] !== null
                ? $this->receipts->humanSize((int) $topup['receipt_size'])
                : null,
            'isImage' => $this->receipts->isViewable((string) ($topup['receipt_mime'] ?? '')),
            'reviewer' => $this->users->findById($topup['reviewed_by'] !== null ? (int) $topup['reviewed_by'] : 0),
            'claimer' => $this->users->findById($topup['claimed_by'] !== null ? (int) $topup['claimed_by'] : 0),
            'isClaimedByMe' => $topup['claimed_by'] !== null && (int) $topup['claimed_by'] === $identity->id,
        ]);
    }
}
