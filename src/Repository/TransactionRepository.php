<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class TransactionRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%transaction}}', [
            'user_id' => (int) $row['user_id'],
            'service_id' => isset($row['service_id']) ? (int) $row['service_id'] : null,
            'reference' => $row['reference'],
            'amount' => $row['amount'],
            'status' => $row['status'] ?? 'pending',
            'metadata' => isset($row['metadata']) ? json_encode($row['metadata'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%transaction}}', $values, ['id' => $id])->execute();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%transaction}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    /** A request the given user owns, or null — the ownership check for every status action. */
    public function findOwned(int $id, int $userId): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%transaction}} WHERE [[id]] = :id AND [[user_id]] = :uid LIMIT 1')
            ->bindValues([':id' => $id, ':uid' => $userId])
            ->queryOne();
        return $row === false ? null : $row;
    }

    /**
     * Read the JSON metadata column as an array.
     *
     * @return array<string, mixed>
     */
    public static function metadata(array $row): array
    {
        $raw = $row['metadata'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * Attach a deliverable file to a service request, replacing any previous one.
     *
     * The path is a plain column rather than an entry in `metadata` because it
     * is queried, not just displayed: the admin queue lists "has file / no file"
     * and the history page polls for the moment it flips. JSON in a metadata blob
     * cannot be indexed or filtered without a full scan of every request.
     *
     * The caller must delete the *previous* file from disk itself — this method
     * only touches the database and has no idea where the bytes live. A
     * deliberate contract, so a failed upload can never destroy the deliverable
     * that is already there.
     *
     * @param array{path: string, name: string, mime: string, size: int} $file
     * @param int $adminId who uploaded it, for audit.
     */
    public function attachDeliverable(int $id, array $file, int $adminId): void
    {
        $this->update($id, [
            'deliverable_path' => $file['path'],
            'deliverable_name' => $file['name'],
            'deliverable_mime' => $file['mime'],
            'deliverable_size' => (int) $file['size'],
            'deliverable_uploaded_at' => date('Y-m-d H:i:s'),
            'deliverable_uploaded_by' => $adminId,
        ]);
    }

    /**
     * Clear a request's deliverable columns and hand back the row as it was, so
     * the caller can delete the now-orphaned file.
     *
     * Clearing is a real operation, not a status: an admin can attach a file to
     * a request and then decide it was the wrong scan, while the request is
     * still `processing`.
     *
     * @return array<string, mixed>|null the previous row, or null when there was nothing to clear.
     */
    public function detachDeliverable(int $id): ?array
    {
        $before = $this->findById($id);
        if ($before === null || ($before['deliverable_path'] ?? null) === null) {
            return null;
        }

        $this->update($id, [
            'deliverable_path' => null,
            'deliverable_name' => null,
            'deliverable_mime' => null,
            'deliverable_size' => null,
            'deliverable_uploaded_at' => null,
            'deliverable_uploaded_by' => null,
        ]);

        return $before;
    }

    /**
     * The rows behind a set of ids, for one user, in the order asked for.
     *
     * This is what makes the history page's live update a *single* request per
     * tick instead of fifteen: a page shows up to `PER_PAGE` requests, each of
     * which is its own Alpine component, and one endpoint per row would mean a
     * burst of near-identical queries every few seconds.
     *
     * Scoped by `user_id` in the WHERE clause, not filtered afterwards — same
     * reason as `findOwned()`: a request belonging to somebody else must be
     * invisible, not merely discarded, or the endpoint becomes a way to confirm
     * that an id exists.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>> keyed by id, absent ids omitted.
     */
    public function watchForUser(array $ids, int $userId): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        // The list is rendered into the HTML and echoed back by the client, so it
        // is untrusted input by the time it gets here. Only integers survive the
        // cast above and the placeholders are built from the cleaned list, so
        // there is nothing left to inject.
        $placeholders = [];
        $params = [':uid' => $userId];
        foreach ($ids as $index => $id) {
            $key = ':id' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $rows = $this->db
            ->createCommand(
                'SELECT {{%transaction}}.*, {{%service}}.[[name]] AS service_name'
                . ' FROM {{%transaction}}'
                . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%transaction}}.[[service_id]]'
                . ' WHERE {{%transaction}}.[[user_id]] = :uid'
                . ' AND {{%transaction}}.[[id]] IN (' . implode(', ', $placeholders) . ')'
            )
            ->bindValues($params)
            ->queryAll();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['id']] = $row;
        }

        return $keyed;
    }

    /** Move a request to a new status, merging (not replacing) its metadata. */
    public function setStatus(int $id, string $status, array $metadata = []): void
    {
        $values = ['status' => $status];
        if ($metadata !== []) {
            $existing = self::metadata($this->findById($id) ?? []);
            $values['metadata'] = json_encode($metadata + $existing, JSON_UNESCAPED_UNICODE);
        }
        $this->update($id, $values);
    }

    public function forUser(int $userId, int $page, int $perPage, string $status = ''): array
    {
        $where = '{{%transaction}}.[[user_id]] = :uid';
        $params = [':uid' => $userId];
        if ($status !== '') {
            $where .= ' AND {{%transaction}}.[[status]] = :st';
            $params[':st'] = $status;
        }
        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /** Whitelisted sortable columns => SQL column expression. */
    public const SORTABLE = [
        'id' => '{{%transaction}}.[[id]]',
        'reference' => '{{%transaction}}.[[reference]]',
        'amount' => '{{%transaction}}.[[amount]]',
        'status' => '{{%transaction}}.[[status]]',
        'created_at' => '{{%transaction}}.[[created_at]]',
        'username' => '{{%user}}.[[username]]',
    ];

    public function all(int $page, int $perPage, string $status = '', string $q = '', string $sort = 'id', string $dir = 'desc'): array
    {
        $where = '1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND {{%transaction}}.[[status]] = :st';
            $params[':st'] = $status;
        }
        if ($q !== '') {
            $where .= ' AND ({{%transaction}}.[[reference]] LIKE :q OR {{%user}}.[[username]] LIKE :q OR {{%user}}.[[phone]] LIKE :q)';
            $params[':q'] = "%{$q}%";
        }
        return $this->pagedJoin($where, $params, $page, $perPage, $sort, $dir);
    }

    private function pagedJoin(string $where, array $params, int $page, int $perPage, string $sort = 'id', string $dir = 'desc'): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%transaction}} LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%transaction}}.[[user_id]] WHERE ' . $where
            )
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                'SELECT {{%transaction}}.*, {{%service}}.[[name]] AS service_name, {{%user}}.[[username]], {{%user}}.[[phone]]'
                . ' FROM {{%transaction}}'
                . ' LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%transaction}}.[[user_id]]'
                . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%transaction}}.[[service_id]]'
                . ' WHERE ' . $where
                . ' ORDER BY ' . $this->sortClause($sort, $dir)
                . " LIMIT {$perPage} OFFSET {$offset}"
            )
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The dashboard's "recent searches" panel.
     *
     * A "search" here is a service request — that is the only thing in this
     * product that carries a query, and it already stores the submitted input in
     * its metadata, so there is no second table to keep in sync and nothing to
     * log on a path that might not run.
     *
     * Each row comes back ready for the template: the service slug (so the
     * re-run button can link straight back to the form), the label of the field
     * the user actually typed into, and the value itself. Top-ups have no
     * service and are filtered out — a recharge is not a search.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentSearches(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        $rows = $this->db
            ->createCommand(
                'SELECT {{%transaction}}.[[id]], {{%transaction}}.[[reference]],'
                . ' {{%transaction}}.[[status]], {{%transaction}}.[[created_at]],'
                . ' {{%transaction}}.[[metadata]], {{%service}}.[[name]] AS service_name,'
                . ' {{%service}}.[[slug]] AS service_slug'
                . ' FROM {{%transaction}}'
                . ' INNER JOIN {{%service}} ON {{%service}}.[[id]] = {{%transaction}}.[[service_id]]'
                . ' WHERE {{%transaction}}.[[user_id]] = :uid'
                . ' AND {{%service}}.[[deleted_at]] IS NULL'
                . ' ORDER BY {{%transaction}}.[[id]] DESC'
                . " LIMIT {$limit}"
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        $searches = [];
        foreach ($rows as $row) {
            $metadata = self::metadata($row);
            $input = is_array($metadata['input'] ?? null) ? $metadata['input'] : [];

            // The first configured field is the one users think of as "the
            // search" (NID, mobile, username…). Fall back to whatever is there
            // so a request made with an unusual form still renders a value.
            $label = null;
            $value = null;
            foreach ($input as $key => $raw) {
                $value = (string) $raw;
                if ($value !== '') {
                    $label = (string) $key;
                    break;
                }
            }
            if ($value === null || $value === '') {
                continue; // Nothing to re-run; skip rather than show a dead row.
            }

            $searches[] = [
                'id' => (int) $row['id'],
                'reference' => (string) $row['reference'],
                'service_name' => (string) ($row['service_name'] ?? 'সার্ভিস'),
                'service_slug' => (string) ($row['service_slug'] ?? ''),
                'field' => (string) $label,
                'value' => $value,
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $searches;
    }

    /**
     * Build a safe "expr dir" ORDER BY fragment from the SORTABLE whitelist.
     */
    private function sortClause(string $sort, string $dir): string
    {
        $col = self::SORTABLE[$sort] ?? self::SORTABLE['id'];
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return "{$col} {$direction}";
    }

    /**
     * The user's requests grouped by the *service category* the ordered
     * service belongs to — the order-type chips on the history page
     * (ফুল NID / লোকেশন / বায়োমেট্রিক / …). Top-ups have no service, so they
     * land under `null` and are excluded; the "all" chip counts from statusCounts().
     *
     * @return array<string, int> slug => count
     */
    public function categoryCounts(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT {{%service_category}}.[[slug]] AS slug, COUNT(*) AS c'
                . ' FROM {{%transaction}}'
                . ' JOIN {{%service}} ON {{%service}}.[[id]] = {{%transaction}}.[[service_id]]'
                . ' JOIN {{%service_category}} ON {{%service_category}}.[[id]] = {{%service}}.[[category_id]]'
                . ' WHERE {{%transaction}}.[[user_id]] = :uid'
                . ' GROUP BY {{%service_category}}.[[slug]]'
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['slug']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Filter a user's requests by service-category slug (order-type chip).
     */
    public function forUserByCategory(int $userId, int $page, int $perPage, string $categorySlug): array
    {
        $where = '{{%transaction}}.[[user_id]] = :uid'
            . ' AND {{%service_category}}.[[slug]] = :slug';
        $params = [':uid' => $userId, ':slug' => $categorySlug];
        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /** The user's orders of ONE service (the per-service table under the order form). */
    public function forUserByService(int $userId, int $serviceId, int $page = 1, int $perPage = 5): array
    {
        $where = '{{%transaction}}.[[user_id]] = :uid'
            . ' AND {{%transaction}}.[[service_id]] = :sid';
        $params = [':uid' => $userId, ':sid' => $serviceId];
        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /**
     * How many requests the user has in each status, keyed by status.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[status]], COUNT(*) AS [[c]] FROM {{%transaction}}'
                . ' WHERE [[user_id]] = :uid GROUP BY [[status]]'
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    public function statsForUser(int $userId): array
    {
        return $this->stats('[[user_id]] = :uid', [':uid' => $userId]);
    }

    /** Completed-request count for the dashboard "সফল অনুসন্ধান" card. */
    public function completedCount(int $userId): int
    {
        $value = $this->db
            ->createCommand(
                "SELECT COUNT(*) FROM {{%transaction}} WHERE [[user_id]] = :uid AND [[status]] = 'completed'"
            )
            ->bindValue(':uid', $userId)
            ->queryScalar();

        return (int) $value;
    }

    public function statsAll(): array
    {
        return $this->stats('1=1', []);
    }

    private function stats(string $where, array $params): array
    {
        $row = $this->db
            ->createCommand(
                "SELECT COUNT(*) AS total, COALESCE(SUM([[amount]]),0) AS amount FROM {{%transaction}} WHERE {$where}"
            )
            ->bindValues($params)
            ->queryOne();
        return [
            'total' => (int) ($row['total'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }
}
