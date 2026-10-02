<?php

declare(strict_types=1);

namespace App\Auth;

use App\Env;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Bearer API tokens for machine clients (the APK).
 *
 * Format on the wire: `<selector>.<verifier>`. The selector indexes the row
 * (so lookup is one indexed query); the verifier is checked with
 * hash_equals against an HMAC — a stolen database dump cannot mint tokens
 * because only the sha256 of the verifier is stored.
 */
final class ApiTokenRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Mint a new token for a user. Returns the plaintext in the response once
     * and stores only the hash.
     *
     * @return array{token: string, refresh: string, expires_at: string|null}
     */
    public function issue(int $userId, ?string $deviceLabel = null): array
    {
        $ttlDays = Env::int('API_TOKEN_TTL_DAYS', 30);
        $expiresAt = $ttlDays > 0 ? date('Y-m-d H:i:s', time() + $ttlDays * 86400) : null;

        [$selector, $verifierHash, $verifier] = self::generatePair();

        $this->db->createCommand()->insert('{{%api_token}}', [
            'user_id' => $userId,
            'token_hash' => $verifierHash,
            'device_label' => $deviceLabel !== null ? mb_substr($deviceLabel, 0, 120) : null,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ])->execute();
        $id = (int) $this->db->getLastInsertID();

        // The refresh token is a second row with a distinct selector; rotating
        // it on every use makes replay detectable.
        [$rSelector, $rVerifierHash, $rVerifier] = self::generatePair();
        $this->db->createCommand()->insert('{{%api_token}}', [
            'user_id' => $userId,
            'token_hash' => $rVerifierHash,
            'device_label' => ($deviceLabel !== null ? mb_substr($deviceLabel, 0, 100) : 'device') . ' [refresh]',
            'expires_at' => $expiresAt !== null ? date('Y-m-d H:i:s', time() + $ttlDays * 2 * 86400) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return [
            'token' => $selector . '.' . $verifier,
            'refresh' => $rSelector . '.' . $rVerifier,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Resolve a presented bearer token to its owning user id, or null.
     * Marks last_used_at (cheap, once per authenticated request).
     */
    public function resolve(string $bearer): ?int
    {
        $parts = explode('.', $bearer, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$selector, $verifier] = $parts;

        $row = $this->db
            ->createCommand('SELECT * FROM {{%api_token}} WHERE [[token_hash]] = :h LIMIT 1')
            ->bindValue(':h', self::verifierHashFor($selector, $verifier))
            ->queryOne();

        if (!is_array($row)) {
            return null;
        }
        if ($row['revoked_at'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        // Owner must still be an active, live account.
        $user = $this->db
            ->createCommand("SELECT [[id]] FROM {{%user}} WHERE [[id]] = :id AND [[status]] = 'active' AND [[deleted_at]] IS NULL")
            ->bindValue(':id', (int) $row['user_id'])
            ->queryScalar();
        if ($user === false) {
            return null;
        }

        $this->db
            ->createCommand()
            ->update('{{%api_token}}', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']])
            ->execute();

        return (int) $row['user_id'];
    }

    /** Rotate a refresh token: revoke the presented one, issue a fresh pair. */
    public function rotate(string $refreshBearer): ?array
    {
        $userId = $this->resolve($refreshBearer);
        if ($userId === null) {
            return null;
        }

        $parts = explode('.', $refreshBearer, 2);
        $this->revokeByHash(self::verifierHashFor($parts[0], $parts[1]));

        return $this->issue($userId);
    }

    /** Revoke the presented access token (logout). */
    public function revoke(string $bearer): bool
    {
        $parts = explode('.', $bearer, 2);
        if (count($parts) !== 2) {
            return false;
        }
        return $this->revokeByHash(self::verifierHashFor($parts[0], $parts[1]));
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->db
            ->createCommand()
            ->update(
                '{{%api_token}}',
                ['revoked_at' => date('Y-m-d H:i:s')],
                '[[user_id]] = :uid AND [[revoked_at]] IS NULL',
            )
            ->bindValue(':uid', $userId)
            ->execute();
    }

    private function revokeByHash(string $hash): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%api_token}}',
                ['revoked_at' => date('Y-m-d H:i:s')],
                '[[token_hash]] = :h AND [[revoked_at]] IS NULL',
            )
            ->bindValue(':h', $hash)
            ->execute() > 0;
    }

    /**
     * The stored hash binds the selector and verifier together, so a verifier
     * alone (from a breach of a *different* user's token) does not resolve.
     */
    private static function verifierHashFor(string $selector, string $verifier): string
    {
        return hash('sha256', $selector . ':' . $verifier . ':' . self::appKey());
    }

    /**
     * @return array{0: string, 1: string} selector + the hash to STORE
     */
    private static function generatePair(): array
    {
        $selector = bin2hex(random_bytes(8));
        // The verifier is a random secret — NOT derivable from the selector,
        // otherwise a leaked database row would reveal the whole token.
        $verifier = bin2hex(random_bytes(32));

        return [$selector, self::verifierHashFor($selector, $verifier), $verifier];
    }

    private static function appKey(): string
    {
        return (string) Env::get('APP_KEY', '');
    }
}
