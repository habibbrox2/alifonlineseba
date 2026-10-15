<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Demo Bangla text conversion (SutonnyMJ ↔ Unicode) — fake data only.
 *
 * The reference site runs a live converter. SutonnyMJ (Vijaya) to Unicode is a
 * glyph-mapping problem with a large lookup table, and no such table ships with
 * this platform, so nothing is converted here: the order waits in the
 * operator's queue with the text and the requested direction stored on it.
 */
final class MockBanglaConverterService implements ServiceProviderInterface
{
    /** Above this the stored payload stops being a readable copy of the answer. */
    private const MAX_CHARS = 5000;

    public function key(): string
    {
        return 'mock-bangla-converter';
    }

    public function name(): string
    {
        return 'Bangla Text Converter (Demo)';
    }

    public function fields(): array
    {
        return [
            new ServiceField(
                'source_text',
                'যে লেখা রূপান্তর করতে চান',
                'textarea',
                true,
                'এখানে পুরো লেখাটি পেস্ট করুন',
                'রূপান্তরের আগের লেখাটি একবারেই দিন',
            ),
            new ServiceField(
                'direction',
                'রূপান্তরের দিক',
                'text',
                false,
                'Sutonny → Unicode',
                'Sutonny → Unicode অথবা Unicode → Sutonny — না দিলে Sutonny → Unicode ধরা হবে',
            ),
        ];
    }

    public function requirements(): array
    {
        return [
            'রূপান্তরের দিক লিখুন: Sutonny → Unicode অথবা Unicode → Sutonny',
            'যে লেখা দরকার সেটি পুরোটাই একসাথে দিন',
            'একটি অর্ডারে একটি লেখার জন্যই আবেদন করুন',
            'ডেমো মোড: রূপান্তর অপারেটর সম্পন্ন করবেন এবং ফলাফল ডেমো হিসেবে ডেলিভারি হবে',
        ];
    }

    /** Manual: there is no conversion table to run here, so an operator does it. */
    public function autoGenerate(): bool
    {
        return false;
    }

    public function execute(array $input): ServiceResult
    {
        $text = (string) ($input['source_text'] ?? '');
        $direction = trim((string) ($input['direction'] ?? ''));

        $errors = [];
        if (trim($text) === '') {
            $errors['source_text'] = 'রূপান্তর করার লেখাটি দিন।';
        } elseif (mb_strlen($text) > self::MAX_CHARS) {
            $errors['source_text'] = 'একবারে সর্বোচ্চ ' . self::MAX_CHARS . ' অক্ষর দেওয়া যাবে।';
        }
        if ($errors !== []) {
            return ServiceResult::fail('Validation failed.', $errors);
        }

        return ServiceResult::ok(
            [
                '_demo' => true,
                '_notice' => 'এটি ডেমো ডাটা — কোনো প্রকৃত রূপান্তর করা হয়নি',
                'direction' => $direction !== '' ? $direction : 'Sutonny → Unicode',
                'character_count' => mb_strlen($text),
                'source_preview' => mb_substr(trim($text), 0, 120),
                'conversion' => 'অপারেটর সম্পন্ন করবেন (ডেমো)',
            ],
            'রূপান্তরের অনুরোধ গ্রহণ করা হয়েছে (ডেমো)।',
        );
    }
}
