<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;

/**
 * The admin services page, with a whole selection handled at once: switch
 * services on or off, move them to the trash, bring them back, or purge them
 * for good.
 *
 * A service list is the one admin list where this is obvious. Turning off a
 * service that is being abused is one click per row today, which means an
 * operator mid-incident is still on click three of nine — and the batch that
 * never finishes is the batch nobody remembers. The same is true of the
 * seasonal case: forty services to deactivate every evening and reactivate
 * every morning.
 *
 * It is deliberately *not* the same shape as `ServiceRequestAdminService::settleMany()`,
 * and the difference is worth stating, because it decides what this service is
 * allowed to do:
 *
 * - A settle moves money, so each row carries a refund decision and a
 *   notification. It was worth a queue (see `BulkJobService`) to keep a hundred
 *   of those out of a web request. A bulk action here is at worst one UPDATE per
 *   row against a table with tens of rows, so a queue would add a progress bar
 *   to a thing that takes milliseconds — and, worse, would mean the operator
 *   could not tell from the page whether the batch had landed.
 * - This is not a queue either, so a partial failure is possible in principle.
 *   It is bounded instead: every row is idempotent (see below), so re-running a
 *   batch whose response was lost settles what is left and reports the rest as
 *   unchanged. Nothing here can be paid twice or deleted twice.
 *
 * The guard rules are the single-row path's, deliberately duplicated rather than
 * shared. `AdminServicesAction` sets a flash per click and reads a session per
 * row; threading that through a loop would give a batch of nine flashes where
 * nine only one is true. The rules themselves are three lines each and are
 * asserted on both sides in `AdminServicesBulkTest`, which is what keeps the
 * copies honest — a shared helper that both callers must each still remember to
 * call would be a worse arrangement than two copies with a test between them.
 *
 * Idempotence is the property that makes a batch safe to retry. `softDelete()`
 * only fires on a row whose `deleted_at IS NULL`, `restore()` only on a row that
 * has one, and the status write skips a row already in the target status. So a
 * repeated run reports "already there" instead of moving a row twice.
 *
 * Two things arrived after the first version, both answering the same
 * complaint — that a batch is only as good as the selection it was given.
 *
 * **"Everything matching this filter"** (`SCOPE_FILTERED`) exists because a
 * selection carried as a list of ids is a selection with a ceiling: the page
 * renders the whole table, so past a few hundred rows the only way to act on
 * all of them is to submit a request the bar did not draw, and an operator
 * mid-cleanup should not have to think about the transport. The scope sends the
 * *filter* instead of the ids and resolves them here, against the same
 * `ServiceRepository::adminFiltered()` the list was rendered from — one
 * definition of "matching", so the bar can never act on rows the admin cannot
 * see. `BULK_LIMIT` stays for explicit id lists, where the ceiling is the
 * defence against a hand-written POST rather than a product decision.
 *
 * **Undo** (`undo()`) exists because the bar's two worst buttons are one click
 * apart from each other and both sit under a selection the admin built by eye.
 * The snapshot is written per batch, to the session, and consumed once: it is
 * not a trash bin, it is the space to put your hands back. It deliberately
 * covers only the rows the batch actually moved — undoing the rows it reported
 * as already-there would be undoing more than it did.
 */
final readonly class ServiceBulkAction
{
    /**
     * Actions the bar may ask for, in the order the UI lists them.
     *
     * Public because the bar is drawn from it: a hard-coded list of `<option>`
     * next to a whitelist here is a list that drifts.
     */
    public const ACTIONS = ['activate', 'deactivate', 'trash', 'restore', 'purge'];

    /**
     * How the bar said "these rows": the ticked ids, or everything the filter
     * currently on screen matches.
     *
     * `trash` and `purge` are the only actions whose two scopes differ, because
     * they are the only ones whose rows can leave the table.
     */
    public const SCOPE_SELECTED = 'selected';
    public const SCOPE_FILTERED = 'filtered';

    /**
     * Past tense of each action, for the audit-trail name.
     *
     * Spelled out rather than concatenated. `'trash' . 'd'` reads fine in a
     * hurry and writes `admin.service.bulk_trashd` into the log, which is a
     * name nobody can grep for later — and an audit trail that cannot be
     * searched is an audit trail nobody reads.
     *
     * @var array<string, string>
     */
    private const LOG_SUFFIX = [
        'activate' => 'activated',
        'deactivate' => 'deactivated',
        'trash' => 'trashed',
        'restore' => 'restored',
        'purge' => 'purged',
    ];

    /**
     * Largest selection one submission may carry.
     *
     * The page renders every service in one table, so a real selection can be
     * larger than the order queue's twenty — but the cap is here for the other
     * kind of caller: a hand-written POST carrying ten thousand ids would be ten
     * thousand UPDATE statements driven by whoever wrote the request.
     *
     * It bounds *ids travelling in a request*, not rows acted on. The filtered
     * scope is bounded separately, by `FILTERED_LIMIT`.
     */
    public const BULK_LIMIT = 200;

    /**
     * Ceiling on the filtered scope, and on nothing else.
     *
     * High enough that "all matching" means all matching for any list an admin
     * will realistically be holding, and low enough that a pathological filter
     * is a request that ends rather than a table lock that does not. One row
     * over it is fetched so the overflow is *detected* — `runFiltered()` says
     * so in the summary and in the log rather than quietly applying the first
     * thousand and calling it a clean batch.
     */
    public const FILTERED_LIMIT = 1000;

    /**
     * Rows a trash batch can undo.
     *
     * Ids only — a soft delete leaves the row and its history intact, so undo
     * is the existing `restore()` and the snapshot costs eight bytes a row.
     */
    public const UNDO_LIMIT = 200;

    /**
     * Rows a purge batch can undo.
     *
     * A purge has to snapshot the whole row plus the ids of the orders it
     * detached, because after the DELETE there is nothing left to read them
     * from. That is kilobytes a row rather than bytes, so the ceiling is lower
     * than `UNDO_LIMIT` and a batch over it keeps an Undo covering its first
     * rows — the banner says "of N", and the alternative — an Undo that silently
     * restores half — is the one nobody should have to discover by being
     * unlucky.
     */
    public const UNDO_PURGE_LIMIT = 100;

    public function __construct(
        private ServiceRepository $services,
        private ActivityLogRepository $logs,
    ) {}

    public static function isAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /**
     * Is this a filter that actually narrows anything?
     *
     * Checked again here rather than trusted from the caller: on POST the
     * filter arrives as form fields, and `trashed=exclude` with nothing else set
     * is the no-filter case written down explicitly. It matters because the
     * filtered scope means the whole table when the filter is empty, and for
     * `purge` the whole table is every service in the shop.
     *
     * Written against `ServiceRepository`'s vocabulary rather than a shared
     * normaliser on purpose — this is an independent check on untrusted input,
     * not a second copy of a policy, and `AdminServicesBulkTest` asserts the two
     * agree on the values that matter.
     *
     * @param array<string, mixed> $filter
     */
    public static function isFilterSet(array $filter): bool
    {
        if (trim((string) ($filter['q'] ?? '')) !== '') {
            return true;
        }
        if ((int) ($filter['category'] ?? 0) > 0) {
            return true;
        }
        if (in_array((string) ($filter['status'] ?? ''), ServiceRepository::STATUSES, true)) {
            return true;
        }
        $trashed = (string) ($filter['trashed'] ?? ServiceRepository::DELETED_EXCLUDE);
        return $trashed === ServiceRepository::DELETED_ONLY || $trashed === ServiceRepository::DELETED_ALL;
    }

    /**
     * Run one action over the ticked ids.
     *
     * Unknown actions are refused rather than guessed at, for the same reason
     * the order queue refuses an unmatched `do`: this endpoint deletes rows, and
     * a typo must not fall through into whatever the defaults happen to be.
     *
     * `ok` is true only when at least one row actually moved, so the caller can
     * colour the flash. "Nothing happened" and "done" are different answers and
     * an operator who cannot tell them apart will re-click.
     *
     * @param array<int|string, mixed> $ids
     *
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int, kept: int, action: string, undo: ?array}
     */
    public function run(array $ids, string $action, ?int $adminId): array
    {
        if (!self::isAction($action)) {
            return $this->result(false, 0, 0, 0, 0, $action, 'অজানা অ্যাকশন।');
        }

        // intval() strips whatever a client put in the array, the filter drops
        // the zeros and negatives that leaves, the de-dupe means a checkbox
        // submitted twice acts once, and the cap bounds a crafted POST.
        $ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);
        $ids = array_slice(array_values(array_unique($ids)), 0, self::BULK_LIMIT);

        if ($ids === []) {
            return $this->result(false, 0, 0, 0, 0, $action, 'কোনো সার্ভিস নির্বাচন করা হয়নি।');
        }

        return $this->apply($ids, $action, $adminId, [
            'scope' => self::SCOPE_SELECTED,
            'matched' => count($ids),
        ]);
    }

    /**
     * Run one action over everything the filter matches.
     *
     * Same rules, same log, same guards as `run()` — only the list of rows
     * differs, and it comes from the repository rather than the request, so
     * there is nothing in it for a client to have forged.
     *
     * @param array<string, mixed> $filter
     *
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int, kept: int, action: string, undo: ?array}
     */
    public function runFiltered(array $filter, string $action, ?int $adminId): array
    {
        if (!self::isAction($action)) {
            return $this->result(false, 0, 0, 0, 0, $action, 'অজানা অ্যাকশন।');
        }

        if (!self::isFilterSet($filter)) {
            return $this->result(
                false,
                0,
                0,
                0,
                0,
                $action,
                'কোনো ফিল্টার চালু নেই। প্রথমে ফিল্টার সেট করে তারপর "ফিল্টারের সবগুলো" বেছে নিন।',
            );
        }

        // One row past the ceiling, so "matched more than we acted on" is a
        // fact rather than a guess.
        $found = $this->services->adminFiltered($filter, self::FILTERED_LIMIT + 1);
        $ids = $found['ids'];
        $truncated = count($ids) > self::FILTERED_LIMIT;

        if ($truncated) {
            $ids = array_slice($ids, 0, self::FILTERED_LIMIT);
        }

        if ($ids === []) {
            return $this->result(false, 0, 0, 0, 0, $action, 'এই ফিল্টারে কোনো সার্ভিস নেই।');
        }

        return $this->apply($ids, $action, $adminId, [
            'scope' => self::SCOPE_FILTERED,
            'matched' => $found['total'],
            'truncated' => $truncated,
        ]);
    }

    /**
     * The batch, once its rows are known — whichever way they were chosen.
     *
     * Rows are loaded with `$withDeleted = true` for restore and purge to act
     * on, and so a row the other path would not load still has to be *reported*
     * as skipped rather than vanish.
     *
     * @param int[] $ids
     * @param array{scope: string, matched: int, truncated?: bool} $context
     *
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int, kept: int, action: string, undo: ?array}
     */
    private function apply(array $ids, string $action, ?int $adminId, array $context): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $service = $this->services->findServiceById($id, true);
            if ($service !== null) {
                $rows[$id] = $service;
            }
        }

        $applied = 0;
        $unchanged = 0;
        $skipped = 0;
        $kept = 0;
        $moved = [];
        /** @var list<array{row: array<string, mixed>, transaction_ids: int[]}> $purged */
        $purged = [];

        foreach ($ids as $id) {
            $service = $rows[$id] ?? null;

            if ($service === null) {
                $skipped++;
                continue;
            }

            // Read before the purge, because afterwards the orders this row
            // owned are indistinguishable from every top-up in the table.
            $detached = $action === 'purge' ? $this->services->orderIdsFor($id) : [];

            $outcome = $this->applyTo($id, $action, $service);
            match ($outcome['result']) {
                'applied' => $applied++,
                'unchanged' => $unchanged++,
                default => $skipped++,
            };
            $kept += $outcome['kept'];

            if ($outcome['result'] === 'applied') {
                $moved[] = $id;
                if ($action === 'purge') {
                    $purged[] = ['row' => $service, 'transaction_ids' => $detached];
                }
            }
        }

        if ($moved !== []) {
            $this->log($action, $moved, $applied, $unchanged, $skipped, $kept, $adminId, $context);
        }

        $result = $this->result(
            $applied > 0,
            $applied,
            $unchanged,
            $skipped,
            $kept,
            $action,
            $this->summarise($action, $applied, $unchanged, $skipped, $kept, $context),
        );
        $result['undo'] = $this->undoFor($action, $moved, $purged);

        return $result;
    }

    /**
     * One row, one action.
     *
     * @param array<string, mixed> $service
     *
     * @return array{result: 'applied'|'unchanged'|'skipped', kept: int}
     */
    private function applyTo(int $id, string $action, array $service): array
    {
        $trashed = $service['deleted_at'] !== null;

        // A trashed service is not on the site, so "active" would be a claim
        // about nothing. The admin's route back to a visible row is the trash
        // bar's restore, not a status toggle.
        if (($action === 'activate' || $action === 'deactivate') && $trashed) {
            return ['result' => 'skipped', 'kept' => 0];
        }

        if ($action === 'activate' || $action === 'deactivate') {
            $status = $action === 'activate' ? 'active' : 'inactive';
            if ((string) $service['status'] === $status) {
                return ['result' => 'unchanged', 'kept' => 0];
            }
            $this->services->updateService($id, ['status' => $status]);
            return ['result' => 'applied', 'kept' => 0];
        }

        if ($action === 'trash') {
            if ($trashed) {
                return ['result' => 'unchanged', 'kept' => 0];
            }
            if (!$this->services->softDelete($id)) {
                return ['result' => 'skipped', 'kept' => 0];
            }
            // Orders survive a soft delete, so the count is what makes the
            // operator's next question ("what happened to the orders?") already
            // answered.
            return ['result' => 'applied', 'kept' => $this->services->orderCount($id)];
        }

        if ($action === 'restore') {
            if (!$trashed) {
                return ['result' => 'unchanged', 'kept' => 0];
            }
            return $this->services->restore($id)
                ? ['result' => 'applied', 'kept' => 0]
                : ['result' => 'skipped', 'kept' => 0];
        }

        // purge: only a trashed row, same rule and same wording as the
        // single-row button. A live service is skipped rather than deleted —
        // the single-row path refuses it too, and a batch that quietly did what
        // a single click would not is a batch nobody should be allowed to run.
        if (!$trashed) {
            return ['result' => 'skipped', 'kept' => 0];
        }

        return ['result' => 'applied', 'kept' => $this->services->purgeService($id)];
    }

    /**
     * The snapshot that makes this batch reversible, or null when there is
     * nothing worth reversing.
     *
     * Only rows the batch actually *moved* are captured. Undo means "put back
     * what I just took away"; a restore that also resurrected the rows the
     * summary reported as unchanged or skipped would undo more than the batch
     * did, which for `purge` means re-inserting rows a previous batch had
     * already removed for good.
     *
     * @param int[] $moved
     * @param list<array{row: array<string, mixed>, transaction_ids: int[]}> $purged
     *
     * @return array{action: string, ids: int[], items: list<array<string, mixed>>}|null
     */
    private function undoFor(string $action, array $moved, array $purged): ?array
    {
        if ($action === 'trash') {
            $ids = array_slice($moved, 0, self::UNDO_LIMIT);
            return $ids === [] ? null : ['action' => 'trash', 'ids' => $ids, 'items' => []];
        }

        if ($action !== 'purge') {
            return null;
        }

        $items = array_slice($purged, 0, self::UNDO_PURGE_LIMIT);
        return $items === [] ? null : ['action' => 'purge', 'ids' => [], 'items' => $items];
    }

    /**
     * Put back what the last batch took away.
     *
     * The payload is consumed by the caller whatever happens here, so this is
     * one-shot: a double-clicked Undo cannot run twice, and a second click on a
     * page that somehow still shows the button reports that there is nothing
     * left instead of re-inserting rows.
     *
     * The two actions undo differently because they destroyed differently. A
     * trash batch left the row and its orders alone, so undo is `restore()`.
     * A purge detached the orders and then deleted the row, so undo re-inserts
     * the row *and* re-attaches them — a service restored with its history
     * still orphaned under `service_id IS NULL` would be a restore that
     * reported success and lost data.
     *
     * A row that will not come back (already restored by hand, or its id since
     * taken by a new service) is counted as failed and named in the message.
     * It is never overwritten.
     *
     * @param array<string, mixed>|null $payload A snapshot from `undoFor()`, or null when there is none.
     *
     * @return array{ok: bool, message: string, restored: int, failed: int}
     */
    public function undo(?array $payload, ?int $adminId): array
    {
        if ($payload === null) {
            return ['ok' => false, 'message' => 'ফেরানোর জন্য আর কিছু নেই।', 'restored' => 0, 'failed' => 0];
        }

        $action = (string) ($payload['action'] ?? '');

        if ($action === 'trash') {
            $restored = 0;
            foreach ((array) ($payload['ids'] ?? []) as $id) {
                if ((int) $id > 0 && $this->services->restore((int) $id)) {
                    $restored++;
                }
            }
            $failed = count((array) ($payload['ids'] ?? [])) - $restored;

            $message = $restored > 0
                ? sprintf('%d টি সার্ভিস ফিরিয়ে আনা হয়েছে।', $restored)
                : 'ফেরানো যায়নি — ওগুলো হয়তো ইতিমধ্যেই ফিরিয়ে আনা হয়েছে।';
            if ($restored > 0 && $failed > 0) {
                $message .= sprintf(' %d টি ফেরানো যায়নি।', $failed);
            }

            if ($restored > 0) {
                $this->logs->create([
                    'user_id' => $adminId,
                    'action' => 'admin.service.bulk_undo',
                    'description' => sprintf('Bulk undo (trash): %d service(s) restored', $restored),
                    'metadata' => [
                        'bulk_action' => 'trash',
                        'undone' => 'restore',
                        'restored' => $restored,
                        'failed' => $failed,
                        'service_ids' => array_map('intval', (array) ($payload['ids'] ?? [])),
                    ],
                ]);
            }

            return ['ok' => $restored > 0, 'message' => $message, 'restored' => $restored, 'failed' => $failed];
        }

        if ($action !== 'purge') {
            return ['ok' => false, 'message' => 'ফেরানোর জন্য আর কিছু নেই।', 'restored' => 0, 'failed' => 0];
        }

        $restored = 0;
        $failed = 0;
        $reorders = 0;
        $ids = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (!is_array($item) || !is_array($item['row'] ?? null)) {
                $failed++;
                continue;
            }
            $transactionIds = array_map('intval', (array) ($item['transaction_ids'] ?? []));
            if (!$this->services->restorePurged($item['row'], $transactionIds)) {
                $failed++;
                continue;
            }
            $restored++;
            $reorders += count($transactionIds);
            $ids[] = (int) ($item['row']['id'] ?? 0);
        }

        if ($restored > 0) {
            $this->logs->create([
                'user_id' => $adminId,
                'action' => 'admin.service.bulk_undo',
                'description' => sprintf('Bulk undo (purge): %d service(s) restored', $restored),
                'metadata' => [
                    'bulk_action' => 'purge',
                    'undone' => 'restore',
                    'restored' => $restored,
                    'failed' => $failed,
                    'reattached_transactions' => $reorders,
                    'service_ids' => $ids,
                ],
            ]);
        }

        $message = $restored > 0
            ? sprintf('%d টি মুছে ফেলা সার্ভিস ট্র্যাশে ফিরিয়ে আনা হয়েছে।', $restored)
            : 'ফেরানো যায়নি — ওগুলো হয়তো ইতিমধ্যেই ফিরিয়ে আনা হয়েছে, অথবা আইডি নতুন কোনো সার্ভিস ব্যবহার করছে।';
        if ($restored > 0 && $reorders > 0) {
            $message .= sprintf(' %d টি পুরনো অর্ডার রেকর্ড আবার সেই সার্ভিসের সাথে যুক্ত হয়েছে।', $reorders);
        }
        if ($restored > 0 && $failed > 0) {
            $message .= sprintf(' %d টি ফেরানো যায়নি।', $failed);
        }

        return ['ok' => $restored > 0, 'message' => $message, 'restored' => $restored, 'failed' => $failed];
    }

    /**
     * One activity-log row per batch, not one per service.
     *
     * A hundred-row purge is a single decision taken once, and the audit trail
     * should read that way. The ids travel in the metadata, so "what happened
     * to that batch" is still answerable without a hundred rows to page
     * through — and so is whether the admin ticked them or named a filter,
     * which is the question a "why did this service go off?" turns into.
     *
     * @param int[] $ids
     * @param array{scope: string, matched: int, truncated?: bool} $context
     */
    private function log(
        string $action,
        array $ids,
        int $applied,
        int $unchanged,
        int $skipped,
        int $kept,
        ?int $adminId,
        array $context,
    ): void {
        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.bulk_' . self::LOG_SUFFIX[$action],
            'description' => sprintf(
                'Bulk %s: %d service(s) (%d unchanged, %d skipped)',
                $action,
                $applied,
                $unchanged,
                $skipped,
            ),
            'metadata' => [
                'bulk_action' => $action,
                'scope' => $context['scope'],
                'matched' => $context['matched'],
                'count' => $applied,
                'unchanged' => $unchanged,
                'skipped' => $skipped,
                'kept_orders' => $kept,
                'service_ids' => $ids,
            ] + (($context['truncated'] ?? false) ? ['truncated' => true] : []),
        ]);
    }

    /**
     * The Bengali summary the admin reads back.
     *
     * Every clause that can be true is reported, because the interesting case
     * is the mixed one: nine ticked, three already off, two gone. "9 services
     * deactivated" and nothing else would leave the operator unable to tell a
     * partial batch from a clean one.
     *
     * The filtered scope appends its own clause for the same reason: a batch
     * that matched three hundred and acted on a thousand, or on a hundred of
     * three hundred, has to say so in the flash, because the flash is the only
     * place the admin will look before deciding whether to narrow the filter
     * and run it again.
     *
     * @param array{scope: string, matched: int, truncated?: bool} $context
     */
    private function summarise(
        string $action,
        int $applied,
        int $unchanged,
        int $skipped,
        int $kept,
        array $context,
    ): string {
        $done = [
            'activate' => '%d টি সার্ভিস সক্রিয় করা হয়েছে।',
            'deactivate' => '%d টি সার্ভিস নিষ্ক্রিয় করা হয়েছে।',
            'trash' => '%d টি সার্ভিস ট্র্যাশে পাঠানো হয়েছে।',
            'restore' => '%d টি সার্ভিস ফিরিয়ে আনা হয়েছে।',
            'purge' => '%d টি সার্ভিস স্থায়ীভাবে মুছা হয়েছে।',
        ][$action];

        // Only the destructive actions carry a "what about the orders" note, and
        // only when there is something to note.
        if ($kept > 0) {
            $done .= $action === 'purge'
                ? sprintf(' %d টি পুরনো অর্ডার রেকর্ড সংরক্ষিত আছে।', $kept)
                : sprintf(' %d টি পুরনো অর্ডার রেকর্ড অক্ষত আছে। চাইলে রিস্টোর করা যাবে।', $kept);
        }

        if ($applied === 0) {
            $message = 'কোনো সার্ভিসের অবস্থা বদলায়নি।';
            if ($unchanged > 0) {
                $message .= sprintf(' %d টি আগেই এই অবস্থায় ছিল।', $unchanged);
            }
            if ($skipped > 0) {
                $message .= sprintf(' %d টি বাদ পড়েছে।', $skipped);
            }
            return $message . $this->scopeClause($context);
        }

        $message = sprintf($done, $applied);
        if ($unchanged > 0) {
            $message .= sprintf(' %d টি আগেই এই অবস্থায় ছিল।', $unchanged);
        }
        if ($skipped > 0) {
            $message .= sprintf(' %d টি বাদ পড়েছে।', $skipped);
        }

        return $message . $this->scopeClause($context);
    }

    /**
     * The "this was a filter, and here is how much of it" sentence.
     *
     * Only for the filtered scope: on the ticked-id scope the number acted on is
     * the whole story, and adding "of 9 selected" to every batch would be noise.
     *
     * @param array{scope: string, matched: int, truncated?: bool} $context
     */
    private function scopeClause(array $context): string
    {
        if ($context['scope'] !== self::SCOPE_FILTERED) {
            return '';
        }

        if ($context['truncated'] ?? false) {
            return sprintf(
                ' ফিল্টারে %d টি মিলেছে, প্রথম %d টির ওপর কাজ করা হয়েছে — বাকিগুলোর জন্য ফিল্টারটি ছোট করে আবার চালান।',
                $context['matched'],
                self::FILTERED_LIMIT,
            );
        }

        return sprintf(' ফিল্টারের সবগুলোর মধ্যে মিলেছে %d টি।', $context['matched']);
    }

    /**
     * @return array{ok: bool, message: string, applied: int, unchanged: int, skipped: int, kept: int, action: string, undo: ?array}
     */
    private function result(
        bool $ok,
        int $applied,
        int $unchanged,
        int $skipped,
        int $kept,
        string $action,
        string $message,
    ): array {
        return [
            'ok' => $ok,
            'message' => $message,
            'applied' => $applied,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'kept' => $kept,
            'action' => $action,
            // Overwritten with a snapshot by apply() when the batch had
            // something reversible in it. Null here means the caller's batch
            // moved nothing.
            'undo' => null,
        ];
    }
}