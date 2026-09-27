# Deployment Guide (Shared Hosting / Apache)

TH Tools একটি PHP 8.2+ / Yii 3 অ্যাপ্লিকেশন, যা cPanel-স্টাইল শেয়ার্ড হোস্টিং-এ ডিপ্লয় করা যায়।

## ১. রিকোয়্যারমেন্ট

- PHP **8.2+** (ext: pdo_mysql, mbstring, openssl, filter)
- MySQL 8 (বা 5.7+)
- Apache + mod_rewrite
- Composer (লোকালে বিল্ড করে আপলোড করলে হোস্টে Composer লাগে না)

## ২. স্ট্রাকচার

লক্ষ্য: ওয়েবরুট হবে `public/`। দুইটি সাধারণ কেস:

### কেস A — DocumentRoot `public/` এ পয়েন্ট করা যায় (সবচেয়ে ভালো)

```
/home/user/thtools/        ← পুরো প্রজেক্ট
/home/user/public_html/    ← symlink → /home/user/thtools/public
```

cPanel: *Domains → Document Root* পরিবর্তন করে `thtools/public` সেট করুন, অথবা:

```bash
ln -s /home/user/thtools/public /home/user/public_html
```

`public/.htaccess` ফাইলটি এই কেসেই কাজ করে।

### কেস B — DocumentRoot পরিবর্তন করা যায় না (public_html ওয়েবরুট)

দুইটি পরিবর্তন:

1. প্রজেক্ট আপলোড করুন ওয়েবরুটের *বাইরে*, যেমন `/home/user/thtools/`।
2. `public_html/.htaccess` বানান:

```apache
RewriteEngine On
RewriteRule ^(.*)$ ../thtools/public/$1 [L]
```

যদি হোস্ট উপরের `..` রিরাইট না দেয় (অনেক হোস্ট দেয় না), তাহলে `public_html/index.php` wrapper ব্যবহার করুন:

```php
<?php
// public_html/index.php — forwards into the app's public dir.
chdir(dirname(__DIR__) . '/thtools/public');
require dirname(__DIR__) . '/thtools/public/index.php';
```

এবং `public_html/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

> **বিকল্প:** `.env`-এ `APP_HOST_PATH=/thtools/public` সেট করলে সাব-ডিরেক্টরি হোস্টিং-ও (যেমন `example.com/tools/`) সাপোর্ট করে — বিস্তারিত `src/Environment.php`।

## ৩. কনফিগারেশন

`public/` এর এক লেভেল উপরে `.env` ফাইল বানান (টেমপ্লেট হিসেবে `.env.example` দেখুন):

```dotenv
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://your-domain.com
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=yourdb;charset=utf8mb4
DB_USERNAME=yourdbuser
DB_PASSWORD=strong-secret
CACHE_PATH=runtime/cache
SESSION_NAME=TH_SESSION
```

**নিরাপত্তা চেকলিস্ট:**
- `APP_DEBUG=false` (true রাখলে এক্সেপশন স্ট্যাক-ট্রেস পাবলিক হয়ে যায়)
- `.env` ফাইল কখনো `public/`-এর ভেতরে রাখবেন না; `public/.htaccess` তবুও dotfiles ব্লক করে
- `runtime/` ডিরেক্টরি সার্ভারে writable রাখুন, ওয়েব থেকে অ্যাক্সেসযোগ্য নয়

## ৪. বিল্ড ও ডাটাবেস

লোকালে:

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

সার্ভারে (SSH থাকলে):

```bash
php yii migrate:up --no-interaction
php yii app:seed   # ঐচ্ছিক — ডেমো ডাটা
rm -rf runtime/cache   # কনফিগ ক্যাশ রিফ্রেশ
```

SSH না থাকলে: লোকালে উপরের কমান্ডগুলো চালিয়ে পুরো ফোল্ডার আপলোড করুন; `runtime/cache/` খালি করে আপলোড করুন।

## ৫. অ্যাসেট

`npm run build` চালালে তৈরি হয়:

- `public/assets/css/app.css` (Tailwind minified)
- `public/assets/js/*` (app.js, dashboard.js, alpine.min.js)
- `public/assets/icons/lucide-sprite.svg`

আপলোডে এই তিনটি ফোল্ডার অবশ্যই যাবে — PHP রানটাইমে এগুলো জেনারেট হয় না।

## ৬. সেশন ও ক্যাশ

- সেশন ফাইল-ভিত্তিক, `SESSION_NAME` কুকি নাম ব্যবহার করে — শেয়ার্ড হোস্টিং-এ কোনো অতিরিক্ত সেটআপ লাগে না।
- ক্যাশ ফাইল-ভিত্তিক (`CACHE_PATH`)। ডিপ্লয়ের পর কনফিগ/রুট পরিবর্তন হলে `runtime/cache/` মুছে দিন।

## ৭. ট্রাবলশুটিং

| সমস্যা | সমাধান |
|---|---|
| সব পেজে 404 | mod_rewrite চালু আছে কিনা, `public/.htaccess` আপলোড হয়েছে কিনা চেক করুন (`AllowOverride All` দরকার) |
| Blank page | `.env` পাথ ভুল বা `runtime/` writable নয়; `APP_DEBUG=true` দিয়ে একবার চেক করুন |
| DB connection error | `DB_DSN`-এ charset=utf8mb4 আছে কিনা, ইউজারের DB-তে privilege আছে কিনা দেখুন |
| অ্যাসেট 404 | `public/assets/*` আপলোড হয়েছে কিনা দেখুন; CDN/থিম ক্যাশ পরিষ্কার করুন |
| CSRF 419/422 | ডোমেইন HTTPS হলে কুকি `Secure` — সিস্টেম ঘড়ি ঠিক আছে কিনা দেখুন; সেশন পাথ writable কিনা চেক করুন |

## ৮. আপডেট ডিপ্লয়

1. নতুন কোড আপলোড (`vendor/`, `public/assets/` সহ)
2. `php yii migrate:up --no-interaction`
3. `rm -rf runtime/cache`
4. অপচ্ছন্ন করুন: `runtime/logs`, পুরনো `runtime/diag-*.php`
