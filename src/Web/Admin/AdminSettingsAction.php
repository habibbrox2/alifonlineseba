<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\SettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminSettingsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private SettingsRepository $settings,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $errors = [];
        $input = $this->settings->all();

        if ($request->getMethod() === 'POST') {
            $identity = $request->getAttribute('identity');
            $body = (array) $request->getParsedBody();

            $input = array_map(
                static fn ($v) => trim((string) $v),
                array_intersect_key($body, SettingsRepository::KEYS),
            );

            // An unchecked checkbox is simply missing from the body, so restoring
            // "off" to every checkbox key is what makes toggling off persist.
            foreach (SettingsRepository::checkboxKeys() as $key) {
                $input[$key] = isset($body[$key]) ? '1' : '0';
            }

            foreach ($input as $key => $value) {
                if ($value !== '' && $this->settings->isUrlKey($key) && !$this->settings->isSafeUrl($value)) {
                    $errors[$key] = 'সঠিক http(s) লিংক দিন (অথবা খালি রাখুন)।';
                }
                if ($value !== '' && ($key === 'contact_email' || $key === 'contact_phone')) {
                    $valid = $key === 'contact_email'
                        ? filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                        : preg_match('/^[0-9+\-\s()]{6,20}$/', $value) === 1;
                    if (!$valid) {
                        $errors[$key] = $key === 'contact_email' ? 'সঠিক ইমেইল দিন।' : 'সঠিক ফোন নম্বর দিন।';
                    }
                }
            }

            foreach (['topup_min_amount', 'topup_max_amount'] as $key) {
                if (($input[$key] ?? '') === '') {
                    continue;
                }
                if (!is_numeric($input[$key]) || (float) $input[$key] <= 0) {
                    $errors[$key] = 'শূন্যের চেয়ে বড় সংখ্যা দিন।';
                }
            }

            // Referral amounts. The threshold is the odd one out: 0 is a
            // legitimate value there ("any approved recharge counts"), so it
            // is checked against zero rather than for positivity, and only
            // rejected when it is not a number at all. The upper bound stops a
            // fat-fingered 6-digit figure from paying out per signup.
            foreach (['referrer_bonus_amount', 'referee_bonus_amount'] as $key) {
                $value = $input[$key] ?? '';
                if ($value === '' || !is_numeric($value) || (float) $value < 0 || (float) $value > 100000) {
                    $errors[$key] = '০ থেকে ১০০,০০০ টাকার মধ্যে সংখ্যা দিন।';
                }
            }
            $threshold = $input['referral_min_first_recharge'] ?? '';
            if ($threshold === '' || !is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 1000000) {
                $errors['referral_min_first_recharge'] = '০ থেকে ১০,০০,০০০ টাকার মধ্যে সংখ্যা দিন (০ মানে সীমা নেই)।';
            }

            $min = isset($input['topup_min_amount']) && is_numeric($input['topup_min_amount'])
                ? (float) $input['topup_min_amount']
                : 0.0;
            $max = isset($input['topup_max_amount']) && is_numeric($input['topup_max_amount'])
                ? (float) $input['topup_max_amount']
                : 0.0;

            if ($errors === [] && $max < $min) {
                $errors['topup_max_amount'] = 'সর্বোচ্চ পরিমাণ সর্বনিম্নের চেয়ে বড় হতে হবে।';
            }

            if ($errors === []) {
                $this->settings->putMany($input, $identity !== null ? (int) $identity->id : null);
                $this->session->set('flash_success', 'সাইট সেটিংস সংরক্ষণ হয়েছে।');

                return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/settings']);
            }
        }

        return $this->view->render('site/admin/settings.twig', [
            'settings' => $input,
            'errors' => $errors,
        ]);
    }
}
