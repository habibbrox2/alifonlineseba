<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\BulkJobRepository;
use App\Service\BulkJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bulk-job worker, invoked by cron every minute:
 *
 *   * * * * * cd /home/user/alif_tools && php yii app:bulk:work
 *
 * One tick claims a job, runs it to completion a chunk at a time and exits 0.
 *
 * `--limit` exists so a tick cannot be talked into monopolising a shared
 * host's single cron slot: the queue is drained a job at a time, and a cron
 * that overruns stops every *other* cron the site depends on — which is the
 * exact failure the queue was built to avoid, arriving by a different road.
 */
#[AsCommand('app:bulk:work', 'Drain the admin bulk-action queue (cron worker).')]
final class BulkWorkCommand extends Command
{
    /** A job that has failed this many times is treated as poison, not as bad luck. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly BulkJobRepository $jobs,
        private readonly BulkJobService $bulk,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max jobs per tick', '10')
            ->addOption('stale', null, InputOption::VALUE_REQUIRED, 'Seconds before a processing job is assumed dead', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $stale = max(30, (int) $input->getOption('stale'));

        $done = 0;
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->jobs->claimNext($stale);
            if ($job === null) {
                break;
            }

            $id = (int) $job['id'];

            try {
                $message = $this->bulk->run($job);
                $io->writeln(sprintf('<info>#%d</info> %s', $id, $message));
            } catch (\Throwable $e) {
                // A thrown chunk is the one thing the loop cannot absorb: the
                // job keeps its cursor, so retrying resumes rather than repeats.
                // Log what went wrong before deciding whether to try again —
                // a job that is merely flaky and one that is permanently broken
                // look identical from here, and only the error text separates them.
                $this->jobs->markFailed($id, $e->getMessage());
                $io->writeln(sprintf('<error>#%d failed:</error> %s', $id, $e->getMessage()));

                $row = $this->jobs->findById($id);
                if ($row !== null && (int) $row['attempts'] < self::MAX_ATTEMPTS) {
                    $this->jobs->requeue($id);
                    $io->writeln(sprintf('  requeued (attempt %d)', (int) $row['attempts'] + 1));
                }
            }

            $done++;
        }

        $io->success($done === 0 ? 'Queue empty.' : sprintf('Ran %d job(s).', $done));

        return Command::SUCCESS;
    }
}
