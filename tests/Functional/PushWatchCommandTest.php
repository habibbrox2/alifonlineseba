<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Console\PushWatchCommand;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Notification\Push\VapidKeys;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * app:webpush:watch — the thing you run when you want to know that Web Push
 * is being adopted, right now, without going looking.
 *
 * The assertion that matters most here is the negative one. `subscribe()` is an
 * upsert and `push-subscribe.js` re-posts the same subscription on every single
 * page load, so a watcher built on the obvious "what changed since last time"
 * query reports the same browser several times a minute and becomes something
 * people learn to ignore. Every test here drives the real repository, so the
 * input is exactly what the application writes — including the upsert, which is
 * the case under test.
 *
 * Because the first run on an empty cursor *seeds* rather than reports (see
 * `testTheFirstRunSeedsTheExistingTableWithoutReportingIt`), most tests call
 * `seed()` first to put the watcher in the state an operator is actually in when
 * they are watching: the cursor exists, the table is quiet, and now a browser
 * subscribes. Subscribing and then running the watcher for the first time is
 * deliberately *not* how these tests are written — a row present at seed time
 * is indistinguishable from a row that arrived a millisecond earlier, and the
 * command says so rather than guessing.
 *
 * The cursor is a temp file, so the tests never read or write the operator's
 * own state, and `--once` keeps each run to a single poll.
 *
 * Throwaway rows only, removed in _after() by endpoint prefix. Staff alerts
 * write to `notification` and `notification_queue`, so those are removed by id
 * range captured before the test rather than by event — a real operator alert
 * must not be deleted by a test suite.
 */
final class PushWatchCommandTest extends \Codeception\Test\Unit
{
    /** Endpoints created here all start with this, and nothing else does. */
    private const ENDPOINT_PREFIX = 'https://push.example.invalid/wpwatch-test-';

    private ConnectionInterface $db;
    private UserRepository $users;
    private PushSubscriptionRepository $subs;
    private NotificationManager $notify;

    private string $tempDir = '';
    private string $cursor = '';
    private string $endpoint = '';
    private int $staffId = 0;
    private int $maxNotificationId = 0;
    private int $maxQueueId = 0;
    private bool $staffPushEnabled = true;

    /**
     * Subscription ids the suite did not create, in a real browser on the live
     * site.
     *
     * The watcher reads the whole table, so a row nobody here made is still a
     * row it reports on — and these tests ran against a live development
     * database, not a scratch one. Three of them assert on table-wide counts,
     * which quietly assumed an empty table and so failed the first time a real
     * visitor allowed notifications. Rather than delete a stranger's
     * subscription to keep a test green, the assertions account for these.
     *
     * @var int[]
     */
    private array $foreignSubscriptionIds = [];
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->subs = new PushSubscriptionRepository($this->db);

        $queue = new QueueRepository($this->db);
        $this->notify = new NotificationManager(
            new NotificationRepository($this->db),
            $queue,
            new TemplateRenderer($this->db, new SettingsRepository($this->db)),
            $this->users,
            $this->db,
        );

        $this->tempDir = sys_get_temp_dir() . '/wpwatch-' . substr(md5(uniqid('', true)), 0, 10);
        mkdir($this->tempDir, 0o777, true);
        $this->cursor = $this->tempDir . '/cursor.json';
        $this->endpoint = self::ENDPOINT_PREFIX . bin2hex(random_bytes(6));

        $staffId = $this->users->firstStaffId();
        assertGreaterThan(0, $staffId, 'The staff-alert tests need an active staff account.');
        $this->staffId = $staffId;

        $this->loadVapidKeys();
        $this->staffPushEnabled = $this->users->isPushEnabled($this->staffId);
        if (!$this->staffPushEnabled) {
            // pushAllowed() is an opt-out gate, and the point of these tests is
            // that a staff alert reaches a browser, so the switch has to be on
            // for the duration. _after() puts it back.
            $this->users->setPushEnabled($this->staffId, true);
        }

        $this->maxNotificationId = (int) $this->db
            ->createCommand('SELECT MAX([[id]]) FROM {{%notification}}')
            ->queryScalar();
        $this->maxQueueId = (int) $this->db
            ->createCommand('SELECT MAX([[id]]) FROM {{%notification_queue}}')
            ->queryScalar();

        $this->foreignSubscriptionIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $this->db
                ->createCommand('SELECT [[id]] FROM {{%push_subscription}} WHERE [[endpoint]] NOT LIKE :p')
                ->bindValue(':p', self::ENDPOINT_PREFIX . '%')
                ->queryColumn(),
        );
        sort($this->foreignSubscriptionIds);
    }

    protected function _after(): void
    {
        $this->db
            ->createCommand('DELETE FROM {{%push_subscription}} WHERE [[endpoint]] LIKE :p')
            ->bindValue(':p', self::ENDPOINT_PREFIX . '%')
            ->execute();

        $queued = $this->db
            ->createCommand('SELECT [[id]] FROM {{%notification_queue}} WHERE [[id]] > :id')
            ->bindValue(':id', $this->maxQueueId)
            ->queryColumn();
        if ($queued !== []) {
            $queued = array_map(static fn (mixed $id): int => (int) $id, $queued);
            $placeholders = implode(', ', array_map(static fn (int $id): string => ':q' . $id, $queued));
            $bindings = [];
            foreach ($queued as $id) {
                $bindings[':q' . $id] = $id;
            }

            $this->db
                ->createCommand("DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN ({$placeholders})")
                ->bindValues($bindings)
                ->execute();
            $this->db
                ->createCommand("DELETE FROM {{%notification_queue}} WHERE [[id]] IN ({$placeholders})")
                ->bindValues($bindings)
                ->execute();
        }
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE [[id]] > :id')
            ->bindValue(':id', $this->maxNotificationId)
            ->execute();

        if (!$this->staffPushEnabled) {
            $this->users->setPushEnabled($this->staffId, false);
        }
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        $this->previousEnv = [];

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    // ---- The transitions ---------------------------------------------------

    public function testANewBrowserIsAnnounced(): void
    {
        $this->seed();

        $id = $this->subscribe();

        [$exit, $out] = $this->watch();

        assertSame(Command::SUCCESS, $exit);
        assertStringContainsString('NEW', $out, 'A row that appeared is the event this command exists for.');
        assertStringContainsString('#' . $id, $out, 'The subscription id is what an operator looks up afterwards.');
        assertStringContainsString('Chrome', $out, 'The browser is the useful half of the sentence.');
        assertStringContainsString('anonymous', $out, 'Nobody was logged in, and saying so is not the same as saying nothing.');
    }

    /**
     * The one that decides whether this command is usable.
     *
     * `push-subscribe.js` re-posts the subscription on every page load, and
     * `subscribe()` upserts on `endpoint`, so this call changes `updated_at` on
     * a row the watcher has already seen. A watcher watching the write instead
     * of the transition prints this same browser again, forever.
     */
    public function testTheEveryPageLoadRepostIsSilent(): void
    {
        $this->seed();

        $id = $this->subscribe();
        [, $announced] = $this->watch();
        assertStringContainsString('NEW', $announced, 'Otherwise this test would pass for the wrong reason.');

        [, $out] = $this->watch();

        assertStringNotContainsString('NEW', $out, 'A returning browser is not a new browser.');
        assertStringNotContainsString('REVIVED', $out, 'It was never inactive.');
        assertStringNotContainsString('CLAIMED', $out, 'It had an owner already.');
        assertStringNotContainsString('#' . $id, $out, 'The row is unchanged as far as the watcher is concerned.');
    }

    /** A browser we had written off coming back is worth a line. */
    public function testABrowserThatComesBackIsAnnouncedAsRevived(): void
    {
        $this->seed();

        $id = $this->subscribe();
        $this->watch();

        $this->subs->unsubscribe($id);
        [, $out] = $this->watch();

        assertStringContainsString('OFF', $out, 'A 404/410 retirement has no other trace anywhere.');
        assertStringNotContainsString('REVIVED', $out, 'It has not come back yet.');

        $this->subscribe();
        [, $out] = $this->watch();

        assertStringContainsString('REVIVED', $out, 'A browser that re-grants permission is a live subscription again.');
        assertStringNotContainsString('NEW', $out, 'The row is not new; it came back.');
    }

    /**
     * The single most useful moment in the table: the visitor who accepted the
     * /app banner has now logged in, and their browser has become reachable by
     * an account.
     */
    public function testALoginClaimIsAnnounced(): void
    {
        $this->seed();

        $this->subscribe();
        $this->watch();

        $this->subs->subscribe($this->endpoint, null, null, $this->staffId);
        [, $out] = $this->watch();

        assertStringContainsString('CLAIMED', $out, 'An anonymous row that gains an owner is the event worth interrupting for.');
        assertStringContainsString('user #' . $this->staffId, $out, 'The account it now belongs to is the point of the event.');
    }

    /** One line per row, even when a re-grant and a login land together. */
    public function testARowThatRevivesAndIsClaimedAtOnceIsAnnouncedOnce(): void
    {
        $this->seed();

        $id = $this->subscribe();
        $this->watch();

        $this->subs->unsubscribe($id);
        $this->watch();

        $this->subs->subscribe($this->endpoint, null, null, $this->staffId);
        [, $out] = $this->watch();

        assertStringContainsString('CLAIMED', $out, 'Owning it is the stronger fact, and the copy says it was returning.');
        assertStringNotContainsString('REVIVED', $out, 'Two lines saying half each is not one line saying both.');
    }

    // ---- Starting up, restarting, and refusing to double up ----------------

    /**
     * Starting the watcher on a site with a few hundred subscribers must not
     * print a few hundred "new" lines.
     */
    public function testTheFirstRunSeedsTheExistingTableWithoutReportingIt(): void
    {
        $this->subscribe();

        [$exit, $out] = $this->watch();

        assertSame(Command::SUCCESS, $exit);
        assertStringContainsString(
            'Seeded from ' . $this->tableSize() . ' existing subscription(s)',
            $out,
            'The count is the whole table, so it includes rows this suite did not create.',
        );
        assertStringNotContainsString('NEW', $out, 'Reporting the backlog is how an operator learns to ignore this command.');
    }

    /** The escape hatch for exactly that: ask for the backlog on purpose. */
    public function testReplayReportsTheExistingTable(): void
    {
        $id = $this->subscribe();

        [, $out] = $this->watch(['--replay' => true]);

        assertStringContainsString('NEW', $out);
        assertStringContainsString('#' . $id, $out);
    }

    /** A restart resumes where it left off rather than repeating itself. */
    public function testTheCursorSurvivesARestart(): void
    {
        $this->seed();

        $id = $this->subscribe();
        [, $first] = $this->watch();
        assertStringContainsString('#' . $id, $first);

        [, $second] = $this->watch();

        assertStringNotContainsString('NEW', $second, 'The cursor is what makes a restart quiet.');
    }

    /** A purged row must not keep the cursor growing forever. */
    public function testAPurgedRowLeavesTheCursor(): void
    {
        $this->seed();

        $id = $this->subscribe();
        $this->watch();

        $this->subs->unsubscribe($id);
        $this->db
            ->createCommand()->delete('{{%push_subscription}}', ['id' => $id])->execute();
        $this->watch();

        $cursor = json_decode((string) file_get_contents($this->cursor), true);

        // The purged row must be gone from the cursor. Whatever else is in the
        // table belongs to somebody else and is none of this test's business.
        $ids = array_map(static fn (mixed $id): int => (int) $id, array_keys($cursor['rows']));
        sort($ids);

        assertSame(
            $this->foreignSubscriptionIds,
            $ids,
            'The cursor is the current table, not a history of it.',
        );
    }

    /** A mangled cursor must say so, and must not then flood. */
    public function testAnUnreadableCursorIsReportedAndReseeded(): void
    {
        $this->subscribe();
        file_put_contents($this->cursor, 'this is not json');

        [, $out] = $this->watch();

        assertStringContainsString('unreadable', $out, 'A silent re-seed looks like the watcher missed a lot.');
        assertStringContainsString('Seeded from ' . $this->tableSize(), $out);
        assertStringNotContainsString('NEW', $out);
    }

    /**
     * Two watchers on one cursor each save the state they last saw, so every
     * event the other one handled is reported twice or not at all.
     */
    public function testASecondWatcherOnTheSameCursorIsRefused(): void
    {
        $handle = fopen($this->cursor, 'c+b');
        assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'The test has to hold the lock for this to mean anything.');

        try {
            [$exit, $out] = $this->watch();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        assertSame(Command::FAILURE, $exit, 'Two watchers on one cursor is a data race, not a feature.');
        assertStringContainsString('Another watcher already holds', $out);
    }

    /** Validated before anything is written, so a typo cannot half-start it. */
    public function testAnUnknownNotifyModeIsRejectedWithoutTouchingTheCursor(): void
    {
        [$exit, $out] = $this->watch(['--notify' => 'maybe']);

        assertSame(Command::FAILURE, $exit);
        assertStringContainsString("must be 'none' or 'admins'", $out);
        assertFileDoesNotExist($this->cursor, 'The option is checked before the cursor is opened, so a rejected run leaves no state at all.');
    }

    // ---- The staff alert ---------------------------------------------------

    /** The default is quiet: a watcher must not spam staff on its own. */
    public function testNoStaffAlertByDefault(): void
    {
        $this->seed();
        $this->subscribe();

        [$exit, $out] = $this->watch();

        assertSame(Command::SUCCESS, $exit);
        assertStringContainsString('NEW', $out);
        assertStringContainsString('--notify=admins', $out, 'The way to get an alert has to be discoverable.');
        assertSame(
            0,
            $this->notificationCount(NotificationEvent::SYSTEM_ALERT),
            'A debugging watcher that writes to the notification tables by default is a surprise, not a feature.',
        );
    }

    public function testTheStaffAlertReachesStaffOnEveryChannelItCanUse(): void
    {
        $this->seed();
        $id = $this->subscribe();

        [, $out] = $this->watch(['--notify' => 'admins']);

        assertStringContainsString('NEW', $out);
        assertStringNotContainsString('staff alert failed', $out);

        $inApp = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification}} WHERE [[id]] > :id AND [[user_id]] = :u')
            ->bindValue(':id', $this->maxNotificationId)
            ->bindValue(':u', $this->staffId)
            ->queryScalar();
        assertSame(1, $inApp, 'The staff account should find the alert in their own inbox.');

        // Roster-aware: the alert goes to the dispatch target *and* every
        // account the fan-out reads from `adminIds()` — a second admin means a
        // second row, which is correct behaviour, not a regression. What must
        // hold for any roster: one webpush row per recipient who has push on,
        // and one telegram row per fan-out admin (the admin loop queues
        // Telegram unconditionally, and the subject path never does here —
        // the test manager is built without a bot repository, so
        // `contactChannels('telegram')` is empty for everybody).
        $recipients = array_values(array_unique([$this->staffId, ...$this->staffIds()]));
        $expectedPush = count(
            array_filter($recipients, fn (int $id): bool => $this->users->isPushEnabled($id)),
        );
        assertSame(
            $expectedPush,
            $this->queuedCount('webpush'),
            'Staff work from a browser, so the alert has to reach every staff box with push on.',
        );

        assertSame(
            count($this->staffIds()),
            $this->queuedCount('telegram'),
            'Telegram is the channel staff get on their phone — one row per fan-out admin.',
        );

        $message = (string) $this->db
            ->createCommand('SELECT [[message]] FROM {{%notification}} WHERE [[id]] > :id')
            ->bindValue(':id', $this->maxNotificationId)
            ->queryScalar();
        assertStringContainsString('#' . $id, $message, 'The id in the copy is what makes the alert actionable.');
    }

    /**
     * The case that was broken, and the one this whole arrangement is about.
     *
     * `system.alert` is addressed to a member of staff, and the watcher picks
     * `firstStaffId()` on purpose. The admin fan-out then skipped the dispatch
     * target, and on a box with a single staff account the target *is* the
     * entire admin list — so the skip removed every recipient and the watcher
     * announced new browsers to nobody at all, on any channel.
     *
     * The original version of this test pinned the roster ("the dispatch
     * target is the only staff account") and failed as soon as the live
     * database grew a second admin — which is a roster fact, not a bug, and a
     * test that goes red when operations do their job teaches people to ignore
     * it. The invariant below is what the bug actually violated, and it holds
     * for any roster: every fan-out admin *and* the dispatch target each get
     * exactly one copy — the target never dropped, nobody counted twice — and
     * the addressed target still gets their queued channel.
     */
    public function testTheDispatchTargetAndEveryStaffMemberGetExactlyOneCopy(): void
    {
        $expectedRecipients = array_values(array_unique([$this->staffId, ...$this->staffIds()]));
        sort($expectedRecipients);

        $this->seed();
        $this->subscribe();

        [, $out] = $this->watch(['--notify' => 'admins']);

        assertStringNotContainsString('staff alert failed', $out);

        $rows = $this->db
            ->createCommand(
                'SELECT [[user_id]] FROM {{%notification}} WHERE [[id]] > :id AND [[event]] = :e'
            )
            ->bindValue(':id', $this->maxNotificationId)
            ->bindValue(':e', NotificationEvent::SYSTEM_ALERT)
            ->queryColumn();
        $actual = array_map(static fn (mixed $id): int => (int) $id, $rows);
        sort($actual);

        assertSame(
            $expectedRecipients,
            $actual,
            'Every staff account and the dispatch target must get exactly one inbox copy: '
                . 'a missing id is the original bug (the target filtered out), a duplicate is a double alert.',
        );

        $webpushForTarget = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%notification_queue}}'
                . ' WHERE [[id]] > :id AND [[channel]] = :c AND [[user_id]] = :u',
            )
            ->bindValue(':id', $this->maxQueueId)
            ->bindValue(':c', 'webpush')
            ->bindValue(':u', $this->staffId)
            ->queryScalar();

        assertSame(
            1,
            $webpushForTarget,
            'The person the watcher addressed must still get the queued alert on their own browser.',
        );
    }

    /**
     * Admitting the target to the fan-out must not write their inbox row twice.
     *
     * The in-app row is written at the top of `dispatch()` for the target, and
     * the fan-out's own copy is for *other* recipients. Without the distinction
     * the single-staff case would trade one alert that never arrived for two
     * that did.
     */
    public function testTheDispatchTargetIsNotGivenTwoCopiesOfTheAlert(): void
    {
        $this->seed();
        $this->subscribe();

        $this->watch(['--notify' => 'admins']);

        $rows = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification}} WHERE [[id]] > :id AND [[user_id]] = :u')
            ->bindValue(':id', $this->maxNotificationId)
            ->bindValue(':u', $this->staffId)
            ->queryScalar();

        assertSame(1, $rows, 'One alert, one inbox row.');
    }

    /**
     * The other half of the change: `system.alert` is special because it is
     * addressed *to* staff. Every other admin-fan-out event names the user as
     * the subject, and there the original rule stands — an admin who submits
     * their own recharge is not an audience for the news that they did.
     */
    public function testStaffAreStillNotNotifiedAboutTheirOwnTopupRequest(): void
    {
        $this->notify->dispatch(
            NotificationEvent::TOPUP_REQUESTED,
            $this->staffId,
            ['amount' => 100, 'reference' => 'trx-' . bin2hex(random_bytes(4))],
        );

        // The self-skip is about *the actor*: they get their own inbox row and
        // no fan-out copy. Every other admin hears about it — that is the rule
        // working — so the totals are computed from the live fan-out list
        // instead of being pinned to the one-admin roster this test used to
        // run against (a second admin legitimately makes both counts grow).
        $otherAdmins = array_diff($this->staffIds(), [$this->staffId]);

        assertSame(
            count($otherAdmins),
            $this->queuedCount('telegram'),
            'Only the other admins get the Telegram copy — the actor is skipped as a recipient.',
        );
        assertSame(
            1 + count($otherAdmins),
            $this->notificationCount(NotificationEvent::TOPUP_REQUESTED),
            'One own inbox row for the staff member, plus one fan-out copy per other admin.',
        );
    }

    /**
     * `uk_queue_dedupe` is UNIQUE, and the dedupe key is built from the event,
     * the recipient, the channel and a reference. A system alert has no id of
     * its own, so without a reference every alert for this event hashes
     * identically and `enqueue()` silently drops all but the first — forever.
     * Two browsers subscribing would produce one notification.
     */
    public function testASecondBrowserIsNotSwallowedByTheDedupeKey(): void
    {
        $this->seed();

        $this->subscribe();
        $this->watch(['--notify' => 'admins']);
        $this->watch(['--notify' => 'admins']);

        $this->subscribe(self::ENDPOINT_PREFIX . bin2hex(random_bytes(6)));
        $this->watch(['--notify' => 'admins']);

        // Scoped to the dispatch target: two alert-producing runs, two
        // alerts for the same person — the dedupe key must not swallow the
        // second. A table-wide count would also grow with every staff account
        // the live database holds, which is a roster fact, not a dedupe bug.
        $forTarget = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%notification_queue}}'
                . ' WHERE [[id]] > :id AND [[channel]] = :c AND [[user_id]] = :u',
            )
            ->bindValue(':id', $this->maxQueueId)
            ->bindValue(':c', 'webpush')
            ->bindValue(':u', $this->staffId)
            ->queryScalar();

        assertSame(
            2,
            $forTarget,
            'The second browser must produce a second alert, not vanish into a unique index.',
        );
    }

    /** A browser leaving is reported in the terminal and not pushed to staff. */
    public function testAnOptOutIsNotPushedToStaff(): void
    {
        $this->seed();

        $id = $this->subscribe();
        $this->watch(['--notify' => 'admins']);
        $before = $this->notificationCount(NotificationEvent::SYSTEM_ALERT);

        $this->subs->unsubscribe($id);
        [, $out] = $this->watch(['--notify' => 'admins']);

        assertStringContainsString('OFF', $out);
        assertSame(
            $before,
            $this->notificationCount(NotificationEvent::SYSTEM_ALERT),
            'Nobody asked to be told a browser left.',
        );
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * One discarded run, to leave a cursor behind for the test's own events.
     *
     * This is the watcher in the state an operator keeps it in: started, quiet,
     * and now watching for something. See the class docblock for why the tests
     * do not simply subscribe and watch.
     */
    private function seed(): void
    {
        $this->watch();
    }

    /**
     * How many rows the watcher will see: everything in the table, which is
     * this test's own subscription plus whatever the live site already had.
     */
    private function tableSize(): int
    {
        return count($this->foreignSubscriptionIds) + 1;
    }

    /**
     * Make the VAPID key pair visible to Env for the duration of this test.
     *
     * tests/bootstrap.php calls Environment::prepare() but deliberately never
     * loads .env: the suites run against the live development database, and
     * provider credentials are not something the whole suite should inherit.
     * This matters more here than it does elsewhere, because NotificationManager
     * refuses to queue a webpush job when `VapidKeys::fromEnv()` is null — so
     * without the keys the staff-alert assertions would pass vacuously and a
     * future change that stopped queueing alerts entirely would go unnoticed.
     *
     * @see \App\Tests\Functional\WebPushOptOutTest::loadVapidKeys()
     */
    private function loadVapidKeys(): void
    {
        $this->previousEnv = [
            'VAPID_SUBJECT' => $_ENV['VAPID_SUBJECT'] ?? null,
            'VAPID_PRIVATE_KEY' => $_ENV['VAPID_PRIVATE_KEY'] ?? null,
        ];

        if (VapidKeys::fromEnv() !== null) {
            return;
        }

        /** @var array<string, string> $values */
        $values = \Dotenv\Dotenv::createArrayBacked(dirname(__DIR__, 2))->safeLoad();
        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if (isset($values[$name]) && $values[$name] !== '') {
                $_ENV[$name] = $values[$name];
            }
        }

        assertTrue(
            VapidKeys::fromEnv() !== null,
            'This test cannot assert anything about push without VAPID keys in .env.'
                . ' Generate a pair with: php scripts/generate-vapid-keys.php mailto:ops@example.com'
        );
    }

    private function subscribe(?string $endpoint = null): int
    {
        return $this->subs->subscribe(
            $endpoint ?? $this->endpoint,
            random_bytes(65),
            random_bytes(16),
            null,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: int, 1: string}
     */
    private function watch(array $options = []): array
    {
        $command = new PushWatchCommand($this->subs, $this->users, $this->notify);

        $tester = new CommandTester($command);
        $exit = $tester->execute(
            $options + ['--once' => true, '--state' => $this->cursor],
            ['decorated' => false],
        );

        return [$exit, $tester->getDisplay()];
    }

    /** Notification rows this test's own runs added, above the pre-test high-water mark. */
    private function notificationCount(string $event): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification}} WHERE [[id]] > :id AND [[event]] = :e')
            ->bindValue(':id', $this->maxNotificationId)
            ->bindValue(':e', $event)
            ->queryScalar();
    }

    /** Queue rows this test's own runs added, above the pre-test high-water mark. */
    private function queuedCount(string $channel): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification_queue}} WHERE [[id]] > :id AND [[channel]] = :c')
            ->bindValue(':id', $this->maxQueueId)
            ->bindValue(':c', $channel)
            ->queryScalar();
    }

    /**
     * The recipient list NotificationManager actually fans out to, same query.
     * @return int[]
     */
    private function staffIds(): array
    {
        return array_map(
            static fn (mixed $id): int => (int) $id,
            $this->db
                ->createCommand(
                    "SELECT [[id]] FROM {{%user}} WHERE [[role]] IN ('admin','staff')"
                    . " AND [[status]] = 'active' AND [[deleted_at]] IS NULL",
                )
                ->queryColumn(),
        );
    }
}
