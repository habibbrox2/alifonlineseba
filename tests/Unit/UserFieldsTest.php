<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ServiceDate;
use App\Service\UserFields;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * The rules the admin's create form and its edit form share.
 *
 * They are tested apart from the actions because the actions are two files
 * calling this one: a rule that lives in an action is a rule one of them will
 * eventually forget.
 */
final class UserFieldsTest extends Unit
{
    /** A body that passes everything, so each test can spoil exactly one field. */
    private function valid(array $override = []): array
    {
        return array_merge([
            'full_name' => 'রহিম উদ্দিন',
            'username' => 'rahim',
            'phone' => '01712345678',
            'email' => 'rahim@example.test',
            'date_of_birth' => '',
        ], $override);
    }

    public function testATypedDateBecomesTheStoredForm(): void
    {
        $result = UserFields::validate($this->valid(['date_of_birth' => '06-10-2026']));

        assertSame([], $result['errors']);
        assertSame('2026-10-06', $result['values']['date_of_birth']);
    }

    public function testTheIsoFormIsAcceptedForAFormPostedWithoutTheWidget(): void
    {
        // A client whose JavaScript never ran sends exactly what the database
        // would have handed it; refusing that would make the field unusable
        // with scripting off.
        $result = UserFields::validate($this->valid(['date_of_birth' => '1990-05-04']));

        assertSame([], $result['errors']);
        assertSame('1990-05-04', $result['values']['date_of_birth']);
    }

    public function testAnImpossibleDateIsRefusedWithTheSharedMessage(): void
    {
        $result = UserFields::validate($this->valid(['date_of_birth' => '31-02-1990']));

        // The same words the service form and the browser both use — one rule,
        // one message, or a person is told two different things about the same
        // mistake.
        assertSame(ServiceDate::errorMessage(), $result['errors']['date_of_birth'] ?? null);
        assertNull($result['values']['date_of_birth']);
    }

    public function testAnOptionalDateLeftBlankIsNotAnError(): void
    {
        $result = UserFields::validate($this->valid());

        assertArrayNotHasKey('date_of_birth', $result['errors']);
        assertNull($result['values']['date_of_birth']);
    }

    public function testAGoodDateDoesNotExcuseABadName(): void
    {
        // Errors are keyed per field rather than collapsed into one list, so
        // the form can show each message beside the input it belongs to and a
        // second mistake is reported instead of being hidden behind the first.
        $result = UserFields::validate($this->valid([
            'full_name' => 'র',
            'date_of_birth' => '06-10-2026',
        ]));

        assertArrayHasKey('full_name', $result['errors']);
        assertArrayNotHasKey('date_of_birth', $result['errors']);
    }

    public function testTheHandlesAreCheckedToo(): void
    {
        $result = UserFields::validate($this->valid([
            'username' => 'ab',
            'phone' => '12345',
            'email' => 'not-an-email',
        ]));

        assertArrayHasKey('username', $result['errors']);
        assertArrayHasKey('phone', $result['errors']);
        assertArrayHasKey('email', $result['errors']);
        assertCount(3, $result['errors']);
    }

    public function testABlankEmailIsStoredAsNullRatherThanAnEmptyString(): void
    {
        // The column is UNIQUE: an empty string stored on two accounts would
        // be a duplicate-key error on the second one.
        $result = UserFields::validate($this->valid(['email' => '   ']));

        assertSame([], $result['errors']);
        assertNull($result['values']['email']);
    }
}