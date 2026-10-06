<?php

declare(strict_types=1);

namespace App\ServiceProvider;

use App\Service\ServiceDate;

/**
 * Demo NID card form — fake data only.
 *
 * markup: identity photo,
 * signature, NID/PIN numbers, both name spellings, birth details, parents,
 * gender, blood group, issue date and the full postal address.
 */
final class MockNidMakeService implements ServiceProviderInterface
{
    /**
     * Byte budgets for the two uploaded images.
     *
     * They are an order of magnitude apart on purpose. A face needs pixels and
     * a pen stroke does not, so the photo gets four times the signature's
     * allowance — enough for a 1000px JPEG that prints sharp on a card, while
     * the signature is a strip that only ever gets printed a couple of
     * millimetres wide. Neither has any business being megabytes on disk.
     */
    public const PHOTO_MAX_BYTES = 100 * 1024;

    public const SIGNATURE_MAX_BYTES = 40 * 1024;

    public function key(): string
    {
        return 'mock-nid-make';
    }

    /**
     * Automatic: the card is the customer's own answers, typeset.
     *
     * There is nothing here for an operator to judge. The form becomes a PDF
     * and the only failure mode is the renderer failing, which is a fault in
     * this platform rather than a question about the customer's data. An
     * approve button would be asking somebody to confirm that mPDF worked.
     *
     * Note the contrast with the other three providers, which answer a question
     * about a third party — whether a NID belongs to such-and-such — and so
     * genuinely need a human before the customer is charged for the answer.
     */
    public function autoGenerate(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'এনআইডি মেইক';
    }

    public function fields(): array
    {
        return [
            new ServiceField('nid_number', 'আইডি নম্বর', 'text', true, '১০ বা ১৩ ডিজিট', 'ডেমো মোড: যেকোনো ১০/১৩ ডিজিটের নম্বর চলবে'),
            new ServiceField('pin', 'পিন নম্বর', 'text', true, '১৭ ডিজিট', 'খালি রাখলেও চলবে'),
            new ServiceField('name_bangla', 'নাম (বাংলা)', 'text', true, 'যেমন: ছালেহা বেগম'),
            new ServiceField('name_english', 'নাম (ইংরেজি)', 'text', true, 'যেমন: Saleha Begum'),
            new ServiceField('date_of_birth', 'জন্ম তারিখ', 'date', true),
            new ServiceField('birth_place', 'জন্মস্থান', 'text', true, 'যেমন: ঢাকা'),
            new ServiceField('father_name', 'বাবার নাম', 'text', true, 'যেমন: রাস্তু মিয়া'),
            new ServiceField('mother_name', 'মায়ের নাম', 'text', true, 'যেমন: আছিরন'),
            new ServiceField('gender', 'লিঙ্গ', 'text', true, 'পুরুষ / নারী / অন্যান্য'),
            new ServiceField('blood_group', 'রক্তের গ্রুপ', 'text', true, 'যেমন: B+', 'A+, B+, O+, AB+ ইত্যাদি'),
            new ServiceField('issue_date', 'ইস্যু তারিখ', 'date', true),
            new ServiceField('full_address', 'ঠিকানা', 'textarea', true, '', 'বাসা/হোল্ডিং, গ্রাম/রাস্তা, মৌজা, ডাকঘর ও পোস্ট কোড, উপজেলা, জেলা, বিভাগ'),
            new ServiceField('photo', 'আইডি ফটো', 'image', true, '', 'কার্ডের মতো সোজা ও পরিষ্কার ছবি। ছবিটি স্বয়ংক্রিয়ভাবে ১০০ KB-এর মধ্যে ছোট করা হবে। ছবি না দিলেও অর্ডার চলবে — ডেমো মোডে কোনো ছবিই যাচাই করা হয় না।', self::PHOTO_MAX_BYTES),
            new ServiceField('signature', 'আইডি সাক্ষর', 'image', true, '', 'কার্ডের নিচে থাকা স্বাক্ষর। ছবিটি স্বয়ংক্রিয়ভাবে ৪০ KB-এর মধ্যে ছোট করা হবে। ছবি না দিলেও অর্ডার চলবে।', self::SIGNATURE_MAX_BYTES),
        ];
    }

    public function requirements(): array
    {
        return [
            'এনআইডি নম্বর (১০ বা ১৩ ডিজিট)',
            'নাম বাংলা ও ইংরেজি — দুটোই',
            'জন্ম তারিখ',
            'লিঙ্গ ও সম্পূর্ণ ঠিকানা',
            'ডেমো মোডে সব তথ্য কাল্পনিক — কোনো সরকারি ডাটাবেজ ব্যবহার হয় না',
        ];
    }

    public function execute(array $input): ServiceResult
    {
        $errors = [];

        $nid = preg_replace('/\D/', '', (string) ($input['nid_number'] ?? ''));
        $pin = preg_replace('/\D/', '', (string) ($input['pin'] ?? ''));
        $dob = trim((string) ($input['date_of_birth'] ?? ''));
        $issueDate = trim((string) ($input['issue_date'] ?? ''));

        // Format is checked only for the fields the admin actually enabled for this service;
        // presence of required fields is enforced by the ServiceManager.
        if ($nid !== '' && !in_array((int) strlen((string) $nid), [10, 13], true)) {
            $errors['nid_number'] = 'NID must be 10 or 13 digits.';
        }
        if ($pin !== '' && strlen((string) $pin) !== 17) {
            $errors['pin'] = 'PIN must be exactly 17 digits.';
        }
        if ($dob !== '' && !ServiceDate::isValid($dob)) {
            $errors['date_of_birth'] = ServiceDate::errorMessage();
        }
        if ($issueDate !== '' && !ServiceDate::isValid($issueDate)) {
            // This field used to demand `DD/MM/YYYY` from the browser while its
            // sibling demanded `YYYY-MM-DD` — two formats on one form, and a
            // date the widget shows could be rejected by the very service that
            // asked for it. ServiceDate decides both now; see its docblock.
            $errors['issue_date'] = ServiceDate::errorMessage();
        }
        if ($errors !== []) {
            return ServiceResult::fail('Validation failed.', $errors);
        }

        $text = function (string $key) use ($input): string {
            $value = trim((string) ($input[$key] ?? ''));

            return $value !== '' ? $value : '—';
        };

        $image = function (string $key) use ($input): string {
            $path = trim((string) ($input[$key] ?? ''));

            return $path !== '' ? basename(str_replace('\\', '/', $path)) : '—';
        };

        $data = [
            '_demo' => true,
            '_notice' => 'এটি ডেমো ডাটা — সম্পূর্ণ কাল্পনিক',
            'nid_number' => $nid !== '' ? $nid : '—',
            'pin' => $pin !== '' ? $pin : '—',
            'name_bangla' => $text('name_bangla'),
            'name_english' => $text('name_english'),
            'date_of_birth' => $dob !== '' ? $dob : '—',
            'birth_place' => $text('birth_place'),
            'father_name' => $text('father_name'),
            'mother_name' => $text('mother_name'),
            'gender' => $text('gender'),
            'blood_group' => $text('blood_group'),
            'issue_date' => $issueDate !== '' ? $issueDate : '—',
            'full_address' => $text('full_address'),
            // The stored value is the hashed relative path ImageUploadStorage
            // returns — never the client's filename — so the order metadata
            // cannot be steered by what a caller names their upload. What the
            // result card shows is the file name, which is all a reader wants.
            'photo' => $image('photo'),
            'signature' => $image('signature'),
        ];

        return ServiceResult::ok($data, 'ডেমো এনআইডি মেইক ফর্ম তৈরি হয়েছে (ফেক ডাটা)');
    }
}
