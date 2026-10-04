<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The admin side of a service order: claim it, decide it, hand over the file.
 *
 * Three rules govern everything here, and each one exists because of a specific
 * accident it prevents.
 *
 * **One admin at a time.** There is more than one operator, and two of them
 * opening the same order is not a near-miss — it means one of them approves
 * work the other one did. So opening an order claims it: `claim()` is a single
 * conditional UPDATE, and the second admin's statement matches zero rows and
 * they are told who has it. `release()` hands it back, because sometimes the
 * person who opened it is the wrong person for it.
 *
 * **Money moves on exactly one edge.** An order is debited from the user when
 * they place it. It is credited to the admin who approves it. It is refunded to
 * the user if it is cancelled or fails. Both directions write a ledger entry,
 * through {@see LedgerService}, so "where did this month's money come from" is
 * a query and not an argument.
 *
 * **The guard is the `approved_by` column, not a status check.** A status check
 * reads "has somebody decided this yet", which is not the question — the
 * question is "has the money already gone", and an order can be `completed`
 * with its money still owed if a credit failed. `markApproved()` writes the
 * column and the status in one guarded UPDATE, so approving twice is
 * impossible rather than merely discouraged.
 */
final readonly class ServiceRequestAdminService
{
    /**
     * Largest selection one bulk submission may carry.
     *
     * Public because the queue and the CSV export are held to the same cap: a
     * limit enforced only on the settle path would let the two paths drift.
     */
    public const BULK_LIMIT = 100;

    public function __construct(
        private ServiceOrderRepository $orders,
        private LedgerService $ledger,
        private UserRepository $users,
        private DeliverableStorage $deliverables,
        private NotificationManager $notify,
        private ActivityLogRepository $logs,
    ) {}

    /**
     * Take an order for review, naming the admin who took it.
     *
     * Returns a `false` with a reason rather than throwing, because "somebody
     * else is already on it" is an ordinary answer on a page a queue of work
     * links to — not an exceptional condition.
     *
     * @return array{0: bool, 1: string} [ok, Bengali message]
     */
    public function claim(int $id, Identity $admin): array
    {
        $row = $this->orders->findById($id);
        if ($row === null) {
            return [false, 'অর্ডার পাওয়া যায়নি।'];
        }

        $status = (string) $row['status'];
        if (!in_array($status, [StatusPresenter::PENDING, 'review'], true)) {
            return [false, 'এই অর্ডারটি ইতিমধ্যে সিদ্ধান্ত হয়ে গেছে।'];
        }

        // Already ours — re-opening our own order is not a claim, and saying
        // so is more honest than stamping a fresh `claimed_at` every reload,
        // which would make the queue look like nobody is working it.
        if ($status === 'review' && (int) ($row['claimed_by'] ?? 0) === $admin->id) {
            return [true, 'অর্ডারটি আপনার নামে ধরা আছে।'];
        }

        if (!$this->orders->claimOrder($id, $admin->id)) {
            $holder = $this->claimerName($row);

            return [false, $holder === null
                ? 'এই অর্ডারটি একজন অ্যাডমিন ইতিমধ্যে ধরে আছেন।'
                : sprintf('এই অর্ডারটি %s ধরে আছেন — তাঁর সিদ্ধান্তের জন্য অপেক্ষা করুন।', $holder)];
        }

        $this->log($admin, $id, (string) $row['reference'], 'order.claimed', sprintf(
            'Order #%d claimed for review',
            $id,
        ));

        return [true, 'অর্ডারটি আপনার নামে ধরা হয়েছে।'];
    }

    /**
     * Claim without commentary, for a GET that renders the page.
     *
     * Opening the desk *is* the act of reviewing, exactly as it is for a
     * recharge, so the page load claims the row. The return value is ignored
     * here — the page still renders either way, and showing a taken order to
     * the person who has it (read-only, their own claim) is more useful than a
     * refusal.
     */
    public function claimOnOpen(int $id, Identity $admin): void
    {
        $this->orders->claimOrder($id, $admin->id);
    }

    /**
     * Hand a claimed order back to the queue.
     *
     * @return array{0: bool, 1: string}
     */
    public function release(int $id, Identity $admin): array
    {
        $row = $this->orders->findById($id);
        if ($row === null) {
            return [false, 'অর্ডার পাওয়া যায়নি।'];
        }

        if (!$this->orders->releaseOrder($id, $admin->id)) {
            return [false, (string) $row['status'] === 'review'
                ? 'এই অর্ডারটি আপনার নামে নেই — অন্য অ্যাডমিন ধরে আছেন।'
                : 'শুধুমাত্র যাচাই-ধরা অর্ডারই তালিকায় ফেরত পাঠানো যায়।'];
        }

        $this->log($admin, $id, (string) $row['reference'], 'order.released', sprintf(
            'Order #%d released back to the queue',
            $id,
        ));

        return [true, 'অর্ডারটি তালিকায় ফিরিয়ে দেওয়া হয়েছে।'];
    }

    /**
     * Approve an order: complete it and pay the admin who approved it.
     *
     * The order of operations is not stylistic. `markApproved()` runs first
     * because it is the guarded, atomic step — it is what makes a double
     * approve impossible. The credit follows because it is the step that can
     * fail for an ordinary reason (a database hiccup), and an order that is
     * marked approved but unpaid would be money owed to an operator with
     * nothing on any page saying so. If the credit does fail, the mark is
     * rolled back so the order goes back to being claimable and the next
     * operator to try gets a clean shot at it.
     *
     * @return array{0: bool, 1: string}
     */
    public function approve(int $id, Identity $admin, string $note = ''): array
    {
        $row = $this->orders->findById($id);
        if ($row === null) {
            return [false, 'অর্ডার পাওয়া যায়নি।'];
        }

        $status = (string) $row['status'];
        if ($status === StatusPresenter::COMPLETED) {
            return [false, 'এই অর্ডারটি ইতিমধ্যে অনুমোদিত হয়েছে।'];
        }
        if (!in_array($status, ServiceOrderRepository::OPEN_STATUSES, true)) {
            return [false, 'অনুমোদনের জন্য অর্ডারটি আর খোলা নেই।'];
        }

        $amount = (float) $row['amount'];
        if ($amount <= 0) {
            return [true, 'ফ্রি অর্ডারটি সম্পন্ন করা হয়েছে (টাকা নেওয়া হয়নি)।'];
        }

        // The atomic gate. `false` here means somebody else got there first,
        // and it is the only thing standing between a double-click and an
        // operator paid twice for one order.
        if (!$this->orders->markApproved($id, $admin->id, StatusPresenter::COMPLETED)) {
            return [false, 'এই অর্ডারটি ইতিমধ্যে অনুমোদিত হয়েছে।'];
        }

        [$paid, $message] = $this->ledger->creditAdmin($admin->id, $amount, [
            'type' => TransactionRepository::TYPE_ORDER_CREDIT,
            'service_order_id' => $id,
            'description' => sprintf(
                'অর্ডার #%d (%s) অনুমোদন',
                $id,
                (string) $row['reference'],
            ),
            'metadata' => ['order_reference' => (string) $row['reference']],
        ]);

        if (!$paid) {
            // Compensating write: undo the mark so the order is unpaid and
            // still claimable. Leaving it marked would silently lose the
            // operator's revenue.
            $this->orders->update($id, [
                'status' => $status === 'review' ? 'review' : StatusPresenter::PENDING,
                'approved_by' => null,
                'approved_at' => null,
                'claimed_by' => $status === 'review' ? $admin->id : null,
                'claimed_at' => $status === 'review' ? date('Y-m-d H:i:s') : null,
            ]);

            return [false, $message . ' অর্ডারটি ফেরত অপেক্ষমাণ রাখা হয়েছে।'];
        }

        if ($note !== '') {
            $this->orders->update($id, ['admin_note' => mb_substr($note, 0, 500)]);
        }

        $this->announce($row, StatusPresenter::COMPLETED, false);
        $this->log($admin, $id, (string) $row['reference'], 'order.approved', sprintf(
            'Order #%d approved: %s credited to admin #%d',
            $id,
            number_format($amount, 2),
            $admin->id,
        ));

        return [true, sprintf('অর্ডার অনুমোদিত — ৳%s আপনার ব্যালেন্সে যোগ হয়েছে।', number_format($amount, 2))];
    }

    /**
     * Cancel or fail an order: the held amount goes back to the user.
     *
     * Two guards, in this order, and the order matters.
     *
     * `approved_by` first: when it is set the money has already gone to the
     * operator, and this order is not a customer-service problem any more, it
     * is a books problem. Relabelling it `failed` would tell the user their
     * work did not happen while the operator kept the cash — so it is refused
     * outright and reversing it is a deliberate super-admin action. Checking
     * this *after* the open-status guard would make it unreachable, because a
     * paid order is `completed` and so never open, and the operator would be
     * told only that the order is closed.
     *
     * Then the open-status guard. An order that is already settled but was
     * never paid out — cancelled, then marked failed by mistake — may still be
     * relabelled: no money is involved, and refusing would leave an operator
     * unable to correct a mislabelled row. The status is written, nothing is
     * credited, and the log line says so.
     *
     * @param string $status one of `cancelled` / `failed`
     *
     * @return array{0: bool, 1: string}
     */
    public function refund(int $id, Identity $admin, string $status, string $reason = '', string $note = ''): array
    {
        if (!in_array($status, [StatusPresenter::CANCELLED, StatusPresenter::FAILED], true)) {
            return [false, 'অজানা অবস্থা।'];
        }

        $row = $this->orders->findById($id);
        if ($row === null) {
            return [false, 'অর্ডার পাওয়া যায়নি।'];
        }

        $from = (string) $row['status'];
        if ($from === $status) {
            return [true, 'অবস্থা অপরিবর্তিত আছে।'];
        }

        // Before the open-status guard, so the operator is told the one thing
        // that actually explains the refusal: the money is already gone.
        if ($row['approved_by'] !== null) {
            return [false, 'অর্ডারটির টাকা ইতিমধ্যে এডমিনকে দেওয়া হয়েছে। সুপারএডমিনের সাহায্য প্রয়োজন।'];
        }

        $amount = abs((float) $row['amount']);
        $isOpen = in_array($from, ServiceOrderRepository::OPEN_STATUSES, true);

        if (!$isOpen && !in_array($from, [StatusPresenter::CANCELLED, StatusPresenter::FAILED], true)) {
            return [false, 'এই অর্ডারটি আর খোলা নেই — টাকা ফেরত দেওয়া যাবে না।'];
        }

        // Refund first, then move the status. If the refund fails the order
        // stays open and nobody loses money; if the status write were first and
        // the refund then failed, the order would be closed with the customer
        // still out of pocket and nothing left to retry from. A relabel of an
        // already-settled row skips this entirely — the money already went.
        if ($isOpen && $amount > 0) {
            [$refunded, $message] = $this->ledger->creditUser((int) $row['user_id'], $amount, [
                'type' => TransactionRepository::TYPE_ORDER_REFUND,
                'service_order_id' => $id,
                'description' => sprintf('অর্ডার #%d ফেরত (%s)', $id, StatusPresenter::label($status)),
                'metadata' => ['order_reference' => (string) $row['reference']],
            ]);
            if (!$refunded) {
                return [false, $message];
            }
        }

        $this->orders->update($id, [
            'status' => $status,
            'claimed_by' => null,
            'claimed_at' => null,
            'cancel_reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
            'admin_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);

        // Only a real refund is news. Relabelling a settled row moves no money
        // and the user already has the last word on it, so announcing it would
        // be a second notification about something already decided.
        if ($isOpen) {
            $this->announce($row, $status, $amount > 0);
        }
        $this->log($admin, $id, (string) $row['reference'], 'order.' . $status, sprintf(
            'Order #%d set to %s%s',
            $id,
            $status,
            $isOpen && $amount > 0 ? sprintf(' — refunded %s', number_format($amount, 2)) : '',
        ));

        return [true, sprintf(
            'অর্ডার %s — ইউজারের ব্যালেন্সে ৳%s ফেরত দেওয়া হয়েছে।',
            StatusPresenter::label($status),
            number_format($amount, 2),
        )];
    }

    /**
     * Move an order to a status that does not move money.
     *
     * `processing` is the honest answer for "an operator has started on this
     * and the customer should know". It deliberately does not pay anybody: the
     * payment is the approval, and letting a status change pay out would make
     * the ledger depend on a dropdown.
     *
     * @return array{0: bool, 1: string}
     */
    public function setStatus(int $id, string $status, Identity $admin, string $note = ''): array
    {
        if ($status === StatusPresenter::COMPLETED) {
            return $this->approve($id, $admin, $note);
        }
        if (in_array($status, [StatusPresenter::CANCELLED, StatusPresenter::FAILED], true)) {
            return $this->refund($id, $admin, $status, $note);
        }
        if ($status !== StatusPresenter::PROCESSING) {
            return [false, 'অজানা অবস্থা।'];
        }

        $row = $this->orders->findById($id);
        if ($row === null) {
            return [false, 'অর্ডার পাওয়া যায়নি।'];
        }

        $from = (string) $row['status'];
        if ($from === $status) {
            return [true, 'অবস্থা অপরিবর্তিত আছে।'];
        }
        if (!in_array($from, ServiceOrderRepository::OPEN_STATUSES, true)) {
            return [false, 'এই অর্ডারটি আর খোলা নেই।'];
        }

        $this->orders->setStatus($id, $status, $note !== '' ? ['admin_note' => mb_substr($note, 0, 500)] : []);

        $this->announce($row, $status, false);
        $this->log($admin, $id, (string) $row['reference'], 'order.status', sprintf(
            'Admin set #%d to %s (was %s)',
            $id,
            $status,
            $from,
        ));

        return [true, 'অবস্থা হালনাগাদ হয়েছে: ' . StatusPresenter::label($status)];
    }

    /**
     * Settle many orders to one status, on an admin's authority.
     *
     * The queue is a queue: an operator working a backlog of a hundred orders
     * does the same thing to all of them, and making that a hundred page loads
     * is how a hundred become fifty. So the work is the *same* work — every row
     * goes through the single-order path, with its claim, its money rules, its
     * notification and its activity log — and this only removes the clicking.
     *
     * Only `pending` rows are settled. A `review` row belongs to the admin who
     * claimed it, and quietly approving somebody else's open order from a bulk
     * button is the exact accident the claim exists to prevent.
     *
     * `ok` is true only when something actually moved. The caller flashes it,
     * and "nothing happened" deserves a different colour from "done".
     *
     * @param int[] $ids
     *
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int}
     */
    public function settleMany(array $ids, string $status, Identity $admin): array
    {
        if (!in_array($status, StatusPresenter::REQUEST_STATUSES, true)) {
            return $this->bulkResult(false, 0, 0, 0, 'অজানা অবস্থা।');
        }

        // intval() strips whatever a client put in the array, the filter drops
        // the zeros and negatives that leaves, and the de-dupe means a
        // checkbox submitted twice settles once rather than twice.
        $ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);
        $ids = array_slice(array_values(array_unique($ids)), 0, self::BULK_LIMIT);

        if ($ids === []) {
            return $this->bulkResult(false, 0, 0, 0, 'কোনো অর্ডার নির্বাচন করা হয়নি।');
        }

        $rows = $this->orders->findManyByIds($ids);

        $applied = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            // Missing row, or a row somebody else is already working.
            if ($row === null) {
                $skipped++;
                continue;
            }
            if ((string) $row['status'] !== StatusPresenter::PENDING) {
                $unchanged++;
                continue;
            }

            [$ok] = $this->setStatus($id, $status, $admin);
            $ok ? $applied++ : $skipped++;
        }

        $message = sprintf('%d টি অর্ডার হালনাগাদ হয়েছে: %s', $applied, StatusPresenter::label($status));
        if ($unchanged > 0) {
            $message .= sprintf(' · %d টি বাদ পড়েছে (আগেই ধরা বা সিদ্ধান্ত হওয়া)', $unchanged);
        }
        if ($skipped > 0) {
            $message .= sprintf(' · %d টি বাদ পড়েছে', $skipped);
        }

        if ($applied === 0) {
            $message = 'কোনো অর্ডারের অবস্থা বদলায়নি।';
            if ($unchanged > 0) {
                $message .= sprintf(' %d টি আগেই অন্য অ্যাডমিন ধরে আছে বা সিদ্ধান্ত হয়ে গেছে।', $unchanged);
            }
            if ($skipped > 0) {
                $message .= sprintf(' %d টি বাদ পড়েছে।', $skipped);
            }
        }

        return $this->bulkResult($applied > 0, $applied, $unchanged, $skipped, $message);
    }

    /**
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int}
     */
    private function bulkResult(bool $ok, int $applied, int $unchanged, int $skipped, string $message): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'applied' => $applied,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
        ];
    }

    /**
     * Store a result file against an order, replacing any previous one.
     *
     * The old bytes are deleted only after the new row is written, so a failed
     * upload can never destroy a deliverable that is already there. The
     * opposite order is the tempting one — and it is how you end up with a row
     * pointing at a file that was never written.
     *
     * @return array{0: bool, 1: string} [ok, Bengali message]
     */
    public function attachDeliverable(int $id, UploadedFileInterface $file, Identity $admin): array
    {
        $row = $this->orders->findById($id);
        if ($row === null) {
            return [false, 'অর্ডার পাওয়া যায়নি।'];
        }

        $previousPath = $row['deliverable_path'] ?? null;

        try {
            $stored = $this->deliverables->store($file, $id);
        } catch (\RuntimeException $e) {
            // The message is already user-facing and specific (size, format,
            // PHP upload error); the admin needs to know which of those it was.
            return [false, $e->getMessage()];
        }

        $this->orders->attachDeliverable($id, $stored, $admin->id);

        if ($previousPath !== null && $previousPath !== $stored['path']) {
            $this->deliverables->delete($previousPath);
        }

        $this->notify->dispatch(
            NotificationEvent::SERVICE_REQUEST_FILE,
            (int) $row['user_id'],
            ['service' => $this->serviceName($row), 'reference' => (string) $row['reference']],
            '/service-history',
            ['reference' => (string) $row['reference'], 'tx_id' => $id],
        );
        $this->log($admin, $id, (string) $row['reference'], 'order.file_attached', sprintf(
            'File "%s" attached to #%d',
            $stored['name'],
            $id,
        ));

        return [true, 'ফাইলটি আপলোড হয়েছে। ইউজার এখন ডাউনলোড করতে পারবে।'];
    }

    /**
     * Remove an order's deliverable, from disk and from the row.
     *
     * Needed because an attached file is not automatically the right one: an
     * admin can upload the wrong scan, or a result that turned out to belong to
     * a different order. Deleting the bytes is the point — an orphaned ID result
     * that nothing in the app can reach is data about somebody that we can no
     * longer revoke.
     *
     * @return array{0: bool, 1: string}
     */
    public function detachDeliverable(int $id, Identity $admin): array
    {
        $before = $this->orders->detachDeliverable($id);
        if ($before === null) {
            return [false, 'এই অর্ডারে কোনো ফাইল নেই।'];
        }

        $this->deliverables->delete($before['deliverable_path'] ?? null);
        $this->log($admin, $id, (string) $before['reference'], 'order.file_removed', sprintf(
            'File removed from #%d',
            $id,
        ));

        return [true, 'ফাইলটি মুছে ফেলা হয়েছে।'];
    }

    /**
     * Tell the user their order moved.
     *
     * Only a *change* is announced — see the guard in `setStatus()`.
     */
    private function announce(array $row, string $status, bool $refunded): void
    {
        $event = match ($status) {
            StatusPresenter::PROCESSING => NotificationEvent::SERVICE_REQUEST_PROCESSING,
            StatusPresenter::COMPLETED => NotificationEvent::SERVICE_REQUEST_COMPLETED,
            StatusPresenter::FAILED => NotificationEvent::SERVICE_REQUEST_FAILED,
            StatusPresenter::CANCELLED => NotificationEvent::SERVICE_REQUEST_CANCELLED,
            default => null,
        };

        if ($event === null) {
            return;
        }

        $this->notify->dispatch(
            $event,
            (int) $row['user_id'],
            [
                'service' => $this->serviceName($row),
                'reference' => (string) $row['reference'],
                'reason' => $status === StatusPresenter::FAILED ? 'প্রদানক ব্যর্থ' : '',
                'amount' => $refunded ? number_format(abs((float) $row['amount']), 2) : '',
            ],
            '/service-history',
            ['reference' => (string) $row['reference'], 'tx_id' => (int) $row['id']],
        );
    }

    /** The name of whoever holds the claim, for the "somebody else has it" message. */
    private function claimerName(array $row): ?string
    {
        $claimedBy = $row['claimed_by'] ?? null;
        if ($claimedBy === null) {
            return null;
        }
        $user = $this->users->findById((int) $claimedBy);

        return $user === null ? null : (string) $user['username'];
    }

    private function serviceName(array $row): string
    {
        $metadata = ServiceOrderRepository::metadata($row);

        return (string) ($row['service_name'] ?? ($metadata['service_name'] ?? 'সার্ভিস'));
    }

    private function log(
        Identity $admin,
        int $id,
        string $reference,
        string $action,
        string $description,
    ): void {
        $this->logs->create([
            'user_id' => $admin->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'metadata' => ['order' => $reference, 'order_id' => $id],
        ]);
    }
}
