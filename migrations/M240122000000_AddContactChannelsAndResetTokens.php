<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Contact channels on the account, and the one table a password reset needs.
 *
 * Two features land together because both are "prove you own this account":
 *
 * - `whatsapp_no` / `telegram_no` on `{{%user}}` are the opt-in for the two
 *   messaging channels. Their *presence* is the consent — a person who types a
 *   WhatsApp number into their profile is asking to be reached there, and
 *   NotificationManager::wantsChannel() reads exactly that instead of a
 *   separate preference row. Nullable, and no UNIQUE: a family shares one
 *   WhatsApp line, and a unique index would refuse the second account the
 *   moment somebody typed the same number twice.
 *
 *   They are deliberately not `phone`: `phone` is the login identifier with a
 *   UNIQUE index behind it, and reusing it would make "log in with my
 *   WhatsApp" indistinguishable from "notify me on WhatsApp".
 *
 * - `{{%password_reset_token}}` carries both reset styles in one table, keyed
 *   by `channel`:
 *       email    → `token_hash` is sha256 of a long random token that rides
 *                  in the link.
 *       whatsapp/telegram → `token_hash` is sha256 of a 6-digit OTP, so the
 *                  lookup never compares a plaintext code and a database dump
 *                  cannot be replayed as a reset. A six-digit code has only
 *                  10^6 values, so the hash is not what protects it —
 *                  `attempts` and `expires_at` are — but it keeps every
 *                  secret in this table in the same (hashed) shape.
 *
 *   Only the hash is stored in both cases. `attempts` exists so a code can be
 *   brute-forced at most a few times before the row is dead, and `expires_at`
 *   is a real column rather than a claim in the code because the row itself
 *   has to be self-describing when it is read back an hour later.
 *
 *   Why not the `password_reset_tokens: email PK` shape from
 *   docs/architecture-plan.md: an email primary key makes "resend" an
 *   upsert-over-the-old-link, which silently invalidates a link the user has
 *   already opened in another tab, and it cannot represent a phone-only
 *   account at all. One row per attempt with an explicit expiry is the shape
 *   that supports resend, OTP and link side by side.
 */
final class M240122000000_AddContactChannelsAndResetTokens implements RevertibleMigrationInterface
{
    /** Same reasoning as M240109: never inherit a latin1 database default. */
    private const CHARSET = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'whatsapp_no', 'string(32) NULL');
        $b->addColumn('{{%user}}', 'telegram_no', 'string(64) NULL');

        $b->createTable('{{%password_reset_token}}', [
            'id' => 'pk',
            'user_id' => 'integer NOT NULL',
            // 'email' | 'whatsapp' | 'telegram' — which channel this attempt
            // was delivered on, and therefore which proof the user can offer.
            'channel' => "string(16) NOT NULL DEFAULT 'email'",
            // sha256 of the emailed link token, or of the OTP. Never the value
            // itself: the mail body / SMS is the only place it exists in the
            // clear.
            'token_hash' => 'char(64) NOT NULL',
            'expires_at' => 'datetime NOT NULL',
            // NULL while live. Set the moment a reset is completed, so a
            // consumed link is distinguishable from an expired one in the UI.
            'consumed_at' => 'datetime NULL',
            // Guard against guessing the OTP; the flow locks after a few.
            'attempts' => "tinyint NOT NULL DEFAULT '0'",
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET);

        // Lookups are by hash (link or OTP), never by id. Deliberately NOT
        // unique: sha256 of a six-digit code repeats across users, so a UNIQUE
        // index would refuse the second person who happens to draw "481920"
        // while the first one's code is still live. The email token is 32
        // random bytes and cannot collide in practice; uniqueness there is a
        // property of the generator, not of the index.
        $b->createIndex('{{%password_reset_token}}', 'ix_reset_token_hash', 'token_hash');
        $b->createIndex('{{%password_reset_token}}', 'ix_reset_user_created', ['user_id', 'created_at']);
        $b->addForeignKey('{{%password_reset_token}}', 'fk_reset_user', 'user_id', '{{%user}}', 'id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%password_reset_token}}');
        $b->dropColumn('{{%user}}', 'telegram_no');
        $b->dropColumn('{{%user}}', 'whatsapp_no');
    }
}
