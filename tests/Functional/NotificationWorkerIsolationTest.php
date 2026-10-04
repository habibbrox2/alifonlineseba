<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Notification\QueueRepository;
use App\Repository\DeviceRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The worker's per-row isolation: a channel that throws must cost one row,
 * not the batch.
 *
 * ## Why this is a shared-hosting test
 *
 * `app:notification:work` is started by a one-line cPanel cron and its output
 * goes to a log nobody reads until something looks wrong. When a driver threw
 * — a host shipping PHP without ext-curl, an openssl call that fails, a
 * credentials file that will not parse — the exception escaped `execute()`,
 * the process died on the first row of the batch, and every following tick
 * claimed the same rows and died the same way. Nothing was logged, `Sent`
 * never moved, and the queue depth on /admin/notifications climbed forever:
 * a failure with no visible cause, which is the worst kind to hand an
 * operator.
 *
 * ## Why a subprocess
 *
 * The throw is produced the way a shared host produces one — a function
 * disabled in php.ini that the account owner cannot re-enable — so the run
 * goes through the real `yii` entry point, exactly as cron invokes it. An
 * in-process harness cannot disable a function after startup, and a mocked
 * channel would test a stand-in rather than the code path the cron runs.
 *
 * The child's environment pins every channel: FCM is made available (so it
 * reaches the disabled call) and Telegram / Web Push are made unavailable (so
 * nothing in this run can reach the network).
 */
final class NotificationWorkerIsolationTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private QueueRepository $queue;
    private UserRepository $users;
    private DeviceRepository $devices;

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    private int $userId = 0;

    /** @var int[] */
    private array $queueIds = [];

    private string $credentials = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->queue = new QueueRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->devices = new DeviceRepository($this->db);

        // A service-account JSON that parses, so isAvailable() is satisfied
        // and the worker gets as far as signing the OAuth assertion.
        $this->credentials = sys_get_temp_dir() . '/fcm-iso-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($this->credentials, json_encode([
            'client_email' => 'iso@test.iam.gserviceaccount.com',
            'private_key' => 'not-a-key',
        ]));

        foreach ([
            'FIREBASE_CREDENTIALS_PATH' => $this->credentials,
            'FIREBASE_PROJECT_ID' => 'iso-test',
        ] as $name => $value) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = $value;
        }
    }

    protected function _after(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        $this->previousEnv = [];

        if ($this->queueIds !== []) {
            $this->db->createCommand()->delete('{{%notification_delivery}}', ['queue_id' => $this->queueIds])->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['id' => $this->queueIds])->execute();
            $this->queueIds = [];
        }
        if ($this->userId > 0) {
            $this->db->createCommand()->delete('{{%notification_device}}', ['user_id' => $this->userId])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $this->userId])->execute();
            $this->userId = 0;
        }
        if ($this->credentials !== '' && is_file($this->credentials)) {
            @unlink($this->credentials);
        }
    }

    public function testAThrowingRowDoesNotTakeTheBatchWithIt(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled, cannot start the worker');
        }

        $this->userId = $this->makeUser();
        // FCM only reaches the credentials exchange when the user has a device
        // to send to; without one it reports success and never touches the
        // code that throws.
        $this->devices->upsert($this->userId, 'iso-device-' . bin2hex(random_bytes(8)), 'android', null, null);

        $throwing = $this->enqueue('fcm');
        $afterwards = $this->enqueue('sms'); // unknown channel — never throws

        [$exit, $output] = $this->runWorker();

        // The worker survived: without the catch this is where the Error
        // surfaced, the process exited 255, and neither row was settled.
        assertSame(0, $exit, "The worker must exit 0 when a row throws:\n{$output}");

        $row = $this->row($throwing);
        // The message proves *this* row threw — the ordinary failure branch
        // would have written "FCM OAuth token fetch failed." instead — so the
        // assertions below cannot go green without the catch being exercised.
        assertStringContainsString('openssl_sign', (string) $row['last_error']);
        assertSame(1, (int) $row['attempts'], 'One throw is one attempt, not a silent success.');
        assertSame('queued', (string) $row['status'], 'A thrown row goes back for retry with its reason recorded.');

        // …and the row queued behind it was still handled. This is the half
        // that says the loop *continued*, not merely that it did not die.
        $next = $this->row($afterwards);
        assertSame('dead', (string) $next['status'], 'The row after the throw must still be processed.');
        assertStringContainsString("Unknown channel 'sms'", (string) $next['last_error']);
    }

    /**
     * Run `app:notification:work` the way cron does, under a php.ini that has
     * disabled the function the FCM driver needs.
     *
     * @return array{0: int, 1: string} exit code, combined output
     */
    private function runWorker(): array
    {
        $root = codecept_root_dir();
        $environment = getenv();
        if (!\is_array($environment)) {
            self::fail('Could not read the environment to hand it to the worker.');
        }

        // Pinned per channel: FCM must be *available* (otherwise it returns
        // early and never reaches the disabled function), while Telegram and
        // Web Push must not be — nothing in this run may reach the network.
        // phpdotenv is immutable, so these survive the .env load, and the
        // values are picked up from $_SERVER because variables_order has S.
        $environment['FIREBASE_CREDENTIALS_PATH'] = $this->credentials;
        $environment['FIREBASE_PROJECT_ID'] = 'iso-test';
        $environment['VAPID_PRIVATE_KEY'] = '';
        $environment['VAPID_SUBJECT'] = '';
        $environment['TELEGRAM_BOT_TOKEN'] = '';

        $process = @proc_open(
            [PHP_BINARY, '-d', 'disable_functions=openssl_sign', $root . 'yii', 'app:notification:work', '--limit=200'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        if (!\is_resource($process)) {
            self::fail('Could not start the worker subprocess.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout . "\n" . $stderr];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->db
            ->createCommand('SELECT * FROM {{%notification_queue}} WHERE [[id]] = :id')
            ->bindValue(':id', $id)
            ->queryOne();
    }

    private function enqueue(string $channel): int
    {
        $id = $this->queue->enqueue([
            'event' => 'topup.approved',
            'user_id' => $this->userId,
            'channel' => $channel,
            'max_attempts' => 3,
            'payload' => ['title' => 't', 'body' => 'b', 'data' => ['reference' => 'ISO' . $channel]],
            'dedupe_key' => 'iso|' . $this->userId . '|' . $channel . '|' . bin2hex(random_bytes(8)),
        ]);
        assertSame(true, $id !== null);
        $this->queueIds[] = (int) $id;

        return (int) $id;
    }

    private function makeUser(): int
    {
        return $this->users->create([
            'username' => 'iso_' . substr(md5(uniqid('', true)), 0, 8),
            'phone' => '8' . substr(md5(uniqid('', true)), 0, 9),
            'email' => null,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
        ]);
    }
}
