<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Repository\BotConnectionRepository;

/**
 * Telegram Bot API channel — admin recipients only (audit §10.1). The bot
 * token lives in env and is never rendered or logged; a missing token makes
 * the channel permanently unavailable so the worker skips cleanly.
 */
final class TelegramChannel
{
    private const API = 'https://api.telegram.org/bot';

    public function __construct(private readonly BotConnectionRepository $bots) {}

    public function isAvailable(): bool
    {
        return trim((string) \App\Env::get('TELEGRAM_BOT_TOKEN', '')) !== '';
    }

    /**
     * @param array<string, mixed> $payload {title, body, data}
     */
    public function send(int $userId, array $payload): DeliveryResult
    {
        if (!$this->isAvailable()) {
            return DeliveryResult::permanent('Telegram bot token not configured.');
        }

        $chats = $this->bots->activeChatIds($userId);
        if ($chats === []) {
            return DeliveryResult::sent(); // not connected is not a failure
        }

        $text = trim(((string) ($payload['title'] ?? '')) . "\n" . ((string) ($payload['body'] ?? '')));
        $lastId = '';
        $sent = 0;
        $start = (int) (microtime(true) * 1000);

        foreach ($chats as $chatId) {
            $result = $this->sendMessage((int) $chatId, $text);
            if ($result->ok) {
                $sent++;
                $lastId = $result->providerMessageId;
            } elseif (!$result->retryable && str_contains($result->error, 'chat not found')) {
                // The admin blocked the bot: deactivate rather than retry forever.
                $this->bots->deactivate((int) $chatId);
            }
        }

        $latency = (int) (microtime(true) * 1000) - $start;
        return $sent > 0
            ? DeliveryResult::sent($lastId, $latency)
            : DeliveryResult::transient('Telegram send failed for all chats.', $latency);
    }

    private function sendMessage(int $chatId, string $text): DeliveryResult
    {
        $token = trim((string) \App\Env::get('TELEGRAM_BOT_TOKEN', ''));
        $ch = curl_init(self::API . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($response, true);
            $messageId = (string) ($decoded['result']['message_id'] ?? '');
            return DeliveryResult::sent($messageId, 0);
        }

        $error = mb_substr($response !== '' ? $response : "HTTP {$status}", 0, 300);
        // 4xx other than 429 is a permanent condition (bad chat, blocked bot).
        $permanent = $status >= 400 && $status < 500 && $status !== 429;
        return $permanent ? DeliveryResult::permanent($error) : DeliveryResult::transient($error);
    }
}
