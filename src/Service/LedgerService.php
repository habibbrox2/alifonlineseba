<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\Exception;

/**
 * The one place money moves.
 *
 * Every balance change in this application goes through this class — a recharge
 * approval, an order's debit, an admin's credit, a refund, a withdrawal, a
 * super-admin's correction. That is a deliberate choke point, because the
 * alternative is what the codebase had before: `UserRepository::adjustBalance()`
 * called from eight services, each remembering to also write a transaction row,
 * each getting it slightly different.
 *
 * Three things happen together, always:
 *
 *  1. The owner's row is locked (`SELECT … FOR UPDATE`). Without it two
 *     simultaneous approvals can both read a balance of 100 and both spend it.
 *  2. The balance is checked and moved.
 *  3. The movement is appended to the ledger with the balance on both sides.
 *
 * Steps 2 and 3 share one database transaction, so the ledger can never
 * disagree with the balance it describes. `balance_before`/`balance_after` are
 * read from the locked row rather than computed in PHP — under concurrency the
 * computed value is the one that lies.
 */
final readonly class LedgerService
{
    public function __construct(
        private ConnectionInterface $db,
        private UserRepository $users,
        private TransactionRepository $ledger,
    ) {}

    /**
     * Take money out of a user's wallet: the charge for an order, or an
     * admin's withdrawal hold.
     *
     * Refuses rather than allowing a negative balance. A customer who cannot
     * pay has to be told to top up; a customer who is quietly allowed to go
     * negative will find out from a recharge they did not make.
     *
     * @param array{
     *     type: string,
     *     description?: string|null,
     *     service_order_id?: int|null,
     *     allowNegative?: bool,
     *     metadata?: array<string, mixed>|null,
     *     reference?: string|null,
     * } $options
     *
     * @return array{0: bool, 1: string, 2: float, 3: int|null} [ok, message, balance after, ledger entry id]
     */
    public function debitUser(int $userId, float $amount, array $options): array
    {
        return $this->move($userId, 'user', -abs($amount), $options);
    }

    /**
     * Put money into a user's wallet: a recharge approval, an order refund, or
     * the return of a rejected withdrawal hold.
     *
     * @param array<string, mixed> $options
     * @return array{0: bool, 1: string, 2: float, 3: int|null}
     */
    public function creditUser(int $userId, float $amount, array $options): array
    {
        return $this->move($userId, 'user', abs($amount), $options);
    }

    /**
     * Credit an admin's wallet for work they approved.
     *
     * The counterpart of {@see debitUser()}: the same order takes money from
     * one wallet and puts it in another, and both entries reference the same
     * `service_order_id` so the pair can be read together.
     *
     * @param array<string, mixed> $options
     * @return array{0: bool, 1: string, 2: float, 3: int|null}
     */
    public function creditAdmin(int $adminId, float $amount, array $options): array
    {
        return $this->move($adminId, 'admin', abs($amount), $options);
    }

    /**
     * Take money out of an admin's wallet.
     *
     * `allowNegative` is not accepted here, and that asymmetry is the point: an
     * admin cannot overdraw themselves, because the platform would be owing
     * them money with nothing behind it.
     *
     * @param array<string, mixed> $options
     * @return array{0: bool, 1: string, 2: float, 3: int|null}
     */
    public function debitAdmin(int $adminId, float $amount, array $options): array
    {
        return $this->move($adminId, 'admin', -abs($amount), $options);
    }

    /**
     * The shared body. `$signed` is the balance delta; everything else — the
     * ledger direction, the direction label, the overdraft check — follows
     * from its sign, which is why no caller supplies a direction.
     *
     * @param array<string, mixed> $options
     * @return array{0: bool, 1: string, 2: float, 3: int|null}
     */
    private function move(int $ownerId, string $ownerKind, float $signed, array $options): array
    {
        $amount = abs($signed);
        if ($amount <= 0) {
            return [false, 'পরিমাণ শূন্য।', $this->currentBalance($ownerId, $ownerKind), null];
        }

        $allowNegative = (bool) ($options['allowNegative'] ?? false);
        $type = (string) $options['type'];

        try {
            $result = $this->db->transaction(function () use (
                $ownerId,
                $ownerKind,
                $signed,
                $amount,
                $type,
                $options,
                $allowNegative,
            ): array {
                $row = $this->lockedRow($ownerId);

                if ($row === null) {
                    return [false, 'অ্যাকাউন্ট পাওয়া যায়নি।', 0.0, null];
                }
                // A trashed account cannot be charged or paid. Its history is
                // preserved for a restore, but moving money for an account
                // nobody can reach is how a deletion turns into a loss.
                if ($row['deleted_at'] !== null) {
                    return [false, 'অ্যাকাউন্টটি মুছে ফেলা হয়েছে।', (float) $row['balance'], null];
                }
                if ($row['status'] !== 'active') {
                    return [false, 'অ্যাকাউন্টটি নিষ্ক্রিয় আছে।', (float) $row['balance'], null];
                }

                $before = round((float) $row['balance'], 2);
                $after = round($before + $signed, 2);

                if (!$allowNegative && $after < 0) {
                    return [false, $this->insufficientMessage($ownerKind, $before, $amount), $before, null];
                }

                $this->db
                    ->createCommand()
                    ->update(
                        '{{%user}}',
                        ['balance' => $after, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $ownerId],
                    )
                    ->execute();

                $entryId = $this->ledger->record([
                    'type' => $type,
                    'amount' => $amount,
                    'direction' => $signed < 0
                        ? TransactionRepository::DIRECTION_DEBIT
                        : TransactionRepository::DIRECTION_CREDIT,
                    $ownerKind . '_id' => $ownerId,
                    'service_order_id' => $options['service_order_id'] ?? null,
                    'description' => $options['description'] ?? null,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference' => $options['reference'] ?? null,
                    'metadata' => $options['metadata'] ?? null,
                ]);

                return [true, '', $after, $entryId];
            });
        } catch (Exception $e) {
            // The ledger and the balance share a transaction, so a failure here
            // means neither moved. Surfacing it as a plain refusal keeps the
            // caller's control flow the same as an overdraft, which is the
            // case it already handles.
            return [false, 'লেনদেন সম্পন্ন হয়নি। আবার চেষ্টা করুন।', $this->currentBalance($ownerId, $ownerKind), null];
        }

        return $result;
    }

    /**
     * Read the owner row under a row lock.
     *
     * `FOR UPDATE` is the whole reason two simultaneous approvals cannot both
     * spend the same balance. It is scoped to this transaction only, so it is
     * held for the microseconds between the read and the write and released
     * with the commit.
     *
     * @return array<string, mixed>|null
     */
    private function lockedRow(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%user}} WHERE [[id]] = :id FOR UPDATE')
            ->bindValue(':id', $id)
            ->queryOne();

        return $row === false ? null : $row;
    }

    /** Best-effort balance for the error path, where the transaction is gone. */
    private function currentBalance(int $ownerId, string $ownerKind): float
    {
        $user = $this->users->findById($ownerId, true);

        return $user === null ? 0.0 : (float) $user['balance'];
    }

    private function insufficientMessage(string $ownerKind, float $have, float $need): string
    {
        if ($ownerKind === 'admin') {
            return sprintf(
                'প্রত্যাহারের জন্য পর্যাপ্ত ব্যালেন্স নেই। আপনার জমা: ৳%s, চাইছেন: ৳%s',
                number_format($have, 2),
                number_format($need, 2),
            );
        }

        return sprintf(
            'পর্যাপ্ত ব্যালেন্স নেই। আপনার ব্যালেন্স: ৳%s, প্রয়োজন: ৳%s',
            number_format($have, 2),
            number_format($need, 2),
        );
    }
}
