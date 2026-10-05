<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create — or promote — a super-admin. The bootstrap path for platform authority.
 *
 * `superadmin` is a `user.role` value, and every page that hands money to
 * somebody is behind `SuperAdminMiddleware`, which only a holder of that role
 * may pass. Appointing the first one happens *on* the page that only a
 * super-admin may open, so a fresh install has no way in: `app:seed` creates an
 * `admin`, and `AdminStaffAction` refuses everybody but an existing
 * super-admin, and the ledger's last-super-admin guard refuses to let the
 * roster empty itself. The CLI is the one place outside the web that can name
 * the platform owner, which is exactly what this is for.
 *
 *   php yii app:super-admin                              # create `superadmin`
 *   php yii app:super-admin --username=owner --phone=01712345678
 *   php yii app:super-admin --promote=admin              # make an account one
 *   php yii app:super-admin --promote=admin --password=…
 *   php yii app:super-admin --dry-run
 *
 * Two rules keep this from being a back door around the web panel's guards:
 *
 * - **The password is never read from the command line by default.** Omit
 *   `--password` and a strong one is generated and printed once — a password
 *   passed as an argument ends up in shell history, in `ps`, and in the CI log
 *   of whoever runs the deploy.
 * - **Every write is logged.** The actor is null (there is no user at a shell),
 *   the target id is in the metadata, so `/admin/activity-logs` can answer
 *   "who is in charge, and when did that change" for an account that was never
 *   appointed through the roster.
 *
 * Idempotent by design: re-running it against an account that is already a
 * super-admin changes nothing and says so, which is what makes it safe in a
 * `scripts/setup.sh` that may run twice.
 */
#[AsCommand('app:super-admin', 'Creates or promotes a super-admin account (payouts + staff authority).')]
final class SuperAdminCommand extends Command
{
    /** Matches `AuthService::register()` so a CLI account can sign in like any other. */
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_.]{3,64}$/';

    private const PHONE_PATTERN = '/^01[3-9]\d{8}$/';

    /** Stricter than the web form's 6: this is the account that pays people out. */
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $logs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'username',
                'u',
                InputOption::VALUE_REQUIRED,
                'Username for the new account. Default: superadmin.'
            )
            ->addOption(
                'promote',
                'p',
                InputOption::VALUE_REQUIRED,
                'Promote this existing account (username or phone) instead of creating one. A trashed account is restored.'
            )
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Password to set. Omit it and a strong one is generated and printed once.'
            )
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Mobile number for the new account. Generated when omitted.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Optional email address for the new account.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing.')
            ->setHelp(
                "The first super-admin has to be created here: /admin/staff is guarded by\n"
                . "SuperAdminMiddleware, so nobody can appoint one before one exists.\n\n"
                . "Creates a new account by default; --promote=ID turns an account that\n"
                . "already exists into the platform owner instead (role=superadmin,\n"
                . "status=active, restored if it had been trashed).\n\n"
                . "Re-running against an account that is already a super-admin writes nothing."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $promote = trim((string) ($input->getOption('promote') ?? ''));

        $password = (string) ($input->getOption('password') ?? '');
        if ($password !== '' && strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH));
            return Command::INVALID;
        }
        if ($password !== '') {
            // Said out loud every time, because the alternative — a password
            // nobody chose, sitting in a deploy script — is worse.
            $io->warning('--password is visible in shell history and `ps`. Prefer omitting it.');
        }

        $this->reportCurrentRoster($io);

        $result = $promote !== ''
            ? $this->promote($io, $promote, $password, $dryRun)
            : $this->create($io, $input, $password, $dryRun);

        if ($result !== null) {
            return $result;
        }

        return Command::SUCCESS;
    }

    /**
     * Who is already in charge, before and after. A command whose whole point
     * is a role has no business running without saying who holds it.
     */
    private function reportCurrentRoster(SymfonyStyle $io): void
    {
        $holders = [];
        foreach ($this->users->listStaff() as $row) {
            if ((string) $row['role'] === Identity::ROLE_SUPERADMIN) {
                $holders[] = sprintf('#%d %s (%s)', (int) $row['id'], (string) $row['username'], (string) $row['status']);
            }
        }

        $io->text($holders === []
            ? 'No super-admin exists right now — payouts and staff management are unreachable.'
            : 'Current super-admin(s): ' . implode(', ', $holders));
    }

    /**
     * Create the account. Returns a non-zero exit code on refusal, or null when
     * the command has done (or deliberately not done) its work.
     */
    private function create(SymfonyStyle $io, InputInterface $input, string $password, bool $dryRun): ?int
    {
        $username = trim((string) ($input->getOption('username') ?? ''));
        if ($username === '') {
            $username = 'superadmin';
        }
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $io->error('Username must be 3–64 characters: letters, numbers, dot and underscore only.');
            return Command::INVALID;
        }
        if ($this->users->usernameExists($username)) {
            $io->error(sprintf(
                'Username "%s" is taken — every username stays reserved even for a trashed account. Use --promote=%s instead.',
                $username,
                $username,
            ));
            return Command::INVALID;
        }

        $phone = trim((string) ($input->getOption('phone') ?? ''));
        if ($phone !== '' && !preg_match(self::PHONE_PATTERN, $phone)) {
            $io->error('Phone must be a Bangladeshi mobile number, e.g. 01712345678.');
            return Command::INVALID;
        }
        if ($phone === '') {
            $phone = $this->freePhone();
        } elseif ($this->users->phoneExists($phone)) {
            $io->error(sprintf('Phone %s is already registered. Pass --promote=%s if that account is the one.', $phone, $phone));
            return Command::INVALID;
        }

        $email = trim((string) ($input->getOption('email') ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Email is not a valid address.');
            return Command::INVALID;
        }

        // Generated rather than prompted: this runs from a deploy script as often
        // as from a shell, and `ask()` in a non-TTY reads EOF and returns null.
        $secret = $password !== '' ? $password : $this->generatePassword();

        $io->title($dryRun ? 'Dry run — no rows will be written' : 'Creating a super-admin');
        $io->definitionList(
            ['username' => $username],
            ['phone' => $phone],
            ['email' => $email !== '' ? $email : '(none)'],
            ['password' => $password !== '' ? '(the one you passed)' : $secret],
            ['pages unlocked' => '/admin/staff, /admin/withdraws, /admin/withdraws/{id}, platform-wide /admin/ledger?scope=all'],
        );

        if ($dryRun) {
            $io->note('Nothing was written. Re-run without --dry-run to create the account.');
            return null;
        }

        $userId = $this->users->create([
            'username' => $username,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'password_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => Identity::ROLE_SUPERADMIN,
            'balance' => 0,
        ]);

        $this->log($userId, 'created', sprintf('Super-admin account %s created via CLI', $username));

        $io->success(sprintf('Super-admin #%d (%s) created.', $userId, $username));
        $this->printNext($io, $username, $password !== '' ? null : $secret);

        return null;
    }

    /**
     * Promote an account that already exists.
     */
    private function promote(SymfonyStyle $io, string $identifier, string $password, bool $dryRun): ?int
    {
        // Trashed rows included: an operator who was demoted and trashed while
        // locked out still has their username reserved, so a plain lookup says
        // "no such account" and the recovery turns into a manual database edit.
        $row = $this->users->findByIdentifier($identifier, true);
        if ($row === null) {
            $io->error(sprintf('No account matches "%s" (username or mobile number).', $identifier));
            return Command::INVALID;
        }

        $id = (int) $row['id'];
        $trashed = ($row['deleted_at'] ?? null) !== null;
        $role = (string) $row['role'];
        $status = (string) $row['status'];
        $unchanged = $role === Identity::ROLE_SUPERADMIN && $status === 'active' && !$trashed && $password === '';

        $io->title($dryRun ? 'Dry run — no rows will be written' : 'Promoting an account');
        $io->definitionList(
            ['account' => sprintf('#%d %s (%s)', $id, (string) $row['username'], (string) $row['phone'])],
            ['role' => sprintf('%s -> %s', $role, Identity::ROLE_SUPERADMIN)],
            ['status' => $trashed ? 'trashed -> active (restored)' : $status],
            ['password' => $password !== '' ? 'reset to the one you passed' : 'unchanged'],
        );

        if ($unchanged) {
            $io->success('Already an active super-admin — nothing to do.');
            return null;
        }
        if ($dryRun) {
            $io->note('Nothing was written. Re-run without --dry-run to apply.');
            return null;
        }

        $this->users->update($id, [
            'role' => Identity::ROLE_SUPERADMIN,
            'status' => 'active',
            'deleted_at' => null,
        ]);
        if ($password !== '') {
            $this->users->update($id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        $this->log($id, 'promoted', sprintf(
            'Super-admin %s via CLI (was role=%s status=%s)',
            (string) $row['username'],
            $role,
            $trashed ? 'trashed' : $status,
        ));

        $io->success(sprintf('%s is now a super-admin.', (string) $row['username']));
        $this->printNext($io, (string) $row['username'], null);

        return null;
    }

    /**
     * What the person actually has to do next. A super-admin row is not a
     * working login on its own, and the fastest way to find that out is to try.
     */
    private function printNext(SymfonyStyle $io, string $username, ?string $generated): void
    {
        if ($generated !== null) {
            $io->note(sprintf('Password (shown once): %s', $generated));
        }
        $io->text([
            sprintf('Sign in at /login with "%s".', $username),
            'Pages that were refusing everybody now open: /admin/staff and /admin/withdraws.',
            'The sidebar picks up "উত্তোলন অনুরোধ" and "স্টাফ পরিচালনা" on the next load.',
        ]);
        $io->warning('Change the password after the first sign-in; anyone with it can pay money out.');
    }

    /**
     * The activity-log row. `user_id` is null on purpose — there is no signed-in
     * operator at a shell, and inventing one would put a fake actor in the audit
     * trail that the panel's own "who appointed you" question reads.
     */
    private function log(int $subjectId, string $action, string $description): void
    {
        $this->logs->create([
            'user_id' => null,
            'action' => 'admin.superadmin_' . $action,
            'description' => $description,
            'ip_address' => null,
            'user_agent' => 'cli',
            'metadata' => ['subject_id' => $subjectId],
        ]);
    }

    /**
     * A phone number nobody holds yet.
     *
     * `user.phone` is NOT NULL and UNIQUE, so this cannot simply be left blank.
     * A generated one is printed in the summary: the owner signs in by username
     * and can replace the number from /profile once it matters that SMS reaches
     * them.
     */
    private function freePhone(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $phone = '01' . random_int(3, 9) . random_int(0, 99999999);
            $phone = str_pad($phone, 11, '0');
            if (preg_match(self::PHONE_PATTERN, $phone) && !$this->users->phoneExists($phone)) {
                return $phone;
            }
        }

        throw new \RuntimeException('Could not find a free phone number in 20 attempts.');
    }

    /** 24 hex characters — no symbols to lose to a copy/paste. */
    private function generatePassword(): string
    {
        return bin2hex(random_bytes(12));
    }
}