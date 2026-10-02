# Deployment Guide (Shared Hosting / Apache)

Alif Tools একটি PHP 8.2+ / Yii 3 অ্যাপ্লিকেশন, যা cPanel-স্টাইল শেয়ার্ড হোস্টিং-এ ডিপ্লয় করা যায়।

## ১. রিকোয়্যারমেন্ট

- PHP **8.2+** (ext: pdo_mysql, mbstring, openssl, filter)
- MySQL 8 (বা 5.7+)
- Apache + mod_rewrite
- Composer (লোকালে বিল্ড করে আপলোড করলে হোস্টে Composer লাগে না)

## ২. স্ট্রাকচার

লক্ষ্য: ওয়েবরুট হবে `public/`। দুইটি সাধারণ কেস:

### কেস A — DocumentRoot `public/` এ পয়েন্ট করা যায় (সবচেয়ে ভালো)

```
/home/user/aliftools/        ← পুরো প্রজেক্ট
/home/user/public_html/    ← symlink → /home/user/aliftools/public
```

cPanel: *Domains → Document Root* পরিবর্তন করে `aliftools/public` সেট করুন, অথবা:

```bash
ln -s /home/user/aliftools/public /home/user/public_html
```

`public/.htaccess` ফাইলটি এই কেসেই কাজ করে।

### কেস B — DocumentRoot পরিবর্তন করা যায় না (public_html ওয়েবরুট)

দুইটি পরিবর্তন:

1. প্রজেক্ট আপলোড করুন ওয়েবরুটের *বাইরে*, যেমন `/home/user/aliftools/`।
2. `public_html/.htaccess` বানান:

```apache
RewriteEngine On
RewriteRule ^(.*)$ ../aliftools/public/$1 [L]
```

যদি হোস্ট উপরের `..` রিরাইট না দেয় (অনেক হোস্ট দেয় না), তাহলে `public_html/index.php` wrapper ব্যবহার করুন:

```php
<?php
// public_html/index.php — forwards into the app's public dir.
chdir(dirname(__DIR__) . '/aliftools/public');
require dirname(__DIR__) . '/aliftools/public/index.php';
```

এবং `public_html/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

> **বিকল্প:** `.env`-এ `APP_HOST_PATH=/aliftools/public` সেট করলে সাব-ডিরেক্টরি হোস্টিং-ও (যেমন `example.com/tools/`) সাপোর্ট করে — বিস্তারিত `src/Environment.php`।

## ৩. কনফিগারেশন

`public/` এর এক লেভেল উপরে `.env` ফাইল বানান (টেমপ্লেট হিসেবে `.env.example` দেখুন):

```dotenv
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://your-domain.com
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=alif_tools;charset=utf8mb4
DB_USERNAME=aliftools
DB_PASSWORD=strong-secret
CACHE_PATH=runtime/cache
SESSION_NAME=ALIF_SESSION
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

### ৪.০ এক কমান্ডে সেটআপ (নতুন সার্ভারের জন্য)

`scripts/setup.sh` চালালেই `.env` টেমপ্লেট কপি, `APP_KEY` জেনারেশন, ডাটাবেস তৈরি, মাইগ্রেশন ও সিড — সব একসাথে হয়ে যায়:

```bash
cd /home/user/alif_tools
bash scripts/setup.sh
```

এটি **পুনরাবৃত্তিগতভাবে নিরাপদ** (`re-run` করলে কিছুই নষ্ট হয় না):

| ধাপ | পুনরায় চালালে কী হয় |
|---|---|
| `.env` | আগেরটি রাখা হয় (টেমপ্লেট মুছে ফেলে না) |
| `APP_KEY` | ইতোমধ্যে থাকলে অপরিবর্তিত — টোকেন invalid হয় না |
| ডাটাবেস | `CREATE DATABASE IF NOT EXISTS` — নো-অপ |
| `migrate:up` | *No new migrations found* |
| `app:seed` | আগের থাকা সার্ভিস/ইউজার স্কিপ করে |

অপশন:

```bash
bash scripts/setup.sh --force       # .env টেমপ্লেট দিয়ে চুরিয়ে নতুন করে (সাবধান!)
bash scripts/setup.sh --skip-db     # ডাটাবেস cPanel-এ আগেই বানানো থাকলে
bash scripts/setup.sh --skip-seed   # ডেমো ডেটা/অ্যাডমিন চাই না
PHP_BIN=/opt/php82/bin/php bash scripts/setup.sh   # নির্দিষ্ট PHP বাইনারি
```

> **শেয়ার্ড হোস্টিং:** অনেক cPanel অ্যাকাউন্টে ডাটাবেস তৈরির `CREATE` প্রিভিলেজ নেই। সেক্ষেত্রে স্ক্রিপ্ট হলুদ সতর্কবার্তা দেবে, তারপর `--skip-db` দিয়ে আবার চালান — মাইগ্রেশন আগের মতোই চলবে।
>
> স্ক্রিপ্ট শেষে চারটি চেকলিস্ট আউটপুট করে (অ্যাডমিন পাসওয়ার্ড বদলান, `APP_DEBUG=false`, `runtime/` পারমিশন, ক্রন)।

### ৪.১ ডাটাবেস তৈরি (নতুন সার্ভার সেটআপ)

অ্যাপটি `alif_tools` নামের ডাটাবেস ব্যবহার করে (`.env.example`-এর ডিফল্ট)। `utf8mb4` বাধ্যতামূলক — বাংলা কনটেন্ট ও ইমোজির জন্য।

**SSH/টার্মিনাল থাকলে (MySQL 8):**

```sql
CREATE DATABASE alif_tools CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aliftools'@'localhost' IDENTIFIED BY 'strong-secret';
GRANT ALL PRIVILEGES ON alif_tools.* TO 'aliftools'@'localhost';
FLUSH PRIVILEGES;
```

এরপর `.env`-এ সেট করুন:

```dotenv
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=alif_tools;charset=utf8mb4
DB_USERNAME=aliftools
DB_PASSWORD=strong-secret
```

**cPanel-এ (SSH ছাড়া):** *MySQL® Databases* উইজার্ড থেকে তিন ধাপ — (১) ডাটাবেস তৈরি, (২) ইউজার তৈরি, (৩) *ALL PRIVILEGES* দিয়ে ইউজার–ডাটাবেস যুক্ত করা। অনেক হোস্ট ডাটাবেস/ইউজার নামের আগে অ্যাকাউন্ট-প্রিফিক্স জুড়ে দেয় (যেমন `user_alif_tools`) — তেমন হলে `.env`-এর `DB_DSN`-এ সেই **সম্পূর্ণ** নামটিই লিখুন।

> **লক্ষ্য:** ডাটাবেস খালি থাকতে হবে — টেবিল বানায় নিচের মাইগ্রেশন, হাতে `CREATE TABLE` করার দরকার নেই।

### ৪.২ মাইগ্রেশন ও সিড

এক কমান্ডে: `bash scripts/setup.sh` (উপরে §৪.০ দেখুন)।

হাতে ধরে করতে চাইলে সার্ভারে (SSH থাকলে):

```bash
php yii migrate:up --no-interaction   # সব কোর টেবিল + নোটিফিকেশন স্কিমা তৈরি করে
php yii app:seed -n                   # ঐচ্ছিক — ডেমো ক্যাটালগ ও ইউজার (admin/Admin1234!)
rm -rf runtime/cache                  # কনফিগ ক্যাশ রিফ্রেশ
```

- `migrate:up` আইডেম্পোটেন্ট — একই কমান্ড আবার চালালে বলে *Nothing to migrate*; ভয় নেই।
- `app:seed` শুধু **খালি/নতুন** ক্যাটালগে রো যোগ করে; আগে থেকে থাকা সার্ভিস/ইউজার স্কিপ হয় (ট্রানজেকশন হিস্ট্রি অক্ষত থাকে)। সিড করা ডেমো অ্যাডমিনের পাসওয়ার্ড প্রথম লগইনের পরপরই বদলে ফেলুন।

SSH না থাকলে: লোকালে উপরের কমান্ডগুলো চালিয়ে পুরো ফোল্ডার আপলোড করুন; `runtime/cache/` খালি করে আপলোড করুন।

### ৪.৩ যাচাই

```bash
php yii migrate:up --no-interaction   # "Nothing to migrate" দেখালে স্কিমা সম্পূর্ণ
```

ব্রাউজারে `https://your-domain.com/` খুলুন — হোমপেজ ও লগইন কাজ করলে ডাটাবেস কানেকশন ঠিক আছে। ডেমো ইউজার দিয়ে লগইন করে একটি সার্ভিস অর্ডার পেজ (যেমন Official Server Copy) খুললে ভ্যারিয়েন্ট সিলেক্টর ও মূল্য দেখা যাবে।

## ৫. নোটিফিকেশন ক্রন জব

অ্যাপটি ইন-অ্যাপ ও টেলিগ্রাম নোটিফিকেশন **আলাদা কমান্ড থেকে** পাঠায় — ওয়েব রিকোয়েস্ট থামা পড়লেও (ক্রন, SSH, ডিপ্লয়) বার্তা ডেলিভার হতে থাকে। এজন্যই `app:notification:work` ক্রনে দিতে হয়; ছাড়া দিলে কিউ তোলা থেকে যাবে এবং `/admin/notifications`-এ "Queued" সংখ্যা ক্রমশ বাড়তে থাকবে।

ক্রন ছাড়াও যে কাজ হয়: ট্রানজেকশন সফল/ব্যর্থ হলে সার্ভার থেকেই কিউতে এন্ট্রি পড়ে, কিন্তু **পাঠানোর কাজটা** করে শুধু ওয়ার্কার কমান্ড।

### ৫.১ দুটি জব লাগে

| কমান্ড | সময় | কাজ |
|---|---|---|
| `app:notification:work` | প্রতি মিনিট (`* * * * *`) | কিউ থেকে ব্যাচ claim করে FCM/Telegram-এ পাঠায় |
| `app:notification:purge` | প্রতিদিন ভোর ৩টায় (`0 3 * * *`) | পাঠানো/ডেড-লেটারের পুরোনো রো ও নিষ্ক্রিয় ডিভাইস মুছে ফেলে |

### ৫.২ cPanel-এ যোগ করার ধাপ

1. cPanel → **Cron Jobs** (কিছু হোস্টে *Advanced* → *Cron Jobs*)।
2. **Common Commands** থেকে **Once a minute** নির্বাচন করুন (শুধু *Command* বক্স + বারের নম্বর পাবেন)।
3. **Command** ঘরে ঠিকানা বসান:

```bash
cd /home/aliftools/alif_tools && /usr/local/bin/php yii app:notification:work --limit=200 >> runtime/logs/cron-notify.log 2>&1
```

4. বাঁ পাশ থেকে **Add New Cron Job** চাপুন।

> **`cd` বাদ দেবেন না** — ক্রনের working directory হোম ডিরেক্টরি থাকে, তাই ছাড়া দিলে `yii` খুঁজে পাবে না এবং আউটপুট `runtime/logs/`-এ ফাইল খুলতে ব্যর্থ হবে।

### ৫.৩ দৈনিক পার্জ জব

উপরের জব যোগ হওয়ার পর **Once a day** সিলেক্ট করে যোগ করুন:

```bash
cd /home/aliftools/alif_tools && /usr/local/bin/php yii app:notification:purge >> runtime/logs/cron-notify.log 2>&1
```

`app:notification:purge` কোনো আর্গুমেন্ট নেয়। এটি না চালালে `notification_queue` ও `device` টেবিল অসীম বড় হতে থাকবে — সেকেন্ড-পর-সেকেন্ড ইনডেক্স ধীর হয়ে যায়।

### ৫.৪ ফাইল-পারমিশন

```bash
chmod 750 runtime
mkdir -p runtime/logs && chmod 750 runtime/logs
```

লগ ফাইল না চাইলে `>> ... 2>&1` অংশটি বাদ দিন — তবে তখন ক্রন ফেইল হলে কারণ দেখার কোনো উপায় থাকে না, তাই রাখাই ভালো।

### ৫.৫ PATH ও PHP বাইনারি

*Common Commands*-এর PHP ভার্সন আপনার `.htaccess`-এর `PHP 8.2`-এর সঙ্গে না মিলতে পারে। ভিন্ন হলে পুরো পাথ বসান — cPanel সাধারণত নিচের পাথগুলো ব্যবহার করে:

```bash
/usr/local/bin/php       # বেশিরভাগ cPanel
/opt/cpanel/ea-php82/root/usr/bin/php
/usr/local/php82/bin/php
```

সঠিক পাথ বের করতে (SSH থাকলে) —

```bash
ls -d /opt/cpanel/ea-php*/root/usr/bin/php /usr/local/php*/bin/php 2>/dev/null
```

SSH না থাকলে একটি অস্থায়ী `cron-probe.php` ফাইল `public/`-এ ফেলে ব্রাউজারে খুলুন:

```php
<?php echo PHP_BINARY, "\n", PHP_VERSION;
```

যে পাথ দেখাবে, সেটিই ক্রনে বসান। **চেক করার পরে ফাইলটি মুছে ফেলুন**।

### ৫.৬ চালু হয়েছে কি না যাচাই

1. cPanel-এর *Last Run* কলামে প্রতি মিনিটে সময় বদলাতে থাকা উচিত।
2. `runtime/logs/cron-notify.log`-এর লাস্ট লাইনে দেখুন — খালি হলে `Queue empty.`, কাজ হলে `Processed 12: 12 sent, 0 scheduled for retry, 0 dead-lettered.`
3. **অ্যাডমিন প্যানেলে লগইন করে `/admin/notifications` খুলুন** — উপরের *Queue depth* কার্ড হলো যাচাইয়ের আসল জায়গা:
   - `Queued` সংখ্যা ক্রমশ বাড়ছে → ক্রন চলছে না (PATH/`cd` সমস্যা)।
   - `Dead` বাড়ছে → চ্যানেল কনফিগ ভাঙা; ডেড-রো-রে বার্তা পড়ে দেখুন, যেমন *"Unknown channel …"* মানে ওই ইউজারের ডিভাইস টোকেন অবৈধ।
   - `Queued` শূন্য থাকে → হেলথি।

একবার টেস্ট করতে চাইলে ক্রন বাদ দিয়ে হাতে চালান:

```bash
cd /home/aliftools/alif_tools && php yii app:notification:work --limit=5 -v
```

### ৫.৭ ট্রাবলশুটিং

| সমস্যা | সমাধান |
|---|---|
| ক্রনে `php: command not found` | পুরো পাথ বসান — §৫.৫ |
| `Could not open input file: yii` | `cd` ছাড়া দিয়েছেন; অ্যাবসলুট পাথে `cd /home/... && ...` দিন |
| `/usr/bin/env: php: No such file` | `.env`-এর `PHP_BIN`-এর বদলে OS-এর PATH ঠিক নেই; আগের লাইনে `export PATH=/opt/cpanel/ea-php82/root/usr/bin:$PATH` যোগ করুন |
| লগে `Database connection refused` | ক্রনের কাজকারী ডিরেক্টরিতে `.env` নেই — `cd` ঠিক আছে কি না দেখুন |
| ৫ মিনিট পরেও কোনো লাইন নেই | হোস্টে ক্রন ফ্রিকোয়েন্সি সীমিত (সাধারণত ১ মিনিট) — লগ ফাইল পারমিশন ও `runtime/logs/` আছে কি না দেখুন |
| `PHP 8.2` না পেয়ে `7.4` এরর | `.htaccess`-এর ভার্সনের সঙ্গে মিলিয়ে বাইনারি পাথ বেছে নিন |
| লগ ফাইল বাড়ছে কিন্তু `Sent` বাড়ছে না | ওয়ার্কার একটি রোতে আটকে যাচ্ছে না (এজন্ই এক টিকে একটি ব্যাচ ক্লেইম করে) — `Dead` ফিল্টারে কারণ দেখুন |

## ৬. অ্যাসেট

`npm run build` চালালে তৈরি হয়:

- `public/assets/css/app.css` (Tailwind minified)
- `public/assets/js/*` (app.js, dashboard.js, alpine.min.js)
- `public/assets/icons/lucide-sprite.svg`
- `public/assets/brand/` (লোগো SVG) + `public/favicon.*`, `apple-touch-icon.png`, `icon-*.png`, `site.webmanifest`

আপলোডে এগুলো অবশ্যই যাবে — PHP রানটাইমে জেনারেট হয় না। ব্র্যান্ড রাস্টার (ico/png) পুনঃজেনারেট করতে হলে: `php runtime/brand-gen/generate.php` (GD লাগে, `public/` ও `android/.../res/` দুটোতেই লিখে)।

## ৭. সেশন ও ক্যাশ

- সেশন ফাইল-ভিত্তিক, `SESSION_NAME` কুকি নাম ব্যবহার করে — শেয়ার্ড হোস্টিং-এ কোনো অতিরিক্ত সেটআপ লাগে না।
- ক্যাশ ফাইল-ভিত্তিক (`CACHE_PATH`)। ডিপ্লয়ের পর কনফিগ/রুট পরিবর্তন হলে `runtime/cache/` মুছে দিন (নতুন রুট/টেমপ্লেট 404 দিলেও এটিই প্রথম পদক্ষেপ — §৮ দেখুন)।

## ৮. ট্রাবলশুটিং

| সমস্যা | সমাধান |
|---|---|
| সব পেজে 404 | mod_rewrite চালু আছে কিনা, `public/.htaccess` আপলোড হয়েছে কিনা চেক করুন (`AllowOverride All` দরকার) |
| Blank page | `.env` পাথ ভুল বা `runtime/` writable নয়; `APP_DEBUG=true` দিয়ে একবার চেক করুন |
| DB connection error | `DB_DSN`-এ charset=utf8mb4 আছে কিনা, ইউজারের DB-তে privilege আছে কিনা দেখুন |
| Unknown database 'alif_tools' | §৪.১-এর ধাপে ডাটাবেস তৈরি হয়নি; প্রিফিক্সড নাম (যেমন `user_alif_tools`) হলে `DB_DSN`-এ সেটাই আছে কিনা দেখুন |
| মাইগ্রেশন ব্যর্থ / অর্ধেক টেবিল | `php yii migrate:up --no-interaction` আবার চালান — Yii শুধু বাকি মাইগ্রেশন চালায়; নোটিফিকেশন মাইগ্রেশনটি (M240109) আংশিক-ব্যর্থ রানও সামলায়। আটকালে `SELECT * FROM migration`-এ শেষ সফল এন্ট্রি দেখে সেটার পরেরটি আলাদা করে পরীক্ষা করুন |
| অ্যাসেট 404 | `public/assets/*` আপলোড হয়েছে কিনা দেখুন; CDN/থিম ক্যাশ পরিষ্কার করুন |
| CSRF 419/422 | ডোমেইন HTTPS হলে কুকি `Secure` — সিস্টেম ঘড়ি ঠিক আছে কিনা দেখুন; সেশন পাথ writable কিনা চেক করুন |

## ৯. আপডেট ডিপ্লয়

1. নতুন কোড আপলোড (`vendor/`, `public/assets/` সহ)
2. `php yii migrate:up --no-interaction`
3. `rm -rf runtime/cache`
4. অপচ্ছন্ন করুন: `runtime/logs`, পুরনো `runtime/diag-*.php`
