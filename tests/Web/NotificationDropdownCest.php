<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;

/**
 * The header notification dropdown.
 *
 * It used to ship a hard-coded "লোড হচ্ছে…" line that never changed, so the
 * failure mode to guard against is a regression to server-rendered placeholder
 * markup: the rows have to come from app.js at runtime, which means the Twig
 * output must contain an Alpine component and *no* pre-baked rows. The rest of
 * the assertions are the pieces a user can still be stranded without — the
 * no-JS form fallback, the link to the full page, and the four states the
 * panel swaps between.
 */
final class NotificationDropdownCest
{
    private const DEMO_USER = 'rahim.demo';
    private const DEMO_PASSWORD = 'Demo1234!';

    public function dropdownIsAnApiBackedAlpineComponent(WebTester $I): void
    {
        $I->wantTo('have a dropdown that loads its rows from the API.');
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::DEMO_USER,
            'password' => self::DEMO_PASSWORD,
        ]);

        $html = $I->grabPageSource();

        // The server only knows the unread count; it cannot know the rows, so a
        // component that is handed the count and nothing else is the shape that
        // forces the fetch.
        Assert::assertMatchesRegularExpression(
            '/x-data="thNotifications\(\s*\d+\s*\)"/',
            $html,
            'The dropdown must be an Alpine component seeded with the unread count.',
        );
        $I->seeElement('#notification-dropdown-body');
        $I->seeElement('[x-data^="thNotifications"] template[x-for="item in items"]');

        Assert::assertStringNotContainsString(
            'লোড হচ্ছে',
            $html,
            'The static loading placeholder is gone; the skeleton is x-show driven now.',
        );
        Assert::assertStringNotContainsString(
            'id="notification-dropdown-body"><div class="flex gap-3 border-b',
            $html,
            'No row may be rendered server-side — the list arrives from /api/notifications.',
        );
    }

    public function everyDropdownStateIsPresent(WebTester $I): void
    {
        $I->wantTo('see loading, error, empty and row markup, all toggled by Alpine.');
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::DEMO_USER,
            'password' => self::DEMO_PASSWORD,
        ]);

        $html = $I->grabPageSource();

        // Skeleton, failure (with a retry), and the empty state.
        Assert::assertStringContainsString('x-show="loading && !loaded"', $html);
        Assert::assertStringContainsString('animate-pulse', $html);
        Assert::assertStringContainsString('x-show="error"', $html);
        Assert::assertStringContainsString('@click="load(true)"', $html);
        Assert::assertStringContainsString('আবার চেষ্টা করুন', $html);
        Assert::assertStringContainsString('x-show="isEmpty"', $html);
        Assert::assertStringContainsString('কোনো নোটিফিকেশন নেই।', $html);

        // What a row binds to — the fields app.js reads off each item.
        foreach (['item.title', 'item.message', 'item.created_at', 'item.read_at', 'item.type'] as $binding) {
            Assert::assertStringContainsString(
                $binding,
                $html,
                'A row must bind ' . $binding . ' or the dropdown renders blanks.',
            );
        }
    }

    public function dropdownStillWorksWithoutJavascript(WebTester $I): void
    {
        $I->wantTo('keep a no-JS path out of the dropdown.');
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::DEMO_USER,
            'password' => self::DEMO_PASSWORD,
        ]);

        // @submit.prevent only intercepts when Alpine boots; the plain form post
        // is what saves a user whose script is blocked.
        $I->seeElement('form[action="/notifications/read-all"]');
        $I->seeElement('input[name="_csrf"]');
        $I->see('সব পড়া হয়েছে');

        // And a way to the full page, which lists everything server-side.
        $I->seeElement('#notification-dropdown-body + a[href="/notifications"]');
        $I->see('সব নোটিফিকেশন দেখুন');
    }

    public function rowsMarkAsReadInsteadOfNavigating(WebTester $I): void
    {
        $I->wantTo('mark a row read in place rather than losing the dropdown.');
        $I->amOnPage('/login');
        $I->submitForm('form[action="/login"]', [
            'identifier' => self::DEMO_USER,
            'password' => self::DEMO_PASSWORD,
        ]);

        $html = $I->grabPageSource();

        // href is kept for middle-click and for the no-JS fallback; the click
        // handler has to win, which is what .prevent is for.
        Assert::assertMatchesRegularExpression(
            '/<a href="\/notifications" @click\.prevent="markRead\(item\)"/',
            $html,
            'Clicking a row must mark it read, not navigate away.',
        );
        Assert::assertStringContainsString(
            '@submit.prevent="markAllRead()"',
            $html,
            'The "সব পড়া হয়েছে" submit must go through the API, not post the page.',
        );
        Assert::assertStringContainsString(':disabled="unread === 0"', $html);
    }
}
