<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The admin side of a service request: settle its status and attach the
 * finished file.
 *
 * This exists because until now the *only* person who could move a service
 * request was the user who made it — there was no admin UI for it at all. That
 * is a strange gap: the user could start a request, but nobody but the user
 * could mark it completed or hand over the result. The two halves belong
 * together, so they live together here: the status is the decision, the file is
 * the evidence for it, and an admin uploading a result without settling the
 * status would leave the user staring at a "প্রসেসিং" badge with a file they
 * cannot see.
 *
 * Money is handled deliberately. The balance is debited when the request is
 * submitted and refunded when it fails or is cancelled, so an admin settling a
 * request is moving real money. `refundIfUnsettled()` only credits a request
 * that is still `pending` or `processing` — the two states where the money is
 * still held. Without that guard, an admin marking an already-cancelled request
 * "failed" would pay the user twice.
 */
final readonly class ServiceRequestAdminService
{
    /**
     * Statuses in which the charged amount is still held by the platform.
     *
     * `completed` has delivered, `failed`/`cancelled` have already refunded —
     * so those three are the ones a settle must never credit again.
     */
    private const UNSETTLED = [StatusPresenter::PENDING, StatusPresenter::PROCESSING];

    /**
     * Ceiling on one bulk submission.
     *
     * The queue page shows twenty rows, so a real selection can never exceed
     * that — the cap is here for the other kind of caller: a hand-written POST
     * carrying ten thousand ids, which would be ten thousand notifications and
     * ten thousand refund decisions driven by whoever wrote the request.
     */
    /**
     * Largest selection one submission may carry.
     *
     * Public because the queue and the CSV export are held to the same cap: a
     * limit enforced only on the settle path would let the two paths drift, and
     * the cap exists for the page size above them, not for this method.
     */
    public const BULK_LIMIT = 100;

    public function __construct(
        private TransactionRepository $transactions,
        private UserRepository $users,
        private DeliverableStorage $deliverables,
        private NotificationManager $notify,
        private ActivityLogRepository $logs,
    ) {}

    /**
     * Move a request to a new status, on an admin's authority.
     *
     * @return array{0: bool, 1: string} [ok, Bengali message]
     */
    public function setStatus(int $id, string $status, Identity $admin, string $note = ''): array
    {
        if (!StatusPresenter::isRequestStatus($status)) {
            return [false, 'অজানা অবস্থা।'];
        }

        $row = $this->transactions->findById($id);
        if ($row === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }

        $from = (string) $row['status'];
        if ($from === $status) {
            // Not an error, but nothing to do and nothing to announce — a
            // notification for a no-op would train users to ignore them.
            return [true, 'অবস্থা অপরিবর্তিত আছে।'];
        }

        $metadata = [];
        if ($note !== '') {
            $metadata['admin_note'] = mb_substr($note, 0, 500);
        }
        $this->transactions->setStatus($id, $status, $metadata);

        $refunded = $this->refundIfUnsettled($row, $from, $status);

        $this->announce($row, $status, $refunded);
        $this->log($admin, $id, (string) $row['reference'], 'service.admin_status', sprintf(
            'Admin set #%d to %s (was %s)%s',
            $id,
            $status,
            $from,
            $refunded ? ' — refunded' : '',
        ));

        return [true, 'অবস্থা হালনাগাদ হয়েছে: ' . StatusPresenter::label($status)];
    }

    /**
     * Settle many requests to one status, on an admin's authority.
     *
     * The queue is a queue: an operator working a backlog of a hundred orders
     * does the same thing to all of them, and making that a hundred page loads
     * is how a hundred become fifty. So the work is the *same* work — every row
     * goes through `setStatus()`, with its refund guard, its notification and
     * its activity log — and this only removes the clicking.
     *
     * The dangerous case is the one this refuses to be convenient about. A
     * top-up lives in the same table with a NULL `service_id` and is already
     * paid; running `refundIfUnsettled()` over one would credit the user money
     * that was never debited by this path. The bar is never drawn on the
     * "all transactions" tab, and this is the guard behind that decision — the
     * UI hides the path, the service makes it impossible, so a crafted POST
     * gets a skipped count instead of a payout.
     *
     * Rows already in the target status are counted, not settled: re-running
     * `setStatus()` on them would be the no-op it already handles, and skipping
     * here keeps the counts honest instead of reporting twenty changes that
     * were zero.
     *
     * `ok` is true only when something actually moved. The caller flashes it,
     * and "nothing happened" deserves a different colour from "done" — a silent
     * green bar after a submission that changed nothing is how an operator
     * concludes the queue is clear when it is not.
     *
     * @param int[] $ids
     *
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int}
     */
    public function settleMany(array $ids, string $status, Identity $admin): array
    {
        if (!StatusPresenter::isRequestStatus($status)) {
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

        $rows = $this->transactions->findManyByIds($ids);

        $applied = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            // Missing row, or a row that is a top-up rather than an order.
            if ($row === null || ($row['service_id'] ?? null) === null) {
                $skipped++;
                continue;
            }

            if ((string) $row['status'] === $status) {
                $unchanged++;
                continue;
            }

            [$ok] = $this->setStatus($id, $status, $admin);
            $ok ? $applied++ : $skipped++;
        }

        $message = sprintf(
            '%d টি অর্ডার হালনাগাদ হয়েছে: %s',
            $applied,
            StatusPresenter::label($status),
        );
        if ($unchanged > 0) {
            $message .= sprintf(' · %d টি আগেই এই অবস্থায় ছিল', $unchanged);
        }
        if ($skipped > 0) {
            $message .= sprintf(' · %d টি বাদ পড়েছে', $skipped);
        }

        if ($applied === 0) {
            $message = 'কোনো অর্ডারের অবস্থা বদলায়নি।';
            if ($unchanged > 0) {
                $message .= sprintf(' %d টি আগেই এই অবস্থায় ছিল।', $unchanged);
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
     * Store a result file against a request, replacing any previous one.
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
        $row = $this->transactions->findById($id);
        if ($row === null) {
            return [false, 'অনুরোধ পাওয়া যায়নি।'];
        }

        $previousPath = $row['deliverable_path'] ?? null;

        try {
            $stored = $this->deliverables->store($file, $id);
        } catch (\RuntimeException $e) {
            // The message is already user-facing and specific (size, format,
            // PHP upload error); the admin needs to know which of those it was.
            return [false, $e->getMessage()];
        }

        $this->transactions->attachDeliverable($id, $stored, $admin->id);

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
        $this->log($admin, $id, (string) $row['reference'], 'service.file_attached', sprintf(
            'File "%s" attached to #%d',
            $stored['name'],
            $id,
        ));

        return [true, 'ফাইলটি আপলোড হয়েছে। ইউজার এখন ডাউনলোড করতে পারবে।'];
    }

    /**
     * Remove a request's deliverable, from disk and from the row.
     *
     * Needed because an attached file is not automatically the right one: an
     * admin can upload the wrong scan, or a result that turned out to belong to
     * a different request. Deleting the bytes is the point — an orphaned ID
     * result that nothing in the app can reach is data about somebody that we
     * can no longer revoke.
     *
     * @return array{0: bool, 1: string}
     */
    public function detachDeliverable(int $id, Identity $admin): array
    {
        $before = $this->transactions->detachDeliverable($id);
        if ($before === null) {
            return [false, 'এই অনুরোধে কোনো ফাইল নেই।'];
        }

        $this->deliverables->delete($before['deliverable_path'] ?? null);
        $this->log($admin, $id, (string) $before['reference'], 'service.file_removed', sprintf(
            'File removed from #%d',
            $id,
        ));

        return [true, 'ফাইলটি মুছে ফেলা হয়েছে।'];
    }

    /**
     * Credit the user back when a settle takes a held request to a refunded
     * state. Returns whether a refund actually happened, for the log line.
     */
    private function refundIfUnsettled(array $row, string $from, string $to): bool
    {
        if (in_array($to, [StatusPresenter::FAILED, StatusPresenter::CANCELLED], true)
            && in_array($from, self::UNSETTLED, true)
        ) {
            $this->users->adjustBalance((int) $row['user_id'], abs((float) $row['amount']));
            return true;
        }

        return false;
    }

    /**
     * Tell the user their request moved.
     *
     * Only a *change* is announced — see the guard in `setStatus()`. `pending` is
     * excluded because the user submitted it themselves seconds ago and already
     * has the original confirmation; a second notification for the same event is
     * how users learn to ignore the bell.
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
                'amount' => $refunded ? number_format((float) $row['amount'], 2) : '',
            ],
            '/service-history',
            ['reference' => (string) $row['reference'], 'tx_id' => (int) $row['id']],
        );
    }

    private function serviceName(array $row): string
    {
        $metadata = TransactionRepository::metadata($row);

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
            'metadata' => ['tx' => $reference, 'tx_id' => $id],
        ]);
    }
}
