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
        'approved' => ['অনুমোদিত', 'badge-success'],
        'rejected' => ['বাতিলকৃত', 'badge-danger'],
        'active' => ['সক্রিয়', 'badge-success'],
        'disabled' => ['নিষ্ক্রিয়', 'badge-neutral'],
        'inactive' => ['নিষ্ক্রিয়', 'badge-neutral'],
        'deleted' => ['মুছে ফেলা', 'badge-danger'],
        'info' => ['তথ্য', 'badge-info'],
        'success' => ['সফল', 'badge-success'],
        'warning' => ['সতর্কতা', 'badge-warning'],
        'danger' => ['অ্যালার্ট', 'badge-danger'],
    ];

    /** Action key => [label, icon, button variant]. */
    private const ACTIONS = [
        'start' => ['শুরু করুন', 'play', 'btn-primary'],
        'cancel' => ['বাতিল করুন', 'x-circle', 'btn-danger'],
        'retry' => ['পুনরায় চালান', 'rotate-ccw', 'btn-primary'],
        'view' => ['ফলাফল দেখুন', 'eye', 'btn-ghost'],
    ];

    /**
     * The action buttons offered for a service request in the given status.
     *
     * `view` is answered from data already on the page (it only expands the
     * stored result), so it never reaches the server. A pending request offers
     * both `start` and `cancel`, since submitting only queues it.
     *
     * @return array<int, array{key: string, label: string, icon: string, variant: string, remote: bool}>
     */
    public static function requestActions(string $status): array
    {
        $keys = match ($status) {
            self::PENDING => ['start', 'cancel'],
            self::FAILED, self::CANCELLED => ['retry'],
            self::COMPLETED => ['view'],
            default => [],
        };

        $actions = [];
        foreach ($keys as $key) {
            [$label, $icon, $variant] = self::ACTIONS[$key];
            $actions[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'variant' => $variant,
                'remote' => $key !== 'view',
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
                'value' => is_scalar($value) ? (string) $value : self::flatten($value),
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
        return self::MAP[$status][1] ?? 'badge-neutral';
    }

    public static function html(string $status): string
    {
        return '<span class="badge ' . htmlspecialchars(self::badge($status), ENT_QUOTES) . '">'
            . htmlspecialchars(self::label($status), ENT_QUOTES)
            . '</span>';
    }
}
