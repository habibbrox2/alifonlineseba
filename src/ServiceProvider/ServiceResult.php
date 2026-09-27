<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Result of a service execution — always demo data.
 */
final class ServiceResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly array $errors = [],
    ) {}

    public static function ok(array $data, string $message = 'Completed successfully.'): self
    {
        return new self(true, $message, $data, []);
    }

    public static function fail(string $message, array $errors = []): self
    {
        return new self(false, $message, [], $errors);
    }
}
