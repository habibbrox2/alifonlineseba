<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\ReferralRepository;
use App\Repository\UserRepository;
use App\Service\ReferralService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET /referrals — the user's own "বন্ধুকে রেফার করে বোনাস পান" page.
 *
 * The page exists to make one number impossible to misread: how much has been
 * earned, how much is still pending, and what the friend has left to do to
 * release it. Everything the user needs to share is on it, because a referral
 * page that makes people leave to find their code gets abandoned.
 */
final readonly class ReferralsAction
{
    private const PER_PAGE = 10;

    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private ReferralRepository $referrals,
        private ReferralService $service,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $page = max(1, (int) ($route->getArgument('page', '1')));

        // Minted lazily — an account created before the referral column
        // existed has no code until it actually visits this page.
        $code = $this->users->ensureReferralCode($identity->id);
        $data = $this->referrals->forReferrer($identity->id, $page, self::PER_PAGE);

        // `remaining` and the percent are derived here rather than stored, so
        // the progress bar on the page can never disagree with the two numbers
        // it is drawn from.
        foreach ($data['rows'] as $index => $row) {
            $required = max(1, (int) ($row['required_count'] ?? 1));
            $completed = max(0, (int) ($row['completed_count'] ?? 0));
            $data['rows'][$index]['required_count'] = $required;
            $data['rows'][$index]['completed_count'] = $completed;
            $data['rows'][$index]['remaining'] = max(0, $required - $completed);
            $data['rows'][$index]['progress'] = (int) min(100, round($completed / $required * 100));
        }

        return $this->view->render('site/account/referrals.twig', [
            'code' => $code,
            'shareLink' => $this->service->shareLink($identity->id),
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'summary' => $this->referrals->summaryFor($identity->id),
            'referrerBonus' => $this->service->referrerBonus(),
            'refereeBonus' => $this->service->refereeBonus(),
            'requiredRecharges' => $this->service->requiredRecharges(),
            'minRecharge' => $this->service->minQualifyingRecharge(),
            'summaryText' => $this->service->summaryText(),
            'terms' => $this->service->terms(),
            'enabled' => $this->service->isEnabled(),
        ]);
    }
}
