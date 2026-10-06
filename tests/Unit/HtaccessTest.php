<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;

use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The Apache front controller config, read as text because none of it runs
 * anywhere except on the shared host.
 *
 * `php yii serve` and every in-process test skip .htaccess entirely, so a rule
 * that breaks production breaks nothing here — the app keeps looking healthy
 * while the live site 403s a route it demonstrably answers in development.
 * The dotfile deny matches any path starting with a dot, which made
 * `/.well-known/assetlinks.json` — a route the app answers — unreachable under
 * Apache while the dev server served it fine, so TWA verification could never
 * succeed and read exactly like "not configured"; and a file-exists
 * passthrough that runs before the deny would serve a stray `.env` or
 * `.user.ini` sitting in the web root as plain text.
 *
 * The assertions below are about that ordering, which is the whole design.
 *
 * The rule *names* follow the hardening in a52be83 rather than the original
 * wording, because asserting the old strings would only prove the file had not
 * been improved: the exemption narrowed from the whole `/.well-known/` tree to
 * the two paths that genuinely need the front controller (TWA's
 * `assetlinks.json`, ACME's `acme-challenge/`), the flag was renamed to
 * `ALLOW_WELL_KNOWN` to match, the deny gained `[L]` and a `(^|/)\.` pattern
 * that also catches a dot mid-path, and the front controller moved from `[L]`
 * to `[END]` (which the post-deploy check already accepts — see 604e890).
 * What did not change, and what is still asserted, is the ordering: exemption,
 * then guarded deny, then passthrough, then catch-all, nothing after it.
 */
final class HtaccessTest extends Unit
{
    private string $rules;

    protected function _before(): void
    {
        $raw = (string) file_get_contents(codecept_root_dir() . 'public/.htaccess');
        assertNotFalse($raw, 'public/.htaccess must exist — it is the shared-hosting entry point.');

        // Comments discuss the very words the assertions look for; strip them
        // so the test reads directives and never prose.
        $active = array_filter(
            explode("\n", $raw),
            static fn (string $line): bool => !preg_match('/^\s*#/', $line),
        );
        $this->rules = implode("\n", $active);
    }

    public function testWellKnownReachesTheFrontControllerBeforeTheDotfileDeny(): void
    {
        $exempt = strpos(
            $this->rules,
            'RewriteRule ^\.well-known/(?:assetlinks\.json|acme-challenge/) - [E=ALLOW_WELL_KNOWN:1]',
        );
        $deny = strpos($this->rules, 'RewriteRule (^|/)\. - [F,L]');

        assertNotFalse(
            $exempt,
            'The assetlinks/acme exemption must exist — without it /.well-known/assetlinks.json is a 403 under Apache.',
        );
        assertNotFalse($deny, 'The dotfile deny must exist.');
        assertLessThan($deny, $exempt, 'The exemption must run before the deny, or assetlinks.json is a 403.');
    }

    public function testTheDenyAppliesEvenWhenTheDotfileExistsOnDisk(): void
    {
        $cond = strpos($this->rules, 'RewriteCond %{ENV:ALLOW_WELL_KNOWN} !=1');
        $deny = strpos($this->rules, 'RewriteRule (^|/)\. - [F,L]');
        $passthrough = strpos($this->rules, 'RewriteCond %{REQUEST_FILENAME} -f [OR]');

        assertNotFalse($cond, 'The deny must be guarded by the exemption.');
        assertNotFalse($deny, 'The dotfile deny must exist.');
        assertNotFalse($passthrough, 'The real-file passthrough must exist.');

        assertLessThan($deny, $cond, 'The RewriteCond must guard the deny rule directly.');
        assertLessThan($passthrough, $deny, 'A passthrough that runs first would serve a stray .env as text.');
    }

    public function testFilesMatchBackstopsWhenModRewriteIsMissing(): void
    {
        $htaccess = (string) file_get_contents(codecept_root_dir() . 'public/.htaccess');

        assertStringContainsString('<FilesMatch "^\.">', $htaccess);
        assertStringContainsString('Require all denied', $htaccess);
    }

    public function testFrontControllerIsTheLastRewriteRule(): void
    {
        // Matched without the flag: [L] and [END] are both valid endings, and
        // this test is about position, not which of the two the file uses.
        $catchAll = 'RewriteRule ^ index.php';
        $last = strrpos($this->rules, $catchAll);
        assertNotFalse($last, 'Every other request must fall through to index.php.');
        assertStringContainsString('RewriteEngine On', $this->rules);

        // Nothing may follow the catch-all: a rule after it would never run.
        assertStringNotContainsString(
            'RewriteRule',
            substr($this->rules, $last + strlen($catchAll)),
            'No rewrite rule may follow the front controller.',
        );
    }
}
