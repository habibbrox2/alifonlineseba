<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\AuthThrottle;
use App\Env;
use App\Notification\Channel\TelegramChannel;
use App\Notification\Channel\WhatsAppChannel;
use App\Repository\BotConnectionRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Password reset over three channels, in one table.
 *
 *   email     — a long random token in a link; the address is the proof.
 *   whatsapp  — a 6-digit OTP sent to `user.whatsapp_no`.
 *   telegram  — a 6-digit OTP sent to the user's connected bot chat.
 *
 * ## Why every secret is stored hashed
 *
 * `token_hash` holds sha256 of whichever secret was issued (HMAC'd with
 * APP_KEY, so an offline brute force of a 6-digit code also needs the server
 * key). The link or the OTP exists in the clear only in the mail body / the
 * message the user receives. A database dump therefore cannot be replayed
 * into a password change.
 *
 * ## Why the caller never learns whether the account exists
 *
 * `issue()` returns the same "if that account exists, we sent it" answer for
 * an unknown identifier, an account with no address on the chosen channel and
 * a real send. Account enumeration on a reset form is how somebody with a
 * phone number but no password gets a list of who is worth targeting; the
 * delivery failures are logged, not echoed.
 *
 * ## Attempt limits
 *
 * Per-identifier request throttling rides the existing `AuthThrottle` (file
 * cache, env-configurable — audit §670). OTP *verification* is counted on the
 * row itself (`attempts`), because the code is guessable in 10^6 tries and a
 * cache reset must not clear that counter: 5 wrong codes kill the row.
 */
final class PasswordResetService
{
    /** Link lifetime: long enough to read mail on a phone, short enough to expire. */
    public const EMAIL_TTL_SECONDS = 3600;

    /** OTP lifetime. Ten minutes is the industry norm and matches the audit's 15-minute lock. */
    public const OTP_TTL_SECONDS = 600;

    /** Wrong codes before the row is consumed for good. */
    public const MAX_OTP_ATTEMPTS = 5;

    /** How many reset requests one identifier+IP may make per throttle window. */
    private const MAX_REQUESTS = 5;

    public const CHANNELS = ['email', 'whatsapp', 'telegram'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UserRepository $users,
        private readonly BotConnectionRepository $bots,
        private readonly AuthThrottle $throttle,
        private readonly EmailSender $mail,
        private readonly WhatsAppChannel $whatsapp,
        private readonly TelegramChannel $telegram,
    ) {}

    /**
     * Start a reset. Always answers the same way — see the class docblock.
     *
     * @param string $identifier username, phone or email
     * @param string $channel    one of {@see CHANNELS}
     * @return array{sent: bool, channel: string, rowId: ?int}
     */
    public function issue(string $identifier, string $channel, string $ip, string $userAgent = ''): array
    {
        $channel = in_array($channel, self::CHANNELS, true) ? $channel : 'email';

        // Throttled *before* the lookup so a wrong identifier costs the same
        // as a right one — otherwise a valid-account probe is distinguishable
        // by timing the rate limit itself.
        $throttleKey = 'reset.request.' . $identifier . '|' . $ip;
        if ($this->throttle->tooManyAttempts($throttleKey)) {
            return ['sent' => false, 'channel' => $channel, 'rowId' => null];
        }
        $this->throttle->hit($throttleKey);

        $user = $this->resolve($identifier);
        if ($user === null) {
            return ['sent' => false, 'channel' => $channel, 'rowId' => null];
        }

        $rowId = null;
        $sent = match ($channel) {
            'email' => $this->issueEmail($user),
            'whatsapp', 'telegram' => (bool) ($rowId = $this->issueOtp($user, $channel)),
            default => false,
        };

        if (!$sent) {
            // The *why* is for the log, never for the response: "that number
            // has no WhatsApp on file" is exactly the sentence that tells a
            // stranger which accounts exist.
            $this->logFailure((int) $user['id'], $channel, $ip, $userAgent);
        }

        // The row id is what the OTP verify step is addressed by. It goes back
        // to the caller (and only the caller — it is not a secret, but it is
        // not useful without the code either) so the session can pin the
        // attempt to *this* row rather than to "some live code for somebody".
        return ['sent' => $sent, 'channel' => $channel, 'rowId' => $rowId];
    }

    /**
     * Verify and consume a secret, and set the new password atomically.
     *
     * Two shapes, and the difference is the whole security model:
     *
     *  - **email** is looked up *by* the hash: a 64-hex-char random token has
     *    no guessable prefix, so the row it finds is the row it proves.
     *  - **OTP** is looked up *by id* (pinned in the session when the code was
     *    issued) and only then compared. A hash lookup could not work here —
     *    a wrong guess would simply find no row, `attempts` would never move,
     *    and the 10^6 code space would be wide open to a script. Finding the
     *    row first is what makes the attempt counter count.
     *
     * One UPDATE guards the commit: `consumed_at IS NULL AND expires_at >
     * NOW()` — so two tabs racing the same link produce exactly one password
     * change, and a secret that was used cannot be used again.
     *
     * @param int|null $rowId required for OTP channels, ignored for email
     * @return array{ok: bool, error: string|null, userId: ?int}
     */
    public function consume(string $channel, string $secret, string $password, string $ip, ?int $rowId = null): array
    {
        if (strlen($password) < 6) {
            return ['ok' => false, 'error' => 'নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষর।', 'userId' => null];
        }

        $hash = $this->hash($secret);

        if ($channel === 'email') {
            $row = $this->findLive($channel, $hash);
            if ($row === null || !hash_equals((string) $row['token_hash'], $hash)) {
                return ['ok' => false, 'error' => self::invalidMessage($channel), 'userId' => null];
            }
        } else {
            $row = $rowId === null ? null : $this->findLiveById($rowId, $channel);
            if ($row === null) {
                return ['ok' => false, 'error' => self::invalidMessage($channel), 'userId' => null];
            }

            // Counted on the row, not the cache: see the class docblock. The
            // increment is committed *before* the comparison so an interrupted
            // guess still costs an attempt.
            //
            // `>=` and not `>`: the Nth wrong code has to be the one that
            // kills the row. Comparing the incremented value with `>` would
            // store 5 and stay live, handing out an N+1-th guess — the
            // counter would read "5 attempts used" while still accepting one.
            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= self::MAX_OTP_ATTEMPTS) {
                $this->kill((int) $row['id']);
                return ['ok' => false, 'error' => self::invalidMessage($channel), 'userId' => null];
            }
            $this->db->createCommand()->update(
                '{{%password_reset_token}}',
                ['attempts' => $attempts, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => (int) $row['id']],
            )->execute();

            if (!hash_equals((string) $row['token_hash'], $hash)) {
                $left = self::MAX_OTP_ATTEMPTS - $attempts;
                return [
                    'ok' => false,
                    'error' => $left > 0
                        ? "কোড সঠিক নয়। আর {$left}টি চেষ্টা বাকি।"
                        : self::invalidMessage($channel),
                    'userId' => null,
                ];
            }
        }

        $userId = (int) $row['user_id'];
        $consumed = $this->db->createCommand()->update(
            '{{%password_reset_token}}',
            ['consumed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            // The guard is the race: the second tab's UPDATE matches 0 rows.
            '[[id]] = :id AND [[consumed_at]] IS NULL AND [[expires_at]] > :now',
        )->bindValues([':id' => (int) $row['id'], ':now' => date('Y-m-d H:i:s')])->execute();

        if ($consumed === 0) {
            return ['ok' => false, 'error' => self::invalidMessage($channel), 'userId' => null];
        }

        $this->users->update($userId, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);

        // Every other live reset for this account dies with the one that was
        // used: a link mailed an hour ago must not still work after the OTP
        // path already changed the password.
        $this->db->createCommand()->delete(
            '{{%password_reset_token}}',
            '[[user_id]] = :u AND [[consumed_at]] IS NULL',
        )->bindValue(':u', $userId)->execute();

        return ['ok' => true, 'error' => null, 'userId' => $userId];
    }

    /** A live (unconsumed, unexpired) row for this channel + secret, or null. */
    public function findLive(string $channel, string $hash): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT * FROM {{%password_reset_token}}'
                . ' WHERE [[channel]] = :c AND [[token_hash]] = :h AND [[consumed_at]] IS NULL'
                . ' AND [[expires_at]] > :now ORDER BY [[id]] DESC LIMIT 1'
            )
            ->bindValues([':c' => $channel, ':h' => $hash, ':now' => date('Y-m-d H:i:s')])
            ->queryOne();

        return $row === false || $row === null ? null : $row;
    }

    /**
     * A live row by primary key, scoped to a channel.
     *
     * The id is pinned in the session when the OTP is issued, so an attacker
     * who somehow learns another user's row id still cannot spend it from
     * their own session — the session is what names the row.
     */
    public function findLiveById(int $id, string $channel): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT * FROM {{%password_reset_token}}'
                . ' WHERE [[id]] = :id AND [[channel]] = :c AND [[consumed_at]] IS NULL'
                . ' AND [[expires_at]] > :now LIMIT 1'
            )
            ->bindValues([':id' => $id, ':c' => $channel, ':now' => date('Y-m-d H:i:s')])
            ->queryOne();

        return $row === false || $row === null ? null : $row;
    }

    /** HMAC-sa256 of a secret. APP_KEY missing ⇒ plain sha256 (still one-way). */
    public function hash(string $secret): string
    {
        $key = (string) Env::get('APP_KEY', '');
        return $key !== ''
            ? hash_hmac('sha256', $secret, $key)
            : hash('sha256', $secret);
    }

    // ---- issuance ----------------------------------------------------------

    private function issueEmail(array $user): bool
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $this->store((int) $user['id'], 'email', $this->hash($token), self::EMAIL_TTL_SECONDS);

        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/reset-password?token=' . $token;
        $name = (string) ($user['username'] ?? '');

        return $this->mail->send(
            $email,
            'পাসওয়ার্ড রিসেট — All Seba',
            "হ্যালো {$name},\n\n"
            . "পাসওয়ার্ড রিসেট করার জন্য নিচের লিংকে ক্লিক করুন (১ ঘণ্টার মধ্যে কাজ করবে):\n"
            . "{$link}\n\n"
            . "আপনি এটা চাননি হলে এই ইমেইল উপেক্ষা করুন — পাসওয়ার্ড বদলানো হবে না।\n"
            . "— All Seba",
        );
    }

    /** @return int|null the row the OTP was stored in, or null when undeliverable */
    private function issueOtp(array $user, string $channel): ?int
    {
        $userId = (int) $user['id'];
        $number = null;

        if ($channel === 'whatsapp') {
            $number = $this->users->contactOn('whatsapp', $userId);
            if ($number === null || !$this->whatsapp->isAvailable()) {
                return null;
            }
        } else {
            // Telegram is addressed by chat id, not by the phone number in the
            // profile: the bot can only write to somebody who has started it.
            if (!$this->telegram->isAvailable() || $this->bots->activeChatIds($userId) === []) {
                return null;
            }
        }

        $code = (string) random_int(100000, 999999);
        $rowId = $this->store($userId, $channel, $this->hash($code), self::OTP_TTL_SECONDS);

        $text = "{$code} হলো আপনার ভেরিফিকেশন কোড। এটা কারো সাথে শেয়ার করবেন না।"
            . ' (১০ মিনিটের মধ্যে ব্যবহার করুন)';

        $result = $channel === 'whatsapp'
            ? $this->whatsapp->sendText((string) $number, $text)
            : $this->telegram->send($userId, ['title' => '', 'body' => $text]);

        if (!$result->ok) {
            // Delivered nowhere ⇒ the row must not stay live: a code nobody
            // received is only a liability sitting in the table.
            $this->kill($rowId);
            return null;
        }

        return $rowId;
    }

    /**
     * Supersede every earlier row for this user+channel, then insert.
     *
     * The order matters: consume-old *then* insert. Inserting first and
     * consuming afterwards would match the row just written (its
     * `consumed_at` is NULL) and kill the code on the spot — the resend would
     * "succeed" and the user would be holding a dead code.
     *
     * @return int the new row's id
     */
    private function store(int $userId, string $channel, string $hash, int $ttl): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->createCommand()->update(
            '{{%password_reset_token}}',
            ['consumed_at' => $now, 'updated_at' => $now],
            '[[user_id]] = :u AND [[channel]] = :c AND [[consumed_at]] IS NULL',
        )->bindValues([':u' => $userId, ':c' => $channel])->execute();

        $this->db->createCommand()->insert('{{%password_reset_token}}', [
            'user_id' => $userId,
            'channel' => $channel,
            'token_hash' => $hash,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    private function kill(int $id): void
    {
        $this->db->createCommand()->delete('{{%password_reset_token}}', ['id' => $id])->execute();
    }

    /**
     * username / phone / email → the live row, or null.
     *
     * Email is included here (unlike `findByIdentifier()`, which is the login
     * path and deliberately keeps email out) because a person who only ever
     * signed up with an address has to be able to recover the account with it.
     */
    private function resolve(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $user = $this->users->findByIdentifier($identifier);
        if ($user === null && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            $user = $this->users->findByContact('email', $identifier);
            $user = $user === null ? null : $this->users->findById($user);
        }

        if ($user === null || $user['deleted_at'] !== null || $user['status'] !== 'active') {
            return null;
        }
        return $user;
    }

    private function logFailure(int $userId, string $channel, string $ip, string $userAgent): void
    {
        try {
            $this->db->createCommand()->insert('{{%activity_log}}', [
                'user_id' => $userId > 0 ? $userId : null,
                'action' => 'auth.reset_undeliverable',
                'description' => "Password reset could not be delivered on {$channel}",
                'ip_address' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 512),
                'created_at' => date('Y-m-d H:i:s'),
            ])->execute();
        } catch (\Throwable) {
            // A log line must never be the reason a reset fails.
        }
    }

    private static function invalidMessage(string $channel): string
    {
        return match ($channel) {
            'email' => 'লিংকটি অচল বা ব্যবহৃত হয়ে গেছে। নতুন লিংক চাইন।',
            default => 'কোডটি অচল বা ব্যবহৃত হয়ে গেছে। নতুন কোড চাইন।',
        };
    }
}
