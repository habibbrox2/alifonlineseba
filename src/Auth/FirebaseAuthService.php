<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\UserRepository;

/**
 * Provision an account from a verified Firebase ID token.
 *
 * Works with Firebase Authentication providers such as email/password, phone,
 * and federated identity.
 * The two-entry rule: if a verified email or phone already owns an account,
 * sign in as that account; otherwise create one.
 *
 * Nothing here touches a password — Firebase-authenticated accounts have none
 * and must not be invited to set one as a condition of signing in.
 */
final readonly class FirebaseAuthService
{
    /**
     * A phone that satisfies the `user.phone` NOT NULL / UNIQUE columns for a
     * Firebase account that never supplied one. Formatted as a plausible
     * Bangladeshi mobile (01XXXXXXXXX) so it looks right in the UI and in the
     * admin list, while being clearly synthetic (starts with 010, never a real
     * operator prefix) so support can tell it apart from a real number if it
     * ever comes up.
     */
    private const SYNTHETIC_PHONE_PREFIX = '010';

    public function __construct(private readonly UserRepository $users) {}

    /**
     * @param array{
     *     sub: string,
     *     email?: string,
     *     email_verified?: bool,
     *     name?: string|null,
     *     picture?: string|null,
     *     firebase_provider: string,
     *     phone_number?: string|null,
     * } $payload   the verified claims from {@see FirebaseIdTokenVerifier::verify()}.
     *
     * @return array{id: int, isNew: bool, username: string, fullName: string, avatar: ?string}
     */
    public function provision(array $payload, string $ip, string $userAgent): array
    {
        $email = isset($payload['email']) && $payload['email'] !== ''
            ? strtolower(trim($payload['email']))
            : '';
        $phone = isset($payload['phone_number']) && $payload['phone_number'] !== ''
            ? trim($payload['phone_number'])
            : '';

        // Try email first if available.
        if ($email !== '') {
            $existing = $this->users->findByEmail($email);
            if ($existing !== null) {
                return [
                    'id' => (int) $existing['id'],
                    'isNew' => false,
                    'username' => (string) $existing['username'],
                    'fullName' => (string) ($existing['full_name'] ?? ''),
                    'avatar' => $existing['avatar'] ?? null,
                ];
            }
        }

        // Fall back to phone lookup if no email match.
        if ($phone !== '') {
            $existing = $this->users->findActiveByPhone($phone);
            if ($existing !== null) {
                return [
                    'id' => (int) $existing['id'],
                    'isNew' => false,
                    'username' => (string) $existing['username'],
                    'fullName' => (string) ($existing['full_name'] ?? ''),
                    'avatar' => $existing['avatar'] ?? null,
                ];
            }
        }

        return $this->provisionNew($payload, $ip, $userAgent, $email, $phone);
    }

    /**
     * @return array{id: int, isNew: bool, username: string, fullName: string, avatar: ?string}
     */
    private function provisionNew(array $payload, string $ip, string $userAgent, string $email, string $phone): array
    {
        // Derive a stable username. Prefer email local-part, fall back to phone
        // tail, then provider prefix.
        $username = $this->usernameFromPayload($payload, $email, $phone);
        $username = $this->ensureUniqueUsername($username);

        $finalPhone = $phone !== '' ? $phone : self::SYNTHETIC_PHONE_PREFIX . bin2hex(random_bytes(5));
        $provider = (string) ($payload['firebase_provider'] ?? 'firebase');

        $id = $this->users->create([
            'username' => $username,
            'full_name' => trim((string) ($payload['name'] ?? '')),
            'phone' => $finalPhone,
            'email' => $email !== '' ? $email : null,
            'password_hash' => '', // no password — Firebase-authenticated only
            'role' => 'user',
            'status' => 'active',
            'firebase_provider' => $provider,
        ]);

        // Persist the Firebase UID so the account can be re-linked later.
        $this->users->update($id, [
            'firebase_uid' => (string) ($payload['sub'] ?? ''),
        ]);

        // Activity log for the account creation, so the sign-in method is
        // recoverable in the admin audit trail.
        $this->users->touchLastLogin($id);

        $avatar = self::safeUrl((string) ($payload['picture'] ?? ''));

        return [
            'id' => $id,
            'isNew' => true,
            'username' => $username,
            'fullName' => trim((string) ($payload['name'] ?? '')),
            'avatar' => $avatar,
        ];
    }

    /**
     * Derive a stable, readable username from the available claims.
     *
     * Prefers the email local-part (`alice@example.com` → `alice`), falls back
     * to the last 8 digits of the phone (`+8801712345678` → `1712345678`),
     * and finally to the provider name (`phone`, `google.com`, etc.).
     */
    private function usernameFromPayload(array $payload, string $email, string $phone): string
    {
        if ($email !== '') {
            $local = explode('@', $email, 2)[0] ?? '';
            $local = trim($local);
            $local = preg_replace('/[^a-zA-Z0-9._-]/', '', $local);
            $local = strtolower($local);
            if ($local !== '') {
                return mb_substr($local, 0, 32);
            }
        }

        if ($phone !== '') {
            $digits = preg_replace('/[^0-9]/', '', $phone) ?: '';
            if ($digits !== '') {
                return mb_substr($digits, -8) ?: 'phone';
            }
        }

        $provider = (string) ($payload['firebase_provider'] ?? 'firebase');
        $provider = preg_replace('/[^a-zA-Z0-9._-]/', '', $provider);
        $provider = strtolower($provider);
        if ($provider === '') {
            $provider = 'firebase';
        }

        return mb_substr($provider, 0, 32);
    }

    /**
     * Make the derived username unique against the UNIQUE index on username.
     */
    private function ensureUniqueUsername(string $base): string
    {
        if (!$this->users->usernameExists($base)) {
            return $base;
        }

        $suffix = 1;
        do {
            $candidate = $base . $suffix;
            $suffix++;
        } while ($this->users->usernameExists($candidate) && $suffix < 1000);

        return $candidate;
    }

    /**
     * A stable picture URL from the token's picture claim.
     */
    private static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
