<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Single source of truth for how a status is labelled, badged and — for
 * service requests — which actions the owner may take on it.
 *
 * The Twig `status-badge.twig` component and the JSON API both read from here,
 * so a status can never drift between the server-rendered page and the
 * AJAX-updated row.
 */
final class StatusPresenter
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** Every status a service request may be in. */
    public const REQUEST_STATUSES = [
        self::PENDING,
        self::PROCESSING,
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    /** status => [label, badge class] */
    private const MAP = [
        self::PENDING => ['পেন্ডিং', 'badge-warning'],
        self::PROCESSING => ['প্রসেসিং', 'badge-info'],
        self::COMPLETED => ['সম্পন্ন', 'badge-success'],
        self::FAILED => ['ব্যর্থ', 'badge-danger'],
        self::CANCELLED => ['বাতিল', 'badge-danger'],
        'review' => ['যাচাই ধরা হয়েছে', 'badge-info'],
        'approved' => ['অনুমোদিত', 'badge-success'],
        'rejected' => ['বাতিলকৃত', 'badge-danger'],
        // Referral lifecycle. `pending` is shared with service requests, so it
        // already appears above; only the two referral-only states are added
        // here. The wording is deliberately user-facing — "অপেক্ষমাণ" tells a
        // referrer their friend has signed up but not yet recharged, which is
        // the single most common question on that page.
        'paid' => ['পরিশোধিত', 'badge-success'],
        'active' => ['সক্রিয়', 'badge-success'],
        'disabled' => ['নিষ্ক্রিয়', 'badge-neutral'],
        'inactive' => ['নিষ্ক্রিয়', 'badge-neutral'],
        'deleted' => ['মুছে ফেলা', 'badge-danger'],
        'info' => ['তথ্য', 'badge-info'],
        'success' => ['সফল', 'badge-success'],
        'warning' => ['সতর্কতা', 'badge-warning'],
        'danger' => ['অ্যালার্ট', 'badge-danger'],
    ];

    /**
     * Statuses that mean "still moving" — a spinner belongs in the pill.
     *
     * These three are the states a user *waits* in: the queue nobody has
     * touched, the one an operator is working on, and the one a verifier has
     * picked up. They get an animated ring inside the badge (see
     * `.badge-loading` in resources/css/app.css) so "work is happening" reads
     * as motion instead of as a word you have to know the meaning of.
     *
     * Deliberately not extended to `completed`/`failed`: a settled row that
     * still spins is a lie, and it steals attention from the rows that are
     * actually live.
     */
    private const IN_FLIGHT = [self::PENDING, self::PROCESSING, 'review'];

    /** Action key => [label, icon, button variant]. */
    private const ACTIONS = [
        'start' => ['শুরু করুন', 'play', 'btn-primary'],
        'cancel' => ['বাতিল করুন', 'x-circle', 'btn-danger'],
        'retry' => ['পুনরায় চালান', 'rotate-ccw', 'btn-primary'],
        'view' => ['ফলাফল দেখুন', 'eye', 'btn-ghost'],
        'download' => ['ফাইল ডাউনলোড', 'download', 'btn-primary'],
    ];

    /**
     * Actions that are answered entirely on the client.
     *
     * `view` only expands a result already on the page. `download` is a plain
     * link to the streaming endpoint — there is nothing to POST, and routing it
     * through the action API would mean the browser handling the file response
     * as JSON.
     */
    private const LOCAL_ACTIONS = ['view', 'download'];

    /**
     * The action buttons offered for a service request in the given status.
     *
     * `view` is answered from data already on the page (it only expands the
     * stored result), so it never reaches the server. A pending request offers
     * both `start` and `cancel`, since submitting only queues it.
     *
     * `$hasDeliverable` is passed in rather than derived from the status because
     * the two are independent: an admin can attach a file while a request is
     * still `processing`, and can detach it after `completed`. Deciding from the
     * status would make the button appear at a moment the file does not exist,
     * or hide one that does.
     *
     * The download button is placed first — it is the reason the user is looking
     * at a completed row at all.
     *
     * @return array<int, array{key: string, label: string, icon: string, variant: string, remote: bool}>
     */
    public static function requestActions(string $status, bool $hasDeliverable = false): array
    {
        $keys = match ($status) {
            self::PENDING => ['start', 'cancel'],
            self::FAILED, self::CANCELLED => ['retry'],
            self::COMPLETED => ['view'],
            default => [],
        };

        if ($hasDeliverable) {
            array_unshift($keys, 'download');
        }

        $actions = [];
        foreach ($keys as $key) {
            [$label, $icon, $variant] = self::ACTIONS[$key];
            $actions[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'variant' => $variant,
                'remote' => !in_array($key, self::LOCAL_ACTIONS, true),
            ];
        }

        return $actions;
    }

    /** result key => Bengali label, for the "view" panel. */
    private const RESULT_LABELS = [
        'nid_number' => 'এনআইডি নম্বর',
        'tin_number' => 'টিআইএন নম্বর',
        'name' => 'নাম',
        'name_bn' => 'নাম (বাংলা)',
        'father_name' => 'পিতার নাম',
        'mother_name' => 'মাতার নাম',
        'date_of_birth' => 'জন্ম তারিখ',
        'address' => 'ঠিকানা',
        'blood_group' => 'রক্তের গ্রুপ',
        'photo' => 'ছবি',
        'voter_sl_no' => 'ভোটার ক্রমিক নম্বর',
        'district' => 'জেলা',
        'upazila' => 'উপজেলা',
        'center' => 'কেন্দ্র',
        'circle' => 'সার্কেল',
        'last_return' => 'সর্বশেষ রিটার্ন',
        'status' => 'অবস্থা',
    ];

    /**
     * A stored provider result flattened to label/value pairs, ready for the
     * "view" panel. Internal keys (`_demo`, `_notice`) and empty values are
     * dropped, so the panel only shows what is actually worth reading.
     *
     * @param array<string, mixed>|null $result
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public static function resultEntries(?array $result): array
    {
        if ($result === null) {
            return [];
        }

        $entries = [];
        foreach ($result as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, '_') || $value === null || $value === []) {
                continue;
            }
            $entries[] = [
                'key' => $key,
                'label' => self::RESULT_LABELS[$key] ?? self::label($key),
                // A date is shown the way it was typed (`06-10-2026`), never
                // as the `2026-10-06` the row stores — and ServiceDate is a
                // no-op for every value that is not a date, so a phone number
                // or an address passes through untouched.
                'value' => is_scalar($value) ? ServiceDate::display($value) : self::flatten($value),
            ];
        }

        return $entries;
    }

    private static function flatten(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }
        $parts = [];
        array_walk_recursive($value, static function ($item) use (&$parts): void {
            if (is_scalar($item)) {
                $parts[] = (string) $item;
            }
        });
        return implode(', ', $parts);
    }

    /** Whether a status string may be used as a service-request status filter. */
    public static function isRequestStatus(string $status): bool
    {
        return in_array($status, self::REQUEST_STATUSES, true);
    }

    public static function label(string $status): string
    {
        return self::MAP[$status][0] ?? ucfirst($status);
    }

    public static function badge(string $status): string
    {
        $class = self::MAP[$status][1] ?? 'badge-neutral';

        // Returned from here rather than from html() on purpose: this string
        // is what the JSON API hands the poller (`status_badge`), so a row that
        // changes to `processing` from an AJAX update animates exactly like the
        // one the server rendered — one place decides, both agree.
        return in_array($status, self::IN_FLIGHT, true) ? $class . ' badge-loading' : $class;
    }

    public static function html(string $status): string
    {
        return '<span class="badge ' . htmlspecialchars(self::badge($status), ENT_QUOTES) . '">'
            . htmlspecialchars(self::label($status), ENT_QUOTES)
            . '</span>';
    }
}
