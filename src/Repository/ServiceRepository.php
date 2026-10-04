<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class ServiceRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    // ---- Categories -------------------------------------------------------

    public function findCategoryBySlug(string $slug): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%service_category}} WHERE [[slug]] = :slug LIMIT 1')
            ->bindValue(':slug', $slug)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function allCategories(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM {{%service_category}}';
        if ($activeOnly) {
            $sql .= " WHERE [[status]] = 'active'";
        }
        $sql .= ' ORDER BY [[sort_order]] ASC, [[id]] ASC';
        return $this->db->createCommand($sql)->queryAll();
    }

    public function createCategory(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%service_category}}', [
            'name' => $row['name'],
            'slug' => $row['slug'],
            'icon' => $row['icon'] ?? null,
            'description' => $row['description'] ?? null,
            'accent' => $row['accent'] ?? 'auto',
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'status' => $row['status'] ?? 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function updateCategory(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%service_category}}', $values, ['id' => $id])->execute();
    }

    public function deleteCategory(int $id): void
    {
        $this->db->createCommand()->delete('{{%service_category}}', ['id' => $id])->execute();
    }

    // ---- Services ---------------------------------------------------------

    /**
     * Look a service up by slug. Trashed services are hidden unless
     * $withDeleted is set — the admin slug check needs to see them so a new
     * service cannot steal a slug that a restorable service still holds.
     */
    public function findServiceBySlug(string $slug, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM {{%service}} WHERE [[slug]] = :slug';
        if (!$withDeleted) {
            $sql .= ' AND [[deleted_at]] IS NULL';
        }
        $row = $this->db
            ->createCommand($sql . ' LIMIT 1')
            ->bindValue(':slug', $slug)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function findCategoryById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%service_category}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function findServiceById(int $id, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM {{%service}} WHERE [[id]] = :id';
        if (!$withDeleted) {
            $sql .= ' AND [[deleted_at]] IS NULL';
        }
        $row = $this->db
            ->createCommand($sql . ' LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function servicesByCategory(?int $categoryId = null): array
    {
        if ($categoryId === null) {
            return $this->db
                ->createCommand("SELECT * FROM {{%service}} WHERE [[status]] = 'active' AND [[deleted_at]] IS NULL ORDER BY [[sort_order]] ASC, [[id]] ASC")
                ->queryAll();
        }
        return $this->db
            ->createCommand("SELECT * FROM {{%service}} WHERE [[category_id]] = :cid AND [[status]] = 'active' AND [[deleted_at]] IS NULL ORDER BY [[sort_order]] ASC, [[id]] ASC")
            ->bindValue(':cid', $categoryId)
            ->queryAll();
    }

    /**
     * Every service regardless of status, trashed ones included — for the admin
     * list, which shows both so deleted rows can be restored.
     */
    public function allServicesAdmin(): array
    {
        return $this->db
            ->createCommand('SELECT * FROM {{%service}} ORDER BY [[deleted_at]] IS NOT NULL ASC, [[sort_order]] ASC, [[id]] ASC')
            ->queryAll();
    }

    /**
     * Trash filter values, named to match `UserRepository`'s.
     *
     * The two admin lists answer the same question — where are the deleted rows
     * — and a shared vocabulary is what lets them share a chip bar without one
     * of them quietly inventing its own third spelling of "trash".
     */
    public const DELETED_EXCLUDE = 'exclude';
    public const DELETED_ONLY = 'only';
    public const DELETED_ALL = 'all';

    /** Status values the admin filter accepts. Anything else is dropped. */
    public const STATUSES = ['active', 'inactive'];

    /**
     * The admin list under a filter, plus how many rows the filter matches.
     *
     * `total` is the match count rather than `count($rows)` on purpose: the
     * bulk bar offers "select all matching this filter", and a capped caller
     * has to be able to say that the cap hid something. With `$limit` at zero —
     * the view — the two are the same number and the distinction costs nothing.
     *
     * The ordering is `allServicesAdmin()`'s, unchanged, so turning a filter on
     * never reshuffles the list under the admin's cursor.
     *
     * @param array{q?: mixed, category?: mixed, status?: mixed, trashed?: mixed} $filter
     * @return array{rows: list<array>, ids: int[], total: int}
     */
    public function adminFiltered(array $filter, int $limit = 0): array
    {
        [$where, $params] = $this->adminFilterClause($filter);

        $total = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}}' . $where)
            ->bindValues($params)
            ->queryScalar();

        $sql = 'SELECT * FROM {{%service}}' . $where
            . ' ORDER BY [[deleted_at]] IS NOT NULL ASC, [[sort_order]] ASC, [[id]] ASC';
        if ($limit > 0) {
            // intval()'d: the limit is interpolated rather than bound because
            // MySQL will not accept a placeholder there, and it is an int from
            // a constant, not from a request.
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $rows = $this->db->createCommand($sql)->bindValues($params)->queryAll();

        return [
            'rows' => $rows,
            'ids' => array_map('intval', array_column($rows, 'id')),
            'total' => $total,
        ];
    }

    /**
     * WHERE fragment and bindings for the admin filter.
     *
     * One method because the filter has two callers — the list and the bulk
     * bar's "everything matching" scope — and a bar that resolved a different
     * set than the list it was drawn on would be a bar that acts on rows the
     * admin cannot see.
     *
     * @param array<string, mixed> $filter
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminFilterClause(array $filter): array
    {
        $conditions = [];
        $params = [];

        $trashed = (string) ($filter['trashed'] ?? self::DELETED_EXCLUDE);
        if ($trashed === self::DELETED_ONLY) {
            $conditions[] = '[[deleted_at]] IS NOT NULL';
        } elseif ($trashed !== self::DELETED_ALL) {
            $conditions[] = '[[deleted_at]] IS NULL';
        }

        $categoryId = (int) ($filter['category'] ?? 0);
        if ($categoryId > 0) {
            $conditions[] = '[[category_id]] = :cid';
            $params[':cid'] = $categoryId;
        }

        $status = (string) ($filter['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $conditions[] = '[[status]] = :status';
            $params[':status'] = $status;
        }

        $term = trim((string) ($filter['q'] ?? ''));
        if ($term !== '') {
            // Name and slug, not description: the admin is looking for one
            // service by what it is called, and description is free text in
            // which any common word matches half the table.
            $conditions[] = '([[name]] LIKE :q OR [[slug]] LIKE :q)';
            $params[':q'] = "%{$term}%";
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    public function searchServices(string $term, ?int $categoryId = null): array
    {
        $sql = "SELECT * FROM {{%service}} WHERE [[status]] = 'active' AND [[deleted_at]] IS NULL AND ([[name]] LIKE :t OR [[description]] LIKE :t)";
        $params = [':t' => "%{$term}%"];
        if ($categoryId !== null) {
            $sql .= ' AND [[category_id]] = :cid';
            $params[':cid'] = $categoryId;
        }
        $sql .= ' ORDER BY [[sort_order]] ASC, [[id]] ASC';
        return $this->db->createCommand($sql)->bindValues($params)->queryAll();
    }

    public function createService(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%service}}', [
            'category_id' => (int) $row['category_id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'] ?? null,
            'icon' => $row['icon'] ?? null,
            'badge' => $row['badge'] ?? null,
            'service_type' => $row['service_type'] ?? 'mock',
            'form_fields' => isset($row['form_fields']) && $row['form_fields'] !== null
                ? (is_string($row['form_fields']) ? $row['form_fields'] : json_encode($row['form_fields'], JSON_THROW_ON_ERROR))
                : null,
            'price' => $row['price'] ?? 0,
            'variants' => isset($row['variants']) && $row['variants'] !== null
                ? (is_string($row['variants']) ? $row['variants'] : json_encode($row['variants'], JSON_THROW_ON_ERROR))
                : null,
            'rules' => $row['rules'] ?? null,
            'status' => $row['status'] ?? 'active',
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /**
     * Store the per-service purchasable variants (JSON list of {label, price})
     * and ordering rules/instructions text. Pass null for either to clear it.
     */
    public function updateVariantsAndRules(int $id, ?array $variants, ?string $rules): void
    {
        $this->db->createCommand()->update('{{%service}}', [
            'variants' => $variants === null ? null : json_encode($variants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'rules' => $rules === null ? null : $rules,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();
    }

    public function updateService(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%service}}', $values, ['id' => $id])->execute();
    }

    /**
     * Store the per-service form field configuration as a JSON list of
     * {name, required} objects. Pass null to fall back to provider defaults.
     */
    public function updateFormFields(int $id, ?array $fields): void
    {
        $this->db->createCommand()->update(
            '{{%service}}',
            [
                'form_fields' => $fields === null ? null : json_encode($fields, JSON_THROW_ON_ERROR),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            ['id' => $id],
        )->execute();
    }

    // ---- Soft delete / restore -------------------------------------------

    /**
     * Move a service to the trash. The row stays, so its slug reservation and
     * transaction history survive a restore. Returns false if it was already
     * trashed or does not exist.
     */
    public function softDelete(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%service}}',
                ['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'deleted_at' => null],
            )
            ->execute() > 0;
    }

    /**
     * Bring a trashed service back. Returns false if it was not trashed.
     *
     * The condition is a raw SQL string on purpose: the array condition
     * ['deleted_at' => ['<>', null]] does NOT compile to IS NOT NULL, it
     * expands to "deleted_at = '<>' OR deleted_at IS NULL".
     */
    public function restore(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%service}}',
                ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')],
                '[[id]] = :id AND [[deleted_at]] IS NOT NULL',
            )
            ->bindValue(':id', $id)
            ->execute() > 0;
    }

    /** Empty the trash. Returns how many services were restored. */
    public function restoreAll(): int
    {
        return $this->db
            ->createCommand()
            ->update('{{%service}}', ['deleted_at' => null], '[[deleted_at]] IS NOT NULL')
            ->execute();
    }

    public function countTrashed(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[deleted_at]] IS NOT NULL')
            ->queryScalar();
    }

    /**
     * Ids of the orders attached to a service — the rows `purgeService()` is
     * about to detach.
     *
     * Read *before* the purge, not reconstructed after it: once `service_id` is
     * NULL there is nothing left to ask.
     *
     * @return int[]
     */
    public function orderIdsFor(int $serviceId): array
    {
        $ids = $this->db
            ->createCommand('SELECT [[id]] FROM {{%service_order}} WHERE [[service_id]] = :id ORDER BY [[id]] ASC')
            ->bindValue(':id', $serviceId)
            ->queryColumn();
        return array_map('intval', $ids);
    }

    /** How many orders reference this service. */
    public function orderCount(int $serviceId): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service_order}} WHERE [[service_id]] = :id')
            ->bindValue(':id', $serviceId)
            ->queryScalar();
    }

    /**
     * Permanently remove an already-trashed service, preserving order history
     * (FK-safe): the order rows are kept by detaching them (service_id → NULL),
     * because an order is a financial record as well as a queue entry — losing
     * it would break the ledger rows that point at it.
     *
     * The ledger's own legacy `service_id` column is detached too, even though
     * nothing writes it any more. Its foreign key still exists, and a single
     * row written before the split would otherwise refuse the DELETE and make
     * a purge permanently impossible on a live database.
     *
     * Returns how many orders were kept.
     */
    public function purgeService(int $id): int
    {
        $service = $this->findServiceById($id, true);
        if ($service === null || $service['deleted_at'] === null) {
            return 0;
        }

        $kept = $this->orderCount($id);

        $this->db->createCommand()
            ->update('{{%service_order}}', ['service_id' => null], ['service_id' => $id])
            ->execute();
        $this->db->createCommand()
            ->update('{{%transaction}}', ['service_id' => null], ['service_id' => $id])
            ->execute();
        $this->db->createCommand()->delete('{{%service}}', ['id' => $id])->execute();

        return $kept;
    }

    /**
     * Put a purged service back, exactly as it was.
     *
     * A purge is a DELETE, so undoing one means re-inserting the row *and*
     * re-attaching the orders `purgeService()` detached. The second half
     * is the part that is easy to leave out, and leaving it out produces a
     * service that looks restored and is not: it is back on the list with its
     * whole order history orphaned under a NULL `service_id`, which reads as
     * data loss that "undo" was supposed to have prevented.
     *
     * Both halves share one transaction, so a failure cannot commit the row
     * without the history.
     *
     * Columns are named one by one rather than taken from the row as given,
     * because the row travels back through the session to reach here and
     * session data is no more trusted than a POST body. A column this method
     * forgot would be silently dropped; an invented one would be a SQL error,
     * and the explicit list makes both failures loud during review.
     *
     * @param array<string, mixed> $row A row as `findServiceById()` returned it.
     * @param int[] $transactionIds Order ids to re-attach.
     * @return bool False when the id is taken again, so the caller reports
     *              "could not restore" rather than overwriting live data.
     */
    public function restorePurged(array $row, array $transactionIds = []): bool
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $values = [
            'id' => $id,
            'category_id' => (int) ($row['category_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => $row['description'] ?? null,
            'icon' => $row['icon'] ?? null,
            'badge' => $row['badge'] ?? null,
            'service_type' => (string) ($row['service_type'] ?? 'mock'),
            // Cast to string: a decimal(10,2) column round-tripped through the
            // session as a float can come back as 1.0E-5, which MySQL rejects.
            'price' => (string) ($row['price'] ?? '0'),
            'status' => (string) ($row['status'] ?? 'active'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?: $now),
            'updated_at' => (string) ($row['updated_at'] ?: $now),
            'form_fields' => $row['form_fields'] ?? null,
            // Kept as it was: a purged service comes back into the trash, not
            // onto the site. Undo should undo, not publish.
            'deleted_at' => $row['deleted_at'] ?? null,
            'variants' => $row['variants'] ?? null,
            'rules' => $row['rules'] ?? null,
        ];

        $attach = array_values(array_unique(array_filter(
            array_map('intval', $transactionIds),
            static fn (int $transactionId): bool => $transactionId > 0,
        )));

        return (bool) $this->db->transaction(function () use ($id, $values, $attach): bool {
            if ($this->findServiceById($id, true) !== null) {
                return false;
            }

            $this->db->createCommand()->insert('{{%service}}', $values)->execute();

            if ($attach !== []) {
                $placeholders = [];
                $params = [':sid' => $id];
                foreach ($attach as $i => $transactionId) {
                    $name = ':tid' . $i;
                    $placeholders[] = $name;
                    $params[$name] = $transactionId;
                }
                $this->db
                    ->createCommand(
                        'UPDATE {{%service_order}} SET [[service_id]] = :sid'
                        . ' WHERE [[id]] IN (' . implode(', ', $placeholders) . ')',
                    )
                    ->bindValues($params)
                    ->execute();
            }

            return true;
        });
    }
}
