<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\AuthThrottle;
use App\Auth\IdentityRepository;
use App\Env;

/**
 * High-level auth operations with validation + throttling.
 */
final class AuthService
{
    public function __construct(
        private readonly IdentityRepository $identities,
        private readonly AuthThrottle $throttle,
        private readonly ReferralService $referrals,
    ) {}

    /**
     * @return array{errors: array<string,string>, userId: ?int}
     */
    public function register(array $input, string $ip, string $userAgent): array
    {
        $errors = [];
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');

        // Required, unlike every other optional field on the form: this is the
        // name that appears on the account's orders and in the admin list, and
        // an account created without one can only ever be identified by its
        // handle. 120 matches the column width — a value the schema would
        // truncate is a value the user typed under a false promise.
        if ($fullName === '' || mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'Enter your full name (at least 2 characters).';
        } elseif (mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Full name may not exceed 120 characters.';
        }
        if ($username === '' || strlen($username) < 3) {
            $errors['username'] = 'Username must be at least 3 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
            $errors['username'] = 'Username may only contain letters, numbers, dot and underscore.';
        }
        if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
            $errors['phone'] = 'Enter a valid Bangladeshi mobile number (e.g. 01712345678).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }
        if ($password !== $confirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'userId' => null];
        }

        // `?ref=` from the share link. Unvalidated on purpose here — the
        // service decides what a code means and silently ignores the rest.
        $referrer = $this->referrals->resolveCode((string) ($input['referral_code'] ?? ''));

        $userId = $this->identities->register([
            'full_name' => $fullName,
            'username' => $username,
            'phone' => $phone,
            'email' => $email ?: null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            // Resolved BEFORE insert: the code names an account that already
            // exists, so it can be checked (and the new user stamped with it)
            // in the same statement. A bad code resolves to null and the
            // signup proceeds normally — never block a registration over a
            // bonus.
            'referred_by' => $referrer['id'] ?? null,
        ], $ip, $userAgent);

        // Attached after the insert: the referral row has a foreign key to the
        // new account, so it cannot be written first.
        if ($referrer !== null) {
            $this->referrals->attach($userId, (int) $referrer['id'], (string) $referrer['referral_code']);
        }

        return ['errors' => [], 'userId' => $userId];
    }

    /**
     * @return array{errors: array, message: ?string}
     */
    public function login(array $input, string $ip, string $userAgent): array
    {
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $remember = !empty($input['remember']);

        $errors = [];
        if ($identifier === '') {
            $errors['identifier'] = 'Enter your username or mobile number.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'message' => null];
        }

        $throttleKey = $identifier . '|' . $ip;
        if ($this->throttle->tooManyAttempts($throttleKey)) {
            return ['errors' => [], 'message' => 'Too many failed attempts. Try again in a few minutes.'];
        }

        $error = $this->identities->login($identifier, $password, $ip, $userAgent, $remember);
        if ($error !== null) {
            $this->throttle->hit($throttleKey);
            return ['errors' => [], 'message' => $error];
        }

        $this->throttle->clear($throttleKey);
        return ['errors' => [], 'message' => null];
    }
}
