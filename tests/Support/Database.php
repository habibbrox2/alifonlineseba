<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * One database connection for the whole test run.
 *
 * Every functional test built its own container to reach the connection, which
 * worked and then quietly cost a connection per test: the suite has 550 tests
 * against a MariaDB whose `max_connections` is 151, so a run that got far
 * enough started failing with "Too many connections" — and the failures that
 * produced looked nothing like the cause (pages rendering 500, asserts about
 * rendered HTML), which is exactly the kind of failure that sends you looking
 * in the wrong place.
 *
 * Sharing one connection also makes the tests more honest rather than less:
 * they already run against the development database (see tests/bootstrap.php),
 * they clean up after themselves, and a single connection means a leftover row
 * from one test is visible to the next exactly as it would be in production.
 *
 * The container is memoised for the same reason the connection is: thirty-odd
 * files asking for a container should not open thirty connections.
 */
final class Database
{
    private static ?ContainerInterface $container = null;

    public static function container(): ContainerInterface
    {
        return self::$container ??= new Container(
            ContainerConfig::create()->withDefinitions(
                require codecept_root_dir() . 'config/common/di/db.php',
            ),
        );
    }

    public static function connection(): ConnectionInterface
    {
        return self::container()->get(ConnectionInterface::class);
    }
}