<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Throwable;

/**
 * Mock balance top-up flow: request → admin approval → balance credit.
 */
final class TopupService
{
    public const MIN_AMOUNT = 10.0;
    public const MAX_AMOUNT = 10000.0;

    public function __construct(
        private readonly TopupRepository $topups,
        private readonly UserRepository $users,
        private readonly TransactionRepository $transactions,
        private readonly NotificationRepository $notifications,
        private readonly ActivityLogRepository $logs,
    ) {}

    /**
     * Create a pending top-up request from the user's profile.
     *
     * @return array{0: bool, 1: string, 2: array<string,string>} [ok, message, fieldErrors]
     */
    public function request(int $userId, array $input): array
    {
        $amount = (float) str_replace(',', '', trim((string) ($input['amount'] ?? '0')));
        $method = (string) ($input['method'] ?? 'bkash');
        $sender = trim((string) ($input['sender_number'] ?? ''));
        $reference = trim((string) ($input['reference'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));

        $errors = [];
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            $errors['amount'] = sprintf('পরিমাণ ৳%s – ৳%s এর মধ্যে দিন।', number_format(self::MIN_AMOUNT), number_format(self::MAX_AMOUNT));
        }
        if (!in_array($method, TopupRepository::METHODS, true)) {
            $errors['method'] = 'মেথড নির্বাচন করুন।';
        }
        if ($sender === '') {
            $errors['sender_number'] = 'সেন্ডার নম্বর দিন।';
        }

        if ($errors !== []) {
            return [false, 'অনুরোধ সম্পূর্ণ করুন।', $errors];
        }

        $id = $this->topups->create([
            'user_id' => $userId,
            'amount' => $amount,
            'method' => $method,
            'sender_number' => $sender,
            'reference' => $reference !== '' ? $reference : null,
            'note' => $note !== '' ? $note : null,
        ]);

        $this->logs->create([
            'user_id' => $userId,
            'action' => 'topup.requested',
            'description' => sprintf('Top-up requested: ৳%.2f via %s', $amount, $method),
        ]);

        return [true, 'টপ-আপ অনুরোধ জমা হয়েছে — অ্যাডমিন অনুমোদনের পর ব্যালেন্স যোগ হবে।', []];
    }

    /**
     * Approve: credit balance, record transaction, notify user. Idempotent.
     */
    public function approve(int $topupId, int $reviewerId): array
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }
        if ($topup['status'] !== 'pending') {
            return [false, 'এই অনুরোধটি আগেই প্রসেস করা হয়েছে।'];
        }

        $amount = (float) $topup['amount'];
        $userId = (int) $topup['user_id'];

        $reference = 'TH' . strtoupper(bin2hex(random_bytes(5)));
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
            ],
        ]);

        $this->users->adjustBalance($userId, +$amount);
        $this->topups->update((int) $topup['id'], [
            'status' => 'approved',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'transaction_id' => $txId,
        ]);

        $this->notifications->create(
            $userId,
            'ব্যালেন্স যোগ হয়েছে',
            sprintf('আপনার ৳%s টপ-আপ অনুরোধ অনুমোদিত হয়েছে।', number_format($amount, 2)),
            'success',
        );
        $this->logs->create([
            'user_id' => $reviewerId,
            'action' => 'topup.approved',
            'description' => sprintf('Top-up #%d approved: ৳%.2f for user #%d', (int) $topup['id'], $amount, $userId),
            'metadata' => ['topup_id' => (int) $topup['id'], 'transaction_id' => $txId],
        ]);

        return [true, sprintf('৳%s টপ-আপ অনুমোদিত — ব্যালেন্স যোগ হয়েছে।', number_format($amount, 2))];
    }

    /**
     * Reject: mark rejected, notify user. Idempotent.
     */
    public function reject(int $topupId, int $reviewerId, string $reason = ''): array
    {
        $topup = $this->topups->findById($topupId);
        if ($topup === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }
        if ($topup['status'] !== 'pending') {
            return [false, 'এই অনুরোধটি আগেই প্রসেস করা হয়েছে।'];
        }

        $this->topups->update((int) $topup['id'], [
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->notifications->create(
            (int) $topup['user_id'],
            'টপ-আপ বাতিল হয়েছে',
            sprintf('আপনার ৳%s টপ-আপ অনুরোধ বাতিল করা হয়েছে।%s', number_format((float) $topup['amount'], 2), $reason !== '' ? ' কারণ: ' . $reason : ''),
            'warning',
        );
        $this->logs->create([
            'user_id' => $reviewerId,
            'action' => 'topup.rejected',
            'description' => sprintf('Top-up #%d rejected: ৳%.2f for user #%d', (int) $topup['id'], (float) $topup['amount'], (int) $topup['user_id']),
            'metadata' => ['topup_id' => (int) $topup['id'], 'reason' => $reason],
        ]);

        return [true, 'টপ-আপ অনুরোধ বাতিল করা হয়েছে।'];
    }
}
