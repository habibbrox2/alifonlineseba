# Alif Tools — ডিজিটাল সার্ভিস প্ল্যাটফর্ম

PHP 8.2+/Yii 3, Twig, MySQL 8, Tailwind CSS, Alpine.js ও Lucide আইকন দিয়ে তৈরি।
**দেশজুড়ে ডিজিটাল সার্ভিস — এক ঠিকানায় অর্ডার, ট্র্যাক ও সাপোর্ট।**

## Project Overview

Alif Tools একটি পূর্ণ-ফাংশনাল ডিজিটাল সার্ভিস প্ল্যাটফর্ম, যেখানে ব্যবহারকারী সহজে সার্ভিস বুকিং, অর্ডার ম্যানেজমেন্ট, ব্যালেন্স ট্র্যাকিং, পেমেন্ট, নোটিফিকেশন এবং অ্যাকাউন্ট ম্যানেজমেন্ট পরিচালনা করতে পারে। একইসাথে অ্যাডমিন ও সুপারএডমিনদের জন্য আলাদা ড্যাশবোর্ড, স্টাফ ম্যানেজমেন্ট, লেনদেন পরিদর্শন, সেটিংস এবং অ্যাক্টিভিটি লগ ব্যবস্থা রয়েছে।

এই প্রকল্পটি ছোট/মিড-স্কেল ডিজিটাল সার্ভিস ব্যবসার জন্য ডিজাইন করা হয়েছে, যেখানে:

- ব্যবহারকারী দ্রুত সার্ভিস নির্বাচন ও অর্ডার করতে পারে
- অ্যাডমিন কাজের অগ্রগতি, ব্যালেন্স ও পেমেন্ট তথ্য দেখতে পারে
- সুপারএডমিন নিরাপদভাবে প্ল্যাটফর্মের প্রশাসন পরিচালনা করতে পারে
- API, UI ও প্রশাসনিক কার্যক্রম একসাথে একীভূত হয়েছে

## Quick Start

```bash
composer install
npm install
cp .env.example .env
php yii migrate:up --no-interaction
php yii app:seed
npm run build
php -S 127.0.0.1:8080 -t public public/index.php
```

তারপর ব্রাউজারে খুলুন: `http://127.0.0.1:8080`

## Environment Setup

প্রয়োজনীয় পরিবেশ:

- PHP 8.2+
- Composer 2.x
- Node 18+ / npm
- MySQL 8 (বা 5.7+)
- `pdo_mysql`, `mbstring`, `openssl`, `filter` এক্সটেনশন নিশ্চিত করুন
- `ext-curl` প্রয়োজন (FCM/Telegram/Web Push)

`.env.example` থেকে `.env` কপি করে DB credentials, app URL, session ও security settings ঠিক করুন।

## Credentials

| ইউজারনেম | পাসওয়ার্ড | রোল |
|---|---|---|
| `admin` | `Admin1234!` | admin (ব্যালেন্স ৳5,000) |
| `rahim.demo` | `Demo1234!` | user (ব্যালেন্স ৳200) |
| `superadmin` | `php yii app:super-admin` দিয়ে তৈরি হওয়া/generated পাসওয়ার্ড | superadmin |

> `app:seed` ডিফল্টভাবে `admin` ও `rahim.demo` ব্যবহারকারী তৈরি করে। প্রথম `superadmin` তৈরি করতে `php yii app:super-admin` চালাতে হবে।

## Admin Access

`/admin/staff`, `/admin/withdraws` এবং প্ল্যাটফর্ম-ওয়াইড লেজার-জাতীয় সংবেদনশীল পেজগুলো `superadmin`-নির্ভর।

```bash
php yii app:super-admin
php yii app:super-admin --promote=admin
php yii app:super-admin --dry-run
```

- নতুন সুপারএডমিন তৈরি করতে CLI ব্যবহার করুন
- `--promote=admin` দিয়ে বিদ্যমান অ্যাকাউন্টকেও সুপারএডমিন বানানো যায়
- `--dry-run` দিয়ে কী পরিবর্তন হবে তা আগে দেখে নিতে পারবেন

## Deployment Notes

- ডেভ সার্ভার ডিফল্টভাবে `127.0.0.1:8080`-এ চলবে
- production deployment-এ `public/` ডিরেক্টরি web root হিসেবে সেট করুন
- `.env`-এ `APP_DEBUG=false` রাখুন
- `runtime/` ডিরেক্টরি writable রাখুন
- Apache/Nginx routing ও `public/.htaccess` কনফিগারেশন সঠিকভাবে সেট করুন
- production পরিবেশে sensitive credentials ও secrets `.env`-এ নিরাপদভাবে রাখুন

## ফিচারসমূহ

- **পাবলিক ওয়েবসাইট** — হোমপেজ, সার্ভিস ব্রাউজ, About/Privacy/Terms পেজ ও ব্র্যান্ড-ফোকাসড ডিজাইন
- **অথেনটিকেশন** — রেজিস্ট্রেশন, লগইন (থ্রটলসহ), লগআউট, সেশন ফিক্সেশন প্রোটেকশন
- **ড্যাশবোর্ড** — ব্যালেন্স, অর্ডার স্ট্যাটাস, ক্যাটাগরি ফিল্টার ও ডিবাউন্সড সার্চ (Alpine.js)
- **সার্ভিস এক্সিকিউশন** — mock provider ভিত্তিক NID, ভোটার সার্চ, TIN, সনদ ইত্যাদি সার্ভিস, ফর্ম ভ্যালিডেশন, ব্যালেন্স ডিডাকশন ও অর্ডার রেকর্ড
- **অ্যাকাউন্ট প্যানেল** — অর্ডার ইতিহাস, ট্রানজ্যাকশন লেজার, নোটিফিকেশন (read/read-all), প্রোফাইল ও পাসওয়ার্ড পরিবর্তন
- **অ্যাডমিন প্যানেল** — স্ট্যাটস ড্যাশবোর্ড, ইউজার ম্যানেজমেন্ট (রোল, স্ট্যাটাস, পাসওয়ার্ড রিসেট), ক্যাটাগরি ও সার্ভিস CRUD, লেনদেন পরিদর্শন, অ্যাক্টিভিটি লগ
- **JSON API** — `/api/*` এ ড্যাশবোর্ড, সার্ভিস, অর্ডার, নোটিফিকেশন ও প্রোফাইল API, স্ট্যান্ডার্ড `{success, message, data, errors}` রেসপন্স ফরম্যাট
- **নিরাপত্তা** — CSRF (ফর্ম + `X-CSRF-Token` হেডার), RBAC (user/staff/admin/superadmin), সিকিউরিটি হেডার ও হ্যাশড পাসওয়ার্ড
- **টেস্টিং** — Codeception (Unit, Console, Functional, Web স্যুট)

## প্রয়োজনীয়তা

- PHP 8.2+ (pdo_mysql, mbstring, openssl, filter)
- PHP ext-curl — FCM/Telegram/Web Push পুশ পাঠানোর জন্য (ঐচ্ছিক: না থাকলে চ্যানেল নিজে থেকে dead-letter হয়, সাইট ও ইন-অ্যাপ নোটিফিকেশন অক্ষত থাকে)
- Composer 2.x
- Node 18+ / npm (অ্যাসেট বিল্ডের জন্য)
- MySQL 8 (বা 5.7+)

## ইনস্টলেশন

```bash
# ১. ডিপেন্ডেন্সি ইনস্টল
composer install
npm install

# ২. এনভায়রনমেন্ট কনফিগার
cp .env.example .env   # .env ফাইলে DB credential সেট করুন

# ৩. ডাটাবেস মাইগ্রেশন ও সিড ডেটা
php yii migrate:up --no-interaction
php yii app:seed       # নমুনা ক্যাটালগ, সার্ভিস ও ইউজার তৈরি

# ৪. অ্যাসেট বিল্ড (Tailwind + JS sync + Lucide sprite)
npm run build

# ৫. ডেভ সার্ভার চালু করুন
php -S 127.0.0.1:8080 -t public public/index.php
```

তারপর ব্রাউজারে `http://127.0.0.1:8080` খুলুন।

### সিড করা অ্যাকাউন্ট

| ইউজারনেম | পাসওয়ার্ড | রোল |
|---|---|---|
| `admin` | `Admin1234!` | admin (ব্যালেন্স ৳5,000) |
| `superadmin` | `php yii app:super-admin` কমান্ডের মাধ্যমে তৈরি হওয়া/generated পাসওয়ার্ড | superadmin |
| `rahim.demo` | `Demo1234!` | user (ব্যালেন্স ৳200) |

> `app:seed` ডিফল্টভাবে শুধু `admin` ও `rahim.demo` ব্যবহারকারী তৈরি করে। প্রথম `superadmin` তৈরি করতে `php yii app:super-admin` চালাতে হবে। যদি প্রয়োজন হয়, `--promote=admin` দিয়ে বিদ্যমান অ্যাকাউন্টকেও সুপারএডমিন বানানো যেতে পারে।

### সুপারএডমিন

`/admin/staff`, `/admin/withdraws` এবং প্ল্যাটফর্ম-ওয়াইড লেজার একচেটিয়াভাবে `superadmin` রোল-ধারী ব্যবহারকারীর জন্য খোলা থাকে।
প্রথম সুপারএডমিনকে ওয়েব প্যানেল থেকে তৈরি করা সম্ভব নয়, কারণ ওই পেজগুলো নিজেই সুপারএডমিন-সুরক্ষিত। তাই একমাত্র কার্যকর উপায় হলো CLI:

```bash
php yii app:super-admin                     # নতুন `superadmin` অ্যাকাউন্ট তৈরি + একবার দেখানো জেনারেটেড পাসওয়ার্ড
php yii app:super-admin --promote=admin     # বিদ্যমান অ্যাকাউন্টকে সুপারএডমিন বানান (পাসওয়ার্ড অপরিবর্তিত)
php yii app:super-admin --dry-run          # কী পরিবর্তন হবে তা দেখুন, কিন্তু কোনো লেখা হবে না
```

প্রতিটি পরিবর্তন `activity_log`-এ `admin.superadmin_created` / `admin.superadmin_promoted` হিসেবে নথিভুক্ত হয়।

## টেস্ট

```bash
composer test                     # সব স্যুট চালানো (Web স্যুটের জন্য ডেভ সার্ভার চালু থাকতে হবে)
vendor/bin/codecept run Unit,Console,Functional   # ইন-প্রসেস টেস্ট (সার্ভার লাগবে না)
vendor/bin/codecept run Web       # PhpBrowser টেস্ট (127.0.0.1:8080-এ সার্ভার দরকার)
```

বর্তমান স্ট্যাটাস: **২৬টি টেস্ট, সব পাস** (Unit 15 · Console 2 · Functional 5 · Web 5)।

## প্রজেক্ট স্ট্রাকচার

```
├── config/                 # Yii 3 config (common + web, nested params merge)
├── migrations/             # DB schema
├── public/                 # ওয়েবরুট (index.php, assets, .htaccess)
├── resources/
│   ├── css/                # Tailwind সোর্স
│   ├── js/                 # ES modules (app.js, dashboard.js …) → public/assets এ sync হয়
│   └── views/              # Twig টেমপ্লেট (layouts/, site/, errors/)
├── scripts/                # sync-js.js, generate-sprite.php
├── src/
│   ├── Auth/               # Identity, session auth, role guards
│   ├── Console/            # yii কমান্ড (app:seed, app:fcm:check, app:webpush:check)
│   ├── Notification/       # ইভেন্ট, কিউ, চ্যানেল (in_app, fcm, webpush, telegram)
│   ├── Provider/           # মক সার্ভিস প্রোভাইডার
│   ├── Repository/         # DB অ্যাক্সেস
│   └── Web/                # Actions (Site, Auth, Dashboard, Services, Account, Admin, Api)
├── tests/                  # Codeception স্যুট
└── docs/                   # architecture-plan, reference-ui-analysis, deployment, payment-brand, push
```

## ডকুমেন্টেশন

- [ডিপ্লয়মেন্ট গাইড (শেয়ার্ড হোস্টিং)](docs/deployment.md)
- [Shared Hosting রেডিনেস রিপোর্ট — কী করা হলো, কেন করা হলো](docs/shared-hosting-readiness-report.md)
- [আর্কিটেকচার প্ল্যান](docs/architecture-plan.md)
- [রেফারেন্স UI অ্যানালাইসিস](docs/reference-ui-analysis.md)
- [পেমেন্ট ব্র্যান্ড গাইডলাইন (bKash, Nagad, Rocket)](docs/payment-brand-guidelines.md)
- [ব্রাউজার পুশ নোটিফিকেশন — আর্কিটেকচার](docs/push-notifications.md)
- [ব্রাউজার পুশ নোটিফিকেশন — ইউজার ও অ্যাডমিন গাইড](docs/push-notifications-bn.md)
- [ওয়েব পুশ রানবুক (সেটআপ ও ট্রাবলশুটিং)](docs/webpush-runbook.md)

## লাইসেন্স

Alif Tools — সর্বস্বত্ব সংরক্ষিত।
