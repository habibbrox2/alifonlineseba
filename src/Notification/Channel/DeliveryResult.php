<?php

declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * The result of one send attempt through one channel driver. The worker
 * branches only on these three outcomes.
 */
final class DeliveryResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $retryable = true,
        public readonly string $providerMessageId = '',
        public readonly int $latencyMs = 0,
        public readonly string $error = '',
    ) {}

    public static function sent(string $providerMessageId = '', int $latencyMs = 0): self
    {
        return new self(true, retryable: false, providerMessageId: $providerMessageId, latencyMs: $latencyMs);
    }

    /** Retryable: 5xx, timeout, 429 — the provider hiccupped, try again. */
    public static function transient(string $error, int $latencyMs = 0): self
    {
        return new self(false, retryable: true, latencyMs: $latencyMs, error: $error);
    }

    /** Permanent: bad token, 4xx, rejected recipient — never retry. */
    public static function permanent(string $error, int $latencyMs = 0): self
    {
        return new self(false, retryable: false, latencyMs: $latencyMs, error: $error);
    }
}
