<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;

final class HomePageCest
{
    public function base(WebTester $I): void
    {
        $I->wantTo('home page works.');
        $I->amOnPage('/');
        $I->expectTo('see hero headline.');
        $I->see('ডিজিটাল সেবা এক ক্লিকে!');
        $I->see('All Seba');
    }

    public function loginPage(WebTester $I): void
    {
        $I->wantTo('login page works.');
        $I->amOnPage('/login');
        $I->see('লগইন');
    }

    public function errorPage(WebTester $I): void
    {
        $I->wantTo('see friendly 404 page.');
        $I->amOnPage('/non-existent-page');
        $I->canSeeResponseCodeIs(404);
        $I->see('404');
        $I->see('হোমে ফিরে যান');
    }
}
