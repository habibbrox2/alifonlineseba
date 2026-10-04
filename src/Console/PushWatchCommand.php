<?php

declare(strict_types=1);

namespace App\Console;

use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Announces every browser that subscribes to Web Push, the moment it happens:
 *
 *   php yii app:webpush:watch                  # watch, polling every 5s
 *   php yii app:webpush:watch --once           # one poll, then exit (cron)
 *   php yii app:webpush:watch --notify=admins  # also alert staff (Telegram + browser)
 *
 * ## Why the obvious query is the wrong one
 *
 * `SELECT * WHERE id > :last` watches for rows appearing, and that is only a
 * third of what subscribing to this application means. `subscribe()` is an
 * upsert keyed on `endpoint`, and `push-subscribe.js` re-posts the same
 * subscription on *every page load*, so the table's own `updated_at` ticks
 * several times a minute per browser and is worthless as a change signal. A
 * watcher keyed on it would bury the one event anybody cares about.
 *
 * So the watcher remembers the last known state of every row — is it active,
 * does it have an owner — and reports the transitions, not the writes:
 *
 *   new      a row appeared. A browser granted permission for the first time.
 *   revived  a row went is_active 0 -> 1. A browser we had written off came
 *            back, which is also what a 410-retired row looks like when the
 *            user re-grants.
 *   claimed  a row went user_id NULL -> somebody. The visitor who accepted the
 *            /app banner has now logged in, and this is the single most useful
 *            moment in the table: a known-anonymous browser just became
 *            reachable by an account.
 *   off      a row went active -> inactive. Reported because a 404/410
 *            retirement has no other trace anywhere, and a watcher that
 *            explains every change is one people keep running.
 *
 * A row that is already active and already owned changing nothing is the
 * every-page-load re-post, and it is deliberately silent. The first run seeds
 * from the table without reporting it, so starting the watcher on a site with
 * a few hundred subscribers does not print a few hundred "new" lines and train
 * the operator to ignore it.
 *
 * State lives in one small JSON cursor, held under an exclusive lock so two
 * watchers cannot interleave and each skip the other's events.
 */
#[AsCommand('app:webpush:watch', 'Announce every browser that subscribes to Web Push, the moment it happens.')]
final class PushWatchCommand extends Command
{
    /** Bit flags — the two facts about a row that make a change worth reporting. */
    private const FLAG_ACTIVE = 1;
    private const FLAG_OWNED = 2;

    private const MIN_INTERVAL = 1;
    private const MAX_INTERVAL = 3600;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly UserRepository $users,
        private readonly NotificationManager $notify,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'interval',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds between polls.',
                (string) \App\Env::int('WEBPUSH_WATCH_INTERVAL', 5),
            )
            ->addOption(
                'once',
                null,
                InputOption::VALUE_NONE,
                'Poll a single time and exit, for a cron job.',
            )
            ->addOption(
                'replay',
                null,
                InputOption::VALUE_NONE,
                'On the first run, report the subscriptions already in the table as new instead of seeding silently.',
            )
            ->addOption(
                'notify',
                null,
                InputOption::VALUE_REQUIRED,
                'Also send a system alert to staff: "admins" or "none".',
                'none',
            )
            ->addOption(
                'state',
                null,
                InputOption::VALUE_REQUIRED,
                'Cursor file recording what the last run saw.',
                dirname(__DIR__, 2) . '/runtime/webpush-watch.json',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $once = (bool) $input->getOption('once');
        $replay = (bool) $input->getOption('replay');
        $statePath = (string) $input->getOption('state');
        $notify = (string) $input->getOption('notify');
        $interval = max(
            self::MIN_INTERVAL,
            min(self::MAX_INTERVAL, (int) $input->getOption('interval')),
        );

        if (!in_array($notify, ['none', 'admins'], true)) {
            $io->error("--notify must be 'none' or 'admins', not '{$notify}'.");
            return Command::FAILURE;
        }

        $handle = $this->openState($statePath, $io);
        if ($handle === null) {
            return Command::FAILURE;
        }

        try {
            return $this->watch($io, $handle, $statePath, $interval, $once, $replay, $notify);
        } finally {
            // The lock lives on the handle, so it has to go before the message
            // that tells the operator the watcher has stopped.
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle Locked cursor file.
     * @param string $notify  'none' or 'admins'
     */
    private function watch(
        SymfonyStyle $io,
        $handle,
        string $statePath,
        int $interval,
        bool $once,
        bool $replay,
        string $notify,
    ): int {
        $staffId = $notify === 'admins' ? $this->staffRecipient($io) : null;

        $io->title('Web Push subscription watcher');
        $io->definitionList(
            ['Cursor' => $statePath],
            ['Polling' => $once ? 'once, then exit' : "every {$interval}s"],
            ['Staff alert' => $notify === 'admins'
                ? ($staffId === null ? '<error>requested, but no staff account exists</error>' : "yes → user #{$staffId}")
                : 'no (--notify=admins to also alert staff)'],
        );

        $cursor = $this->loadCursor($handle, $statePath, $io);
        if ($cursor === null) {
            if ($replay) {
                // --replay is exactly "start from an empty cursor", so every
                // row already in the table reads as new on this first tick.
                $cursor = [];
            } else {
                // Seeding rather than reporting: the table is full of browsers
                // that subscribed before anybody was watching, and announcing
                // them all as new is how an operator learns to ignore this
                // command.
                $cursor = $this->flags($this->subscriptions->allForWatching());
                $this->saveCursor($handle, $cursor);
                $io->text(sprintf(
                    'Seeded from %d existing subscription(s) — not reported. Changes from now on are.',
                    count($cursor),
                ));
            }
        }

        $io->success('Watching. Ctrl-C to stop.');

        while (true) {
            $rows = $this->subscriptions->allForWatching();
            $flags = $this->flags($rows);
            $events = $this->diff($flags, $cursor);

            // The cursor *is* the current table, so a row somebody purged drops
            // out of it here rather than accumulating forever in the file.
            $cursor = $flags;
            $this->saveCursor($handle, $cursor);

            $this->report($io, $events, $rows, $staffId, $io->isDecorated());

            if ($once) {
                return Command::SUCCESS;
            }

            sleep($interval);
        }
    }

    /**
     * One line per transition, then the tally.
     *
     * The tally is what makes the silence trustworthy: a watcher that prints
     * nothing for an hour is indistinguishable from one that is broken, so
     * every tick says what it looked at and found.
     *
     * Quiet when nothing happened, loud when it did: a resident loop printing
     * a line every five seconds forever trains the operator to stop reading it.
     * The tally is on the event lines, where it says what changed.
     *
     * @param list<array{0: string, 1: int}> $events
     * @param array<int, array<string, mixed>> $rows
     */
    private function report(SymfonyStyle $io, array $events, array $rows, ?int $staffId, bool $decorated): void
    {
        if ($events === []) {
            $io->writeln('<comment>no changes</comment>', OutputInterface::VERBOSITY_VERBOSE);

            return;
        }

        $counts = ['new' => 0, 'revived' => 0, 'claimed' => 0, 'off' => 0];

        foreach ($events as [$kind, $id]) {
            $counts[$kind]++;
            $row = $rows[$id] ?? [];
            $browser = PushSubscriptionRepository::describeBrowser((string) ($row['user_agent'] ?? ''));
            $owner = $this->owner($row);

            $io->writeln(sprintf(
                '  %s  <info>#%d</info>  %s  %s  <comment>%s</comment>  %s',
                $this->label($kind),
                $id,
                $browser,
                $owner,
                $this->host((string) ($row['endpoint'] ?? '')),
                date('H:i:s'),
            ));

            // A terminal bell is the only notification that works when the
            // watcher is running in a window you are not looking at. Only on a
            // real terminal: in a log file or a test it is just noise.
            if ($decorated) {
                $io->write("\x07");
            }

            if ($staffId !== null && $kind !== 'off') {
                $error = $this->alert($this->reason($kind, $id, $browser, $owner), $id, $kind, $staffId);
                if ($error !== null) {
                    // An alert that fails must not cost us the watch: the row is
                    // already in the cursor, so the next tick will not retry it.
                    $io->writeln('         <error>staff alert failed:</error> ' . $error);
                }
            }
        }

        $io->writeln(sprintf(
            '<info>%d new</info>, %d revived, %d claimed, %d off',
            $counts['new'],
            $counts['revived'],
            $counts['claimed'],
            $counts['off'],
        ));
    }

    /**
     * Push the event to staff as a system alert.
     *
     * `reference` is doing real work here. `system.alert` has no id of its own,
     * and NotificationManager builds the queue's dedupe key from the event, the
     * recipient, the channel and that reference — so without one every alert
     * for this event would hash identically and `uk_queue_dedupe` would drop
     * every one after the first, forever. Two different browsers subscribing
     * would produce exactly one notification.
     *
     * @return string|null the failure message, or null on success
     */
    private function alert(string $reason, int $id, string $kind, int $staffId): ?string
    {
        try {
            $this->notify->dispatch(NotificationEvent::SYSTEM_ALERT, $staffId, [
                'reason' => $reason,
                'reference' => "pushwatch-{$id}-{$kind}",
            ]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The staff account a `system.alert` is dispatched to.
     *
     * One id, not a list: NotificationManager fans a system alert out to every
     * admin itself, and skips the user it was dispatched for, so handing it the
     * whole staff list would double-notify whoever came first.
     */
    private function staffRecipient(SymfonyStyle $io): ?int
    {
        $id = $this->users->firstStaffId();
        if ($id === null) {
            $io->warning('No active staff account — staff alerts are off. The terminal output is unaffected.');
        }

        return $id;
    }

    /**
     * Reduce the table to the two facts a transition is made of.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int> id => flags
     */
    private function flags(array $rows): array
    {
        $flags = [];
        foreach ($rows as $id => $row) {
            $flags[$id] = ((int) $row['active'] === 1 ? self::FLAG_ACTIVE : 0)
                | ((int) ($row['user_id'] ?? 0) > 0 ? self::FLAG_OWNED : 0);
        }

        return $flags;
    }

    /**
     * Transitions worth a human's attention.
     *
     * At most one event per row per tick, in descending order of interest: a
     * browser that came back *and* got claimed by an account is one line
     * saying both, not two lines saying half each.
     *
     * @param array<int, int> $flags
     * @param array<int, int> $cursor
     * @return list<array{0: string, 1: int}>
     */
    private function diff(array $flags, array $cursor): array
    {
        $events = [];

        foreach ($flags as $id => $now) {
            $was = $cursor[$id] ?? null;

            if ($was === null) {
                $events[] = ['new', $id];
                continue;
            }

            // Identical flags, different updated_at: the same browser posting
            // its subscription again on this page load. The common case, and the
            // one the watcher exists to not report.
            if ($was === $now) {
                continue;
            }

            $wasActive = ($was & self::FLAG_ACTIVE) !== 0;
            $wasOwned = ($was & self::FLAG_OWNED) !== 0;
            $isActive = ($now & self::FLAG_ACTIVE) !== 0;
            $isOwned = ($now & self::FLAG_OWNED) !== 0;

            $events[] = match (true) {
                $isOwned && !$wasOwned => ['claimed', $id],
                $isActive && !$wasActive => ['revived', $id],
                !$isActive && $wasActive => ['off', $id],
                default => null,
            };
        }

        return array_values(array_filter($events));
    }

    /** @param array<string, mixed> $row */
    private function owner(array $row): string
    {
        $userId = (int) ($row['user_id'] ?? 0);

        return $userId > 0 ? "user #{$userId}" : 'anonymous';
    }

    private function host(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown host';
    }

    private function label(string $kind): string
    {
        return match ($kind) {
            'new' => '<fg=green;options=bold>NEW    </>',
            'revived' => '<fg=yellow;options=bold>REVIVED</>',
            'claimed' => '<fg=cyan;options=bold>CLAIMED</>',
            default => '<fg=gray;options=bold>OFF    </>',
        };
    }

    /**
     * The staff-facing sentence. Bengali, because it lands in an admin's inbox
     * and on their phone, not in a terminal.
     *
     * The subscription id goes in: the alert has no other way of saying which
     * browser it is about, and an admin who wants to look at this one row
     * should not have to guess.
     */
    private function reason(string $kind, int $id, string $browser, string $owner): string
    {
        $who = $owner === 'anonymous' ? 'অ্যানোনিমাস' : $owner;

        return match ($kind) {
            'revived' => "আগের একটি ব্রাউজার (#{$id}) আবার সাবস্ক্রাইব করেছে: {$browser} ({$who})।",
            'claimed' => "লগইনের পর ব্রাউজারটি (#{$id}) {$who}-এর নামে যুক্ত হয়েছে: {$browser}।",
            default => "নতুন একটি ব্রাউজার (#{$id}) সাবস্ক্রাইব করেছে: {$browser} ({$who})।",
        };
    }

    /**
     * @param resource $handle
     * @return resource|null
     */
    private function openState(string $path, SymfonyStyle $io)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $io->error("Cannot create {$dir} for the cursor file.");

            return null;
        }

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            $io->error("Cannot open the cursor file {$path}.");

            return null;
        }

        // Without the lock, two watchers each save the cursor they last saw and
        // every event the other one handled is reported twice or not at all.
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $io->error("Another watcher already holds {$path}. Stop it, or pass --state to use a second cursor.");

            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @return array<int, int>|null null when there is no usable cursor yet
     */
    private function loadCursor($handle, string $path, SymfonyStyle $io): ?array
    {
        rewind($handle);
        $raw = (string) stream_get_contents($handle);
        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)) {
            // The cursor is derived state, so a mangled one is not worth
            // preserving — but it is worth saying out loud, because the run
            // that follows re-seeds silently and would otherwise look like the
            // watcher had missed a lot.
            $io->warning("The cursor at {$path} was unreadable and has been re-seeded. Existing subscriptions were not reported.");

            return null;
        }

        $rows = [];
        foreach ($decoded['rows'] as $id => $flags) {
            $rows[(int) $id] = (int) $flags;
        }

        return $rows;
    }

    /**
     * @param resource $handle
     * @param array<int, int> $cursor
     */
    private function saveCursor($handle, array $cursor): void
    {
        $json = json_encode(['v' => 1, 'rows' => $cursor], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }
}
