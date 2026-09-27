<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Immutable user identity resolved from session.
 */
final class Identity
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly float $balance,
        public readonly ?string $avatar,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['phone'],
            $row['email'] ?? null,
            (string) $row['role'],
            (string) $row['status'],
            (float) ($row['balance'] ?? 0),
            $row['avatar'] ?? null,
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function canAccessAdmin(): bool
    {
        return $this->isAdmin() || $this->isStaff();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
