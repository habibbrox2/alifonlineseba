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
