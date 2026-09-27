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

        if ((int) $this->db->createCommand('SELECT COUNT(*) FROM {{%user}}')->queryScalar() > 0) {
            $io->warning('Database already seeded — skipping.');
            return Command::SUCCESS;
        }

        // ---- Users -----------------------------------------------------------
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

        // ---- Categories ------------------------------------------------------
        $categories = [
            ['NID Services', 'nid-services', 'credit-card', 'এনআইডি সংক্রান্ত সকল সেবা', 1],
            ['Voter Services', 'voter-services', 'users', 'ভোটার সার্চ ও তথ্য সেবা', 2],
            ['Tax / TIN', 'tax-tin', 'receipt', 'টিন সার্টিফিকেট ও কর সেবা', 3],
            ['Certificate Services', 'certificate-services', 'file-text', 'সনদ ও সার্টিফিকেট সেবা', 4],
            ['Digital Services', 'digital-services', 'smartphone', 'ডিজিটাল ও অনলাইন সেবা', 5],
        ];
        $catIds = [];
        foreach ($categories as [$name, $slug, $icon, $desc, $sort]) {
            $catIds[$slug] = $this->services->createCategory([
                'name' => $name,
                'slug' => $slug,
                'icon' => $icon,
                'description' => $desc,
                'sort_order' => $sort,
            ]);
        }

        // ---- Services (mock/demo) ---------------------------------------------
        $services = [
            ['nid-services', 'ইনস্ট্যান্ট NID সার্ভার কপি', 'instant-nid-server-copy', 25, 'zap', 'ইনস্ট্যান্ট ডেলিভারি', 'NID ও জন্মতারিখ দিয়ে ডেমো সার্ভার কপি ডাউনলোড।'],
            ['nid-services', 'NID কপি', 'nid-copy', 280, 'file-text', 'PDF কপি', 'ভোটার নম্বর বা ফর্ম নম্বর দিয়ে ডেমো ফুল NID কপি।'],
            ['voter-services', 'নাম ও ঠিকানা দিয়ে ভোটার সার্চ', 'voter-search', 2.5, 'search', 'অটোমেটিক', 'জেলা, উপজেলা ও নাম দিয়ে ডেমো ভোটার বিবরণ।'],
            ['tax-tin', 'টিন সার্টিফিকেট', 'tin-certificate', 120, 'receipt', 'TIN সেবা', '১২ ডিজিটের TIN দিয়ে ডেমো সার্টিফিকেট।'],
            ['certificate-services', 'জন্মনিবন্ধন ক্লোন কপি', 'birth-certificate-copy', 15, 'file-check', 'অটোমেটিক', '১৭ ডিজিট নম্বর দিয়ে ডেমো সনদ।'],
            ['digital-services', 'ডেমো ডিজিটাল সেবা প্যাক', 'demo-digital-pack', 50, 'smartphone', 'ডেমো', 'বিভিন্ন ডিজিটাল সেবার ডেমো প্যাকেজ।'],
        ];
        foreach ($services as $i => [$cat, $name, $slug, $price, $icon, $badge, $desc]) {
            $this->services->createService([
                'category_id' => $catIds[$cat],
                'name' => $name,
                'slug' => $slug,
                'description' => $desc,
                'icon' => $icon,
                'badge' => $badge,
                'service_type' => 'mock',
                'price' => $price,
                'sort_order' => $i + 1,
            ]);
        }

        $io->success('Seeded 2 users, 5 categories, 6 services. Admin: admin / Admin1234!, User: rahim.demo / Demo1234!');
        return Command::SUCCESS;
    }
}
