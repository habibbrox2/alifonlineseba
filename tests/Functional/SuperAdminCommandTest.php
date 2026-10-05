<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Console\SuperAdminCommand;
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
 * app:super-admin — the only door into the pages that pay people out.
 *
 * `/admin/staff` is itself behind `SuperAdminMiddleware`, so a fresh install has
 * nobody able to appoint the first super-admin, and a locked-out operator has
 * no page to ask on. Everything this command does therefore has to be provable
 * without the web: it must write the exact role the guard reads
 * (`Identity::ROLE_SUPERADMIN`), leave a usable password behind, restore an
 * account that had been trashed, write nothing at all when re-run, and — the
 * one that matters most — write nothing when it says it will write nothing.
 *
 * Every test creates its own throwaway account and removes it in _after();
 * nothing here touches a real operator's row. Activity-log rows are removed by
 * id range, because the command logs with `user_id = NULL` (there is no
 * signed-in operator at a shell) and those rows belong to nobody to clean up by
 * foreign key.
 */
final class SuperAdminCommandTest extends \Codeception\Test\Unit
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

    public function testCreatingASuperAdminWritesTheRoleTheGuardReads(): void
    {
        $username = 'sa_new_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username]);
        $id = $this->idOf($username);
        $this->createdUserIds[] = $id;

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        assertSame(Identity::ROLE_SUPERADMIN, (string) $this->users->findById($id)['role']);
        assertSame('active', (string) $this->users->findById($id)['status']);
    }

    public function testTheGeneratedPasswordIsPrintedOnceAndActuallyWorks(): void
    {
        $username = 'sa_pw_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username]);
        $id = $this->idOf($username);
        $this->createdUserIds[] = $id;

        // The whole point of generating it: the operator has to be able to sign
        // in afterwards, from the string the command printed and nothing else.
        assertMatchesRegularExpression('/Password \(shown once\): ([0-9a-f]{24})/', $tester->getDisplay(), $tester->getDisplay());
        preg_match('/Password \(shown once\): ([0-9a-f]{24})/', $tester->getDisplay(), $m);

        $hash = (string) $this->users->findById($id)['password_hash'];
        assertTrue(password_verify($m[1], $hash), 'The printed password must verify against the stored hash.');
    }

    public function testPromotingAnExistingAccountAppointsIt(): void
    {
        $username = 'sa_promote_' . bin2hex(random_bytes(4));
        $id = $this->createUser($username, 'admin');
        $tester = $this->tester();

        $tester->execute(['--promote' => $username]);

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        $row = $this->users->findById($id);
        assertSame(Identity::ROLE_SUPERADMIN, (string) $row['role']);
        assertSame('active', (string) $row['status']);
        assertStringContainsString('is now a super-admin', $tester->getDisplay());
    }

    /**
     * Re-running is the normal case in a setup script that ran twice, and it
     * must not invent a second owner or touch the row.
     */
    public function testRunningTwiceWritesNothingTheSecondTime(): void
    {
        $username = 'sa_twice_' . bin2hex(random_bytes(4));
        $id = $this->createUser($username, 'user');
        $tester = $this->tester();

        $tester->execute(['--promote' => $username]);
        $firstUpdated = (string) $this->users->findById($id)['updated_at'];

        $tester->execute(['--promote' => $username]);

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        assertStringContainsString('Already an active super-admin', $tester->getDisplay());
        assertSame($firstUpdated, (string) $this->users->findById($id)['updated_at']);
    }

    /**
     * A trashed account still reserves its username, so a plain lookup finds
     * nothing and the operator is pushed back to editing the database by hand.
     */
    public function testAPromotedTrashedAccountIsRestored(): void
    {
        $username = 'sa_trash_' . bin2hex(random_bytes(4));
        $id = $this->createUser($username, 'admin');
        $this->users->update($id, ['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'suspended']);
        $tester = $this->tester();

        $tester->execute(['--promote' => $username]);

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        $row = $this->users->findById($id);
        assertSame(Identity::ROLE_SUPERADMIN, (string) $row['role']);
        assertSame('active', (string) $row['status']);
        assertTrue($row['deleted_at'] === null, 'A promoted account must not stay trashed.');
    }

    public function testATakenUsernameIsRefusedRatherThanDuplicated(): void
    {
        $username = 'sa_taken_' . bin2hex(random_bytes(4));
        $this->createUser($username, 'user');
        $tester = $this->tester();

        $tester->execute(['--username' => $username]);

        assertSame(Command::INVALID, $tester->getStatusCode());
        assertStringContainsString('is taken', $tester->getDisplay());
    }

    public function testAShortPasswordIsRefusedBeforeAnythingIsWritten(): void
    {
        $username = 'sa_short_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--password' => 'short']);

        assertSame(Command::INVALID, $tester->getStatusCode());
        assertTrue(!$this->users->usernameExists($username), 'A refused password must leave no account behind.');
    }

    public function testDryRunWritesNothing(): void
    {
        $username = 'sa_dry_' . bin2hex(random_bytes(4));
        $tester = $this->tester();

        $tester->execute(['--username' => $username, '--dry-run' => true]);

        assertSame(Command::SUCCESS, $tester->getStatusCode());
        assertStringContainsString('Nothing was written', $tester->getDisplay());
        assertTrue(!$this->users->usernameExists($username));
    }

    /**
     * "Who made this admin a super-admin" has to have an answer that does not
     * depend on anybody remembering — including for an account appointed from
     * a shell, where there is no signed-in actor to record.
     */
    public function testEveryPromotionIsWrittenToTheActivityLog(): void
    {
        $username = 'sa_log_' . bin2hex(random_bytes(4));
        $id = $this->createUser($username, 'staff');
        $tester = $this->tester();

        $tester->execute(['--promote' => $username]);

        $row = $this->db
            ->createCommand('SELECT * FROM {{%activity_log}} WHERE [[action]] = :a ORDER BY [[id]] DESC LIMIT 1')
            ->bindValue(':a', 'admin.superadmin_promoted')
            ->queryOne();

        assertTrue(is_array($row), 'The promotion must leave an activity-log row.');
        assertTrue($row['user_id'] === null, 'A CLI appointment has no signed-in actor to name.');
        assertTrue(str_contains((string) $row['description'], $username));
        assertSame(['subject_id' => $id], json_decode((string) $row['metadata'], true));
    }

    /**
     * The command's first line is a statement about who currently holds
     * platform authority, and it is printed before it changes anything — so the
     * run *after* a promotion is the one that has to name the holder.
     *
     * Both branches are asserted loosely on purpose: this suite runs against a
     * development database that may already have a real super-admin in it, and a
     * test that asserted "no super-admin exists" would start failing the moment
     * the operator did their job.
     */
    public function testTheRosterLineAlwaysStatesWhoIsInCharge(): void
    {
        $tester = $this->tester();
        $tester->execute(['--username' => 'sa_roster_a_' . bin2hex(random_bytes(4)), '--dry-run' => true]);
        assertMatchesRegularExpression('/(No super-admin exists|Current super-admin\(s\):)/', $tester->getDisplay());

        $username = 'sa_roster_' . bin2hex(random_bytes(4));
        $this->createUser($username, 'user');
        $tester->execute(['--promote' => $username]);

        $tester->execute(['--promote' => $username]);
        assertStringContainsString('Current super-admin(s):', $tester->getDisplay());
        assertStringContainsString($username, $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SuperAdminCommand($this->users, $this->logs));
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