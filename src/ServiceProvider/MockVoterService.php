<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo voter search — fake data only.
 */
final class MockVoterService implements ServiceProviderInterface
{
    public function key(): string
    {
        return 'mock-voter';
    }

    public function name(): string
    {
        return 'Voter Search (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField('district', 'জেলা', 'text', true, 'যেমন: ঢাকা'),
            new ServiceField('upazila', 'উপজেলা', 'text', false, 'যেমন: ধামরাই'),
            new ServiceField('full_name', 'পূর্ণ নাম', 'text', true, 'যেমন: রহিম উদ্দিন'),
        ];
    }

    public function requirements(): array
    {
        return [
            'জেলা ও নাম আবশ্যক',
            'ডেমো মোডে সব ফলাফল কাল্পনিক',
        ];
    }

    public function execute(array $input): ServiceResult
    {
        $district = trim((string) ($input['district'] ?? ''));
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $upazila = trim((string) ($input['upazila'] ?? ''));

        $data = [
            '_demo' => true,
            '_notice' => 'এটি ডেমো ডাটা — সম্পূর্ণ কাল্পনিক',
            'voter_sl_no' => random_int(100, 999),
            'name' => $fullName ?: '—',
            'district' => $district ?: '—',
            'upazila' => $upazila ?: '—',
            'center' => 'Demo High School Center (Demo)',
            'nid_number' => '1990' . random_int(1000000000, 9999999999),
        ];

        return ServiceResult::ok($data, 'ডেমো ভোটার তথ্য পাওয়া গেছে (ফেক ডাটা)');
    }
}
