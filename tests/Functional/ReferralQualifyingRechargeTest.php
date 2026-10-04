<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReferralRepository;
use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\LedgerService;
use App\Service\ReceiptStorage;
use App\Service\ReferralService;
use App\Service\TopupService;
use App\Tests\Support\TestGraph;
use Nyholm\Psr7\UploadedFile;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The referral rule: a *run* of qualifying recharges, not one recharge.
 *
 * ## What this file is really testing
 *
 * The programme used to pay both sides on the friend's first approved recharge.
 * That is a ৳50 bonus for one small top-up, which is a signup bonus wearing a
 * disguise. The rule now is: the friend must complete `required_count` approved
 * recharges, each of at least `min_amount`, and only then is either side paid.
 *
 * Every test drives the *real* `TopupService::approve()` rather than calling
 * `ReferralService::onTopupApproved()` directly. That matters more here than in
 * most files: the integration point is the whole claim. A test that calls the
 * trigger by hand proves the arithmetic and says nothing about whether approving
 * a recharge actually reaches it — which is exactly the wiring this change
 * depends on.
 *
 * ## Why the money assertions are as specific as they are
 *
 * `payManually()` exists as an admin escape hatch, and the old single-recharge
 * rule meant an admin could pay early and then have a recharge approved. That is
 * the double-credit the new idempotency key has to make impossible, so there is a
 * test for it. It asserts on balances *and* on the ledger rows, because a
 * balance can look right by accident while the transaction history is wrong, and
 * the history is what an auditor reads.
 *
 * Fixtures only, removed in `_after()`. Nothing here touches the real settings:
 * the two referral settings this feature adds are written and restored, since a
 * test that left the required count at 3 would quietly change what every other
 * test in the suite believes.
 */
final class ReferralQualifyingRechargeTest extends \Codeception\Test\Unit
{
    /** A valid 1x1 PNG, so the receipt passes the real finfo MIME sniff. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private static ?string $pngBytes = null;

    private const START_BALANCE = 1000.0;
    private const REFERRER_BONUS = 50.0;
    private const REFEREE_BONUS = 20.0;
    /** Comfortably above the ৳100 floor these tests run with. */
    private const QUALIFYING_AMOUNT = 250.0;
    /** Comfortably below it. */
    private const SMALL_AMOUNT = 50.0;

    private ConnectionInterface $db;
    private UserRepository $users;
    private SettingsRepository $settings;
    private ReferralRepository $referrals;
    private ReferralService $referralsService;
    private TopupService $topups;
    private TopupRepository $topupRepo;
    private TransactionRepository $ledgerRepo;

    /** @var int[] */
    private array $userIds = [];
    /** @var int[] */
    private array $topupIds = [];
    /** @var int[] */
    private array $referralIds = [];
    /** @var array<string, string> */
    private array $previousSettings = [];
    private string $suffix = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);

        $this->users = new UserRepository($this->db);
        $this->settings = new SettingsRepository($this->db);
        $this->referrals = new ReferralRepository($this->db);
        $this->ledgerRepo = new TransactionRepository($this->db);
        $ledger = TestGraph::ledger($this->db, $this->users);
        $notify = TestGraph::notify($this->db, $this->users, $this->settings);

        $this->referralsService = new ReferralService(
            $this->referrals,
            $this->users,
            $ledger,
            $this->settings,
            $notify,
            new ActivityLogRepository($this->db),
            $this->db,
        );

        $this->topupRepo = new TopupRepository($this->db);
        $this->topups = new TopupService(
            $this->topupRepo,
            $this->users,
            $ledger,
            new NotificationRepository($this->db),
            new ActivityLogRepository($this->db),
            $this->settings,
            new ReceiptStorage(sys_get_temp_dir() . '/refqual-receipts'),
            $notify,
            // The real referral service, so approving a recharge really does
            // reach the trigger under test.
            $this->referralsService,
        );

        $this->suffix = substr(md5(uniqid('', true)), 0, 10);

        // Pin the rule for the duration and put it back afterwards. Read first,
        // so a test that fails mid-way still restores the operator's values.
        $this->previousSettings = [
            'referral_enabled' => $this->settings->get('referral_enabled', ''),
            'referral_required_recharges' => $this->settings->get('referral_required_recharges', ''),
            'referral_min_qualifying_recharge' => $this->settings->get('referral_min_qualifying_recharge', ''),
            'referrer_bonus_amount' => $this->settings->get('referrer_bonus_amount', ''),
            'referee_bonus_amount' => $this->settings->get('referee_bonus_amount', ''),
        ];
        $this->settings->putMany([
            'referral_enabled' => '1',
            'referral_required_recharges' => '5',
            'referral_min_qualifying_recharge' => '100',
            'referrer_bonus_amount' => (string) self::REFERRER_BONUS,
            'referee_bonus_amount' => (string) self::REFEREE_BONUS,
        ], null);
    }

    protected function _after(): void
    {
        // Referrals before the users they point at: the FKs would refuse the
        // user delete otherwise.
        foreach ($this->referralIds as $id) {
            $this->db->createCommand()
                ->update('{{%referral}}', [
                    'referrer_transaction_id' => null,
                    'referee_transaction_id' => null,
                    'trigger_topup_id' => null,
                    'reviewed_by' => null,
                ], ['id' => $id])
                ->execute();
            $this->db->createCommand()->delete('{{%referral}}', ['id' => $id])->execute();
        }

        foreach ($this->userIds as $id) {
            // Ledger rows reference the user, and the bonus rows carry the
            // referral in their metadata rather than a column, so they are found
            // by user_id.
            $this->db->createCommand()->delete('{{%transaction}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%transaction}}', ['admin_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%topup_request}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%referral}}', ['referee_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%referral}}', ['referrer_id' => $id])->execute();
            $this->db->createCommand()->update('{{%user}}', ['referred_by' => null], ['id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }

        $this->settings->putMany($this->previousSettings, null);
    }

    // ---- Fixtures ---------------------------------------------------------

    private function makeUser(string $tag, string $role = 'user'): Identity
    {
        $id = $this->users->create([
            'username' => 'rq_' . $tag . '_' . $this->suffix,
            'phone' => '6' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'rq_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
            'balance' => self::START_BALANCE,
        ]);
        $this->users->update($id, ['free_searches' => 0]);
        $this->userIds[] = $id;

        return Identity::fromRow((array) $this->users->findById($id));
    }

    /** Attach a referral the way signup does, through the real service path. */
    private function referFriend(Identity $referrer, Identity $referee): array
    {
        $code = (string) $this->users->ensureReferralCode($referrer->id);
        assertTrue(
            $this->referralsService->attach($referee->id, $referrer->id, $code),
            'The referral must attach, or nothing after this is testing the rule.',
        );

        $row = $this->referrals->findByReferee($referee->id);
        assertNotNull($row);
        $this->referralIds[] = (int) $row['id'];

        return $row;
    }

    /**
     * Submit a recharge and approve it, through the real TopupService.
     *
     * @return array the approved topup row
     */
    private function approvedRecharge(Identity $user, string $tag, float $amount): array
    {
        $row = $this->submitRecharge($user, $tag, $amount);
        $topupId = (int) $row['id'];
        $this->topupIds[] = $topupId;

        [$approved] = $this->topups->approve($topupId, 1);
        assertTrue($approved, 'Recharge must be approvable');

        return (array) $this->topupRepo->findById($topupId);
    }

    /**
     * Submit a recharge the way the recharge page does — including the receipt,
     * which the `topup_receipt_required` setting insists on.
     *
     * @return array the pending topup row
     */
    private function submitRecharge(Identity $user, string $tag, float $amount): array
    {
        $ref = 'RQ' . strtoupper($this->suffix) . strtoupper($tag);

        [$ok, $message, $errors] = $this->topups->request($user->id, [
            'amount' => (string) $amount,
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'sender_name' => 'Test Sender',
            'reference' => $ref,
            'note' => '',
        ], $this->receiptUpload());

        assertTrue(
            $ok,
            'Recharge must be accepted: ' . $message . ' ' . json_encode($errors, JSON_UNESCAPED_UNICODE),
        );

        $row = $this->topupRepo->findByReference($ref);
        assertNotNull($row, 'The recharge must be stored under the reference we sent.');
        $this->topupIds[] = (int) $row['id'];

        return $row;
    }

    private function receiptUpload(string $name = 'receipt.png'): UploadedFile
    {
        $bytes = self::pngBytes();

        return new UploadedFile(
            $this->writeStream($bytes),
            strlen($bytes),
            UPLOAD_ERR_OK,
            $name,
            'image/png',
        );
    }

    private static function pngBytes(): string
    {
        if (self::$pngBytes === null) {
            $decoded = base64_decode(self::PNG_BASE64, true);
            if ($decoded === false) {
                throw new \RuntimeException('PNG fixture must be valid base64');
            }
            self::$pngBytes = $decoded;
        }

        return self::$pngBytes;
    }

    private function writeStream(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    private function balance(Identity $user): float
    {
        return (float) ((array) $this->users->findById($user->id))['balance'];
    }

    private function referralFor(Identity $referee): array
    {
        $row = $this->referrals->findByReferee($referee->id);
        assertNotNull($row, 'The referral row must exist');
        return $row;
    }

    /** How many ledger rows of this type credited this user. */
    private function bonusRows(Identity $user): int
    {
        return (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%transaction}}'
                . ' WHERE [[user_id]] = :u AND [[type]] = :t',
            )
            ->bindValues([':u' => $user->id, ':t' => TransactionRepository::TYPE_REFERRAL_BONUS])
            ->queryScalar();
    }

    // ---- The rule ---------------------------------------------------------

    /**
     * The headline behaviour: four of five does not pay, the fifth does, and
     * both sides are paid exactly once at the same moment.
     */
    public function testTheFifthQualifyingRechargePaysBothSidesExactlyOnce(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        $referrerBefore = $this->balance($referrer);
        $refereeBefore = $this->balance($referee);

        // Four qualifying recharges: progress moves, nothing is paid.
        for ($i = 1; $i <= 4; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);

            $row = $this->referralFor($referee);
            assertSame(
                $i,
                (int) $row['completed_count'],
                "After {$i} qualifying recharges the counter must read {$i}.",
            );
            assertSame(ReferralRepository::PENDING, (string) $row['status'], 'Still pending.');
        }

        // The referee has paid in four times, so only the *bonus* is missing.
        assertEquals($referrerBefore, $this->balance($referrer), 'No bonus before the run finishes.');
        assertEquals(
            $refereeBefore + 4 * self::QUALIFYING_AMOUNT,
            $this->balance($referee),
            'Four recharges credited, but no bonus on top of them.',
        );
        assertSame(0, $this->bonusRows($referrer));
        assertSame(0, $this->bonusRows($referee));

        // The fifth completes it.
        $winning = $this->approvedRecharge($referee, '5', self::QUALIFYING_AMOUNT);

        $row = $this->referralFor($referee);
        assertSame(ReferralRepository::PAID, (string) $row['status'], 'The fifth qualifying recharge pays.');
        assertSame(5, (int) $row['completed_count']);
        assertEquals(
            $referrerBefore + self::REFERRER_BONUS,
            $this->balance($referrer),
            'The referrer is paid the promised amount.',
        );
        assertEquals(
            $refereeBefore + 5 * self::QUALIFYING_AMOUNT + self::REFEREE_BONUS,
            $this->balance($referee),
            'The referee is paid the promised amount.',
        );
        assertEquals(self::REFERRER_BONUS, (float) $row['paid_referrer_amount'], 'What was credited is recorded.');
        assertEquals(self::REFEREE_BONUS, (float) $row['paid_referee_amount']);
        assertNotNull($row['qualified_at'], 'The moment of qualification is recorded.');

        // The winning recharge is traceable from the referral row.
        assertSame((int) $winning['id'], (int) $row['trigger_topup_id']);
        assertEquals(self::QUALIFYING_AMOUNT, (float) $row['trigger_amount']);
    }

    /** A sixth recharge must not pay a second time. */
    public function testRechargesAfterQualificationDoNotPayASecondTime(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        for ($i = 1; $i <= 5; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        $referrerAfter = $this->balance($referrer);
        $refereeAfter = $this->balance($referee);
        $rowsAfter = $this->bonusRows($referrer) + $this->bonusRows($referee);

        // Three more approved recharges. The run is already paid, so these are
        // ordinary recharges now.
        for ($i = 6; $i <= 8; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        assertEquals($referrerAfter, $this->balance($referrer), 'A paid referral is never paid again.');
        assertEquals(
            $refereeAfter + 3 * self::QUALIFYING_AMOUNT,
            $this->balance($referee),
            'The referee only got the three recharges, and no second bonus.',
        );
        assertSame($rowsAfter, $this->bonusRows($referrer) + $this->bonusRows($referee), 'No extra ledger rows.');
        assertSame(ReferralRepository::PAID, (string) $this->referralFor($referee)['status']);
    }

    // ---- What counts and what does not ------------------------------------

    /** A recharge under the floor does not move the run. */
    public function testARechargeBelowTheMinimumDoesNotCount(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        for ($i = 1; $i <= 4; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        // Well under the ৳100 floor, approved like any other recharge.
        $this->approvedRecharge($referee, 'small', self::SMALL_AMOUNT);

        $row = $this->referralFor($referee);
        assertSame(
            4,
            (int) $row['completed_count'],
            'An approved recharge below the floor is approved but does not qualify.',
        );
        assertSame(ReferralRepository::PENDING, (string) $row['status'], 'Four small-or-large recharges do not pay.');
        assertSame(0, $this->bonusRows($referrer));
    }

    /**
     * Only `approved` counts. A rejected recharge is a refusal and a pending one
     * has not happened yet, so neither may move the run — and because the counter
     * is derived from the recharge table rather than incremented blindly, the
     * five *approved* recharges still complete it afterwards.
     */
    public function testOnlyApprovedRechargesCount(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        // Two rejected recharges, then one left sitting in the queue. Only one
        // open request per account is allowed, which is the product's own
        // guard, so they have to be rejected one at a time to make room.
        for ($i = 1; $i <= 2; $i++) {
            $row = $this->submitRecharge($referee, 'x' . $i, self::QUALIFYING_AMOUNT);
            [$rejected] = $this->topups->reject((int) $row['id'], 1, 'not paid');
            assertTrue($rejected, 'A rejected recharge is a decision, not a qualify.');
        }
        $pending = $this->submitRecharge($referee, 'pend', self::QUALIFYING_AMOUNT);

        $this->referralsService->onTopupApproved($referee->id, 0, self::QUALIFYING_AMOUNT);

        assertSame(
            0,
            (int) $this->referralFor($referee)['completed_count'],
            'Two rejected and one pending recharge are not three recharges.',
        );

        // Clear the queue so the qualifying run below can be submitted at all.
        [$cancelled] = $this->topups->cancel((int) $pending['id'], $referee->id);
        assertTrue($cancelled, 'The user cancels their own pending recharge.');

        // Now five genuinely approved ones finish it.
        for ($i = 1; $i <= 5; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        assertSame(ReferralRepository::PAID, (string) $this->referralFor($referee)['status']);
    }

    // ---- Idempotency ------------------------------------------------------

    /**
     * The double-credit the whole design is built to prevent.
     *
     * An admin pays a referral early as a goodwill gesture, and then the friend
     * completes the run. Both paths want to credit the same two balances, and
     * the only thing standing between that and a second ৳50 each is the
     * deterministic ledger reference.
     */
    public function testAManualPayoutIsNotCreditedAgainByALaterRecharge(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $referral = $this->referFriend($referrer, $referee);

        $referrerBefore = $this->balance($referrer);
        $refereeBefore = $this->balance($referee);

        [$ok] = $this->referralsService->payManually((int) $referral['id'], 1, 'goodwill');
        assertTrue($ok, 'A manual payout must work on a pending referral.');
        assertSame(ReferralRepository::PAID, (string) $this->referralFor($referee)['status']);

        $afterManual = $this->balance($referrer);
        $rowsAfterManual = $this->bonusRows($referrer);

        // The friend then finishes the run. `onTopupApproved()` still runs — it
        // is wired into every approval — so this is the exact call that would
        // pay a second time if the guard were not there.
        for ($i = 1; $i <= 5; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        assertEquals($afterManual, $this->balance($referrer), 'The referrer is not paid twice.');
        assertSame(
            $rowsAfterManual,
            $this->bonusRows($referrer),
            'Not one extra ledger row may appear for a referral already paid.',
        );
        assertGreaterThan(0, $afterManual - $referrerBefore, 'The manual payout really did pay once.');
    }

    /**
     * Replaying the trigger on an already-paid referral changes nothing.
     *
     * This is the same guard as above seen from the other side: the call is made
     * directly rather than through approvals, so nothing can be hiding behind
     * the approval flow being idempotent.
     */
    public function testReplayingTheTriggerOnAPaidReferralPaysNothing(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        for ($i = 1; $i <= 5; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        $referrerBalance = $this->balance($referrer);
        $refereeBalance = $this->balance($referee);

        for ($i = 0; $i < 3; $i++) {
            [$paid] = $this->referralsService->onTopupApproved($referee->id, 999, self::QUALIFYING_AMOUNT);
            assertFalse($paid, 'A paid referral must never report a fresh payout.');
        }

        assertEquals($referrerBalance, $this->balance($referrer));
        assertEquals($refereeBalance, $this->balance($referee));
        assertSame(1, $this->bonusRows($referrer), 'Exactly one referrer bonus row, ever.');
        assertSame(1, $this->bonusRows($referee), 'Exactly one referee bonus row, ever.');
    }

    /** Both sides' ledger references are distinct and stable. */
    public function testTheTwoSidesGetDistinctStableReferences(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        for ($i = 1; $i <= 5; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        $references = $this->db
            ->createCommand(
                'SELECT [[reference]] FROM {{%transaction}} WHERE [[type]] = :t'
                . ' AND [[user_id]] IN (:a, :b)',
            )
            ->bindValues([
                ':t' => TransactionRepository::TYPE_REFERRAL_BONUS,
                ':a' => $referrer->id,
                ':b' => $referee->id,
            ])
            ->queryColumn();

        $references = array_map('strval', (array) $references);
        assertSame(2, count($references), 'Exactly two bonus rows for a two-sided payout.');
        assertSame(
            count($references),
            count(array_unique($references)),
            'The two sides must not collide on the unique reference.',
        );

        // And they are the documented, derivable keys — not random strings, so
        // a support ticket can be traced to them without a database query.
        $referralId = (int) $this->referralFor($referee)['id'];
        foreach ($references as $reference) {
            assertTrue(
                $reference === ReferralRepository::bonusReference($referralId, 'referrer')
                || $reference === ReferralRepository::bonusReference($referralId, 'referee'),
                'Unexpected reference: ' . $reference,
            );
        }
    }

    // ---- Snapshots and configuration --------------------------------------

    /**
     * The rule is snapshotted onto the referral when it is created.
     *
     * An operator raising the requirement from 5 to 10 must not strand a friend
     * who is halfway through a run of five — that is a promise already made.
     */
    public function testTheRuleIsSnapshottedAtSignupNotReadAtPayoutTime(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $referral = $this->referFriend($referrer, $referee);

        assertSame(5, (int) $referral['required_count'], 'The rule in force at signup is stored.');
        assertEquals(100.0, (float) $referral['min_amount'], 'The floor in force at signup is stored.');

        $this->approvedRecharge($referee, '1', self::QUALIFYING_AMOUNT);
        $this->approvedRecharge($referee, '2', self::QUALIFYING_AMOUNT);

        // The operator tightens the rule for everyone from now on.
        $this->settings->putMany([
            'referral_required_recharges' => '10',
            'referral_min_qualifying_recharge' => '500',
        ], null);

        assertSame(
            10,
            $this->referralsService->requiredRecharges(),
            'New referrals get the new rule.',
        );

        // Three more approvals finish the *snapshot* run of five, even though
        // the live rule now demands ten recharges of ৳500.
        for ($i = 3; $i <= 5; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        assertSame(
            ReferralRepository::PAID,
            (string) $this->referralFor($referee)['status'],
            'A referral in flight finishes on the terms it was created with.',
        );
    }

    /** A requirement of 0 or a nonsense value falls back to the default. */
    public function testANonsensicalRequirementFallsBackToTheDefault(): void
    {
        $this->settings->putMany(['referral_required_recharges' => '0'], null);
        assertSame(5, $this->referralsService->requiredRecharges(), 'Zero would qualify at signup.');

        $this->settings->putMany(['referral_required_recharges' => 'not-a-number'], null);
        assertSame(5, $this->referralsService->requiredRecharges(), 'Junk is not a rule.');

        $this->settings->putMany(['referral_required_recharges' => '3'], null);
        assertSame(3, $this->referralsService->requiredRecharges(), 'A legitimate value is honoured.');
    }

    /** A floor of 0 means any approved recharge counts, which is allowed. */
    public function testAZeroFloorCountsEveryApprovedRecharge(): void
    {
        $this->settings->putMany([
            'referral_required_recharges' => '3',
            'referral_min_qualifying_recharge' => '0',
        ], null);

        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        for ($i = 1; $i <= 3; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::SMALL_AMOUNT);
        }

        assertSame(
            ReferralRepository::PAID,
            (string) $this->referralFor($referee)['status'],
            'With no floor, three small approved recharges are three qualifying ones.',
        );
    }

    /** One referral per account, and the attribution lock survives a second code. */
    public function testAnAccountCanOnlyBeReferredOnce(): void
    {
        $first = $this->makeUser('ref1');
        $second = $this->makeUser('ref2');
        $referee = $this->makeUser('fri');

        $this->referFriend($first, $referee);

        assertFalse(
            $this->referralsService->attach($referee->id, $second->id, (string) $this->users->ensureReferralCode($second->id)),
            'A second code must not overwrite the first attribution.',
        );

        $row = $this->referralFor($referee);
        assertSame(
            $first->id,
            (int) $row['referrer_id'],
            'The first referrer keeps the referral and its eventual bonus.',
        );
    }

    /** The user's own progress is readable, which is what the page renders. */
    public function testProgressIsReadableForTheUserFacingPage(): void
    {
        $referrer = $this->makeUser('ref');
        $referee = $this->makeUser('fri');
        $this->referFriend($referrer, $referee);

        for ($i = 1; $i <= 4; $i++) {
            $this->approvedRecharge($referee, (string) $i, self::QUALIFYING_AMOUNT);
        }

        $progress = $this->referrals->progressFor($referee->id);
        assertNotNull($progress);
        assertSame(4, $progress['completed_count'], 'The page shows four of five.');
        assertSame(1, $progress['remaining'], 'And one recharge still to go.');
        assertFalse($progress['qualified'], 'Four of five is not qualified.');

        $this->approvedRecharge($referee, '5', self::QUALIFYING_AMOUNT);

        $progress = $this->referrals->progressFor($referee->id);
        assertNotNull($progress);
        assertSame(0, $progress['remaining']);
        assertTrue($progress['qualified']);
    }

    /** An account with no referral has no progress, and that is not an error. */
    public function testAnAccountWithNoReferralHasNoProgress(): void
    {
        $stranger = $this->makeUser('solo');
        assertSame(null, $this->referrals->progressFor($stranger->id));

        // And approving a recharge for them is a no-op, not a failure.
        $this->approvedRecharge($stranger, 'x', self::QUALIFYING_AMOUNT);
        [$paid] = $this->referralsService->onTopupApproved($stranger->id, 1, self::QUALIFYING_AMOUNT);
        assertFalse($paid, 'A referral trigger for a non-referred account is a no-op.');
    }
}