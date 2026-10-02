<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ReferralRepository;
use App\Repository\SettingsRepository;
use App\Service\ReferralCode;
use App\Service\StatusPresenter;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The referral code and the settings it is priced from.
 *
 * A code that contains a 0 or a 1 costs a support ticket every time somebody
 * re-keys it off a screenshot, and a bonus that a settings typo can push to
 * seven figures costs a lot more. Both are cheap to pin down here and
 * expensive to notice in production.
 */
final class ReferralCodeTest extends \Codeception\Test\Unit
{
    public function testAlphabetExcludesTheConfusableGlyphs(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $forbidden) {
            assertFalse(
                str_contains(ReferralCode::ALPHABET, $forbidden),
                "Alphabet must not contain {$forbidden}",
            );
        }
    }

    public function testGeneratedCodeHasTheRightShape(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = ReferralCode::generate();

            assertSame(ReferralCode::LENGTH, strlen($code));
            assertTrue(ReferralCode::isValid($code), "Generated an invalid code: {$code}");
        }
    }

    public function testGeneratedCodesAreNotConstant(): void
    {
        // A generator that returned one fixed code would still pass the shape
        // assertions above, so prove it actually varies.
        $codes = [];
        for ($i = 0; $i < 50; $i++) {
            $codes[ReferralCode::generate()] = true;
        }

        assertTrue(count($codes) > 45, 'Expected mostly distinct codes, got ' . count($codes));
    }

    public function testNormaliseFoldsHumanInput(): void
    {
        assertSame('Q74RUQ', ReferralCode::normalise(' q74ruq '));
        assertSame('Q74RUQ', ReferralCode::normalise('Q74-RUQ'));
        assertSame('Q74RUQ', ReferralCode::normalise('Q74_RUQ'));
        assertSame('Q74RUQ', ReferralCode::normalise('%Q74RUQ%'));
        assertSame('', ReferralCode::normalise('!!!'));
    }

    public function testIsValidRejectsWrongLengthAndForeignGlyphs(): void
    {
        assertFalse(ReferralCode::isValid('Q74RU'), 'Five characters is too short.');
        assertFalse(ReferralCode::isValid('Q74RUQX'), 'Seven characters is too long.');
        assertFalse(ReferralCode::isValid('Q74RU0'), '0 is not in the alphabet.');
        assertFalse(ReferralCode::isValid('Q74RUI'), 'I is not in the alphabet.');
        assertFalse(ReferralCode::isValid(''));
    }

    public function testEveryReferralSettingIsWhitelisted(): void
    {
        // SettingsRepository silently drops unknown keys, so a typo in a key name
        // would make the admin form save nothing and look like it worked.
        foreach ([
            'referral_enabled',
            'referrer_bonus_amount',
            'referee_bonus_amount',
            'referral_min_first_recharge',
            'referral_terms',
        ] as $key) {
            assertTrue(
                isset(SettingsRepository::KEYS[$key]),
                "Setting {$key} is missing from the whitelist",
            );
        }
    }

    public function testReferralDefaultsAreNonNegativeNumbers(): void
    {
        foreach (['referrer_bonus_amount', 'referee_bonus_amount', 'referral_min_first_recharge'] as $key) {
            [$label, $default, $type] = SettingsRepository::KEYS[$key];

            assertSame('number', $type, "{$key} must be a number field, not {$type}");
            assertTrue(
                is_numeric($default) && (float) $default >= 0,
                "{$key} default must be a non-negative number, got {$default}",
            );
            assertTrue($label !== '', "{$key} needs a human label");
        }
    }

    public function testReferralIsEnabledByDefault(): void
    {
        // A newly deployed site with a referral section in the sidebar but the
        // programme switched off by default would advertise a dead offer.
        assertSame('1', SettingsRepository::KEYS['referral_enabled'][1]);
    }

    public function testPaidStatusIsPresentable(): void
    {
        // The ledger and the user's own page both render status badges; a row
        // with status "paid" that the presenter does not know falls through to
        // a raw English string in the middle of a Bengali page.
        foreach (ReferralRepository::STATUSES as $status) {
            assertNotSame('', StatusPresenter::label($status), "Status {$status} has no label");
            assertNotSame('', StatusPresenter::badge($status), "Status {$status} has no badge");
        }
    }

    public function testReferralStatusesAreTheThreeExpectedOnes(): void
    {
        assertSame(['pending', 'paid', 'rejected'], ReferralRepository::STATUSES);
    }

    public function testCodePatternMatchesTheAlphabet(): void
    {
        // Guards the regex itself: an unescaped glyph inside the character class
        // would silently widen the accepted set.
        assertMatchesRegularExpression(
            '/^[' . ReferralCode::ALPHABET . ']{6}$/',
            ReferralCode::generate(),
        );
    }
}
