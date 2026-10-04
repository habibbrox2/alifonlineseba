<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\SettingsRepository;
use App\Service\OrderWindowService;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * The daily window in which service orders may be placed.
 *
 * The failure this guards against is a quiet one: an 08:00–22:00 rule evaluated
 * in the server's zone (Europe/Berlin here) shuts the shop at 18:00 Bangladesh
 * time, and because orders still work — just at the wrong hours — nothing looks
 * broken. So the tests below pin the zone, both boundaries, the overnight wrap,
 * and the fail-open behaviour that stops one bad row from closing intake.
 */
final class OrderWindowTest extends \Codeception\Test\Unit
{
    /** A fixed Dhaka instant; the date only has to be a real one. */
    private function dhaka(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-15 ' . $time, new \DateTimeZone(OrderWindowService::TIMEZONE));
    }

    public function testAFreshDeploymentOpensAtEightAndClosesAtTen(): void
    {
        // The repository serves these defaults for a key with no row, so these
        // three values ARE the behaviour of a site that has never opened the
        // settings page.
        assertSame('1', SettingsRepository::KEYS['order_window_enabled'][1], 'the window must be on by default');
        assertSame('08:00', SettingsRepository::KEYS['order_window_start'][1]);
        assertSame('22:00', SettingsRepository::KEYS['order_window_end'][1]);
    }

    public function testTheWindowIsInclusiveAtItsStartAndExclusiveAtItsEnd(): void
    {
        $window = OrderWindowService::between('08:00', '22:00');

        assertFalse($window->isOpen($this->dhaka('07:59')), 'a minute before opening');
        assertTrue($window->isOpen($this->dhaka('08:00')), '08:00 is inside — "from 8" is not "after 8"');
        assertTrue($window->isOpen($this->dhaka('12:00')));
        assertTrue($window->isOpen($this->dhaka('21:59')), 'the last minute of the window');
        assertFalse($window->isOpen($this->dhaka('22:00')), 'closing time is the first moment outside');
        assertFalse($window->isOpen($this->dhaka('23:59')));
        assertFalse($window->isOpen($this->dhaka('00:00')), 'midnight is not 8 AM');
        assertFalse($window->isOpen($this->dhaka('03:17')));
    }

    public function testTheSameInstantReadsTheSameWhicheverZoneItIsExpressedIn(): void
    {
        $window = OrderWindowService::between('08:00', '22:00');

        // 04:00 UTC on this date is 10:00 in Dhaka and 06:00 in Berlin.
        $morning = new \DateTimeImmutable('2026-06-15 04:00:00', new \DateTimeZone('UTC'));

        assertTrue($window->isOpen($morning), '10:00 Dhaka is inside the window');
        assertTrue(
            $window->isOpen($morning->setTimezone(new \DateTimeZone('Europe/Berlin'))),
            'the same instant must not depend on the zone the caller happened to write it in',
        );

        // 17:30 UTC is 23:30 in Dhaka but only 19:30 in Berlin — inside the
        // window by the server's own clock, outside it by the real one.
        $night = new \DateTimeImmutable('2026-06-15 17:30:00', new \DateTimeZone('UTC'));

        assertFalse($window->isOpen($night), '23:30 Dhaka is after closing');
        assertFalse(
            $window->isOpen($night->setTimezone(new \DateTimeZone('Europe/Berlin'))),
            '19:30 Berlin must not pass for 19:30 Dhaka',
        );
    }

    public function testTheDefaultNowArgumentReadsTheDhakaClock(): void
    {
        // The call sites pass no argument, so this is the path production takes.
        // Comparing against the Dhaka clock by hand is what fails if someone
        // "simplifies" the service back to the default timezone — the test then
        // reports a 4–5 hour error instead of nothing at all.
        $window = OrderWindowService::between('08:00', '22:00');
        $now = new \DateTimeImmutable('now', new \DateTimeZone(OrderWindowService::TIMEZONE));
        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');

        assertSame(
            $minutes >= 8 * 60 && $minutes < 22 * 60,
            $window->isOpen(),
            'isOpen() must resolve "now" in Asia/Dhaka, not in the server default zone',
        );
    }

    public function testAWindowMayWrapPastMidnight(): void
    {
        $window = OrderWindowService::between('22:00', '06:00');

        assertTrue($window->isOpen($this->dhaka('22:00')));
        assertTrue($window->isOpen($this->dhaka('23:30')));
        assertTrue($window->isOpen($this->dhaka('00:00')), 'the window continues into the next day');
        assertTrue($window->isOpen($this->dhaka('05:59')));
        assertFalse($window->isOpen($this->dhaka('06:00')));
        assertFalse($window->isOpen($this->dhaka('12:00')));
        assertFalse($window->isOpen($this->dhaka('21:59')));
    }

    public function testTurningTheGateOffNeverClosesIt(): void
    {
        $window = OrderWindowService::between('08:00', '22:00', false);

        assertFalse($window->isEnabled());
        assertTrue($window->isOpen($this->dhaka('03:00')), 'disabled means 24/7, whatever the times say');
        assertTrue($window->isOpen($this->dhaka('12:00')));
        assertSame('দিনে ২৪ ঘণ্টা সার্ভিস অর্ডার করা যাবে।', $window->label());
    }

    public function testAlwaysOpenIgnoresTheClockEntirely(): void
    {
        $window = OrderWindowService::alwaysOpen();

        assertTrue($window->isOpen($this->dhaka('03:00')));
        assertTrue($window->isOpen($this->dhaka('13:37')));
        assertFalse($window->isEnabled());
    }

    /**
     * @dataProvider unreadableWindows
     */
    public function testAWindowThatCannotBeReadLeavesIntakeOpen(string $start, string $end): void
    {
        // Nothing can store these — AdminSettingsAction rejects them — so this is
        // only about a row edited straight in the database. The rule is that a
        // bad value must not become an outage.
        $window = OrderWindowService::between($start, $end);

        assertTrue($window->isOpen($this->dhaka('03:00')), "{$start}–{$end} must fail open, not shut the shop");
        assertTrue($window->isOpen($this->dhaka('12:00')));
    }

    public static function unreadableWindows(): array
    {
        return [
            'letters' => ['eight', '22:00'],
            'empty start' => ['', '22:00'],
            'hour out of range' => ['08:00', '25:00'],
            'minute out of range' => ['08:00', '22:60'],
            'no separator' => ['0800', '2200'],
            'identical endpoints' => ['08:00', '08:00'],
        ];
    }

    /**
     * @dataProvider times
     */
    public function testTimeFormatValidation(string $value, bool $valid): void
    {
        assertSame($valid, OrderWindowService::isValidTime($value), "{$value} validity");
    }

    public static function times(): array
    {
        return [
            'midnight' => ['00:00', true],
            'single-digit hour' => ['8:00', true],
            'last minute' => ['23:59', true],
            'surrounded by spaces' => [' 08:00 ', true],
            'empty' => ['', false],
            'blank' => ['   ', false],
            'hour 24' => ['24:00', false],
            'minute 60' => ['08:60', false],
            'no separator' => ['0800', false],
            'one-digit minute' => ['08:0', false],
            'not a time' => ['সকাল ৮টা', false],
            'negative' => ['-1:00', false],
            'with seconds' => ['08:00:00', false],
        ];
    }

    public function testTheLabelSpeaksBengaliWithAMeridiem(): void
    {
        assertSame(
            'প্রতিদিন সকাল ০৮:০০ থেকে রাত ১০:০০ পর্যন্ত সার্ভিস অর্ডার করা যাবে।',
            OrderWindowService::between('08:00', '22:00')->label(),
            'the operator asked for 8 AM to 10 PM, so the page must say exactly that',
        );
        assertSame(
            'প্রতিদিন দুপুর ১২:০০ থেকে বিকাল ০৪:০০ পর্যন্ত সার্ভিস অর্ডার করা যাবে।',
            OrderWindowService::between('12:00', '16:00')->label(),
        );
        assertSame(
            'প্রতিদিন রাত ১২:০০ থেকে সকাল ০৬:০০ পর্যন্ত সার্ভিস অর্ডার করা যাবে।',
            OrderWindowService::between('00:00', '06:00')->label(),
        );
    }

    public function testTheClosedMessageNamesTheWindowAndItsOpening(): void
    {
        $message = OrderWindowService::between('08:00', '22:00')->closedMessage();

        assertStringStartsWith('এখন সার্ভিস অর্ডার বন্ধ আছে।', $message);
        assertStringContainsString('সকাল ০৮:০০', $message, 'the user needs to know when it reopens, not just that it is shut');
        assertStringContainsString('রাত ১০:০০', $message);
    }
}
