<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo file/font download — fake data only.
 *
 * The real service hands over a purchased sample file (Illustrator, MS Word) or
 * a language font pack. Nothing is stored or sold here: the order waits in the
 * operator's queue and the file is attached to it as the deliverable, which is
 * the only path this platform has for handing a file to a customer.
 */
final class MockDigitalFileService implements ServiceProviderInterface
{
    public function key(): string
    {
        return 'mock-digital-file';
    }

    public function name(): string
    {
        return 'Digital File Download (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField(
                'file_request',
                'কোন ফাইল বা ফন্ট দরকার',
                'text',
                false,
                'যেমন: লেটারহেড নমুনা (AI), বাংলা ফন্ট প্যাক',
                'সার্ভিসের নাম ছাড়াও নির্দিষ্ট কোন ফাইলটি দরকার লিখলে অপারেটর সহজে বুঝতে পারবেন',
            ),
            new ServiceField(
                'contact_mobile',
                'মোবাইল নম্বর',
                'tel',
                false,
                'যেমন: 01318129911',
                'ফাইল পাঠানোর আগে যোগাযোগ করতে হলে এই নম্বরেই কল করা হবে',
            ),
        ];
    }

    public function requirements(): array
    {
        return [
            'কোন ফাইল বা ফন্ট প্যাক দরকার সেটি স্পষ্ট লিখুন',
            'ডেলিভারি হবে অর্ডারের ফলাফলে সংযুক্ত ফাইলে',
            'ডেমো মোড: কোনো বাস্তব ফাইল বা পেমেন্ট বিনিময় হয় না',
        ];
    }

    /**
     * Manual: the platform has no file library to serve from — an operator
     * attaches the purchased file to the order, so it must sit in the queue.
     */
    public function autoGenerate(): bool
    {
        return false;
    }

    public function execute(array $input): ServiceResult
    {
        $mobile = preg_replace('/\D/', '', (string) ($input['contact_mobile'] ?? '')) ?? '';
        if ($mobile !== '' && preg_match('/^01[3-9]\d{8}$/', $mobile) !== 1) {
            return ServiceResult::fail('Validation failed.', [
                'contact_mobile' => 'সঠিক মোবাইল নম্বর দিন (যেমন 01318129911)।',
            ]);
        }

        $request = trim((string) ($input['file_request'] ?? ''));

        return ServiceResult::ok(
            [
                '_demo' => true,
                '_notice' => 'এটি ডেমো ডাটা — কোনো বাস্তব ফাইল বা পেমেন্ট বিনিময় হয়নি',
                'file_request' => $request !== '' ? $request : '—',
                'contact_mobile' => $mobile !== '' ? $mobile : '—',
                'delivery' => 'অপারেটর অর্ডারে ফাইল সংযুক্ত করবেন (ডেমো)',
            ],
            'ফাইল ডাউনলোডের অনুরোধ গ্রহণ করা হয়েছে (ডেমো)।',
        );
    }
}
