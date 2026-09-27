<?php

declare(strict_types=1);

namespace App\Web;

use Throwable;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Decorates the session so the `Secure` cookie flag follows the actual request
 * scheme instead of a value fixed at container build time.
 *
 * The same deployment serves plain HTTP on the loopback origin and HTTPS through
 * the Cloudflare tunnel, so a static `cookie_secure` either drops the flag in
 * production or makes the session middleware throw in development.
 */
final class SecureCookieSession implements SessionInterface
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly RequestProviderInterface $requestProvider,
    ) {
    }

    public function getCookieParameters(): array
    {
        $parameters = $this->session->getCookieParameters();
        $parameters['secure'] = $this->isRequestSecure();

        return $parameters;
    }

    private function isRequestSecure(): bool
    {
        try {
            return $this->requestProvider->get()->getUri()->getScheme() === 'https';
        } catch (Throwable) {
            // No request bound yet (console / early bootstrap): fall back to the origin scheme.
            return false;
        }
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function set(string $key, $value): void
    {
        $this->session->set($key, $value);
    }

    public function close(): void
    {
        $this->session->close();
    }

    public function open(): void
    {
        $this->session->open();
    }

    public function isActive(): bool
    {
        return $this->session->isActive();
    }

    public function getId(): ?string
    {
        return $this->session->getId();
    }

    public function setId(string $sessionId): void
    {
        $this->session->setId($sessionId);
    }

    public function regenerateId(): void
    {
        $this->session->regenerateId();
    }

    public function discard(): void
    {
        $this->session->discard();
    }

    public function getName(): string
    {
        return $this->session->getName();
    }

    public function all(): array
    {
        return $this->session->all();
    }

    public function remove(string $key): void
    {
        $this->session->remove($key);
    }

    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    public function pull(string $key, $default = null): mixed
    {
        return $this->session->pull($key, $default);
    }

    public function clear(): void
    {
        $this->session->clear();
    }

    public function destroy(): void
    {
        $this->session->destroy();
    }
}
