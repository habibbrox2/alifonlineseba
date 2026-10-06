<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo TIN certificate lookup — fake data only.
 */
final class MockTinService implements ServiceProviderInterface
{
    public function key(): string
    {
        return 'mock-tin';
    }

    public function name(): string
    {
        return 'TIN Certificate (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField('tin_number', 'টিন নম্বর (১২ ডিজিট)', 'text', true, '12-digit TIN'),
        ];
    }

    public function requirements(): array
    {
        return ['১২ ডিজিটের টিন নম্বর', 'ডেমো মোডে সব তথ্য কাল্পনিক'];
    }

    /**
     * Manual: an operator has to look at this one.
     *
     * A lookup returns whatever the upstream data says, and a human decides
     * whether that is worth charging for — which is the whole reason the
     * review queue exists.
     */
    public function autoGenerate(): bool
    {
        return false;
    }

    public function execute(array $input): ServiceResult
    {
        $tin = preg_replace('/\D/', '', (string) ($input['tin_number'] ?? ''));
        if ($tin !== '' && strlen((string) $tin) !== 12) {
            return ServiceResult::fail('Validation failed.', ['tin_number' => 'TIN must be exactly 12 digits.']);
        }

        $data = [
            '_demo' => true,
            '_notice' => 'এটি ডেমো ডাটা — সম্পূর্ণ কাল্পনিক',
            'tin_number' => $tin ?: '—',
            'name' => 'Rahim Uddin (Demo)',
            'circle' => 'Demo Circle 301, Dhaka (Demo)',
            'status' => 'Active (Demo)',
            'last_return' => '2025-2026 (Demo)',
        ];

        return ServiceResult::ok($data, 'ডেমো টিন সার্টিফিকেট তৈরি হয়েছে (ফেক ডাটা)');
    }
}
