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

// A blank charset in the DSN has to be corrected before the driver is built.
// PDO reads `charset=` from the DSN string itself and rejects an empty value
// outright with "Unknown character set", so this fails inside PDO::__construct
// — before any of the handling below can run, and before the driver ever issues
// SET NAMES. Env::get() only falls back to a default when the key is absent,
// so a .env written with a trailing `charset=` hands back an empty string
// instead of the intended value.
//
// Only the parameters are rebuilt; the `mysql:` prefix is left untouched,
// because getDsn() returns it and re-adding it would produce `mysql:mysql:...`.
[$driverName, $dsnParameters] = array_pad(explode(':', $dsn, 2), 2, '');

$parameters = [];
foreach (explode(';', $dsnParameters) as $parameter) {
    $parameter = trim($parameter);
    if ($parameter === '') {
        continue; // a trailing `;`, or the one left behind by `charset=;`
    }
    // `charset` or `charset=` with nothing after it is the blank case;
    // `charset=utf8mb4` (or any real value) is left exactly as written.
    if ($parameter === 'charset' || preg_match('/^charset\s*=\s*$/i', $parameter) === 1) {
        $parameter = 'charset=utf8mb4';
    }
    $parameters[] = $parameter;
}
$dsn = $driverName . ':' . implode(';', $parameters);

return [
    ConnectionInterface::class => static function () use ($dsn) {
        $driver = new MysqlDriver(
            $dsn,
            (string) Env::get('DB_USERNAME'),
            (string) Env::get('DB_PASSWORD'),
        );

        // Same blank-value hazard as the DSN above, one layer later: an empty
        // DB_CHARSET becomes `SET NAMES ''`, which the server also rejects with
        // "Unknown character set".
        //
        // This is the charset of the *connection*, not of the tables: it decides
        // how bytes are encoded on the way in and out. The tables the migrations
        // create are given utf8mb4 explicitly, because a host whose database
        // default is latin1 otherwise produces columns that cannot hold Bengali.
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
