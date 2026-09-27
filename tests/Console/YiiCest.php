<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Tests\Support\ConsoleTester;

final class YiiCest
{
    public function base(ConsoleTester $I): void
    {
        $I->runShellCommand('php ' . dirname(__DIR__, 2) . '/yii --silent');
        $I->canSeeResultCodeIs(0);
    }
}
