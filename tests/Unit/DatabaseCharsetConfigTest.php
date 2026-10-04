<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The connection charset is the one piece of database config that cannot fail
 * quietly.
 *
 * PDO reads `charset=` straight out of the DSN string and rejects an empty value
 * with "SQLSTATE[HY000] [2019] Unknown character set" — thrown inside
 * PDO::__construct, before the driver exists and long before anything can log a
 * useful hint. On the shared host that killed the deploy at the database
 * pre-flight while the credentials were perfectly valid, so the failure named the
 * wrong thing entirely.
 *
 * Env::get() only falls back to a default when a key is *absent*, so a .env
 * written with a trailing `charset=` yields an empty string rather than the
 * intended value. db.php normalises both that and a blank DB_CHARSET; these
 * tests pin the behaviour down by loading the real config file.
 */
final class DatabaseCharsetConfigTest extends Unit
{
    private const CONFIG_FILE = __DIR__ . '/../../config/common/di/db.php';

    /**
     * Build the DSN exactly as config/common/di/db.php does.
     *
     * The file returns DI definitions whose closures capture the normalised DSN,
     * so the DSN is recovered by asking the connection for it rather than by
     * duplicating the logic here — that way this test fails if the config
     * changes, instead of testing a copy of it.
     */
    private function dsnFor(string $rawDsn): string
    {
        $previous = $_ENV['DB_DSN'] ?? null;
        $_ENV['DB_DSN'] = $rawDsn;

        try {
            $definitions = require self::CONFIG_FILE;
            $factory = $definitions[\Yiisoft\Db\Connection\ConnectionInterface::class];

            // The closure builds a real connection; a broken DSN would throw
            // here, which is itself the assertion we want for the blank cases.
            $connection = $factory();
            $dsn = (string) $connection->getDriver()->getDsn();

            return $dsn;
        } finally {
            if ($previous === null) {
                unset($_ENV['DB_DSN']);
            } else {
                $_ENV['DB_DSN'] = $previous;
            }
        }
    }

    public function testBlankCharsetInTheDsnBecomesUtf8mb4(): void
    {
        // The exact shape that broke production: a trailing `charset=` with
        // nothing after it.
        assertSame(
            'mysql:host=localhost;dbname=test;charset=utf8mb4',
            $this->dsnFor('mysql:host=localhost;dbname=test;charset='),
        );
    }

    public function testTrailingSeparatorAfterBlankCharsetIsCleanedUp(): void
    {
        // `charset=;` must not leave a stray separator behind.
        assertSame(
            'mysql:host=localhost;dbname=test;charset=utf8mb4',
            $this->dsnFor('mysql:host=localhost;dbname=test;charset=;'),
        );
    }

    public function testWhitespaceOnlyCharsetIsTreatedAsBlank(): void
    {
        assertSame(
            'mysql:host=localhost;charset=utf8mb4;dbname=test',
            $this->dsnFor('mysql:host=localhost;charset=   ;dbname=test'),
        );
    }

    public function testAnExplicitCharsetIsLeftAlone(): void
    {
        assertSame(
            'mysql:host=localhost;dbname=test;charset=utf8mb4',
            $this->dsnFor('mysql:host=localhost;dbname=test;charset=utf8mb4'),
        );
    }

    public function testANonDefaultCharsetIsNotOverwritten(): void
    {
        // If a host genuinely needs latin1, the app must not overrule it.
        assertSame(
            'mysql:host=localhost;charset=latin1;dbname=test',
            $this->dsnFor('mysql:host=localhost;charset=latin1;dbname=test'),
        );
    }

    public function testADsnWithoutACharsetIsUnchanged(): void
    {
        assertSame(
            'mysql:host=localhost;dbname=test',
            $this->dsnFor('mysql:host=localhost;dbname=test'),
        );
    }

    public function testAParameterMerelyStartingWithCharsetIsNotMistakenForOne(): void
    {
        assertSame(
            'mysql:host=localhost;charsetmode=y;dbname=test',
            $this->dsnFor('mysql:host=localhost;charsetmode=y;dbname=test'),
        );
    }

    public function testTheConnectionActuallyOpensWithABlankCharsetInTheDsn(): void
    {
        // The unit above compares strings; this proves the resulting DSN is one
        // PDO accepts, against a real server. Without it, a normalisation that
        // merely looks right could still fail at PDO::__construct.
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $name = getenv('DB_NAME') ?: 'th_tools';
        $user = getenv('DB_USERNAME');
        if ($user === false) {
            $user = 'root';
        }

        $dsn = $this->dsnFor(sprintf('mysql:host=%s;dbname=%s;charset=', $host, $name));

        $pdo = new \PDO($dsn, $user, (string) (getenv('DB_PASSWORD') ?: ''));
        $pdo->query('SELECT 1');

        assertSame(1, 1, 'connecting with the normalised DSN should succeed');
    }
}