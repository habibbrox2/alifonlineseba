<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class TopupRepository
{
    public const METHODS = ['bkash', 'nagad', 'rocket'];

    public const PENDING = 'pending';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** Queue order, which is also the order the status filter chips are shown in. */
    public const STATUSES = [self::PENDING, self::REVIEW, self::APPROVED, self::REJECTED];

    /**
     * Statuses that still owe the user a decision.
     *
     * Everything that branches on "is this request still open" reads this list
     * rather than comparing against `pending`, so adding `review` cannot
     * accidentally open a hole in a guard.
     */
    public const OPEN_STATUSES = [self::PENDING, self::REVIEW];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%topup_request}}', [
            'user_id' => (int) $row['user_id'],
            'amount' => $row['amount'],
            'method' => (string) $row['method'],
            'sender_number' => $row['sender_number'] ?? null,
            'sender_name' => $row['sender_name'] ?? null,
            'reference' => $row['reference'] ?? null,
            'note' => $row['note'] ?? null,
            'receipt_path' => $row['receipt_path'] ?? null,
            'receipt_name' => $row['receipt_name'] ?? null,
            'receipt_mime' => $row['receipt_mime'] ?? null,
            'receipt_size' => $row['receipt_size'] ?? null,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%topup_request}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    /**
     * Same transaction ID already submitted and not yet rejected.
     *
     * A rejected request may legitimately be resubmitted with the same TrxID
     * (the user fixed a typo or was asked to resend), so only pending and
     * approved rows count as a collision.
     */
    public function findByReference(string $reference, ?int $excludeId = null): ?array
    {
        $sql = "SELECT * FROM {{%topup_request}} WHERE [[reference]] = :ref AND [[status]] <> 'rejected'";
        $params = [':ref' => $reference];
        if ($excludeId !== null) {
            $sql .= ' AND [[id]] <> :id';
            $params[':id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $row = $this->db->createCommand($sql)->bindValues($params)->queryOne();
        return $row === false ? null : $row;
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%topup_request}}', $values, ['id' => $id])->execute();
    }

    public function forUser(int $userId, int $page = 1, int $perPage = 5, string $status = ''): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = '[[user_id]] = :uid';
        $params = [':uid' => $userId];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND [[status]] = :st';
            $params[':st'] = $status;
        }
        return $this->paged($where, $params, $page, $perPage, '[[id]] DESC');
    }

    /**
     * How many recharges are still waiting for a decision.
     *
     * Counts `pending` *and* `review`: a claimed request is not off the
     * operator's plate, it is simply being worked on, and a dashboard that
     * hid them would look calm precisely when a queue is being worked.
     */
    public function unresolvedCount(): int
    {
        return (int) $this->db
            ->createCommand(
                "SELECT COUNT(*) FROM {{%topup_request}} WHERE [[status]] IN ('" . implode("','", self::OPEN_STATUSES) . "')",
            )
            ->queryScalar();
    }

    /**
     * Requests per status, always keyed by every value in {@see STATUSES} so a
     * view can loop over the counts without guarding for a missing key.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT [[status]], COUNT(*) AS c FROM {{%topup_request}} GROUP BY [[status]]',
            )
            ->queryAll();

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Whether this user already has a request nobody has decided yet.
     *
     * Checked before a new submission so one user cannot stack four copies of
     * the same payment into the queue and hope one of them gets approved.
     */
    public function hasOpenRequest(int $userId): bool
    {
        $found = $this->db
            ->createCommand(
                "SELECT 1 FROM {{%topup_request}} WHERE [[user_id]] = :uid"
                . " AND [[status]] IN ('" . implode("','", self::OPEN_STATUSES) . "') LIMIT 1",
            )
            ->bindValue(':uid', $userId)
            ->queryScalar();

        return $found !== false && (int) $found === 1;
    }

    /**
     * Admin queue.
     *
     * @param string $q     free text over username / phone / TrxID / sender number
     * @param string $sort  created_at|amount
     * @param string $dir   asc|desc
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
            $where[] = '(u.[[username]] LIKE :q OR u.[[phone]] LIKE :q'
                . ' OR t.[[reference]] LIKE :q OR t.[[sender_number]] LIKE :q'
                . ' OR t.[[receipt_name]] LIKE :q)';
            $params[':q'] = '%' . trim($q) . '%';
        }

        $column = $sort === 'amount' ? 't.[[amount]]' : 't.[[created_at]]';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $clause = implode(' AND ', $where);
        // Unresolved work always floats to the top unless the admin explicitly
        // sorts. `review` outranks `pending` so whatever an admin is actively
        // looking at stays at the top of their own screen.
        $priority = $sort === 'created_at' && strtolower($dir) !== 'asc'
            ? ", (t.[[status]] = 'review') DESC, (t.[[status]] = 'pending') DESC"
            : '';

        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%topup_request}} t'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = t.[[user_id]]'
                . " WHERE {$clause}"
            )
            ->bindValues($params)
            ->queryScalar();

        $rows = $this->db
            ->createCommand(
                'SELECT t.*, u.[[username]], u.[[phone]]'
                . ' FROM {{%topup_request}} t'
                . ' LEFT JOIN {{%user}} u ON u.[[id]] = t.[[user_id]]'
                . " WHERE {$clause}"
                . " ORDER BY {$column} {$direction}{$priority}"
                . ' LIMIT ' . (int) $perPage . ' OFFSET ' . max(0, ($page - 1) * $perPage)
            )
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }

    public function stats(): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT COUNT(*) AS total,'
                . " COALESCE(SUM(CASE WHEN [[status]] = 'pending' THEN 1 ELSE 0 END), 0) AS pending,"
                . " COALESCE(SUM(CASE WHEN [[status]] = 'review' THEN 1 ELSE 0 END), 0) AS review,"
                . " COALESCE(SUM(CASE WHEN [[status]] IN ('pending','review') THEN 1 ELSE 0 END), 0) AS unresolved,"
                . " COALESCE(SUM(CASE WHEN [[status]] IN ('pending','review') THEN [[amount]] ELSE 0 END), 0) AS unresolved_amount,"
                . " COALESCE(SUM(CASE WHEN [[status]] = 'approved' THEN 1 ELSE 0 END), 0) AS approved,"
                . " COALESCE(SUM(CASE WHEN [[status]] = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected,"
                . " COALESCE(SUM(CASE WHEN [[status]] = 'approved' THEN [[amount]] ELSE 0 END), 0) AS approved_amount,"
                . ' COALESCE(SUM(CASE WHEN [[receipt_path]] IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_receipt'
                . ' FROM {{%topup_request}}'
            )
            ->queryOne();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'review' => (int) ($row['review'] ?? 0),
            'unresolved' => (int) ($row['unresolved'] ?? 0),
            'unresolved_amount' => (float) ($row['unresolved_amount'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'approved_amount' => (float) ($row['approved_amount'] ?? 0),
            'with_receipt' => (int) ($row['with_receipt'] ?? 0),
        ];
    }

    /**
     * Unclaimed pending ids, oldest first — used by the admin bulk-approve
     * action.
     *
     * Deliberately `pending` only, never `review`: a row somebody else has
     * already opened is somebody else's decision to make, and quietly
     * approving it from a bulk button is the exact accident the `review` state
     * exists to prevent.
     */
    public function pendingIds(int $limit = 50): array
    {
        $rows = $this->db
            ->createCommand(
                "SELECT [[id]] FROM {{%topup_request}} WHERE [[status]] = 'pending'"
                . ' ORDER BY [[id]] ASC LIMIT ' . (int) $limit
            )
            ->queryAll();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{rows: array, total: int}
     */
    private function paged(string $where, array $params, int $page, int $perPage, string $order): array
    {
        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%topup_request}} WHERE {$where}")
            ->bindValues($params)
            ->queryScalar();
        $rows = $this->db
            ->createCommand(
                "SELECT * FROM {{%topup_request}} WHERE {$where} ORDER BY {$order}"
                . ' LIMIT ' . (int) $perPage . ' OFFSET ' . max(0, ($page - 1) * $perPage)
            )
            ->bindValues($params)
            ->queryAll();
        return ['rows' => $rows, 'total' => $total];
    }
}
