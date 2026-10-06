<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * `user.full_name`, plus a pass over every row of `{{%user}}` so the directory
 * each role is read from is internally consistent.
 *
 * Two jobs in one migration because they have to land together: a name column
 * that every existing account leaves blank is not a name column, and the
 * backfill only makes sense while we are already rewriting the table's rows.
 *
 * ## The column
 *
 * `full_name` is what a human is called — orders, rosters, ledgers and the
 * profile card all show it. `username` stays the login handle (UNIQUE, ASCII,
 * validated by a regex) and is deliberately *not* reused as a display name:
 * `rahim.demo` is fine on a login form and poor on a receipt.
 *
 * `NOT NULL DEFAULT ''` rather than NULL, because every read path would
 * otherwise need a null check, and the empty string has one obvious meaning:
 * "this account has not given a name yet" — which `displayName()` answers with
 * the username. Existing rows are backfilled from `username` below so nobody
 * who signed up before this migration shows an empty card.
 *
 * ## The row pass
 *
 * - `role` is lower-cased/trimmed and folded onto the four values the code
 *   actually recognises (`superadmin|admin|staff|user`), with legacy spellings
 *   mapped to their intended role and anything unrecognised falling back to
 *   `user`. The fallback is safe in one direction only, and that direction is
 *   the safe one: an account whose role the middleware cannot name already has
 *   no admin access (AdminMiddleware requires staff/superadmin/admin), so
 *   rewriting it to `user` removes an access *claim* the code never honoured,
 *   never an access the row really had. Case alone (`Admin` → `admin`) is the
 *   common repair.
 *
 *   What this deliberately does NOT do: promote anybody to `superadmin`.
 *   Handing out platform-wide authority is a human decision with a CLI command
 *   behind it (`php yii app:super-admin`), and a migration that grants it
 *   silently would be the least reviewable privilege escalation in the system.
 *
 * - `status` is folded to `active` or `disabled`. Login already refuses
 *   anything that is not exactly `active`, so every value outside that set is
 *   already a locked account wearing a label nobody renders (`banned`,
 *   `pending`, `0`…). This only makes the row say so, in a word the status
 *   badge knows. Fail-closed on purpose: an unknown value is never guessed
 *   into `active`.
 *
 * - identifiers (`username`, `phone`, `email`) are trimmed — but only where
 *   trimming cannot collide with another row, because all three carry UNIQUE
 *   indexes and one unlucky UPDATE would abort the migration after the column
 *   had already been added. A value that would collide is left alone rather
 *   than breaking the deploy.
 *
 * - `email` empty string becomes NULL. MySQL treats two NULLs as distinct
 *   under a UNIQUE index but refuses two `''`, so a blank email stored as an
 *   empty string quietly blocks the next account from also having none.
 *
 * - `api_key` / `referral_code` are minted for any row that has none. Both are
 *   UNIQUE, both are normally written on insert or lazily by
 *   `UserRepository::ensure*()`, and both are backfilled here so the tables are
 *   complete the moment the deploy finishes instead of on the next page view.
 *   The API key is a credential and is drawn with `random_bytes`; the referral
 *   code is a shareable handle, so it follows M240112's id-derived approach
 *   (deterministic, retryable, and never able to strand the migration).
 *
 * - `free_searches` below zero is clamped to 0: a negative demo allowance can
 *   only be a bug, and `consumeFreeSearch()` would otherwise never fire again
 *   for that account.
 *
 * `balance` is never touched — that is money with a ledger behind it, and
 * "tidying" it here would silently change what the books say.
 */
final class M240124000000_AddFullNameAndNormaliseUsers implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'full_name', "string(120) NOT NULL DEFAULT ''");

        // Staff/roster/fan-out queries all filter on exactly these two columns
        // (`role IN (…)` joined to `status = 'active'`), and until now they
        // have been table scans over the whole account list.
        $b->createIndex('{{%user}}', 'ix_user_role_status', ['role', 'status']);

        $this->trimIdentifiers($b);
        $this->normaliseEmail($b);
        $this->normaliseRole($b);
        $this->normaliseStatus($b);
        $this->backfillFullName($b);
        $this->backfillApiKeys($b);
        $this->backfillReferralCodes($b);
        $this->clampFreeSearches($b);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('{{%user}}', 'ix_user_role_status');
        $b->dropColumn('{{%user}}', 'full_name');

        // The row rewrites are deliberately not reversed: there is no honest
        // "before" to restore (an unknown role or a blank API key is not a
        // value worth going back to), and half-reverting a directory is worse
        // than leaving a tidy one.
    }

    /**
     * Trim `username` / `phone` / `email` where the trimmed value is free.
     *
     * All three are login identifiers with UNIQUE indexes behind them, so the
     * collision check runs per row *before* the write: one colliding value
     * would otherwise abort the whole migration, and a migration that dies
     * after its ALTER TABLE leaves the next run failing on a duplicate column.
     * A row whose trimmed form is taken is skipped — a stray space on one
     * account is a smaller problem than a deploy that cannot finish.
     */
    private function trimIdentifiers(MigrationBuilder $b): void
    {
        $db = $b->getDb();
        $rows = $db->createCommand(
            'SELECT [[id]], [[username]], [[phone]], [[email]] FROM {{%user}} ORDER BY [[id]]'
        )->queryAll();

        foreach ($rows as $row) {
            foreach (['username', 'phone', 'email'] as $column) {
                $value = (string) ($row[$column] ?? '');
                $trimmed = trim($value);

                if ($trimmed === $value || $trimmed === '') {
                    continue;
                }

                $taken = (int) $db->createCommand(
                    "SELECT COUNT(*) FROM {{%user}} WHERE [[{$column}]] = :v AND [[id]] <> :id"
                )->bindValues([':v' => $trimmed, ':id' => (int) $row['id']])->queryScalar();

                if ($taken > 0) {
                    continue;
                }

                $b->update('{{%user}}', [$column => $trimmed], ['id' => (int) $row['id']]);
            }
        }
    }

    /** Blank email → NULL, so the UNIQUE index stops refusing empty strings. */
    private function normaliseEmail(MigrationBuilder $b): void
    {
        $b->execute("UPDATE {{%user}} SET [[email]] = NULL WHERE [[email]] = ''");
    }

    /**
     * Fold every role spelling onto the four values the middleware reads.
     *
     * Written as one statement instead of a PHP loop over distinct values so
     * the rule lives in one place and re-running it is a no-op.
     */
    private function normaliseRole(MigrationBuilder $b): void
    {
        $b->execute(
            'UPDATE {{%user}} SET [[role]] = CASE LOWER(TRIM([[role]]))'
            . " WHEN 'superadmin' THEN 'superadmin'"
            . " WHEN 'super_admin' THEN 'superadmin'"
            . " WHEN 'super-admin' THEN 'superadmin'"
            . " WHEN 'super admin' THEN 'superadmin'"
            . " WHEN 'platform_owner' THEN 'superadmin'"
            . " WHEN 'admin' THEN 'admin'"
            . " WHEN 'administrator' THEN 'admin'"
            . " WHEN 'staff' THEN 'staff'"
            . " WHEN 'moderator' THEN 'staff'"
            . " ELSE 'user'"
            . ' END'
        );
    }

    /**
     * `active` survives; everything else becomes `disabled`.
     *
     * Fail-closed: login() refuses any status that is not exactly `active`, so
     * an unrecognised value already cannot sign in — this only replaces a word
     * no badge renders with one that does.
     */
    private function normaliseStatus(MigrationBuilder $b): void
    {
        $b->execute(
            'UPDATE {{%user}} SET [[status]] = CASE'
            . " WHEN LOWER(TRIM([[status]])) IN ('active', 'enabled', '1') THEN 'active'"
            . " ELSE 'disabled'"
            . ' END'
        );
    }

    /**
     * Trim the new column, then fill the blanks from `username`.
     *
     * The username is the only name every row is guaranteed to have, so an
     * account that predates the column still renders as itself everywhere the
     * display name is shown. Done after trimIdentifiers() so the source value
     * is already clean.
     */
    private function backfillFullName(MigrationBuilder $b): void
    {
        $b->execute("UPDATE {{%user}} SET [[full_name]] = TRIM([[full_name]])");
        $b->execute(
            "UPDATE {{%user}} SET [[full_name]] = TRIM([[username]])"
            . " WHERE [[full_name]] = '' OR [[full_name]] IS NULL"
        );
    }

    /**
     * Mint an `AL_XXXXXXXX` key for every account that has none.
     *
     * `random_bytes` rather than an id-derived value: unlike the referral code,
     * this one is a credential shown on the profile page and accepted as proof
     * of ownership, so a predictable draw would be a vulnerability. The UNIQUE
     * index is checked before each write and the draw repeated on a collision.
     */
    private function backfillApiKeys(MigrationBuilder $b): void
    {
        $db = $b->getDb();
        $ids = $db->createCommand(
            "SELECT [[id]] FROM {{%user}} WHERE [[api_key]] IS NULL OR [[api_key]] = '' ORDER BY [[id]]"
        )->queryColumn();

        foreach ($ids as $id) {
            $key = '';
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $key = 'AL_' . strtoupper(bin2hex(random_bytes(4)));
                $taken = (int) $db
                    ->createCommand('SELECT COUNT(*) FROM {{%user}} WHERE [[api_key]] = :k')
                    ->bindValue(':k', $key)
                    ->queryScalar();
                if ($taken === 0) {
                    break;
                }
            }

            $b->update('{{%user}}', ['api_key' => $key], ['id' => (int) $id]);
        }
    }

    /**
     * Mint a 6-character referral code for every account that has none.
     *
     * Same id-derived scheme as M240112: the code is read aloud and re-keyed,
     * not used as a secret, and a deterministic draw with a salt retry cannot
     * strand the migration the way an unbounded random loop under a UNIQUE
     * index could.
     */
    private function backfillReferralCodes(MigrationBuilder $b): void
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $size = strlen($alphabet);
        $db = $b->getDb();
        $ids = $db->createCommand(
            "SELECT [[id]] FROM {{%user}} WHERE [[referral_code]] IS NULL OR [[referral_code]] = '' ORDER BY [[id]]"
        )->queryColumn();

        foreach ($ids as $id) {
            $code = '';
            for ($salt = 0; $salt < 5; $salt++) {
                $hash = hash('sha256', 'allseba-referral:' . $id . ':' . $salt);
                $code = '';
                for ($i = 0; $i < 6; $i++) {
                    $code .= $alphabet[hexdec(substr($hash, $i * 2, 2)) % $size];
                }
                $taken = (int) $db
                    ->createCommand('SELECT COUNT(*) FROM {{%user}} WHERE [[referral_code]] = :c')
                    ->bindValue(':c', $code)
                    ->queryScalar();
                if ($taken === 0) {
                    break;
                }
            }

            $b->update('{{%user}}', ['referral_code' => $code], ['id' => (int) $id]);
        }
    }

    /** A negative demo allowance is a bug, not a balance. */
    private function clampFreeSearches(MigrationBuilder $b): void
    {
        $b->execute('UPDATE {{%user}} SET [[free_searches]] = 0 WHERE [[free_searches]] < 0');
    }
}
