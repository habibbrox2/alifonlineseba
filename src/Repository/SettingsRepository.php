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
    /** key => [label, default, type] — type: text|url|multiline */
    public const KEYS = [
        'site_tagline'      => ['ট্যাগলাইন', 'ইনস্ট্যান্ট ডিজিটাল সার্ভিস প্ল্যাটফর্ম', 'text'],
        'contact_email'     => ['যোগাযোগ ইমেইল', '', 'text'],
        'contact_phone'     => ['যোগাযোগ ফোন', '', 'text'],
        'footer_note'       => ['ফুটার নোট', 'এটি একটি ডেমো অ্যাপ্লিকেশন — সকল ডাটা কাল্পনিক।', 'multiline'],
        'facebook_url'      => ['ফেসবুক পেজ', '', 'url'],
        'youtube_url'       => ['ইউটিউব চ্যানেল', '', 'url'],
        'whatsapp_url'      => ['হোয়াটসঅ্যাপ', '', 'url'],
        'telegram_url'      => ['টেলিগ্রাম', '', 'url'],
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
