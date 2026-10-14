<?php

declare(strict_types=1);

/**
 * Codeception bootstrap.
 *
 * `.env` has to be loaded here for the same reason src/bootstrap.php loads it:
 * without it every `Env::get()` falls through to the hard-coded DEFAULTS, and
 * DB_DSN's default names one specific database (`th_tools`). On any machine
 * whose .env points somewhere else the suite then fails with "Unknown database"
 * — or, worse, connects to a database nobody meant to touch.
 *
 * Loading it means the suite runs against the *development* database, which is
 * what the suite expects: scripts/purge-test-fixtures.php exists precisely
 * because the functional tests create and remove rows there, and CI overrides
 * DB_DSN/DB_USERNAME/DB_PASSWORD as real environment variables (Env::get reads
 * $_ENV and $_SERVER before the defaults, so an exported value wins over .env).
 *
 * APP_ENV is deliberately left alone: an exported APP_ENV=test must keep
 * priority, or a CI run could not mark itself as not-dev.
 */

if (empty($_ENV['APP_ENV']) && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

App\Environment::prepare();