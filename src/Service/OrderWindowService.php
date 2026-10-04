<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;

/**
 * The daily window during which new service orders may be submitted.
 *
 * The operator sets the start and end times in the admin panel; this class is
 * the one place that decides whether "now" falls inside them, so the order form
 * and `ServiceManager::submit()` can never disagree about it.
 *
 * Two things it is deliberately careful about, both of which fail silently:
 *
 * 1. **Timezone.** "8 AM" means nothing without a zone. PHP's default here is
 *    Europe/Berlin (`date.timezone` in php.ini) and nothing in src/ or config/
 *    calls `date_default_timezone_set()`, so `new DateTimeImmutable('now')` sits
 *    four hours behind Bangladesh in summer and five in winter — an 08:00–22:00
 *    window would shut at 18:00. Every comparison is therefore made in
 *    TIMEZONE, never in the default zone.
 * 2. **Failing open.** A window that cannot be parsed — a value written
 *    straight into the row, or a half-applied migration — leaves orders open
 *    rather than closing the shop. The admin form refuses to store such a value
 *    (see AdminSettingsAction), so this is only defence in depth, and open is
 *    the only safe direction for it to fail in.
 *
 * Instances are immutable value objects built through the factories below, not
 * autowired: the container cannot infer three settings rows.
 */
final class OrderWindowService
{
    /** Order intake is scheduled in Bangladesh time whatever the server zone is. */
    public const TIMEZONE = 'Asia/Dhaka';

    private const ENABLED_KEY = 'order_window_enabled';
    private const START_KEY = 'order_window_start';
    private const END_KEY = 'order_window_end';

    /**
     * @param int|null $startMinutes minutes from midnight, null when unparseable
     * @param int|null $endMinutes   minutes from midnight, null when unparseable
     */
    private function __construct(
        private readonly bool $enabled,
        private readonly ?int $startMinutes,
        private readonly ?int $endMinutes,
    ) {}

    /** Production instance: the window as the admin last saved it. */
    public static function fromSettings(SettingsRepository $settings): self
    {
        $enabled = in_array(
            strtolower(trim($settings->get(self::ENABLED_KEY))),
            ['1', 'true', 'yes', 'on'],
            true,
        );

        return self::between(
            $settings->get(self::START_KEY),
            $settings->get(self::END_KEY),
            $enabled,
        );
    }

    /** A window with no gate at all — used by callers that must never be closed. */
    public static function alwaysOpen(): self
    {
        return new self(false, null, null);
    }

    /**
     * A window between two HH:MM strings, independent of the database.
     *
     * Unparseable endpoints are kept as null and read as "no restriction"; a
     * start equal to the end is likewise treated as open, since a zero-length
     * window is never what an operator meant and the admin form rejects it.
     */
    public static function between(string $start, string $end, bool $enabled = true): self
    {
        return new self($enabled, self::parseMinutes($start), self::parseMinutes($end));
    }

    /** Whether an HH:MM string is a real time of day, for form validation. */
    public static function isValidTime(string $value): bool
    {
        return self::parseMinutes($value) !== null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Is order intake open at this moment?
     *
     * The window is inclusive of its start and exclusive of its end, so "8 to
     * 10 PM" opens at 08:00:00 and closes at 22:00:00 exactly. A start later
     * than the end wraps past midnight (22:00 → 06:00), which costs one `||`
     * and makes an overnight window configurable rather than a code change.
     */
    public function isOpen(?\DateTimeInterface $now = null): bool
    {
        if (!$this->hasWindow()) {
            return true;
        }

        $minutes = self::localMinutes($now ?? new \DateTimeImmutable('now'));

        if ($this->startMinutes < $this->endMinutes) {
            return $minutes >= $this->startMinutes && $minutes < $this->endMinutes;
        }

        return $minutes >= $this->startMinutes || $minutes < $this->endMinutes;
    }

    /** A Bengali sentence naming the window, for the closed notice. */
    public function label(): string
    {
        if (!$this->hasWindow()) {
            return 'দিনে ২৪ ঘণ্টা সার্ভিস অর্ডার করা যাবে।';
        }

        return 'প্রতিদিন '
            . self::bengaliTime($this->startMinutes)
            . ' থেকে '
            . self::bengaliTime($this->endMinutes)
            . ' পর্যন্ত সার্ভিস অর্ডার করা যাবে।';
    }

    /** The user-facing refusal, or an empty string while intake is open. */
    public function closedMessage(): string
    {
        return 'এখন সার্ভিস অর্ডার বন্ধ আছে। ' . $this->label();
    }

    /** True only for a window that can actually be evaluated. */
    private function hasWindow(): bool
    {
        return $this->enabled
            && $this->startMinutes !== null
            && $this->endMinutes !== null
            && $this->startMinutes !== $this->endMinutes;
    }

    /** Minutes since midnight in TIMEZONE, whatever zone `$now` arrives in. */
    private static function localMinutes(\DateTimeInterface $now): int
    {
        $local = \DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }

    private static function parseMinutes(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    /**
     * 08:00 → "সকাল ০৮:০০", 22:00 → "রাত ১০:০০".
     *
     * On a 12-hour clock with Bengali digits, because a raw "22:00" on a
     * Bangladeshi page reads as a machine value rather than an instruction.
     */
    private static function bengaliTime(int $minutes): string
    {
        $hour = intdiv($minutes, 60);
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return self::meridiem($hour) . ' ' . self::bengaliDigits(sprintf('%02d:%02d', $twelve, $minutes % 60));
    }

    private static function meridiem(int $hour): string
    {
        return match (true) {
            $hour < 5 => 'রাত',
            $hour < 12 => 'সকাল',
            $hour < 16 => 'দুপুর',
            $hour < 20 => 'বিকাল',
            default => 'রাত',
        };
    }

    private static function bengaliDigits(string $value): string
    {
        return strtr($value, [
            '0' => '০',
            '1' => '১',
            '2' => '২',
            '3' => '৩',
            '4' => '৪',
            '5' => '৫',
            '6' => '৬',
            '7' => '৭',
            '8' => '৮',
            '9' => '৯',
        ]);
    }
}
