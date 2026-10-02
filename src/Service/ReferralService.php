<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\ReferralRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The "বন্ধুকে রেফার করে বোনাস পান" programme.
 *
 * Design notes:
 *
 * - The bonus is triggered by the friend's FIRST APPROVED RECHARGE, not by
 *   signup. A signup bonus is worth nothing to the operator and is farmed with
 *   a throwaway account in about four minutes. Waiting for money to arrive
 *   means the bonus is only ever paid for a customer who actually paid.
 * - Both sides are credited in wallet balance, and the referral row snapshots
 *   the amounts at signup. If the operator changes the bonus next month,
 *   referrals already in flight still pay what the user was shown.
 * - Everything is idempotent, and idempotency is enforced by the database:
 *   `ReferralRepository::transition()` updates `WHERE status = 'pending'`, so
 *   only one of two concurrent calls can flip the row and pay. A second
 *   approval of the same recharge, a replayed POST, or two admins clicking at
 *   once all result in exactly one payout.
 * - The whole payout — both ledger rows, both balance changes and the status
 *   flip — runs in one transaction. A crash between crediting the referrer and
 *   the referee would otherwise mint balance from nothing.
 */
final class ReferralService
{
    /** A referral bonus larger than this is a settings typo, not an offer. */
    private const HARD_MAX_BONUS = 100000.0;

    /** Defensive: the very first recharge of an account, for the trigger check. */
    private const FIRST_RECHARGE_CHECK = 1;

    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly UserRepository $users,
        private readonly TransactionRepository $transactions,
        private readonly SettingsRepository $settings,
        private readonly NotificationManager $notify,
        private readonly ActivityLogRepository $logs,
        private readonly ConnectionInterface $db,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->get('referral_enabled', '1') !== '0';
    }

    public function referrerBonus(): float
    {
        return $this->amount('referrer_bonus_amount', 50.0);
    }

    public function refereeBonus(): float
    {
        return $this->amount('referee_bonus_amount', 20.0);
    }

    /**
     * The smallest first recharge that unlocks the bonus. Zero disables the
     * threshold, which is a legitimate choice — it just means any approved
     * recharge counts.
     */
    public function minFirstRecharge(): float
    {
        $value = (float) $this->settings->get('referral_min_first_recharge', '100');
        return $value > 0 ? min($value, self::HARD_MAX_BONUS) : 0.0;
    }

    public function terms(): string
    {
        return $this->settings->get('referral_terms', '');
    }

    /**
     * The public copy for the referral page: one paragraph that always states
     * both amounts and the threshold, so the page can never advertise a
     * different deal from the one settings will actually pay.
     */
    public function summaryText(): string
    {
        $referrer = number_format($this->referrerBonus(), 0);
        $referee = number_format($this->refereeBonus(), 0);
        $min = $this->minFirstRecharge();
        $unlock = $min > 0
            ? sprintf('ন্যূনতম ৳%s রিচার্জ অনুমোদিত হলে', number_format($min, 0))
            : 'প্রথম রিচার্জ অনুমোদিত হলে';

        return sprintf(
            'আপনার বন্ধু যখন %s যোগ করে প্রথমবার রিচার্জ করবেন, তখন আপনি ৳%s এবং আপনার বন্ধু ৳%s ব্যালেন্স বোনাস পাবেন।',
            $unlock,
            $referrer,
            $referee,
        );
    }

    /** The shareable link for a user's own code. */
    public function shareLink(int $userId): string
    {
        $code = $this->users->ensureReferralCode($userId);
        $base = rtrim((string) \App\Env::get('APP_URL'), '/');

        return $base . '/register?ref=' . $code;
    }

    /**
     * Turn a pasted or `?ref=` code into the referrer's user row.
     *
     * Returns null — never throws — for anything unusable: an unknown code, a
     * suspended or deleted account, or a non-referring staff account. A bad
     * code must not block someone from signing up; it just earns no bonus.
     */
    public function resolveCode(string $raw): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $code = ReferralCode::normalise($raw);
        if (!ReferralCode::isValid($code)) {
            return null;
        }

        return $this->users->findByReferralCode($code);
    }

    /**
     * Record the referral at signup.
     *
     * The row is created `pending` with the bonus amounts already snapshotted;
     * nothing is paid here. Every guard is a no-op rather than an error,
     * because the alternative is refusing somebody a valid registration over a
     * bonus they were never going to be paid.
     */
    public function attach(int $refereeId, int $referrerId, string $code): bool
    {
        if (!$this->isEnabled() || $refereeId === $referrerId) {
            return false;
        }
        // One referral per account — the UNIQUE index on referee_id would
        // abort the insert anyway, but a clean false beats a driver exception.
        if ($this->referrals->findByReferee($refereeId) !== null) {
            return false;
        }

        $this->referrals->create([
            'referrer_id' => $referrerId,
            'referee_id' => $refereeId,
            'code' => $code,
            'referrer_amount' => $this->referrerBonus(),
            'referee_amount' => $this->refereeBonus(),
        ]);

        $this->logs->create([
            'user_id' => $refereeId,
            'action' => 'referral.created',
            'description' => sprintf('Referred by code %s (user #%d)', $code, $referrerId),
            'metadata' => ['code' => $code, 'referrer_id' => $referrerId],
        ]);

        return true;
    }

    /**
     * Referral trigger, called from TopupService once a recharge is approved.
     *
     * The topup row is already `approved` and the balance already credited by
     * the time this runs, so the "is this the first one?" question is answered
     * by counting approved recharges: if the count is not exactly one, this
     * was not the friend's first purchase and no bonus is due.
     *
     * A referral that simply does not pay is NOT an error — no referral, a
     * disabled programme, a second recharge or a below-threshold first
     * recharge are all normal outcomes. The caller gets [false, reason] and
     * moves on; the only failure worth surfacing to the user is success.
     *
     * @return array{0: bool, 1: string} [paid, message]
     */
    public function onTopupApproved(int $refereeId, int $topupId, float $amount): array
    {
        if (!$this->isEnabled()) {
            return [false, 'রেফারেল সিস্টেম বন্ধ আছে।'];
        }

        $referral = $this->referrals->findByReferee($refereeId);
        if ($referral === null) {
            return [false, 'এই অ্যাকাউন্টের কোনো রেফারেল নেই।'];
        }
        if ($referral['status'] !== ReferralRepository::PENDING) {
            return [false, 'এই রেফারেল আগেই প্রক্রিয়া করা হয়েছে।'];
        }

        $approvedCount = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%topup_request}} WHERE [[user_id]] = :u AND [[status]] = :s')
            ->bindValues([':u' => $refereeId, ':s' => 'approved'])
            ->queryScalar();

        if ($approvedCount !== self::FIRST_RECHARGE_CHECK) {
            return [false, 'এটি বন্ধুর প্রথম রিচার্জ নয় — বোনাস প্রযোজ্য নয়।'];
        }

        $min = $this->minFirstRecharge();
        if ($min > 0 && $amount < $min) {
            return [false, sprintf('প্রথম রিচার্জ ৳%s এর কম হওয়ায় বোনাস দেওয়া হয়নি।', number_format($min, 0))];
        }

        $paid = $this->pay(
            (int) $referral['id'],
            [
                'trigger_topup_id' => $topupId,
                'trigger_amount' => $amount,
            ],
        );

        return $paid
            ? [true, sprintf('রেফারেল বোনাস পরিশোধ — ৳%s', number_format((float) $referral['referrer_amount'], 0))]
            : [false, 'বোনাস পরিশোধ করা যায়নি।'];
    }

    /**
     * Admin-initiated payout, for when the trigger could not fire on its own
     * (a recharge approved before the programme was switched on, or a manual
     * goodwill payment).
     *
     * @return array{0: bool, 1: string}
     */
    public function payManually(int $referralId, int $adminId, string $note = ''): array
    {
        $referral = $this->referrals->findById($referralId);
        if ($referral === null) {
            return [false, 'রেফারেল পাওয়া যায়নি।'];
        }
        if ($referral['status'] === ReferralRepository::PAID) {
            return [false, 'এই বোনাস আগেই পরিশোধ করা হয়েছে।'];
        }
        if ($referral['status'] === ReferralRepository::REJECTED) {
            return [false, 'বাতিলকৃত রেফারেল পরিশোধ করা যায় না — আগে "অপেক্ষমাণ" অবস্থায় ফেরত আনুন।'];
        }

        $paid = $this->pay($referralId, [
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'admin_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);

        return $paid ? [true, 'রেফারেল বোনাস পরিশোধ হয়েছে।'] : [false, 'বোনাস পরিশোধ করা যায়নি — অন্য অ্যাডমিন আগেই করে ফেলেছেন।'];
    }

    /**
     * Void a referral without paying it.
     *
     * @return array{0: bool, 1: string}
     */
    public function reject(int $referralId, int $adminId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return [false, 'বাতিলের কারণ লিখুন — ইউজার এটি দেখতে পাবেন।'];
        }

        $referral = $this->referrals->findById($referralId);
        if ($referral === null) {
            return [false, 'রেফারেল পাওয়া যায়নি।'];
        }
        if ($referral['status'] === ReferralRepository::PAID) {
            return [false, 'পরিশোধিত বোনাস বাতিল করা যায় না — আগে ব্যালেন্স সমন্বয় করুন।'];
        }

        $ok = $this->referrals->transition($referralId, ReferralRepository::PENDING, ReferralRepository::REJECTED, [
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'admin_note' => mb_substr($reason, 0, 500),
        ]);
        if (!$ok) {
            return [false, 'এই রেফারেলের অবস্থা পরিবর্তন হয়েছে — আবার চেষ্টা করুন।'];
        }

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'referral.rejected',
            'description' => sprintf('Referral #%d rejected: %s', $referralId, $reason),
            'metadata' => ['referral_id' => $referralId, 'reason' => $reason],
        ]);

        $this->notify->dispatch(
            NotificationEvent::REFERRAL_REJECTED,
            (int) $referral['referrer_id'],
            [
                'referee' => $this->username((int) $referral['referee_id']),
                'reason' => $reason,
            ],
            '/referrals',
            ['referral_id' => $referralId],
        );

        return [true, 'রেফারেল বাতিল হয়েছে।'];
    }

    /**
     * Put a voided referral back into the queue, e.g. after a mistaken
     * rejection. This does NOT pay anything — the row is back to `pending`, so
     * the next approved recharge (or a manual pay) settles it.
     *
     * @return array{0: bool, 1: string}
     */
    public function reset(int $referralId, int $adminId): array
    {
        $referral = $this->referrals->findById($referralId);
        if ($referral === null) {
            return [false, 'রেফারেল পাওয়া যায়নি।'];
        }
        if ($referral['status'] !== ReferralRepository::REJECTED) {
            return [false, 'শুধু বাতিলকৃত রেফারেল অপেক্ষমাণ অবস্থায় ফেরানো যায়।'];
        }

        $ok = $this->referrals->transition($referralId, ReferralRepository::REJECTED, ReferralRepository::PENDING, [
            'reviewed_by' => null,
            'reviewed_at' => null,
            'admin_note' => null,
        ]);
        if (!$ok) {
            return [false, 'এই রেফারেলের অবস্থা পরিবর্তন হয়েছে — আবার চেষ্টা করুন।'];
        }

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'referral.reset',
            'description' => sprintf('Referral #%d returned to pending', $referralId),
            'metadata' => ['referral_id' => $referralId],
        ]);

        return [true, 'রেফারেল অপেক্ষমাণ অবস্থায় ফেরানো হয়েছে।'];
    }

    /**
     * Credit both sides and flip the row, atomically.
     *
     * `transition()` is called FIRST and only pays if it won. That ordering is
     * the whole idempotency story: the loser of a race never reaches the
     * balance code at all.
     */
    private function pay(int $referralId, array $context): bool
    {
        return (bool) $this->db->transaction(function () use ($referralId, $context): bool {
            $referral = $this->referrals->findById($referralId);
            if ($referral === null || $referral['status'] !== ReferralRepository::PENDING) {
                return false;
            }

            $referrerId = (int) $referral['referrer_id'];
            $refereeId = (int) $referral['referee_id'];
            $referrerAmount = (float) $referral['referrer_amount'];
            $refereeAmount = (float) $referral['referee_amount'];
            $refereeName = $this->username($refereeId);

            $referrerTx = $referrerAmount > 0
                ? $this->credit($referrerId, $referrerAmount, [
                    'type' => 'referral_bonus',
                    'direction' => 'referrer',
                    'referral_id' => $referralId,
                    'referee' => $refereeName,
                    'code' => (string) $referral['code'],
                ])
                : null;

            $refereeTx = $refereeAmount > 0
                ? $this->credit($refereeId, $refereeAmount, [
                    'type' => 'referral_bonus',
                    'direction' => 'referee',
                    'referral_id' => $referralId,
                    'referrer' => $this->username($referrerId),
                    'code' => (string) $referral['code'],
                ])
                : null;

            $won = $this->referrals->transition(
                $referralId,
                ReferralRepository::PENDING,
                ReferralRepository::PAID,
                $context + [
                    'referrer_transaction_id' => $referrerTx,
                    'referee_transaction_id' => $refereeTx,
                ]
            );

            if (!$won) {
                // Cannot normally happen inside the transaction (we just read
                // the row as pending and hold the write lock), but returning
                // false rolls the credits back rather than paying a referral
                // nobody can see in the ledger.
                return false;
            }

            $this->notify->dispatch(
                NotificationEvent::REFERRAL_BONUS_REFERRER,
                $referrerId,
                [
                    'amount' => number_format($referrerAmount, 2),
                    'referee' => $refereeName,
                ],
                '/referrals',
                ['referral_id' => $referralId],
            );

            if ($refereeAmount > 0) {
                $this->notify->dispatch(
                    NotificationEvent::REFERRAL_BONUS_REFEREE,
                    $refereeId,
                    [
                        'amount' => number_format($refereeAmount, 2),
                        'referrer' => $this->username($referrerId),
                    ],
                    '/recharge',
                    ['referral_id' => $referralId],
                );
            }

            $this->logs->create([
                'user_id' => null,
                'action' => 'referral.paid',
                'description' => sprintf(
                    'Referral #%d paid: referrer #%d got %s, referee #%d got %s',
                    $referralId,
                    $referrerId,
                    number_format($referrerAmount, 2),
                    $refereeId,
                    number_format($refereeAmount, 2),
                ),
                'metadata' => [
                    'referral_id' => $referralId,
                    'referrer_transaction_id' => $referrerTx,
                    'referee_transaction_id' => $refereeTx,
                ],
            ]);

            return true;
        });
    }

    /** Ledger row + balance credit. Both sides of a referral payout go through here. */
    private function credit(int $userId, float $amount, array $metadata): int
    {
        $txId = $this->transactions->create([
            'user_id' => $userId,
            'reference' => 'AL' . strtoupper(bin2hex(random_bytes(5))),
            'amount' => $amount,
            'status' => 'completed',
            'metadata' => $metadata,
        ]);
        $this->users->adjustBalance($userId, $amount);

        return $txId;
    }

    private function amount(string $key, float $default): float
    {
        $value = (float) $this->settings->get($key, (string) $default);
        // A negative or absurd settings row must not become a negative
        // balance credit, so anything outside the sane band falls back.
        return ($value > 0 && $value <= self::HARD_MAX_BONUS) ? $value : $default;
    }

    private function username(int $userId): string
    {
        return (string) ($this->users->findById($userId)['username'] ?? ('#' . $userId));
    }
}
