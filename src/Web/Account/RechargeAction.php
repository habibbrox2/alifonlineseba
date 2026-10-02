<?php

declare(strict_types=1);

namespace App\Web\Account;

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
 * GET|POST /recharge — the user's recharge desk, in two steps like the
 * reference site: step 1 picks amount + method, step 2 shows the operator's
 * personal wallet number and takes the TrxID (+ optional receipt).
 *
 * Step 2 is a GET with query params (`?step=pay&method=bkash&amount=500`) so
 * it is bookmarkable and refresh-safe; the amounts in the URL are re-validated
 * server-side before anything is stored, never trusted for the insert.
 */
final readonly class RechargeAction
{
    private const PER_PAGE = 10;

    public function __construct(
        private WebViewRenderer $view,
        private TopupService $topups,
        private TopupRepository $repo,
        private SettingsRepository $settings,
        private ReceiptStorage $receipts,
        private UserRepository $users,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $params = $request->getQueryParams();
        $step = $params['step'] ?? 'form';

        if ($request->getMethod() === 'GET' && $step === 'pay') {
            return $this->payStep($request, $identity);
        }

        if ($request->getMethod() === 'POST') {
            return $this->submit($request, $identity);
        }

        return $this->formStep($request, $identity, $route);
    }

    /** Step 1: amount + method picker plus the request history. */
    private function formStep(ServerRequestInterface $request, Identity $identity, CurrentRoute $route): ResponseInterface
    {
        $errors = [];
        $form = [
            'amount' => (string) ($request->getQueryParams()['amount'] ?? ''),
            'method' => (string) ($request->getQueryParams()['method'] ?? 'bkash'),
        ];

        $page = max(1, (int) $route->getArgument('page', '1'));
        $status = (string) ($request->getQueryParams()['status'] ?? '');
        $data = $this->repo->forUser($identity->id, $page, self::PER_PAGE, $status);

        return $this->view->render('site/account/recharge.twig', [
            'identity' => $identity,
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'status' => $status,
            'form' => $form,
            'errors' => $errors,
            'methods' => SettingsRepository::METHOD_LABELS,
            'minAmount' => $this->topups->minAmount(),
            'maxAmount' => $this->topups->maxAmount(),
            'quickAmounts' => $this->quickAmounts(),
            'offerText' => trim($this->settings->get('topup_offer_text', '')),
            'notice' => $this->notice(),
            'hasPending' => $this->repo->forUser($identity->id, 1, 1, 'pending')['rows'] !== [],
            'statuses' => TopupRepository::STATUSES,
            'apiKey' => $this->users->ensureApiKey($identity->id),
            'freeSearches' => $this->users->freeSearches($identity->id),
        ]);
    }

    /** Step 2: wallet number + TrxID form. Amount/method come from the URL and are re-checked. */
    private function payStep(ServerRequestInterface $request, Identity $identity): ResponseInterface
    {
        $params = $request->getQueryParams();
        $method = strtolower((string) ($params['method'] ?? ''));
        $amountRaw = str_replace(',', '', (string) ($params['amount'] ?? ''));

        $min = $this->topups->minAmount();
        $max = $this->topups->maxAmount();
        $amount = is_numeric($amountRaw) ? round((float) $amountRaw, 2) : 0.0;

        // The step-1 form enforces the limits client-side; this is the real check.
        if (!in_array($method, TopupRepository::METHODS, true) || $amount < $min || $amount > $max) {
            $this->session->set('flash_error', 'রিচার্জ শুরু করতে আগে পরিমাণ ও মেথড নির্বাচন করুন।');
            return new \Nyholm\Psr7\Response(302, ['Location' => '/recharge']);
        }

        $wallet = trim($this->settings->get('wallet_' . $method, ''));

        return $this->view->render('site/account/recharge-pay.twig', [
            'identity' => $identity,
            'method' => $method,
            'methodLabel' => SettingsRepository::METHOD_LABELS[$method] ?? strtoupper($method),
            'amount' => $amount,
            'wallet' => $wallet,
            'instruction' => trim($this->settings->get('topup_note', '')),
            'offerText' => trim($this->settings->get('topup_offer_text', '')),
            'notice' => $this->notice(),
            'hasPending' => $this->repo->forUser($identity->id, 1, 1, 'pending')['rows'] !== [],
            'maxBytes' => ReceiptStorage::MAX_BYTES,
            'allowedExtensions' => ReceiptStorage::ALLOWED_EXTENSIONS,
        ]);
    }

    /** POST from step 2 — the actual TopupService request, unchanged guards. */
    private function submit(ServerRequestInterface $request, Identity $identity): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        $file = $request->getUploadedFiles()['receipt'] ?? null;

        // Step-1 state rides along as hidden fields; they are only used to send
        // the user back to the right step-2 page after a validation error.
        $amount = (string) ($input['amount'] ?? '');
        $method = (string) ($input['method'] ?? '');

        [$ok, $message, $errors] = $this->topups->request($identity->id, $input, $file);

        if ($ok) {
            $this->session->set('flash_success', $message);
            // PRG: a refresh must not resubmit the same payment.
            return new \Nyholm\Psr7\Response(302, ['Location' => '/recharge']);
        }

        $this->session->set('flash_error', $message);
        $this->session->set('recharge_errors', [$errors, compact('amount', 'method')]);
        return new \Nyholm\Psr7\Response(302, ['Location' => '/recharge?step=pay&method=' . urlencode($method) . '&amount=' . urlencode($amount)]);
    }

    /** @return float[] quick-amount chips parsed from settings, deduped and sane */
    private function quickAmounts(): array
    {
        $raw = (string) $this->settings->get('topup_quick_amounts', '');
        $values = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part) && (float) $part > 0) {
                $values[] = round((float) $part, 2);
            }
        }
        return array_values(array_unique($values));
    }

    /** @return array{enabled: bool, title: string, body: string} */
    private function notice(): array
    {
        return [
            'enabled' => $this->settings->get('notice_enabled', '0') === '1',
            'title' => trim($this->settings->get('notice_title', 'জরুরি নোটিশ')),
            'body' => trim($this->settings->get('notice_body', '')),
        ];
    }
}
