<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The fields a person is asked for when a user record is written by hand —
 * the admin's create form and its edit form, which are the same form at two
 * points in a row's life.
 *
 * It exists so those two cannot drift: a rule added to one and forgotten in
 * the other produces an account that can be created one way and edited
 * another, and the difference only shows up later as a row nobody can fix.
 * Uniqueness is deliberately *not* here — it needs the database, so it stays
 * with the action, which knows whether it is creating a row or updating one.
 *
 * Dates go through {@see ServiceDate} like every other date in the product:
 * `06-10-2026` goes in, `2026-10-06` comes out in `values`, and the same
 * Bengali message comes back in `errors` that the service forms show.
 */
final class UserFields
{
    /**
     * Validate a submitted user form.
     *
     * @param array<string, mixed> $input the raw parsed body
     * @return array{
     *     values: array<string, string|null>,
     *     errors: array<string, string>
     * } `values` carries canonical, ready-to-store data — not the raw text.
     */
    public static function validate(array $input): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $birthTyped = trim((string) ($input['date_of_birth'] ?? ''));

        $errors = [];

        // The 120 cap mirrors the column, so MySQL never truncates a name the
        // person just typed — a silently shortened name is worse than a refusal.
        if ($fullName === '' || mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'পুরো নাম লিখুন (কমপক্ষে ২ অক্ষর)।';
        } elseif (mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'পুরো নাম ১২০ অক্ষরের বেশি হতে পারবে না।';
        }

        if (preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username) !== 1) {
            $errors['username'] = 'ইউজারনেম ৩–৩২ অক্ষরের হতে হবে (a-z, 0-9, . _ -)।';
        }

        if (preg_match('/^01[3-9][0-9]{8}$/', $phone) !== 1) {
            $errors['phone'] = 'সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন (যেমন 01712345678)।';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'সঠিক ইমেইল দিন।';
        }

        $birthDate = null;
        if ($birthTyped !== '') {
            // An optional question: blank is a legal answer and clears the
            // column. Anything else has to be a real date — `31-02-1990` is
            // refused rather than rolled into March the way strtotime() would.
            $birthDate = ServiceDate::canonical($birthTyped);
            if ($birthDate === null) {
                $errors['date_of_birth'] = ServiceDate::errorMessage();
            }
        }

        return [
            'values' => [
                'full_name' => $fullName,
                'username' => $username,
                'phone' => $phone,
                'email' => $email === '' ? null : $email,
                'date_of_birth' => $birthDate,
            ],
            'errors' => $errors,
        ];
    }
}