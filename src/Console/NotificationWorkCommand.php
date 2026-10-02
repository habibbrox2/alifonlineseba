<?php

declare(strict_types=1);

namespace App\Console;

use App\Notification\Channel\FcmChannel;
use App\Notification\Channel\TelegramChannel;
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

            $result = match ($channel) {
                'fcm' => $this->fcm->send($userId, $payload),
                'telegram' => $this->telegram->send($userId, $payload),
                // Unknown/disabled channel: dead-letter with a clear reason
                // instead of retrying something that can never succeed.
                default => \App\Notification\Channel\DeliveryResult::permanent("Unknown channel '{$channel}'."),
            };

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
}
