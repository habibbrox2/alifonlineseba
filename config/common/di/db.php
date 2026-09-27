<?php

declare(strict_types=1);

use App\Env;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;

/** @var array $params */

$dsn = (string) Env::get('DB_DSN');

return [
    ConnectionInterface::class => static function () use ($dsn) {
        $driver = new MysqlDriver($dsn, (string) Env::get('DB_USERNAME'), (string) Env::get('DB_PASSWORD'));
        $driver->charset((string) Env::get('DB_CHARSET', 'utf8mb4'));

        $cachePath = dirname(__DIR__, 3) . '/' . (string) Env::get('CACHE_PATH', 'runtime/cache');
        $fileCache = new FileCache($cachePath);

        return new MysqlConnection($driver, new SchemaCache($fileCache));
    },

    CacheInterface::class => static fn (): FileCache => new FileCache(
        dirname(__DIR__, 3) . '/' . (string) Env::get('CACHE_PATH', 'runtime/cache')
    ),
];
