<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Yiisoft\Session\SessionInterface;

/**
 * Handles login/logout bookkeeping: session fixation protection, activity logs.
 */
final class IdentityRepository
{
    public const SESSION_KEY = 'user_id';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $logs,
    ) {}

    /**
     * Verify credentials and start a session. Returns error message or null on success.
     */
    public function login(string $identifier, string $password, string $ip, string $userAgent, bool $remember = false): ?string
    {
        $row = $this->users->findByIdentifier($identifier, true);

        if ($row === null) {
            $this->logs->create([
                'action' => 'auth.login_failed',
                'description' => 'Unknown identifier',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'metadata' => ['identifier' => $identifier],
            ]);
            return 'Invalid credentials. Please check your username and password.';
        }

        // A trashed account keeps its rows but must never open a session.
        if ($row['deleted_at'] !== null) {
            $this->logs->create([
                'user_id' => (int) $row['id'],
                'action' => 'auth.login_blocked',
                'description' => 'Login attempt on deleted account',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
            return 'This account has been removed. Contact support.';
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->logs->create([
                'user_id' => (int) $row['id'],
                'action' => 'auth.login_failed',
                'description' => 'Wrong password',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
            return 'Invalid credentials. Please check your username and password.';
        }

        if ($row['status'] !== 'active') {
            $this->logs->create([
                'user_id' => (int) $row['id'],
                'action' => 'auth.login_blocked',
                'description' => 'Login attempt on disabled account',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
            return 'This account has been disabled. Contact support.';
        }

        // Session fixation protection
        $this->session->regenerateID();
        $this->session->set(self::SESSION_KEY, (int) $row['id']);
        if ($remember) {
            $this->session->set('remember', true);
        }
        $this->users->touchLastLogin((int) $row['id']);
        $this->logs->create([
            'user_id' => (int) $row['id'],
            'action' => 'auth.login',
            'description' => 'User logged in',
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
        return null;
    }

    public function logout(): void
    {
        $userId = $this->session->get(self::SESSION_KEY);
        if ($userId !== null) {
            $this->logs->create(['user_id' => (int) $userId, 'action' => 'auth.logout']);
        }
        $this->session->remove(self::SESSION_KEY);
        $this->session->destroy();
    }

    public function register(array $data, string $ip, string $userAgent): int
    {
        $id = $this->users->create([
            'username' => $data['username'],
            // Passed through untouched by the caller's validation: the name is
            // the human half of the account, the username the login half.
            'full_name' => $data['full_name'] ?? '',
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password_hash' => $data['password_hash'],
            'role' => 'user',
            'status' => 'active',
            // Set by AuthService when the signup carried a valid ?ref= code;
            // the pending referral row itself is written by ReferralService,
            // which owns that lifecycle.
            'referred_by' => $data['referred_by'] ?? null,
        ]);
        $this->logs->create([
            'user_id' => $id,
            'action' => 'auth.register',
            'description' => 'New registration',
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
        return $id;
    }

    public static function sessionKey(): string
    {
        return self::SESSION_KEY;
    }
}
