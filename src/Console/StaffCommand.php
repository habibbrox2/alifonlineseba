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
 * Create an `admin` or a `staff` account.
 *
 * The sibling of `app:super-admin`, which is the one door into the pages that
 * hand money out and manage the roster. This one opens the *next* door: the
 * roster itself can only promote an account that already exists
 * (`AdminStaffAction` edits rows; it never inserts one), and `app:seed` creates
 * a single demo `admin` and then skips every user on a database that already
 * has rows. So on a live database there is no way to hire anybody — which is
 * exactly the state a deployment finds itself in.
 *
 *   php yii app:staff                                  # create `admin.user`
 *   php yii app:staff --username=karim --role=admin
 *   php yii app:staff --username=helper --role=staff
 *   php yii app:staff --dry-run
 *
 * The same three rules `app:super-admin` keeps, because this command is the
 * same kind of thing:
 *
 * - **The password is generated unless one is passed.** A password typed on a
 *   command line ends up in shell history and in `ps`.
 * - **Every write is logged**, with the actor left null because nobody is
 *   signed in at a shell.
 * - **Re-running is safe.** A username stays reserved even after the account is
 *   trashed, so the refusal points at `app:super-admin --promote` / the roster
 *   instead of silently creating a near-duplicate.
 *
 * Roles are deliberately limited to the two that are *not* `superadmin`. Anyone
 * who can name a super-admin can also run `app:super-admin`, and this command
 * being unable to mint one keeps the two powers visibly separate.
 */
#[AsCommand('app:staff', 'Creates an admin or staff account (order approval + roster).')]
final class StaffCommand extends Command
{
    /** Matches `AuthService::register()` so a CLI account can sign in like any other. */
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_.]{3,64}$/';

    private const PHONE_PATTERN = '/^01[3-9]\d{8}$/';

    /** Matches the roster page, which also refuses anything under eight. */
    private const MIN_PASSWORD_LENGTH = 8;

    /** The roles this command may write. `superadmin` is deliberately absent. */
    private const ROLES = ['admin', 'staff'];

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
                'Username for the new account. Default: admin.<username-less suffix>.'
            )
            ->addOption(
                'role',
                'r',
                InputOption::VALUE_REQUIRED,
                'admin (can approve orders and request a payout) or staff (can only verify). Default: admin.'
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
                "Hires an admin or a staff member.\n\n"
                . "/admin/staff can promote an account but cannot create one, and\n"
                . "app:seed only fills an empty database — so this is how a live site\n"
                . "gets a second operator.\n\n"
                . "role=admin may approve orders (which earns them money) and request a\n"
                . "withdrawal of their own. role=staff may only verify orders and earns\n"
                . "nothing. Neither can approve a payout or touch the ledger — that is\n"
                . "app:super-admin, and this command will not mint one."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $role = strtolower(trim((string) ($input->getOption('role') ?? '')));
        if ($role === '') {
            $role = 'admin';
        }
        if (!in_array($role, self::ROLES, true)) {
            $io->error(sprintf(
                'Role must be one of: %s. Use `php yii app:super-admin` for a super-admin.',
                implode(', ', self::ROLES),
            ));
            return Command::INVALID;
        }

        $password = (string) ($input->getOption('password') ?? '');
        if ($password !== '' && strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH));
            return Command::INVALID;
        }
        if ($password !== '') {
            $io->warning('--password is visible in shell history and `ps`. Prefer omitting it.');
        }

        $username = trim((string) ($input->getOption('username') ?? ''));
        if ($username === '') {
            $username = $this->freeUsername('admin');
        }
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $io->error('Username must be 3–64 characters: letters, numbers, dot and underscore only.');
            return Command::INVALID;
        }
        if ($this->users->usernameExists($username)) {
            $io->error(sprintf(
                'Username "%s" is taken — every username stays reserved even for a trashed account. Promote the existing one from /admin/staff instead.',
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
            $io->error(sprintf('Phone %s is already registered.', $phone));
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

        $io->title($dryRun ? 'Dry run — no rows will be written' : sprintf('Creating a %s account', $role));
        $io->definitionList(
            ['username' => $username],
            ['phone' => $phone],
            ['email' => $email !== '' ? $email : '(none)'],
            ['password' => $password !== '' ? '(the one you passed)' : $secret],
            ['role' => sprintf('%s — %s', $role, UserRepository::ROLE_LABELS[$role])],
            ['can approve payouts' => 'no (that is a super-admin)'],
        );

        if ($dryRun) {
            $io->note('Nothing was written. Re-run without --dry-run to create the account.');
            return Command::SUCCESS;
        }

        $userId = $this->users->create([
            'username' => $username,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'password_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => $role,
            'balance' => 0,
        ]);

        // Same shape as the super-admin command's log row: no actor, because
        // there is no signed-in operator at a shell.
        $this->logs->create([
            'user_id' => null,
            'action' => 'admin.staff_created',
            'description' => sprintf('%s account %s created via CLI', $role, $username),
            'ip_address' => null,
            'user_agent' => 'cli',
            'metadata' => ['subject_id' => $userId, 'role' => $role],
        ]);

        $io->success(sprintf('%s #%d (%s) created as %s.', ucfirst($role), $userId, $username, UserRepository::ROLE_LABELS[$role]));
        if ($secret !== $password) {
            $io->note(sprintf('Password (shown once): %s', $secret));
        }
        $io->text([
            sprintf('Sign in at /login with "%s".', $username),
            $role === 'admin'
                ? 'They can verify and approve orders, and request a withdrawal of their own earnings.'
                : 'They can verify orders only — approving one (which pays them) is an admin job.',
            sprintf('The roster at /admin/staff lists them as "%s".', UserRepository::ROLE_LABELS[$role]),
        ]);
        $io->warning('Change the password after the first sign-in, or hand it over out of band.');

        return Command::SUCCESS;
    }

    /**
     * A username nobody holds yet.
     *
     * `admin.2`, `admin.3` and so on — visibly an account made by a person at a
     * shell, which is worth having in the roster next to `app:super-admin`'s
     * named accounts.
     */
    private function freeUsername(string $prefix): string
    {
        for ($attempt = 2; $attempt < 100; $attempt++) {
            $candidate = $prefix . '.' . $attempt;
            if (!$this->users->usernameExists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf('Could not find a free "%s.N" username.', $prefix));
    }

    /**
     * A phone number nobody holds yet.
     *
     * `user.phone` is NOT NULL and UNIQUE, so this cannot simply be left blank.
     * The generated one is printed in the summary: the operator signs in by
     * username and can replace the number from /profile once SMS matters.
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
