<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * What a user typed into a provider-backed service form — one row per order.
 *
 * The data already exists in `service_order.metadata.input`, and deliberately
 * stays there: `ServiceManager::retry()` re-runs the provider from that
 * snapshot without the form being filled in a second time, so it is a record of
 * what was *submitted*, not a cache of the submission. This table is the same
 * answers in a shape that can be queried — `field_values` is JSON keyed by the
 * form field name, and `user_id` / `service_id` are indexed columns, so
 * "every nid-make form this customer filled in" is one read rather than a join
 * whose callers all have to remember to add the same filter.
 *
 * Why the values are JSON rather than a column per field: the field list comes
 * from a provider *and* from an admin's field configuration in /admin/services,
 * and both change without a deploy. A column-per-field table would need a
 * migration every time somebody ticked a box, which is why this is one JSON
 * column and not eleven.
 *
 * What lands here is exactly what passed `ServiceManager::pickConfiguredInput()`
 * — only fields the form actually showed, with image fields carrying the hashed
 * storage path that `ImageUploadStorage` returned, never a client filename.
 */
final class ServiceSubmissionRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Record (or re-record) an order's answers.
     *
     * An upsert rather than an insert because the submission is written again
     * whenever the form is resubmitted, and the UNIQUE index on
     * `service_order_id` is what stops a second row appearing: one order, one
     * submission, always the latest.
     *
     * @param array<string, mixed> $values keyed by form field name
     * @return int the submission id
     */
    public function save(
        int $orderId,
        int $userId,
        ?int $serviceId,
        string $provider,
        array $values,
    ): int {
        $now = date('Y-m-d H:i:s');
        $columns = [
            'service_order_id' => $orderId,
            'user_id' => $userId,
            'service_id' => $serviceId,
            'provider' => $provider,
            'field_values' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // The update list carries real values rather than bare column names: the
        // builder treats a name=>true pair as "set this column", which would
        // also overwrite `created_at` — and that column is the record of when
        // the customer *first* sent this form. Overwriting it would make
        // `created_at` and `updated_at` say the same thing and lose the only
        // way to tell a first submission from a correction.
        $this->db
            ->createCommand()
            ->upsert(
                '{{%service_submission}}',
                $columns,
                [
                    'user_id' => $columns['user_id'],
                    'service_id' => $columns['service_id'],
                    'provider' => $columns['provider'],
                    'field_values' => $columns['field_values'],
                    'updated_at' => $columns['updated_at'],
                ],
            )
            ->execute();

        return $this->idFor($orderId);
    }

    public function findByOrderId(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $row = $this->db
            ->createCommand('SELECT * FROM {{%service_submission}} WHERE [[service_order_id]] = :id LIMIT 1')
            ->bindValue(':id', $orderId)
            ->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * The answers for one order, decoded — or an empty array when the order
     * predates this table.
     *
     * Empty rather than throwing, because a missing submission is a normal
     * state for an order placed before the table existed, and every caller of
     * this is a read-only rendering path that has a sensible blank answer.
     */
    public function valuesFor(int $orderId): array
    {
        $row = $this->findByOrderId($orderId);

        return $row === null ? [] : self::values($row);
    }

    /**
     * Read the JSON column as an array keyed by field name.
     *
     * The same defensive shape as `ServiceOrderRepository::metadata()`: the
     * column is JSON but the row may still be hand-edited, and a submission
     * that has been corrupted must not take a page down with it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function values(array $row): array
    {
        $raw = $row['field_values'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * The submissions behind a set of order ids, keyed by order id.
     *
     * One query for the whole selection, because the history page asks about
     * fifteen rows at a time and asking once per row would be fifteen round
     * trips to learn fifteen things it could have been told once. An order with
     * no submission is simply absent — the caller treats the difference between
     * "absent" and "empty values" as the same thing, which it is.
     *
     * The ids come off a page that was rendered into the HTML, so they are
     * untrusted input by the time they get here: they are cast to int and the
     * placeholders are built from the cleaned list, so nothing left to inject.
     *
     * @param int[] $orderIds
     * @return array<int, array<string, mixed>> order id => row
     */
    public function findManyByOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params[':id' . $i] = $id;
        }

        $rows = $this->db
            ->createCommand(
                'SELECT * FROM {{%service_submission}}'
                . ' WHERE [[service_order_id]] IN (' . implode(', ', $placeholders) . ')',
            )
            ->bindValues($params)
            ->queryAll();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['service_order_id']] = $row;
        }

        return $keyed;
    }

    private function idFor(int $orderId): int
    {
        $value = $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_submission}} WHERE [[service_order_id]] = :id LIMIT 1')
            ->bindValue(':id', $orderId)
            ->queryScalar();

        return (int) $value;
    }
}