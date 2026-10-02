<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generation and validation of the referral codes users share with friends.
 *
 * Deliberately a pure static class with no dependencies: the code is produced
 * in three different layers (the user repository mints one lazily, the referral
 * service mints one at signup, and the unit tests exercise the alphabet), and a
 * value object that needs a connection object in each of those places helps
 * nobody.
 *
 * The alphabet omits 0/O, 1/I/L and U/V is kept (unlike some systems) because
 * the code is 6 characters long — dropping V would push the space down to
 * 30^6, which is still ~729M combinations but buys nothing: users in
 * Bangladesh read these aloud and re-key them from a screenshot far more often
 * than they mistype V for U. What costs real support tickets is 0/O and 1/I,
 * so those are what went.
 */
final class ReferralCode
{
    /** No 0/O, no 1/I/L. 31 glyphs. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const LENGTH = 6;

    /** A fresh random code. Uses random_bytes, not the clock. */
    public static function generate(): string
    {
        $size = strlen(self::ALPHABET);
        $alphabet = self::ALPHABET;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $size - 1)];
        }

        return $code;
    }

    /**
     * Fold whatever a user pasted into the canonical form.
     *
     * Codes travel through URLs, WhatsApp forwards and copy-paste, so they
     * arrive lower-cased, wrapped in spaces, or with the user's stray hyphen
     * still attached. Stripping non-alphanumerics here means every call site
     * gets the same answer for the same human input.
     */
    public static function normalise(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    /** Whether a (already normalised) string has the right shape. */
    public static function isValid(string $code): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) === 1;
    }
}
