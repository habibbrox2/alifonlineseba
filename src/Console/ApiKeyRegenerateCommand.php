<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Rewrite legacy API keys into the current Alif Tools format.
 *
 * Accounts created before the rebrand carry a `TH_…` key. Anything the outside
 * world has cached — an integration, a shell script, a shared key in a support
 * chat — is holding that string, so the command is deliberately *rotating* the
 * value rather than adding a second one: there is one `user.api_key` column and
 * the old prefix is simply no longer produced anywhere.
 *
 *   php yii app:api-key:regenerate --dry-run     # preview
 *   php yii app:api-key:regenerate               # apply
 *   php yii app:api-key:regenerate --user=12     # one account
 *   php yii app:api-key:regenerate --include-empty
 *
 * `--dry-run` is the default behaviour of nothing at all: the command only ever
 * writes rows it would have written, and prints the mapping either way, so the
 * operator has a record of which key became which before it happens.
 */
#[AsCommand('app:api-key:regenerate', 'Rewrite legacy TH_ API keys into the AL_ format.')]
final class ApiKeyRegenerateCommand extends Command
{
    /**
     * Keys are only 8 hex characters, so 32 bits of entropy — a birthday
     * collision across a large user base is not the interesting failure. The
     * UNIQUE index on the column is, so the retry loop below is what actually
     * keeps this command safe to run twice.
     */
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user id.')
            ->addOption(
                'include-empty',
                null,
                InputOption::VALUE_NONE,
                'Also mint a key for accounts that have none yet (NULL or blank).'
            )
            ->setHelp(
                "Legacy keys use the TH_ prefix and are rewritten to AL_.\n"
                . "Only rows whose current key does not already start with AL_ are touched,\n"
                . "so the command is idempotent and safe to re-run after a partial failure."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $userId = $input->getOption('user');
        $includeEmpty = (bool) $input->getOption('include-empty');

        // The backslash matters: '_' is a LIKE wildcard, so an unescaped 'AL_%'
        // would also match "ALX…" and silently skip keys that need rewriting.
        $where = '[[api_key]] NOT LIKE :al';
        $params = [':al' => 'AL\_%'];

        if ($includeEmpty) {
            $where = '([[api_key]] IS NULL OR [[api_key]] = :blank OR ' . $where . ')';
            $params[':blank'] = '';
        }

        if ($userId !== null) {
            if (!ctype_digit((string) $userId)) {
                $io->error('--user must be a numeric user id.');
                return Command::INVALID;
            }
            $where .= ' AND [[id]] = :id';
            $params[':id'] = (int) $userId;
        }

        $rows = $this->db->createCommand('SELECT [[id]], [[username]], [[api_key]] FROM {{%user}} WHERE ' . $where . ' ORDER BY [[id]]')
            ->bindValues($params)
            ->queryAll();

        if ($rows === []) {
            $io->success('Nothing to do — every key is already in the AL_ format.');
            return Command::SUCCESS;
        }

        $io->title($dryRun ? 'Dry run — no rows will be written' : 'Regenerating API keys');
        $io->text(sprintf('%d account(s) matched.', count($rows)));

        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders(['ID', 'Username', 'Old key', 'New key']);

        $changed = 0;
        foreach ($rows as $row) {
            $newKey = $this->uniqueKey();
            $table->addRow([(string) $row['id'], (string) $row['username'], (string) ($row['api_key'] ?? '(none)'), $newKey]);

            if (!$dryRun) {
                $this->db->createCommand()->update(
                    '{{%user}}',
                    ['api_key' => $newKey, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $row['id']],
                )->execute();
            }
            $changed++;
        }

        $table->render();

        if ($dryRun) {
            $io->note(sprintf('%d key(s) would be rewritten. Re-run without --dry-run to apply.', $changed));
            return Command::SUCCESS;
        }

        $io->success(sprintf('Rewrote %d key(s) to the AL_ format.', $changed));
        $io->warning('Existing integrations using the old keys must be updated by their owners.');
        return Command::SUCCESS;
    }

    /**
     * Generate a key that is not already taken.
     *
     * A collision would blow up the UNIQUE index and abort the whole command
     * mid-way, leaving a half-rotated table — which is exactly the state the
     * operator would then have to reason about. Cheap to check, so just check.
     */
    private function uniqueKey(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $key = UserRepository::generateApiKey();
            $taken = $this->db->createCommand('SELECT 1 FROM {{%user}} WHERE [[api_key]] = :key LIMIT 1')
                ->bindValue(':key', $key)
                ->queryScalar();
            if ($taken === false) {
                return $key;
            }
        }

        throw new \RuntimeException(
            sprintf('Could not find a free API key in %d attempts; is the table full?', self::MAX_ATTEMPTS)
        );
    }
}
