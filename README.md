# Alif Tools — ডিজিটাল সার্ভিস প্ল্যাটফর্ম
 PHP 8.2+/Yii 3, Twig, MySQL 8, Tailwind CSS, Alpine.js ও Lucide আইকন দিয়ে তৈরি। 
 **দেশজুড়ে ডিজিটাল সার্ভিস — এক ঠিকানায় অর্ডার, ট্র্যাক ও সাপোর্ট।**

## ফিচারসমূহ

- **পাবলিক সাইট** — হোম, সার্ভিস ব্রাউজ, about/privacy/terms পেজ
- **অথেনটিকেশন** — রেজিস্ট্রেশন, লগইন (থ্রটলসহ), লগআউট, সেশন ফিক্সেশন প্রোটেকশন
- **ড্যাশবোর্ড** — ব্যালেন্স/অর্ডার স্ট্যাটস, ক্যাটাগরি ফিল্টার, ডিবাউন্সড সার্চ (Alpine.js)
- **সার্ভিস এক্সিকিউশন** — মক প্রোভাইডার (NID কপি, ভোটার সার্চ, টিন সার্টিফিকেট ইত্যাদি), ফর্ম ভ্যালিডেশন, ব্যালেন্স ডেডাকশন, অর্ডার রেকর্ড
- **অ্যাকাউন্ট** — অর্ডার হিস্ট্রি, ট্রানজ্যাকশন লেজার, নোটিফিকেশন (read/read-all), প্রোফাইল ও পাসওয়ার্ড পরিবর্তন
- **অ্যাডমিন প্যানেল** — স্ট্যাটস ড্যাশবোর্ড, ইউজার ম্যানেজমেন্ট (রোল/স্ট্যাটাস/পাসওয়ার্ড রিসেট), ক্যাটাগরি ও সার্ভিস CRUD, লেনদেন পরিদর্শন, অ্যাক্টিভিটি লগ
- **JSON API** — `/api/*` অধীনে ড্যাশবোর্ড/সার্ভিস/অর্ডার/নোটিফিকেশন/প্রোফাইল এন্ডপয়েন্ট, স্ট্যান্ডার্ড `{success, message, data, errors}` এনভেলপ
- **নিরাপত্তা** — CSRF (ফর্ম + `X-CSRF-Token` হেডার), RBAC (user/staff/admin), সিকিউরিটি হেডার, হ্যাশড পাসওয়ার্ড
- **টেস্ট** — Codeception (Unit, Console, Functional, Web স্যুট)

## প্রয়োজনীয়তা

- PHP 8.2+ (pdo_mysql, mbstring, openssl, filter)
- PHP ext-curl — FCM/Telegram/Web Push পুশ পাঠানোর জন্য (ঐচ্ছিক: না থাকলে চ্যানেল নিজে থেকে dead-letter হয়, সাইট ও ইন-অ্যাপ নোটিফিকেশন অক্ষত থাকে)
- Composer 2.x
- Node 18+ / npm (অ্যাসেট বিল্ডের জন্য)
- MySQL 8 (বা 5.7+)

## ইনস্টলেশন

```bash
# ১. ডিপেন্ডেন্সি
composer install
npm install

# ২. এনভায়রনমেন্ট
cp .env.example .env   # .env এডিট করুন (DB ক্রেডেনশিয়াল)

# ৩. ডাটাবেস
php yii migrate:up --no-interaction
php yii app:seed       # নমুনা ক্যাটালগরি/সার্ভিস/ইউজার

# ৪. অ্যাসেট (Tailwind + JS sync + Lucide sprite)
npm run build

# ৫. রান (ডেভ সার্ভার)
php -S 127.0.0.1:8099 -t public public/index.php
```

`http://127.0.0.1:8099` ওপেন করুন।

### সিড করা অ্যাকাউন্ট

| ইউজারনেম | পাসওয়ার্ড | রোল |
|---|---|---|
| `admin` | `Admin1234!` | admin (ব্যালেন্স ৳5,000) |
| `rahim.demo` | `Demo1234!` | user (ব্যালেন্স ৳200) |

### সুপারএডমিন

`/admin/staff`, `/admin/withdraws` আর প্ল্যাটফর্ম-ওয়াইড লেজার শুধু `superadmin` রোল ধরে খোলে।
প্রথম সুপারএডমিন ওয়েব প্যানেল থেকে নিয়োগ করা যায় না — ওই পেজটাই সুপারএডমিন-দ্বারা-সুরক্ষিত — তাই একমাত্র পথ হলো CLI:

```bash
php yii app:super-admin                     # নতুন `superadmin` অ্যাকাউন্ট + জেনারেটেড পাসওয়ার্ড (একবারই দেখায়)
php yii app:super-admin --promote=admin     # আগের অ্যাকাউন্টকেই সুপারএডমিন বানান (পাসওয়ার্ড অপরিবর্তিত)
php yii app:super-admin --dry-run          # কী বদলাবে দেখান, কিছু লেখে না
```

প্রতিটি পরিবর্তন `activity_log`-এ `admin.superadmin_created` / `admin.superadmin_promoted` হিসেবে জমা হয়।

## টেস্ট

```bash
composer test                     # সব স্যুট (Web স্যুটে ডেভ সার্ভার চলমান থাকতে হবে)
vendor/bin/codecept run Unit,Console,Functional   # ইন-প্রসেস টেস্ট (সার্ভার লাগে না)
vendor/bin/codecept run Web       # PhpBrowser টেস্ট (127.0.0.1:8099-এ সার্ভার দরকার)
```

বর্তমান স্ট্যাটাস: **২৬ টেস্ট, সব পাস** (Unit 15 · Console 2 · Functional 5 · Web 5)।

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
