# কাজের পরিকল্পনা (Phased TODO)

মূল অনুরোধ (৬টি প্রয়োজনীয়তা), পর্যায়ভিত্তিক ভাগ করে কাজ শুরু।
প্রতিটি ধাপ শেষে এই ফাইলে অবস্থা আপডেট করা হবে।

## প্রয়োজনীয়তা

1. প্রোফাইলে ইউজার তথ্য: ইমেইল, হোয়াটসঅ্যাপ নম্বর, টেলিগ্রাম নম্বর।
2. পাসওয়ার্ড রিসেট — ইমেইল লিংক + হোয়াটসঅ্যাপ/টেলিগ্রামে OTP।
3. ইউজারের হোয়াটসঅ্যাপ/টেলিগ্রাম নম্বর থাকলে **সব** নোটিফিকেশন ওখানেও যাবে।
4. নোটিফিকেশন পারমিশন চাইবে **শুধু লগইন অবস্থায়**; পারমিশন না দিলে বা নোটিফিকেশন
   বন্ধ থাকলে **স্থায়ী রিমাইন্ডার** থাকবে।
5. সাইট সেটিংস = সুপার এডমিন। রিচার্জ সেটিংস = সুপার এডমিন; এডমিন রিচার্জ রিকোয়েস্ট
   রিভিউ/অনুমোদন/যাচাই করতে পারবে।
6. `APP_URL` ডিফল্ট ডোমেইন; সাবডোমেইন/অন্য ডোমেইন দিয়েও সাইট চলবে।

## ধাপসমূহ

| # | ধাপ | অবস্থা |
|---|-----|--------|
| 1 | Migration: `user.whatsapp_no` / `user.telegram_no` + `password_reset_token` টেবিল | ✅ সম্পন্ন |
| 2 | প্রোফাইল: ইমেইল/হোয়াটসঅ্যাপ/টেলিগ্রাম ফিল্ড + ভ্যালিডেশন | ✅ সম্পন্ন |
| 3 | পাসওয়ার্ড রিসেট: ইমেইল লিংক + হোয়াটসঅ্যাপ/টেলিগ্রাম OTP ফ্লো | ✅ সম্পন্ন |
| 4 | নোটিফিকেশন ফ্যান-আউট ইউজারের হোয়াটসঅ্যাপ/টেলিগ্রামে | ✅ সম্পন্ন |
| 5 | পুশ পারমিশন শুধু লগইনে + বন্ধ থাকলে স্থায়ী রিমাইন্ডার | ⬜ বাকি |
| 6 | সাইট/রিচার্জ সেটিংস সুপার-এডমিন স্কোপ (এডমিন রিভিউ অপরিবর্তিত) | ⬜ বাকি |
| 7 | `APP_URL` ডিফল্ট ডোমেইন + সাবডোমেইন/অন্য ডোমেইন সাপোর্ট | ⬜ বাকি |
| 8 | পূর্ণ টেস্ট স্যুট + টাইপচেক চালিয়ে যাচাই | ⬜ বাকি |

---

## অগ্রগতি নোট

### ধাপ 1 — Migration ✅

- ফাইল: `migrations/M240122000000_AddContactChannelsAndResetTokens.php`
- `{{%user}}` → `whatsapp_no varchar(32) NULL`, `telegram_no varchar(64) NULL`
  (UNIQUE নয় — একই নম্বর পরিবারের দুজনের হতে পারে; ডুপ্লিকেট প্রোফাইল ফর্মে চেক হয়)।
- `{{%password_reset_token}}`: `user_id`, `channel(email|whatsapp|telegram)`,
  `token_hash` (সব সিক্রেট হ্যাশড), `expires_at`, `consumed_at`, `attempts`,
  টাইমস্ট্যাম্প। `token_hash` NON-unique (৬-ডিজিট OTP কলিশন সম্ভব)।
- `email` PK-ভিত্তিক পুরনো পরিকল্পনা বাতিল — resend/OTP/link তিনটাই এক টেবিলে চলে না।
- `php yii migrate:down` → `migrate:up` করে স্কিমা যাচাই করা হয়েছে।

### ধাপ 2 — প্রোফাইল ফিল্ড ✅

- `src/Web/Account/ProfileAction.php` — `normalizePhone()` (+880 → 0),
  `isPhoneLike()` (E.164 envelope), `isTelegramHandle()` (@handle বা নম্বর),
  অন্য অ্যাকাউন্টের সাথে ডুপ্লিকেট চেক (`UserRepository::findByContact`)।
  ভ্যালিডেশন ফেল করলে টাইপ করা মান ফর্মে ফিরে আসে।
- `src/Repository/UserRepository.php` — `findByContact()`, `contactOn()` (যেকোনো
  চ্যানেলে ইউজারের ঠিকানা; কলাম না থাকলে null — migrate-এর আগেও নষ্ট হবে না)।
- `resources/views/site/account/profile.twig` — হোয়াটসঅ্যাপ/টেলিগ্রাম ইনপুট,
  প্লেসহোল্ডারে ব্যাখ্যা: "দিলে সব নোটিফিকেশন ওখানেও আসবে" (= অপট-ইন)।

### টেস্ট বেসলাইন (আমার পরিবর্তনের আগে)

`vendor/bin/codecept run Unit,Console,Functional` →
**Tests: 558, Assertions: 4282, Failures: 7** (প্রি-এক্সিস্টিং):
- `HtaccessTest` × 3 (dotfile/front-controller)
- `PushWatchCommandTest` × 4 (লাইভ ডিভেলপমেন্ট ডিবিতে অতিরিক্ত স্টাফ/সাবস্ক্রিপশন
  থাকায় table-wide count মিলছে না)

এই ৭টি বিফলতা পুরনো; নতুন বিফলতা এলে তা আলাদা করে ধরতে হবে।

### টেস্ট বেসলাইন (এখন)

**Tests: 580, Assertions: 4370, Failures: 7** — একই ৭টি প্রি-এক্সিস্টিং বিফলতা
(`HtaccessTest` × 3, `PushWatchCommandTest` × 4), কোনো রিগ্রেশন নেই।
যোগ হয়েছে `PasswordResetTest` (২০) + `FlashMessageCest` (২) = ২২টি টেস্ট।

### ধাপ 3 — পাসওয়ার্ড রিসেট ✅

- ✅ `src/Service/EmailSender.php` — `mail()`-ভিত্তিক প্লেইন-টেক্সট সেন্ডার
  (কোনো মেইল লাইব্রেরি নেই; `blocker()` কারণ ফেরত দেয়, `send()` কখনো throw করে না,
  Bengali সাবজেক্ট RFC 2047 base64-encoded)। `.env`-এ `MAIL_FROM`,
  `MAIL_FROM_NAME` লাগবে (এখনো সেট করা হয়নি)।
- ✅ `src/Notification/Channel/WhatsAppChannel.php` — Meta Cloud API ড্রাইভার
  (`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`; ঐচ্ছিক `WHATSAPP_TEMPLATE` /
  `WHATSAPP_TEMPLATE_LANGUAGE` — ২৪-ঘণ্টা উইন্ডোর বাইরে অনুমোদিক টেমপ্লেট লাগে)।
  `toE164()` লোকাল `017…` → `88017…`। `send()` = কিউ ওয়ার্কার এন্ট্রি,
  `sendText()` = OTP-র জন্য সিঙ্ক্রোনাস পাঠ।
- ✅ `src/Service/PasswordResetService.php` — `issue()` / `verify()`,
  `MAX_OTP_ATTEMPTS = 5`, চ্যানেল `email|whatsapp|telegram`। সব কেসে একই উত্তর
  ("অ্যাকাউন্ট থাকলে পাসওয়ার্ড রিসেট লিংক…") — অ্যাকাউন্ট নেই/চ্যানেল নেই/সফল,
  তিনটাই ব্যবহারকারীর কাছে একাকার।
- **বাগ ফিক্স**: ৫ম ভুল OTP-তে সারি `attempts=5` নিয়ে জীবিত থাকত, ফলে ৬র্থ চেষ্টা
  করা যেত — `MAX_OTP_ATTEMPTS = 5`-এর "৫টি ভুল কোডের পরে সারি স্থায়ীভাবে নষ্ট" কথার
  বিপরীত। এখন ৫ম ভুলেই সারি consume হয়। `PasswordResetTest` দিয়ে যাচাই করা।
- ✅ `src/Web/Auth/ForgotPasswordAction.php`, `ResetPasswordAction.php`,
  টেলিগ্রাম OTP (মোজো: `TelegramChannel` + `BotConnectionRepository`),
  রুট (`/forgot-password`, `/reset-password`), ডিআই, ভিউ, লগইন পেজে লিংক।
- **বাগ ফিক্স**: ইমেইল চ্যানেলে রিডাইরেক্ট `/reset-password`-এ যেত, যেখানে টোকেন
  নেই — "ইনবক্স দেখুন" বার্তার পর ফিরে এমন পেজ পড়ত যেখানে লেখা "লিংক পাননি"।
  এখন ইমেইলে ফর্মেই ফেরা (`messageFor()`-এ একই শব্দ, লাইভ-ইউজার প্রকাশ না করে)।
- **বাগ ফিক্স**: `layouts/auth.twig` কোনো ফ্ল্যাশই রেন্ডার করত না, তাই পুরো রিকভারি
  ফ্লো নীরব ছিল। এখন `flash_success`/`flash_error` ব্যানার (ড্যাশবোর্ড লেআউটের
  একই ক্লাস, লিখে যায় `role="status"`/`role="alert"`), `auth_content` ব্লকের ঠিক আগে।
- ✅ টেস্ট: `tests/Functional/PasswordResetTest.php` (২০), `tests/Functional/FlashMessageCest.php` (২)।
  ফ্ল্যাশ টেস্ট কন্ট্রোল-চেক করা (ব্যানার সরালে সত্যিই ফেল)।

### ধাপ 4 — নোটিফিকেশন ফ্যান-আউট ✅

প্রোফাইলে নম্বর দেওয়া মানে সেই চ্যানেলে **সব** নোটিফিকেশন পাওয়ার সম্মতি।
ইভেন্ট ম্যাট্রিক্স এখন মেঝি (webpush/fcm/টেলিগ্রাম-ফর-স্টাফ), কন্টাক্ট কলাম সেটা
তুলে দেয় — `dispatch()`-এ `array_unique([...spec['channels'], ...contactChannels($userId)])`।

- ✅ `NotificationManager::contactChannels()` — একটাই মেথড, দুই লুপেই।
  - **হোয়াটসঅ্যাপ** = নম্বর, `user.whatsapp_no`-ই ঠিক ঠিকানা।
  - **টেলিগ্রাম** = *চ্যাট*; প্রোফাইলের `telegram_no` হ্যান্ডেল/নম্বর হতে পারে,
    কিন্তু বট কেবল সেই কথোপকথনেই লিখতে পারে যা ইউজার নিজে শুরু করেছে।
    তাই `contactOn('telegram')` **এবং** `bots->activeChatIds()` — দুটোই লাগে।
- ✅ ব্ল্যাংক/স্পেস-ওনলি নম্বর সম্মতি নয় (`contactOn()` trim করে `null` দেয়)।
- ✅ ডিডাপ: রিপ্লে করা অনুমোদন দ্বিতীয় কিউ সারি বানায় না।

**বাগ ফিক্স (ফ্যান-আউট চালু করার ফলে প্রকাশিত):** `telegram` এখন ইউজারের চ্যানেল,
কিন্তু সিড করা telegram কপি **সব ইভেন্টেই স্টাফ-উদ্দেশ্য** — যেগুলোতে telegram কপি আছে
(`service_request.created`, `topup.requested`, `topup.cancelled`,
`admin_withdraw.requested`, `system.alert`) সেগুলো সবই `also_admins` ইভেন্ট, কারণ
আগে টেলিগ্রাম শুধু স্টাফের ছিল। ফ্যান-আউট সেই অনুমান ভেঙে দেয়: গ্রাহক নিজেই যে
অর্ডার করেছেন তাকে `নতুন সার্ভিস অনুরোধ — পাসপোর্ট — SC1 — ব্যবহারকারী #24839`
পাঠানো হতো (কন্ট্রোল-চেকে প্রমাণিত)।
`TemplateRenderer::render()`-এ এখন `$staffAudience` প্যারামিটার — নন-স্টাফের জন্য
telegram কপি (টেবিলের সারি ও সিড উভয়ই) ফলব্যাক মানো হয় না, তাই নিজের in_app
কপি পায়। এতে `whatsapp → telegram` বরো (fcm না থাকলে) যে দ্বিতীয় পথটাও বন্ধ হয়।
স্টাফ ফ্যান-আউট `true` পায়, তাই তাদের কপি অপরিবর্তিত।

- ✅ টেস্ট: `tests/Functional/NotificationContactFanOutTest.php` (১২)।
  `NotificationCoreTest` এই অংশটা ধরতে পারে না — সে বট রিপোজিটরি ছাড়াই ম্যানেজার
  বানায়, তাই টেলিগ্রাম-পাশ সেখানে কনস্ট্রাকশনেই অদৃশ্য।
  কপি-দাবিটা টেমপ্লেট টেবিলে নয়, কিউ সারির **payload**-এ যাচাই করা — কারণ ওয়ার্কার
  যা পাঠায় তা-ই ফোনে পৌঁছায়; স্টাফ-কপি গার্ডও কন্ট্রোল-চেক করা।
- **Tests: 592, Assertions: 4393, Failures: 7** — একই ৭টি প্রি-এক্সিস্টিং বিফলতা
  (৩ × `HtaccessTest`, ৪ × `PushWatchCommandTest`), কোনো রিগ্রেশন নেই।

### গুরুত্বপূর্ণ টিপস

- টেস্ট: `vendor/bin/codecept run Unit,Console,Functional` (Web স্যুটে 127.0.0.1:8099
  সার্ভার লাগে)।
- ব্রাউজারে দেখতে ডেভ সার্ভার: রিপোরোরুটে `yii` ফাইলটি **কনসোল** এন্ট্রিপয়েন্ট, তাই
  `php -S` রাউটার হিসেবে দিলে `strict_types declaration must be the very first
  statement` ফেলে। `build`-এ `serve` কমান্ডও নেই। কাজ করে এমন রাউটার স্ক্রিপ্ট
  (`/tmp/router_dev.php`):

  ```php
  <?php
  $root = getcwd();
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if ($path && $path !== '/' && is_file($root . '/public' . $path)) {
      return false;               // স্ট্যাটিক ফাইল সরাসরি দেবে
  }
  require $root . '/public/index.php';
  ```

  চালাতে: `cd` রিপোরোরুটে গিয়ে
  `php -S 127.0.0.1:8102 -t public /tmp/router_dev.php`
  (৮০৯৯/৮১০১-এ পুরনো প্রসেস লেগে আছে, নতুন পোর্ট নিতে হবে)।
- মাইগ্রেশন: `echo y | php yii migrate:up` (ইন্টারঅ্যাকটিভ কনফার্মেশন নেয়)।
- CSRF: সব POST-এ `_csrf` লাগে, না দিলে 422 (ফাংশনাল টেস্টে গেট-প্রুফ হিসেবে ব্যবহৃত)।
- **ফাংশনাল টেস্টে POST বানানোর নিয়ম** (`HttpSoft\Message\ServerRequest`):
  - `parsedBody` নিজে দিতে হয় — আসল SAPI-তে PHP `$_POST` ভরে, কিন্তু টেস্ট হার্নেস
    রিকোয়েস্ট নিজে বানায়। না দিলে CSRF মিডলওয়্যার ও অ্যাকশন দুজনেই ফর্ম খালি পায়
    → 422।
  - বডি `string` দিলে সেটা **ফাইল পাথ** ধরা হয় (`Stream` দিতে হবে)।
  - কুকি `Cookie` হেডারে নয়, **`cookieParams`** অ্যারেতে দিতে হয় —
    `SessionMiddleware` সেখান থেকেই সেশন আইডি পড়ে; সেশন কুকির নাম `ALIF_SESSION`।
  - প্রতিটি `sendRequest()` একই প্রসেসে নতুন রানার বুট করে, তাই মাঝে
    `session_write_close()` না দিলে পরের রিকোয়েস্ট খালি সেশন পায়।
- Twig `auto_reload` `APP_ENV`-এর ওপর নির্ভর করে, আর `.env`-এ `APP_ENV=prod`
  হওয়ায় **বন্ধ**। ভিউ এডিট করলে `rm -rf runtime/twig` না দিলে পরিবর্তন নীরবে
  থেকে যায়।
- টেস্ট bootstrap `.env` লোড করে না — VAPID-এর মতো ক্ষেত্রে নিজে লোড করতে হয়।
- নোটিফিকেশন গেট: `NotificationManager::wantsChannel()` — telegram/whatsapp এখন
  প্রোফাইলের কন্টাক্ট কলাম দেখে (ধাপ ৪); এডমিন ফ্যান-আউট এটি বাইপাস করে।
- টেস্টে রো বানিয়ে পড়ার সময় FK অর্ডার মনে রাখতে হয়: `bot_connection.user_id` →
  `user.id`, তাই `bot_connection` আগে মুছতে হয়, তারপর `user`। উল্টালে পুরো sweep
  অর্ধেক থেমে যায়।
- `whatsapp` চ্যানেলের কোনো ড্রাইভার নেই — `NotificationWorkCommand::deliver()`-এ
  unknown channel হলে permanent dead-letter; ধাপ ৪-এ `WhatsAppChannel` যোগ করতে হবে।
- সেটিংস: `/admin/settings` রুট এখন `AdminMiddleware` গ্রুপে (সব স্টাফ);
  `SuperAdminMiddleware` গ্রুপে সরাতে হবে (ধাপ ৬)। সাইডবারেও রোল অনুযায়ী দেখাতে হবে।
- ডোমেইন: `IdentityViewInjection::siteUrl` সবসময় `APP_URL` থেকে; রিকোয়েস্ট-হোস্ট
  অনুযায়ী হলে সাবডোমেইনে ক্যাননিকাল/লিংক ভাঙবে (ধাপ ৭)। `NoIndexMiddleware`
  ইতিমধ্যে হোস্ট-নিরপেক্ষ, শুধু `siteUrl` বদলাতে হবে।
