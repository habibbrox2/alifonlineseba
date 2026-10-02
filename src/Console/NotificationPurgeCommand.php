<?php

declare(strict_types=1);

namespace App\Console;

use App\Notification\QueueRepository;
use App\Repository\DeviceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention purge — run daily by cron:
 *
 *   0 3 * * * cd /home/user/alif_tools && php yii app:notification:purge
 *
 * Keeps the queue and device tables from growing without bound (audit risk #13).
 */
#[AsCommand('app:notification:purge', 'Purge old sent/dead queue rows and deactivated devices (retention).')]
final class NotificationPurgeCommand extends Command
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly DeviceRepository $devices,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // NOTIFY_RETENTION_DAYS is how long a *sent* row is kept as an audit
        // trail; dead rows go after 30 days because nothing can act on them.
        // The two are different on purpose — sent rows are the record that a
        // notification really was delivered.
        $sentDays = max(1, (int) \App\Env::int('NOTIFY_RETENTION_DAYS', 180));

        $queuePurged = $this->queue->purge($sentDays, 30);
        $devicesPurged = $this->devices->purgeInactive(90);

        $io->success(sprintf('Purged %d queue rows and %d inactive devices.', $queuePurged, $devicesPurged));
        return Command::SUCCESS;
    }
}
