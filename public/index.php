<?php

declare(strict_types=1);

use App\Environment;
use Yiisoft\Config\Modifier\RecursiveMerge;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = dirname(__DIR__);

require_once $root . '/src/bootstrap.php';

// PHP built-in server routing (development).
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (is_file(__DIR__ . $path)) {
        return false;
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

$runner = new HttpApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    nestedParamsGroups: ['params', 'params-console'],
    configModifiers: [
        // Allow vendor packages to declare the same param key (last one wins).
        RecursiveMerge::groups('params', 'params-web', 'params-console'),
    ],
);

$runner->run();
