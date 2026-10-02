<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The referral ledger.
 *
 * One row per referred account, created the moment that account signs up with
 * a valid `?ref=` code and left `pending` until the friend's first recharge is
 * approved. The row — not the user table — is the record of what was promised,
 * which side has been paid and who signed off on it, so an admin can always
 * answer "why did this person get ৳50" from the ledger alone.
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
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
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
