<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Category-based theme accent.
 *
 * Every service category carries its own brand accent, so the UI can colour a
 * card, chip, icon and its primary button by *what the service is* instead of
 * painting every option in the default primary green.
 *
 * Resolution order for a category (row array, slug string or null):
 *
 *   1. explicit `accent` column — set by an admin in /admin/categories
 *   2. keyword match on slug + name, English and Bangla
 *   3. deterministic hash of the slug, so two unconfigured categories still
 *      differ from each other and stay stable across requests
 *
 * The returned value is only a palette *key*; the actual colours live in
 * `resources/css/app.css` as `.accent-<key>` custom-property sets.
 *
 * Variants (the purchasable options of one service) are tinted one step finer:
 * a category-wide accent is a useful signal, but a two-option picker where both
 * cards are the same green tells the buyer nothing. See `variantKey()`.
 */
final class CategoryAccent
{
    public const DEFAULT_ACCENT = 'emerald';

    /**
     * Ordered so the hash fallback spreads across visually distinct hues
     * instead of landing two categories on neighbours.
     *
     * @var list<string>
     */
    public const PALETTE = [
        'emerald',
        'sky',
        'violet',
        'amber',
        'rose',
        'teal',
        'indigo',
        'orange',
        'lime',
        'fuchsia',
        'cyan',
        'slate',
    ];

    /** @var array<string, string> */
    private const LABELS = [
        'emerald' => 'সবুজ',
        'sky' => 'আকাশি',
        'violet' => 'বেগুনি',
        'amber' => 'হলুদ',
        'rose' => 'গোলাপি',
        'teal' => 'টিল',
        'indigo' => 'নীল',
        'orange' => 'কমলা',
        'lime' => 'লাইম',
        'fuchsia' => 'ম্যাজেন্টা',
        'cyan' => 'সায়ান',
        'slate' => 'ধূসর',
    ];

    /**
     * Keyword (lowercased) => palette key. Matched with `str_contains`, so
     * Bangla and English fragments both work. Order matters only when several
     * keywords appear in one name; the most specific ones are listed first.
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        // Identity & civil records
        'nationality' => 'indigo',
        'জাতীয়' => 'indigo',
        'জন্ম' => 'rose',
        'birth' => 'rose',
        'marriage' => 'rose',
        'বিয়ে' => 'rose',
        'family' => 'rose',
        'পরিবার' => 'rose',
        'voter' => 'violet',
        'ভোটার' => 'violet',
        'nid' => 'sky',
        'tin' => 'emerald',
        'টিন' => 'emerald',
        'passport' => 'slate',
        'পাসপোর্ট' => 'slate',
        'license' => 'slate',
        'লাইসেন্স' => 'slate',
        'death' => 'slate',
        'মৃত্যু' => 'slate',
        'certificate' => 'cyan',
        'সার্টিফিকেট' => 'cyan',
        'সনদ' => 'cyan',

        // Telecom & utilities
        'mobile' => 'teal',
        'মোবাইল' => 'teal',
        'banglalink' => 'teal',
        'grameenphone' => 'teal',
        'robi' => 'teal',
        'teletalk' => 'teal',
        'aamar' => 'teal',
        'internet' => 'teal',
        'ইন্টারনেট' => 'teal',

        // Money
        'bank' => 'amber',
        'ব্যাংক' => 'amber',
        'money' => 'amber',
        'পেমেন্ট' => 'amber',
        'payment' => 'amber',
        'bill' => 'amber',
        'বিল' => 'amber',
        'recharge' => 'amber',
        'রিচার্জ' => 'amber',
        'insurance' => 'fuchsia',
        'বীমা' => 'fuchsia',
        'loan' => 'fuchsia',
        'ঋণ' => 'fuchsia',

        // Education & work
        'education' => 'indigo',
        'শিক্ষা' => 'indigo',
        'result' => 'indigo',
        'রেজাল্ট' => 'indigo',
        'marksheet' => 'indigo',
        'মার্কশিট' => 'indigo',
        'job' => 'cyan',
        'চাকরি' => 'cyan',
        'business' => 'orange',
        'ব্যবসা' => 'orange',
        'trade' => 'orange',
        'ট্রেডে' => 'orange',

        // Property, misc
        'land' => 'lime',
        'জমি' => 'lime',
        'location' => 'lime',
        'লোকেশন' => 'lime',
        'ঠিকানা' => 'lime',
        'draw' => 'fuchsia',
        'লটারি' => 'fuchsia',
        'কুপন' => 'fuchsia',
        'chat' => 'emerald',
        'মেসেজ' => 'emerald',
        'whatsapp' => 'emerald',
        'other' => 'slate',
        'অন্যান্য' => 'slate',
    ];

    public function isValid(?string $accent): bool
    {
        return $accent !== null && in_array($accent, self::PALETTE, true);
    }

    /**
     * Palette options for an admin `<select>`: ['emerald' => 'সবুজ', ...].
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];
        foreach (self::PALETTE as $key) {
            $options[$key] = self::LABELS[$key];
        }
        return $options;
    }

    /**
     * @param array<string, mixed>|string|null $category Category row, slug or plain name.
     */
    public function key($category): string
    {
        if (is_string($category)) {
            // A palette key is already the answer. Without this check it would
            // be treated as a slug and pushed through keyword/hash resolution,
            // so key('violet') returned 'teal' and every "don't reuse the
            // category colour" guard was comparing against the wrong hue.
            return $this->isValid($category) ? $category : $this->fromText($category, $category);
        }
        if (!is_array($category)) {
            return self::DEFAULT_ACCENT;
        }

        $explicit = $category['accent'] ?? null;
        if (is_string($explicit) && $this->isValid($explicit)) {
            return $explicit;
        }

        $slug = (string) ($category['slug'] ?? '');
        $name = (string) ($category['name'] ?? '');

        return $this->fromText($slug, $name !== '' ? $slug . ' ' . $name : $slug);
    }

    /**
     * The CSS class that activates the palette, e.g. `accent-sky`.
     *
     * @param array<string, mixed>|string|null $category
     */
    public function cssClass($category): string
    {
        return 'accent-' . $this->key($category);
    }

    /**
     * Accent for one purchasable variant of a service.
     *
     * Resolution order:
     *
     *   1. explicit `accent` in the variant data — an admin can pin it in the
     *      service editor, mirroring the category column
     *   2. keyword match on the variant label, so a "রেচার্জ" option inside a
     *      generic service still reads as money (amber)
     *   3. a deterministic walk around the palette starting from the category
     *      colour, skipping the category colour and anything already handed out
     *
     * Step 3 is what guarantees the point of the feature: two options of the
     * same service never collapse onto one colour — up to the palette limit.
     * The category colour is held back, so `count(PALETTE) - 1` options are
     * guaranteed distinct; past that the walk necessarily recycles, which is
     * only reachable by a service with a dozen-plus options. `$taken` lets the
     * caller resolve a whole variant list at once via `variantKeys()` so siblings stay
     * distinct from each other, not merely from the category.
     *
     * @param array<string, mixed>|string|null $variant Variant row or its label.
     * @param list<string> $taken Palette keys already used by earlier variants.
     */
    public function variantKey($variant, string $categoryAccent, int $index, array $taken = []): string
    {
        $label = '';
        if (is_array($variant)) {
            $explicit = $variant['accent'] ?? null;
            if (is_string($explicit) && $this->isValid($explicit)) {
                return $explicit;
            }
            $label = (string) ($variant['label'] ?? $variant['name'] ?? '');
        } elseif (is_string($variant)) {
            $label = $variant;
        }

        if ($label !== '') {
            $keyword = $this->fromText($label, $label);
            if (!in_array($keyword, $taken, true) && $keyword !== $categoryAccent) {
                return $keyword;
            }
        }

        return $this->spread($categoryAccent, $index, $taken);
    }

    /**
     * Palette key for every variant of a service, indexed the same way the
     * template loops over them.
     *
     * @param list<array<string, mixed>|string>|null $variants
     *
     * @return array<int, string> Empty when there are no variants to tint.
     */
    public function variantKeys(?array $variants, $category): array
    {
        if ($variants === null || $variants === []) {
            return [];
        }

        $categoryAccent = $this->key($category);
        $keys = [];
        // $taken is tracked alongside $keys so each variant is resolved against
        // the accents already handed out and can never collide with a sibling.
        $taken = [];
        foreach ($variants as $variant) {
            $key = $this->variantKey($variant, $categoryAccent, count($keys), $taken);
            $keys[] = $key;
            $taken[] = $key;
        }

        return $keys;
    }

    /**
     * The CSS class for a variant, e.g. `accent-teal`.
     *
     * @param array<string, mixed>|string|null $variant
     * @param list<string> $taken
     */
    public function variantCssClass($variant, string $categoryAccent, int $index, array $taken = []): string
    {
        return 'accent-' . $this->variantKey($variant, $categoryAccent, $index, $taken);
    }

    /**
     * Walk the palette from the category colour until a free hue turns up.
     *
     * The stride is 5 rather than 1: PALETTE is roughly ordered by hue, so
     * neighbours read as "the same colour, slightly off" on a card. Because
     * gcd(5, 12) is 1 the walk still visits every hue before repeating, so a
     * long option list cycles through the whole wheel instead of stalling.
     *
     * The category colour is excluded from the walk entirely, not merely
     * started past. A full-length loop would reach it again on the last step
     * and hand an option the same colour as the card it sits in — which is
     * exactly what the feature is meant to prevent, and which only showed up
     * once a service had more options than there were free hues.
     */
    private function spread(string $categoryAccent, int $index, array $taken): string
    {
        $start = array_search($categoryAccent, self::PALETTE, true);
        $start = $start === false ? 0 : $start;

        // Candidate hues in walk order, with the category colour filtered out.
        $hues = [];
        $count = count(self::PALETTE);
        for ($step = 1; $step < $count; $step++) {
            $candidate = self::PALETTE[($start + $step * 5) % $count];
            if ($candidate !== $categoryAccent) {
                $hues[] = $candidate;
            }
        }

        foreach ($hues as $candidate) {
            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        // More options than there are hues left — reuse is unavoidable, but it
        // stays inside the non-category set and $index keeps it deterministic,
        // so a re-render never reshuffles the colours under the customer.
        return $hues[$index % count($hues)];
    }

    /**
     * Keyword match first, slug hash as the stable fallback.
     */
    private function fromText(string $slug, string $text): string
    {
        $haystack = mb_strtolower($text);

        foreach (self::KEYWORDS as $keyword => $accent) {
            if (str_contains($haystack, $keyword)) {
                return $accent;
            }
        }

        // crc32 over the slug is stable across requests, unlike array order.
        return self::PALETTE[crc32($slug) % count(self::PALETTE)];
    }
}
