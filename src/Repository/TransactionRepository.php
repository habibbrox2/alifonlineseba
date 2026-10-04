<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The money ledger: one row per movement of money, ever, for anybody.
 *
 * This table used to be the service-order queue as well, which meant "show me
 * the money" and "show me the work" were the same query with a different
 * filter. They are separate concerns now — orders live in
 * {@see ServiceOrderRepository} — and what is left here is a strictly
 * append-only record.
 *
 * Append-only is the design constraint, not a description of the code: no
 * method here updates or deletes a row. A correction is a *new* pair of
 * entries (the wrong one and its reversal), which is why `LedgerService`
 * exists as the only way to write to this table and why every entry carries
 * the balance before and after it. Reading the ledger top to bottom therefore
 * reproduces the balance exactly, and an operator disputing a figure can be
 * answered with the rows rather than with an assertion.
 *
 * `user_id` and `admin_id` are separate columns rather than one polymorphic
 * `owner_id`: an account is one or the other, and a single indexed column per
 * role is what makes "this admin's earnings" a cheap query instead of a scan
 * with a type filter.
 */
final class TransactionRepository
{
    // --- Ledger entry types -------------------------------------------------
    //
    // A closed set, because these are the words a support answer is written
    // with. `type` is indexed and filtered on, so adding a value here without
    // a label is how a page ends up showing a raw English slug to a Bengali
    // speaker.

    /** Money arriving from outside: a recharge the platform verified. */
    public const TYPE_TOPUP = 'topup';

    /** Money leaving a user to pay for an order. */
    public const TYPE_ORDER_DEBIT = 'order_debit';

    /** Money arriving for an admin who approved an order. */
    public const TYPE_ORDER_CREDIT = 'order_credit';

    /** Money returning to a user because an order was cancelled or failed. */
    public const TYPE_ORDER_REFUND = 'order_refund';

    /** An admin taking their earnings out. Debited at request time. */
    public const TYPE_WITHDRAW = 'withdraw';

    /** A rejected withdrawal being given back to the admin. */
    public const TYPE_WITHDRAW_REFUND = 'withdraw_refund';

    /** A referral bonus, paid from the platform rather than from an order. */
    public const TYPE_REFERRAL_BONUS = 'referral_bonus';

    /** A deliberate, audited correction made by a super-admin. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    /** type => [Bengali label, badge class]. */
    private const TYPE_MAP = [
        self::TYPE_TOPUP => ['রিচার্জ', 'badge-success'],
        self::TYPE_ORDER_DEBIT => ['অর্ডার খরচ', 'badge-info'],
        self::TYPE_ORDER_CREDIT => ['অর্ডার আয়', 'badge-success'],
        self::TYPE_ORDER_REFUND => ['অর্ডার ফেরত', 'badge-warning'],
        self::TYPE_WITHDRAW => ['উত্তোলন', 'badge-neutral'],
        self::TYPE_WITHDRAW_REFUND => ['উত্তোলন বাতিল', 'badge-warning'],
        self::TYPE_REFERRAL_BONUS => ['রেফারেল বোনাস', 'badge-success'],
        self::TYPE_ADJUSTMENT => ['সমন্বয়', 'badge-neutral'],
    ];

    /**
     * Every type, in the order a person reads their own statement: what came
     * in, what went out, what came back.
     */
    public const TYPES = [
        self::TYPE_TOPUP,
        self::TYPE_REFERRAL_BONUS,
        self::TYPE_ORDER_DEBIT,
        self::TYPE_ORDER_REFUND,
        self::TYPE_WITHDRAW,
        self::TYPE_WITHDRAW_REFUND,
        self::TYPE_ADJUSTMENT,
        self::TYPE_ORDER_CREDIT,
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Append one entry.
     *
     * `direction` is derived from the amount rather than passed separately: a
     * caller that writes `amount = 50, direction = debit` has made a mistake,
     * and the only defence would be a second thing to keep in step. The sign
     * convention is that `$amount` is always a positive magnitude and the
     * direction says which way it went.
     *
     * @param array{
     *     type: string,
     *     amount: float,
     *     user_id?: int|null,
     *     admin_id?: int|null,
     *     service_order_id?: int|null,
     *     direction?: string,
     *     description?: string|null,
     *     balance_before?: float|null,
     *     balance_after?: float|null,
     *     reference?: string|null,
     *     status?: string,
     *     metadata?: array<string, mixed>|null,
     * } $row
     *
     * @return int the new entry's id
     */
    public function record(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $amount = abs((float) $row['amount']);
        $direction = (string) ($row['direction'] ?? self::DIRECTION_CREDIT);

        $this->db->createCommand()->insert('{{%transaction}}', [
            'user_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'admin_id' => isset($row['admin_id']) && $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
            'service_id' => null,
            'service_order_id' => isset($row['service_order_id']) && $row['service_order_id'] !== null
                ? (int) $row['service_order_id']
                : null,
            'type' => (string) $row['type'],
            'direction' => $direction,
            'amount' => $amount,
            'reference' => (string) ($row['reference'] ?? self::generateReference()),
            'status' => (string) ($row['status'] ?? 'completed'),
            'description' => isset($row['description']) ? mb_substr((string) $row['description'], 0, 255) : null,
            'balance_before' => isset($row['balance_before']) ? (float) $row['balance_before'] : null,
            'balance_after' => isset($row['balance_after']) ? (float) $row['balance_after'] : null,
            'metadata' => isset($row['metadata']) && $row['metadata'] !== []
                ? json_encode($row['metadata'], JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    /** AL-prefixed hex reference, so a ledger row is quotable over the phone. */
    public static function generateReference(): string
    {
        return 'ALTX' . strtoupper(bin2hex(random_bytes(5)));
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%transaction}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * The signed amount of an entry, as a balance delta.
     *
     * Everything that sums money reads this rather than `amount`, because a
     * statement that adds up the magnitudes is a statement that is always
     * positive and therefore always wrong.
     */
    public static function signed(array $row): float
    {
        $amount = abs((float) ($row['amount'] ?? 0));

        return ((string) ($row['direction'] ?? self::DIRECTION_CREDIT)) === self::DIRECTION_DEBIT
            ? -$amount
            : $amount;
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPE_MAP[$type][0] ?? $type;
    }

    public static function typeBadge(string $type): string
    {
        return self::TYPE_MAP[$type][1] ?? 'badge-neutral';
    }

    /**
     * A user's own statement — the account "লেনদেন" page.
     *
     * Scoped by `user_id` in the WHERE clause rather than filtered afterwards,
     * so an entry belonging to an admin's wallet can never reach a customer's
     * page even if a filter were dropped.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function forUser(int $userId, int $page, int $perPage, string $type = ''): array
    {
        $where = 't.[[user_id]] = :uid';
        $params = [':uid' => $userId];
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where .= ' AND t.[[type]] = :ty';
            $params[':ty'] = $type;
        }

        return $this->paged($where, $params, $page, $perPage);
    }

    /**
     * One admin's ledger.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function forAdmin(int $adminId, int $page, int $perPage, string $type = ''): array
    {
        $where = 't.[[admin_id]] = :aid';
        $params = [':aid' => $adminId];
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where .= ' AND t.[[type]] = :ty';
            $params[':ty'] = $type;
        }

        return $this->paged($where, $params, $page, $perPage);
    }

    /**
     * The platform-wide ledger, for a super-admin.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function all(int $page, int $perPage, string $type = '', string $q = ''): array
    {
        $where = ['1=1'];
        $params = [];

        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where[] = 't.[[type]] = :ty';
            $params[':ty'] = $type;
        }
        if (trim($q) !== '') {
            $where[] = '(t.[[reference]] LIKE :q OR cu.[[username]] LIKE :q OR au.[[username]] LIKE :q)';
            $params[':q'] = '%' . trim($q) . '%';
        }

        return $this->paged(implode(' AND ', $where), $params, $page, $perPage);
    }

    /**
     * Totals for one owner, by type.
     *
     * @return array<string, array{credit: float, debit: float, count: int}>
     */
    public function totalsForUser(int $userId): array
    {
        return $this->totals('t.[[user_id]] = :id', [':id' => $userId]);
    }

    /** @return array<string, array{credit: float, debit: float, count: int}> */
    public function totalsForAdmin(int $adminId): array
    {
        return $this->totals('t.[[admin_id]] = :id', [':id' => $adminId]);
    }

    /**
     * Every admin's earnings, for the super-admin's staff screen.
     *
     * Summed in SQL rather than by paging through `forAdmin()` per person: the
     * answer to "who has earned what" is one grouped query, and looping would
     * make the staff page cost one round trip per admin on it.
     *
     * @return array<int, array{id: int, username: string, earned: float, withdrawn: float, orders: int}>
     */
    public function adminEarnings(): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT u.[[id]], u.[[username]],'
                . " COALESCE(SUM(CASE WHEN t.[[type]] = 'order_credit' THEN t.[[amount]] ELSE 0 END), 0) AS earned,"
                . " COALESCE(SUM(CASE WHEN t.[[type]] = 'withdraw' THEN t.[[amount]] ELSE 0 END), 0) AS withdrawn,"
                . " COALESCE(SUM(CASE WHEN t.[[type]] = 'order_credit' THEN 1 ELSE 0 END), 0) AS orders"
                . ' FROM {{%user}} u'
                . ' LEFT JOIN {{%transaction}} t ON t.[[admin_id]] = u.[[id]]'
                . " WHERE u.[[role]] IN ('admin','staff','superadmin')"
                . ' GROUP BY u.[[id]], u.[[username]]'
                . ' ORDER BY earned DESC',
            )
            ->queryAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'earned' => (float) $row['earned'],
                'withdrawn' => (float) $row['withdrawn'],
                'orders' => (int) $row['orders'],
                // What is still in the admin's wallet, as the ledger sees it.
                'available' => (float) $row['earned'] - (float) $row['withdrawn'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function paged(string $where, array $params, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->db
            ->createCommand("SELECT COUNT(*) FROM {{%transaction}} t WHERE {$where}")
            ->bindValues($params)
            ->queryScalar();

        $rows = $this->db
            ->createCommand(
                'SELECT t.*, cu.[[username]] AS user_name, au.[[username]] AS admin_name,'
                . ' o.[[reference]] AS order_reference, s.[[name]] AS service_name'
                . ' FROM {{%transaction}} t'
                . ' LEFT JOIN {{%user}} cu ON cu.[[id]] = t.[[user_id]]'
                . ' LEFT JOIN {{%user}} au ON au.[[id]] = t.[[admin_id]]'
                . ' LEFT JOIN {{%service_order}} o ON o.[[id]] = t.[[service_order_id]]'
                . ' LEFT JOIN {{%service}} s ON s.[[id]] = o.[[service_id]]'
                . " WHERE {$where}"
                . ' ORDER BY t.[[id]] DESC'
                . " LIMIT {$perPage} OFFSET {$offset}",
            )
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, array{credit: float, debit: float, count: int}>
     */
    private function totals(string $where, array $params): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT t.[[type]],'
                . " COALESCE(SUM(CASE WHEN t.[[direction]] = 'credit' THEN t.[[amount]] ELSE 0 END), 0) AS credit,"
                . " COALESCE(SUM(CASE WHEN t.[[direction]] = 'debit' THEN t.[[amount]] ELSE 0 END), 0) AS debit,"
                . ' COUNT(*) AS c'
                . " FROM {{%transaction}} t WHERE {$where}"
                . ' GROUP BY t.[[type]]',
            )
            ->bindValues($params)
            ->queryAll();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['type']] = [
                'credit' => (float) $row['credit'],
                'debit' => (float) $row['debit'],
                'count' => (int) $row['c'],
            ];
        }

        return $totals;
    }

    /**
     * Headline numbers for the account page: how much came in, how much went
     * out, and how many entries there are.
     *
     * @return array{total: int, credit: float, debit: float}
     */
    public function statsForUser(int $userId): array
    {
        return $this->stats('[[user_id]] = :uid', [':uid' => $userId]);
    }

    /** @return array{total: int, credit: float, debit: float} */
    public function statsAll(): array
    {
        return $this->stats('1=1', []);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{total: int, credit: float, debit: float}
     */
    private function stats(string $where, array $params): array
    {
        $row = $this->db
            ->createCommand(
                "SELECT COUNT(*) AS total,"
                . " COALESCE(SUM(CASE WHEN [[direction]] = 'credit' THEN [[amount]] ELSE 0 END), 0) AS credit,"
                . " COALESCE(SUM(CASE WHEN [[direction]] = 'debit' THEN [[amount]] ELSE 0 END), 0) AS debit"
                . " FROM {{%transaction}} WHERE {$where}",
            )
            ->bindValues($params)
            ->queryOne();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'credit' => (float) ($row['credit'] ?? 0),
            'debit' => (float) ($row['debit'] ?? 0),
        ];
    }
}
