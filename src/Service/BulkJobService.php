<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Repository\BulkJobRepository;

/**
 * The bulk-action bar's job queue: `enqueueSettle()` is all the web request
 * does, `run()` is all the worker does, and nothing else in the system knows a
 * batch is a batch.
 *
 * Why the work left the request in the first place. Settling a batch used to
 * happen inside the POST that submitted it — one request walking up to a
 * hundred rows, each with its own refund, notification and activity log. Two
 * things go wrong with that, and neither is the database being slow: a web
 * server will cut the request off at its own timeout (the operator gets a 504
 * and no way to tell which half of the batch moved), and a single slow row
 * holds the whole connection for everyone behind it. The request now writes a
 * row and returns; the cron worker does the walking.
 *
 * Why chunking rather than one long transaction is what makes the result
 * legible. Each chunk is a bounded unit of work that checkpoints its cursor, so
 * the queue page can say "৬০/১০০" instead of a spinner, and a worker that dies
 * mid-batch resumes from the cursor rather than starting over. Resuming is safe
 * rather than merely lucky: settling an order that is already in the target
 * status is a counted no-op inside `settleMany()`, so a chunk whose rows moved
 * but whose checkpoint did not land is re-run as "already there" and never
 * paid, refunded or announced twice.
 */
final class BulkJobService
{
    /**
     * Rows per chunk.
     *
     * Small enough that a chunk finishes well inside any request budget even
     * when each row fans out to a refund, a notification and a log write, and
     * large enough that the per-chunk bookkeeping is noise. Overridable because
     * the right answer depends on the row's own cost more than on any constant.
     */
    public const DEFAULT_CHUNK_SIZE = 25;

    public function __construct(
        private readonly BulkJobRepository $jobs,
        private readonly ServiceRequestAdminService $settle,
    ) {}

    /**
     * Take a batch off the operator's hands.
     *
     * Refuses exactly what the inline settle refused, and with the same words,
     * so the submission that used to be rejected at the first row is still
     * rejected at the door — an unknown status or an empty selection must not
     * become a queued job that a worker picks up minutes later.
     *
     * @param array<int|string, mixed> $ids
     *
     * @return array{ok: bool, message: string, jobId: int}
     */
    public function enqueueSettle(array $ids, string $status, Identity $admin): array
    {
        if (!StatusPresenter::isRequestStatus($status)) {
            return ['ok' => false, 'message' => 'অজানা অবস্থা।', 'jobId' => 0];
        }

        // The same normalisation the settle itself does — strip, drop the
        // non-positive leftovers, de-duplicate, cap — so what gets queued is
        // exactly what a direct settle would have acted on.
        $ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);
        $ids = array_slice(array_values(array_unique($ids)), 0, ServiceRequestAdminService::BULK_LIMIT);

        if ($ids === []) {
            return ['ok' => false, 'message' => 'কোনো অর্ডার নির্বাচন করা হয়নি।', 'jobId' => 0];
        }

        $jobId = $this->jobs->enqueue(
            'settle_status',
            ['ids' => $ids, 'status' => $status],
            $admin->id,
            $admin->username,
            count($ids),
            self::chunkSize(),
        );

        return [
            'ok' => true,
            'jobId' => $jobId,
            // Named as a queue, not as a result: the operator is told to watch
            // the panel rather than shown a summary that does not exist yet.
            'message' => sprintf(
                '%d টি অর্ডার কাজের সারিতে যোগ হয়েছে — ফলাফল নিচে দেখুন।',
                count($ids),
            ),
        ];
    }

    /**
     * Work one claimed job to completion, a chunk at a time.
     *
     * Returns the message now on the job row, for the worker's own summary.
     *
     * @param array<string, mixed> $job A row this worker has claimed.
     */
    public function run(array $job): string
    {
        $id = (int) $job['id'];
        $payload = json_decode((string) ($job['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];

        $ids = array_values(array_filter(array_map('intval', (array) ($payload['ids'] ?? []))));
        $status = (string) ($payload['status'] ?? '');

        // The enqueue path validates this, so reaching here means the row was
        // written by something other than it. Fail it loudly rather than let a
        // worker settle nothing on a loop.
        if (!StatusPresenter::isRequestStatus($status)) {
            $message = 'কাজের অবস্থা অবৈধ, তাই চালানো হয়নি।';
            $this->jobs->markFailed($id, $message);

            return $message;
        }

        $total = count($ids);
        if ($total === 0) {
            $message = 'কাজের তালিকা খালি।';
            $this->jobs->markFailed($id, $message);

            return $message;
        }

        $cursor = max(0, min((int) $job['cursor'], $total));
        $applied = (int) $job['applied'];
        $unchanged = (int) $job['unchanged'];
        $skipped = (int) $job['skipped'];
        $chunkSize = max(1, (int) $job['chunk_size']);
        $attempts = (int) $job['attempts'];
        $admin = $this->requester($job);

        while ($cursor < $total) {
            $chunk = array_slice($ids, $cursor, $chunkSize);
            if ($chunk === []) {
                break;
            }

            // The same work, on the same authority, as settling by hand: every
            // row inside a chunk still goes through setStatus() with its refund
            // guard, its notification and its activity log.
            $result = $this->settle->settleMany($chunk, $status, $admin);

            $applied += $result['applied'];
            $unchanged += $result['unchanged'];
            $skipped += $result['skipped'];
            $cursor += count($chunk);

            $this->jobs->saveProgress(
                $id,
                $cursor,
                $cursor,
                $applied,
                $unchanged,
                $skipped,
                (int) round($cursor / $total * 100),
                sprintf('%d/%d', $cursor, $total),
            );

            // If the row is no longer ours — reclaimed by another tick, or the
            // job was retried by hand — stop here. Marking it done would be a
            // lie about work the other worker is still doing.
            $current = $this->jobs->findById($id);
            if ($current === null || (string) $current['status'] !== 'processing' || (int) $current['attempts'] !== $attempts) {
                return 'কাজটি অন্য কর্মীর কাছে চলে গেছে।';
            }
        }

        $message = $this->summary($applied, $unchanged, $skipped, $status);
        $this->jobs->markDone($id, $message);

        return $message;
    }

    /**
     * The identity the job runs under.
     *
     * The session that submitted it is long gone by the time a worker runs, so
     * the authority is rebuilt from the requester recorded on the row. Only the
     * id reaches the activity log — the rest is filled in so the object is an
     * honest `Identity` rather than a half-built one something else may later
     * read a phone number from.
     */
    private function requester(array $job): Identity
    {
        return new Identity(
            (int) ($job['requested_by'] ?? 0),
            (string) ($job['requested_by_name'] ?? 'admin'),
            '',
            null,
            'admin',
            'active',
            0.0,
            null,
        );
    }

    /**
     * The finished batch in one line, worded like the inline result it
     * replaced so an operator reading either says the same thing.
     */
    private function summary(int $applied, int $unchanged, int $skipped, string $status): string
    {
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

        return $message;
    }

    public static function chunkSize(): int
    {
        return max(1, \App\Env::int('BULK_CHUNK_SIZE', self::DEFAULT_CHUNK_SIZE));
    }
}
