<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;

final class NotFoundHandlerCest
{
    public function nonExistentPage(WebTester $I): void
    {
        $I->wantTo('see 404 page.');
        $I->amOnPage('/non-existent-page');
        $I->canSeeResponseCodeIs(404);
        $I->see('404');
        $I->see('Page Not Found');
        $I->see('আপনি যে পেজটি খুঁজছেন সেটি পাওয়া যায়নি।');
    }

    public function returnHome(WebTester $I): void
    {
        $I->wantTo('check "Go Back Home" link.');
        $I->amOnPage('/non-existent-page');
        $I->canSeeResponseCodeIs(404);
        $I->click('হোমে ফিরে যান');
        $I->expectTo('see page home.');
        $I->see('ডিজিটাল সেবা এক ক্লিকে!');
    }
}
