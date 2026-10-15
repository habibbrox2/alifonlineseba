<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo pourashava/union application — fake data only.
 *
 * Covers the two things a local government office issues: a trade licence
 * (new, renewal, correction) and any certificate or attestation. The real work
 * is an application filed at the office with the customer's papers, so nothing
 * is filed here — the order waits in the operator's queue with the applicant's
 * identity and what is being asked for.
 */
final class MockLocalGovService implements ServiceProviderInterface
{
    public function key(): string
    {
        return 'mock-local-gov';
    }

    public function name(): string
    {
        return 'Local Government Application (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField(
                'applicant_name',
                'আবেদনকারীর নাম',
                'text',
                true,
                'যেমন: মোঃ রহিম উদ্দিন',
                'এনআইডি কার্ডের নামের সাথে হুবহু মিলিয়ে দিন',
            ),
            new ServiceField(
                'nid_number',
                'এনআইডি / জন্মনিবন্ধন নম্বর',
                'text',
                true,
                'যেমন: 1990123456789',
                '১৩ ডিজিটের এনআইডি অথবা ১৭ ডিজিটের জন্মনিবন্ধন নম্বর',
            ),
            new ServiceField(
                'contact_mobile',
                'মোবাইল নম্বর',
                'tel',
                true,
                'যেমন: 01318129911',
                'চলমান নম্বর দিন — অফিস থেকে যোগাযোগ করা হতে পারে',
            ),
            new ServiceField(
                'details',
                'আবেদনের বিস্তারিত',
                'textarea',
                false,
                'যেমন: প্রতিষ্ঠানের নাম ও ব্যবসার ধরন, সনদের বিষয়',
                'ট্রেড লাইসেন্সের প্রতিষ্ঠানের নাম বা সনদের বিষয় — যা জানা দরকার লিখুন',
            ),
        ];
    }

    public function requirements(): array
    {
        return [
            'আবেদনকারীর সঠিক নাম, এনআইডি নম্বর ও চলমান মোবাইল নম্বর',
            'প্রয়োজনীয় কাগজপত্র ও অফিসিফি, যদি থাকে, সম্পর্কে অর্ডারের পর অপারেটর জানাবেন',
            'ভুল তথ্যের জন্য টাকা ফেরতযোগ্য নয়',
            'ডেমো মোড: কোনো প্রকৃত আবেদন জমা হয় না',
        ];
    }

    /**
     * Manual: the application is filed at the pourashava/union office by a
     * person, which no provider can do on the customer's behalf.
     */
    public function autoGenerate(): bool
    {
        return false;
    }

    public function execute(array $input): ServiceResult
    {
        $name = trim((string) ($input['applicant_name'] ?? ''));
        $nid = preg_replace('/\D/', '', (string) ($input['nid_number'] ?? '')) ?? '';
        $mobile = preg_replace('/\D/', '', (string) ($input['contact_mobile'] ?? '')) ?? '';
        $details = trim((string) ($input['details'] ?? ''));

        $errors = [];
        if ($name !== '' && mb_strlen($name) > 100) {
            $errors['applicant_name'] = 'নাম সর্বোচ্চ ১০০ অক্ষরের হতে পারবে।';
        }
        if ($nid !== '' && preg_match('/^(\d{13}|\d{17})$/', $nid) !== 1) {
            $errors['nid_number'] = '১৩ ডিজিটের এনআইডি বা ১৭ ডিজিটের জন্মনিবন্ধন নম্বর দিন।';
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
                '_notice' => 'এটি ডেমো ডাটা — কোনো প্রকৃত আবেদন জমা হয়নি',
                'applicant_name' => $name !== '' ? $name : '—',
                'nid_number' => $nid !== '' ? $nid : '—',
                'contact_mobile' => $mobile !== '' ? $mobile : '—',
                'details' => $details !== '' ? $details : '—',
                'application_status' => 'অপারেটরের অপেক্ষায় (ডেমো)',
            ],
            'আবেদনের অনুরোধ গ্রহণ করা হয়েছে (ডেমো)।',
        );
    }
}
