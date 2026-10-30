# বাগ সমগ্র ও সমাধানের প্ল্যান — ডিজিটাল সেবা / আলিফ টুলস

## ১. খোঁজ নেওয়া κι-এইরিয়া

নিচের স্ক্রিন ও সাবসিস্টেমগুলো দেখা extremo গেছে:

|وهيতা | ডিরেক্টরি |
|------|-----------|
| স্টাফ | `resources/views/site/admin/staff.twig`, `src/Web/Admin/AdminStaffAction.php` |
| ইউজার | `resources/views/site/admin/users.twig`, `AdminUserEditAction.php` |
| অ্যাক্টিভিটি লগ | `resources/views/site/admin/logs.twig`, `AdminLogsAction.php` |
| অর্ডার | `resources/views/site/admin/orders.twig`, `order.twig`, `AdminOrdersAction.php`, `AdminOrderAction.php` |
| রিচার্জ | `resources/views/site/admin/recharge.twig`, `topups.twig`, `AdminRechargeAction.php`, `AdminTopupsAction.php` |
| উইথড্র | `resources/views/site/admin/withdraws.twig`, `withdraw-desk.twig`, `AdminWithdrawsAction.php` |
| সার্ভিস | `resources/views/site/admin/services.twig`, `AdminServicesAction.php`, `AdminServicesApiAction.php` |
| সেটিংস / রোল hiérarcy / পারমিশন | `settings.twig`, `role-hierarchy.twig`, `permissions-matrix.twig` |
| নোটিফিকেশন | `notifications.twig`, `AdminNotificationsAction.php` |
| এন্ড্রয়েড অ্যাডমিন অ্যাপ | `android-admin/app/src/main/java/com/aliftools/admin/` |

সেশন/অথ/auth फ्लো audit: `src/Auth/AuthMiddleware.php`, `IdentityRepository.php`, `SecureCookieSession.php`, `session.php` (DI), `config/common/routes.php`।

---

## ২. খুঁজে পাওয়া বাগসমূহ (ব elongation severity অনুযায়ী)

### উচ্চ গুরুত্ব (HIGH)

**H1 — `use_strict_mode = 1` + সার্ভার-সাইড সেশন মুছে ফেললে পরোক্ষ লগআউট**
- `config/common/di/session.php:24` — `'use_strict_mode' => '1'`
- গার্ভেজ কালেকশন, ডেপ্লয়মেন্টে `runtime/` cleared, বা দুটি ওয়েব সার্ভারে সেশন শেয়ার না হলে `session_start()` নতুন খালি সেশন আইডি তৈরি করে। `AuthMiddleware` দেখে `user_id = null` → লগিন পেজে পাঠিয়ে দেয়। ব্যবহারকারী মাঝে মাঝে অপ্রত্যাশিতভাবে লগআউট হয়।

**H2 — AuthMiddleware ডিলিট/ডিএক্টিভেট করা ব্যবহারকারীর লাইভ সেশন বন্ধ করে দেয়**
- `src/Auth/AuthMiddleware.php:69-71` — ইউজার ট্র্যাশ করা হলে বা স্ট্যাটাস `active` না হলে সেশন থেকে `user_id` মুছে ফেলা হয়। সেই ব্যবহারকারীর অন্য ট্যাবে আগের স্ক্রিন খোলা থাকলেও পরবর্তী রিকোয়েস্টে লগিন পেজে রিডাইরেক্ট হয়।

**H3 — Secure কুকি ফ্ল্যাগ http/https মিক্সে কুকি ব্লক হয়ে লগআউট**
- `src/Web/SecureCookieSession.php:27-33` — রিকোয়েস্ট স্কিম অনুযায়ী `secure` ফ্ল্যাগ সেট হয়। एंड্রয়েড/TWA অথবা প্রক্সি স্ট্যাচুস ভুল হলে ব্রাউজার সিকিউর কুকি পাঠায় না → সেশন পাওয়া যায় না → লগিন পেজে চলে যায়। vendor `SessionMiddleware::commitSession()` এ `cookie_secure=true` এবং স্কিম নন-https হলে 500 এরর দিতে পারে।

### মধ্যম গুরুত্ব (MEDIUM)

**M1 — রিচার্জ রিজেক্টের.confirm না থাকা ও রiesen খালি থাকা**
- `resources/views/site/admin/recharge.twig:196, 213` — রিজেক্ট বাটনে কোনো কনফার্মেশন ডায়ালগ নেই, রiesen টেক্সটರಿಯা `required` ছিল না। ভুল ক্লিক করলে খালি রiesenসহ রিজেক্ট চলে যেত।

**M2 — রেফারেল রিজেক্টের confirm না থাকা**
- `resources/views/site/admin/referrals.twig:188-201` — পে/রিসেটের উভয়েরই কনফার্মেশন আছে কিন্তু রিজেক্টের নেই। সঠিক ফরম্যাটে রiesen চেয়া যায়নি।

**M3 — `users.twig` এর বাটন টাইটেলে "filled" শব্দ বাকি থাকা**
- লাইন 210, 219, 231, 242 — `title="এডমিন হিসেব filled"` প্রভাবিত।

**M4 — স্ট্যাটাস টগল বাটনের Swich UX তিন স্ক্রিনে নেই**
- `users.twig`, `staff.twig`, `categories.twig` এ সাধারণ টেক্সট বাটন আছে, সার্ভিস स्क্রিনে যা সwitch প্যাটার্ন আছে সেটি নেই।

### নিম্ন গুরুত্ব (LOW)

**L1 — স্ক্রিনএন্ট্রিতে নাল আইডেন্টিটি ডিরেফারেন্স → 500/ব্ল্যাংক**
- ড্যাশবোর্ড, প্রোফাইল, রিচার্জ, ট্রানজেকশন, রেফারেল, নোটিফিকেশন, সার্ভিস 히стори, অর্ডার, উইথড্র এ্যাকশনে `$identity->id` ডিরেফারেন্স নাল চেক ছাড়া। বর্তমানে 미ডলওয়্যার গার্ড থাকায় নিরাপদ, কিন্তু নতুন রুট মিসলে ভেঙে যাবে।

**L2 — `AuthMiddleware` প্রতিটি অথ-গার্ডেড রিকোয়েস্টে `RoleRankRepository::all()` কল করে**
- ডাটাবেজ কানেকশন ব pérdida হলে সব প্রোটেক্টেড স্ক্রিন 500 করে।

**L3 — স uptime মেইনটেন্যান্স মোডে গ্রাহক স্ক্রিন 503**
- ডিজাইনের বাইরে কিছু নয়, ব exatamente "স্ক্রিন খোলা যায় না" সমস্যা তৈরি করতে পারে।

---

## ৩. ইতিমধ্যে সমাধান করা (M1–M4) — Applied

নিচের পাঁচ টুইগ ফাইল সম্পাদিত হয়েছে (git diff تأیید করে):

| ফাইল | সমাধান |
|------|---------|
| `resources/views/site/admin/recharge.twig` | `reason` টেক্সটറിയায় `required` যোগ; রিজেক্ট বাটনে `data-confirm` (tone `money`) যোগ |
| `resources/views/site/admin/referrals.twig` | রিজেক্ট ফর্মে `data-confirm` যোগ |
| `resources/views/site/admin/users.twig` | ৪টি বাটন টাইটেল থেকে "filled" সরিয়ে "হিসেব করুন" লিখে দিয়ে; স্ট্যাটাস টগলকে `role="switch` বাটনে রূপান্তর |
| `resources/views/site/admin/staff.twig` | স্ট্যাটাস টগলকে `role="switch` বাটনে রূপান্তর (`do=status`, `status` পেয়ার বজায়) |
| `resources/views/site/admin/categories.twig` | স্ট্যাটাস টগলকে `role="switch` বাটনে রূপান্তর (`do=toggle` প্যাটার্ন) |
| `resources/views/site/admin/services.twig` | পূর্বেই স্ট্যাটাস টগল switch বাটনে রূপান্তর করা হয়েছিল |

php -l যাচাই: `config/common/di/session.php`, `src/Auth/AuthMiddleware.php`, `src/Web/SecureCookieSession.php`, `config/common/routes.php` — সিনট্যাক্স ত্রুটি নেই।

### যাচাই করাণ
- `TopupService::reject` (`src/Service/TopupService.php:310-312`) — খালি reason দিলে এরর রিটার্ন করে → `required` সঠিক।
- `ReferralService::reject` (`src/Service/ReferralService.php:347-350`) — একই জন্য non-empty reason প্রয়োজন → সঠিক।

---

## ৪. বাকি সমাধানের প্ল্যান (Prathomik Prokriti)

### ধাপ ১ — সেশন/লগআউট বাগ (H1, H2, H3)

| নং | সমস্যা | সম্পাদন | ফাইল | পদ্ধতি |
|----|--------|------|-------|--------|
| H1 | `use_strict_mode` সেশন হারালে লগআউট |、`session.php` DI | `config/common/di/session.php` | `use_strict_mode` বন্ধ করা অথবা ফলব্যাক: নতুন সেশন হলে লগিন পেজের বদলে কেবল গেস্ট হিসাবেই работать করবে; বর্তমানে গেস্ট রিকোয়েস্ট পেয়ে AuthMiddleware লগিন পেজে রিডাইরেক্ট করে। কনফিগেরované ডিফল্ট ব্যবহার করা নিরাপদ। |
| H2 | ইউজার ডিলিট/ডিএক্টিভ হলে লাইভ সেশন বন্ধ | `AuthMiddleware.php` | `src/Auth/AuthMiddleware.php` | ডelijeted/disabled ইউজারের ক্ষেত্রে সেশন মুছে না দিয়ে কেবল 403/লগিন পেজে পাঠানো হোক; অথবা অ্যাকশন নেওয়ার সময় সেই ইউজারের সেশন invalidate করা ডকুমেন্ট করা হোক। |
| H3 | Secure কুকি http/https মিক্স | `SecureCookieSession.php` | `src/Web/SecureCookieSession.php` | `cookie_secure` ফ্ল্যাগ অপেক্ষাকৃত রিকোয়েস্ট-স্পেসিফিক থাকুক কিন্তু `X-Forwarded-Proto` হেডার〕

### ধাপ ২ — আইডেন্টিটি নাল গার্ড (M1, L1)

- যেসব এ্যাকশনে `$identity->id` নাল চেক ছাড়া আছে, সেখানে গার্ড যোগ করা বা নতুন রুট যোগ করার সময় 미дলওয়্যার গার্ড নিশ্চিত করা।
- ফাইল: `DashboardAction.php`, `ProfileAction.php`, `RechargeAction.php`, `TransactionsAction.php`, `ReferralsAction.php`, `NotificationsAction.php`, `ServiceDetailAction.php`, `ServiceHistoryAction.php`, `ReceiptAction.php`, `AvatarAction.php`, `RechargeCancelAction.php`, `AdminOrdersAction.php`, `AdminWithdrawsAction.php`, `AdminSettingsAction.php`।

### ধাপ ৩ — এন্ড্রয়েড অ্যাডমিন অ্যাপ

সাবএজেন্ট রিপোর্ট অনুযায়ী (বાકি React/Jetpack Compose স্ক্রিনে স্ক্রিন-ক্র্যাশ ও লগআউট বাগ):
- টোকেন রিফ্রেশ/রietro লজিক (`AdminRepository`, `data/api/`) — 401 হলে রিফ্রেশ টোকেনের একবার রietro করা, ব্যর্থ হলে লগিন পেজে পাঠানো।
- secured storage থেকে টোকেন পড়া (`data/local/`) — নাল টোকেন হলে ক্র্যাশ না করে লগিন স্ক্রিনে পাঠানো।
- নেভিগেশন গার্ড (`AdminNavGraph`, `RoleCapabilities`) — রোল অনুযায়ী স্ক্রিন খোলার অনুমতি।
- স্ক্রিন ভিউমডেল (`ui/viewmodel/*`) — এরর হ্যান্ডলিং, লোডিং স্টেট, ব্ল্যাংক স্ক্রিন এড়ানো।

---

## ৫. সার্ভিস ম্যানেজমেন্ট UI/UX আপডেট (পরিকল্পনা)

ইতিমধ্যে `services.twig` এ নিচের ফিচারগুলো কাজ করছে:
- সার্ভিস তৈরি (`do=create`)
- সার্ভিস এডিট (`do=update`)
- স্ট্যাটাস পরিবর্তন switch বাটন (`do=toggle`)
- মুছে ফেলা / ট্র্যাশ / রিস্টোর / পারম্যানেন্ট ডিলিট (`do=delete`, `restore`, `purge`)
- ফর্ম ফিল্ড কনফিগারেশন (`do=fields`, `fields_reset`)
- বাল্ক অ্যাকশন (`bulk`, `bulk_undo`)

### poursuivit পরিবর্তন (পরদিনের Sprint)

১. **সwitch বাটনের অ্যাক্সেসিবিলিটি:** `aria-checked`, `role="switch`, title/aria-label restantes tümска三টির `(users, staff, categories)` উভয়ে জোড়া দেয়া হয়েছে।
২. **ভ্যারিয়েন্ট এডিট আপডেট:** বর্তমানে ভ্যারিয়েন্ট রোগে অল 공동 অপশন আছে; নতুন রো যোগ, ডিলিট, এবং প্রাইস এডিটের সহজ UX থাকবে।
৩. **ক্যাটাগরি ফিল্টার ও সর্ট:** ফিল্টার বারে ক্যাটাগরি, স্ট্যাটাস, ট্র্যাশ ফিল্টার bereits আছে; সর্ট অর্ডার কলাম hinzu করা যেতে পারে।
৪. **বাল্ক অ্যাকশন আন্ডু:** `bulk_undo` অপশনটি রয়েছে; ড্রাফট আন্ডু মেসেজ উন্নত করা যেতে পারে।
৫. **মোবাইল রেসপন্সিভনেস:** টেবিলের Rowland.mobile ভিউতে হরিজন্টাল স্ক্রল (`overflow-x-auto`) আছে; টগল বাটন ছোট স্ক্রিনে ট্যাচ-ফ্রেন্ডলি করা উচিত।

---

## ৬. পরীক্ষা ও যাচাই (যা अभी laufen হবে না)

- `php -l` টুইগ ফাইলের জন্য প্রযোজ্য নয় (টুইগ PHP নয়), তবু PHP ফাইলের সিনট্যাক্স চেক করা হয়েছে।
- `npm run build` — টুইগ changementsি কি এসেট বিল্ডের প্রয়োজন নেই, তাই বিল্ড প্রয়োজন নেই।
- এন্ড্রয়েড ইউনিট টেস্ট: `.\gradlew.bat testDebugUnitTest` (JDK 17+) — এখন নেই, পরের ধাপে।
- ফাংশনাল টেস্ট (Codeception): requires test database, এখন নেই।

---

## ৭. ফলাফল সারাংশ

- **ফাইল পরিবর্তিত:** ৫ টি টুইগ টেমপ্লেট (`categories.twig`, `recharge.twig`, `services.twig`, `staff.twig`, `users.twig`) — ৪৮ লাইন যোগ, ১৮ লাইন দূর করা।
- **কী কী সমস্যা সমাধান:** রিচার্জ/রেফারেল রিজেক্ট কনফার্মেশন, "filled" টাইটেল typo, স্ট্যাটাস টগল switch UX (৪টি স্ক্রিনে), রiesen required ভ্যালিডেশন।
- **কী কী বাকি:** সেশন লগআউট বাগ (H1, H2, H3), আইডেন্টিটি নাল গার্ড (L1), এন্ড্রয়েড অ্যাডমিন অ্যাপ স্ক্রিন ক্র্যাশ/লগআউট বাগ, সার্ভিস UI/UXের বাকি আপডেট।

আপনি চাইলে আমি এই প্ল্যান অনুযায়ী 다음 ধাপে বাকি বাগগুলোও Application করে দিতে পারব। কোন ধাপটি আগে শুরু করবেন বলুন।
