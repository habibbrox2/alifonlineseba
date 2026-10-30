# Local PHP and Codeception Testing

Run PHP syntax checks, frontend assets, and Codeception locally on Windows. This workflow does not use GitHub Actions, a remote database, deployment, or release automation.

## Requirements

- PHP 8.2+ with pdo_mysql, openssl, mbstring, and curl
- Composer
- Node.js and npm
- Local MySQL or MariaDB
- Project dependencies installed

From the repository root:

    composer install
    npm ci

## Create an isolated test database

Do not point tests at the database configured in .env. The application test fixtures create and modify records.

Create a dedicated database and restricted local user. Example:

    CREATE DATABASE allseba_local_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'allseba_local_test'@'127.0.0.1' IDENTIFIED BY 'use-a-random-local-password';
    GRANT ALL PRIVILEGES ON allseba_local_test.* TO 'allseba_local_test'@'127.0.0.1';
    FLUSH PRIVILEGES;

Copy the template:

    Copy-Item .env.test.example .env.test.local
    notepad .env.test.local

Set DB_DSN, DB_USERNAME, and DB_PASSWORD to the dedicated database. The database name must end with _test. Never put production credentials in this file.

Generate a local-only VAPID key. For XAMPP, add its OpenSSL binary to PATH first:

    $env:PATH = "C:\xampp\apache\bin;$env:PATH"
    php scripts/generate-vapid-keys.php mailto:local-tests@example.invalid

Copy the printed VAPID_SUBJECT and VAPID_PRIVATE_KEY values into .env.test.local.

## Apply schema migrations

Verify the DSN in .env.test.local before migrating. The console bootstrap uses that file when APP_ENV=test:

    $env:APP_ENV = 'test'
    php yii migrate/up --interactive=0

The runner intentionally does not run migrations automatically.

## Run tests locally

Run all checks and suites:

    .\scripts\run-local-tests.ps1

Run a specific Codeception suite:

    .\scripts\run-local-tests.ps1 -Suite unit
    .\scripts\run-local-tests.ps1 -Suite functional
    .\scripts\run-local-tests.ps1 -Suite web
    .\scripts\run-local-tests.ps1 -Suite console

If assets are already built:

    .\scripts\run-local-tests.ps1 -SkipBuild

Skip the PHP syntax pass:

    .\scripts\run-local-tests.ps1 -SkipLint -SkipBuild

The all and web suites start a temporary PHP server on 127.0.0.1:8099 and stop it after the run. Use -KeepServer to leave it running.

## Safety and output

- APP_ENV=test loads only .env.test.local; it does not load the ordinary .env.
- The runner accepts only local MySQL/MariaDB DSNs and database names ending in _test.
- The runner never runs migrations automatically.
- .env.test.local is Git-ignored.
- Codeception output: tests/_output/
- Local web server logs: runtime/local-test-server.log and runtime/local-test-server.log.err
- This local workflow does not push, merge, deploy, or publish releases.
