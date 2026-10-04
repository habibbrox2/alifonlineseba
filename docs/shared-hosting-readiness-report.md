# Shared Hosting রেডিনেস রিপোর্ট — কী করা হলো, কেন করা হলো

**তারিখ:** 2026-10-04
**প্রজেক্ট:** Alif Tools / All Seba (PHP 8.2 · Yii 3 · Twig · MySQL · Apache shared hosting)
**অনুরোধ:** "shared webhosting-এ রান করবে সেইভাবে সকল কোড আপডেট করো"
**স্কোপ নির্ধারণ:** ব্যবহারকারী নিশ্চিত করেছেন যে হোস্টে **SSH ও cron দুটোই আছে** এবং ডিপ্লয় হয় **GitHub Actions (rsync over SSH)** দিয়ে। তাই কাজটি হলো *অডিট + মেরামত*: কোডের প্রতিটি অংশ যেন shared host-এর বাস্তব সীমাবদ্ধতায় (Apache-র রিরাইট নিয়ম, php.ini-তে disabled function, এক-লাইনের ক্রন, কোড-প্যাটার্নের ভুল) ভাঙে না। নতুন ইনফ্রাস্ট্রাকচার (ওয়েব-মাইগ্রেশন, ফাইল-ম্যানেজার zip) তৈরি করা হয়নি — দরকার ছিল না।

---

## ১. অডিট পদ্ধতি

নিম্নলিখিতগুলো স্ক্যান ও পড়া হয়েছে:

| কী খোঁজা হলো | কেন |
|---|---|
| `public/.htaccess`-এর রিরাইট ক্রম | shared host-এ docroot `public/`, তাই .htaccess-ই একমাত্র Apache-স্তরের নিরাপত্তা ও রাউটিং |
| `pcntl_`, `proc_open`, `exec`, `curl_init` ব্যবহার | শেয়ার্ড হোস্ট প্রায়ই ফাংশন disable করে |
| `sys_get_temp_dir`, `open_basedir`, `ini_set`, `set_time_limit` | open_basedir ও time limit সীমা |
| সব `queryOne()` বনাম `=== false` তুলনা | ফ্রেমওয়ার্ক কী ফেরত দেয়, সেটা নিয়ে ভুল ধারণা সাইলেন্ট বাগ বানায় |
| কিউ ওয়ার্কার, CLI ধারণা, cron নির্ভরতা | ক্রন এক-লাইন, আউটপুট কেউ পড়ে না |
| hard-coded ডেভ URL/পাথ (`127.0.0.1`, `D:/xampp`) | প্রোডে কি টানে |
| upload/session/cache path ও `UPLOAD_ERR_*` হ্যান্ডলিং | শেয়ার্ড হোস্টের ডিফল্ট সীমা |

বেসলাইন রেকর্ড: **532 tests / 4232 assertions (Unit+Console+Functional) পাস**, Web স্যুট আলাদা (সার্ভার লাগে)।

---

## ২. যা পাওয়া গেছে ও যা ঠিক করা হয়েছে (৪টি বাস্তব সমস্যা)

### ২.১ `public/.htaccess` — দুটি বাগ, দুটোই শুধু প্রোডে (Apache-তে) দেখা যেত

**ফাইল:** [public/.htaccess](../public/.htaccess)

**সমস্যা (ক) — `/.well-known/assetlinks.json` পৌঁছাতই পারত না**

- **কারণ:** ডটফাইল নিয়ম `RewriteRule (^\.|/\.) - [F]` যেকোনো ডট দিয়ে শুরু হওয়া পাথে 403 দেয় — `.well-known/assetlinks.json` শুরু হয় `.` দিয়ে, তাই Apache অ্যাপ দেখার আগেই 403 ফেলত।
- **কেন এটা ধরা পড়েনি:** ডেভ `php -S` সার্ভারে `.htaccess` পড়ে না, তাই ডেভে রুটটি ঠিকমতো কাজ করত। ডক-এ এই 403-কে "ইচ্ছাকৃত" বলে ভুল ব্যাখ্যা করা হয়েছিল — আসলে সেটা Apache-র নিজের 403 ছিল।
- **প্রভাব:** TWA (Trusted Web Activity) ভেরিফিকেশন **কখনোই সফল হত না**, কারণ ফিঙ্গারপ্রিন্ট সেট করেও অ্যাপ উত্তর দিতে পারত না।
- **সমাধান:** `.well-known` রুটটির জন্য আলাদা নিয়ম `RewriteRule ^\.well-known(/|$) - [E=DOTFILE_ALLOWED:1]`, তারপর ডটফাইল-ব্লক `RewriteCond %{ENV:DOTFILE_ALLOWED} !1` দিয়ে গার্ড করা — যাতে AutoSSL/ACME-র **আসল ফাইলও** (`.well-known/acme-challenge/…`) সার্ভ হয়।

**সমস্যা (খ) — বিদ্যমান ডটফাইল সরাসরি সার্ভ হত**

- **কারণ:** ফাইল-এক্সিস্ট পাসথ্রু (`RewriteCond %{REQUEST_FILENAME} -f` → `[L]`) ব্লকটি ডটফাইল-নিয়মের **আগে** ছিল, ফলে `public/`-এ কেউ `.env` বা `.user.ini` ফেললে সেটি `200 OK`-তে প্লেইন টেক্সট হিসেবে বের হত।
- **সমাধান:** নিয়মবদ্ধটি পাসথ্রুর **আগে** সরানো + `<FilesMatch "^\."> Require all denied` দিয়ে backstop (mod_rewrite না থাকলেও নিরাপত্তা থাকবে)। আগের `<FilesMatch "^\.env">` সর্বব্যাপী নিয়মে উন্নীত।

**সমস্যা নয় যা সুরক্ষিত রাখা হলো:** `Options -Indexes`, `Expires`/`Deflate`, আর রিয়েল ফাইল-পাসথ্রু অক্ষত (অ্যাসেট, ফেভিকন)।

---

### ২.২ নোটিফিকেশন কিউ ওয়ার্কার একটি খারাপ রোতে পুরো ব্যাচ মেরে দিত

**ফাইল:** [src/Console/NotificationWorkCommand.php](../src/Console/NotificationWorkCommand.php)

- **কারণ:** চ্যানেল ড্রাইভার কোনো `Throwable` থ্রো করলে (host-এ function disabled, ওয়েবশকেশন error, DB ব্লিপ, খারাপ credentials) এক্সেপশন `execute()` থেকে বেরিয়ে যেত → প্রসেস মরত → পরের টিক একই রো claim করে আবার মরত। কোনো কিছুই লগ হত না, `Sent` বাড়ত না, `/admin/notifications`-এ `Queued` অসীম বাড়ত।
- **কেন ধরা পড়েনি:** ডকব্লক দাবি করেছিল "a single bad row must not kill the batch", কিন্তু কোডে try/catch ছিল না। `app:bulk:work`-এ একই আইসোলেশন **আছে** — অর্থাৎ এটা ছিল একই কনভেনশনের বাইরে থাকা একটি ভুলে পড়া অংশ। টেস্টও ছিল না (bulk worker-এও নেই)।
- **সমাধান:** প্রতি-রো `try/catch (\Throwable)` — কারণ `last_error`-এ রেকর্ড, `retryable: true` দিয়ে রিট্রাই ( `max_attempts` পূর্ণ হলে dead-letter), লুপ `continue` করে পরের রোতে যায়। ড্রাইভার-ডিসপ্যাচ আলাদা `deliver()` মেথডে তোলা হয়েছে যাতে "যে অংশ থ্রো করতে পারে" স্পষ্ট থাকে।

---

### ২.৩ `ext-curl` ছাড়া তিনটি পুশ চ্যানেল fatal দিত

**ফাইল:** [src/Notification/Channel/CurlSupport.php](../src/Notification/Channel/CurlSupport.php) (নতুন), সঙ্গে Fcm/Telegram/WebPush চ্যানেল

- **কারণ:** FCM, Telegram, Web Push — অ্যাপের **একমাত্র** curl-ব্যবহারকারী, এবং তিনটিই ক্রন থেকে চলে। হোস্টে curl না থাকলে বা `disable_functions=curl_exec` থাকলে `curl_init()` একটি fatal `Error` — ঠিক ২.২-এর বাগের সঙ্গে মিলে গিয়ে ক্রন প্রতি মিনিটে মরত।
- **কেন ধরা পড়েনি:** `isAvailable()`/`credentialsError()` শুধু *credentials* যাচাই করত — কোনোটিই "পাঠানোর ক্ষমতা আছে কি না" জিজ্ঞেস করত না। composer-এ `ext-curl` চাহিদাও লেখা ছিল না।
- **সমাধান:** `CurlSupport::blocker()` একটিমাত্র জায়গায় `curl_init` **ও** `curl_exec` দুটোই যাচাই করে (হোস্ট দুটিকে আলাদা আলাদা disable করে), কারণ বোঝানো মেসেজ ফেরত দেয়। তিনটি চ্যানেলের `send()`/`sendToToken()`/`sendToSubscription()` ও `credentialsError()`-এ সবচেয়ে আগে যাচাই — যাতে `app:fcm:check`/`app:webpush:check` সবু ক্রেডেনশিয়াল ঠিক বলে "পরে হঠাৎ ক্র্যাশ" না করে আসল কারণ নাম ধরে বলে।
- **কেন composer-এ `ext-curl` যোগ করা হয়নি:** অ্যাপ ইচ্ছাকৃতভাবে curl ছাড়াও চলে (সাইট, ইন-অ্যাপ নোটিফিকেশন, অর্ডার) — শুধু পুশ নিঃশব্দে dead-letter হয়। composer-এ বাধ্যতামূলক করলে CI runner-এ install ব্যর্থ হবে, কিন্তু **হোস্ট সম্পর্কে কোনো তথ্য দেবে না** (কারণ হোস্টে composer চলেই না)। তাই প্রয়োজন দুটোই ডকে লেখা হয়েছে, আর রানটাইমে ডায়াগনোস্টিক আছে।

---

### ২.৪ `DeviceRepository::upsert` নতুন ডিভাইস কখনো ইনসার্ট করত না

**ফাইল:** [src/Repository/DeviceRepository.php](../src/Repository/DeviceRepository.php)

- **কারণ:** Yii-র `queryOne(): ?array` রো না থাকলে **`null`** দেয় (সোর্স: `is_array($results) ? $results : null`), কোডে লেখা ছিল `if ($existing === false)` — যা কখনোই true হত না। ফলে প্রথম রেজিস্ট্রেশন *update* ব্রান্চে যেত, যেখানে `WHERE id = NULL` মানে ০ সারি আপডেট → কোনো রো লেখা হত না, warning ছাড়াই একটি অন্তর্নিহিত id ফেরত হত।
- **প্রভাব:** APK-র `POST /api/devices` রেজিস্ট্রেশন **নিঃশব্দে ব্যর্থ** → FCM টোকেন কখনো জমা হয় না → মোবাইলে পুশ কখনো পৌঁছায় না। লগে কিছুই থাকত না।
- **কেন ধরা পড়েনি:** `DeviceRepository`-এর কোনো টেস্টই ছিল না (নতুন-রেজিস্ট্রেশন কেস কখনো কভার হয়নি)। আর এই প্যাটার্নের ভুল সংস্করণ কোডবেসে আগেও দুইবার ধরা পড়েছে — `PushSubscriptionRepository` ও `TemplateRenderer`-এ ঠিক একই ভুলের জন্য আলাদা কমেন্ট লেখা আছে।
- **সমাধান:** `if ($existing === null || $existing === false)` + সেই কমেন্ট-প্যাটার্ন অনুসরণ করে কারণ লেখা। বাকি `=== false` জায়গাগুলো যাচাই করা হয়েছে — সেগুলো `queryScalar()` ব্যবহার করে, যা সত্যিই `false` দেয়, তাই সঠিক (হানিপট নেই)।

---

## ৩. ডকুমেন্টেশন পরিবর্তন

| ফাইল | কী | কেন |
|---|---|---|
| [README.md](../README.md) | রিকোয়্যারমেন্টে `ext-curl` যোগ (ঐচ্ছিক, degradation সহ) | নতুন ডেভ/অপারেটর আগে থেকে জানুক |
| [docs/deployment.md](deployment.md) §১ | ext-curl চাহিদা + cPanel *Select PHP Version* পথ | হোস্টে চালু করার নির্দেশনা |
| docs/deployment.md §৮ | troubleshooting-এ ২টি সারি: "পুশ পাঠানো হচ্ছে না" ও "`assetlinks.json` 403" | সবচেয়ে সম্ভাব্য দুটি সাইলেন্ট সমস্যার সমাধান |
| docs/deployment.md §৫.৬ | `Dead` বাড়লে curl-মেসেজও কারণ হতে পারে | কিউ-ড্যাশবোর্ড পড়ার সময় কারণ বোঝা যায় |
| docs/deployment.md §১০.৮ | `.htaccess` ব্যতিক্রম সম্পর্কে সৎ ব্যাখ্যা | আগের ব্যাখ্যা ভুল ছিল (403 আসত Apache থেকে) |
| docs/deployment.md §২ | `APP_HOST_PATH` সম্পর্কে **ভুল দাবি সংশোধন** | সেটি শুধু এরর-পেজের ট্রেস-লিংক বদলায়; **সাব-ডিরেক্টরি ইনস্টল সমর্থন করে না** (`@baseUrl = '/'`, রুট ও অ্যাসেট URL সব ডোমেইন-রুটেই ধরে বানানো)। ভুল দাবি থাকলে ভুল জায়গায় ডিপ্লয় করে সময় নষ্ট হতো |

---

## ৪. নতুন টেস্ট (৩টি ফাইল, ৭টি টেস্ট)

| টেস্ট | কী যাচাই করে | কেন এভাবে |
|---|---|---|
| [tests/Unit/HtaccessTest.php](../tests/Unit/HtaccessTest.php) | `.htaccess`-এর **ক্রম**: `.well-known` ব্যতিক্রম → ডটফাইল-ব্লক → ফাইল-পাসথ্রু → ফ্রন্ট-কন্ট্রোলার, সঙ্গে `<FilesMatch>` backstop | `.htaccess` কোথাও চলে না ডেভ/ইন-প্রসেস টেস্টে — ভাঙলেও সব সবুজ থাকত। কমেন্ট বাদ দিয়ে শুধু ডিরেক্টিভ পড়ে অ্যাসার্ট করে |
| [tests/Unit/CurlSupportTest.php](../tests/Unit/CurlSupportTest.php) | subprocess-এ `disable_functions=curl_init,curl_exec` দিয়ে প্রমাণ করে যে blocker সত্যিই নাম ধরে কারণ দেয় | হোস্টের শর্তটি বাস্তবেই তৈরি করে যাচাই; mock দিয়ে হলে প্রমাণ হতো না |
| [tests/Functional/NotificationWorkerIsolationTest.php](../tests/Functional/NotificationWorkerIsolationTest.php) | আসল `yii` এন্ট্রি পয়েন্ট (cron-এর মতো) subprocess-এ `disable_functions=openssl_sign` দিয়ে চালিয়ে: থ্রো করা রোর কারণ রেকর্ড হয়, একটিমাত্র attempt হয়, **পরের রো এখনো প্রসেস হয়**, exit 0 | in-process-এ function disable করা যায় না; সব চ্যানেলের env পিন করা যাতে এই রানে **কোনো নেটওয়ার্ক কল না হয়** |

**মিউটেশন চেক** (প্রতিটি নতুন টেস্ট প্রমাণিত যে ভাঙলে ভাঙে):

1. `NotificationWorkCommand`-এর catch-এ `throw $e;` বসালে → isolation test ব্যর্থ (exit 1, স্ট্যাক ট্রেস)।
2. `DeviceRepository` হটা-ব্যাক `=== false` করলে → isolation test এরর (array offset on null warning)।
3. `.htaccess`-এ পাসথ্রু ব্লক আগে সরালে → `HtaccessTest` ব্যর্থ (`Failed asserting that 280 is less than 130`)।

---

## ৫. যাচাইয়ের ফলাফল

### ৫.১ আসল Apache-তে `.htaccess` (localhost:8080, ঠিক এই প্রজেক্টের docroot)

| অনুরোধ | পুরনো `.htaccess` | নতুন `.htaccess` |
|---|---|---|
| `/.probe1.txt` (`public/`-এ আসল ফাইল) | **200 — ফাইলটি সার্ভ হয়ে গেছে** | **403** (Apache deny) |
| `/.well-known/assetlinks.json` | **403 — Apache, অ্যাপ দেখেনি** | **404 — অ্যাপের JSON** (`{"detail": "No Digital Asset Links statement is configured…"}`) |
| `/.well-known/acme-probe.txt` (আসল ফাইল) | — | **200** (AutoSSL/ACME অক্ষত) |
| `/assets/.probe2.txt` (সাবডিরেক্টরির ডটফাইল) | — | **403** (শুধু রিরাইট-নিয়মেই ধরা, `<FilesMatch>` সাবডিরেক্টরিতে পৌঁছায় না) |
| `/login` | — | **200** |
| `/assets/css/app.css` | — | **200** |

(— = পুরনো ফাইলে পরীক্ষা করা হয়নি; শুধু দুটি কেন্দ্রীয় কেসে তুলনা করা হয়েছে।)

### ৫.২ টেস্ট স্যুট

| স্যুট | ফল |
|---|---|
| Unit + Console + Functional | **539 tests / 4262 assertions — OK** (বেসলাইন 532 / 4232 → +৭ টেস্ট) |
| Web (PhpBrowser, `php -S` সার্ভারে) | 26 tests — **১টি ব্যর্থতা, পূর্ববর্তী** (নিচে §৬) |
| `php yii list` (deploy workflow-এর স্মোক) | চলে |
| সব পরিবর্তিত PHP ফাইল `php -l` | পরিষ্কার |

### ৫.৩ Deploy workflow-এর নতুন post-deploy ধাপ

[.github/workflows/deploy-cpanel.yml](../.github/workflows/deploy-cpanel.yml)-এ `Check the host meets the app's requirements` ধাপ — migrations-এর পরে, health check-এর আগে। হোস্টে চলে: **PHP ভার্সন (8.2+), ext-curl, runtime-এর writability (আসলে লেখার চেষ্টা করে), `.htaccess` (front controller + `/.well-known` ব্যতিক্রম)**। ফল লগের annotation ও job summary-র টেবিল — দুটোতেই।

**ডিজাইন সিদ্ধান্ত:** ধাপটি রিপোর্ট-ওনলি — রিমোট স্ক্রিপ্ট সবসময় `exit 0`, চেকগুলো annotation দেয়, ধাপ ব্যর্থ করে না। কারণ অ্যাপ ইচ্ছাকৃতভাবে degradation করে (curl নেই → পুশ থামে, সাইট নয়) এবং deploy ভাঙলে বলার দায়িত্ব health check-এর — সবুজ সাইটে লাল ক্রস দেখলে মানুষ লাল ক্রস দেখতে শিখে ফেলে।

**যাচাই (স্থানীয়ভাবে, স্ক্রিপ্ট ফাইল থেকে awk দিয়ে বের করে চালিয়ে):**

| কেস | ফল |
|---|---|
| YAML parse (`yaml.safe_load`) | ধাপটি ঠিক migrations-এর পরে, health check-এর আগে; `env` সঠিক |
| পজিটিভ (আসল প্রজেক্ট + আসল PHP) | ৬টি marker, exit 0; **`runtime/logs (missing)` সত্যিই ধরেছে** |
| `DEPLOY_PATH` অনুপস্থিত | error marker + annotation, exit 0 |
| curl disabled (`-d disable_functions`) | `ext-curl \| missing or disabled \| warn` + warning, exit 0 |
| PHP 7.4 (fake binary) | `PHP version \| 7.4.33 (8.2+ required) \| error`, exit 0 |
| `.htaccess` অসম্পূর্ণ / অনুপস্থিত | `RewriteEngine missing; front controller missing` \| error, `/.well-known` absent \| warn |
| runner-অংশ | summary টেবিল ৬ সারিতে দাঁড়ায়, `::check::` marker বাদ দিয়ে বাকিটা লগে রিপ্লে হয়, `1 failed, 0 warning(s)` কাউন্ট সঠিক, exit 0 |

`bash -n` দুই অংশেই পরিষ্কার; Unit স্যুট পরেও পাস (179 tests / 2189 assertions)।

---

## ৬. যা ব্যর্থ বা চালানো যায়নি

1. **Web স্যুটে ১টি ব্যর্থতা — `AdminOrderQueueBulkCest::theExportAnswersWithAFileRatherThanAnotherPage`**
   কারণ `checkOption('input[name="ids[]"][value="…"]')` এলিমেন্ট পাওয়া যায়নি (সেই অর্ডারটি পেজে রেন্ডার হয়নি) — ডেটা/পরিবেশ-নির্ভর।
   **প্রমাণ যে এটা আগ থেকেই আছে:** সব পরিবর্তন `git stash push -u` দিয়ে সরিয়ে **বেসলাইন কোডে একই টেস্ট চালানো হয়েছে → সেখনেও ব্যর্থ**; তারপর stash pop করে ফাইলগুলো ব্যাকআপ থেকে মিলিয়ে দেখা হয়েছে অক্ষত। সম্পর্কিত নোট আগ থেকেই আছে `docs/referral-qualifying-recharge-rule.md`-তে (php -S-এর পরিবেশগত 404 সমস্যা)। **স্পর্শ করা হয়নি।**

2. **`vendor/bin/psalm` চলেনি** — `psalm.xml` থেকে গেছে কিন্তু composer.json-এ psalm প্যাকেজ নেই (require-dev-এ codeception, phpunit, php-cs-fixer, rector, composer-dependency-analyser)। ফলে স্ট্যাটিক অ্যানালাইসিস এই কাজের অংশ হিসেবে পারফর্ম করা যায়নি; বদলে টেস্ট + মিউটেশন-চেক + আসল Apache পরীক্ষা দিয়ে যাচাই করা হয়েছে।

3. **কোনো কমিট করা হয়নি** (অনুরোধ পাওয়া যায়নি) — পরিবর্তনগুলো working tree-তে অপেক্ষমাণ।

---

## ৭. সীমাবদ্ধতা (যা এই কাজের বাইরে)

- **Apache-only:** `.htaccess` Nginx/LiteSpeed-তে কাজ করে না; সেই হোস্টে nginx-কনফিগ/`.user.ini` লাগবে।
- **সাব-ডিরেক্টরি ইনস্টল সমর্থিত নয়** (`example.com/tools/`) — এখন শুধু ডক সংশোধন করে সৎ করা হয়েছে, কোডে বাস্তব সমর্থন যোগ করা হয়নি (রুট/অ্যাসেট URL, রুট, কুকি path — সবকিছু ডোমেইন-রুটে ধরে)।
- **`ext-curl` ঐচ্ছিকই থাকল** (চাহিদা হিসেবে composer-এ বাধ্যতামূলক করা হয়নি) — কারণটি §২.৩-এ।
- **ফাইল-আপলোড সীমা** (`upload_max_filesize`/`post_max_size`) অডিটে দেখা হয়েছে অ্যাপ ইতিমধ্যে `UPLOAD_ERR_INI_SIZE`-কে ব্যবহারকারীবান্ধব মেসেজে বদলায়; তবে হোস্টের সীমা বাড়ানো `.user.ini` দিয়ে সমাধান করা হয়নি (হোস্টভেদে প্রয়োজন বলে সেটা স্পেকুলেটিভ হত)।
- **opcache রিসেট** ডিপ্লয়ে যোগ করা হয়নি — cPanel-এ `opcache.validate_timestamps=1` ডিফল্ট, rsync `-a` mtime রাখে, তাই প্রয়োজনীয়তা দেখা যায়নি।

---

## ৮. পরিবর্তিত ফাইল

**সংশোধিত (৯টি):**

- `public/.htaccess`
- `src/Console/NotificationWorkCommand.php`
- `src/Notification/Channel/FcmChannel.php`
- `src/Notification/Channel/TelegramChannel.php`
- `src/Notification/Channel/WebPushChannel.php`
- `src/Repository/DeviceRepository.php`
- `README.md`
- `docs/deployment.md`
- `.github/workflows/deploy-cpanel.yml` (§৫.৩)

**নতুন (৫টি):**

- `src/Notification/Channel/CurlSupport.php`
- `tests/Unit/HtaccessTest.php`
- `tests/Unit/CurlSupportTest.php`
- `tests/Functional/NotificationWorkerIsolationTest.php`
- `docs/shared-hosting-readiness-report.md` (এই ফাইল)

`git diff --stat`: আগের ১৩টি ফাইল (৮টি সংশোধিত + ৫টি নতুন, 760 insertions) **কমিট `75b552f`-এ তোলা হয়েছে**; বর্তমান pending পরিবর্তন একটিমাত্র ফাইল — `.github/workflows/deploy-cpanel.yml` (**171 insertions**)।

---

## ৯. পরবর্তী পরামর্শ

1. `php yii app:hosting:check` ডায়াগনস্টিক কমান্ড — ext-curl, runtime/cache/storage writability, PHP ভার্সন, open_basedir, `.htaccess` একসাথে যাচাই।
2. ~~deploy workflow-এ post-deploy ধাপ~~ — **সম্পন্ন**, §৫.৩ দেখুন (`Check the host meets the app's requirements`)।
3. `queryOne() === false` প্যাটার্নের পুনরাবৃত্তি রোধ — স্ট্যাটিক গার্ড বা rector rule (কারণ একই বাগ তিনবার এসেছে)।
4. সাব-ডিরেক্টরি ইনস্টল সমর্থন (প্রয়োজন থাকলে)।
