<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Admin withdrawal requests.
 *
 * Shaped exactly like the recharge queue — `pending` → `review` → a decision,
 * with `claimed_by` naming the reviewer — because it *is* the same job: a
 * human confirms a real payment leaving the platform before it is recorded as
 * done. Reusing the shape means the review screen an operator already knows
 * for recharges is the right screen for withdrawals too.
 *
 * The one difference is the money. A recharge credits the user and is decided
 * by the admin who pressed the button. A withdrawal is debited to the *requester*
 * the moment the row is created (see {@see AdminWithdrawService::request()}),
 * so approving only records the decision and rejecting credits the hold back.
 * That is why there is no `amount_remaining` column: what is on the row is what
 * was moved.
 */
final class AdminWithdrawRepository
{
    public const PENDING = 'pending';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::REVIEW, self::APPROVED, self::REJECTED];

    /** Statuses that still owe a decision. */
    public const OPEN_STATUSES = [self::PENDING, self::REVIEW];

    /**
     * Payout methods an admin can ask for.
     *
     * A closed list rather than free text: the review screen labels each one,
     * and an unlabelled value in that column is a payment instruction nobody
     * can act on.
     */
    public const METHODS = ['bank', 'bkash', 'nagad', 'rocket'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%admin_withdraw_request}}', [
            'admin_id' => (int) $row['admin_id'],
            'amount' => (float) $row['amount'],
            'method' => (string) ($row['method'] ?? 'bank'),
            'account_details' => isset($row['account_details'])
                ? mb_substr((string) $row['account_details'], 0, 500)
                : null,
            'note' => isset($row['note']) ? mb_substr((string) $row['note'], 0, 500) : null,
            'status' => self::PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%admin_withdraw_request}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();

        return $row === false ? null : $row;
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%admin_withdraw_request}}', $values, ['id' => $id])->execute();
    }

    /**
     * Take a withdrawal for review.
     *
     * Same conditional-UPDATE claim as the order queue, for the same reason:
     * two super-admins approving the same payout would pay it twice.
     */
    public function claim(int $id, int $reviewerId): bool
    {
        $staleBefore = date('Y-m-d H:i:s', time() - 1800);

        $affected = $this->db
            ->createCommand(
                'UPDATE {{%admin_withdraw_request}}'
                . ' SET [[status]] = :review, [[claimed_by]] = :who, [[claimed_at]] = :now, [[updated_at]] = :now'
                . ' WHERE [[id]] = :id AND [[status]] = :pending'
                . ' AND ([[claimed_by]] IS NULL OR [[claimed_at]] < :stale)',
            )
            ->bindValues([
                ':review' => self::REVIEW,
                ':who' => $reviewerId,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
                ':pending' => self::PENDING,
                ':stale' => $staleBefore,
            ])
            ->execute();

        return $affected > 0;
    }

    /** Hand a claimed withdrawal back to the queue. Only the holder may do so. */
    public function release(int $id, int $reviewerId): bool
    {
        $affected = $this->db
            ->createCommand(
                'UPDATE {{%admin_withdraw_request}}'
                . ' SET [[status]] = :pending, [[claimed_by]] = NULL, [[claimed_at]] = NULL,'
                . ' [[updated_at]] = :now'
                . ' WHERE [[id]] = :id AND [[status]] = :review AND [[claimed_by]] = :who',
            )
            ->bindValues([
                ':pending' => self::PENDING,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
                ':who' => $reviewerId,
            ])
            ->execute();

        return $affected > 0;
    }

    /**
     * Record the decision, once.
     *
     * `reviewed_by IS NULL` in the WHERE is the idempotency gate: a replayed
     * POST matches no rows instead of recording a second reviewer.
     *
     * @param array<string, mixed> $values extra columns to write with the decision
     */
    public function markReviewed(int $id, int $reviewerId, string $status, array $values = []): bool
    {
        $affected = $this->db
            ->createCommand(
                'UPDATE {{%admin_withdraw_request}}'
                . ' SET [[status]] = :status, [[reviewed_by]] = :who, [[reviewed_at]] = :now,'
                . ' [[claimed_by]] = :who, [[claimed_at]] = :now, [[updated_at]] = :now'
                . ' WHERE [[id]] = :id AND [[reviewed_by]] IS NULL AND [[status]] IN (:o1, :o2)',
            )
            ->bindValues([
                ':status' => $status,
                ':who' => $reviewerId,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
                ':o1' => self::PENDING,
                ':o2' => self::REVIEW,
            ])
            ->execute();

        // The guard and the write are one statement on purpose; this is only
        // reached when the row has not moved yet.
        if ($affected > 0 && $values !== []) {
            $this->update($id, $values);
        }

        return $affected > 0;
    }

    /**
     * Whether this admin already has a request nobody has decided yet.
     *
     * Checked before a new submission so one operator cannot stack four copies
     * of the same payout into the queue. It is an existence test rather than a
     * read of the newest row: with four requests where the oldest is the open
     * one, "look at the last page" would answer no and let the fifth through.
     */
    public function hasOpenRequest(int $adminId): bool
    {
        $found = $this->db
            ->createCommand(
                'SELECT 1 FROM {{%admin_withdraw_request}}'
                . " WHERE [[admin_id]] = :aid AND [[status]] IN ('pending','review') LIMIT 1",
            )
            ->bindValue(':aid', $adminId)
            ->queryScalar();

        return $found !== false && (int) $found === 1;
    }

    /**
     * One admin's own withdrawal history.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function forAdmin(int $adminId, int $page, int $perPage, string $status = ''): array
    {
        $where = 't.[[admin_id]] = :aid';
        $params = [':aid' => $adminId];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND t.[[status]] = :st';
            $params[':st'] = $status;
        }

        return $this->paged($where, $params, $page, $perPage);
    }

    /**
     * The platform-wide payout queue.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function adminList(
        int $page,
        int $perPage,
        string $status = '',
        string $q = '',
        string $sort = 'created_at',
        string $dir = 'desc',
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 't.[[status]] = :st';
            $params[':st'] = $status;
        }
        if (trim($q) !== '') {
            $where[] = '(u.[[username]] LIKE :q OR u.[[phone]] LIKE :q OR t.[[account_details]] LIKE :q)';
            $params[':q'] = '%' . trim($q) . '%';
        }

        $column = $sort === 'amount' ? 't.[[amount]]' : 't.[[created_at]]';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $clause = implode(' AND ', $where);
        // Claimed work floats to the top, exactly as on the recharge queue.
        $priority = $sort === 'created_at' && strtolower($dir) !== 'asc'
            ? ", (t.[[status]] = 'review') DESC, (t.[[status]] = 'pending') DESC"
            : '';

        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%admin_withdraw_request}} t'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = t.[[admin_id]]'
                . " WHERE {$clause}",
            )
            ->bindValues($params)
            ->queryScalar();

        $rows = $this->db
            ->createCommand(
                'SELECT t.*, u.[[username]], u.[[phone]], u.[[balance]] AS admin_balance'
                . ' FROM {{%admin_withdraw_request}} t'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = t.[[admin_id]]'
                . " WHERE {$clause}"
                . " ORDER BY {$column} {$direction}{$priority}"
                . ' LIMIT ' . (int) $perPage . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            )
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Payout queue headline numbers.
     *
     * @return array{total: int, pending: int, review: int, unresolved: int, unresolved_amount: float, approved_amount: float}
     */
    public function stats(): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT COUNT(*) AS total,'
                . " COALESCE(SUM(CASE WHEN [[status]] = 'pending' THEN 1 ELSE 0 END), 0) AS pending,"
                . " COALESCE(SUM(CASE WHEN [[status]] = 'review' THEN 1 ELSE 0 END), 0) AS review,"
                . " COALESCE(SUM(CASE WHEN [[status]] IN ('pending','review') THEN 1 ELSE 0 END), 0) AS unresolved,"
                . " COALESCE(SUM(CASE WHEN [[status]] IN ('pending','review') THEN [[amount]] ELSE 0 END), 0) AS unresolved_amount,"
                . " COALESCE(SUM(CASE WHEN [[status]] = 'approved' THEN [[amount]] ELSE 0 END), 0) AS approved_amount"
                . ' FROM {{%admin_withdraw_request}}',
            )
            ->queryOne();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'review' => (int) ($row['review'] ?? 0),
            'unresolved' => (int) ($row['unresolved'] ?? 0),
            'unresolved_amount' => (float) ($row['unresolved_amount'] ?? 0),
            'approved_amount' => (float) ($row['approved_amount'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function paged(string $where, array $params, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%admin_withdraw_request}} t WHERE {$where}")
            ->bindValues($params)
            ->queryScalar();

        $rows = $this->db
            ->createCommand(
                'SELECT t.*, r.[[username]] AS reviewer_name'
                . ' FROM {{%admin_withdraw_request}} t'
                . ' LEFT JOIN {{%user}} r ON r.[[id]] = t.[[reviewed_by]]'
                . " WHERE {$where}"
                . ' ORDER BY t.[[id]] DESC'
                . " LIMIT {$perPage} OFFSET {$offset}",
            )
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }
}
