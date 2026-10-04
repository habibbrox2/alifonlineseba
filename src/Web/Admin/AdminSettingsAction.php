<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\SettingsRepository;
use App\Service\OrderWindowService;
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

            // An unchecked checkbox is simply missing from the body, so anything
            // that is not a truthy value is forced back to "off" — that is what
            // makes toggling off persist. Testing the value rather than mere
            // presence matters: a client that posts an explicit "0" (a hidden
            // input mirroring the checkbox, or a hand-rolled form) would
            // otherwise read as "on" and silently re-enable the setting.
            foreach (SettingsRepository::checkboxKeys() as $key) {
                $input[$key] = in_array(
                    strtolower(trim((string) ($body[$key] ?? ''))),
                    ['1', 'true', 'on', 'yes'],
                    true,
                ) ? '1' : '0';
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

            // How many recharges release the bonus. 0 is refused here even
            // though 0 is a legitimate value for the amounts above: a run of
            // zero would qualify the moment the friend signs up, which is
            // precisely the signup bonus this programme exists not to pay. The
            // upper bound is a guard against a typo, not a policy.
            $required = $input['referral_required_recharges'] ?? '';
            if (
                $required === ''
                || filter_var($required, FILTER_VALIDATE_INT) === false
                || (int) $required < 1
                || (int) $required > 1000
            ) {
                $errors['referral_required_recharges'] = '১ থেকে ১০০০ পর্যন্ত পূর্ণসংখ্যা দিন (১ মানে প্রথম রিচার্জেই বোনাস)।';
            }

            // The per-recharge floor. 0 is allowed, matching the existing
            // threshold field, and means any approved recharge counts towards
            // the run.
            $qualifyingMin = $input['referral_min_qualifying_recharge'] ?? '';
            if (
                $qualifyingMin === ''
                || !is_numeric($qualifyingMin)
                || (float) $qualifyingMin < 0
                || (float) $qualifyingMin > 1000000
            ) {
                $errors['referral_min_qualifying_recharge'] = '০ থেকে ১০,০০,০০০ টাকার মধ্যে সংখ্যা দিন (০ মানে সীমা নেই)।';
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

            // Order intake window. Blank endpoints are tolerated while the gate
            // is off, so switching it off does not demand retyping, but a
            // malformed one is never storable: OrderWindowService fails open on
            // a time it cannot read, so junk would sit in the settings looking
            // like a window and quietly mean "always open". Equal endpoints get
            // the same treatment for the same reason — equal times also read as
            // "always open", and that is not what someone who typed the same
            // value twice meant.
            $windowEnabled = ($input['order_window_enabled'] ?? '0') === '1';
            $windowTimes = [
                'order_window_start' => trim((string) ($input['order_window_start'] ?? '')),
                'order_window_end' => trim((string) ($input['order_window_end'] ?? '')),
            ];
            foreach ($windowTimes as $key => $value) {
                if ($value === '') {
                    if ($windowEnabled) {
                        $errors[$key] = 'সময় দিন (যেমন 08:00)।';
                    }
                    continue;
                }
                if (!OrderWindowService::isValidTime($value)) {
                    $errors[$key] = 'সময় HH:MM ফরম্যাটে দিন (যেমন 08:00)।';
                }
            }
            if ($windowEnabled
                && $windowTimes['order_window_start'] !== ''
                && $windowTimes['order_window_start'] === $windowTimes['order_window_end']
                && !isset($errors['order_window_start'], $errors['order_window_end'])
            ) {
                $errors['order_window_end'] = 'শুরু ও শেষের সময় আলাদা হতে হবে।';
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
