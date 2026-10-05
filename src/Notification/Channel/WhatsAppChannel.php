<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Env;
use App\Repository\UserRepository;

/**
 * WhatsApp channel — official Meta Cloud API only.
 *
 * The project audit (docs/notification-architecture-audit.md §8) rules out
 * whatsapp-web.js / Baileys and friends: they get the sending number banned
 * and break without notice. So this is the one documented HTTP endpoint:
 *
 *     POST https://graph.facebook.com/v20.0/{PHONE_NUMBER_ID}/messages
 *     Authorization: Bearer {WHATSAPP_TOKEN}
 *
 * Configuration (all in env, never in the database):
 *   WHATSAPP_TOKEN            — long-lived system-user access token
 *   WHATSAPP_PHONE_NUMBER_ID  — the sender id from the Meta console
 *
 * Without both the channel reports itself unavailable and the worker
 * dead-letters its jobs with a message an operator can act on, exactly like
 * FCM and Telegram do — a channel that silently drops messages is worse than
 * one that says it is off.
 *
 * ## Why the 24-hour window matters to this code
 *
 * Meta only allows free-form `text` messages inside a 24-hour customer
 * service window; anything outside it must be an *approved template*. An
 * order-completed alert sent to somebody who has not written in recently is
 * exactly the outside-window case, so `WHATSAPP_TEMPLATE` (the template name)
 * and `WHATSAPP_TEMPLATE_LANGUAGE` are honoured when set: the body is sent as
 * the template's single `{{1}}` parameter. When no template is configured the
 * message goes as free text, which is correct for the first 24 hours and for
 * deployments still in Meta's conversation window — and it fails loudly
 * rather than silently when Meta rejects it.
 */
final class WhatsAppChannel
{
    private const API = 'https://graph.facebook.com/v20.0/';

    public function __construct(private readonly UserRepository $users) {}

    public function isAvailable(): bool
    {
        return trim((string) Env::get('WHATSAPP_TOKEN', '')) !== ''
            && trim((string) Env::get('WHATSAPP_PHONE_NUMBER_ID', '')) !== '';
    }

    /** '' when the channel may send, otherwise the reason it cannot. */
    public function blocker(): string
    {
        if (($blocker = CurlSupport::blocker()) !== '') {
            return $blocker;
        }
        if (trim((string) Env::get('WHATSAPP_TOKEN', '')) === '') {
            return 'WHATSAPP_TOKEN is not set.';
        }
        if (trim((string) Env::get('WHATSAPP_PHONE_NUMBER_ID', '')) === '') {
            return 'WHATSAPP_PHONE_NUMBER_ID is not set.';
        }
        return '';
    }

    /**
     * Queue worker entry point: one job, one user, their WhatsApp number.
     *
     * @param array<string, mixed> $payload {title, body, data}
     */
    public function send(int $userId, array $payload): DeliveryResult
    {
        if (($blocker = $this->blocker()) !== '') {
            return DeliveryResult::permanent($blocker);
        }

        $to = $this->users->contactOn('whatsapp', $userId);
        if ($to === null) {
            // No number on the account is not a failure — the fan-out gate
            // should not have queued this row at all. Reporting success keeps
            // the queue from retrying a row that can never succeed.
            return DeliveryResult::sent();
        }

        $text = trim(((string) ($payload['title'] ?? '')) . "\n" . ((string) ($payload['body'] ?? '')));

        return $this->sendText($to, $text);
    }

    /**
     * Send one free-form (or templated) text to one number. Used by the queue
     * worker above and by the password-reset OTP flow, which must be delivered
     * now rather than on the next cron tick.
     */
    public function sendText(string $to, string $text): DeliveryResult
    {
        if (($blocker = $this->blocker()) !== '') {
            return DeliveryResult::permanent($blocker);
        }

        $recipient = self::toE164($to);
        if ($recipient === '') {
            return DeliveryResult::permanent('WhatsApp number is not a valid phone number.');
        }

        $template = trim((string) Env::get('WHATSAPP_TEMPLATE', ''));
        $message = $template !== ''
            ? [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) Env::get('WHATSAPP_TEMPLATE_LANGUAGE', 'en')],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]],
                    ],
                ],
            ]
            : [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['body' => $text],
            ];

        $start = (int) (microtime(true) * 1000);
        $ch = curl_init(self::API . trim((string) Env::get('WHATSAPP_PHONE_NUMBER_ID', '')) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . trim((string) Env::get('WHATSAPP_TOKEN', '')),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $latency = (int) (microtime(true) * 1000) - $start;

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($response, true);
            $messages = is_array($decoded) ? ($decoded['messages'] ?? []) : [];
            $messageId = (string) ($messages[0]['id'] ?? '');
            return DeliveryResult::sent($messageId, $latency);
        }

        $error = mb_substr($response !== '' ? $response : "HTTP {$status}", 0, 300);
        // 4xx other than 429 is permanent: bad number, rejected template, bad
        // token. Retrying those every minute is how a queue fills with rows
        // that will never move.
        $permanent = $status >= 400 && $status < 500 && $status !== 429;
        return $permanent ? DeliveryResult::permanent($error, $latency) : DeliveryResult::transient($error, $latency);
    }

    /**
     * Local (01712345678) or international (+88017…) → E.164 without the plus.
     *
     * Bangladesh-local numbers are the normal input on this site, so `0…` is
     * prefixed with 880; anything already international is stripped of its
     * `+` and passed through. An unparseable value yields '' and the caller
     * reports it rather than letting Meta reject a malformed `to`.
     */
    public static function toE164(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '880')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '880' . substr($digits, 1);
        }
        return $digits;
    }
}
