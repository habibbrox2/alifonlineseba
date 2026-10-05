<?php

declare(strict_types=1);

namespace App\Console;

use App\Notification\Channel\FcmChannel;
use App\Notification\Channel\TelegramChannel;
use App\Notification\Channel\WhatsAppChannel;
use App\Notification\Channel\WebPushChannel;
use App\Notification\QueueRepository;
use App\Repository\DeviceRepository;
use App\Repository\BotConnectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The queue worker, invoked by cron every minute:
 *
 *   * * * * * cd /home/user/alif_tools && php yii app:notification:work --limit=200
 *
 * One tick claims a batch, sends each through its channel driver, records
 * delivery, and exits 0 — a single bad row must not kill the batch.
 */
#[AsCommand('app:notification:work', 'Drain the notification queue (cron worker).')]
final class NotificationWorkCommand extends Command
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly FcmChannel $fcm,
        private readonly TelegramChannel $telegram,
        private readonly WebPushChannel $webpush,
        private readonly WhatsAppChannel $whatsapp,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max jobs per tick', (string) \App\Env::int('NOTIFY_QUEUE_BATCH', 200));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $backoffBase = \App\Env::int('NOTIFY_BACKOFF_BASE', 60);

        $jobs = $this->queue->claimBatch($limit);
        if ($jobs === []) {
            $io->text('Queue empty.');
            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $dead = 0;

        foreach ($jobs as $job) {
            $id = (int) $job['id'];
            $channel = (string) $job['channel'];
            $userId = (int) ($job['user_id'] ?? 0);
            $payload = json_decode((string) ($job['payload'] ?? '{}'), true) ?: [];

            try {
                $result = $this->deliver($channel, $userId, $payload);
            } catch (\Throwable $e) {
                // A row that throws must not take the batch with it. This is
                // the same isolation `app:bulk:work` already has, and the
                // docblock above promises; without it a host that is missing a
                // function (ext-curl disabled, openssl gone), a database blip,
                // or a credentials file that will not parse kills the worker on
                // its first job — every tick, forever, with nothing logged and
                // the claimed rows stuck until the next reclaim. The row goes
                // back for retry with the reason recorded, and the rest of the
                // batch is still sent; `max_attempts` keeps a poison row from
                // retrying for good.
                $message = trim($e->getMessage());
                $this->queue->markFailed(
                    $id,
                    $message !== '' ? $message : $e::class,
                    retryable: true,
                    backoffBase: $backoffBase,
                );
                $failed++;
                continue;
            }

            if ($result->ok) {
                $this->queue->markSent($id, $result->providerMessageId, $result->latencyMs);
                $sent++;
                continue;
            }

            $this->queue->markFailed($id, $result->error, $result->retryable, $backoffBase);
            if ($result->retryable) {
                $failed++;
            } else {
                $dead++;
            }
        }

        $io->success(sprintf('Processed %d: %d sent, %d scheduled for retry, %d dead-lettered.', count($jobs), $sent, $failed, $dead));
        return Command::SUCCESS;
    }

    /**
     * One job, one channel driver — the part that is allowed to throw, and is
     * caught by the loop above.
     *
     * @param array<string, mixed> $payload
     */
    private function deliver(string $channel, int $userId, array $payload): \App\Notification\Channel\DeliveryResult
    {
        return match ($channel) {
            'fcm' => $this->fcm->send($userId, $payload),
            'telegram' => $this->telegram->send($userId, $payload),
            // One job, one user, every browser they have left watching —
            // the fan-out is inside WebPushChannel, so this is the same
            // shape as the two above.
            'webpush' => $this->webpush->send($userId, $payload),
            // Number-based, opt-in: the fan-out only queued this row because
            // `user.whatsapp_no` is filled in. Until the driver existed the
            // match had no arm for it, and every WhatsApp row dead-lettered as
            // "Unknown channel" — the notifications were being written and
            // then thrown away.
            'whatsapp' => $this->whatsapp->send($userId, $payload),
            // Unknown/disabled channel: dead-letter with a clear reason
            // instead of retrying something that can never succeed.
            default => \App\Notification\Channel\DeliveryResult::permanent("Unknown channel '{$channel}'."),
        };
    }
}
