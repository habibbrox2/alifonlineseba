<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The referral ledger.
 *
 * One row per referred account, created the moment that account signs up with
 * a valid `?ref=` code and left `pending` until the friend's run of qualifying
 * recharges is finished. The row — not the user table — is the record of what
 * was promised, which side has been paid and who signed off on it, so an admin
 * can always answer "why did this person get ৳50" from the ledger alone.
 *
 * Qualification is progress rather than a moment: the friend has to complete
 * `required_count` approved recharges of at least `min_amount` each. Both are
 * snapshotted here at creation, exactly like `referrer_amount`, so the deal a
 * referral is judged by is the one that was on offer when the friend signed up
 * rather than whatever the settings say today.
 *
 * `referee_id` carries a UNIQUE index: a person can be referred exactly once,
 * no matter how many codes they land on. That constraint is the last line of
 * defence against a self-referral ring; the service checks for it first, but
 * the database is what actually makes it true.
 */
final class ReferralRepository
{
    /** A signup landed; the bonus has not been triggered yet. */
    public const PENDING = 'pending';
    /** Both sides have been credited. */
    public const PAID = 'paid';
    /** The operator voided it — abuse, duplicate account, chargeback. */
    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::PAID, self::REJECTED];

    /** Whitelisted sortable columns => SQL column expression. */
    public const SORTABLE = [
        'id' => '{{%referral}}.[[id]]',
        'code' => '{{%referral}}.[[code]]',
        'status' => '{{%referral}}.[[status]]',
        'completed_count' => '{{%referral}}.[[completed_count]]',
        'trigger_amount' => '{{%referral}}.[[trigger_amount]]',
        'referrer_amount' => '{{%referral}}.[[referrer_amount]]',
        'created_at' => '{{%referral}}.[[created_at]]',
        'referrer' => '[[ru]].[[username]]',
        'referee' => '[[fu]].[[username]]',
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param array{referrer_id: int, referee_id: int, code: string,
     *              referrer_amount?: float, referee_amount?: float} $row
     */
    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%referral}}', [
            'referrer_id' => (int) $row['referrer_id'],
            'referee_id' => (int) $row['referee_id'],
            'code' => (string) $row['code'],
            'status' => self::PENDING,
            // The amounts are snapshotted from settings at signup, not read at
            // payout time. If the operator cuts the bonus next month, referrals
            // already in flight must still pay what the user was promised.
            'referrer_amount' => $row['referrer_amount'] ?? 0,
            'referee_amount' => $row['referee_amount'] ?? 0,
            // Same reasoning for the qualification rule itself: the count and
            // the per-recharge floor are what this referral is judged against,
            // fixed at the moment it was created. Raising the requirement later
            // applies to new referrals only — otherwise a friend halfway
            // through a run of five would be told they now owe three more.
            'required_count' => max(1, (int) ($row['required_count'] ?? 5)),
            'min_amount' => max(0.0, (float) ($row['min_amount'] ?? 0.0)),
            'completed_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    /**
     * How many of this account's approved recharges qualify.
     *
     * "Qualifying" is deliberately narrower than "approved", and the status list
     * here is the whole definition:
     *
     * - `approved` counts. Nothing else does. `pending` has not happened yet,
     *   `review` is being decided, and `rejected` is a refusal — so none of
     *   them can move a referral towards a payout, and none of them can be
     *   un-counted either, because there is no "was approved then reversed"
     *   state in `topup_request` to unwind.
     * - `amount >= $minAmount` counts. A friend who tops up ৳10 five times has
     *   not done what the offer said, and the operator paid ৳70 of real money
     *   for it. `$minAmount` of 0 disables the floor, which is a legitimate
     *   operator choice and is why the comparison is written as `<` rather than
     *   assuming a positive threshold.
     *
     * Counted from the recharge table rather than incremented on approval so
     * the number is always true of the data. It is read inside the same
     * transaction as the payout, where the row lock the guarded UPDATE takes is
     * what serialises two concurrent approvals.
     *
     * @return int
     */
    public function qualifyingRechargeCount(int $refereeId, float $minAmount): int
    {
        return (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%topup_request}}'
                . ' WHERE [[user_id]] = :u AND [[status]] = :st AND [[amount]] >= :min',
            )
            ->bindValues([':u' => $refereeId, ':st' => TopupRepository::APPROVED, ':min' => $minAmount])
            ->queryScalar();
    }

    /**
     * Record progress against a referral without paying anything.
     *
     * Returns false when the referral is no longer `pending`, which is the
     * caller's signal that this run has already been settled or voided and the
     * counter must be left alone.
     */
    public function recordProgress(int $referralId, int $completed): bool
    {
        return $this->transition($referralId, self::PENDING, self::PENDING, [
            'completed_count' => max(0, $completed),
        ]);
    }

    /**
     * The referral attached to this account, read with its progress columns.
     *
     * Alias `remaining` is computed rather than stored so it can never drift
     * from the two numbers it comes from.
     */
    /**
     * The ledger reference that identifies one side's referral bonus, forever.
     *
     * This is the idempotency key, and it is what stops a double credit even
     * when everything else is racing. `transaction.reference` carries a UNIQUE
     * index, so a second insert of the same reference is refused by the
     * database rather than by a check that two concurrent requests can both
     * pass. The value is derived only from the referral id and the side, so it
     * is stable across retries, replays and two admins clicking at once — and
     * it fits the 32-character column.
     *
     * `referrer` and `referee` are the two directions of the same payout, so
     * the side is part of the key: paying one side twice is just as wrong as
     * paying both twice, and the two must not collide.
     */
    public static function bonusReference(int $referralId, string $side): string
    {
        return sprintf('REFBONUS-%d-%s', $referralId, $side === 'referee' ? 'RF' : 'RR');
    }

    /**
     * Whether this side's bonus has already been written to the ledger.
     *
     * Read inside the payout transaction, so it is a question about committed
     * state rather than about what this request has seen. It is belt to the
     * unique index's braces: this gives the caller a clean "already paid"
     * answer, and the index is what makes the guarantee hold if two transactions
     * ever overlap.
     */
    public function bonusAlreadyPaid(int $referralId, string $side): bool
    {
        $exists = $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%transaction}} WHERE [[reference]] = :r',
            )
            ->bindValue(':r', self::bonusReference($referralId, $side))
            ->queryScalar();

        return (int) $exists > 0;
    }

    public function progressFor(int $refereeId): ?array
    {
        $row = $this->findByReferee($refereeId);
        if ($row === null) {
            return null;
        }

        $required = max(1, (int) ($row['required_count'] ?? 1));
        $completed = max(0, (int) ($row['completed_count'] ?? 0));

        $row['required_count'] = $required;
        $row['completed_count'] = $completed;
        $row['remaining'] = max(0, $required - $completed);
        $row['qualified'] = $completed >= $required;

        return $row;
    }

    public function findById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%referral}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();

        return $row === false ? null : $row;
    }

    /** The referral attached to this account, if any. */
    public function findByReferee(int $refereeId): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%referral}} WHERE [[referee_id]] = :id LIMIT 1')
            ->bindValue(':id', $refereeId)
            ->queryOne();

        return $row === false ? null : $row;
    }

    public function update(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%referral}}', $values, ['id' => $id])->execute();
    }

    /**
     * Move a referral to a new status only if it is still in `$from`.
     *
     * Returns true when this call is the one that changed the row. Every
     * money-moving path in ReferralService goes through here, so two admins
     * clicking "পরিশোধ" at the same moment, or a retried request, results in
     * exactly one payout — the loser is told the referral is no longer pending.
     */
    public function transition(int $id, string $from, string $to, array $values = []): bool
    {
        $values['status'] = $to;
        $values['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->db->createCommand()
            ->update('{{%referral}}', $values, ['id' => $id, 'status' => $from])
            ->execute();

        return $affected === 1;
    }

    /**
     * Referrals a user made, newest first.
     *
     * @return array{rows: array, total: int}
     */
    public function forReferrer(int $referrerId, int $page, int $perPage): array
    {
        $where = '{{%referral}}.[[referrer_id]] = :id';
        $params = [':id' => $referrerId];

        return $this->paged($where, $params, $page, $perPage);
    }

    /**
     * @return array{rows: array, total: int}
     */
    public function adminList(
        int $page,
        int $perPage,
        string $status = '',
        string $query = '',
        string $sort = 'created_at',
        string $dir = 'desc'
    ): array {
        $where = '1=1';
        $params = [];

        if ($status !== '') {
            $where .= ' AND {{%referral}}.[[status]] = :st';
            $params[':st'] = $status;
        }
        if ($query !== '') {
            $where .= ' AND ('
                . '{{%referral}}.[[code]] LIKE :q'
                . ' OR [[ru]].[[username]] LIKE :q'
                . ' OR [[fu]].[[username]] LIKE :q'
                . ' OR [[ru]].[[phone]] LIKE :q'
                . ' OR [[fu]].[[phone]] LIKE :q)';
            $params[':q'] = "%{$query}%";
        }

        return $this->paged($where, $params, $page, $perPage, $sort, $dir);
    }

    /**
     * Site-wide referral totals for the admin dashboard header.
     *
     * @return array{total:int,pending:int,paid:int,rejected:int,
     *               referrer_paid:float,referee_paid:float,paid_users:int}
     */
    public function stats(): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT'
                . ' COUNT(*) AS total,'
                . ' COALESCE(SUM([[status]] = :pending), 0) AS pending,'
                . ' COALESCE(SUM([[status]] = :paid), 0) AS paid,'
                . ' COALESCE(SUM([[status]] = :rejected), 0) AS rejected,'
                . ' COALESCE(SUM(CASE WHEN [[status]] = :paid THEN [[referrer_amount]] ELSE 0 END), 0) AS referrer_paid,'
                . ' COALESCE(SUM(CASE WHEN [[status]] = :paid THEN [[referee_amount]] ELSE 0 END), 0) AS referee_paid'
                . ' FROM {{%referral}}'
            )
            ->bindValues([':pending' => self::PENDING, ':paid' => self::PAID, ':rejected' => self::REJECTED])
            ->queryOne() ?: [];

        $paidUsers = (int) $this->db
            ->createCommand('SELECT COUNT(DISTINCT [[referrer_id]]) FROM {{%referral}} WHERE [[status]] = :p')
            ->bindValue(':p', self::PAID)
            ->queryScalar();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'paid' => (int) ($row['paid'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'referrer_paid' => (float) ($row['referrer_paid'] ?? 0),
            'referee_paid' => (float) ($row['referee_paid'] ?? 0),
            'paid_users' => $paidUsers,
        ];
    }

    /**
     * One user's own referral numbers.
     *
     * @return array{total:int,pending:int,paid:int,rejected:int,earned:float,pending_earned:float}
     */
    public function summaryFor(int $referrerId): array
    {
        $row = $this->db
            ->createCommand(
                'SELECT'
                . ' COUNT(*) AS total,'
                . ' COALESCE(SUM([[status]] = :pending), 0) AS pending,'
                . ' COALESCE(SUM([[status]] = :paid), 0) AS paid,'
                . ' COALESCE(SUM([[status]] = :rejected), 0) AS rejected,'
                . ' COALESCE(SUM(CASE WHEN [[status]] = :paid THEN [[referrer_amount]] ELSE 0 END), 0) AS earned,'
                . ' COALESCE(SUM(CASE WHEN [[status]] = :pending THEN [[referrer_amount]] ELSE 0 END), 0) AS pending_earned'
                . ' FROM {{%referral}} WHERE [[referrer_id]] = :id'
            )
            ->bindValues([':id' => $referrerId, ':pending' => self::PENDING, ':paid' => self::PAID, ':rejected' => self::REJECTED])
            ->queryOne() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'paid' => (int) ($row['paid'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'earned' => (float) ($row['earned'] ?? 0),
            'pending_earned' => (float) ($row['pending_earned'] ?? 0),
        ];
    }

    /** Row counts per status, for the admin filter chips. */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $rows = $this->db
            ->createCommand('SELECT [[status]], COUNT(*) AS n FROM {{%referral}} GROUP BY [[status]]')
            ->queryAll();

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * @return array{rows: array, total: int}
     */
    private function paged(string $where, array $params, int $page, int $perPage, string $sort = 'created_at', string $dir = 'desc'): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        $orderBy = (self::SORTABLE[$sort] ?? self::SORTABLE['created_at'])
            . ' ' . (strtolower($dir) === 'asc' ? 'ASC' : 'DESC')
            // Stable tiebreak so paging never repeats or skips a row when two
            // referrals share a created_at second.
            . ', {{%referral}}.[[id]] DESC';

        $from = '{{%referral}}'
            . ' INNER JOIN {{%user}} [[ru]] ON [[ru]].[[id]] = {{%referral}}.[[referrer_id]]'
            . ' INNER JOIN {{%user}} [[fu]] ON [[fu]].[[id]] = {{%referral}}.[[referee_id]]';

        $total = (int) $this->db->createCommand('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where)
            ->bindValues($params)
            ->queryScalar();

        $rows = $total === 0 ? [] : $this->db->createCommand('SELECT {{%referral}}.*, [[ru]].[[username]] AS referrer_name, [[fu]].[[username]] AS referee_name FROM ' . $from . ' WHERE ' . $where . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset)
            ->bindValues($params)
            ->queryAll();

        return ['rows' => $rows, 'total' => $total];
    }
}
