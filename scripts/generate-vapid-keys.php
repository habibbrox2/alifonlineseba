<?php

declare(strict_types=1);

/**
 * One-time setup script: print the VAPID key pair for Web Push.
 *
 * Usage:
 *   php scripts/generate-vapid-keys.php [subject]
 *
 * where [subject] is a mailto: or https: URI identifying the sender, e.g.
 *   php scripts/generate-vapid-keys.php mailto:ops@example.com
 *
 * The output is the two lines to paste into `.env`:
 *   VAPID_SUBJECT=mailto:ops@example.com
 *   VAPID_PRIVATE_KEY=<base64url of the 32-byte scalar>
 *
 * (The public key is not printed: `VapidKeys` derives it from the private
 * scalar on every request, so storing a second copy would only create a way
 * for the two to drift apart.)
 *
 * ## Why this cannot be a console command
 *
 * The obvious home for it is `php yii app:vapid:keys`, but this project has no
 * controller namespace wired into the console runner, so adding one would mean
 * introducing a directory and a config key for a script that runs once per
 * deployment. A file in `scripts/` is the same one-liner with none of that.
 *
 * ## Why the key is generated out of process
 *
 * `openssl_pkey_new()` fails on this host: OpenSSL is built without a config
 * file, so it aborts with "configuration file routines::no such file" before
 * it ever looks at the curve name. `VapidKeys` hits the same wall and uses the
 * same workaround — a `openssl ecparam` subprocess. Doing it here rather than
 * in the library keeps that quirk in one place, since nobody should be
 * surprised by it twice.
 *
 * ## Why the value is base64url of the raw scalar
 *
 * `.env` is line-oriented, and a PEM has newlines that no dotenv parser here
 * preserves. The 32-byte private scalar has neither problem, and
 * `VapidKeys::fromEnv()` re-wraps it in a SEC 1 EC PRIVATE KEY structure at
 * load time.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Env;

$subject = $argv[1] ?? '';
if ($subject === '') {
    fwrite(STDERR, "usage: php scripts/generate-vapid-keys.php <mailto:|https: subject>\n");
    fwrite(STDERR, "example: php scripts/generate-vapid-keys.php mailto:ops@example.com\n");
    exit(1);
}

if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://')) {
    fwrite(STDERR, "Subject must be a mailto: or https: URI (RFC 8292 section 2.1).\n");
    exit(1);
}

$command = ['openssl', 'ecparam', '-genkey', '-name', 'prime256v1'];
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$process = @proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not run `openssl`. Install OpenSSL or set the key by hand.\n");
    exit(1);
}

fclose($pipes[0]);
$pem = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

if (proc_close($process) !== 0 || !is_string($pem) || !str_contains($pem, 'BEGIN')) {
    fwrite(STDERR, "openssl failed: " . ($stderr !== '' ? trim($stderr) : 'no output') . "\n");
    exit(1);
}

$key = openssl_pkey_get_private($pem);
if ($key === false) {
    fwrite(STDERR, "Generated key could not be read back by this PHP build.\n");
    exit(1);
}

/**
 * The SEC 1 structure is `ECPrivateKey ::= SEQUENCE { version INTEGER(1),
 * privateKey OCTET STRING, [0] parameters, [1] publicKey }`. OpenSSL gives us
 * the parsed scalar directly, so the DER has to be rebuilt by hand to pull it
 * out — the same reason VapidKeys builds its own wrapper.
 */
$details = openssl_pkey_get_details($key);
$scalar = $details['ec']['d'] ?? '';
if (!is_string($scalar) || strlen($scalar) !== 32) {
    fwrite(STDERR, "Unexpected private scalar length; is the curve really P-256?\n");
    exit(1);
}

$encoded = rtrim(strtr(base64_encode($scalar), '+/', '-_'), '=');

echo "Add these to .env:\n\n";
echo 'VAPID_SUBJECT=' . $subject . "\n";
echo 'VAPID_PRIVATE_KEY=' . $encoded . "\n\n";
echo "The matching public key is derived at load time; do not store it.\n";
