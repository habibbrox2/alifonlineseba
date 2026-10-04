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

        // An empty or whitespace-only DB_CHARSET has to fall back to utf8mb4
        // here rather than being passed through. Env::get() only falls back when
        // the key is *absent*, so a .env carrying a blank DB_CHARSET= line hands
        // back an empty string, and the driver turns that into `SET NAMES ''`,
        // which the server rejects with "Unknown character set". The deploy died
        // on exactly that before any migration ran.
        //
        // This is the charset of the *connection*, not of the tables: it decides
        // how bytes are encoded on the way in and out. The tables it creates are
        // given utf8mb4 explicitly by the migrations, because a host whose
        // database default is latin1 otherwise produces columns that cannot hold
        // Bengali.
        $charset = trim((string) Env::get('DB_CHARSET', 'utf8mb4'));
        $driver->charset($charset === '' ? 'utf8mb4' : $charset);

        $cachePath = dirname(__DIR__, 3) . '/' . (string) Env::get('CACHE_PATH', 'runtime/cache');
        $fileCache = new FileCache($cachePath);

        return new MysqlConnection($driver, new SchemaCache($fileCache));
    },

    CacheInterface::class => static fn (): FileCache => new FileCache(
        dirname(__DIR__, 3) . '/' . (string) Env::get('CACHE_PATH', 'runtime/cache')
    ),
];
