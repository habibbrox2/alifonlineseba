<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\UserRepository;

/**
 * Provision an account from a verified Firebase/Google ID token.
 *
 * The two-entry rule: if a verified email already owns an account, sign in as
 * that account; otherwise create one. The created account gets a synthetic
 * username (derived from the email local-part so it is stable and human-readable
 * without being the email itself) and a synthetic phone so the NOT NULL /
 * UNIQUE constraints on `user.phone` are satisfied for accounts that register
 * without ever providing a phone.
 *
 * Nothing here touches a password — Google-signed-in accounts have none and
 * must not be invited to set one as a condition of signing in.
 */
final readonly class GoogleSignInService
{
    /**
     * A phone that satisfies the `user.phone` NOT NULL / UNIQUE columns for a
     * Google-only account that never supplied one. Formatted as a plausible
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
     *     email: string,
     *     email_verified: bool,
     *     name?: string|null,
     *     picture?: string|null,
     *     firebase_provider: string,
     * } $payload   the verified claims from {@see GoogleIdTokenVerifier::verify()}.
     *
     * @return array{id: int, isNew: bool, username: string, fullName: string, avatar: ?string}
     */
    public function provision(array $payload, string $ip, string $userAgent): array
    {
        $email = strtolower(trim($payload['email']));

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            // $existing is a full row array from findByEmail(), not an id —
            // use it directly instead of re-querying by id.
            $row = $existing;
            if ($row === null) {
                return $this->provisionNew($payload, $ip, $userAgent);
            }

            return [
                'id' => (int) $row['id'],
                'isNew' => false,
                'username' => (string) $row['username'],
                'fullName' => (string) ($row['full_name'] ?? ''),
                'avatar' => $row['avatar'] ?? null,
            ];
        }

        return $this->provisionNew($payload, $ip, $userAgent);
    }

    /**
     * @return array{id: int, isNew: bool, username: string, fullName: string, avatar: ?string}
     */
    private function provisionNew(array $payload, string $ip, string $userAgent): array
    {
        $email = strtolower(trim($payload['email']));
        $username = $this->usernameFromEmail($email);
        $username = $this->ensureUniqueUsername($username);

        $phone = self::SYNTHETIC_PHONE_PREFIX . bin2hex(random_bytes(5));
        $provider = (string) ($payload['firebase_provider'] ?? 'google.com');

        $id = $this->users->create([
            'username' => $username,
            'full_name' => trim((string) ($payload['name'] ?? '')),
            'phone' => $phone,
            'email' => $email,
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
     * A stable, readable username derived from the email local-part.
     *
     * `alice@example.com` → `alice`. The local-part is the part a person chose
     * when they created the Google account, so it usually reads as a name or
     * handle; the domain is dropped because it is not a property of the person.
     */
    private function usernameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0] ?? '';
        $local = trim($local);

        // Keep only the characters a username allows, downcase, and cap the
        // length to what the column does — otherwise a long Google handle would
        // not fit.
        $local = preg_replace('/[^a-zA-Z0-9._-]/', '', $local);
        $local = strtolower($local);

        if ($local === '') {
            $local = 'google';
        }

        return mb_substr($local, 0, 32);
    }

    /**
     * Make the derived username unique against the UNIQUE index on username.
     *
     * Two people whose emails both look like `alice@...` (rare, but possible
     * with aliases) would collide on the derived name, so the second one gets
     * a short suffix.
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
     *
     * The verifier already enforces https-only and length, so this is purely a
     * normalisation guard: a missing or empty picture leaves no avatar rather
     * than a broken `<img src>`.
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
