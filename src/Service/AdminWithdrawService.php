<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\AdminWithdrawRepository;
use App\Repository\TransactionRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Admin withdrawals: an operator asks to take their earnings out, and a
 * super-admin decides.
 *
 * **The hold happens at request time, not at approval.** The admin's balance is
 * debited the moment the request row is created, and only approving records the
 * decision. The tempting alternative — hold on approve — has a hole in it that
 * costs real money: an admin with ৳1000 who can request four payouts of ৳1000
 * would have all four approved, because each approval would find ৳1000 still
 * sitting there. Debiting up front makes the balance the single source of
 * truth and needs no reserved-amount column to drift out of step with it.
 *
 * **Rejecting gives the hold back**, through the same ledger as everything
 * else, so the admin's statement shows the withdrawal and its reversal as two
 * rows rather than as a balance that mysteriously grew.
 *
 * **Nobody reviews their own request.** {@see self::approve()} refuses when the
 * requester and the reviewer are the same account, because the whole point of
 * the review is a second pair of eyes.
 */
final readonly class AdminWithdrawService
{
    /**
     * Smallest payout worth processing.
     *
     * A floor, not a policy preference: a ৳1 withdrawal costs the same fixed
     * effort as a ৳10,000 one and would otherwise be an easy way to make the
     * queue noisy.
     */
    public const MIN_AMOUNT = 10.0;

    /** Guard rail against a fat-fingered amount. */
    private const HARD_MAX_AMOUNT = 5000000.0;

    /**
     * method => Bengali label, for the request form and the review desk.
     *
     * The review screen has to *say* where the money is going: a payout whose
     * destination reads "bkash" with a number the reviewer has never seen
     * before is not a review, it is a rubber stamp.
     */
    public const METHOD_LABELS = [
        'bank' => 'ব্যাংক ট্রান্সফার',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private AdminWithdrawRepository $withdraws,
        private LedgerService $ledger,
        private NotificationManager $notify,
        private ActivityLogRepository $logs,
    ) {}

    public function minAmount(): float
    {
        return self::MIN_AMOUNT;
    }

    /**
     * An admin asks to withdraw from their earnings.
     *
     * @param array{amount: mixed, method?: mixed, account_details?: mixed, note?: mixed} $input
     *
     * @return array{0: bool, 1: string, 2: array<string, string>} [ok, message, field errors]
     */
    public function request(int $adminId, array $input): array
    {
        $errors = [];

        $amount = (float) str_replace(',', '', trim((string) ($input['amount'] ?? '0')));
        $method = strtolower(trim((string) ($input['method'] ?? 'bank')));
        $details = trim((string) ($input['account_details'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));

        if ($amount < self::MIN_AMOUNT || $amount > self::HARD_MAX_AMOUNT) {
            $errors['amount'] = sprintf(
                'পরিমাণ ৳%s – ৳%s এর মধ্যে দিন।',
                number_format(self::MIN_AMOUNT, 0),
                number_format(self::HARD_MAX_AMOUNT, 0),
            );
        } elseif (round($amount, 2) != $amount) {
            $errors['amount'] = 'সর্বোচ্চ ২ দশমিক ঘর পর্যন্ত দিন (যেমন ৫০০.৫০)।';
        }

        if (!in_array($method, AdminWithdrawRepository::METHODS, true)) {
            $errors['method'] = 'পেমেন্ট মাধ্যম নির্বাচন করুন।';
        }

        // The destination is the one field that cannot be guessed at. An empty
        // one is a payout nobody can send, discovered at the worst moment.
        if ($details === '') {
            $errors['account_details'] = 'যে ব্যাংক/ওয়ালেটে টাকা পেতে চান তার নম্বর ও বিবরণ দিন।';
        } elseif (mb_strlen($details) < 4) {
            $errors['account_details'] = 'অ্যাকাউন্টের বিবরণ আরও বিস্তারিত দিন।';
        }

        // One open request per admin at a time, for the same reason the recharge
        // queue refuses a second open request from one user: a queue of
        // duplicate payouts from the same person is a queue nobody can clear.
        if ($errors === [] && $this->hasOpenRequest($adminId)) {
            $errors['amount'] = 'আপনার একটি প্রত্যাহার অনুরোধ ইতিমধ্যে প্রক্রিয়াধীন আছে।';
        }

        if ($errors !== []) {
            return [false, 'অনুরোধ সম্পূর্ণ করুন।', $errors];
        }

        // The hold and the row are written together. If the row insert fails
        // after a successful debit, the money would be gone with nothing to
        // show for it, which is the failure mode this whole class exists to
        // make impossible.
        try {
            $id = $this->db->transaction(function () use ($adminId, $amount, $method, $details, $note): int {
                [$held, $message] = $this->ledger->debitAdmin($adminId, $amount, [
                    'type' => TransactionRepository::TYPE_WITHDRAW,
                    'description' => 'উত্তোলন অনুরোধ',
                    'metadata' => ['method' => $method],
                ]);

                if (!$held) {
                    throw new WithdrawRefused($message);
                }

                return $this->withdraws->create([
                    'admin_id' => $adminId,
                    'amount' => $amount,
                    'method' => $method,
                    'account_details' => $details,
                    'note' => $note,
                ]);
            });
        } catch (WithdrawRefused $e) {
            return [false, $e->getMessage(), []];
        } catch (\Throwable) {
            return [false, 'উত্তোলন অনুরোধ জমা হয়নি। আবার চেষ্টা করুন।', []];
        }

        $this->notify->dispatch(
            NotificationEvent::ADMIN_WITHDRAW_REQUESTED,
            $adminId,
            ['amount' => number_format($amount, 2), 'withdraw_id' => $id],
            '/admin/withdraw',
            ['withdraw_id' => $id],
        );
        $this->log($adminId, 'withdraw.requested', sprintf(
            'Withdrawal #%d requested: %s',
            $id,
            number_format($amount, 2),
        ));

        return [true, sprintf(
            '৳%s উত্তোলন অনুরোধ জমা হয়েছে — অনুমোদনের পর পাঠানো হবে।',
            number_format($amount, 2),
        ), []];
    }

    /**
     * Approve a withdrawal. The money already left the balance; this records the
     * decision and stamps the reviewer.
     *
     * @return array{0: bool, 1: string}
     */
    public function approve(int $id, Identity $reviewer): array
    {
        $row = $this->withdraws->findById($id);
        if ($row === null) {
            return [false, 'উত্তোলন অনুরোধ পাওয়া যায়নি।'];
        }

        if ((int) $row['admin_id'] === $reviewer->id) {
            return [false, 'নিজের উত্তোলন অনুরোধ নিজে অনুমোদন করা যায় না।'];
        }

        if (!in_array((string) $row['status'], AdminWithdrawRepository::OPEN_STATUSES, true)) {
            return [false, 'এই অনুরোধটি আগেই প্রক্রিয়া হয়েছে।'];
        }

        if (!$this->withdraws->markReviewed($id, $reviewer->id, AdminWithdrawRepository::APPROVED)) {
            return [false, 'এই অনুরোধটি আগেই প্রক্রিয়া হয়েছে।'];
        }

        $this->notify->dispatch(
            NotificationEvent::ADMIN_WITHDRAW_APPROVED,
            (int) $row['admin_id'],
            ['amount' => number_format((float) $row['amount'], 2), 'withdraw_id' => $id],
            '/admin/withdraw',
            ['withdraw_id' => $id],
        );
        $this->log($reviewer->id, 'withdraw.approved', sprintf(
            'Withdrawal #%d approved: %s to admin #%d',
            $id,
            number_format((float) $row['amount'], 2),
            (int) $row['admin_id'],
        ));

        return [true, sprintf('৳%s উত্তোলন অনুমোদিত হয়েছে।', number_format((float) $row['amount'], 2))];
    }

    /**
     * Reject a withdrawal and give the held money back to the admin.
     *
     * @return array{0: bool, 1: string}
     */
    public function reject(int $id, Identity $reviewer, string $reason = ''): array
    {
        $row = $this->withdraws->findById($id);
        if ($row === null) {
            return [false, 'উত্তোলন অনুরোধ পাওয়া যায়নি।'];
        }

        if (!in_array((string) $row['status'], AdminWithdrawRepository::OPEN_STATUSES, true)) {
            return [false, 'এই অনুরোধটি আগেই প্রক্রিয়া হয়েছে।'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            // Mandatory, for the same reason a recharge rejection needs one: a
            // bare "rejected" tells the admin nothing and they will simply
            // submit again, five minutes later, with the same details.
            return [false, 'বাতিলের একটি কারণ লিখুন।'];
        }

        $amount = abs((float) $row['amount']);

        // The hold goes back before the row is marked decided, so a failure
        // leaves the request open and retryable rather than closed with an
        // admin mysteriously out of pocket.
        [$refunded, $message] = $this->ledger->creditAdmin((int) $row['admin_id'], $amount, [
            'type' => TransactionRepository::TYPE_WITHDRAW_REFUND,
            'description' => sprintf('উত্তোলন অনুরোধ #%d বাতিল — টাকা ফেরত', $id),
            'metadata' => ['withdraw_id' => $id],
        ]);
        if (!$refunded) {
            return [false, $message];
        }

        $this->withdraws->markReviewed($id, $reviewer->id, AdminWithdrawRepository::REJECTED, [
            'reject_reason' => mb_substr($reason, 0, 500),
        ]);

        $this->notify->dispatch(
            NotificationEvent::ADMIN_WITHDRAW_REJECTED,
            (int) $row['admin_id'],
            [
                'amount' => number_format($amount, 2),
                'reason' => mb_substr($reason, 0, 200),
                'withdraw_id' => $id,
            ],
            '/admin/withdraw',
            ['withdraw_id' => $id],
        );
        $this->log($reviewer->id, 'withdraw.rejected', sprintf(
            'Withdrawal #%d rejected: %s returned to admin #%d',
            $id,
            number_format($amount, 2),
            (int) $row['admin_id'],
        ));

        return [true, 'উত্তোলন অনুরোধ বাতিল হয়েছে এবং টাকা ব্যালেন্সে ফিরে গেছে।'];
    }

    /**
     * Claim a withdrawal for review, or report who has it.
     *
     * @return array{0: bool, 1: string}
     */
    public function claim(int $id, Identity $reviewer): array
    {
        $row = $this->withdraws->findById($id);
        if ($row === null) {
            return [false, 'উত্তোলন অনুরোধ পাওয়া যায়নি।'];
        }

        $status = (string) $row['status'];
        if (!in_array($status, AdminWithdrawRepository::OPEN_STATUSES, true)) {
            return [false, 'এই অনুরোধটি আগেই প্রক্রিয়া হয়েছে।'];
        }
        if ($status === AdminWithdrawRepository::REVIEW && (int) ($row['claimed_by'] ?? 0) === $reviewer->id) {
            return [true, 'অনুরোধটি আপনার নামে ধরা আছে।'];
        }

        if (!$this->withdraws->claim($id, $reviewer->id)) {
            return [false, 'এই অনুরোধটি অন্য একজন সুপারএডমিন ধরে আছেন।'];
        }

        $this->log($reviewer->id, 'withdraw.claimed', sprintf('Withdrawal #%d claimed', $id));

        return [true, 'অনুরোধটি আপনার নামে ধরা হয়েছে।'];
    }

    /** @return array{0: bool, 1: string} */
    public function release(int $id, Identity $reviewer): array
    {
        if (!$this->withdraws->release($id, $reviewer->id)) {
            return [false, 'অনুরোধটি আপনার নামে নেই।'];
        }

        $this->log($reviewer->id, 'withdraw.released', sprintf('Withdrawal #%d released', $id));

        return [true, 'অনুরোধটি তালিকায় ফিরিয়ে দেওয়া হয়েছে।'];
    }

    /** Whether this admin already has a request nobody has decided yet. */
    public function hasOpenRequest(int $adminId): bool
    {
        return $this->withdraws->hasOpenRequest($adminId);
    }

    private function log(int $actorId, string $action, string $description): void
    {
        $this->logs->create([
            'user_id' => $actorId,
            'action' => $action,
            'description' => $description,
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'metadata' => ['area' => 'admin_withdraw'],
        ]);
    }
}
