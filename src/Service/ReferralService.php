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

    /** A run this long is a settings typo, not a loyalty programme. */
    private const HARD_MAX_RECHARGES = 1000;

    /** The rule when settings are absent or nonsensical. */
    private const DEFAULT_REQUIRED = 5;

    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly UserRepository $users,
        private readonly LedgerService $ledger,
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
     *
     * @deprecated Kept for the settings form and for referrals created before
     *             the qualifying-recharge rule existed. The live floor is
     *             {@see minQualifyingRecharge()}; this is only the fallback
     *             used when that key has not been set on this installation.
     */
    public function minFirstRecharge(): float
    {
        $value = (float) $this->settings->get('referral_min_first_recharge', '100');
        return $value > 0 ? min($value, self::HARD_MAX_BONUS) : 0.0;
    }

    /**
     * How many qualifying recharges a friend must complete before either side
     * is paid.
     *
     * Clamped to at least 1: a rule of zero would qualify on signup, which is
     * precisely the signup bonus this programme was built not to pay. A
     * settings value that is not a positive integer falls back to the default
     * rather than silently switching the programme off.
     */
    public function requiredRecharges(): int
    {
        $value = (int) $this->settings->get('referral_required_recharges', (string) self::DEFAULT_REQUIRED);

        return $value >= 1 ? min($value, self::HARD_MAX_RECHARGES) : self::DEFAULT_REQUIRED;
    }

    /**
     * The floor each of those recharges has to clear.
     *
     * Zero disables the floor, which is a legitimate operator choice: it means
     * any approved recharge counts towards the run.
     */
    public function minQualifyingRecharge(): float
    {
        $raw = $this->settings->get('referral_min_qualifying_recharge', '');
        if ($raw === '') {
            // An installation that has not had the new key seeded yet falls
            // back to the old single-recharge threshold, so upgrading does not
            // change the meaning of an existing setting.
            return $this->minFirstRecharge();
        }

        $value = (float) $raw;

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
        $required = $this->requiredRecharges();
        $min = $this->minQualifyingRecharge();

        $perRecharge = $min > 0
            ? sprintf('ন্যূনতম ৳%s করে', number_format($min, 0))
            : '';

        return sprintf(
            'আপনার বন্ধু যখন %s %dটি রিচার্জ অনুমোদিত করবেন, তখন আপনি ৳%s এবং আপনার বন্ধু ৳%s ব্যালেন্স বোনাস পাবেন।',
            $perRecharge,
            $required,
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
            // Snapshot the qualification rule alongside the promised amounts, so
            // this referral is finished by the terms that were on offer when the
            // friend signed up.
            'required_count' => $this->requiredRecharges(),
            'min_amount' => $this->minQualifyingRecharge(),
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
     * the time this runs, so this method does two things: it moves the run's
     * progress counter, and it pays out if that recharge finished the run.
     *
     * ## What counts
     *
     * `qualifyingRechargeCount()` is the single definition of a qualifying
     * recharge — approved, and at least the referral's own `min_amount`. This
     * method deliberately does not add "and the amount of this particular
     * recharge" to it: the count is derived from the recharge table, so it is
     * already true of committed data by the time it is read, and re-deriving it
     * from a hand-passed amount would be a second source of truth for the same
     * number. The `$amount` argument is therefore only ever used for the
     * human-readable message and for the `trigger_amount` the winning recharge
     * records.
     *
     * ## What does not pay
     *
     * A referral that simply does not qualify is NOT an error — no referral, a
     * disabled programme, an already-settled run, or a recharge below the floor
     * are all normal outcomes. The caller gets [false, reason] and moves on; the
     * only outcome worth surfacing to the user is a completed payout.
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

        $required = max(1, (int) $referral['required_count']);
        $min = (float) $referral['min_amount'];

        // Counted across the whole recharge history rather than incremented, so
        // the counter is correct even if an earlier approval ran before this
        // column existed or after a row was corrected by hand.
        $completed = $this->referrals->qualifyingRechargeCount($refereeId, $min);

        // Progress first, and it is written even when the run is not finished.
        // The referral page reads this column, so without it a user with four of
        // five approved recharges would be told they have made no progress.
        // `recordProgress()` is a guarded `pending -> pending` update, so it
        // cannot resurrect a run that was just paid or just voided.
        $this->referrals->recordProgress((int) $referral['id'], $completed);

        if ($completed < $required) {
            $remaining = $required - $completed;

            return [false, sprintf(
                'বন্ধুর %dটির মধ্যে %dটি রিচার্জ হয়েছে — আরও %dটি দরকার।',
                $required,
                $completed,
                $remaining,
            )];
        }

        $paid = $this->pay(
            (int) $referral['id'],
            [
                'trigger_topup_id' => $topupId,
                'trigger_amount' => $amount,
                'completed_count' => $required,
                'qualified_at' => date('Y-m-d H:i:s'),
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
     * Two independent guards make this safe to call twice, and they cover
     * different failures:
     *
     * - The `pending -> paid` transition at the end is a guarded UPDATE, so
     *   only one caller can win it. If this call loses, it returns false and
     *   the enclosing transaction rolls the credits back with it — a referral
     *   nobody can see in the ledger never keeps a balance it cannot explain.
     * - Each credit carries a deterministic ledger reference, so even a caller
     *   that somehow reached the balance code twice cannot mint a second
     *   ৳50: `transaction.reference` is UNIQUE and the insert is refused.
     *
     * Credits are written before the status flip rather than after, because a
     * flip that fails must be able to undo them. Doing it the other way round
     * would leave a `paid` referral whose money was never written.
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

            $referrerTx = $this->credit($referrerId, $referrerAmount, [
                'type' => 'referral_bonus',
                'direction' => 'referrer',
                'referral_id' => $referralId,
                'referee' => $refereeName,
                'code' => (string) $referral['code'],
            ], ReferralRepository::bonusReference($referralId, 'referrer'));

            $refereeTx = $this->credit($refereeId, $refereeAmount, [
                'type' => 'referral_bonus',
                'direction' => 'referee',
                'referral_id' => $referralId,
                'referrer' => $this->username($referrerId),
                'code' => (string) $referral['code'],
            ], ReferralRepository::bonusReference($referralId, 'referee'));

            $won = $this->referrals->transition(
                $referralId,
                ReferralRepository::PENDING,
                ReferralRepository::PAID,
                $context + [
                    'referrer_transaction_id' => $referrerTx > 0 ? $referrerTx : null,
                    'referee_transaction_id' => $refereeTx > 0 ? $refereeTx : null,
                    // What was actually credited, as opposed to the amount that
                    // was promised at signup. They differ when an admin pays a
                    // reduced goodwill bonus, and the ledger should say which
                    // of the two happened rather than leaving the reader to
                    // assume they are the same number.
                    'paid_referrer_amount' => $referrerAmount,
                    'paid_referee_amount' => $refereeAmount,
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

    /**
     * Ledger row + balance credit. Both sides of a referral payout go through here.
     *
     * Routed through {@see LedgerService} rather than writing the row and the
     * balance separately, so the two can never disagree — and so the entry
     * carries `balance_before`/`balance_after` like every other movement. That
     * matters here specifically: the caller wraps this in a transaction and
     * credits *two* accounts, and a row written outside the ledger's own
     * transaction would be the one record of that payout with no audit trail.
     *
     * Returns the ledger entry id so the referral row can point at it.
     *
     * Credit one side of a referral bonus, exactly once.
     *
     * The ledger reference is the idempotency key. `transaction.reference` has
     * a UNIQUE index, so if this row has already been credited — by an earlier
     * approval, a replayed request, or an admin who paid manually and then had
     * a recharge approved — the insert is refused by the database instead of
     * minting a second ৳50. That guarantee does not depend on any check
     * happening to run first, which is the only kind that survives concurrency.
     *
     * Returns 0 when the credit was refused, and the caller treats a 0 as "this
     * side is already paid" rather than as a failure: the money is already
     * there, so the referral is still correctly `paid`.
     */
    private function credit(int $userId, float $amount, array $metadata, string $reference): int
    {
        if ($amount <= 0) {
            return 0;
        }

        [$ok, , , $txId] = $this->ledger->creditUser($userId, $amount, [
            'type' => TransactionRepository::TYPE_REFERRAL_BONUS,
            'description' => 'রেফারেল বোনাস',
            'reference' => $reference,
            'metadata' => $metadata,
        ]);

        return $ok ? (int) $txId : 0;
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
