<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one place a date typed by a human becomes a date the system stores.
 *
 * The rule this class exists to enforce: **what a person sees is
 * `DD-MM-YYYY`, what the database holds is `YYYY-MM-DD`, and nothing in
 * between is ever written.** Every date field on every service form goes
 * through here, so there is one validation and one format rather than one per
 * provider.
 *
 * Why the display format is fixed rather than left to the browser: a bare
 * `<input type="date">` renders in whatever order the operating system thinks
 * is natural, so the same page asks one user for `06-10-2026` and the next for
 * `10-06-2026`. Six of October and ten of June are both legal answers to it.
 * The widget in `resources/js/date-field.js` shows the text form, and this
 * class accepts it — but it also accepts the ISO form, because a form posted
 * with JavaScript off, or by a client that never ran the script, sends exactly
 * that.
 *
 * `strtotime()` is deliberately not used. It does not reject anything: it
 * answers "31-02-2026" with 3 March, so an impossible birth date becomes a
 * different, entirely valid one and nobody is told. {@see checkdate()} says no.
 */
final class ServiceDate
{
    /**
     * What the user is shown and asked to type: day, month, year.
     */
    public const DISPLAY_FORMAT = 'DD-MM-YYYY';

    /** What everything downstream stores and compares. */
    public const STORAGE_FORMAT = 'Y-m-d';

    /**
     * Accepted shapes: `regex => [year, month, day]` capture group numbers.
     *
     * `Y-m-d` is ISO and unambiguous. `d-m-y` is the display format. `d/m/y` is
     * what the nid-make provider used to demand — kept because it is already
     * sitting in stored order metadata on existing services, and an order that
     * can no longer be read back is worse than one extra accepted shape.
     *
     * A two-digit year is refused by every shape. Picking a century on the
     * user's behalf would invent a birth date out of nothing.
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    private const SHAPES = [
        '#^(\d{4})-(\d{1,2})-(\d{1,2})$#' => [1, 2, 3],
        '#^(\d{1,2})-(\d{1,2})-(\d{4})$#' => [3, 2, 1],
        '#^(\d{1,2})/(\d{1,2})/(\d{4})$#' => [3, 2, 1],
    ];

    /**
     * Parse anything a date field can be given.
     *
     * @return string|null `Y-m-d`, or null when the value is not a real date
     *                     (including the empty string, which is "not answered"
     *                     rather than "answered wrongly" and is left for the
     *                     required-field check to judge)
     */
    public static function canonical(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        foreach (self::SHAPES as $pattern => $groups) {
            if (!preg_match($pattern, $raw, $m)) {
                continue;
            }

            $year = (int) $m[$groups[0]];
            $month = (int) $m[$groups[1]];
            $day = (int) $m[$groups[2]];

            if (!checkdate($month, $day, $year)) {
                continue;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /** Whether this value is a date we can store. */
    public static function isValid(mixed $value): bool
    {
        return self::canonical($value) !== null;
    }

    /**
     * The `Y-m-d` value shown back to a person: `06-10-2026`.
     *
     * Used when a rejected form is re-rendered, so what they typed is what
     * they see again. An unparseable value is returned trimmed rather than
     * dropped: it is about to be shown in an error message, and hiding the
     * mistake makes it harder to fix.
     */
    public static function display(mixed $value): string
    {
        $canonical = self::canonical($value);
        if ($canonical === null) {
            return is_scalar($value) ? trim((string) $value) : '';
        }

        [$year, $month, $day] = explode('-', $canonical);

        return "{$day}-{$month}-{$year}";
    }

    /** The message shown against a date field the server would not accept. */
    public static function errorMessage(): string
    {
        return 'সঠিক তারিখ লিখুন — ' . self::DISPLAY_FORMAT . ' (যেমন: 06-10-2026)।';
    }

    /**
     * Normalise every date field of a submission in one pass.
     *
     * Returns the canonical values keyed by field name, and the ones that
     * could not be read. Empty answers are in neither list: an optional field
     * nobody filled in is not an error, and a required one is already reported
     * by the required-field check.
     *
     * @param \App\ServiceProvider\ServiceField[] $fields the form's fields, in
     *        display order — the same list `ServiceManager::fieldsFor()` hands
     *        the template, so the widget and the validator cannot drift apart
     * @param array<string, string> $input the picked, still-raw answers
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function normaliseFields(array $fields, array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            if ($field->type !== 'date') {
                continue;
            }

            $raw = $input[$field->name] ?? '';
            if (!is_string($raw) && !is_int($raw)) {
                continue;
            }
            if (trim((string) $raw) === '') {
                continue;
            }

            $canonical = self::canonical($raw);
            if ($canonical === null) {
                $errors[$field->name] = self::errorMessage();
                continue;
            }

            $values[$field->name] = $canonical;
        }

        return ['values' => $values, 'errors' => $errors];
    }
}