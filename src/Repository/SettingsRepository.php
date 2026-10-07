<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Admin-managed site settings (key/value). Only whitelisted keys can be
 * written; reads fall back to sane defaults when a key is absent.
 */
final class SettingsRepository
{
    /** key => [label, default, type] — type: text|url|multiline|number|checkbox */
    public const KEYS = [
        'site_tagline'      => ['ট্যাগলাইন', 'ইনস্ট্যান্ট ডিজিটাল সার্ভিস প্ল্যাটফর্ম', 'text'],
        'contact_email'     => ['যোগাযোগ ইমেইল', '', 'text'],
        'contact_phone'     => ['যোগাযোগ ফোন', '', 'text'],
        'footer_note'       => ['ফুটার নোট', 'সর্বস্বত্ব সংরক্ষিত।', 'multiline'],
        'facebook_url'      => ['ফেসবুক পেজ', '', 'url'],
        'youtube_url'       => ['ইউটিউব চ্যানেল', '', 'url'],
        'whatsapp_url'      => ['হোয়াটসঅ্যাপ', '', 'url'],
        'telegram_url'      => ['টেলিগ্রাম', '', 'url'],
        'topup_min_amount'  => ['টপ-আপ সর্বনিম্ন টাকা', '10', 'number'],
        'topup_max_amount'  => ['টপ-আপ সর্বোচ্চ টাকা', '100000', 'number'],
        'topup_receipt_required' => ['রশিদ আপলোড বাধ্যতামূলক', '1', 'checkbox'],
        'topup_note'        => ['টপ-আপ নির্দেশনা', '', 'multiline'],

        // Reference-style recharge step 2: the wallet numbers users send money
        // to, one per method. Empty = the method stays selectable but the
        // step-2 page hides its number box (the admin has not set it yet).
        'wallet_bkash'      => ['bKash পার্সোনাল নম্বর', '', 'text'],
        'wallet_nagad'      => ['Nagad পার্সোনাল নম্বর', '', 'text'],
        'wallet_rocket'     => ['Rocket পার্সোনাল নম্বর', '', 'text'],

        // Comma-separated quick-amount chips on the recharge form
        // (reference: ৳ 50 / 100 / 300 / 500 / 2000).
        'topup_quick_amounts' => ['দ্রুত এমাউন্ট বাটন', '50,100,300,500,2000', 'text'],

        // Recharge-page offer banner (reference: "২০০০ টাকা যোগ করলে ১৫০ টাকা বোনাস").
        'topup_offer_text'  => ['রিচার্জ অফার ব্যানার', '🔥 বিশেষ অফার: এক সাথে ২০০০ টাকা যোগ করলে পাচ্ছেন ১৫০ টাকা অতিরিক্ত বোনাস! সঠিকভাবে পেমেন্ট করুন। 🔥', 'multiline'],

        // Site-wide urgent-notice modal shown once per session on login/dashboard.
        'notice_title'      => ['জরুরি নোটিশ শিরোনাম', 'জরুরি নোটিশ', 'text'],
        'notice_body'       => ['জরুরি নোটিশ', '', 'multiline'],
        'notice_enabled'    => ['জরুরি নোটিশ চালু', '0', 'checkbox'],

        // Referral programme ("বন্ধুকে রেফার করে বোনাস পান"). The bonus is paid when
        // the referred friend's qualifying recharge RUN is finished — not at
        // signup, and not on their first purchase. A signup bonus is worth
        // nothing to the operator and is farmed in minutes; a first-recharge
        // bonus is only slightly harder to farm, because one throwaway account
        // and one small top-up still collect it.
        //
        // The rule is therefore a count AND a floor: the friend must complete
        // `referral_required_recharges` approved recharges, and each of those
        // must be at least `referral_min_qualifying_recharge`. Both are snapshotted
        // onto the referral row when it is created, so a referral already in
        // flight finishes on the terms the user was shown even if the operator
        // changes the settings tomorrow.
        //
        // `referral_min_first_recharge` is kept for backwards compatibility: it
        // is the pre-existing key the old single-recharge rule read, it is still
        // on the settings form so no operator loses a field they had, and
        // {@see ReferralService} uses it as the fallback when the new minimum
        // has not been set. It is not read as the qualifying floor once the new
        // key exists.
        'referral_enabled' => ['রেফারেল সিস্টেম চালু', '1', 'checkbox'],
        'referrer_bonus_amount' => ['রেফারকারীর বোনাস (৳)', '50', 'number'],
        'referee_bonus_amount' => ['নতুন ইউজারের বোনাস (৳)', '20', 'number'],
        'referral_required_recharges' => ['প্রয়োজনীয় সফল রিচার্জ সংখ্যা', '5', 'number'],
        'referral_min_qualifying_recharge' => ['প্রতিটি রিচার্জ সর্বনিম্ন (৳)', '100', 'number'],
        'referral_min_first_recharge' => ['প্রথম রিচার্জ সর্বনিম্ন (৳)', '100', 'number'],
        'referral_terms' => ['রেফারেল শর্তাবলী', 'বন্ধুকে রেফার করুন — তার অনুমোদিত রিচার্জ শেষ হলে বোনাস পাবেন।', 'multiline'],

        // Order intake window: a daily Bangladesh-time range in which new
        // service orders may be submitted. Read by App\Service\OrderWindowService,
        // which evaluates "now" in Asia/Dhaka rather than in the server's zone.
        // 'order_window_enabled' = 0 turns the gate off entirely (24/7 intake),
        // which is also the fail-safe if the two times ever hold nonsense.
        'order_window_enabled' => ['অর্ডার সময় ব্যবস্থা চালু', '1', 'checkbox'],
        'order_window_start'   => ['অর্ডার শুরুর সময়', '08:00', 'text'],
        'order_window_end'     => ['অর্ডার শেষের সময়', '22:00', 'text'],

        // Operator-driven maintenance — "the site is closed, come back later",
        // decided by the owner from /admin/settings rather than by a deploy.
        // Read by App\Web\MaintenanceMiddleware, which is a *second*, later
        // gate than `runtime/maintenance.lock`: that one runs before the
        // autoloader because a broken deploy must still render a page, this one
        // runs inside a healthy application because only a healthy application
        // can ask the database whether it is switched on.
        //
        // The admin surfaces stay reachable while it is on (see the middleware
        // for why that is a prefix check rather than a role check), so this
        // switch can always be turned back off — it must never be a one-way one.
        'maintenance_enabled' => ['মেইনটেন্যান্স মোড চালু', '0', 'checkbox'],
        // What a visitor reads on the maintenance page, and the reason shown
        // to the API client as `message`. Empty is allowed: the page then
        // falls back to its own copy.
        'maintenance_message' => ['মেইনটেন্যান্স বার্তা', '', 'multiline'],
        // Scheduled maintenance — a daily HH:MM window (Bangladesh time) during
        // which the site is closed automatically. Empty = no schedule. When the
        // current time is inside the window the middleware treats the site as if
        // maintenance_enabled were on, with the window's own message. The window
        // is HH:MM in 24-hour format, e.g. "02:00" for 2 AM daily. Outside the
        // window the site is open regardless of maintenance_enabled.
        'maintenance_schedule_start' => ['মেইনটেন্যান্স শুরুর সময়', '', 'text'],
        'maintenance_schedule_end' => ['মেইনটেন্যান্স শেষের সময়', '', 'text'],
        'maintenance_schedule_message' => ['মেইনটেন্যান্স সময়ের বার্তা', '', 'multiline'],

        // Footer — shown on every public page.
        // The last-updated timestamp is set by the deploy script (or manually by
        // the operator) whenever a release goes out, so the footer always reflects
        // the real deploy date without the operator having to remember it.
        'footer_updated_at' => ['সর্বশেষ আপডেটের তারিখ', '', 'text'],
    ];

    /** Human labels for the payment methods shown on the recharge form. */
    public const METHOD_LABELS = [
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
    ];

    private const TABLE = '{{%site_setting}}';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string,string> all whitelisted keys resolved with defaults */
    public function all(): array
    {
        $values = array_map(static fn (array $def): string => $def[1], self::KEYS);

        $rows = $this->db
            ->createCommand('SELECT [[setting_key]], [[setting_value]] FROM ' . self::TABLE)
            ->queryAll();
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            if (array_key_exists($key, self::KEYS)) {
                $values[$key] = (string) $row['setting_value'];
            }
        }

        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        if (!isset(self::KEYS[$key])) {
            return $default;
        }
        $row = $this->db
            ->createCommand('SELECT [[setting_value]] FROM ' . self::TABLE . ' WHERE [[setting_key]] = :k LIMIT 1')
            ->bindValue(':k', $key)
            ->queryScalar();

        return $row === false ? self::KEYS[$key][1] : (string) $row;
    }

    /**
     * Upsert whitelisted keys only; unknown keys are silently dropped.
     * @param array<string,string> $values
     */
    public function putMany(array $values, ?int $updatedBy): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::KEYS)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && $this->isUrlKey($key) && !$this->isSafeUrl($value)) {
                continue; // reject unsafe URLs
            }
            $exists = $this->db
                ->createCommand('SELECT [[id]] FROM ' . self::TABLE . ' WHERE [[setting_key]] = :k LIMIT 1')
                ->bindValue(':k', $key)
                ->queryScalar();

            if ($exists !== false) {
                $this->db->createCommand()->update(self::TABLE, [
                    'setting_value' => $value,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ], ['id' => (int) $exists])->execute();
            } else {
                $this->db->createCommand()->insert(self::TABLE, [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ])->execute();
            }
        }
    }

    public function isUrlKey(string $key): bool
    {
        return isset(self::KEYS[$key]) && self::KEYS[$key][2] === 'url';
    }

    /**
     * Keys rendered as a checkbox. Browsers omit an unchecked box from the POST
     * body entirely, so callers must treat "absent" as false rather than skip it.
     *
     * @return list<string>
     */
    public static function checkboxKeys(): array
    {
        $keys = [];
        foreach (self::KEYS as $key => $def) {
            if ($def[2] === 'checkbox') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Only http(s) URLs, no javascript:/data: etc. */
    public function isSafeUrl(string $url): bool
    {
        $filtered = filter_var($url, FILTER_VALIDATE_URL);
        if ($filtered === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($filtered, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
