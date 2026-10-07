# Deployment Guide (Shared Hosting / Apache)

Alif Tools একটি PHP 8.2+ / Yii 3 অ্যাপ্লিকেশন, যা cPanel-স্টাইল শেয়ার্ড হোস্টিং-এ ডিপ্লয় করা যায়।

## ১. রিকোয়্যারমেন্ট

- PHP **8.2+** (ext: pdo_mysql, mbstring, openssl, filter)
- PHP **ext-curl** — FCM/Telegram/Web Push পুশ পাঠানোর জন্য (cPanel-এ *Select PHP Version* → Extensions)। না থাকলে বা `disable_functions`-এ বন্ধ থাকলে সাইট স্বাভাবিক চলতে থাকে, শুধু পুশ চ্যানেল নিজে থেকে dead-letter হয়ে কারণটি কিউতে লিখে দেয় (নিচে §৮)।
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

> **বিকল্প:** `.env`-এ `APP_HOST_PATH=/aliftools/public` সেট করলে এরর-পেজের ট্রেস-লিংক সেই পাথে রিরাইট হয় (`config/common/di/error-handler.php`)। এটি **সাব-ডিরেক্টরি ইনস্টল সাপোর্ট করে না** — রুট, রুট ও অ্যাসেট URL সব ডোমেইন-রুটেই ধরে বানানো, তাই `example.com/tools/`-এ চালাতে চাইলে ডোমেইনের ডকরুট `public/`-এ পয়েন্ট করানোই একমাত্র সমর্থিত পথ (উপরের কেস A/B)।

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
| `app:super-admin` | কোনো পরিবর্তন লেখে না ("Already an active super-admin") |

অপশন:

```bash
bash scripts/setup.sh --force       # .env টেমপ্লেট দিয়ে চুরিয়ে নতুন করে (সাবধান!)
bash scripts/setup.sh --skip-db     # ডাটাবেস cPanel-এ আগেই বানানো থাকলে
bash scripts/setup.sh --skip-seed   # নমুনা ডেটা/অ্যাডমিন চাই না
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
php yii app:seed -n                   # ঐচ্ছিক — নমুনা ক্যাটালগ ও ইউজার (admin/Admin1234!)
rm -rf runtime/cache                  # কনফিগ ক্যাশ রিফ্রেশ
```

- `migrate:up` আইডেম্পোটেন্ট — একই কমান্ড আবার চালালে বলে *Nothing to migrate*; ভয় নেই।
- `app:seed` শুধু **খালি/নতুন** ক্যাটালগে রো যোগ করে; আগে থেকে থাকা সার্ভিস/ইউজার স্কিপ হয় (ট্রানজেকশন হিস্ট্রি অক্ষত থাকে)। সিড করা অ্যাডমিনের পাসওয়ার্ড প্রথম লগইনের পরপরই বদলে ফেলুন।
- `app:seed` শুধু **admin** রোলের একটা অ্যাকাউন্ট দেয়, **সুপারএডমিন নয়**। `/admin/staff`, `/admin/withdraws` ও প্ল্যাটফর্ম-ওয়াইড লেজার শুধু `superadmin` রোল ধরে খোলে, আর ওই পেজ থেকেই প্রথম সুপারএডমিন নিয়োগ করা যায় — তাই ডিপ্লয়ের পর একবার চালাতেই হবে:

  ```bash
  php yii app:super-admin --promote=admin    # সিড করা admin-কেই সুপারএডমিন বানান (পাসওয়ার্ড অপরিবর্তিত)
  php yii app:super-admin                    # অথবা আলাদা `superadmin` অ্যাকাউন্ট + জেনারেটেড পাসওয়ার্ড
  ```

  দুটোই আইডেম্পোটেন্ট — দুবার চালালে দ্বিতীয়বার কিছু লেখে না। পরিবর্তন `activity_log`-এ `admin.superadmin_promoted` হিসেবে জমা হয়।

SSH না থাকলে: লোকালে উপরের কমান্ডগুলো চালিয়ে পুরো ফোল্ডার আপলোড করুন; `runtime/cache/` খালি করে আপলোড করুন।

### ৪.৩ যাচাই

```bash
php yii migrate:up --no-interaction   # "Nothing to migrate" দেখালে স্কিমা সম্পূর্ণ
```

ব্রাউজারে `https://your-domain.com/` খুলুন — হোমপেজ ও লগইন কাজ করলে ডাটাবেস কানেকশন ঠিক আছে। সিড করা ইউজার দিয়ে লগইন করে একটি সার্ভিস অর্ডার পেজ (যেমন Official Server Copy) খুললে ভ্যারিয়েন্ট সিলেক্টর ও মূল্য দেখা যাবে।

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
   - `Dead` বাড়ছে → চ্যানেল কনফিগ ভাঙা; ডেড-রো-রে বার্তা পড়ে দেখুন, যেমন *"Unknown channel …"* মানে ওই ইউজারের ডিভাইস টোকেন অবৈধ, আর *"curl … is disabled or missing"* মানে হোস্টে ext-curl বন্ধ (§৮)।
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
| CSRF 419/422 | ডোমেইন HTTPS হলে কুকি `Secure` — সিস্টেম ঘড়ি ঠিক আছে কি না দেখুন; সেশন পাথ writable কি না চেক করুন |
| পুশ/FCM/Telegram কিছুই পাঠানো হচ্ছে না | `php yii app:fcm:check` (বা `app:webpush:check`) চালান। "curl … is disabled or missing" দিলে হোস্টে ext-curl বন্ধ — সেটি ছাড়াও পুরো সাইট চলে, শুধু পুশ কাজ করে না; `/admin/notifications`-এ সেই কারণ নিজেই dead-letter মেসেজে লেখা থাকে |
| `/.well-known/assetlinks.json` 403 | `public/.htaccess`-এর `.well-known` ব্যতিক্রমটি আছে কি না দেখুন — ডটফাইল ব্লক ছাড়া রুটটি Apache-ই 403 দিয়ে দেয়, অ্যাপ কখনো দেখতেই পায় না। ব্যতিক্রম থাকলে 403 আসছে অ্যাপ থেকে, অর্থাৎ `TWA_FINGERPRINTS` সেট করা নেই |

## ৯. আপডেট ডিপ্লয়

1. নতুন কোড আপলোড (`vendor/`, `public/assets/` সহ)
2. `php yii migrate:up --no-interaction`
3. `rm -rf runtime/cache`
4. অপচ্ছন্ন করুন: `runtime/logs`, পুরনো `runtime/diag-*.php`

## ১০. Cloudflare Tunnel দিয়ে ডিপ্লয় (allseba.online)

> **এই সেকশনটি এখন legacy।** প্রকাশ্য সাইট `allseba.online` cPanel sharedhosting-এ
> SSH দিয়ে আসে (ধাপ ১১) — vhost cPanel-ই সামলায়, তাই Cloudflare tunnel বা লোকাল
> XAMPP-এর কোনো দরকার নেই। নিচের নির্দেশনা শুধু তখনই মানে, যখন আপনি **ইচ্ছাকৃতভাবে**
> লোকাল মেশিন থেকেই টানেল চালাতে চান। `scripts/add-sheba-vhost.php`-এর কাজও সেই একই
> পরিস্থিতির জন্য; cPanel ডিপ্লয়ে এটি চালাবেন না।

শেয়ার্ড হোস্টিং না করে লোকাল মেশিন (XAMPP) থেকেই অ্যাপটি সরাসরি `https://allseba.online`-এ
প্রকাশ করা যায়। Cloudflare Tunnel মানে কোনো পাবলিক IP বা পোর্ট ফোরওয়ার্ড লাগে না —
cloudflared লোকাল Apache-এর দিকে একটি encrypted connection ধরে রাখে, আর Cloudflare-এর
edge থেকে ট্রাফিক সেই connection-এর ভেতর দিয়ে আসে। TLS সার্টিফিকেট ও DNS দুটোই
Cloudflare সামলায়, তাই Let's Encrypt বা কোনো সার্টিফিকেট ফাইলও লাগে না।

### ১০.১ আর্কিটেকচার

```
ইন্টারনেট → Cloudflare Edge (TLS শেষ করে, HTTPS চালু) → tunnel (QUIC)
           → cloudflared (লোকাল) → http://127.0.0.1:8080 → Apache (vhost)
           → DocumentRoot public/ → index.php → Yii 3
```

### ১০.২ একবারের সেটআপ

```bash
# ১. টানেল তৈরি (ড্যাশবোর্ডে Tunnels থেকেও হয়; লগইন লাগবে)
cloudflared tunnel create th-tools-onlinesheba

# ২. ডোমেইন → টানেল (ক্লাউডফ্লেয়ারের জোনে CNAME, proxied)
cloudflared tunnel route dns th-tools-onlinesheba allseba.online
cloudflared tunnel route dns th-tools-onlinesheba www.allseba.online

# ৩. লোকাল কনফিগ: ~/.cloudflared/config.yml
#    ingress-এ হোস্টনেম → লোকাল সার্ভিস, শেষে অবশ্যই http_status:404
# ৪. টানেল চালু
cloudflared tunnel run th-tools-onlinesheba
```

`config.yml`:

```yaml
tunnel: df90132c-89ef-45d9-a680-eb59a954c476
credentials-file: C:\Users\Alif\.cloudflared\df90132c-89ef-45d9-a680-eb59a954c476.json

ingress:
  - hostname: allseba.online
    service: http://127.0.0.1:8080
  - hostname: www.allseba.online
    service: http://127.0.0.1:8080
  - service: http_status:404
```

ভুল কনফিগ বুঝতে:

```bash
cloudflared tunnel ingress validate
cloudflared tunnel ingress rule https://allseba.online
```

**ingress নিয়ম:** প্রতিটি হোস্টনেমের জন্য একটা নিয়ম, আর একদম শেষে ক্যাচ-অল
`- service: http_status:404`। শেষেরটা না দিলে অজানা হোস্টনেমও লোকাল সার্ভিসে চলে যায়।

### ১০.৩ Apache vhost

টানেলের সামনে Apache লাগে, একটা আলাদা পোর্টে (এখানে `8080`) শুনতে হবে:

```apache
<VirtualHost *:8080>
    ServerName allseba.online
    ServerAlias www.allseba.online 127.0.0.1 localhost
    DocumentRoot "D:/xampp-server/digital-sheba/public"

    <Directory "D:/xampp-server/digital-sheba/public">
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "logs/digital-sheba-error.log"
    CustomLog "logs/digital-sheba-access.log" common
</VirtualHost>
```

সেটআপ স্ক্রিপ্টটি একই কাজটি করে (idempotent, পুনরায় চালানো যায়):

```bash
php scripts/add-sheba-vhost.php
```

উপরের `ServerAlias`-এ ইচ্ছাকৃতভাবে পুরনো ডোমেইন `onlinesheba.broxlab.online`
নেই — নতুন মেশিনে সেটা দরকারও হবে না। বিদ্যমান কনফিগে ওই alias এখনো আছে, যাতে
আগে থেকে বানানো APK ইনস্টলগুলো ভাঙে না। নতুন APK সবাই `allseba.online`-এ চলে গেলে
alias আর tunnel ingress — দুটোই সরিয়ে দিন।

### ১০.৪ ⚠️ X-Forwarded-Proto মুছবেন না

এটাই এই সেটআপে সবচেয়ে আস্ত ফাঁদ। cloudflared লোকাল Apache-এ `X-Forwarded-Proto: https`
পাঠায়; অ্যাপের `App\Web\ForwardedProtoMiddleware` সেই হেডার থেকেই HTTPS বুঝে
সেশন কুকিতে `Secure` বসায়। vhost-এ যদি লেখা থাকে

```apache
RequestHeader unset X-Forwarded-Proto
```

তাহলে অ্যাপ HTTP ভাবেই নিজেকে চিনবে → লগইন কুকি `Secure` ছাড়া যাবে →
ব্রাউজার HTTPS সাইটে সেটা ফেরত পাঠাবে → প্রতিবার লগইন হারানো।

অ্যাপ শুধু লুপব্যাক ঠিকানা (`127.0.0.1`, `::1`) থেকে আসা হেডার বিশ্বাস করে, আর cloudflared
ঠিক লুপব্যাক থেকেই ডায়াল করে — তাই হেডারটা আসল সত্যিই।

চেক:

```bash
curl -sI https://allseba.online/ | grep -i '^set-cookie'
# ALIF_SESSION=...; Domain=allseba.online; Path=/; Secure; HttpOnly; SameSite=Lax
```

`Secure` না থাকলে vhost আবার দেখুন।

### ১০.৫ `.env`

```dotenv
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://allseba.online
TWA_ORIGIN=https://allseba.online
TWA_FINGERPRINTS=
```

`APP_URL` এখন পুরো অ্যাপে পাবলিক URL হিসেবে বসে — canonical/og:url, sitemap,
রেফারাল লিংক, push deep link। ডোমেইন বদলালে এটা না বদলালে ইমেইল/শেয়ার লিংকে
পুরনো ডোমেইন চলে যাবে। `TWA_ORIGIN` আর Android/TWA কনফিগ একসাথে বদলাতে হয়।

### ১০.৬ চালু থাকা ও যাচাই

```bash
# টানেল সত্যিই আপস্ট্রিমে আছে কি না
cloudflared tunnel info th-tools-onlinesheba
# সব পেজ ঠিক ডোমেইন দিয়ে হিট করছে কি না
curl -sI https://allseba.online/ | head -1
curl -sI https://allseba.online/app | head -1
```

`httpd -f .../conf/httpd.conf -t` দিয়ে vhost সিনট্যাক্স, `cloudflared tunnel ingress validate`
দিয়ে ingress যাচাই করুন — দুটোই আগে চালালে ভুল কনফিগে সার্ভার না চালু করাই ভালো।

### ১০.৭ টানেল বুটে গেলে

cloudflared কোনো Windows সার্ভিস হিসেবে ইনস্টল না করলে সেশন শেষ হলেই টানেল বন্ধ হয়ে যায়
(ডোমেইন তখন 1033 / connection refused দেবে)। স্থায়ী করতে সার্ভিস হিসেবে ইনস্টল করুন:

```powershell
cloudflared service install <TOKEN>
# বা সার্ভিস ছাড়াই রিবুটে বেঁচে থাকতে Task Scheduler-এ "At startup" ট্রিগার দিন
```

Apache-ও একইভাবে সার্ভিসে থাকা দরকার — নইলে টানেল health দেখাবে, কিন্তু আসল পেজ 502 দেবে।

### ১০.৮ TWA / assetlinks

`/.well-known/assetlinks.json` যতক্ষণ `TWA_FINGERPRINTS` খালি, ততক্ষণ 403 দেয় (ইচ্ছাকৃত)।
`public/.htaccess` এই রুটটিকে ডটফাইল ব্লক থেকে বাদ দেয়, তাই Apache-তেও অনুরোধটি অ্যাপের
কাছেই পৌঁছায় (ডটফাইল নিয়মটি সরাসরি `/.well-known/...`-কে 403 দিলে ফিঙ্গারপ্রিন্ট সেট
করেও ভেরিফিকেশন কখনো সফল হত না)। তবে 403 মানে এখনও সেট করা হয়নি — TWA ইনস্টল করলে
অ্যাড্রেস বার দেখাবে, এটা ডোমেইন সমস্যা নয়, সাইনিং সার্টিফিকেটের
ফিঙ্গারপ্রিন্ট না থাকার কারণে। অ্যাপের সাইনিং সার্টিফিকেট থেকে ফিঙ্গারপ্রিন্ট বের করে `.env`-এ বসান:

```bash
php yii app:twa:fingerprints --cert=path/to/release.cer --package=online.broxlab.aliftools.twa
```

কমান্ডটি `TWA_ORIGIN` ও `TWA_FINGERPRINTS`-এর জন্য ঠিক-ঠিক লাইন ছাপে (`.env`-এ কপি করে
ফাইলটা রিস্টার্ট দিন)। এরপর `https://allseba.online/.well-known/assetlinks.json`
২০০ দিতে হবে, `twa/twa-manifest.json`-এর `host`/`startUrl` ও
`android/app/build.gradle.kts`-এর `API_BASE_URL` একই ডোমেইনে থাকতে হবে — না হলে
TWA অ্যাপ ডোমেইন ভালিডেশন ব্যর্থ করবে।

### ১০.৯ বেটা প্রিভিউ: beta.allseba.online

প্রোডাকশন `allseba.online` cPanel-এ থাকলেও লোকাল অ্যাপটির একটা প্রিভিউ কপি
`beta.allseba.online`-এ আলাদা একটা টানেলে প্রকাশ করা যায় — প্রোডাকশন DNS বা vhost
একেবারে না ছুঁয়েই।

বিন্যাস:

```
beta.allseba.online → Cloudflare edge → টানেল allseba-beta (QUIC)
                     → cloudflared (লোকাল) → http://127.0.0.1:8080
                     → Apache (vhost, §১০.৩) → DocumentRoot public/ → Yii 3
```

> **আপডেট (২০২৬-১০):** বিটা টানেলের সামনে এখন আলাদা `php -S 127.0.0.1:8099` নেই।
> `~/.cloudflared/beta.yml`-এর ingress সরাসরি লোকাল Apache-র
> `http://127.0.0.1:8080`-এ বসানো (§১০.৩), যেটাই `config.yml`-এ `allseba.online`-এর
> জন্যও আছে — তাই বিটা এখন একই ব্যাকএন্ডের লোকাল ওয়ার্কিং-কপি দেখায়। প্রকাশ্য
> সাইট cPanel-এ থাকায় বিটা মানে সার্ভারের কপি নয়, এই মেশিনের কপি।

**আলাদা টানেল কেন:** ইনস্টল করা `cloudflared` সার্ভিসটি চলে
`tunnel run --token-file C:\ProgramData\cloudflared\token` — সেটা remotely managed,
তাই `~/.cloudflared/config.yml` পড়ে না। প্রোডাকশনের টানেল ও কনফিগ অপ্রভাবিত রাখতে বেটার
জন্য নিজস্ব টানেল (`allseba-beta`), নিজস্ব কনফিগ (`~/.cloudflared/beta.yml`) ও নিজস্ব
Windows সার্ভিস (`cloudflared-beta`)।

```bash
cloudflared tunnel create allseba-beta
cloudflared tunnel route dns allseba-beta beta.allseba.online
cloudflared tunnel --config ~/.cloudflared/beta.yml run
```

> **কনফিগ বদলালে সার্ভিস রিস্টার্ট লাগে:** `cloudflared` ingress কনফিগ হট-রিলোড করে না।
> `beta.yml` এডিট করে অ্যাডমিন কমান্ড প্রম্পটে `Restart-Service cloudflared-beta`
> চালান — না হলে টানেল পুরনো রুলই চালিয়ে যায়।

### ১০.৯.১ সার্চ ইনডেক্স থেকে বাইরে রাখা

বেটা কপির `APP_URL` নিজের দিকে থাকে (sitemap, canonical, deep link ঠিক থাকে), কিন্তু
তাহলে কোন হোস্টটি আসল সাইট তা বোঝা যায় না। তাই আলাদা করে `CANONICAL_URL` — যে হোস্ট
ইনডেক্স হবে:

```dotenv
APP_URL=https://beta.allseba.online
CANONICAL_URL=https://allseba.online
```

`App\Web\NoIndexMiddleware` অনুরোধের হোস্ট `CANONICAL_URL`-এর সাথে না মিললে প্রতিটি
রেসপন্সে `X-Robots-Tag: noindex, nofollow` বসায় (HTML, JSON, sitemap, এরর — সব)। প্রোডাকশনে
হোস্ট মিলে যায়, তাই কোনো হেডার যোগ হয় না — আগের আচরণ হুবহু একই।

হেডার ব্যবহার করা হয়েছে, `<meta>` নয়, কারণ একটা টেমপ্লেট `robots` ব্লক ভুলে দিলেও
ক্রলার হেডারটা পায়।

**`robots.txt` এখন রুট** (`App\Web\Site\RobotsAction`) — `Sitemap:` লাইনটা `APP_URL`
থেকে তৈরি হয়, তাই বেটায় `https://beta.allseba.online/sitemap.xml` দেখায়। স্ট্যাটিক
`public/robots.txt` ফাইলটা মুছে দেওয়া হয়েছে; না মুচলে Apache-এর "existing files সরাসরি
সার্ভ" নিয়ম ও ডেভ সার্ভারের `is_file()` চেক রুটের আগেই static ফাইলটাই ফেরত দিত — সেটাই
একমাত্র কারণ `Disallow: /` এখানে বসানো হয়নি (ভুল `CANONICAL_URL` হলে সেটা প্রোডাকশনকেই
ইনডেক্স থেকে ফেলে দিত)।

যাচাই:

```bash
curl -4 -sI https://beta.allseba.online/ | grep -i x-robots-tag     # header আসে
curl -4 -s https://beta.allseba.online/robots.txt | tail -1         # নিজের sitemap
curl -4 -sI http://127.0.0.1:8080/ | head -1                       # আপস্ট্রিম Apache 200 দিচ্ছে
```

> `curl -4` না দিলে এই মেশিনে কাজ করে না: Cloudflare AAAA রেকর্ড ফেরত দেয়, আর লোকাল
> IPv6 কার্যকর নয়, তাই সংযোগই হয় না (অ্যাপের সমস্যা নয়)।

### ১০.৯.২ লগনের পরেই চালু থাকা

টানেল নিজে থেকে চালু হওয়ার দুইভাব আছে — যেটা এখন ইনস্টল করা সেটা প্রথমে।

**১. Startup ফোল্ডার (ইনস্টল করা আছে, অ্যাডমিন লাগে না)** — সাধারণ কমান্ড প্রম্পটে:

```bat
scripts\install-beta-tunnel-startup.bat
```

এটি `%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\cloudflared-beta.vbs`
ফাইলটি লেখে, যা প্রতিবার লগনে টানেলটি উইন্ডো ছাড়াই চালু করে। VBS ব্যবহারের কারণ:
`cloudflared` একটি কনসোল প্রোগ্রাম, তাই Startup-এ সরাসরি `.bat` বা `.lnk` রাখলে প্রতিবার
লগনে একটা কনসোল উইন্ডো ফ্ল্যাশ করে; `WScript.Shell.Run`-এর window style `0` ঠিক সেটাই
ঠেকায়। বারবার চালালে আগের ফাইলটির উপরে লেখে হয়, তাই নিরাপদে রিপ্লে করা যায়।

বন্ধ করতে:

```bat
del "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\cloudflared-beta.vbs"
```

**২. Windows সার্ভিস (অ্যাডমিন লাগে, লগআউটের পরেও টিকে থাকে)** — Administrator কমান্ড প্রম্পটে:

```bat
scripts\install-beta-tunnel-service.bat
```

স্ক্রিপ্টটি `cloudflared-beta` সার্ভিস বানায় (auto start, মরলে ১ মিনিট পরপর রিস্টার্ট),
বারবার চালালে নিরাপদে আগেরটা মুছে নতুন করে বানায়। এটি `scripts/setup.sh`-এর মতোই
idempotent; টানেল বা DNS রেকর্ড স্পর্শ করে না, আর প্রোডাকশনের `cloudflared` সার্ভিসকে
ছোঁয় না।

দুটো একসঙ্গে চালাবেন না — তাহলে একই টানেল দুইবার কানেক্ট করবে।

লক্ষ্য রাখুন: টানেলের সামনে এখন আলাদা কোনো ছোট সার্ভার নয়, §১০.৩-এর Apache (8080)ই
দরকার — সেটা বন্ধ থাকলে টানেল 502 দেবে (§১০.৭ দেখুন)।
## ১১. GitHub Actions দিয়ে অটো ডিপ্লয় (cPanel)

`.github/workflows/deploy-cpanel.yml` — `main`-এ পুশ করলেই ধাপে ধাপে সাইট আপডেট হবে:

```
checkout → composer install → npm run build → php yii list (স্মোক টেস্ট)
        → migrate:new (ডেটাবেজ প্রিফ্লাইট) → maintenance.lock তোলা
        → ফাইল ট্রান্সফার (rsync, না থাকলে tar) → rm runtime/cache → migrate:up
        → lock নামানো → curl হেলথ চেক
```

বিল্ড **রানারে** হয়, সার্ভারে নয় — কারণ cPanel-এ composer/Node নাও থাকতে পারে, আর
`vendor/` ও `public/assets/` দুটোই gitignore করা, তাই এগুলো লোকালে তৈরি হওয়ার কথা নয়।

### ১১.১ একবারের cPanel প্রস্তুতি

1. **SSH চালু করুন:** cPanel → *Security* → *SSH Access* → *Enable SSH* (অনেক হোস্টে টার্মিনাল UI আলাদা)।

   > **rsync লাগে না।** হোস্টে rsync থাকলে সেটাই দিয়ে ট্রান্সফার হয় (আংশিক, দ্রুত);
   > না থাকলে workflow নিজে থেকেই tar দিয়ে পুরো রিলেজ পাঠাবে — যা প্রতিটি বেস ইমেজে আছে।
2. **SSH কী যোগ করুন:** একটি নতুন keypair বানান (শুধু পাবলিক অংশ cPanel-এ দিন):
   ```bash
   # পাসফ্রেজ দেবেন না — নিচে দেখুন কেন
   ssh-keygen -t ed25519 -N "" -C "github-actions-deploy" -f ~/.ssh/cpanel_deploy
   ```
   *Manage SSH Keys* → *Import Key* → `~/.ssh/cpanel_deploy.pub`-এর **কনটেন্ট** পেস্ট করুন
   (ফাইলটা আপলোড করবেন না, ভেতরের লাইনটাই দিতে হবে)।

   প্রাইভেট অংশটি GitHub-এ **base64 করে** দিন (`CPANEL_SSH_KEY_B64`) — কারণ secret
   একটি লেখার খান, আর পেস্ট করা কী-তে Windows-এর `\r`, উদ্ধতিচিহ্ন বা শেষের নিউলাইন
   ঢুকে গেলে OpenSSH কীটা পড়তেই পারে না (`error in libcrypto`)।
   ```bash
   base64 -w0 ~/.ssh/cpanel_deploy > cpanel_deploy.b64   # উইন্ডোজে: -w0 ছাড়া
   ```
   > **পাসফ্রেজ যাবে না।** Workflow-এ কী পড়তে পাসফ্রেজ চাইতে পারে না (ইন্টার‌্যাক্টিভ
   > প্রম্পট হলে job-টাই ঝুলে থাকবে), আর cPanel-এর keypair-ও পাসফ্রেজ রাখতে দেয় না।
3. **ডিরেক্টরি ও symlink** (ম্যানুয়াল, একবারই):
   ```bash
   mkdir -p /home/<cpanel-user>/alif_tools
   ln -s /home/<cpanel-user>/alif_tools/public /home/<cpanel-user>/public_html
   ```
   > উপরের নামে `alit_tools` লেখা হলে সেটাই ব্যবহার হবে — যে নামেই ডিরেক্টরি বানাবেন,
   > `CPANEL_PATH`-এ হুবহু সেটাই দিতে হবে (কেস-সেনসিটিভ)।
4. **`.env`** প্রথমবার হাতে দিন (বা নিচের `CPANEL_ENV_B64` সিক্রেট দিন):
   ```bash
   cd /home/<cpanel-user>/alif_tools
   nano .env      # .env.example থেকে কপি করে DB_DSN/DB_USERNAME/DB_PASSWORD/APP_KEY বসান
   chmod 600 .env
   ```
5. **ডাটাবেস ও মাইগ্রেশন** প্রথমবার: §৪.১-এর নিয়মে ডাটাবেস বানান, তারপর একবার
   `php yii migrate:up --no-interaction` (এরপর থেকে মাইগ্রেশন workflow করে)।

### ১১.২ Repository secrets ও variables

*Settings → Secrets and variables → Actions*:

**Secrets (আবশ্যক):**

| নাম | মান |
|---|---|
| `CPANEL_HOST` | cPanel-এর *Connect via SSH*-এ দেখানো হোস্ট, যেমন `srv123.cpanel.net` (IP বদলায়, হোস্টনেম বদলায় না) |
| `CPANEL_USER` | cPanel অ্যাকাউন্ট নাম (যেটা দিয়ে লগইন করেন) |
| `CPANEL_SSH_KEY_B64` | **এটাই ব্যবহার করুন** — `base64 -w0`-কৃত প্রাইভেট কী (উপরে দেখুন) |
| `CPANEL_SSH_KEY` | বিকল্প — প্রাইভেট কীর কাঁটা লেখা, কিন্তু `\r`/কোটো ঢুকে গেলে নষ্ট হয় |

**Secrets (ঐচ্ছিক):**

| নাম | কাজ |
|---|---|
| `CPANEL_ENV_B64` | `base64 -w0 .env` — সার্ভারে `.env` না থাকলে **শুধু তখনই** বসবে; পরের ডিপ্লয়ে ছুঁবে না |

**Variables:**

| নাম | মান |
|---|---|
| `CPANEL_PATH` | **আবশ্যক** — অ্যাপের রুট পাথ, যেমন `/home/<cpanel-user>/alif_tools`। ডিফল্ট নেই: এই রিপোজিটরি public, তাই cPanel-এর ইউজারনেম ফাইলে বেঁচে থাকা ঠিক নয় |
| `DEPLOY_HEALTH_URL` | ডিফল্ট `https://allseba.online` |
| `CPANEL_SSH_PORT` | ডিফল্ট `22` (অনেক হোস্টে `2222`) |
| `CPANEL_PHP_BIN` | খালি রাখলে হোস্টে `/opt/cpanel/ea-php82/...`, `/usr/local/bin/php`, `/usr/bin/php` ক্রমে খোঁজে |

### ১১.৩ যা ডিপ্লয় করে, যা করে না

> রিলেজে `runtime/` exclude-এ থাকে, তাই সার্ভারের লগ, ক্যাশ ও
> `maintenance.lock` কখনো মুছে ফেলা হয় না — বিস্তারিত §১২-তে।

- **`vendor/` ও `public/assets/` যায়** — gitignore করা, কিন্তু বিল্ড-এর আউটপুট, তাই দরকার।
- **`.env`, `runtime/`, `storage/`, `cache/`, `web/receipts|deliverables|releases/` যায় না** —
  এগুলো git-এ নেই, আর rsync-এর `--delete`-এ exclude করা পথ মুছে ফেলে **না**। ইউজারের আপলোড করা ফাইল, লগ ও সিক্রেট বেঁচে থাকে।
- **পুরোনো ফাইল মোছা হয়** — rsync মোডে `--delete`; tar মোডে প্রতিটি রিলেজের সাথে
  `.deploy-manifest` যায়, আর পরের ডিপ্লয়ে আগের ম্যানিফেস্টে থাকা কিন্তু এই রিলেজে নেই
  এমন ফাইলগুলো মুছে ফেলা হয় (`.env`/আপলোড/লগ কখনো নয়)।
- **মাইগ্রেশন প্রতিটি ডিপ্লয়ে** চলে (`migrate:up` ইডেমপোটেন্ট — নতুন নেই বলেই শুধু ছাড়ে)।
- **Android/TWA/docs-only কমিট ডিপ্লয় ট্রিগার করে না** (`paths-ignore`)।

### ১১.৪ যাচাই ও ট্রাবলশুটিং

- Actions ট্যাবে লগ দেখুন — শেষে `https://…/ ও /login → 200` দুটোই দেখাতে হবে; না দেখালে ডিপ্লয় ব্যর্থ।
- `workflow_dispatch` দিয়ে হাতে চালাতে পারেন।
- **ফোল্ডার না পাল্টানো চাইলে** Actions-এ `environment: production`-এ manual approval বসান
  (Settings → Environments → production → *Required reviewers*)।
- সাইট খুলে খাঁচাই করে পুরোনো `vendor/` থেকে কোনো ফাইল বাকি থাকলে কী হয়:
  Actions-এর সব ধাপ সবুজ, কিন্তু পেজে কিছু একটা পুরোনো — সম্ভবত `runtime/cache/` লেগে আছে; SSH-তে `rm -rf runtime/cache/*` করুন।
- **`::error::Missing repository secrets`** মানে উপরের টেবিলের কোনো সিক্রেট সেট হয়নি।

| সমস্যা | সমাধান |
|---|---|
| `Permission denied (publickey)` | cPanel-এ পাবলিক কী ইমপোর্ট হয়েছে কি না দেখুন; সিক্রেটে **প্রাইভেট** কী দিতে হবে |
| `Load key …: error in libcrypto` | সিক্রেটের লেখাটি কী হিসেবে পড়া যাচ্ছে না — উপরে বলা `\r`/কোটো সমস্যা। `base64 -w0` করে `CPANEL_SSH_KEY_B64`-এ দিলে সব মিটে যায় |
| `Host key verification failed` | হোস্টের IP বদলে গেছে — সেক্রেটে বর্তমান `CPANEL_HOST` আছে কি না দেখুন |
| `::warning::rsync is not installed…falling back to a tar transfer` | এটি ব্যর্থতা নয় — workflow নিজে থেকেই tar-এ চলে। শুধু ট্রান্সফারটি একবারে (পুরো tarball) যায়, তাই একটু ধীর |
| `No PHP binary found on the host` | `CPANEL_PHP_BIN` ভ্যারিয়েবলে সঠিক পথ দিন (`ls -d /opt/cpanel/ea-php*/root/usr/bin/php`) |
| মাইগ্রেশন ব্যর্থ, সাইট 500 | `APP_DEBUG=true` করে ব্রাউজারে সরাসরি ত্রুটিটা দেখুন, তারপর আবার `false` |

## ১২. Maintenance mode (`runtime/maintenance.lock`)

rsync চলাকালীন সার্ভারের কোড ফোল্ডার সাময়িকভাবে অসম (সবচেয়ে ঝুঁকিপূর্ণ সময়: `vendor/`
লেখা হচ্ছে, `.env`-সংশ্লিষ্ট কনফিগ ক্যাশ পুরোনো)। তখন ভিজিটর ফ্যাটাল এরর পেতেন। এই
লক ফাইলটি থাকলে সাইট সেই বদলে একটা সুন্দর “আপডেট হচ্ছে” পেজ (HTTP 503) দেখায়।

### ১২.১ কীভাবে কাজ করে

```
main-এ পুশ → migrate:new (ডিটাবেজ চেক) → runtime/maintenance.lock তৈরি
           → ট্রান্সফার (rsync/tar) → migrate
           → lock মুছে যায় → / ও /login-এ 200 নিশ্চিত → গ্রিন
```

যেকোনো ধাপে deploy মরলে **lock থেকেই যায়** — ফলে অর্ধেক-হওয়া কোড ভিজিটরের সামনে
এসে ফ্যাটাল এরর দেখায় না। পরে ঠিক করে আবার deploy করলেই সাইট ফিরে আসে।

গেটটি `public/index.php`-এ **`src/bootstrap.php`-এর আগে** বসানো, কারণ ওখানেই
`vendor/autoload.php` লোড হয় — আর ভাঙা deploy-এ সেটাই প্রথমে নেই। ফ্রেমওয়ার্কের
ভেতরে gate বসালে ঠিক সেই মুহূর্তে 500 + স্ট্যাক-ট্রেসই দেখাত। পেজটি
[public/maintenance.php](public/maintenance.php)-এ, সম্পূর্ণ স্বাধীন — কোনো
autoload, `.env`, CSS বা ফন্ট নেই (মাঝাকালে `public/assets/` নিজেই আংশিক)।

লক ফাইলের **প্রথম লাইন** পেজে দেখানো হয়, তাই লিখতে হবে:

```bash
printf '%s\n' "Deploying $(git rev-parse --short HEAD)" > runtime/maintenance.lock
```

### ১২.২ যা বন্ধ থাকে না

- **স্ট্যাটিক অ্যাসেট** — `public/.htaccess` আসল ফাইল সরাসরি দেয়, তাই লোগো, CSS, ওয়েবম্যানিফেস্ট ডিপ্লয়ের সময়ও লোড হয় (ইচ্ছাকৃত — না হলে মানুষ হাফ-হাজার ছবি দেখবে)। ডেভ সার্ভারেও একই আচরণ: `public/index.php`-এর cli-server শাখাটি মেইনটেনেন্স গেটের **আগে** বসানো, তাই `php yii serve`-এও CSS/ফেভিকন আসল ফাইলই আসে, 503 নয়।
- **কনসোল কমান্ড** — গেট শুধু HTTP-তে, তাই ক্রন ও `php yii` চলতেই থাকে।

### ১২.৩ সাইট বন্ধ করে রাখতে হলে (হাতে)

```bash
cd /home/<cpanel-user>/alif_tools
printf '%s\n' "Scheduled maintenance — back shortly" > runtime/maintenance.lock
# শেষ করার সময়
rm -f runtime/maintenance.lock
```

> `.env`-এর মতোই, লক ফাইলটি gitignore করা (`/runtime`) ও workflow-এর exclude-এ
> থাকে — `--delete` কখনো এটিকে সরাবে না, আর ভুল করে commit-ও হবে না।

### ১২.৪ ব্যর্থ deploy থেকে সাইট বের করা

workflow লাল হলে লক থেকে যায় — এটাই ইচ্ছাকৃত। সাইট ফিরিয়ে আনতে **দুইভাবে**:

1. **আবার deploy** (সাধারণত এটাই ঠিক): Actions → *Deploy to cPanel* → *Run workflow*।
2. **শুধু লক তুলে ফেলা** — Actions → *Deploy to cPanel* → *Run workflow* →
   **clear_maintenance** টিক দিন। কোড বা ডেটাবেস না বদলে শুধু `runtime/maintenance.lock`
   মুছে দেয়, তারপর সাইট 200 দিচ্ছে কি না দেখায়।

> লক তুলে ফেলার পরও সাইট যদি না ওঠে, তাহলে আগের deploy-ই আংশিক — সেটি ঠিক না
> করে লক ফেরত দিয়ে দিন, নাহলে ভিজিটররা ফ্যাটাল এরর দেখবে।

### ১২.৫ সেটিংস থেকে মেইনটেন্যান্স (সুপারএডমিন)

উপরের লক ফাইল ডিপ্লয়ের জন্য। কোড না বদলায় শুধু সাইটটি সাময়িকভাবে বন্ধ রাখতে চাইলে
(রক্ষণাবেকারি, শিডিউল করা কাজ) লক ফাইল নয় — `/admin/settings` → **মেইনটেন্যান্স মোড**।
সেটিংটি `site_setting.maintenance_enabled`-এ থাকে, আর
`App\Web\MaintenanceMiddleware` (Router-এর ঠিক আগে বসা) সব পাবলিক পাথকে 503 দেয়।

- **কী পাঠানো হয়**: 503 + `Retry-After: 60` + `Cache-Control: no-store`, পেজে মালিকের
  লেখা `maintenance_message` বার্তা, আর `/api/*`-এ ঠিক সেই বার্তাই
  `{success, message, data, errors}` envelope-এ (HTML নয়) — তাই অ্যান্ড্রয়েড অ্যাপ
  বুঝতে পারে।
- **কী বন্ধ থাকে না**: `/login`, `/logout`, `/forgot-password`, `/reset-password` এবং
  `/admin` + `/api/admin/*`। এটি পাথ-ভিত্তিক তালিকা, রোল-চেক নয় — ওই পাথগুলো আগে
  থেকেই `AdminMiddleware` / `AdminApiMiddleware` দিয়ে রক্ষিত, তাই নতুন কোনো ক্ষমতা
  যোগ হয় না, আর সুইচটি **কখনো একমুখী হয় না** — প্যানেল থেকেও, অ্যাপ থেকেও যেকোনো
  ব্রাউজার থেকেও আবার খোলা যায়।
- **লক ফাইলের সাথে দ্বন্দ্ব নেই**: `runtime/maintenance.lock` থাকলে `public/index.php`
  ফ্রেমওয়ার্ক চালু হওয়ার আগেই সাইট বন্ধ করে দেয় — সেই ক্ষেত্রে সেটিংস-ভিত্তিক মোড
  চলারই সুযোগ থাকে না। দুটো একসাথে চালু রাখা যায়, কিন্তু কেউ একটা অন্যটাকে বন্ধ করে না।
- **ডেটাবেজ না চললে মোড খোলা থাকে** (fail open)। ডেটাবেজ ব্যর্থ হলে “সাইট বন্ধ”
  দেখানো মানে আসল সমস্যা লুকানো, আর মালিককে ঠিক ওই প্যানেল থেকেই বাঁচানো — যা একমাত্র
  জায়গা যেখান থেকে সেটিংস বদলানো যায়।
- কনসোল কমান্ড, ক্রন ও স্ট্যাটিক অ্যাসেট এই মোডেও অপরিবর্তিত থাকে: গেটটি শুধু HTTP
  রাউটারের আগে, অর্থাৎ ফ্রেমওয়ার্কের ভেতরে — `index.php`-এর লক-গেটের ঠিক বিপরীতে।
