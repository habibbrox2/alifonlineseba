<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\SettingsRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminSettingsApiAction
{
    public function __construct(
        private SettingsRepository $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->isSuperAdmin()) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
        }

        return Api::ok($this->settings->all());
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $errors = [];
        $body = array_map(
            static fn ($v) => trim((string) $v),
            array_intersect_key($input, SettingsRepository::KEYS),
        );

        foreach (SettingsRepository::checkboxKeys() as $key) {
            $body[$key] = in_array(
                strtolower(trim((string) ($input[$key] ?? ''))),
                ['1', 'true', 'on', 'yes'],
                true,
            ) ? '1' : '0';
        }

        foreach ($body as $key => $value) {
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
            if (($body[$key] ?? '') === '') {
                continue;
            }
            if (!is_numeric($body[$key]) || (float) $body[$key] <= 0) {
                $errors[$key] = 'শূন্যের চেয়ে বড় সংখ্যা দিন।';
            }
        }

        foreach (['referrer_bonus_amount', 'referee_bonus_amount'] as $key) {
            $value = $body[$key] ?? '';
            if ($value === '' || !is_numeric($value) || (float) $value < 0 || (float) $value > 100000) {
                $errors[$key] = '০ থেকে ১০০,০০০ টাকার মধ্যে সংখ্যা দিন।';
            }
        }

        if (isset($body['referral_min_first_recharge']) && $body['referral_min_first_recharge'] !== '') {
            $threshold = $body['referral_min_first_recharge'];
            if (!is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 1000000) {
                $errors['referral_min_first_recharge'] = '০ থেকে ১০,০০,০০০ টাকার মধ্যে সংখ্যা দিন (০ মানে সীমা নেই)।';
            }
        }

        if (isset($body['referral_required_recharges']) && $body['referral_required_recharges'] !== '') {
            $required = $body['referral_required_recharges'];
            if (
                filter_var($required, FILTER_VALIDATE_INT) === false
                || (int) $required < 1
                || (int) $required > 1000
            ) {
                $errors['referral_required_recharges'] = '১ থেকে ১০০০ পর্যন্ত পূর্ণসংখ্যা দিন (১ মানে প্রথম রিচার্জেই বোনাস)।';
            }
        }

        if (isset($body['referral_min_qualifying_recharge']) && $body['referral_min_qualifying_recharge'] !== '') {
            $qualifyingMin = $body['referral_min_qualifying_recharge'];
            if (!is_numeric($qualifyingMin) || (float) $qualifyingMin < 0 || (float) $qualifyingMin > 1000000) {
                $errors['referral_min_qualifying_recharge'] = '০ থেকে ১০,০০,০০০ টাকার মধ্যে সংখ্যা দিন (০ মানে সীমা নেই)।';
            }
        }

        $min = isset($body['topup_min_amount']) && is_numeric($body['topup_min_amount'])
            ? (float) $body['topup_min_amount']
            : 0.0;
        $max = isset($body['topup_max_amount']) && is_numeric($body['topup_max_amount'])
            ? (float) $body['topup_max_amount']
            : 0.0;

        if ($errors === [] && $max < $min) {
            $errors['topup_max_amount'] = 'সর্বোচ্চ পরিমাণ সর্বনিম্নের চেয়ে বড় হতে হবে।';
        }

        // Same bound the settings page applies: the maintenance note goes to
        // every visitor, so it is refused rather than silently cut.
        if (mb_strlen((string) ($body['maintenance_message'] ?? '')) > 300) {
            $errors['maintenance_message'] = '৩০০ অক্ষরের মধ্যে বার্তা লিখুন।';
        }

        if ($errors !== []) {
            return Api::fail('সেটিংস ভুল আছে।', $errors);
        }

        $this->settings->putMany($body, $identity->id);

        return Api::ok($this->settings->all(), 'সাইট সেটিংস সংরক্ষণ হয়েছে।');
    }
}
