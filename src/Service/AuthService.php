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
    ) {}

    /**
     * @return array{errors: array<string,string>, userId: ?int}
     */
    public function register(array $input, string $ip, string $userAgent): array
    {
        $errors = [];
        $username = trim((string) ($input['username'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');

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

        $userId = $this->identities->register([
            'username' => $username,
            'phone' => $phone,
            'email' => $email ?: null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ], $ip, $userAgent);

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
