<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\StatusPresenter;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The service-order queue — one row per order request, and nothing else.
 *
 * This used to be `TransactionRepository`, back when a service order and a
 * balance movement were the same row. They are not the same thing: an order is
 * work somebody has to do and then decide on, and a ledger entry is money that
 * already moved. Sharing a table meant every query had to say `service_id IS
 * NOT NULL` to mean "orders", and the money history could not be shown without
 * a filter everyone had to remember.
 *
 * The order lifecycle is `pending` → `review` → a decision, where `review`
 * means a *named* admin has opened the row (see `claimOrder()`), and the
 * decision is one of completed / failed / cancelled. `approved_by` is the
 * marker for "the money has already gone to an admin's balance" and is written
 * exactly once, inside the same guarded UPDATE that moves the status — see
 * `ServiceOrderAdminService::approve()`.
 */
final class ServiceOrderRepository
{
    /**
     * Statuses in which the charged amount is still held by the platform.
     *
     * A `review` row holds money exactly as a `pending` one does, so every
     * "has the user's money still been paid out?" guard reads this list rather
     * than testing `status = 'pending'` — otherwise a claimed order would look
     * uncharged and be refunded on top of the original debit.
     */
    public const OPEN_STATUSES = [
        StatusPresenter::PENDING,
        'review',
        StatusPresenter::PROCESSING,
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%service_order}}', [
            'user_id' => (int) $row['user_id'],
            'service_id' => (int) $row['service_id'],
            'reference' => (string) $row['reference'],
            'amount' => (float) $row['amount'],
            'status' => (string) ($row['status'] ?? StatusPresenter::PENDING),
            'metadata' => isset($row['metadata'])
                ? json_encode($row['metadata'], JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%service_order}}', $values, ['id' => $id])->execute();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%service_order}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();

        return $row === false ? null : $row;
    }

    /** An order the given user owns, or null — the ownership check for every user-side action. */
    public function findOwned(int $id, int $userId): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT * FROM {{%service_order}} WHERE [[id]] = :id AND [[user_id]] = :uid LIMIT 1',
            )
            ->bindValues([':id' => $id, ':uid' => $userId])
            ->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * Every existing row behind a set of ids, in the order asked for.
     *
     * One query for the whole selection, because a bulk settle over a page of
     * twenty orders that called `findById()` twenty times is twenty round trips
     * to learn twenty things it could have been told once. Ids that do not
     * exist are simply absent from the result — the caller maps the difference
     * back to "skipped" rather than being handed a hole.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>> id => row
     */
    public function findManyByIds(array $ids): array
    {
        return $this->inClause($ids, '');
    }

    /**
     * The export view of a selection: the same rows `findManyByIds()` returns,
     * plus the two joined names a spreadsheet needs and cannot derive.
     *
     * Deliberately a second method rather than a widened `findManyByIds()`.
     * The join is the only difference, and the settle must not start carrying
     * it: a bulk settle is asking "what may I change about these rows", and
     * every extra joined column is another way for the two uses of the same
     * table to drift into disagreeing about what a row is.
     *
     * Both joins are LEFT because an order can outlive the row it points at —
     * an export of a deleted user's order should still export it, with a blank
     * name, rather than silently shortening the file.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>> id => row
     */
    public function findManyForExport(array $ids): array
    {
        return $this->inClause(
            $ids,
            ', {{%service}}.[[name]] AS service_name, {{%user}}.[[username]], {{%user}}.[[phone]]',
        );
    }

    /**
     * Shared id-list fetch. `$extraColumns` is the only thing that separates
     * the plain read from the export read.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function inClause(array $ids, string $extraColumns): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        // The placeholders are generated from the id count and numbered, so
        // nothing a client sends can reach the SQL text — and they have to be
        // distinct names, because two binds called `:id` would collapse into
        // one and quietly search for a single id.
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params[':id' . $i] = $id;
        }

        $rows = $this->db
            ->createCommand(
                'SELECT {{%service_order}}.*' . $extraColumns
                . ' FROM {{%service_order}}'
                . ($extraColumns === ''
                    ? ''
                    : ' LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%service_order}}.[[user_id]]'
                        . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%service_order}}.[[service_id]]')
                . ' WHERE {{%service_order}}.[[id]] IN (' . implode(', ', $placeholders) . ')',
            )
            ->bindValues($params)
            ->queryAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Read the JSON metadata column as an array.
     *
     * @param array<string, mixed> $row
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
     * Attach a deliverable file to an order, replacing any previous one.
     *
     * The path is a plain column rather than an entry in `metadata` because it
     * is queried, not just displayed: the admin queue lists "has file / no
     * file" and the history page polls for the moment it flips. JSON in a
     * metadata blob cannot be indexed or filtered without a full scan.
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
     * Clear an order's deliverable columns and hand back the row as it was, so
     * the caller can delete the now-orphaned file.
     *
     * Clearing is a real operation, not a status: an admin can attach a file to
     * an order and then decide it was the wrong scan, while the order is still
     * `processing`.
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
     * tick instead of fifteen: a page shows up to `PER_PAGE` orders, each of
     * which is its own Alpine component, and one endpoint per row would mean a
     * burst of near-identical queries every few seconds.
     *
     * Scoped by `user_id` in the WHERE clause, not filtered afterwards — same
     * reason as `findOwned()`: an order belonging to somebody else must be
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

        // The list is rendered into the HTML and echoed back by the client, so
        // it is untrusted input by the time it gets here. Only integers survive
        // the cast above and the placeholders are built from the cleaned list,
        // so there is nothing left to inject.
        $placeholders = [];
        $params = [':uid' => $userId];
        foreach ($ids as $index => $id) {
            $key = ':id' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $rows = $this->db
            ->createCommand(
                'SELECT {{%service_order}}.*, {{%service}}.[[name]] AS service_name'
                . ' FROM {{%service_order}}'
                . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%service_order}}.[[service_id]]'
                . ' WHERE {{%service_order}}.[[user_id]] = :uid'
                . ' AND {{%service_order}}.[[id]] IN (' . implode(', ', $placeholders) . ')',
            )
            ->bindValues($params)
            ->queryAll();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['id']] = $row;
        }

        return $keyed;
    }

    /** Move an order to a new status, merging (not replacing) its metadata. */
    public function setStatus(int $id, string $status, array $metadata = []): void
    {
        $values = ['status' => $status];
        if ($metadata !== []) {
            $existing = self::metadata($this->findById($id) ?? []);
            $values['metadata'] = json_encode($metadata + $existing, JSON_UNESCAPED_UNICODE);
        }
        $this->update($id, $values);
    }

    /**
     * Take an order for review, naming the admin who took it.
     *
     * The whole point of this method is the WHERE clause. The claim is a single
     * conditional UPDATE, so two admins opening the same order at the same
     * moment produce exactly one winner: the loser's statement matches zero
     * rows and they are told somebody else has it. A read-then-write pair
     * (`findById()` then `update()`) would let both admins read `pending`, both
     * write `review`, and both walk away believing the order was theirs — which
     * is the failure this replaces.
     *
     * @return bool false when somebody else has it, it is already decided, or
     *              this admin already holds it
     */
    public function claimOrder(int $id, int $adminId, int $staleAfterSeconds = 1800): bool
    {
        // A claim older than the cutoff is treated as abandoned, so an admin
        // who closed the tab mid-review does not strand the order until an
        // operator clears it by hand.
        $staleBefore = date('Y-m-d H:i:s', time() - max(60, $staleAfterSeconds));

        $affected = $this->db
            ->createCommand(
                "UPDATE {{%service_order}}"
                . " SET [[status]] = 'review', [[claimed_by]] = :admin, [[claimed_at]] = :now,"
                . ' [[updated_at]] = :now'
                . ' WHERE [[id]] = :id AND [[status]] = :pending'
                . ' AND ([[claimed_by]] IS NULL OR [[claimed_at]] < :stale)',
            )
            ->bindValues([
                ':admin' => $adminId,
                ':now' => date('Y-m-d H:i:s'),
                ':stale' => $staleBefore,
                ':id' => $id,
                ':pending' => StatusPresenter::PENDING,
            ])
            ->execute();

        return $affected > 0;
    }

    /**
     * Hand a claimed order back to the queue.
     *
     * Reviewing turned out to need somebody else — the order is bigger than
     * this operator's remit, the user has to be chased, another admin knows the
     * vendor. Leaving it in `review` would strand it with nobody's name on it.
     *
     * The owner check is in the WHERE clause rather than after a read, so a
     * stale form from an admin whose claim has already been taken cannot
     * release the new holder's order.
     *
     * @return bool false when this admin does not hold the claim
     */
    public function releaseOrder(int $id, int $adminId): bool
    {
        $affected = $this->db
            ->createCommand(
                "UPDATE {{%service_order}}"
                . " SET [[status]] = :pending, [[claimed_by]] = NULL, [[claimed_at]] = NULL,"
                . ' [[updated_at]] = :now'
                . " WHERE [[id]] = :id AND [[status]] = 'review' AND [[claimed_by]] = :admin",
            )
            ->bindValues([
                ':pending' => StatusPresenter::PENDING,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
                ':admin' => $adminId,
            ])
            ->execute();

        return $affected > 0;
    }

    /**
     * Record an approval and its decider in one guarded statement.
     *
     * `approved_by IS NULL` is the condition that makes paying out idempotent:
     * the second admin to press "approve" on the same order matches zero rows
     * and is told the order is already decided, rather than crediting their own
     * balance a second time for work somebody else did.
     *
     * @return bool false when the order was already approved or already decided
     */
    public function markApproved(int $id, int $adminId, string $status): bool
    {
        $affected = $this->db
            ->createCommand(
                'UPDATE {{%service_order}}'
                . ' SET [[approved_by]] = :admin, [[approved_at]] = :now, [[status]] = :status,'
                . ' [[claimed_by]] = :admin, [[claimed_at]] = :now, [[updated_at]] = :now'
                . ' WHERE [[id]] = :id AND [[approved_by]] IS NULL'
                . ' AND [[status]] IN (:open1, :open2)',
            )
            ->bindValues([
                ':admin' => $adminId,
                ':now' => date('Y-m-d H:i:s'),
                ':status' => $status,
                ':id' => $id,
                ':open1' => StatusPresenter::PENDING,
                ':open2' => 'review',
            ])
            ->execute();

        return $affected > 0;
    }

    public function forUser(int $userId, int $page, int $perPage, string $status = ''): array
    {
        $where = '{{%service_order}}.[[user_id]] = :uid';
        $params = [':uid' => $userId];
        if ($status !== '') {
            $where .= ' AND {{%service_order}}.[[status]] = :st';
            $params[':st'] = $status;
        }

        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /**
     * Sortable columns, as a whitelist of *names* rather than SQL fragments.
     *
     * Names, because the queue query aliases `service_order` to `o` and joins
     * `user` twice — and MySQL refuses `ORDER BY service_order.id` once the
     * table is aliased. A fixed fragment here would work in one query and fail
     * in the other; the prefix is resolved per query instead.
     */
    public const SORTABLE = ['id', 'reference', 'amount', 'status', 'created_at', 'username'];

    /**
     * The admin order queue.
     *
     * `$onlyMine` narrows the queue to what the signed-in admin has claimed,
     * which is the view an operator actually wants after they have worked a
     * few: "what did I pick up" rather than "everything anybody picked up".
     * `$unclaimed` is its mirror and is what the shared queue links to.
     */
    public function adminList(
        int $page,
        int $perPage,
        string $status = '',
        string $q = '',
        string $sort = 'id',
        string $dir = 'desc',
        ?int $onlyAdminId = null,
        bool $unclaimedOnly = false,
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[] = 'o.[[status]] = :st';
            $params[':st'] = $status;
        }
        if ($q !== '') {
            $where[] = '(o.[[reference]] LIKE :q OR u.[[username]] LIKE :q OR u.[[phone]] LIKE :q'
                . ' OR s.[[name]] LIKE :q)';
            $params[':q'] = "%{$q}%";
        }
        if ($onlyAdminId !== null) {
            $where[] = 'o.[[claimed_by]] = :claimer';
            $params[':claimer'] = $onlyAdminId;
        }
        if ($unclaimedOnly) {
            $where[] = 'o.[[claimed_by]] IS NULL';
        }

        // Work somebody has claimed floats above untouched work, because the
        // whole reason the claim exists is that two admins must not both work
        // the same row — and a claimed row is the one already half-done.
        $clause = implode(' AND ', $where);
        $priority = ($sort === 'id' && strtolower($dir) !== 'asc')
            ? ', (o.[[status]] = \'review\') DESC, (o.[[status]] = \'pending\') DESC'
            : '';

        $offset = max(0, ($page - 1) * $perPage);
        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%service_order}} o'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = o.[[user_id]]'
                . ' LEFT JOIN {{%service}} s ON s.[[id]] = o.[[service_id]]'
                . " WHERE {$clause}",
            )
            ->bindValues($params)
            ->queryScalar();

        $rows = $this->db
            ->createCommand(
                'SELECT o.*, s.[[name]] AS service_name, u.[[username]], u.[[phone]],'
                . ' c.[[username]] AS claimed_by_name'
                . ' FROM {{%service_order}} o'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = o.[[user_id]]'
                . ' LEFT JOIN {{%service}} s ON s.[[id]] = o.[[service_id]]'
                . ' LEFT JOIN {{%user}} c ON c.[[id]] = o.[[claimed_by]]'
                . " WHERE {$clause}"
                . ' ORDER BY ' . $this->sortClause($sort, $dir, 'o.', 'u.') . "{$priority}"
                . " LIMIT {$perPage} OFFSET {$offset}",
            )
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }

    private function pagedJoin(
        string $where,
        array $params,
        int $page,
        int $perPage,
        string $sort = 'id',
        string $dir = 'desc',
    ): array {
        $offset = max(0, ($page - 1) * $perPage);
        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%service_order}}'
                . ' LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%service_order}}.[[user_id]]'
                . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%service_order}}.[[service_id]]'
                . " WHERE {$where}",
            )
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                'SELECT {{%service_order}}.*, {{%service}}.[[name]] AS service_name,'
                . ' {{%user}}.[[username]], {{%user}}.[[phone]]'
                . ' FROM {{%service_order}}'
                . ' LEFT JOIN {{%user}} ON {{%user}}.[[id]] = {{%service_order}}.[[user_id]]'
                . ' LEFT JOIN {{%service}} ON {{%service}}.[[id]] = {{%service_order}}.[[service_id]]'
                . " WHERE {$where}"
                . ' ORDER BY ' . $this->sortClause($sort, $dir, '{{%service_order}}.', '{{%user}}.')
                . " LIMIT {$perPage} OFFSET {$offset}",
            )
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Build a safe `"column dir"` ORDER BY fragment from the whitelist.
     *
     * `$orderPrefix` and `$userPrefix` are supplied by the caller because the
     * same sort list is used against two different shapes of query — the admin
     * queue (aliased) and the user's own history (not). `username` is the one
     * column that belongs to the joined user rather than the order, so it takes
     * the other prefix.
     */
    private function sortClause(string $sort, string $dir, string $orderPrefix, string $userPrefix): string
    {
        $col = in_array($sort, self::SORTABLE, true) ? $sort : 'id';
        $prefix = $col === 'username' ? $userPrefix : $orderPrefix;
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        return $prefix . '[[' . $col . ']] ' . $direction;
    }

    /**
     * The dashboard's "recent searches" panel.
     *
     * A "search" here is a service order — that is the only thing in this
     * product that carries a query, and it already stores the submitted input in
     * its metadata, so there is no second table to keep in sync and nothing to
     * log on a path that might not run.
     *
     * Each row comes back ready for the template: the service slug (so the
     * re-run button can link straight back to the form), the label of the field
     * the user actually typed into, and the value itself.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentSearches(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        $rows = $this->db
            ->createCommand(
                'SELECT o.[[id]], o.[[reference]], o.[[status]], o.[[created_at]], o.[[metadata]],'
                . ' s.[[name]] AS service_name, s.[[slug]] AS service_slug'
                . ' FROM {{%service_order}} o'
                . ' INNER JOIN {{%service}} s ON s.[[id]] = o.[[service_id]]'
                . ' WHERE o.[[user_id]] = :uid AND s.[[deleted_at]] IS NULL'
                . ' ORDER BY o.[[id]] DESC'
                . " LIMIT {$limit}",
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        $searches = [];
        foreach ($rows as $row) {
            $metadata = self::metadata($row);
            $input = is_array($metadata['input'] ?? null) ? $metadata['input'] : [];

            // The first configured field is the one users think of as "the
            // search" (NID, mobile, username…). Fall back to whatever is there
            // so an order made with an unusual form still renders a value.
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
     * The user's orders grouped by the *service category* the ordered service
     * belongs to — the order-type chips on the history page (ফুল NID / লোকেশন /
     * বায়োমেট্রিক / …).
     *
     * @return array<string, int> slug => count
     */
    public function categoryCounts(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT {{%service_category}}.[[slug]] AS slug, COUNT(*) AS c'
                . ' FROM {{%service_order}}'
                . ' JOIN {{%service}} ON {{%service}}.[[id]] = {{%service_order}}.[[service_id]]'
                . ' JOIN {{%service_category}} ON {{%service_category}}.[[id]] = {{%service}}.[[category_id]]'
                . ' WHERE {{%service_order}}.[[user_id]] = :uid'
                . ' GROUP BY {{%service_category}}.[[slug]]',
            )
            ->bindValue(':uid', $userId)
            ->queryAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['slug']] = (int) $row['c'];
        }

        return $counts;
    }

    /** Filter a user's orders by service-category slug (order-type chip). */
    public function forUserByCategory(int $userId, int $page, int $perPage, string $categorySlug): array
    {
        $where = '{{%service_order}}.[[user_id]] = :uid AND {{%service_category}}.[[slug]] = :slug';
        $params = [':uid' => $userId, ':slug' => $categorySlug];

        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /** The user's orders of ONE service (the per-service table under the order form). */
    public function forUserByService(int $userId, int $serviceId, int $page = 1, int $perPage = 5): array
    {
        $where = '{{%service_order}}.[[user_id]] = :uid AND {{%service_order}}.[[service_id]] = :sid';
        $params = [':uid' => $userId, ':sid' => $serviceId];

        return $this->pagedJoin($where, $params, $page, $perPage);
    }

    /**
     * How many orders the user has in each status, keyed by status.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $userId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[status]], COUNT(*) AS [[c]] FROM {{%service_order}}'
                . ' WHERE [[user_id]] = :uid GROUP BY [[status]]',
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

    /** Completed-order count for the dashboard "সফল অনুসন্ধান" card. */
    public function completedCount(int $userId): int
    {
        $value = $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%service_order}}'
                . " WHERE [[user_id]] = :uid AND [[status]] = 'completed'",
            )
            ->bindValue(':uid', $userId)
            ->queryScalar();

        return (int) $value;
    }

    public function statsAll(): array
    {
        return $this->stats('1=1', []);
    }

    /**
     * Orders still waiting on an operator, and what they are worth.
     *
     * `review` counts as open: a claimed order is not off the operator's
     * plate, it is simply being worked on, and a dashboard that hid them would
     * look calm precisely when a queue is being worked.
     *
     * @return array{count: int, amount: float}
     */
    public function openOrders(): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT COUNT(*) AS c, COALESCE(SUM([[amount]]),0) AS amount FROM {{%service_order}}'
                . ' WHERE [[status]] IN (:pending, :review)',
            )
            ->bindValues([
                ':pending' => StatusPresenter::PENDING,
                ':review' => 'review',
            ])
            ->queryOne();

        return [
            'count' => (int) ($row['c'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }

    /**
     * How many open orders this admin has claimed — the badge on "আমার কাজ".
     *
     * @return array{count: int, amount: float}
     */
    public function claimedBy(int $adminId): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT COUNT(*) AS c, COALESCE(SUM([[amount]]),0) AS amount FROM {{%service_order}}'
                . " WHERE [[claimed_by]] = :admin AND [[status]] = 'review'",
            )
            ->bindValue(':admin', $adminId)
            ->queryOne();

        return [
            'count' => (int) ($row['c'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }

    private function stats(string $where, array $params): array
    {
        $row = $this->db
            ->createCommand(
                "SELECT COUNT(*) AS total, COALESCE(SUM([[amount]]),0) AS amount"
                . " FROM {{%service_order}} WHERE {$where}",
            )
            ->bindValues($params)
            ->queryOne();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    }
}
