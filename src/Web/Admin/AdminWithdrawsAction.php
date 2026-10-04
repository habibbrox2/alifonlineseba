<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\AdminWithdrawRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\AdminWithdrawService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/withdraws/{id} — the super-admin's desk for one payout.
 *
 * The payout queue is at `/admin/withdraws`; this is where the decision is
 * made, because approving a payout is only legitimate once a human has read the
 * destination account number and compared it with the admin who asked for it.
 * Those two things sit next to each other on screen here for the same reason
 * the receipt does on the recharge desk: the comparison is the job.
 *
 * Opening the page claims the request, exactly as the order desk does. Two
 * super-admins approving the same payout is the one mistake this screen cannot
 * afford, and the claim is what makes it impossible rather than unlikely.
 *
 * The rejection reason is mandatory. A bare "rejected" tells the operator
 * nothing about what to fix, and they will submit again five minutes later with
 * the same account number.
 *
 * The requester cannot approve their own request — refused by
 * {@see AdminWithdrawService::approve()} — and the form says so up front rather
 * than letting the operator discover it by pressing a button.
 */
final readonly class AdminWithdrawsAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private AdminWithdrawRepository $withdraws,
        private AdminWithdrawService $service,
        private TransactionRepository $ledger,
        private UserRepository $users,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        // `/admin/withdraws` (no id) is the queue; `/admin/withdraws/{id}` is
        // one desk. One action, because they share every rule that matters —
        // the claim, the status whitelist, who may decide.
        $rawId = (string) $route->getArgument('id', '');
        if ($rawId === '') {
            return $this->queue($request);
        }

        return $this->desk($request, $identity, (int) $rawId);
    }

    private function queue(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');
        $q = trim((string) ($params['q'] ?? ''));
        $sort = (string) ($params['sort'] ?? 'created_at');
        $dir = (string) ($params['dir'] ?? 'desc');

        $data = $this->withdraws->adminList($page, self::PER_PAGE, $status, $q, $sort, $dir);

        return $this->view->render('site/admin/withdraws.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'q' => $q,
            'sort' => $sort,
            'dir' => $dir,
            'stats' => $this->withdraws->stats(),
            'statuses' => AdminWithdrawRepository::STATUSES,
            'methods' => AdminWithdrawRepository::METHODS,
        ]);
    }

    private function desk(ServerRequestInterface $request, Identity $identity, int $id): ResponseInterface
    {
        $row = $this->withdraws->findById($id);
        if ($row === null) {
            $this->session->set('flash_error', 'উত্তোলন অনুরোধ পাওয়া যায়নি।');

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/withdraws']);
        }

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();

            [$ok, $message] = match ((string) ($input['do'] ?? '')) {
                'approve' => $this->service->approve($id, $identity),
                'reject' => $this->service->reject($id, $identity, trim((string) ($input['reason'] ?? ''))),
                'release' => $this->service->release($id, $identity),
                default => [false, 'অজানা অ্যাকশন।'],
            };

            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);
            $target = $ok ? '/admin/withdraws' : '/admin/withdraws/' . $id;

            return new \Nyholm\Psr7\Response(302, ['Location' => $target]);
        }

        // Looking at it is the start of reviewing it.
        $this->service->claim($id, $identity);
        $row = $this->withdraws->findById($id) ?? $row;

        $admin = $this->users->findById((int) $row['admin_id']);

        return $this->view->render('site/admin/withdraw-desk.twig', [
            'row' => $row,
            'admin' => $admin,
            'adminBalance' => (float) ($admin['balance'] ?? 0),
            // The admin's own earnings, so the reviewer can check the payout
            // against what was actually earned rather than against a number
            // they were told over the phone.
            'ledgerTotals' => $this->ledger->totalsForAdmin((int) $row['admin_id']),
            'isOwn' => (int) $row['admin_id'] === $identity->id,
            'isOpen' => in_array(
                (string) $row['status'],
                AdminWithdrawRepository::OPEN_STATUSES,
                true,
            ),
            'methodLabels' => AdminWithdrawService::METHOD_LABELS,
            'identity' => $identity,
        ]);
    }
}
