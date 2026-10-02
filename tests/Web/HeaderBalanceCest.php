<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;

/**
 * The topbar balance chip: rendered for every signed-in user, carries the raw
 * figure the live refresh in app.js reads, and sits before the bell in the DOM
 * so the amount is readable without opening anything.
 */
final class HeaderBalanceCest
{
    private const DEMO_USER = 'rahim.demo';
    private const DEMO_PASSWORD = 'Demo1234!';

    public function balanceAppearsInTopbar(WebTester $I): void
    {
        $I->wantTo('see the live balance in the topbar next to the bell.');
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::DEMO_USER,
            'password' => self::DEMO_PASSWORD,
        ]);

        $I->seeElement('#header-balance');
        $I->seeElement('#header-balance [data-balance-text]');

        Assert::assertMatchesRegularExpression(
            '/id="header-balance"[^>]*data-balance="-?\d+(\.\d+)?"/',
            $I->grabPageSource(),
            'The chip must expose the raw figure for the live refresh.',
        );
    }

    public function balanceSitsBeforeTheNotificationBell(WebTester $I): void
    {
        $I->wantTo('read the balance before the notification bell.');
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::DEMO_USER,
            'password' => self::DEMO_PASSWORD,
        ]);

        $html = $I->grabPageSource();
        $chip = strpos($html, 'id="header-balance"');
        $bell = strpos($html, 'aria-label="নোটিফিকেশন"');

        Assert::assertGreaterThan(-1, $chip, 'The balance chip is missing from the topbar.');
        Assert::assertGreaterThan(-1, $bell, 'The notification bell is missing from the topbar.');
        Assert::assertGreaterThan($chip, $bell, 'The balance chip must be rendered before the bell.');
    }
}
