<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\LedgerService;
use App\Service\ServiceManager;
use App\Service\StatusPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Fills the order queue with demo requests, so a fresh install has something in
 * the pages that need a history.
 *
 * `app:seed` builds the catalog and the accounts but stops at the queue: it has
 * no orders, so /service-history, /admin/orders and the dashboard counters are
 * all empty on a new database and each of them looks broken rather than new.
 * This command is the other half.
 *
 * Rows are written through the same collaborators the HTTP path uses —
 * `ServiceOrderRepository`, `LedgerService`, the mock providers and
 * `ServiceManager` itself — so a seeded order is indistinguishable from a placed
 * one: it holds the metadata shape `ServiceManager::submit()` writes, its debit
 * is a real ledger entry with `balance_before`/`balance_after` either side of
 * it, and a completed order has an `approved_by` operator credited exactly as
 * `ServiceRequestAdminService::approve()` would have credited them. That is the
 * whole reason nothing is inserted directly here: an order with no ledger row
 * cannot be reconciled against a balance, and a completed order with no
 * operator credit hides revenue the earnings page then reports as missing.
 *
 * Statuses are dealt out in a fixed cycle rather than picked at random, so even
 * a small `--count` produces a queue with something in every column of the admin
 * filter, and `--count` never has to be tuned to reach a steady state.
 *
 *   php yii app:seed-orders                    # 12 orders across 14 days
 *   php yii app:seed-orders --count=30
 *   php yii app:seed-orders --count=6 --days=3
 *   php yii app:seed-orders --user=rahim.demo  # one account only
 *   php yii app:seed-orders --dry-run
 *
 * Re-running is safe and additive: it never edits or removes an existing order,
 * it only adds new ones with fresh references.
 */
#[AsCommand('app:seed-orders', 'Seeds demo service orders (queue + ledger + operator earnings).')]
final class OrderSeedCommand extends Command
{
    /**
     * The statuses handed out, in order, repeated as far as `--count` reaches.
     *
     * `review` is spelled as a literal because it has no `StatusPresenter`
     * constant — it is the claim marker `ServiceOrderRepository::OPEN_STATUSES`
     * carries, not a state a user-facing service request can be filtered by.
     *
     * `completed` appears twice because it is the one status that earns money:
     * it is what the operator's balance is built from and what the dashboard
     * counts as a success.
     *
     * @var list<string>
     */
    private const STATUS_CYCLE = [
        StatusPresenter::COMPLETED,
        StatusPresenter::PENDING,
        'review',
        StatusPresenter::PROCESSING,
        StatusPresenter::COMPLETED,
        StatusPresenter::FAILED,
        StatusPresenter::CANCELLED,
        StatusPresenter::PENDING,
    ];

    /** How many distinct customer accounts the orders are spread across. */
    private const CUSTOMER_POOL = 5;

    /** What a short wallet is topped up to, so there is still money left afterwards. */
    private const TOPUP_FLOOR = 250.0;

    /**
     * Plausible inputs per provider key.
     *
     * Feeding the provider its own sample input (rather than a hand-written
     * result) is what makes the stored payload the shape `start()` would have
     * written, `_demo` and `_notice` markers included.
     *
     * @var array<string, list<array<string, string>>>
     */
    private const SAMPLE_INPUTS = [
        'mock-nid' => [
            ['nid_number' => '1990123456789', 'date_of_birth' => '1990-12-31'],
            ['nid_number' => '1987543210987', 'date_of_birth' => '1987-04-14'],
            ['nid_number' => '2001567890123', 'date_of_birth' => '2001-06-09'],
        ],
        'mock-voter' => [
            ['district' => 'ঢাকা', 'upazila' => 'ধামরাই', 'full_name' => 'রহিম উদ্দিন'],
            ['district' => 'চট্টগ্রাম', 'upazila' => 'পটিয়া', 'full_name' => 'করিম মিয়া'],
            ['district' => 'সিলেট', 'upazila' => 'গোলাপগঞ্জ', 'full_name' => 'আয়েশা বেগম'],
        ],
        'mock-tin' => [
            ['tin_number' => '123456789012'],
            ['tin_number' => '987654321098'],
            ['tin_number' => '456789123047'],
        ],
    ];

    private const ADMIN_NOTE = 'ডেমো অর্ডার — app:seed-orders';

    private const CANCEL_REASON = 'ডেমো — ব্যবহারকারী নিজে বাতিল করেছেন';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ServiceOrderRepository $orders,
        private readonly UserRepository $users,
        private readonly LedgerService $ledger,
        private readonly ServiceManager $manager,
        private readonly ActivityLogRepository $logs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many orders to create. Default: 12.')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Spread them over this many days in the past. Default: 14.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Seed for this username only. Default: several accounts.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan without writing anything.')
            ->setHelp(
                "Fills the order queue with demo requests.\n\n"
                . "Each order is written the way the site writes one — through the order\n"
                . "repository, the ledger and the mock providers — so afterwards the\n"
                . "balances, the admin queue and operator earnings all agree with each\n"
                . "other. A customer who cannot afford an order is topped up first, and\n"
                . "that recharge is a ledger entry too.\n\n"
                . "Timestamps are spread across the past --days so the history pages have a\n"
                . "range rather than one minute's worth of rows. The command is additive:\n"
                . "re-running adds orders and never edits or removes existing ones."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = max(1, (int) ($input->getOption('count') ?? 12));
        $days = max(0, (int) ($input->getOption('days') ?? 14));
        $dryRun = (bool) $input->getOption('dry-run');

        $services = $this->activeServices();
        if ($services === []) {
            $io->error('No active mock services in the catalogue. Run `php yii app:seed` first.');
            return Command::FAILURE;
        }

        $customers = $this->customers((string) ($input->getOption('user') ?? ''));
        if ($customers === []) {
            $io->error(
                'No usable customer accounts. `php yii app:seed` creates one (rahim.demo) '
                . 'when the user table is empty.',
            );
            return Command::FAILURE;
        }

        $operators = $this->operators();

        $io->title($dryRun ? 'Dry run — no rows will be written' : 'Seeding demo orders');
        $io->definitionList(
            ['orders' => (string) $count],
            ['accounts' => implode(', ', array_column($customers, 'username'))],
            ['services' => count($services) . ' active'],
            ['operators' => $operators === [] ? '(none)' : implode(', ', array_column($operators, 'username'))],
            ['window' => $days === 0 ? 'now' : sprintf('last %d days', $days)],
        );

        if ($operators === []) {
            $io->warning(
                'No active operator account, so no order can be approved and credited. '
                . 'Completed orders in this run will be left pending. Create one with '
                . '`php yii app:super-admin` or `php yii app:staff`.',
            );
        }

        $plan = $this->plan($count, $days, $services, $customers);
        $io->table(
            ['status', 'service', 'user', 'amount'],
            array_map(
                static fn (array $step): array => [
                    $step['status'],
                    $step['service_name'],
                    $step['username'],
                    number_format((float) $step['price'], 2),
                ],
                $plan,
            ),
        );

        if ($dryRun) {
            $io->note('Nothing was written. Re-run without --dry-run to create these orders.');
            return Command::SUCCESS;
        }

        $byStatus = [];
        $topups = 0;
        $skipped = 0;
        foreach ($plan as $step) {
            $outcome = $this->write($step, $operators);
            if ($outcome === null) {
                $io->warning(sprintf(
                    'Skipped %s for %s — the wallet would not take the charge.',
                    (string) $step['service_name'],
                    (string) $step['username'],
                ));
                $skipped++;
                continue;
            }

            $byStatus[$outcome['status']] = ($byStatus[$outcome['status']] ?? 0) + 1;
            if ($outcome['topup_id'] !== null) {
                $topups++;
            }
        }

        $io->success(sprintf(
            '%d order(s) created.%s',
            array_sum($byStatus),
            $skipped === 0 ? '' : sprintf(' %d skipped.', $skipped),
        ));

        $summary = array_map(
            static fn (string $status, int $n): string => sprintf('%d %s', $n, StatusPresenter::label($status)),
            array_keys($byStatus),
            array_values($byStatus),
        );
        $io->text([
            $summary === [] ? 'Nothing to summarise.' : 'Statuses: ' . implode(', ', $summary) . '.',
            sprintf('%d demo recharge(s) written so the customers could pay for their orders.', $topups),
            'Look at them at /service-history (as a customer) and /admin/orders (as an operator).',
            'Balances, the ledger and operator earnings all agree with these orders.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Every active, undeleted service, ordered so even a low `--count` walks the
     * catalogue instead of hammering whichever row happens to be first.
     *
     * @return list<array<string, mixed>>
     */
    private function activeServices(): array
    {
        return $this->db
            ->createCommand(
                "SELECT * FROM {{%service}} WHERE [[status]] = 'active' AND [[deleted_at]] IS NULL"
                . " AND [[service_type]] = 'mock' ORDER BY [[sort_order]] ASC, [[id]] ASC",
            )
            ->queryAll();
    }

    /**
     * The accounts the demo orders are placed by.
     *
     * Restricted to `role = 'user'` and `status = 'active'` because both are
     * checked again inside `LedgerService::move()`: a charge against a
     * deactivated or trashed account is refused there, which would leave a queue
     * entry nobody can pay for and nothing to cancel a refund against.
     *
     * @return list<array<string, mixed>>
     */
    private function customers(string $username): array
    {
        if ($username !== '') {
            $user = $this->users->findByIdentifier($username);
            if ($user === null || (string) $user['status'] !== 'active' || $user['deleted_at'] !== null) {
                return [];
            }

            return [$user];
        }

        return $this->db
            ->createCommand(
                "SELECT * FROM {{%user}} WHERE [[role]] = 'user' AND [[status]] = 'active'"
                . ' AND [[deleted_at]] IS NULL ORDER BY [[id]] ASC LIMIT ' . self::CUSTOMER_POOL,
            )
            ->queryAll();
    }

    /**
     * Accounts an order can be approved by, i.e. the ones whose earnings grow.
     *
     * Same `status`/`deleted_at` filter as {@see customers()}, for the same
     * reason: `creditAdmin()` refuses an inactive owner.
     *
     * @return list<array<string, mixed>>
     */
    private function operators(): array
    {
        return $this->db
            ->createCommand(
                "SELECT * FROM {{%user}} WHERE [[role]] IN ('admin','staff','superadmin')"
                . " AND [[status]] = 'active' AND [[deleted_at]] IS NULL ORDER BY [[id]] ASC",
            )
            ->queryAll();
    }

    /**
     * The orders to write, oldest first.
     *
     * Chronological because the ledger is read as a sequence: `balance_before`
     * and `balance_after` only mean anything if the entries run in the order the
     * money actually moved. The backdating pass rewrites `created_at` afterwards
     * without touching those columns, so the order of the writes has to be the
     * order of the timestamps in the first place.
     *
     * @param list<array<string, mixed>> $services
     * @param list<array<string, mixed>> $customers
     * @return list<array<string, mixed>>
     */
    private function plan(int $count, int $days, array $services, array $customers): array
    {
        $plan = [];
        $serviceCount = count($services);
        $customerCount = count($customers);
        $stride = $days > 0 ? max(1, (int) floor(($days * 86400) / $count)) : 0;
        $start = $days > 0 ? time() - ($days * 86400) : time();

        for ($i = 0; $i < $count; $i++) {
            $service = $services[$i % $serviceCount];
            $customer = $customers[$i % $customerCount];
            $provider = $this->manager->providerFor($service);
            if ($provider === null) {
                continue;
            }

            $variants = ServiceManager::variantsFor($service);
            $variant = $variants === [] ? null : $variants[$i % count($variants)];
            $pool = self::SAMPLE_INPUTS[$provider->key()] ?? [['reference' => 'DEMO-' . $i]];

            $plan[] = [
                'user_id' => (int) $customer['id'],
                'username' => (string) $customer['username'],
                'service' => $service,
                'service_name' => (string) $service['name'],
                'provider_key' => $provider->key(),
                'variant' => $variant,
                'price' => $variant !== null ? (float) $variant['price'] : (float) $service['price'],
                'input' => $pool[$i % count($pool)],
                'status' => self::STATUS_CYCLE[$i % count(self::STATUS_CYCLE)],
                'at' => date('Y-m-d H:i:s', $start + ($stride * $i)),
            ];
        }

        return $plan;
    }

    /**
     * Write one order and everything that hangs off it.
     *
     * The order row goes in first, exactly as `ServiceManager::submit()` does it,
     * so the debit that follows has a `service_order_id` to point at — a charge
     * with no order reference is money that vanished.
     *
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $operators
     * @return array{status: string, topup_id: int|null}|null
     *         null when the transition could not be made and the row is unusable
     */
    private function write(array $step, array $operators): ?array
    {
        $service = $step['service'];
        $userId = (int) $step['user_id'];
        $price = (float) $step['price'];
        $reference = $this->reference();

        $topupId = $price > 0 && (float) $this->balanceOf($userId) < $price
            ? $this->topUp($userId, $price)
            : null;

        $orderId = $this->orders->create([
            'user_id' => $userId,
            'service_id' => (int) $service['id'],
            'reference' => $reference,
            'amount' => $price,
            'status' => StatusPresenter::PENDING,
            'metadata' => [
                'provider' => (string) $step['provider_key'],
                'input_keys' => array_keys((array) $step['input']),
                // Kept so a retry can re-run the provider without the form again.
                'input' => (array) $step['input'],
                'service_name' => (string) $step['service_name'],
                'variant' => $step['variant']['label'] ?? null,
                'free_search' => false,
            ],
        ]);

        if ($price > 0) {
            [$charged] = $this->ledger->debitUser($userId, $price, [
                'type' => TransactionRepository::TYPE_ORDER_DEBIT,
                'service_order_id' => $orderId,
                'description' => sprintf('সার্ভিস অর্ডার %s', $reference),
                'metadata' => ['service_name' => (string) $step['service_name']],
            ]);
            if (!$charged) {
                $this->orders->update($orderId, ['status' => StatusPresenter::CANCELLED]);
                return null;
            }
        }

        $status = $this->settle($orderId, $step, $price, $operators);
        if ($status === null) {
            return null;
        }

        $this->logs->create([
            'user_id' => $userId,
            'action' => 'service.seeded',
            'description' => sprintf('%s requested (%s)', (string) $step['service_name'], $reference),
            'ip_address' => null,
            'user_agent' => 'cli',
            'metadata' => ['order_id' => $orderId, 'status' => $status, 'source' => 'app:seed-orders'],
        ]);

        $this->backdate($orderId, $topupId, (string) $step['at']);

        return ['status' => $status, 'topup_id' => $topupId];
    }

    /**
     * Move a freshly-charged order to the status the plan asked for.
     *
     * Each branch is the transition the admin flow performs, so the seeded queue
     * stays claimable, approvable and refundable exactly as a real one is:
     * `claimOrder()` for the two states only a named operator can be in,
     * `markApproved()` for the decision that pays out, and a `creditUser()`
     * refund on the two edges that give the money back.
     *
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $operators
     * @return string|null the settled status, or null when a transition was refused
     */
    private function settle(int $orderId, array $step, float $price, array $operators): ?string
    {
        $status = (string) $step['status'];
        $operator = $operators === [] ? null : $operators[array_rand($operators)];

        if ($status === 'review') {
            return $operator === null ? StatusPresenter::PENDING
                : ($this->orders->claimOrder($orderId, (int) $operator['id']) ? 'review' : null);
        }

        if ($status === StatusPresenter::PROCESSING) {
            $this->orders->setStatus($orderId, StatusPresenter::PROCESSING, [
                'result' => $this->demoResult($step),
            ]);
            return StatusPresenter::PROCESSING;
        }

        if ($status === StatusPresenter::COMPLETED) {
            if ($operator === null) {
                return StatusPresenter::PENDING;
            }
            // Claim first: `markApproved()` only accepts an open row, and
            // claiming is what makes the queue show this one as somebody's work.
            if (!$this->orders->claimOrder($orderId, (int) $operator['id'])) {
                return null;
            }
            if (!$this->orders->markApproved($orderId, (int) $operator['id'], StatusPresenter::COMPLETED)) {
                return null;
            }

            $this->orders->setStatus($orderId, StatusPresenter::COMPLETED, [
                'result' => $this->demoResult($step),
                'admin_note' => self::ADMIN_NOTE,
            ]);
            if ($price > 0) {
                $this->ledger->creditAdmin((int) $operator['id'], $price, [
                    'type' => TransactionRepository::TYPE_ORDER_CREDIT,
                    'service_order_id' => $orderId,
                    'description' => sprintf('অর্ডার #%d (%s) অনুমোদন', $orderId, (string) $step['reference']),
                    'metadata' => ['order_reference' => (string) $step['reference']],
                ]);
            }

            return StatusPresenter::COMPLETED;
        }

        if ($status === StatusPresenter::FAILED) {
            $this->orders->setStatus($orderId, StatusPresenter::FAILED, [
                'attempts' => 1,
                'error' => self::ADMIN_NOTE,
            ]);
            $this->refund($step, $orderId, $price, 'ব্যর্থ');
            return StatusPresenter::FAILED;
        }

        if ($status === StatusPresenter::CANCELLED) {
            $this->orders->update($orderId, [
                'status' => StatusPresenter::CANCELLED,
                'cancel_reason' => self::CANCEL_REASON,
            ]);
            $this->refund($step, $orderId, $price, 'বাতিল');
            return StatusPresenter::CANCELLED;
        }

        return StatusPresenter::PENDING;
    }

    /**
     * Give the charged amount back, which is what makes a failed or cancelled
     * order retryable without the customer topping up again.
     *
     * @param array<string, mixed> $step
     */
    private function refund(array $step, int $orderId, float $price, string $why): void
    {
        if ($price <= 0) {
            return;
        }

        $this->ledger->creditUser((int) $step['user_id'], $price, [
            'type' => TransactionRepository::TYPE_ORDER_REFUND,
            'service_order_id' => $orderId,
            'description' => sprintf('অর্ডার %s %s — টাকা ফেরত', (string) $step['reference'], $why),
        ]);
    }

    /**
     * Credit the customer so the order above is affordable.
     *
     * A real recharge is a verified bKash/Nagad entry; this is the same ledger
     * row written from a shell, which is what keeps `balance_before` and
     * `balance_after` continuous across the debit that follows it.
     *
     * @return int|null the ledger entry id, for the backdating pass
     */
    private function topUp(int $userId, float $price): ?int
    {
        [$credited, , , $entryId] = $this->ledger->creditUser($userId, max(self::TOPUP_FLOOR, $price), [
            'type' => TransactionRepository::TYPE_TOPUP,
            'description' => 'ডেমো রিচার্জ (app:seed-orders)',
        ]);

        return $credited ? $entryId : null;
    }

    /**
     * The stored result of a mock run, so a completed or processing order opens
     * a populated "view result" panel instead of an empty one.
     *
     * Run through the provider itself rather than hand-written, so the payload is
     * the shape `ServiceManager::start()` would have stored.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function demoResult(array $step): array
    {
        $provider = $this->manager->providers()[(string) $step['provider_key']] ?? null;
        if ($provider === null) {
            return [];
        }

        $executed = $provider->execute((array) $step['input']);

        return $executed->success ? $executed->data : [];
    }

    /**
     * Rewrite `created_at` on the rows just written so the history pages have a
     * range instead of one minute's worth of rows.
     *
     * `balance_before`/`balance_after` are deliberately left alone: they are
     * facts about the sequence the money really moved in, and rewriting them to
     * match a fabricated timestamp would make the ledger disagree with itself.
     *
     * @param int|null $topupId the recharge entry that preceded this order, if any
     */
    private function backdate(int $orderId, ?int $topupId, string $at): void
    {
        $this->db
            ->createCommand()
            ->update('{{%service_order}}', ['created_at' => $at, 'updated_at' => $at], ['id' => $orderId])
            ->execute();

        $this->db
            ->createCommand('UPDATE {{%transaction}} SET [[created_at]] = :at, [[updated_at]] = :at WHERE [[service_order_id]] = :id')
            ->bindValues([':at' => $at, ':id' => $orderId])
            ->execute();

        if ($topupId !== null) {
            $this->db
                ->createCommand('UPDATE {{%transaction}} SET [[created_at]] = :at, [[updated_at]] = :at WHERE [[id]] = :id')
                ->bindValues([':at' => $at, ':id' => $topupId])
                ->execute();
        }
    }

    /**
     * A reference in the site's own format, guaranteed unused.
     *
     * The UNIQUE index on `reference` turns a collision into a hard error rather
     * than a quietly overwritten order, so the value is checked rather than
     * assumed unique — two runs inside the same second would otherwise produce
     * the same `dechex(time())` prefix.
     */
    private function reference(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'ALD' . strtoupper(dechex(time())) . random_int(1000, 9999);
            $taken = $this->db
                ->createCommand('SELECT 1 FROM {{%service_order}} WHERE [[reference]] = :r LIMIT 1')
                ->bindValue(':r', $candidate)
                ->queryScalar();
            if ($taken === false) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a free order reference in 10 attempts.');
    }

    /** The account's spendable balance, as the ledger would read it. */
    private function balanceOf(int $userId): float
    {
        $user = $this->users->findById($userId);

        return $user === null ? 0.0 : (float) $user['balance'];
    }
}