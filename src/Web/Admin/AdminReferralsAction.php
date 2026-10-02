<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ReferralRepository;
use App\Service\ReferralService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/referrals — the referral ledger and its payout controls.
 *
 * Normally nothing on this page is a decision: the moment a referred friend's
 * first recharge is approved, ReferralService pays both sides on the spot. The
 * manual actions exist for the cases where that automatic trigger cannot fire —
 * a recharge approved before the programme was switched on, a duplicate account
 * that needs voiding, or a mistaken rejection that has to be undone.
 *
 * Every action goes through ReferralService, which re-checks the row state under
 * a conditional UPDATE, so two admins clicking "পরিশোধ" at the same moment
 * produce exactly one payout rather than two.
 */
final readonly class AdminReferralsAction
{
    private const PER_PAGE = 15;

    public function __construct(
        private WebViewRenderer $view,
        private ReferralRepository $referrals,
        private ReferralService $service,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? '1'));
        $status = (string) ($params['status'] ?? '');
        $query = trim((string) ($params['q'] ?? ''));
        $sort = (string) ($params['sort'] ?? 'created_at');
        $dir = (string) ($params['dir'] ?? 'desc');

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $referralId = (int) ($input['referral_id'] ?? 0);

            [$ok, $message] = match ((string) ($input['do'] ?? '')) {
                'pay' => $this->service->payManually(
                    $referralId,
                    $identity->id,
                    trim((string) ($input['admin_note'] ?? '')),
                ),
                'reject' => $this->service->reject(
                    $referralId,
                    $identity->id,
                    trim((string) ($input['reason'] ?? '')),
                ),
                'reset' => $this->service->reset($referralId, $identity->id),
                default => [false, 'অজানা অ্যাকশন।'],
            };

            $this->session->set($ok ? 'flash_success' : 'flash_error', $message);

            // A rejected action has to come back to the list with the filter still
            // applied, so the admin can see which row they were actually working on.
            $qs = http_build_query([
                'status' => $status,
                'q' => $query,
                'sort' => $sort,
                'dir' => $dir,
                'page' => $page,
            ]);
            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/referrals?' . $qs]);
        }

        $data = $this->referrals->adminList($page, self::PER_PAGE, $status, $query, $sort, $dir);

        return $this->view->render('site/admin/referrals.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'query' => $query,
            'sort' => $sort,
            'dir' => $dir,
            'stats' => $this->referrals->stats(),
            'statusCounts' => $this->referrals->statusCounts(),
            'statuses' => ReferralRepository::STATUSES,
            'settingsUrl' => '/admin/settings',
            'enabled' => $this->service->isEnabled(),
            'referrerBonus' => $this->service->referrerBonus(),
            'refereeBonus' => $this->service->refereeBonus(),
            'minRecharge' => $this->service->minFirstRecharge(),
        ]);
    }
}
