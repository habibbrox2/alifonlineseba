<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo Teletalk application-fee payment — fake data only.
 *
 * The real service pays a government job application fee by sending two SMS
 * messages ("DLS <user id>" then "DLS YES <pin>") to 16222 from a Teletalk
 * prepaid SIM. That SIM belongs to the operator, not the customer, which is
 * the point of the service: no Teletalk SIM is needed on the buyer's side.
 * Nothing is sent here — the order waits in the operator's queue.
 */
final class MockTeletalkPaymentService implements ServiceProviderInterface
{
    public function key(): string
    {
        return 'mock-teletalk-payment';
    }

    public function name(): string
    {
        return 'Teletalk Payment (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField(
                'user_id',
                'টেলিটক User ID',
                'text',
                true,
                'যেমন: MZDTP2XB',
                'অ্যাপ্লিকেশন ফরমের কনফার্মেশন স্লিপে দেওয়া User ID দিন',
            ),
            new ServiceField(
                'contact_mobile',
                'মোবাইল নম্বর',
                'tel',
                true,
                'যেমন: 01318129911',
                'টেলিটকের রিপ্লাই এই নম্বরেই আসবে — নম্বরটি চালু রাখুন',
            ),
            new ServiceField(
                'post_name',
                'পদ ও প্রতিষ্ঠানের নাম',
                'text',
                false,
                'যেমন: ব্যাংক কেসার (৪৯তম বিসিএস)',
                'আবেদনের পদ ও প্রতিষ্ঠান — অপারেটর যাচাইয়ে সহায়ক',
            ),
        ];
    }

    public function requirements(): array
    {
        return [
            'অ্যাপ্লিকেশন ফরমের কনফার্মেশন স্লিপ থেকে পাওয়া টেলিটক User ID',
            'যোগাযোগের মোবাইল নম্বর চালু থাকা — রিপ্লাই এসএমএস সেখানেই আসে',
            'পেমেন্টের পর User ID ও পাসওয়ার্ড ডেলিভারি হবে, অ্যাডমিট কার্ড ডাউনলোডে লাগবে',
            'ডেমো মোড: কোনো প্রকৃত এসএমএস বা পেমেন্ট পাঠানো হয় না',
        ];
    }

    /**
     * Manual: both SMS messages go out from the operator's own Teletalk SIM,
     * which no provider can do on the customer's behalf.
     */
    public function autoGenerate(): bool
    {
        return false;
    }

    public function execute(array $input): ServiceResult
    {
        $rawUserId = preg_replace('/\s+/', '', (string) ($input['user_id'] ?? '')) ?? '';
        $userId = strtoupper($rawUserId);
        $mobile = preg_replace('/\D/', '', (string) ($input['contact_mobile'] ?? '')) ?? '';

        $errors = [];
        if ($userId !== '' && preg_match('/^[A-Z0-9]{6,12}$/', $userId) !== 1) {
            $errors['user_id'] = 'User ID ৬ থেকে ১২ ইংরেজি অক্ষর বা সংখ্যা হতে হবে।';
        }
        if ($mobile !== '' && preg_match('/^01[3-9]\d{8}$/', $mobile) !== 1) {
            $errors['contact_mobile'] = 'সঠিক মোবাইল নম্বর দিন (যেমন 01318129911)।';
        }
        if ($errors !== []) {
            return ServiceResult::fail('Validation failed.', $errors);
        }

        return ServiceResult::ok(
            [
                '_demo' => true,
                '_notice' => 'এটি ডেমো ডাটা — কোনো প্রকৃত টেলিটক পেমেন্ট বা এসএমএস পাঠানো হয়নি',
                'user_id' => $userId !== '' ? $userId : '—',
                'contact_mobile' => $mobile !== '' ? $mobile : '—',
                'sms_1' => 'DLS ' . ($userId !== '' ? $userId : '<User ID>') . ' → ১৬২২২',
                'sms_2' => 'DLS YES <PIN> → ১৬২২২',
                'payment_status' => 'অপারেটরের অপেক্ষায় (ডেমো)',
            ],
            'পেমেন্টের অনুরোধ গ্রহণ করা হয়েছে (ডেমো)।',
        );
    }
}
