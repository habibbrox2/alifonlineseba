<?php

/**
 * One-off: put digital-sheba behind Apache on :8080 for the Cloudflare tunnel.
 *
 * The tunnel (df90132c-89ef-45d9-a680-eb59a954c476, "th-tools-onlinesheba")
 * forwards allseba.dgtts.org -> http://127.0.0.1:8080, but nothing was listening
 * there. This adds the matching Listen directive and vhost.
 *
 * Do NOT strip X-Forwarded-Proto here. cloudflared dials the origin from
 * loopback, and App\Web\ForwardedProtoMiddleware trusts the header from loopback
 * only; honouring it is what makes Yii mark cookies `Secure` behind the https
 * edge. An earlier revision of this file unset it, which silently downgraded
 * every session cookie to one that could travel over plaintext.
 *
 * Idempotent: safe to re-run. Backups are written once.
 */

declare(strict_types=1);

$conf = 'D:/xampp/apache/conf/httpd.conf';
$vhosts = 'D:/xampp/apache/conf/extra/httpd-vhosts.conf';

$docRoot = 'D:/xampp-server/digital-sheba/public';
$serverName = 'allseba.dgtts.org';
$serverAliases = ['www.allseba.dgtts.org', '127.0.0.1', 'localhost'];
$serverAlias = implode(' ', $serverAliases);

function readFileOrFail(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "cannot read {$path}\n");
        exit(1);
    }
    return (string) file_get_contents($path);
}

function writeFileOrFail(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "cannot write {$path}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- Listen 8080

$confBody = readFileOrFail($conf);

if (preg_match('/^\s*Listen\s+8080\s*$/m', $confBody) === 1) {
    echo "httpd.conf: Listen 8080 already present\n";
} else {
    if (preg_match('/^\s*Listen\s+80\s*$/m', $confBody, $m, PREG_OFFSET_CAPTURE) !== 1) {
        fwrite(STDERR, "httpd.conf: no 'Listen 80' line to anchor to\n");
        exit(1);
    }
    $at = $m[0][1] + strlen($m[0][0]);
    $insert = "\n\n# digital-sheba — origin for the Cloudflare tunnel (allseba.dgtts.org).\n# Added by scripts/add-sheba-vhost.php; remove the block to undo.\nListen 8080";
    $confBody = substr($confBody, 0, $at) . $insert . substr($confBody, $at);
    writeFileOrFail($conf, $confBody);
    echo "httpd.conf: added Listen 8080\n";
}

// -------------------------------------------------------------- VirtualHost

$vhostBody = readFileOrFail($vhosts);

$marker = 'digital-sheba (tunnel origin';

if (str_contains($vhostBody, $marker)) {
    echo "httpd-vhosts.conf: digital-sheba vhost already present\n";
} else {
    $block = <<<CONF

# {$marker})
# Added by scripts/add-sheba-vhost.php; remove this block to undo.
<VirtualHost *:8080>
    ServerAdmin webmaster@localhost
    DocumentRoot "{$docRoot}"
    ServerName {$serverName}
    ServerAlias {$serverAlias}

    <Directory "{$docRoot}">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # The tunnel terminates TLS in front of this, so the origin hop is plain
    # HTTP. X-Forwarded-Proto is deliberately left intact: cloudflared connects
    # from loopback, and App\Web\ForwardedProtoMiddleware trusts the header from
    # loopback only. Honouring it is what makes Yii mark cookies `Secure` for
    # the https edge; stripping it here silently downgraded every session cookie.

    ErrorLog "logs/sheba-error.log"
    CustomLog "logs/sheba-access.log" common
</VirtualHost>

CONF;

    $vhostBody = rtrim($vhostBody) . "\n" . $block;
    writeFileOrFail($vhosts, $vhostBody);
    echo "httpd-vhosts.conf: added VirtualHost *:8080 for {$serverName}\n";
}

echo "done\n";
