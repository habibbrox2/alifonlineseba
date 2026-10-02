<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand('app:seed', 'Seeds demo categories, services and users (clearly-marked demo data).')]
final class SeedCommand extends Command
{
    /**
     * Categories from the first placeholder seed pass, superseded by the
     * 8-category taxonomy above. They are deactivated rather than deleted so
     * services and transactions that still point at them keep their FKs.
     *
     * A named list, not "whatever is not in the taxonomy": a category an admin
     * added by hand must never be retired by a re-run of the demo seed.
     *
     * @var list<string>
     */
    private const LEGACY_CATEGORY_SLUGS = [
        'nid-services',
        'voter-services',
        'tax-tin',
        'certificate-services',
        'digital-services',
    ];

    /**
     * Demo variants per slug: options the order page shows as a selector with
     * per-variant prices ("original vs smart card copy" parity).
     *
     * @var array<string, array<int, array{label: string, price: float}>>
     */
    private const SEED_VARIANTS = [
        'official-server-copy' => [
            ['label' => 'অরিজিনাল কপি (PDF)', 'price' => 45],
            ['label' => 'স্মার্ট কার্ড কপি (Hi-Res)', 'price' => 60],
        ],
        'signature-smartcard-pdf' => [
            ['label' => 'সিগনেচার কপি', 'price' => 280],
            ['label' => 'স্মার্ট কার্ড PDF (ফ্রন্ট-ব্যাক)', 'price' => 350],
        ],
        'nid-copy' => [
            ['label' => 'সাধারণ কপি', 'price' => 280],
            ['label' => 'কালার হাই-রেজুলিউশন কপি', 'price' => 340],
        ],
    ];

    /**
     * Demo ordering rules per slug, rendered as the rules card on the order page.
     *
     * @var array<string, string>
     */
    private const SEED_RULES = [
        'official-server-copy' => "সঠিক ১৭ ডিজিটের NID নম্বর দিন — ভুল নম্বরের জন্য টাকা ফেরতযোগ্য নয়।\nএকটি অর্ডারে একজনের তথ্য প্রদান করুন।\nডেমো সার্ভিস: ফলাফল সম্পূর্ণ কাল্পনিক এবং কয়েক সেকেন্ডে ডেলিভারি হয়।",
        'nid-copy' => "ভোটার নম্বর, ফর্ম নম্বর, স্লিপ বা NID — যেকোনো একটি দিলেই চলবে।\nস্মার্ট কার্ড ইস্যু হওয়ার পরেই কপি পাওয়া যাবে।",
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UserRepository $users,
        private readonly ServiceRepository $services,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $usersExist = (int) $this->db->createCommand('SELECT COUNT(*) FROM {{%user}}')->queryScalar() > 0;

        // ---- Users -----------------------------------------------------------
        if (!$usersExist) {
            $this->users->create([
                'username' => 'admin',
                'phone' => '01700000000',
                'email' => 'admin@demo.local',
                'password_hash' => password_hash('Admin1234!', PASSWORD_DEFAULT),
                'role' => 'admin',
                'balance' => 5000,
            ]);
            $this->users->create([
                'username' => 'rahim.demo',
                'phone' => '01712345678',
                'email' => 'rahim@demo.local',
                'password_hash' => password_hash('Demo1234!', PASSWORD_DEFAULT),
                'role' => 'user',
                'balance' => 250,
            ]);
        }

        // ---- Catalog: idempotent by slug -------------------------------------
        // Re-running the seed refreshes categories/services to the current
        // demo catalog without touching user rows (their transactions FK to
        // services, so only services that no transaction references are
        // replaced; the rest are inserted when missing).
        $existingSlugs = [];
        foreach ($this->db->createCommand('SELECT [[slug]] FROM {{%service}}')->queryAll() as $row) {
            $existingSlugs[(string) $row['slug']] = true;
        }
        $existingCategorySlugs = [];
        foreach ($this->db->createCommand('SELECT [[slug]] FROM {{%service_category}}')->queryAll() as $row) {
            $existingCategorySlugs[(string) $row['slug']] = true;
        }

        // ---- Categories — the reference site's 9-chip taxonomy ----------------
        $categories = [
            ['NID ও ভোটার', 'nid-voter', 'credit-card', 'এনআইডি, ভোটার ও সার্ভার কপি সেবা', 1],
            ['টিন সার্টিফিকেট', 'tin-certificate', 'receipt', 'TIN তৈরি, উত্তোলন ও রিটার্ণ', 2],
            ['লোকেশন ও ট্র্যাকিং', 'location-tracking', 'map-pin', 'ফোন ও IMEI লোকেশন ট্র্যাকিং', 3],
            ['বায়োমেট্রিক ও সিম', 'biometric-sim', 'fingerprint', 'সিম বায়োমেট্রিক ও নিবন্ধন তথ্য', 4],
            ['মোবাইল ব্যাংকিং', 'mobile-banking', 'wallet', 'MFS একাউন্ট তথ্য ও KYC', 5],
            ['কল লিস্ট (CDR)', 'call-list-cdr', 'phone-call', 'কল ও এসএমএস রেকর্ড', 6],
            ['জন্ম নিবন্ধন', 'birth-registration', 'file-check', 'জন্মনিবন্ধন সনদ সেবা', 7],
            ['পাসপোর্ট ও BMET', 'passport-bmet', 'plane', 'পাসপোর্ট ও বিএমইটি সেবা', 8],
        ];
        $catIds = [];
        foreach ($categories as [$name, $slug, $icon, $desc, $sort]) {
            if (isset($existingCategorySlugs[$slug])) {
                $catIds[$slug] = (int) $this->db
                    ->createCommand('SELECT [[id]] FROM {{%service_category}} WHERE [[slug]] = :s')
                    ->bindValue(':s', $slug)
                    ->queryScalar();
                continue;
            }
            $catIds[$slug] = $this->services->createCategory([
                'name' => $name,
                'slug' => $slug,
                'icon' => $icon,
                'description' => $desc,
                'sort_order' => $sort,
            ]);
        }

        // ---- Services — the reference site's 18-service catalog (all mock) -----
        $created = 0;
        $services = [
            // NID ও ভোটার
            ['nid-voter', 'ইনস্ট্যান্ট NID সার্ভার কপি', 'instant-nid-server-copy', 25, 'zap', 'ইনস্ট্যান্ট ডেলিভারি', 'NID ও জন্মতারিখ দিয়ে অফিসিয়াল QR কোড ও সাধারণ সার্ভার কপি ডাউনলোড।'],
            ['nid-voter', 'NID কপি', 'nid-copy', 280, 'file-text', 'PDF কপি', 'ভোটার নম্বর, ফর্ম নম্বর, স্লিপ বা এনআইডি নম্বর দিয়ে সরাসরি সার্ভার থেকে ফুল NID কপি অর্ডার করুন।'],
            ['nid-voter', 'সিগনেচার কপি ও স্মার্ট কার্ড PDF', 'signature-smartcard-pdf', 280, 'file-image', 'স্মার্ট PDF', 'অফিসিয়াল সাইন কপি ও স্মার্ট কার্ডের ফ্রন্ট-ব্যাক হাই রেজুলেশন কালার কপি।'],
            ['nid-voter', 'Official Server Copy (১৭ ডিজিট পিনসহ)', 'official-server-copy', 45, 'file-badge', 'ইনস্ট্যান্ট ডেলিভারি', 'TotthoHub Tools এর মাধ্যমে অফিসিয়াল সার্ভার কপি ডাউনলোড সেবা।'],
            ['nid-voter', 'নাম ও ঠিকানা দিয়ে ভোটার সার্চ', 'voter-search-by-name', 2.5, 'search', 'অটোমেটিক', 'জেলা, উপজেলা ও নাম দিয়ে নিখুঁত ভোটার বিবরণ ও এনআইডি নম্বর বের করুন।'],
            ['nid-voter', 'এনআইডি পাসওয়ার্ড সেট (Face Scan ছাড়া)', 'nid-password-set', 90, 'key-round', 'Face-less', 'লক হওয়া অ্যাকাউন্ট রিকভারি ও ফেস স্ক্যান ছাড়া পাসওয়ার্ড কনফিগারেশন।'],
            ['nid-voter', 'এনআইডি সংশোধন ও হারানো কার্ড উত্তোলন', 'nid-correction-reissue', 300, 'file-pen', 'সংশোধন', 'সকল ক্যাটাগরীর নাম, বয়স সংশোধন ও হারানো কার্ড রি-ইস্যু আবেদন।'],
            ['nid-voter', 'ফোন নম্বর / জন্ম নিবন্ধন দিয়ে NID', 'nid-by-phone-birth', 50, 'phone-forwarded', 'ক্রস ম্যাচ', 'মোবাইল নম্বর অথবা জন্ম নিবন্ধন দিয়ে ব্যক্তির সম্পূর্ণ NID কার্ড অনুসন্ধান।'],

            // টিন সার্টিফিকেট
            ['tin-certificate', 'টিন সার্টিফিকেট (নতুন / উত্তোলন / রিটার্ণ)', 'tin-certificate-full', 120, 'receipt', 'TIN সেবা', 'নতুন TIN তৈরি, ১২ ডিজিটের TIN নম্বর দিয়ে উত্তোলন ও জিরো রিটার্ণ দাখিল।'],

            // লোকেশন ও ট্র্যাকিং
            ['location-tracking', 'ফোন নাম্বার দিয়ে লাইভ লোকেশন', 'live-location-by-phone', 280, 'map-pin', 'লাইভ ম্যাপ', 'মোবাইল নম্বরের টাওয়ার সিগন্যাল ও গুগল ম্যাপ লাইভ অবস্থান ট্র্যাকিং।'],
            ['location-tracking', 'IMEI To Location & B-Party IMEI', 'imei-to-location', 700, 'smartphone', 'EIR ডাটা', '১৫ ডিজিটের IMEI দিয়ে লোকেশন ও কলের অপর প্রান্তের বি-পার্টি IMEI বের করুন।'],

            // বায়োমেট্রিক ও সিম
            ['biometric-sim', 'সিমের বায়োমেট্রিক তথ্য (সকল অপারেটর)', 'sim-biometric-info', 140, 'fingerprint', 'সকল সিম', 'জিপি, বাংলালিংক, রবি, এয়ারটেল ও টেলিটকের নিবন্ধিত ব্যক্তির বায়োমেট্রিক ডাটা।'],
            ['biometric-sim', 'NID দিয়ে সকল মোবাইল সিমের তালিকা', 'sim-list-by-nid', 380, 'sim-card', 'All SIM', 'একটি এনআইডি কার্ড দিয়ে কতটি সিম সক্রিয় রয়েছে তার পূর্ণাঙ্গ তালিকা।'],

            // মোবাইল ব্যাংকিং
            ['mobile-banking', 'বিকাশ / নগদ / রকেট একাউন্ট তথ্য', 'mfs-account-info', 780, 'wallet', 'MFS KYC', 'যেকোনো বিকাশ, নগদ বা রকেট একাউন্টের মালিকানা, এনআইডি ও মিনি স্টেটমেন্ট।'],

            // কল লিস্ট (CDR)
            ['call-list-cdr', 'কল ও এসএমএস লিস্ট (৩ ও ৬ মাস)', 'call-sms-list-cdr', 1300, 'phone-call', 'CDR PDF', '৩ থেকে ৬ মাসের ইনকামিং-আউটগোয়িং কল ও মেসেজ রেকর্ড (CDR PDF)।'],

            // জন্ম নিবন্ধন
            ['birth-registration', 'জন্মনিবন্ধন ক্লোন কপি', 'birth-certificate-clone', 15, 'file-check', 'অটোমেটিক', '১৭ ডিজিট নম্বর ও জন্মতারিখ দিয়ে ইনস্ট্যান্ট জন্মনিবন্ধন সনদ সার্চ ও প্রিন্ট।'],

            // পাসপোর্ট ও BMET
            ['passport-bmet', 'MRP পাসপোর্ট ও ই-পাসপোর্ট SB Copy', 'mrp-epassport-sb-copy', 1000, 'plane', 'SB Copy', 'MRP ও ই-পাসপোর্টের অফিসিয়াল SB কপি এবং NID To পাসপোর্ট ইনফরমেশন।'],
            ['passport-bmet', 'BMET সার্ভিস (অনুমোদন, সংশোধন ও আপডেট)', 'bmet-service', 250, 'plane-takeoff', 'BMET 2026', '৭৮% পেন্ডিং অনুমোদন, BMET সংশোধন ও পুরাতন রেকর্ড ২০২৬ সালে আপডেট।'],
        ];
        foreach ($services as $i => [$cat, $name, $slug, $price, $icon, $badge, $desc]) {
            if (isset($existingSlugs[$slug])) {
                continue; // already seeded — leave its transactions attached
            }
            $this->services->createService([
                'category_id' => $catIds[$cat],
                'name' => $name,
                'slug' => $slug,
                'description' => $desc,
                'icon' => $icon,
                'badge' => $badge,
                'service_type' => 'mock',
                'price' => $price,
                'variants' => self::SEED_VARIANTS[$slug] ?? null,
                'rules' => self::SEED_RULES[$slug] ?? null,
                'sort_order' => $i + 1,
            ]);
            $created++;
        }

        // ---- Retire superseded demo slugs -----------------------------------
        // The first seed pass used a smaller placeholder catalog; those slugs
        // are not part of the reference taxonomy. Disable the services and
        // categories (rows stay, so FK/transaction history survives) instead
        // of deleting them.
        // ---- Backfill variants/rules for pre-existing services ---------------
        // Services seeded before those columns existed get the same demo data
        // as a fresh install; rows that already define variants are untouched.
        foreach (self::SEED_VARIANTS as $slug => $variants) {
            $service = $this->services->findServiceBySlug($slug);
            if ($service !== null && self::variantsEmpty($service['variants'] ?? null)) {
                $this->services->updateVariantsAndRules(
                    (int) $service['id'],
                    $variants,
                    self::SEED_RULES[$slug] ?? null,
                );
            }
        }

        // ---- Retire superseded demo slugs -----------------------------------
        foreach (self::LEGACY_CATEGORY_SLUGS as $slug) {
            if (isset($existingCategorySlugs[$slug])) {
                $this->services->updateCategory((int) $this->db
                    ->createCommand('SELECT [[id]] FROM {{%service_category}} WHERE [[slug]] = :s')
                    ->bindValue(':s', $slug)
                    ->queryScalar(), ['status' => 'inactive']);
            }
        }

        $legacyServices = ['tin-certificate', 'nid-copy', 'instant-nid-server-copy', 'voter-search', 'birth-certificate-copy', 'demo-digital-pack'];
        foreach ($legacyServices as $slug) {
            $service = $this->services->findServiceBySlug($slug);
            if ($service !== null) {
                $this->services->updateService((int) $service['id'], ['status' => 'inactive']);
            }
        }

        $io->success(sprintf(
            'Users: %s. Catalog: %d services present, %d newly added, %d categories.',
            $usersExist ? 'kept' : 'seeded (admin/Admin1234!, rahim.demo/Demo1234!)',
            count($existingSlugs) + $created,
            $created,
            count($catIds),
        ));
        return Command::SUCCESS;
    }

    /** NULL / empty string / "[]" — i.e. nothing user-defined is stored yet. */
    private static function variantsEmpty(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return true;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return !is_array($decoded) || $decoded === [];
    }
}
