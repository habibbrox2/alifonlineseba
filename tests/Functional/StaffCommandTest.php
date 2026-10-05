<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Console\StaffCommand;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * app:staff — how a live database hires anybody.
 *
 * `/admin/staff` promotes an account but never inserts one, and `app:seed`
 * skips every user once the database has rows, so this command is the only way
 * a deployed site gets a second operator. That makes three things worth
 * pinning down, and the third is the reason this file exists:
 *
 * - it writes a role the rest of the app recognises (`admin`/`staff`, both in
 *   `Identity::STAFF_ROLES`);
 * - the password it prints is the only copy the operator will ever have;
 * - **it cannot mint a super-admin.** `app:super-admin` is a separate command,
 *   and a hire command that could also appoint platform authority would quietly
 *   merge the two powers this codebase keeps apart.
 *
 * Every test creates its own throwaway account and removes it in _after().
 * Activity-log rows go by id range, because the command logs with
 * `user_id = NULL` (nobody is signed in at a shell) and those rows belong to
 * nobody to clean up by foreign key.
 */
final class StaffCommandTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private ActivityLogRepository $logs;

    /** Ids this test created; deleted in _after(). */
    private array $createdUserIds = [];
    /** Activity-log rows above this id are this test's, and only this test's. */
    private int $maxLogIdBefore = 0;

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->logs = new ActivityLogRepository($this->db);

        $this->maxLogIdBefore = (int) $this->db
            ->createCommand('SELECT MAX([[id]]) FROM {{%activity_log}}')
            ->queryScalar();
    }

    protected function _after(): void
    {
        foreach ($this->createdUserIds as $id) {
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->db
            ->createCommand('DELETE FROM {{%activity_log}} WHERE [[id]] > :id')
            ->bindValue(':id', $this->maxLogIdBefore)
            ->execute();
    }

    public function testCreatingAnAdminWritesARoleTheRestOfTheAppAccepts(): void
    {
        $username = 'st_admin_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--role' => 'admin']);
        $id = $this->idOf($username);
        $this->createdUserIds[] = $id;

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        $row = $this->users->findById($id);
        assertSame('admin', (string) $row['role']);
        assertSame('active', (string) $row['status']);
        assertTrue(in_array((string) $row['role'], Identity::STAFF_ROLES, true), 'An admin must be a staff role.');
    }

    public function testCreatingAStaffMemberWritesTheLowerRole(): void
    {
        $username = 'st_staff_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--role' => 'staff']);
        $id = $this->idOf($username);
        $this->createdUserIds[] = $id;

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        assertSame('staff', (string) $this->users->findById($id)['role']);
    }

    /**
     * The generated password is the operator's only copy — nothing in the
     * application can mail it to them, because nobody is signed in at a shell.
     */
    public function testTheGeneratedPasswordIsPrintedOnceAndActuallyWorks(): void
    {
        $username = 'st_pw_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username]);
        $id = $this->idOf($username);
        $this->createdUserIds[] = $id;

        assertMatchesRegularExpression('/Password \(shown once\): ([0-9a-f]{24})/', $tester->getDisplay(), $tester->getDisplay());
        preg_match('/Password \(shown once\): ([0-9a-f]{24})/', $tester->getDisplay(), $m);

        $hash = (string) $this->users->findById($id)['password_hash'];
        assertTrue(password_verify($m[1], $hash), 'The printed password must verify against the stored hash.');
    }

    /**
     * `app:super-admin` is a deliberate, separate door. If this command could
     * appoint platform authority too, the two would be one command wearing two
     * names, and the audit trail would stop saying which door was used.
     */
    public function testItCannotMintASuperAdmin(): void
    {
        $username = 'st_super_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--role' => 'superadmin']);

        assertSame(Command::INVALID, $tester->getStatusCode());
        assertStringContainsString('app:super-admin', $tester->getDisplay());
        assertTrue(!$this->users->usernameExists($username), 'A refused role must leave no account behind.');
    }

    /** An unknown role must not fall through to a default that means something. */
    public function testAnUnknownRoleIsRefused(): void
    {
        $username = 'st_bogus_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--role' => 'manager']);

        assertSame(Command::INVALID, $tester->getStatusCode());
        assertTrue(!$this->users->usernameExists($username));
    }

    public function testATakenUsernameIsRefusedRatherThanDuplicated(): void
    {
        $username = 'st_taken_' . bin2hex(random_bytes(4));
        $this->createUser($username, 'user');
        $tester = $this->tester();

        $tester->execute(['--username' => $username]);

        assertSame(Command::INVALID, $tester->getStatusCode());
        assertStringContainsString('is taken', $tester->getDisplay());
    }

    public function testAShortPasswordIsRefusedBeforeAnythingIsWritten(): void
    {
        $username = 'st_short_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--password' => 'short']);

        assertSame(Command::INVALID, $tester->getStatusCode());
        assertTrue(!$this->users->usernameExists($username), 'A refused password must leave no account behind.');
    }

    public function testDryRunWritesNothing(): void
    {
        $username = 'st_dry_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--dry-run' => true]);

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        assertStringContainsString('Nothing was written', $tester->getDisplay());
        assertTrue(!$this->users->usernameExists($username));
    }

    /**
     * A hire made at a shell still has to be attributable afterwards, and there
     * is no signed-in actor to name — so the row carries the target id.
     */
    public function testEveryHireIsWrittenToTheActivityLog(): void
    {
        $username = 'st_log_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--role' => 'staff']);
        $id = $this->idOf($username);
        $this->createdUserIds[] = $id;

        $row = $this->db
            ->createCommand('SELECT * FROM {{%activity_log}} WHERE [[action]] = :a ORDER BY [[id]] DESC LIMIT 1')
            ->bindValue(':a', 'admin.staff_created')
            ->queryOne();

        assertTrue(is_array($row), 'A hire must leave an activity-log row.');
        assertTrue($row['user_id'] === null, 'A CLI hire has no signed-in actor to name.');
        assertTrue(str_contains((string) $row['description'], $username));
        assertSame(['subject_id' => $id, 'role' => 'staff'], json_decode((string) $row['metadata'], true));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new StaffCommand($this->users, $this->logs));
    }

    private function createUser(string $username, string $role): int
    {
        $id = $this->users->create([
            'username' => $username,
            'phone' => $this->freePhone(),
            'password_hash' => password_hash('Owner1234!', PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function idOf(string $username): int
    {
        return (int) $this->db
            ->createCommand('SELECT [[id]] FROM {{%user}} WHERE [[username]] = :u')
            ->bindValue(':u', $username)
            ->queryScalar();
    }

    private function freePhone(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $phone = '01' . random_int(3, 9) . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            if (!$this->users->phoneExists($phone)) {
                return $phone;
            }
        }

        throw new \RuntimeException('No free phone number for the test account.');
    }
}
