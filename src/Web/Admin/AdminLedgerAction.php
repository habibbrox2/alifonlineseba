<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\AdminWithdrawService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/ledger — an operator's earnings, and their own withdrawal.
 *
 * Two audiences on one page, split by role:
 *
 * - Any admin sees **their own** ledger: every order they approved, every
 *   taka it paid, every withdrawal they took, and the running balance on both
 *   sides of each entry. That is the answer to "why does my balance say this?"
 *   without an admin having to ask anybody.
 * - A super-admin adds `scope=all`, which is the platform-wide ledger and each
 *   staff member's totals. Nothing is hidden from an admin about their own
 *   money; the `all` view is about *other people's*, which is why it is gated.
 *
 * The withdrawal form lives here rather than on its own route because the
 * decision and the statement are the same question — "I earned this, can I have
 * it" — and splitting them across two pages means one of them is always open
 * and the other is not.
 *
 * Note what the withdrawal form does *not* have: no "admin credited" button.
 * An admin's balance only grows when they approve an order, because that is
 * the only event that earns them money. A manual credit would make the ledger
 * unfalsifiable, which is the entire value of it.
 */
final readonly class AdminLedgerAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private WebViewRenderer $view,
        private TransactionRepository $ledger,
        private UserRepository $users,
        private AdminWithdrawService $withdraws,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $params = $request->getQueryParams();

        if ($request->getMethod() === 'POST') {
            [$ok, $message] = $this->withdraws->request($identity->id, (array) $request->getParsedBody());
            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/ledger']);
        }

        $page = max(1, (int) ($params['page'] ?? '1'));
        $type = (string) ($params['type'] ?? '');
        $q = trim((string) ($params['q'] ?? ''));

        // The platform-wide view is a super-admin capability, and it is checked
        // here rather than only by the route, because this same action serves
        // both scopes. A hand-typed `?scope=all` by an ordinary admin falls
        // back to their own ledger instead of being refused — the page they
        // asked for is the page they get, with their own numbers on it.
        $wantAll = $identity->isSuperAdmin() && (string) ($params['scope'] ?? '') === 'all';

        $data = $wantAll
            ? $this->ledger->all($page, self::PER_PAGE, $type, $q)
            : $this->ledger->forAdmin($identity->id, $page, self::PER_PAGE, $type);

        $withdrawHistory = $this->withdraws->hasOpenRequest($identity->id)
            ? $this->listWithdraws($identity->id)
            : [];

        // Re-read the balance rather than trusting `Identity::balance`, which
        // was a snapshot taken when the session was established — this page is
        // specifically the one where "what do I actually have" is asked.
        $fresh = $this->users->findById($identity->id);

        return $this->view->render('site/admin/ledger.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'type' => $type,
            'q' => $q,
            'types' => TransactionRepository::TYPES,
            'scope' => $wantAll ? 'all' : 'mine',
            'balance' => (float) ($fresh['balance'] ?? 0),
            'totals' => $wantAll ? [] : $this->ledger->totalsForAdmin($identity->id),
            'earnings' => $wantAll ? $this->ledger->adminEarnings() : [],
            'withdrawals' => $withdrawHistory,
            'hasOpenWithdrawal' => $this->withdraws->hasOpenRequest($identity->id),
            'minAmount' => $this->withdraws->minAmount(),
            'methods' => \App\Repository\AdminWithdrawRepository::METHODS,
            'identity' => $identity,
        ]);
    }

    /** @return array<int, array<string, mixed>> the operator's own requests, newest first. */
    private function listWithdraws(int $adminId): array
    {
        $rows = [];
        foreach ($this->withdraws->forAdmin($adminId, 1, 5)['rows'] as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}
