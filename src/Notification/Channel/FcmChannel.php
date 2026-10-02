<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Repository\DeviceRepository;

/**
 * FCM HTTP v1 push channel. Uses the Firebase HTTP v1 API directly via curl
 * with an OAuth2 service-account token — no SDK dependency, which keeps the
 * shared-hosting build-free constraint intact.
 *
 * When the credentials file is absent the channel reports itself unavailable
 * and the worker skips its jobs instead of erroring — so Phase 1 ships with
 * push queued but dormant until the service-account JSON lands.
 */
final class FcmChannel
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        private readonly DeviceRepository $devices,
    ) {}

    public function isAvailable(): bool
    {
        return self::credentialsPath() !== ''
            && is_file(self::credentialsPath())
            && (string) \App\Env::get('FIREBASE_PROJECT_ID', '') !== '';
    }

    /**
     * Dry-run validation for `app:fcm:check`. Returns '' when everything is
     * in place — config present, credentials parse, and an OAuth access token
     * can actually be minted — or the failure reason.
     */
    public function credentialsError(): string
    {
        if ((string) \App\Env::get('FIREBASE_PROJECT_ID', '') === '') {
            return 'FIREBASE_PROJECT_ID is not set.';
        }
        $path = self::credentialsPath();
        if ($path === '') {
            return 'FIREBASE_CREDENTIALS_PATH is not set.';
        }
        if (!is_file($path) || !is_readable($path)) {
            return "FIREBASE_CREDENTIALS_PATH does not point to a readable file: {$path}";
        }
        $creds = json_decode((string) file_get_contents($path), true);
        if (!is_array($creds) || !isset($creds['client_email'], $creds['private_key'])) {
            return 'Credentials file is not a valid service-account JSON (client_email / private_key missing).';
        }
        if ($this->accessToken() === null) {
            return 'OAuth token fetch failed — the service account may be disabled, the key revoked, or server clock skewed.';
        }
        return '';
    }

    /**
     * Send one message to one raw device token — used by `app:fcm:check
     * --token=...` to prove the whole delivery path (auth → v1 API → device)
     * without routing through the queue.
     *
     * @param array<string, mixed> $payload {title, body, data}
     */
    public function sendToToken(string $deviceToken, array $payload): DeliveryResult
    {
        if (!$this->isAvailable()) {
            return DeliveryResult::permanent('FCM credentials not configured.');
        }
        $token = $this->accessToken();
        if ($token === null) {
            return DeliveryResult::transient('FCM OAuth token fetch failed.');
        }
        return $this->sendToOne($token, $deviceToken, $payload);
    }

    private static function credentialsPath(): string
    {
        $path = trim((string) \App\Env::get('FIREBASE_CREDENTIALS_PATH', ''));
        if ($path !== '' && !str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:[/\\\\]#', $path)) {
            // Relative paths resolve against the project root whatever the
            // current working directory is — cron entries and web requests
            // do not share one.
            $path = dirname(__DIR__, 3) . '/' . $path;
        }
        return $path;
    }

    /**
     * Send to every active device of one user. FCM tokens rotate, so the
     * multicast covers all of them and dead tokens are deactivated on the way.
     *
     * @param array<string, mixed> $payload {title, body, data}
     */
    public function send(int $userId, array $payload): DeliveryResult
    {
        if (!$this->isAvailable()) {
            return DeliveryResult::permanent('FCM credentials not configured.');
        }

        $tokens = $this->devices->activeTokens($userId);
        if ($tokens === []) {
            // No device is not a failure — the user simply has not installed
            // the app. Nothing to retry.
            return DeliveryResult::sent();
        }

        $token = $this->accessToken();
        if ($token === null) {
            return DeliveryResult::transient('FCM OAuth token fetch failed.');
        }

        $dead = [];
        $lastId = '';
        $sent = 0;
        $start = (int) (microtime(true) * 1000);

        foreach ($tokens as $deviceToken) {
            $result = $this->sendToOne($token, $deviceToken, $payload);
            if ($result->ok) {
                $sent++;
                $lastId = $result->providerMessageId;
            } elseif (!$result->retryable) {
                // UNREGISTERED / INVALID_ARGUMENT: the token is dead forever.
                $dead[] = $deviceToken;
            }
        }

        if ($dead !== []) {
            $this->devices->deactivateTokens($dead);
        }

        $latency = (int) (microtime(true) * 1000) - $start;
        if ($sent === 0 && count($dead) === count($tokens)) {
            // Every token was dead: the deactivation IS the fix, so report
            // success (nothing left to deliver to).
            return DeliveryResult::sent('', $latency);
        }
        if ($sent === 0) {
            return DeliveryResult::transient('FCM send failed for all tokens.', $latency);
        }

        return DeliveryResult::sent($lastId, $latency);
    }

    /** @param array<string, mixed> $payload */
    private function sendToOne(string $oauthToken, string $deviceToken, array $payload): DeliveryResult
    {
        $projectId = (string) \App\Env::get('FIREBASE_PROJECT_ID', '');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $body = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => (string) ($payload['title'] ?? ''),
                    'body' => (string) ($payload['body'] ?? ''),
                ],
                // The data payload is what deep links read; the human reference
                // only, never the auto-increment id.
                'data' => array_map('strval', (array) ($payload['data'] ?? [])),
                'android' => ['priority' => ($payload['priority'] ?? 5) <= 2 ? 'HIGH' : 'NORMAL'],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $oauthToken,
                'Content-Type: application/json; UTF-8',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($response, true);
            return DeliveryResult::sent((string) ($decoded['name'] ?? ''), 0);
        }

        $error = mb_substr($response !== '' ? $response : "HTTP {$status}", 0, 300);
        // 404 (unregistered token), 400 (bad token) are permanent; 5xx and 429 retry.
        $permanent = $status === 404 || $status === 400;
        return $permanent ? DeliveryResult::permanent($error) : DeliveryResult::transient($error);
    }

    /** Exchange the service-account JWT for an OAuth2 access token. */
    private function accessToken(): ?string
    {
        $json = (string) file_get_contents(self::credentialsPath());
        $creds = json_decode($json, true);
        if (!is_array($creds) || !isset($creds['client_email'], $creds['private_key'])) {
            return null;
        }

        $now = time();
        $assertion = [
            'iss' => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::OAUTH_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        // kid is required whenever the service account holds more than one
        // key (the system-managed one + ours) — without it Google cannot pick
        // the key and rejects the grant with invalid_scope.
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if (isset($creds['private_key_id'])) {
            $header['kid'] = (string) $creds['private_key_id'];
        }
        $jwt = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . $this->base64Url(json_encode($assertion, JSON_THROW_ON_ERROR));
        openssl_sign($jwt, $signature, $creds['private_key'], 'sha256WithRSAEncryption');
        $jwt .= '.' . $this->base64Url($signature);

        $ch = curl_init(self::OAUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = (string) curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($response, true);
        return is_array($decoded) && isset($decoded['access_token']) ? (string) $decoded['access_token'] : null;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
