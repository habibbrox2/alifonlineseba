<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ServiceDate;
use App\ServiceProvider\ServiceField;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The one date contract the product has: a person types `06-10-2026`, the row
 * stores `2026-10-06`, and every layer agrees on both.
 *
 * The format used to be decided per provider — one field demanded `YYYY-MM-DD`
 * while the one below it demanded `DD/MM/YYYY` — so a date the widget showed
 * could be refused by the very service that asked for it. These tests are what
 * keeps it a single rule: the parser, the impossibility check, the display
 * form and the field-by-field normaliser the service form runs through.
 */
final class ServiceDateTest extends \Codeception\Test\Unit
{
    /** Every accepted spelling of one day, and the one value it may store. */
    public function testEveryAcceptedShapeBecomesTheSameStoredDate(): void
    {
        foreach (['2026-10-06', '06-10-2026', '06/10/2026', ' 6-10-2026 ', '6-10-2026'] as $typed) {
            assertSame(
                '2026-10-06',
                ServiceDate::canonical($typed),
                "Shape {$typed} must read as 6 October 2026.",
            );
        }
    }

    /**
     * `strtotime()` would answer "31-02" with 3 March and carry on — an
     * impossible birth date quietly becoming a different, valid one.
     */
    public function testAnImpossibleDateIsRefusedRatherThanRolledOver(): void
    {
        foreach (['31-02-2026', '29-02-2023', '32-01-2026', '00-10-2026', '10-13-2026', '31-04-2026'] as $bad) {
            assertNull(ServiceDate::canonical($bad), "{$bad} is not a date.");
        }

        // 2024 is a leap year, so the same shape that failed above is legal now.
        assertSame('2024-02-29', ServiceDate::canonical('29-02-2024'));
    }

    /** Picking a century for somebody invents a birth date out of nothing. */
    public function testTwoDigitYearsAreRefused(): void
    {
        assertNull(ServiceDate::canonical('06-10-26'));
        assertNull(ServiceDate::canonical('6/10/26'));
    }

    /** "Not answered" and "answered wrongly" are different answers. */
    public function testAnEmptyValueIsNotAnError(): void
    {
        assertNull(ServiceDate::canonical(''));
        assertNull(ServiceDate::canonical('   '));
        assertFalse(ServiceDate::isValid(''));
        assertSame('', ServiceDate::display(''));
    }

    public function testDisplayIsTheTypedFormAndANoopForEverythingElse(): void
    {
        assertSame('06-10-2026', ServiceDate::display('2026-10-06'));
        assertSame('06-10-2026', ServiceDate::display('06-10-2026'));

        // A value that is not a date passes through (trimmed): the same helper
        // sits on a generic result panel and an order-metadata loop that carry
        // phone numbers, addresses and file names alongside dates.
        assertSame('01712345678', ServiceDate::display(' 01712345678 '));
        assertSame('Rahim Uddin (Demo)', ServiceDate::display('Rahim Uddin (Demo)'));
        assertSame('1234567890123', ServiceDate::display('1234567890123'));
    }

    public function testRoundTripIsStable(): void
    {
        $iso = ServiceDate::canonical('06-10-2026');
        assertSame('2026-10-06', $iso);
        assertSame('2026-10-06', ServiceDate::canonical(ServiceDate::display($iso)));
    }

    /**
     * The normaliser walks the *list* of form fields — the same one the
     * template renders — and must key its answers by the field's name, not by
     * the array's integer key.
     */
    public function testNormaliseFieldsKeysAnswersByFieldName(): void
    {
        $fields = [
            new ServiceField('nid_number', 'আইডি', 'text', true),
            new ServiceField('date_of_birth', 'জন্ম তারিখ', 'date', true),
            new ServiceField('issue_date', 'ইস্যু তারিখ', 'date', false),
            new ServiceField('photo', 'ছবি', 'image', true),
        ];

        $result = ServiceDate::normaliseFields($fields, [
            'nid_number' => '1990123456789',
            'date_of_birth' => '11-03-1994',
            'issue_date' => '11/03/1994',
            'photo' => '2026-10-06', // a file path that looks like a date must not be parsed
        ]);

        assertSame(['date_of_birth' => '1994-03-11', 'issue_date' => '1994-03-11'], $result['values']);
        assertSame([], $result['errors'], 'Both shapes are legal, so neither field may fail.');
        assertArrayNotHasKey('nid_number', $result['values'], 'A text field is never touched.');
        assertArrayNotHasKey('photo', $result['values'], 'Neither is an image field.');
    }

    public function testNormaliseFieldsReportsTheOnesItCouldNotRead(): void
    {
        $fields = [
            new ServiceField('date_of_birth', 'জন্ম তারিখ', 'date', true),
            new ServiceField('issue_date', 'ইস্যু তারিখ', 'date', true),
            new ServiceField('photo', 'ছবি', 'image', true),
        ];

        $result = ServiceDate::normaliseFields($fields, [
            'date_of_birth' => '31-02-1990',
            'issue_date' => '',
            'photo' => 'x',
        ]);

        assertArrayHasKey('date_of_birth', $result['errors']);
        assertSame(ServiceDate::errorMessage(), $result['errors']['date_of_birth']);
        assertArrayNotHasKey('issue_date', $result['errors'], 'Blanks are for the required check, not here.');
        assertArrayNotHasKey('date_of_birth', $result['values']);
        assertSame('DD-MM-YYYY', ServiceDate::DISPLAY_FORMAT);
        assertSame('Y-m-d', ServiceDate::STORAGE_FORMAT);
        assertTrue(str_contains(ServiceDate::errorMessage(), ServiceDate::DISPLAY_FORMAT));
    }
}
