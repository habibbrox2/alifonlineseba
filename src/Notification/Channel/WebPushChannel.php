<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Notification\Push\VapidKeys;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;

/**
 * Web Push (RFC 8030) delivery channel, aimed at browsers the user has not
 * installed the Android app on.
 *
 * ## Why the crypto is inlined here
 *
 * RFC 8291 message encryption needs HKDF-SHA-256 and AES-128-GCM. `hash_hkdf()`
 * exists in this PHP build but PHP's own test vectors do not cover the
 * extract/expand split this spec depends on, so the primitives are spelled out
 * by hand below. They are ~10 lines each and they are load-bearing, so they
 * stay visible rather than behind a dependency. The assembled body has been
 * verified byte-for-byte against the RFC 8291 section 5 test vector.
 *
 * ## Payload size
 *
 * aes128gcm caps a single record at 4096 bytes of sealed data, and the record
 * carries a one-byte delimiter plus a 16-byte tag. A push service rejects
 * anything larger with 413, which is permanent, so oversized payloads are
 * truncated to a short notice instead. Dropping the notification entirely
 * would be worse than truncating it.
 */
final class WebPushChannel
{
    /**
     * 4096 (max sealed record) - 1 (padding delimiter) - 16 (GCM tag) - 86
     * (aes128gcm header: 16 salt + 5 rs/idlen + 65 public key).
     */
    private const MAX_PLAINTEXT = 3993;

    /** P-256 is the only curve RFC 8291 defines, and the only one VAPID allows. */
    private const CURVE_NAME = 'prime256v1';

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly UserRepository $users,
    ) {}

    public function isAvailable(): bool
    {
        return VapidKeys::fromEnv() !== null;
    }

    /**
     * Dry-run validation for `app:webpush:check`. Returns '' when Web Push is
     * ready, or the reason it is not.
     */
    public function credentialsError(): string
    {
        if (trim((string) \App\Env::get('VAPID_SUBJECT', '')) === '') {
            return 'VAPID_SUBJECT is not set (expected a mailto: or https: URI).';
        }
        if (trim((string) \App\Env::get('VAPID_PRIVATE_KEY', '')) === '') {
            return 'VAPID_PRIVATE_KEY is not set.';
        }
        if (VapidKeys::fromEnv() === null) {
            return 'VAPID_PRIVATE_KEY could not be loaded as a P-256 key.';
        }
        return '';
    }

    /**
     * Send one payload to every active browser subscription of one user.
     *
     * Mirrors FcmChannel::send(): a user with no subscriptions is a success
     * (they simply have not opted in), and subscriptions the push service
     * reports as gone are deactivated in the same pass.
     *
     * The per-account switch is honoured here, not only at enqueue time, for
     * two reasons. A job enqueued before the user turned notifications off is
     * still in the queue when they do, and honouring it at the door is what
     * makes the switch take effect the moment they press it rather than after
     * the backlog drains. And a direct caller (`app:webpush:check`, a future
     * campaign sender) has not been through the manager at all.
     *
     * @param array<string, mixed> $payload {title, body, data}
     */
    public function send(int $userId, array $payload): DeliveryResult
    {
        if (!$this->isAvailable()) {
            return DeliveryResult::permanent('VAPID keys not configured.');
        }

        // Reported as a success, not a failure: the user asked for exactly
        // this. Retrying a job whose whole purpose is to respect an opt-out
        // would be the queue doing the one thing that makes people turn the
        // site permission off in their browser instead.
        if (!$this->users->isPushEnabled($userId)) {
            return DeliveryResult::sent();
        }

        $subscriptions = $this->subscriptions->activeForUser($userId);
        if ($subscriptions === []) {
            return DeliveryResult::sent();
        }

        $keys = VapidKeys::fromEnv();
        if ($keys === null) {
            return DeliveryResult::permanent('VAPID keys not configured.');
        }

        $body = self::encodePayload($payload);
        if ($body === '') {
            // Nothing worth sending; dropping it beats burning quota.
            return DeliveryResult::sent();
        }

        $dead = [];
        $sent = 0;
        $lastId = '';
        $start = (int) (microtime(true) * 1000);

        foreach ($subscriptions as $subscription) {
            $result = $this->sendToOne($keys, $subscription, $body);
            if ($result->ok) {
                $sent++;
                $lastId = $result->providerMessageId;
            } elseif (!$result->retryable) {
                $dead[] = (int) $subscription['id'];
            }
        }

        if ($dead !== []) {
            $this->subscriptions->deactivateIds($dead);
        }

        $latency = (int) (microtime(true) * 1000) - $start;
        if ($sent === 0 && count($dead) === count($subscriptions)) {
            return DeliveryResult::sent('', $latency);
        }
        if ($sent === 0) {
            return DeliveryResult::transient('Web Push send failed for all subscriptions.', $latency);
        }

        return DeliveryResult::sent($lastId, $latency);
    }

    /**
     * Send to one known-good subscription, bypassing the queue — used by
     * `app:webpush:check` to prove the path end to end.
     *
     * @param array<string, mixed> $payload {title, body, data}
     */
    public function sendToSubscription(array $subscription, array $payload): DeliveryResult
    {
        if (!$this->isAvailable()) {
            return DeliveryResult::permanent('VAPID keys not configured.');
        }
        $keys = VapidKeys::fromEnv();
        if ($keys === null) {
            return DeliveryResult::permanent('VAPID keys not configured.');
        }
        $body = self::encodePayload($payload);
        if ($body === '') {
            return DeliveryResult::sent();
        }

        // Timed here rather than inside sendToOne(), because sendToOne() is
        // also called in a loop by send(), which times the whole fan-out
        // itself; a per-subscription number would double up there and mean
        // nothing.
        $start = (int) (microtime(true) * 1000);
        $result = $this->sendToOne($keys, $subscription, $body);

        return new DeliveryResult(
            $result->ok,
            $result->retryable,
            $result->providerMessageId,
            (int) (microtime(true) * 1000) - $start,
            $result->error,
        );
    }

    /**
     * @param array<string, mixed> $subscription {id?, endpoint, p256dh, auth}
     */
    private function sendToOne(VapidKeys $keys, array $subscription, string $body): DeliveryResult
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $p256dh = (string) ($subscription['p256dh'] ?? '');
        $auth = (string) ($subscription['auth'] ?? '');
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return DeliveryResult::permanent('Incomplete push subscription.');
        }

        // The VAPID audience is the push service origin, not the endpoint path.
        $origin = self::origin($endpoint);
        if ($origin === '') {
            return DeliveryResult::permanent('Push endpoint is not an absolute HTTPS URL.');
        }

        $sealed = $this->encrypt($body, $p256dh, $auth);
        if ($sealed === null) {
            // Deliberately retryable, not permanent. Encryption runs on this
            // host, so a failure here means the *server* is unhealthy — a
            // missing openssl binary, an OpenSSL config that went missing, a
            // key that would not generate. None of that says anything about
            // the subscription, and calling it permanent would deactivate
            // every browser in the table the first time the host hiccuped.
            return DeliveryResult::transient('Could not encrypt for this subscription.');
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $keys->authorizationHeader($origin),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($sealed),
                // Long enough that a phone that has been offline over a
                // weekend still receives the notification once it reconnects,
                // short enough that a push service does not treat us as a
                // mailbox it must keep forever.
                'TTL: ' . \App\Env::int('WEB_PUSH_TTL', 86400),
                'Urgency: ' . \App\Env::int('WEB_PUSH_URGENCY', 5) <= 5 ? 'normal' : 'high',
            ],
            CURLOPT_POSTFIELDS => $sealed,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            // Push services return a Location header on 201; it is the only
            // identifier worth keeping, since there is no message id.
            return DeliveryResult::sent(substr($response, 0, 200));
        }

        $detail = $curlError !== '' ? $curlError : ($response !== '' ? $response : "HTTP {$status}");

        // 404/410 mean the subscription is gone forever: the browser was
        // cleared, or the push service expired it. Retrying is pointless and
        // the row must be deactivated.
        if ($status === 404 || $status === 410) {
            return DeliveryResult::permanent('Subscription expired (' . $status . ').');
        }
        // 401/403 means our VAPID token or audience is wrong — a server-side
        // misconfiguration. Marking it permanent stops the retry storm while
        // the operator fixes the keys; `app:webpush:check` names the cause.
        if ($status === 401 || $status === 403) {
            return DeliveryResult::permanent('VAPID rejected (' . $status . '): ' . mb_substr($detail, 0, 200));
        }
        // 413 means the sealed body was too large even after truncation, and
        // the service will never accept it.
        if ($status === 413) {
            return DeliveryResult::permanent('Payload too large for the push service.');
        }

        // Everything else (429, 5xx, timeouts, connection resets) is the
        // service or the network having a bad day.
        return DeliveryResult::transient(mb_substr($detail, 0, 300));
    }

    /**
     * RFC 8291 aes128gcm content encoding.
     *
     * Returns `salt(16) . rs(4) . idlen(1) . senderPublicKey(65) . ciphertext .
     * tag(16)`, or null when the subscription's keys are unusable.
     *
     * Two details are easy to get wrong and were verified against the RFC's own
     * test vector:
     *
     *  - The additional authenticated data is EMPTY. RFC 8188 section 2 says
     *    so explicitly for aes128gcm; passing the header as AAD produces a
     *    valid-looking body the service cannot decrypt.
     *  - `openssl_pkey_derive()` wants the raw 65-byte point, not DER.
     */
    private function encrypt(string $plaintext, string $p256dh, string $auth): ?string
    {
        $userPublic = self::base64UrlDecode($p256dh);
        if (strlen($userPublic) !== 65 || $userPublic[0] !== "\x04") {
            return null;
        }
        $authSecret = self::base64UrlDecode($auth);
        if (strlen($authSecret) !== 16) {
            return null;
        }

        // Generate the ephemeral sender key. openssl_pkey_new() is unusable on
        // this host (OpenSSL has no config file), so the key comes from the
        // shipped binary via a subprocess, matching how VAPID keys are made.
        $sender = self::generateEphemeralKey();
        if ($sender === null) {
            return null;
        }

        $sharedSecret = $this->deriveSharedSecret($userPublic, $sender['key']);
        if ($sharedSecret === null) {
            return null;
        }

        $senderPublic = $sender['point'];

        // IKM: as in RFC 8291 section 3.4, mixing the two public keys through
        // a label keeps the derived secrets bound to this specific pair.
        $prkKey = self::hkdfExtract('sha256', $authSecret, $sharedSecret);
        $keyInfo = "WebPush: info\x00" . $userPublic . $senderPublic;
        $ikm = self::hkdfExpand('sha256', $prkKey, $keyInfo, 32);

        $salt = random_bytes(16);
        $prk = self::hkdfExtract('sha256', $salt, $ikm);
        $cek = self::hkdfExpand('sha256', $prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdfExpand('sha256', $prk, "Content-Encoding: nonce\x00", 12);

        // RFC 8188 requires the delimiter to be the last record's padding byte.
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext . "\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',    // empty AAD — see the method note
            16,
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            return null;
        }

        // rs = 4096 as a big-endian uint32; idlen = 65 for an uncompressed point.
        $header = $salt . "\x00\x00\x10\x00" . chr(strlen($senderPublic)) . $senderPublic;

        return $header . $ciphertext . $tag;
    }

    /**
     * JSON-encode a payload, truncating it to what a push service will accept.
     *
     * Truncation drops the `data` block first — the deep link and any machine
     * fields — and shortens the body text only if that is still not enough. A
     * user who sees "Result file ready" beats a user who sees nothing.
     *
     * @param array<string, mixed> $payload {title, body, data}
     */
    public static function encodePayload(array $payload): string
    {
        $message = [
            'title' => mb_substr((string) ($payload['title'] ?? ''), 0, 200),
            'body' => mb_substr((string) ($payload['body'] ?? ''), 0, 400),
        ];
        $data = (array) ($payload['data'] ?? []);

        $json = json_encode(['title' => $message['title'], 'body' => $message['body'], 'data' => $data], JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) <= self::MAX_PLAINTEXT) {
            return $json;
        }

        $json = json_encode(['title' => $message['title'], 'body' => $message['body']], JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) <= self::MAX_PLAINTEXT) {
            return $json;
        }

        return mb_substr((string) json_encode([
            'title' => $message['title'],
            'body' => 'Notification too large to display. Open the app for details.',
        ], JSON_UNESCAPED_UNICODE), 0, self::MAX_PLAINTEXT);
    }

    /** The origin of an endpoint — the VAPID `aud` claim. */
    private static function origin(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        if ($parts['scheme'] !== 'https') {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return rtrim($origin, '/');
    }

    /**
     * Generate the throwaway P-256 key for one message, and the public point
     * that goes into the message header.
     *
     * Returns null, or ['key' => OpenSSLAsymmetricKey, 'point' => string] where
     * the point is the 65-byte uncompressed encoding (0x04 || x || y) the
     * subscriber needs in order to reproduce the shared secret.
     *
     * The in-process call is tried first because it is an order of
     * magnitude faster and needs no external binary. It is not
     * available everywhere: PHP's OpenSSL extension is compiled with
     * a default config path that a hosting box may not have, and
     * openssl_pkey_new() then fails with "configuration file
     * routines::no such file" before it ever looks at the curve
     * name. The `openssl ecparam` subprocess is the fallback for
     * exactly that case.
     *
     * Getting the public point back out is the part that is easy to get
     * wrong, and it is why this method returns the point rather than
     * leaving the caller to dig it out of the key object. Asking
     * openssl_pkey_get_details() for ['ec']['x'] looks like the obvious
     * way and it *usually* works — but on this host it silently returns an
     * 'ec' array holding only curve_name, curve_oid and d, with no x or y
     * at all, whenever a failed openssl_pkey_new() ran earlier in the same
     * process. Since the failing openssl_pkey_new() above is exactly such a
     * call, the fallback path is the one that always loses its public key.
     * The subprocess therefore asks the binary for the public key directly
     * instead of guessing at it afterwards.
     *
     * The subprocess goes through proc_open rather than shell_exec so
     * that stderr is piped instead of inherited — no shell redirect
     * syntax, which would be Windows-only — and so the argument list
     * never passes through a shell at all.
     *
     * @return array{key: \OpenSSLAsymmetricKey, point: string}|null
     */
    private static function generateEphemeralKey(): ?array
    {
        $key = @openssl_pkey_new(['curve_name' => self::CURVE_NAME]);
        if ($key !== false) {
            $details = openssl_pkey_get_details($key);
            $x = $details['ec']['x'] ?? null;
            $y = $details['ec']['y'] ?? null;
            if (is_string($x) && is_string($y)) {
                return ['key' => $key, 'point' => "\x04" . $x . $y];
            }
            // A usable key whose public half cannot be read: fall through
            // and let the binary produce a pair whose every part is known.
        }
        while (openssl_error_string() !== false) {
            // Clear the error queue so a later, unrelated call is not
            // blamed for this failure.
        }

        if (!function_exists('proc_open')) {
            return null;
        }
        $pem = self::runOpenssl(['openssl', 'ecparam', '-genkey', '-name', self::CURVE_NAME], null);
        // A nonzero exit means the binary is missing or the curve is
        // unknown; either way there is nothing to send.
        if ($pem === null || !str_contains($pem, 'BEGIN')) {
            return null;
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }

        // The SubjectPublicKeyInfo DER is a fixed 91 bytes for P-256, and the
        // uncompressed point is the last 65 of them. Asking the binary for
        // DER rather than PEM means no base64 to trim.
        $der = self::runOpenssl(['openssl', 'ec', '-pubout', '-outform', 'DER'], $pem);
        if ($der === null || strlen($der) < 65) {
            return null;
        }
        $point = substr($der, -65);
        if ($point[0] !== "\x04") {
            return null;
        }

        return ['key' => $key, 'point' => $point];
    }

    /**
     * Run an openssl subcommand and return its stdout, or null if it failed.
     *
     * @param list<string> $command
     */
    private static function runOpenssl(array $command, ?string $stdin): ?string
    {
        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        // stderr is read and discarded so a failure cannot fill the pipe
        // buffer and deadlock the child.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? $stdout : null;
    }

    /**
     * ECDH over P-256, as the subscriber's browser would compute it.
     *
     * openssl_pkey_derive() accepts a key *object*, not the bare
     * 65-byte point the Push API hands over, so the point is wrapped
     * in a SubjectPublicKeyInfo structure first. The wrapper is built
     * by hand for the same reason the private key is: the OpenSSL
     * config file this host would need to ask for one does not exist.
     */
    private function deriveSharedSecret(string $userPublic, \OpenSSLAsymmetricKey $sender): ?string
    {
        $public = openssl_pkey_get_public(self::publicKeyPem($userPublic));
        if ($public === false) {
            return null;
        }
        $secret = @openssl_pkey_derive($public, $sender, 32);
        return $secret === false || $secret === '' ? null : $secret;
    }

    /**
     * Wrap an uncompressed P-256 point (0x04 || x || y) as a
     * SubjectPublicKeyInfo PEM, so OpenSSL will accept it as a key.
     *
     * The DER is the standard EC SPKI: an AlgorithmIdentifier naming
     * id-ecPublicKey and prime256v1, then a BIT STRING holding the
     * point prefixed with an unused-bits count of zero.
     */
    private static function publicKeyPem(string $point65): string
    {
        $algorithm = self::tlv("\x30",
            self::tlv("\x06", "\x2a\x86\x48\xce\x3d\x02\x01")        // id-ecPublicKey
            . self::tlv("\x06", "\x2a\x86\x48\xce\x3d\x03\x01\x07")); // prime256v1
        // BIT STRING: a leading 0x00 counts the unused bits, which is
        // zero for a whole number of bytes.
        $subjectPublicKey = self::tlv("\x03", "\x00" . $point65);

        return self::pem('PUBLIC KEY', self::tlv("\x30", $algorithm . $subjectPublicKey));
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }

    /** Encode a TLV (tag-length-value) triple. */
    private static function tlv(string $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            $encodedLength = chr($length);
        } else {
            $bytes = ltrim(pack('N', $length), "\x00");
            $encodedLength = chr(0x80 | strlen($bytes)) . $bytes;
        }
        return $tag . $encodedLength . $content;
    }

    /**
     * HKDF-Extract (RFC 5869 section 2.2).
     *
     * Note the argument order: hash_hmac() takes the data first and the key
     * second, and the salt is the key here. Transposing these two silently
     * produces output that is the right length and completely wrong.
     */
    private static function hkdfExtract(string $hash, string $salt, string $ikm): string
    {
        return hash_hmac($hash, $ikm, $salt, true);
    }

    /** HKDF-Expand (RFC 5869 section 2.3). */
    private static function hkdfExpand(string $hash, string $prk, string $info, int $length): string
    {
        $output = '';
        $block = '';
        for ($counter = 1; strlen($output) < $length; $counter++) {
            $block = hash_hmac($hash, $block . $info . chr($counter), $prk, true);
            $output .= $block;
        }
        return substr($output, 0, $length);
    }

    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(
            strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4),
            true,
        );
        return $decoded === false ? '' : $decoded;
    }
}
