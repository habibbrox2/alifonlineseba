<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\ActivityLogRepository;
use App\Repository\RoleRankRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Initialize or reset the role_rank table with default values.
 *
 * The role_rank table stores the customizable role hierarchy ranks set by
 * superadmin. This command ensures the table has the default ranks:
 * - superadmin: 100
 * - admin: 50
 * - staff: 25
 * - user: 10
 *
 *   php yii app:role-rank-init              # initialize with defaults
 *   php yii app:role-rank-init --reset      # delete existing and reinitialize
 *   php yii app:role-rank-init --dry-run    # show what would change
 *
 * Useful after a fresh migration or when the table has been corrupted.
 */
#[AsCommand('app:role-rank-init', 'Initializes the role_rank table with default role hierarchy values.')]
final class RoleRankInitCommand extends Command
{
    /** Default role ranks matching Identity::DEFAULT_ROLE_RANK */
    private const DEFAULT_RANKS = [
        'superadmin' => 100,
        'admin' => 50,
        'staff' => 25,
        'user' => 10,
    ];

    public function __construct(
        private readonly RoleRankRepository $roleRanks,
        private readonly ActivityLogRepository $logs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'reset',
                'r',
                InputOption::VALUE_NONE,
                'Delete existing ranks before initializing (use with caution)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would change without writing'
            )
            ->setHelp(
                "Initializes the role_rank table with the default role hierarchy:\\n"
                . "  superadmin = 100\\n"
                . "  admin      = 50\\n"
                . "  staff      = 25\\n"
                . "  user       = 10\\n\\n"
                . "Use --reset to delete existing rows first (forces reinitialization).\\n"
                . "Use --dry-run to preview changes without writing."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $reset = (bool) $input->getOption('reset');

        // Get current state
        $existing = $this->roleRanks->allDetailed();
        $hasExisting = !empty($existing);

        if ($reset) {
            $io->title($dryRun ? 'Dry run — reset and reinitialize' : 'Resetting and reinitializing role_rank table');
        } else {
            $io->title($dryRun ? 'Dry run — initializing role_rank table' : 'Initializing role_rank table');
        }

        if ($hasExisting && !$reset) {
            $io->text('Current role ranks in database:');
            $tableData = [];
            foreach ($existing as $row) {
                $tableData[] = [
                    (string) $row['role'],
                    (int) $row['rank'],
                    $row['updated_by'] !== null ? '#' . $row['updated_by'] : '(default)',
                    $row['updated_at'],
                ];
            }
            $io->table(['Role', 'Rank', 'Updated By', 'Updated At'], $tableData);
            $io->text('');
        }

        $io->text('Default role ranks to apply:');
        $tableData = [];
        foreach (self::DEFAULT_RANKS as $role => $rank) {
            $current = $existing[$role]['rank'] ?? 'N/A';
            $status = $current === $rank ? 'unchanged' : ($current === 'N/A' ? 'will add' : 'will update');
            $tableData[] = [$role, $rank, $current, $status];
        }
        $io->table(['Role', 'Default Rank', 'Current', 'Action'], $tableData);

        if ($dryRun) {
            if ($reset) {
                $io->note('Dry run: no rows were deleted or inserted. Re-run without --dry-run to apply.');
            } else {
                $io->note('Dry run: no rows were inserted. Re-run without --dry-run to apply.');
            }
            return Command::SUCCESS;
        }

        if ($reset && $hasExisting) {
            // Delete all existing rows
            $this->roleRanks->deleteAll();
            $io->note('Existing role ranks deleted.');
        }

        // Insert or update default ranks
        $inserted = 0;
        $updated = 0;
        foreach (self::DEFAULT_RANKS as $role => $rank) {
            if (isset($existing[$role])) {
                if ($existing[$role]['rank'] !== $rank) {
                    $this->roleRanks->update($role, $rank);
                    $updated++;
                }
            } else {
                $this->roleRanks->create($role, $rank);
                $inserted++;
            }
        }

        if ($inserted > 0 || $updated > 0) {
            $this->logs->create([
                'user_id' => null,
                'action' => 'admin.role_rank_initialized',
                'description' => sprintf(
                    'Role rank table initialized: %d inserted, %d updated',
                    $inserted,
                    $updated
                ),
                'ip_address' => null,
                'user_agent' => 'cli',
                'metadata' => [
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'reset' => $reset,
                ],
            ]);
        }

        if ($inserted > 0 || $updated > 0) {
            $io->success(sprintf('Done: %d inserted, %d updated.', $inserted, $updated));
        } else {
            $io->success('All role ranks already match defaults — nothing to do.');
        }

        // Show final state
        $final = $this->roleRanks->allDetailed();
        $io->text('Final role ranks:');
        $tableData = [];
        foreach ($final as $row) {
            $tableData[] = [
                (string) $row['role'],
                (int) $row['rank'],
                $row['updated_by'] !== null ? '#' . $row['updated_by'] : '(default)',
                $row['updated_at'],
            ];
        }
        $io->table(['Role', 'Rank', 'Updated By', 'Updated At'], $tableData);

        return Command::SUCCESS;
    }
}