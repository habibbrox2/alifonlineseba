<?php

declare(strict_types=1);

use App\Environment;
use Yiisoft\Config\Modifier\RecursiveMerge;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = dirname(__DIR__);

// PHP built-in server routing (development).
//
// Ahead of the maintenance gate on purpose. Under `php yii serve` this file is
// the *router* and runs for every request, assets included, so leaving the
// passthrough below would answer /favicon.ico and /assets/css/app.css with the
// maintenance page. Apache never gets there — public/.htaccess serves real
// files without invoking index.php at all — so putting this first is what makes
// the dev server behave like production during a deploy instead of merely
// looking like it.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (is_file(__DIR__ . $path)) {
        return false;
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

/**
 * Maintenance gate.
 *
 * Checked *before* `src/bootstrap.php`, and that ordering is the whole design:
 * bootstrap requires `vendor/autoload.php`, which is exactly the file an rsync
 * deploy rewrites first. A gate placed inside the framework could only report
 * a broken deploy as a 500 stack trace — by which point the autoloader that
 * should have rendered the error page is itself missing.
 *
 * `runtime/maintenance.lock` is created by the deploy workflow before the
 * transfer and removed after it. If a deploy dies in between, the lock stays
 * and visitors keep seeing a working page instead of a fatal error.
 *
 * The lock lives in `runtime/`, which .gitignore and the workflow's rsync
 * excludes both protect — `--delete` can never remove it, and a stale copy in
 * git can never be pushed to production.
 *
 * Static assets keep working in maintenance: `.htaccess` serves them on
 * Apache, and the cli-server branch above does it for the dev server. The page
 * itself is styled inline for the same reason — it must not depend on a
 * stylesheet a deploy may be rewriting.
 */
if (is_file($root . '/runtime/maintenance.lock')) {
    require __DIR__ . '/maintenance.php';
}

require_once $root . '/src/bootstrap.php';

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
