<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Immutable user identity resolved from session.
 */
final class Identity
{
    /** The role value that grants platform-wide authority. Fits `varchar(16)`. */
    public const ROLE_SUPERADMIN = 'superadmin';

    /** Roles that can sign in to the admin area at all. */
    public const STAFF_ROLES = ['admin', 'staff', self::ROLE_SUPERADMIN];

    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly float $balance,
        public readonly ?string $avatar,
        public readonly ?string $apiKey = null,
        public readonly int $freeSearches = 0,
        /** The person's name as they wrote it. '' when never given. */
        public readonly string $fullName = '',
    ) {}

    /**
     * What to show a human: their name, falling back to the login handle.
     *
     * The fallback is the whole reason this is a method and not a column read:
     * accounts created before `full_name` existed (and anybody who left it
     * blank) would otherwise render as an empty string on the dashboard, the
     * user menu and every order they own.
     */
    public function displayName(): string
    {
        $name = trim($this->fullName);

        return $name !== '' ? $name : $this->username;
    }

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
            isset($row['api_key']) ? (string) $row['api_key'] : null,
            (int) ($row['free_searches'] ?? 0),
            // Absent before the migration runs (and on rows built by hand),
            // so the ?? rather than a cast of null.
            (string) ($row['full_name'] ?? ''),
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->role === self::ROLE_SUPERADMIN;
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    /**
     * The platform owner: manages staff, decides withdrawals, settles books.
     *
     * A *separate* role rather than a flag on `admin`, because "may look at the
     * order queue" and "may hand the money out" have to be separable. If an
     * admin could also approve their own withdrawal, the review step would be
     * theatre — see `AdminWithdrawService::approve()`.
     *
     * Deliberately not inferred from "lowest id" or "first admin": those are
     * accidents of signup order, and an authority that exists because a row
     * was inserted early is an authority nobody can hand over when that person
     * leaves.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
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
