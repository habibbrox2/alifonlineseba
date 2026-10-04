<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Console\WebPushCheckCommand;
use App\Notification\Channel\WebPushChannel;
use App\Notification\Push\VapidKeys;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * app:webpush:check — the diagnostic every Web Push outage gets pointed at.
 *
 * The command earns its keep in exactly one situation: push is silently doing
 * nothing and nobody can tell which stage broke. So the assertions here are not
 * "the command runs" but "each stage it claims to prove is actually proved, and
 * each stage it cannot prove says so" — a checker that returns green while the
 * thing it exists to detect is broken is worse than no checker, because it
 * closes the investigation.
 *
 * Driven in-process through CommandTester rather than by shelling out to
 * `php yii`, so a failure points at a line in this file instead of an exit code
 * from a subprocess whose stderr nobody captured.
 *
 * The service worker path is injected at a temp file. The real public/push-sw.js
 * belongs to a live site; a test that renames it to prove the missing-file path
 * would be testing a real outage, not a real code path.
 */
final class WebPushCheckCommandTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private PushSubscriptionRepository $subs;

    /** @var array<string, string|null> */
    private array $previousEnv = [];
    private string $tempDir = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->subs = new PushSubscriptionRepository($this->db);

        $this->tempDir = sys_get_temp_dir() . '/wpcheck-' . substr(md5(uniqid('', true)), 0, 10);
        mkdir($this->tempDir, 0o777, true);

        $this->loadVapidKeys();
    }

    protected function _after(): void
    {
        foreach (['VAPID_SUBJECT', 'VAPID_PRIVATE_KEY'] as $name) {
            if (($this->previousEnv[$name] ?? null) === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = (string) $this->previousEnv[$name];
            }
        }
        $this->previousEnv = [];

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    // ---- The key stage -----------------------------------------------------

    public function testValidKeysAreProvedAndNotMerelyPrinted(): void
    {
        [$exit, $out] = $this->check();

        assertSame(Command::SUCCESS, $exit);
        assertStringContainsString('P-256', $out, 'A loadable key pair is the claim being made.');
        assertStringContainsString('public/push-sw.js', $out);
    }

    /**
     * The subject is what the push service emails a human about when it decides
     * to revoke a key, so a blank one is a real operational problem — not a
     * cosmetic one.
     */
    public function testBlankSubjectIsCalledOutRatherThanShownAsAnEmptyRow(): void
    {
        $previous = $_ENV['VAPID_SUBJECT'] ?? null;
        $_ENV['VAPID_SUBJECT'] = '';

        try {
            [, $out] = $this->check();
        } finally {
            if ($previous === null) {
                unset($_ENV['VAPID_SUBJECT']);
            } else {
                $_ENV['VAPID_SUBJECT'] = $previous;
            }
        }

        assertStringContainsString('not set', $out);
    }

    // ---- The service worker stage -----------------------------------------

    /**
     * This is the stage every other check waves through: keys load, the table
     * is populated, the push service returns 201 — and the browser still shows
     * nothing, because a 404 on /push-sw.js means there is no worker to hand
     * the message to.
     */
    public function testMissingServiceWorkerFailsTheCommand(): void
    {
        [$exit, $out] = $this->check(['--path' => $this->tempDir . '/absent.js']);

        assertSame(Command::FAILURE, $exit);
        assertStringContainsString('missing', $out);
    }

    /**
     * Empty is not the same as absent and is the likelier botched deploy: an
     * empty file parses without complaint and only fails to ever call
     * showNotification.
     */
    public function testEmptyServiceWorkerIsReportedSeparatelyFromMissing(): void
    {
        $path = $this->tempDir . '/push-sw.js';
        file_put_contents($path, '');

        [$exit, $out] = $this->check(['--path' => $path]);

        assertSame(Command::FAILURE, $exit);
        assertStringContainsString('empty', $out);
        assertStringNotContainsString('is missing', $out);
    }

    public function testPresentServiceWorkerPasses(): void
    {
        $path = $this->tempDir . '/push-sw.js';
        file_put_contents($path, "self.addEventListener('push', () => {});");

        [$exit, $out] = $this->check(['--path' => $path]);

        assertSame(Command::SUCCESS, $exit);
        assertStringContainsString('present and non-empty', $out);
    }

    // ---- The census stage --------------------------------------------------

    /**
     * A bare run must not claim more than it proved. The census is printed
     * before any send precisely so "there is nobody to send to" and "the send
     * failed" stay distinguishable.
     */
    public function testBareRunSendsNothingAndSaysSo(): void
    {
        [$exit, $out] = $this->check();

        assertSame(Command::SUCCESS, $exit);
        assertStringContainsString('nothing was sent', $out);
        assertStringContainsString('Subscriptions', $out);
    }

    // ---- Option validation -------------------------------------------------

    public function testNonNumericUserIsRejectedBeforeAnyLookup(): void
    {
        [$exit, $out] = $this->check(['--user' => 'abc']);

        assertSame(Command::FAILURE, $exit);
        assertStringContainsString('positive user id', $out);
    }

    public function testZeroAndNegativeUserIdsAreRejected(): void
    {
        foreach (['0', '-1'] as $raw) {
            [$exit, $out] = $this->check(['--user' => $raw]);
            assertSame(Command::FAILURE, $exit, "{$raw} must not be treated as a user id.");
            assertStringContainsString('positive user id', $out);
        }
    }

    public function testUnknownUserIdFailsWithItsOwnMessage(): void
    {
        [$exit, $out] = $this->check(['--user' => '99999999']);

        assertSame(Command::FAILURE, $exit);
        assertStringContainsString('No live user', $out);
        assertStringNotContainsString('positive user id', $out);
    }

    /**
     * An endpoint that is not in the table cannot be encrypted for — without
     * the stored p256dh/auth there is nothing to encrypt to. Saying "not
     * stored" is the honest answer; attempting the POST would produce a
     * confusing error from the push service instead.
     */
    public function testUnknownEndpointFailsRatherThanPostingBlindly(): void
    {
        [$exit, $out] = $this->check(['--endpoint' => 'https://push.example.invalid/push/nope']);

        assertSame(Command::FAILURE, $exit);
        assertStringContainsString('No stored subscription', $out);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * @param array<string, string> $options `--path` is this test's seam for the
     *        service worker location; it never reaches the command itself.
     * @return array{int, string}
     */
    private function check(array $options = []): array
    {
        $path = $options['--path'] ?? null;
        unset($options['--path']);

        $command = new WebPushCheckCommand(
            new WebPushChannel($this->subs, $this->users),
            $this->subs,
            $this->users,
            $path,
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute($options, ['decorated' => false]);

        return [$exit, $tester->getDisplay()];
    }

    /**
     * Same reasoning as WebPushOptOutTest: tests/bootstrap.php never loads .env,
     * and a key-stage assertion with no keys present would fail for a reason
     * that has nothing to do with the code under test.
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
            'app:webpush:check cannot assert anything without VAPID keys in .env.'
                . ' Generate a pair with: php scripts/generate-vapid-keys.php mailto:ops@example.com'
        );
    }
}