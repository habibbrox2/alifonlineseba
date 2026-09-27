<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\SimpleCache\CacheInterface;

/**
 * Login throttling: per-IP+identifier attempt counters stored in file cache.
 */
final class AuthThrottle
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxAttempts = 5,
        private readonly int $decaySeconds = 300,
    ) {}

    public function tooManyAttempts(string $key): bool
    {
        $attempts = $this->cache->get($this->cacheKey($key), 0);
        return is_int($attempts) && $attempts >= $this->maxAttempts;
    }

    public function hit(string $key): void
    {
        $attempts = (int) $this->cache->get($this->cacheKey($key), 0);
        $this->cache->set($this->cacheKey($key), $attempts + 1, $this->decaySeconds);
    }

    public function clear(string $key): void
    {
        $this->cache->delete($this->cacheKey($key));
    }

    public function remainingAttempts(string $key): int
    {
        $attempts = (int) $this->cache->get($this->cacheKey($key), 0);
        return max(0, $this->maxAttempts - $attempts);
    }

    private function cacheKey(string $key): string
    {
        return 'throttle.' . hash('sha256', $key);
    }
}
