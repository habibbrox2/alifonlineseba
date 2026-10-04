<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ServiceOrderRepository;

/**
 * Builds the single row payload used by every surface that shows a service
 * request: the server-rendered history page, the AJAX status action, and the
 * live poller.
 *
 * Why one class rather than a `decorate()` on each action: the row is a small
 * contract with the Alpine component, and there are now three server-side
 * producers of it. The moment a fourth field is added to one of them — a
 * deliverable flag, say — a per-action copy would render the page one way and
 * update it another way *for the same row*, which is precisely the bug this
 * feature exists to avoid. The client asserts on a shape it always receives.
 */
final class RequestRowPresenter
{
    /**
     * @param array<string, mixed> $row a raw `service_order` row (joined with `service`).
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $metadata = ServiceOrderRepository::metadata($row);
        $status = (string) $row['status'];
        $result = is_array($metadata['result'] ?? null) ? $metadata['result'] : null;

        // A deliverable only counts if the row points at one. The file's actual
        // presence on disk is deliberately not consulted here: this runs once
        // per row per poll, and a `realpath()` per stat is a filesystem call we
        // would be making 15 times every few seconds. The download action is a
        // link, so a pruned file produces a 404 page rather than a broken UI,
        // and the download endpoint re-checks existence before serving bytes.
        $hasDeliverable = ($row['deliverable_path'] ?? null) !== null
            && (string) $row['deliverable_path'] !== '';

        return [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'service_name' => (string) ($row['service_name'] ?? ($metadata['service_name'] ?? 'সার্ভিস')),
            'amount' => (float) $row['amount'],
            'status' => $status,
            'status_label' => StatusPresenter::label($status),
            'status_badge' => StatusPresenter::badge($status),
            'actions' => StatusPresenter::requestActions($status, $hasDeliverable),
            'has_deliverable' => $hasDeliverable,
            'deliverable_name' => $hasDeliverable ? (string) $row['deliverable_name'] : null,
            'deliverable_size' => $hasDeliverable && $row['deliverable_size'] !== null
                ? (int) $row['deliverable_size']
                : null,
            'deliverable_uploaded_at' => $hasDeliverable
                ? ($row['deliverable_uploaded_at'] !== null ? (string) $row['deliverable_uploaded_at'] : null)
                : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'result' => $result,
            'result_entries' => StatusPresenter::resultEntries($result),
            'error' => isset($metadata['error']) ? (string) $metadata['error'] : null,
        ];
    }

    /**
     * Present a whole set of rows, keyed by id.
     *
     * Keyed rather than a plain list because the poller receives ids and has to
     * match answers back to individual Alpine components; a list would make it
     * re-derive that mapping on every tick.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $rows): array
    {
        $presented = [];
        foreach ($rows as $row) {
            $presented[] = $this->present($row);
        }

        return array_column($presented, null, 'id');
    }
}
