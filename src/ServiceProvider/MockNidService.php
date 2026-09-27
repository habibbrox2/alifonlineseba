<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo NID lookup. Returns clearly-marked fake data — no real identity data.
 */
final class MockNidService implements ServiceProviderInterface
{
    public function key(): string
    {
        return 'mock-nid';
    }

    public function name(): string
    {
        return 'NID (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField('nid_number', 'এনআইডি নম্বর', 'text', true, '১০ বা ১৩ ডিজিট', 'ডেমো মোড: যেকোনো ১০/১৩ ডিজিটের নম্বর চলবে'),
            new ServiceField('date_of_birth', 'জন্ম তারিখ', 'date', true, '', 'ফরম্যাট: YYYY-MM-DD'),
        ];
    }

    public function requirements(): array
    {
        return [
            'এনআইডি নম্বর (১০ বা ১৩ ডিজিট)',
            'জন্ম তারিখ (জন্মনিবন্ধন অনুযায়ী)',
            'ডেমো মোডে সব তথ্য কাল্পনিক — কোনো সরকারি ডাটাবেজ ব্যবহার হয় না',
        ];
    }

    public function execute(array $input): ServiceResult
    {
        $errors = [];
        $nid = preg_replace('/\D/', '', (string) ($input['nid_number'] ?? ''));
        $dob = trim((string) ($input['date_of_birth'] ?? ''));

        // Format is checked only for the fields the admin actually enabled for this service;
        // presence of required fields is enforced by the ServiceManager.
        if ($nid !== '' && !in_array(strlen((string) $nid), [10, 13, 17], true)) {
            $errors['nid_number'] = 'NID must be 10, 13 or 17 digits.';
        }
        if ($dob !== '' && strtotime($dob) === false) {
            $errors['date_of_birth'] = 'Enter a valid date of birth.';
        }
        if ($errors !== []) {
            return ServiceResult::fail('Validation failed.', $errors);
        }

        $seed = crc32($nid . $dob);
        $data = [
            '_demo' => true,
            '_notice' => 'এটি ডেমো ডাটা — সম্পূর্ণ কাল্পনিক',
            'nid_number' => $nid,
            'name' => 'Rahim Uddin (Demo)',
            'name_bn' => 'রহিম উদ্দিন (ডেমো)',
            'father_name' => 'Karim Uddin (Demo)',
            'mother_name' => 'Ayesha Begum (Demo)',
            'date_of_birth' => $dob ?: '—',
            'address' => 'Demo Road 12, Dhamrai, Dhaka (Demo)',
            'blood_group' => ['A+', 'B+', 'O+', 'AB+'][$seed % 4],
            'photo' => null,
        ];

        return ServiceResult::ok($data, 'ডেমো সার্ভার কপি তৈরি হয়েছে (ফেক ডাটা)');
    }
}
