#!/usr/bin/env bash
#
# One-command setup for a NEW server.
#
#   bash scripts/setup.sh
#
# Copies the .env template, generates APP_KEY, creates the database, runs the
# migrations and seeds the demo catalogue. Safe to re-run: an existing .env is
# never overwritten, CREATE DATABASE is IF NOT EXISTS, `migrate:up` is
# idempotent and `app:seed` skips rows that already exist.
#
# Options:
#   --force       overwrite an existing .env with the template
#   --skip-db     do not touch MySQL (use when the host/cPanel made the DB)
#   --skip-seed   migrate only, do not seed the demo catalogue/admin
#   --help
#
# Environment:
#   PHP_BIN   php binary to use (default: php)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
FORCE=0
SKIP_DB=0
SKIP_SEED=0

while [ $# -gt 0 ]; do
    case "$1" in
        --force) FORCE=1 ;;
        --skip-db) SKIP_DB=1 ;;
        --skip-seed) SKIP_SEED=1 ;;
        -h|--help) awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "Unknown option: $1 (try --help)" >&2; exit 2 ;;
    esac
    shift
done

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
warn() { printf '\033[33m!!  %s\033[0m\n' "$1" >&2; }
die()  { printf '\033[31mxx  %s\033[0m\n' "$1" >&2; exit 1; }

# --- 0. preflight ------------------------------------------------------------
step "Checking prerequisites"
command -v "$PHP_BIN" >/dev/null 2>&1 || die "php not found in PATH (set PHP_BIN=/path/to/php)"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP 8.2+ is required, found $("$PHP_BIN" -r 'echo PHP_VERSION;')"
[ -f vendor/autoload.php ] || die "vendor/ is missing — run 'composer install --no-dev' first"
[ -f .env.example ] || die ".env.example is missing from this release"
echo "php $("$PHP_BIN" -r 'echo PHP_VERSION;') · vendor/ ok"

# Reads one key out of .env. Mirrors what phpdotenv does, because the template
# relies on both of its quoting rules: "APP_ENV=prod   # dev | test | prod" has
# an inline comment, and a password with a space or a # must be quoted. Reading
# it any other way silently migrates the wrong database.
env_value() {
    awk -v want="$1" -v file="$ENV_FILE" '
        /^[[:space:]]*#/ { next }
        {
            key = $0; sub(/=.*$/, "", key)
            gsub(/^[ \t]+|[ \t]+$/, "", key)
            if (key != want) next
            v = $0; sub(/^[^=]*=[ \t]*/, "", v)
            if (v ~ /^["\x27]/) {
                q = substr(v, 1, 1)
                rest = substr(v, 2)
                i = index(rest, q)
                if (i > 0) { print substr(rest, 1, i - 1); exit }
            }
            sub(/[ \t]+#.*$/, "", v)
            sub(/[ \t]+$/, "", v)
            gsub(/\r/, "", v)
            print v
            exit
        }
    ' "$ENV_FILE"
}

# --- 1. .env -----------------------------------------------------------------
step "Preparing .env"
ENV_FILE="${ENV_FILE:-.env}"
if [ -f .env ] && [ "$FORCE" -eq 0 ]; then
    echo ".env already exists — keeping it (use --force to replace)"
else
    cp .env.example .env
    echo "copied .env.example -> .env"
    warn "edit .env before going live: APP_URL, DB_DSN, DB_USERNAME, DB_PASSWORD"
fi

# APP_KEY signs bearer tokens and encrypts PII. Generating it here means the
# APK starts working without a second manual step; an existing key is kept
# because rotating it would invalidate every issued token.
APP_KEY="$(env_value APP_KEY)"
if [ -z "$APP_KEY" ]; then
    NEW_KEY="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(32));')"
    if grep -q '^APP_KEY=' .env; then
        sed -i "s|^APP_KEY=.*|APP_KEY=$NEW_KEY|" .env
    else
        printf '\nAPP_KEY=%s\n' "$NEW_KEY" >> .env
    fi
    echo "generated APP_KEY"
else
    echo "APP_KEY already set — left untouched"
fi

# --- 2. database -------------------------------------------------------------
DSN="$(env_value DB_DSN)"
DB_HOST="$(printf '%s' "$DSN" | sed -n 's/.*host=\([^;]*\).*/\1/p')"
DB_PORT="$(printf '%s' "$DSN" | sed -n 's/.*port=\([^;]*\).*/\1/p')"
DB_NAME="$(printf '%s' "$DSN" | sed -n 's/.*dbname=\([^;]*\).*/\1/p')"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-alif_tools}"
DB_USER="${DB_USER:-root}"

if [ "$SKIP_DB" -eq 1 ]; then
    step "Skipping database creation (--skip-db)"
else
    step "Creating database '$DB_NAME' on $DB_HOST:$DB_PORT"
    # Connected without a dbname on purpose: that is the whole point — the
    # database does not exist yet. CREATE IF NOT EXISTS makes a re-run a no-op.
    # cPanel users often lack the grant to create databases; that is a warning,
    # not a failure, because the host may already have made it through the
    # MySQL Databases wizard and the migration below will connect fine.
    if ! DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
        "$PHP_BIN" <<'PHP'
<?php
$h = getenv('DB_HOST'); $p = getenv('DB_PORT'); $n = getenv('DB_NAME');
$u = getenv('DB_USER'); $w = getenv('DB_PASS');
try {
    $pdo = new PDO("mysql:host={$h};port={$p};charset=utf8mb4", $u, $w, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$n}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "database ready\n";
} catch (Throwable $e) {
    // php://stderr rather than STDERR: this script is piped in over stdin, and
    // the STDERR constant is not defined for code that arrives that way.
    file_put_contents('php://stderr', $e->getMessage() . PHP_EOL);
    exit(1);
}
PHP
    then
        warn "could not create the database (no CREATE privilege?) — create it via cPanel or skip with --skip-db"
    fi
fi

# --- 3. migrate + seed -------------------------------------------------------
step "Running migrations"
"$PHP_BIN" yii migrate:up --no-interaction -n

if [ "$SKIP_SEED" -eq 1 ]; then
    step "Skipping seed (--skip-seed)"
else
    step "Seeding demo catalogue and users"
    "$PHP_BIN" yii app:seed -n
fi

# --- 4. cache + assets -------------------------------------------------------
step "Clearing compiled config/template cache"
rm -rf runtime/cache runtime/twig 2>/dev/null || true

if [ ! -f public/assets/css/app.css ]; then
    warn "public/assets/css/app.css is missing — run 'npm install && npm run build' and upload public/assets"
fi

cat <<'DONE'

Setup finished. Before you call it done:

  1. Log in with the seeded admin (admin / Admin1234!) and change that password.
  2. .env → APP_DEBUG=false, APP_URL and the DB_* values must match this host.
  3. runtime/ must be writable by the web user, and must not be web-reachable.
  4. Add the two notification cron jobs (docs/deployment.md §5):
       * * * * * cd /path/to/aliftools && php yii app:notification:work
       0 3 * * * cd /path/to/aliftools && php yii app:notification:purge
     Verify at /admin/notifications — the Queue depth card must stay near zero.
DONE
