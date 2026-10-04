<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Notification\MessageTemplates;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The notification templates are written in Bengali, and a Bengali title in a
 * latin1 column is a fatal error on a strict server.
 *
 * This is not a fresh-install problem: `createTable` names utf8mb4, so a new
 * database never sees it. It is the host that ran M240109 *before* that charset
 * was stated. There the table already exists, `createTable` reports errno 1050
 * and is skipped, the columns stay latin1, and the seed dies:
 *
 *   SQLSTATE[22007] ... Incorrect string value: '\xE0\xA6\xB8...'
 *   for column `notification_template`.`title` at row 1
 *
 * And because that aborts the migration, the conversion migration written to
 * repair it never runs — on that run or any later one. The host could not
 * migrate its way out, which is how production got stuck.
 *
 * The fixture below is that host: the real table is moved aside, a latin1 one
 * takes its name, and the migration is run for real against it.
 *
 * One test, and the swap is undone in `finally` rather than in an `_after`
 * hook: this test moves a real table out from under the rest of the suite, so
 * nothing may be allowed to skip the restore — including a failing assertion.
 */
final class NotificationTemplateCharsetTest extends \Codeception\Test\Unit
{
    private const BACKUP_SUFFIX = '__charset_fixture';

    public function testMigrationRepairsALatin1TemplateTableBeforeSeedingBengali(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        /** @var ConnectionInterface $db */
        $db = $container->get(ConnectionInterface::class);

        $table = $db->getTablePrefix() . 'notification_template';
        $backup = $table . self::BACKUP_SUFFIX;
        $q = static fn (string $name): string => $db->getQuoter()->quoteTableName($name);

        // SHOW VARIABLES, not `SELECT @@SESSION sql_mode`: the driver rewrites
        // the `@@SESSION` form into SQL the server rejects.
        $mode = $db->createCommand("SHOW VARIABLES LIKE 'sql_mode'")->queryOne();
        $originalSqlMode = (string) ($mode['Value'] ?? '');

        try {
            // Strict mode is the host's condition and not this server's: the
            // local MariaDB runs without STRICT_TRANS_TABLES, so without this
            // an unfixed migration stores "??????!" and the test would pass on
            // the very bug that takes production down.
            $db->createCommand("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'")->execute();

            $db->createCommand('DROP TABLE IF EXISTS ' . $q($backup))->execute();
            $db->createCommand('RENAME TABLE ' . $q($table) . ' TO ' . $q($backup))->execute();

            try {
                // The shape M240109 used to create: no charset named, so the
                // column inherits the database default — latin1 on cPanel.
                $db->createCommand(
                    'CREATE TABLE ' . $q($table) . ' (
                        id INTEGER PRIMARY KEY AUTO_INCREMENT NOT NULL,
                        event VARCHAR(64) NOT NULL,
                        channel VARCHAR(16) NOT NULL,
                        locale VARCHAR(8) NOT NULL DEFAULT \'bn\',
                        title VARCHAR(190) NOT NULL,
                        body TEXT NULL,
                        external_id VARCHAR(190) NULL,
                        created_at DATETIME NOT NULL,
                        updated_at DATETIME NOT NULL
                    ) CHARACTER SET latin1 COLLATE latin1_swedish_ci'
                )->execute();

                $this->assertRepairedAndSeeded($db, $table, $q($table));

                // The conversion migration that was written for this runs after
                // M240109, and must now find everything already correct.
                require_once codecept_root_dir() . 'migrations/M240121000000_ConvertNotificationTablesToUtf8mb4.php';
                $conversion = new \M240121000000_ConvertNotificationTablesToUtf8mb4();
                $conversion->up(new MigrationBuilder($db, new NullMigrationInformer()));

                assertSame(
                    count(MessageTemplates::seedRows()),
                    (int) $db->createCommand('SELECT COUNT(*) FROM ' . $q($table))->queryScalar(),
                    're-running the conversion migration changed the seeded rows',
                );
            } finally {
                $db->createCommand('DROP TABLE IF EXISTS ' . $q($table))->execute();
                $db->createCommand('RENAME TABLE ' . $q($backup) . ' TO ' . $q($table))->execute();
            }
        } finally {
            if (preg_match('/^[A-Za-z0-9_,]*$/', $originalSqlMode) === 1) {
                // SET takes no bind parameter, and the value is a mode list
                // read straight back from this server — anything else is left
                // alone rather than interpolated.
                $db->createCommand("SET SESSION sql_mode = '" . $originalSqlMode . "'")->execute();
            }
        }
    }

    /**
     * Everything the repair exists to guarantee, in the order it fails: the
     * migration finishing at all, then the charset, then the bytes.
     */
    private function assertRepairedAndSeeded(ConnectionInterface $db, string $table, string $quotedTable): void
    {
        try {
            require_once codecept_root_dir() . 'migrations/M240109000000_CreateNotificationInfrastructure.php';
            $migration = new \M240109000000_CreateNotificationInfrastructure();
            $migration->up(new MigrationBuilder($db, new NullMigrationInformer()));
        } catch (\Throwable $e) {
            $this->fail(
                "M240109 aborts on a host that already has a latin1 notification_template,\n"
                . "which leaves production unable to migrate at all:\n  " . $e->getMessage() . "\n\n"
                . 'The migration must convert the table to utf8mb4 before it seeds.'
            );
        }

        $status = $db
            ->createCommand('SHOW TABLE STATUS LIKE :name')
            ->bindValue(':name', $table)
            ->queryOne();

        assertNotEmpty($status, 'the fixture table is missing; the test did not set up');
        assertSame(
            'utf8mb4_unicode_ci',
            (string) $status['Collation'],
            'M240109 left notification_template in a charset that cannot hold Bengali',
        );

        $expected = [];
        foreach (MessageTemplates::seedRows() as $row) {
            $expected[$row['event'] . '|' . $row['channel']] = $row['title'];
        }

        $seeded = $db->createCommand(
            'SELECT [[event]], [[channel]], [[title]] FROM ' . $quotedTable
        )->queryAll();

        assertNotEmpty($seeded, 'the migration seeded no templates at all');

        $titles = [];
        foreach ($seeded as $row) {
            $titles[$row['event'] . '|' . $row['channel']] = (string) $row['title'];
        }

        // Byte-for-byte, because the silent failure on a non-strict server is
        // not an error at all: the row lands holding "??????!" instead of
        // Bengali. Comparing against the compiled-in copy catches that too.
        $lost = [];
        foreach ($expected as $key => $title) {
            if (!array_key_exists($key, $titles)) {
                $lost[] = $key . ' (missing)';
                continue;
            }
            if ($titles[$key] !== $title) {
                $lost[] = sprintf('%s: got "%s", want "%s"', $key, $titles[$key], $title);
            }
        }

        assertTrue(
            $lost === [],
            "Templates did not survive the migration:\n  " . implode("\n  ", $lost)
        );
        assertSame(count($expected), count($titles), 'unexpected template rows were seeded');
    }
}