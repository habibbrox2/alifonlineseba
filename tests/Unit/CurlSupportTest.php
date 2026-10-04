<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Notification\Channel\CurlSupport;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The push channels' answer to "can this host send HTTP at all?".
 *
 * It is checked in a subprocess with the functions actually disabled rather
 * than mocked, because the shared-host condition being guarded is a php.ini
 * the account owner cannot change — and because `function_exists()` is the
 * exact predicate the channels rely on, so testing anything else would test a
 * stand-in. PHP 8 removes a disabled function from the symbol table, which is
 * what makes the probe reliable.
 */
final class CurlSupportTest extends Unit
{
    public function testAHostWithCurlReportsNoBlocker(): void
    {
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            assertSame('', CurlSupport::blocker());
        } else {
            // The inverse must hold too, or the guard would be silent on the
            // very host it exists for.
            assertNotSame('', CurlSupport::blocker());
        }
    }

    public function testADisabledCurlIsNamedRatherThanFatalingLater(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled, cannot start PHP with curl disabled');
        }

        $script = 'require ' . var_export(codecept_root_dir() . 'vendor/autoload.php', true) . ';'
            . ' echo json_encode(['
            . '"curl" => function_exists("curl_init") && function_exists("curl_exec"),'
            . '"blocker" => \\App\Notification\Channel\CurlSupport::blocker(),'
            . ']);';

        $process = @proc_open(
            [PHP_BINARY, '-d', 'disable_functions=curl_init,curl_exec', '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            self::markTestSkipped('could not start PHP to disable curl');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode($stdout, true);
        assertSame(JSON_ERROR_NONE, json_last_error(), "subprocess produced no JSON: {$stdout} {$stderr}");

        // The host condition first: without it the rest proves nothing.
        assertFalse($result['curl'], 'disable_functions must actually remove curl from this PHP.');
        assertNotSame('', (string) $result['blocker'], 'A host that cannot curl must get a reason, not a fatal.');
        assertStringContainsString('curl', (string) $result['blocker']);
    }
}
