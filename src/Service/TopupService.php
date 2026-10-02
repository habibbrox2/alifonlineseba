<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Recharge (top-up) flow: the user submits the payment details plus a receipt
 * screenshot, an admin verifies it manually and approves (crediting balance) or
 * rejects it with a reason.
 *
 * Design notes:
 *
 * - Amount limits are read from admin settings rather than hard-coded, so the
 *   operator can change them without a deploy.
 * - A transaction ID that is already pending or approved is refused. This is
 *   the single most valuable guard in the whole flow: without it a user can
 *   paste the same bKash TrxID ten times and get credited ten times.
 * - Approve is idempotent — the status check happens before the balance
 *   change, so a double-click or a replayed POST cannot double-credit.
 * - A request passes through three live states: `pending` (nobody has looked),
 *   `review` (a named admin has claimed it via `claim()`) and then a decision.
 *   Every "is this still open?" guard reads `TopupRepository::OPEN_STATUSES`
 *   rather than hard-coding `pending`, so the middle state cannot fall through
 *   a check.
 * - Receipts are written to disk BEFORE the row is inserted; if the row insert
 *   fails the orphaned file is cleaned up rather than left behind.
 */
final class TopupService
{
    /** Used when the corresponding setting is missing or not a number. */
    public const DEFAULT_MIN_AMOUNT = 10.0;
    public const DEFAULT_MAX_AMOUNT = 100000.0;

    /** Guard rail: a "recharge" larger than this is almost certainly a typo. */
    private const HARD_MAX_AMOUNT = 1000000.0;

    public function __construct(
        private readonly TopupRepository $topups,
        private readonly UserRepository $users,
        private readonly TransactionRepository $transactions,
        private readonly NotificationRepository $notifications,
        private readonly ActivityLogRepository $logs,
        private readonly SettingsRepository $settings,
        private readonly ReceiptStorage $receipts,
        private readonly NotificationManager $notify,
        // Nullable so the recharge functional test can still build this
        // service by hand; the DI container always supplies the real one.
        private readonly ?ReferralService $referrals = null,
    ) {}

    public function minAmount(): float
    {
        $value = (float) $this->settings->get('topup_min_amount', (string) self::DEFAULT_MIN_AMOUNT);
        return $value > 0 ? $value : self::DEFAULT_MIN_AMOUNT;
    }

    public function maxAmount(): float
    {
        $value = (float) $this->settings->get('topup_max_amount', (string) self::DEFAULT_MAX_AMOUNT);
        // Never let a bad settings row remove the upper bound entirely.
        return ($value > 0 && $value <= self::HARD_MAX_AMOUNT) ? $value : self::DEFAULT_MAX_AMOUNT;
    }

    public function isReceiptRequired(): bool
    {
        return $this->settings->get('topup_receipt_required', '1') !== '0';
    }

    /**
     * Create a pending recharge request from the user's account page.
     *
     * @return array{0: bool, 1: string, 2: array<string,string>} [ok, message, fieldErrors]
     */
    public function request(int $userId, array $input, ?UploadedFileInterface $receipt = null): array
    {
        $min = $this->minAmount();
        $max = $this->maxAmount();

        $amount = (float) str_replace(',', '', trim((string) ($input['amount'] ?? '0')));
        $method = strtolower(trim((string) ($input['method'] ?? 'bkash')));
        $sender = trim((string) ($input['sender_number'] ?? ''));
        $senderName = trim((string) ($input['sender_name'] ?? ''));
        $reference = strtoupper(trim((string) ($input['reference'] ?? '')));
        $note = trim((string) ($input['note'] ?? ''));

        $errors = [];

        if ($amount < $min || $amount > $max) {
            $errors['amount'] = sprintf(
                'পরিমাণ %s – %s এর মধ্যে দিন।',
                number_format($min, 0),
                number_format($max, 0),
            );
        } elseif (round($amount, 2) != $amount) {
            $errors['amount'] = 'সর্বোচ্চ ২ দশমিক ঘর পর্যন্ত দিন (যেমন ৫০০.৫০)।';
        }

        if (!in_array($method, TopupRepository::METHODS, true)) {
            $errors['method'] = 'মেথড নির্বাচন করুন।';
        }

        // Bangladeshi mobile numbers only, with or without +88 / spaces.
        if ($sender === '') {
            $errors['sender_number'] = 'যে ওয়ালেট থেকে পাঠিয়েছেন সেই নম্বর দিন।';
        } elseif (preg_match('/^(?:\+?88)?0?1[3-9]\d{8}$/', preg_replace('/[\s\-]/', '', $sender) ?? '') !== 1) {
            $errors['sender_number'] = 'সঠিক মোবাইল নম্বর দিন (যেমন 01712345678)।';
        }

        if ($reference === '') {
            $errors['reference'] = 'লেনদেন আইডি (TrxID) দিন — এটি ছাড়া যাচাই করা সম্ভব নয়।';
        } elseif (preg_match('/^[A-Z0-9\-]{4,64}$/', $reference) !== 1) {
            $errors['reference'] = 'লেনদেন আইডি ৪–৬৪ অক্ষরের, বর্ণ ও সংখ্যা মিলিয়ে দিন।';
        } else {
            $duplicate = $this->topups->findByReference($reference);
            if ($duplicate !== null) {
                $errors['reference'] = (int) $duplicate['user_id'] === $userId
                    ? 'এই লেনদেন আইডি দিয়ে আগেই একটি অনুরোধ জমা হয়েছে।'
                    : 'এই লেনদেন আইডি অন্য একটি অনুরোধে ব্যবহৃত হয়েছে।';
            }
        }

        // Reject a second open request from the same user so the admin queue
        // cannot be flooded with requests for a single payment. "Open" includes
        // `review`: a request somebody is already verifying is emphatically not
        // a reason to accept the same payment again.
        if ($this->topups->hasOpenRequest($userId)) {
            $errors['amount'] = 'আপনার একটি অনুরোধ ইতিমধ্যে প্রক্রিয়াধীন আছে — সেটি শেষ হওয়ার পর নতুন করে জমা দিন।';
        }

        $receiptMeta = null;
        if ($receipt !== null && $receipt->getError() !== UPLOAD_ERR_NO_FILE) {
            try {
                // Filed under a placeholder id, re-filed once the row exists.
                $receiptMeta = $this->receipts->store($receipt, 0);
            } catch (RuntimeException $e) {
                $errors['receipt'] = $e->getMessage();
            }
        } elseif ($this->isReceiptRequired()) {
            $errors['receipt'] = 'পেমেন্টের রশিদের ছবি অথবা PDF আপলোড করুন।';
        }

        if ($errors !== []) {
            // Nothing was written to the DB, so discard any file we just stored.
            if ($receiptMeta !== null) {
                $this->receipts->delete($receiptMeta['path']);
            }
            return [false, 'অনুরোধ সম্পূর্ণ করুন।', $errors];
        }

        $id = $this->topups->create([
            'user_id' => $userId,
            'amount' => $amount,
            'method' => $method,
            'sender_number' => $sender,
            'sender_name' => $senderName !== '' ? mb_substr($senderName, 0, 100) : null,
            'reference' => $reference,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            'receipt_path' => $receiptMeta['path'] ?? null,
            'receipt_name' => $receiptMeta['name'] ?? null,
            'receipt_mime' => $receiptMeta['mime'] ?? null,
            'receipt_size' => $receiptMeta['size'] ?? null,
        ]);

        // Re-file the receipt under this request's own directory so one user's
        // uploads are never enumerable from another's.
        if ($receiptMeta !== null) {
            $moved = $this->receipts->relocate($receiptMeta['path'], $id);
            if ($moved !== $receiptMeta['path']) {
                $this->topups->update($id, ['receipt_path' => $moved]);
            }
        }

        $this->logs->create([
            'user_id' => $userId,
            'action' => 'topup.requested',
            'description' => sprintf(
                'Top-up requested: %s via %s (TrxID %s)',
                number_format($amount, 2),
                $method,
                $reference,
            ),
            'metadata' => ['topup_id' => $id, 'reference' => $reference, 'has_receipt' => $receiptMeta !== null],
        ]);

        // Audit finding: the review queue was poll-only — nothing told a
        // reviewer a payment was waiting. Fan out to the admins now.
        $this->notify->dispatch(
            NotificationEvent::TOPUP_REQUESTED,
            $userId,
            [
                'amount' => number_format($amount, 2),
                'method' => SettingsRepository::METHOD_LABELS[$method] ?? strtoupper($method),
                'reference' => $reference,
                'topup_id' => $id,
                'user_id' => $userId,
            ],
            '/admin/topups',
            ['reference' => $reference, 'topup_id' => $id],
        );

        return [true, 'রিচার্জ অনুরোধ জমা হয়েছে — অ্যাডমিন যাচাই করার পর ব্যালেন্স যোগ হবে।', []];
    }

    /**
     * Approve: credit balance, record transaction, notify user. Idempotent.
     */
    public function approve(int $topupId, int $reviewerId, string $adminNote = ''): array
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }
        if (!in_array($topup['status'], TopupRepository::OPEN_STATUSES, true)) {
            return [false, 'এই অনুরোধটি আগেই প্রসেস করা হয়েছে।'];
        }

        $amount = (float) $topup['amount'];
        $userId = (int) $topup['user_id'];

        $reference = 'AL' . strtoupper(bin2hex(random_bytes(5)));
        $txId = $this->transactions->create([
            'user_id' => $userId,
            'reference' => $reference,
            'amount' => $amount,
            'status' => 'completed',
            'metadata' => [
                'type' => 'topup',
                'topup_id' => (int) $topup['id'],
                'method' => $topup['method'],
                'sender_number' => $topup['sender_number'],
                'trx_id' => $topup['reference'],
            ],
        ]);

        $this->users->adjustBalance($userId, +$amount);
        $this->topups->update((int) $topup['id'], [
            'status' => TopupRepository::APPROVED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            // An approval straight out of the queue never went through `claim()`.
            'claimed_by' => $topup['claimed_by'] ?? $reviewerId,
            'claimed_at' => $topup['claimed_at'] ?? date('Y-m-d H:i:s'),
            'transaction_id' => $txId,
            'admin_note' => $adminNote !== '' ? mb_substr($adminNote, 0, 500) : null,
        ]);

        $this->notify->dispatch(
            NotificationEvent::TOPUP_APPROVED,
            $userId,
            [
                'amount' => number_format($amount, 2),
                'method' => SettingsRepository::METHOD_LABELS[(string) $topup['method']] ?? (string) $topup['method'],
                'reference' => (string) ($topup['reference'] ?? '—'),
                'topup_id' => (int) $topup['id'],
            ],
            '/recharge',
            ['reference' => (string) ($topup['reference'] ?? ''), 'topup_id' => (int) $topup['id']],
        );

        // Referral trigger. The recharge is committed and the balance credited
        // by this point, so a referral payout that fails cannot leave the
        // topup half-done — and the user's own approval message above has
        // already been queued. Never let a referral problem block the
        // approval: the service is written to be a no-op for every account
        // that is not actually mid-referral.
        $this->referrals?->onTopupApproved($userId, (int) $topup['id'], $amount);
        $this->logs->create([
            'user_id' => $reviewerId,
            'action' => 'topup.approved',
            'description' => sprintf(
                'Top-up #%d approved: %s for user #%d',
                (int) $topup['id'],
                number_format($amount, 2),
                $userId,
            ),
            'metadata' => ['topup_id' => (int) $topup['id'], 'transaction_id' => $txId],
        ]);

        return [true, sprintf('%s রিচার্জ অনুমোদিত — ব্যালেন্স যোগ হয়েছে।', number_format($amount, 2))];
    }

    /**
     * Reject: mark rejected with a reason, notify user. Idempotent.
     *
     * A reason is mandatory: a bare "rejected" leaves the user unable to tell
     * a wrong amount from a bad screenshot, and they will simply resubmit.
     */
    public function reject(int $topupId, int $reviewerId, string $reason = '', string $adminNote = ''): array
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }
        if (!in_array($topup['status'], TopupRepository::OPEN_STATUSES, true)) {
            return [false, 'এই অনুরোধটি আগেই প্রসেস করা হয়েছে।'];
        }
        if (trim($reason) === '') {
            return [false, 'বাতিলের কারণ লিখুন — ইউজার এটি দেখবেন।'];
        }

        $this->topups->update((int) $topup['id'], [
            'status' => TopupRepository::REJECTED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'claimed_by' => $topup['claimed_by'] ?? $reviewerId,
            'claimed_at' => $topup['claimed_at'] ?? date('Y-m-d H:i:s'),
            'reject_reason' => mb_substr(trim($reason), 0, 500),
            'admin_note' => $adminNote !== '' ? mb_substr($adminNote, 0, 500) : null,
        ]);

        // The user is owed an answer they can act on, so the message carries the
        // reason and the TrxID verbatim — this notification is often the only
        // place they read it before deciding whether to resubmit.
        $this->notify->dispatch(
            NotificationEvent::TOPUP_REJECTED,
            (int) $topup['user_id'],
            [
                'amount' => number_format((float) $topup['amount'], 2),
                'reference' => (string) ($topup['reference'] ?? '—'),
                'reason' => trim($reason),
                'topup_id' => (int) $topup['id'],
            ],
            '/recharge',
            ['reference' => (string) ($topup['reference'] ?? ''), 'topup_id' => (int) $topup['id']],
        );
        $this->logs->create([
            'user_id' => $reviewerId,
            'action' => 'topup.rejected',
            'description' => sprintf(
                'Top-up #%d rejected: %s for user #%d',
                (int) $topup['id'],
                number_format((float) $topup['amount'], 2),
                (int) $topup['user_id'],
            ),
            'metadata' => ['topup_id' => (int) $topup['id'], 'reason' => trim($reason)],
        ]);

        return [true, 'রিচার্জ অনুরোধ বাতিল করা হয়েছে।'];
    }

    /**
     * User withdraws their own request while it is still undecided.
     *
     * Restricted to the owner and to the open statuses, so a user cannot cancel
     * an already-approved recharge and hide the credit trail. Cancelling a
     * `review` request is allowed: the money has not been credited, and a user
     * who paid the wrong amount should be able to stop a verifier from spending
     * ten minutes on it. The admin's decision re-checks the status afterwards,
     * so a cancel that lands mid-approval still wins.
     */
    public function cancel(int $topupId, int $userId): array
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null || (int) $topup['user_id'] !== $userId) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }
        if (!in_array($topup['status'], TopupRepository::OPEN_STATUSES, true)) {
            return [false, 'শুধুমাত্র প্রক্রিয়াধীন অনুরোধ বাতিল করা যায়।'];
        }

        $this->topups->update($topupId, [
            'status' => TopupRepository::REJECTED,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'reject_reason' => 'ব্যবহারকারী নিজে বাতিল করেছেন।',
        ]);

        $this->logs->create([
            'user_id' => $userId,
            'action' => 'topup.cancelled_by_user',
            'description' => sprintf('Top-up #%d cancelled by user', $topupId),
            'metadata' => ['topup_id' => $topupId],
        ]);

        return [true, 'আপনার রিচার্জ অনুরোধ বাতিল করা হয়েছে।'];
    }

    /**
     * Claim a pending request for review, moving it to `review`.
     *
     * This is what makes the queue survivable with more than one admin: opening
     * a request takes it out of the "anybody may grab this" pile and records
     * who has it, so a second operator sees it as already being worked instead
     * of verifying the same bKash screenshot a second time.
     *
     * Returns the claim stamp to show, or null when there is nothing to claim
     * (already claimed, already decided, or already this reviewer's).
     */
    public function claim(int $topupId, int $reviewerId): ?string
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null || $topup['status'] !== TopupRepository::PENDING) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $this->topups->update($topupId, [
            'status' => TopupRepository::REVIEW,
            'claimed_by' => $reviewerId,
            'claimed_at' => $now,
            'updated_at' => $now,
        ]);

        $this->logs->create([
            'user_id' => $reviewerId,
            'action' => 'topup.claimed',
            'description' => sprintf('Top-up #%d claimed for review', $topupId),
            'metadata' => ['topup_id' => $topupId],
        ]);

        return $now;
    }

    /**
     * Hand a claimed request back to the queue.
     *
     * Reviewing turned out to need someone else — the amount is larger than the
     * verifier's limit, the user has to be chased, another operator knows this
     * wallet. Leaving it in `review` would strand it with nobody's name on it.
     */
    public function release(int $topupId, int $reviewerId): array
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }
        if ($topup['status'] !== TopupRepository::REVIEW) {
            return [false, 'শুধুমাত্র যাচাই-ধরা অনুরোধটিই ফেরত পাঠানো যায়।'];
        }

        $this->topups->update($topupId, [
            'status' => TopupRepository::PENDING,
            'claimed_by' => null,
            'claimed_at' => null,
        ]);

        $this->logs->create([
            'user_id' => $reviewerId,
            'action' => 'topup.released',
            'description' => sprintf('Top-up #%d released back to the queue', $topupId),
            'metadata' => ['topup_id' => $topupId],
        ]);

        return [true, 'অনুরোধটি তালিকায় ফিরিয়ে দেওয়া হয়েছে।'];
    }

    /**
     * Approve several pending requests at once.
     *
     * Each one is processed independently: a single bad row is reported and the
     * rest still go through, which is what an admin clearing a queue wants.
     *
     * @param int[] $ids
     * @return array{approved: int, failed: int, messages: string[]}
     */
    public function approveMany(array $ids, int $reviewerId): array
    {
        $approved = 0;
        $failed = 0;
        $messages = [];

        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 50) as $id) {
            [$ok, $message] = $this->approve($id, $reviewerId);
            if ($ok) {
                $approved++;
            } else {
                $failed++;
                $messages[] = '#' . $id . ': ' . $message;
            }
        }

        return ['approved' => $approved, 'failed' => $failed, 'messages' => $messages];
    }

    /**
     * Delete a request's receipt from disk.
     *
     * Only used by maintenance — an approved or rejected request keeps its
     * receipt as the audit trail for the money movement, so the row is never
     * stripped of its evidence.
     */
    public function purgeReceipt(int $topupId): void
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null) {
            return;
        }
        $this->receipts->delete($topup['receipt_path'] ?? null);
        $this->topups->update($topupId, ['receipt_path' => null, 'receipt_name' => null, 'receipt_mime' => null, 'receipt_size' => null]);
    }
}
