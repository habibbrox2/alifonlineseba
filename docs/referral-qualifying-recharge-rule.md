# রেফারেল "৫টি সফল রিচার্জ" নিয়ম — ইমপ্লিমেন্টেশন নোট

> **সারসংক্ষেপ:** আগে রেফারেল বোনাস বন্ধুর *প্রথম* অনুমোদিত রিচার্জেই পরিশোধ হতো (একটি ছোট টপ-আপে ৳৫০ — মানে লুকানো সাইনআপ বোনাস)। এখন নিয়মটি হলো: বন্ধুকে
> `referral_required_recharges` (ডিফল্ট **৫**) টি **অনুমোদিত** রিচার্জ করতে হবে, প্রতিটির পরিমাণ `referral_min_qualifying_recharge` (ডিফল্ট **৳১০০**) বা তার বেশি হতে হবে।
> ৫ম qualifying রিচার্জ অনুমোদনের সাথে সাথে রেফারেল `paid` হয় এবং **একবারই** রেফারার ৳৫০ + বন্ধুর ৳২০ ব্যালেন্সে যোগ হয়।
> নিয়মটি রেফারেল তৈরির সময়ই রেফারেল রোডে স্ন্যাপশট হয়, তাই অপারেটর পরে সেটিংস বদলালেও চলমান রেফারেলের শর্ত বদলায় না।

---

## ১. ডাটাবেস — `migrations/M240120000000_ReferralQualifyingRechargeRule.php`

`referral` টেবিলে ছয়টি নতুন কলাম ও একটি ইনডেক্স যোগ হয়েছে:

| কলাম | টাইপ | ডিফল্ট | কাজ |
|---|---|---|---|
| `required_count` | `INT NOT NULL` | `5` | সাইনআপের সময়ের শর্ত — কতটি qualifying রিচার্জ লাগবে |
| `completed_count` | `INT NOT NULL` | `0` | এখন পর্যন্ত সম্পন্ন qualifying রিচার্জের সংখ্যা |
| `min_amount` | `DECIMAL(12,2) NOT NULL` | `100.00` | প্রতিটি রিচার্জের সর্বনিম্ন যোগ্য পরিমাণ (স্ন্যাপশট) |
| `qualified_at` | `DATETIME NULL` | `NULL` | কখন শর্ত পূরণ হলো |
| `paid_referrer_amount` | `DECIMAL(12,2) NOT NULL` | `0.00` | প্রকৃতপক্ষে যে পরিমাণ credit হয়েছে |
| `paid_referee_amount` | `DECIMAL(12,2) NOT NULL` | `0.00` | প্রকৃতপক্ষে যে পরিমাণ credit হয়েছে |

সাথে `ix_referral_progress (status, completed_count, required_count)` ইনডেক্স — অ্যাডমিন লিস্টের সর্ট/ফিল্টারের জন্য।

মাইগ্রেশন এছাড়াও দুটি সেটিংস সিড করে (`INSERT … SELECT … WHERE NOT EXISTS`, তাই পুনরায় চালালেও নিরাপদ):
`referral_required_recharges = 5`, `referral_min_qualifying_recharge = 100`।

**ব্যাকফিল:** আগে থেকে `status = 'paid'` রেফারেলগুলোকে `completed_count = required_count` ও `qualified_at = updated_at` দিয়ে চিহ্নিত করা হয়, যাতে পুরোনো পরিশোধিত রেফারেল "অসম্পূর্ণ" দেখাবে না।
বাকি সব রেফারেল `0 / NULL` — কোনো ব্যালেন্স বা লেজার পরিবর্তন হয়নি।

`down()` শুধু স্কিমা (ইনডেক্স + ছয় কলাম) ফেলে এবং **সেটিংস রো সচেক রাখে** — কারণ সেটিংস মানুক ইন্স্টলে-পরে ব্যবহার হতে পারে।

যাচাই (dev DB `th_tools`): ৬/৬ কলাম আছে, ইনডেক্স আছে, ০টি referral রো — কোনো ব্যালেন্স বদলায়নি।

---

## ২. সেটিংস — `src/Repository/SettingsRepository.php`

`KEYS` হলো whitelist (অজানা key চুপচাপ ফেলে দেয়), তাই দুটি নতুন key এখানে রেজিস্টার করা বাধ্যতামূলক:

```php
'referral_required_recharges'          => ['প্রয়োজনীয় সফল রিচার্জ সংখ্যা', '5',   'number'],
'referral_min_qualifying_recharge'     => ['প্রতিটি রিচার্জ সর্বনিম্ন (৳)', '100', 'number'],
'referral_min_first_recharge'          => ['প্রথম রিচার্জ সর্বনিম্ন (৳)',  '100', 'number'], // deprecated
```

`referral_min_first_recharge` রাখা হয়েছে — নতুন key না থাকা ইন্স্টলে সেটিই fallback হিসেবে ব্যবহৃত হয় (upgrade করলে পুরোনো সেটিংয়ের অর্থ বদলাবে না)।

ভ্যালিডেশন (`src/Web/Admin/AdminSettingsAction.php`): `referral_required_recharges` পূর্ণসংখ্যা ১–১০০০ (০ হলে সাইনআপেই বোনাস — তাই বন্ধ), `referral_min_qualifying_recharge` সংখ্যা ০–১০,০০,০০০ (০ মানে কোনো সর্বনিম্ন নেই)।

---

## ৩. বিজনেস লজিক

### `src/Repository/ReferralRepository.php`
* `create()` এখন `required_count` ও `min_amount` স্ন্যাপশট করে (সাইনআপের শর্ত ধরে রাখে)।
* `qualifyingRechargeCount($refereeId, $minAmount)` — **একমাত্র** "qualifying রিচার্জ" সংজ্ঞা: `topup_request.status = 'approved'` **এবং** `amount >= $minAmount`।
* `recordProgress($id, $completed)` — guarded `pending → pending` আপডেট, তাই এটি একই সাথে paid/void হওয়া রেফারেলকে ফিরিয়ে আনতে পারে না।
* `progressFor($refereeId)` — UI-এর জন্য `completed_count`, `required_count`, `remaining`, `qualified`।
* `bonusReference($referralId, 'referrer'|'referee')` — স্ট্যাটিক, deterministic: `REFBONUS-<id>-RR` / `REFBONUS-<id>-RF` (≤৩২ ক্যারেক্টার)।
* `bonusAlreadyPaid()` — deterministic reference আগে credit হয়ে গেছে কি না।
* `SORTABLE`-এ `completed_count` যোগ।

### `src/Service/ReferralService.php`
* পুরোনো `FIRST_RECHARGE_CHECK = 1` সরানো হয়েছে। নতুন: `requiredRecharges()` (≥1, অর্থহীন মানে ৫), `minQualifyingRecharge()` (নতুন key না থাকলে পুরোনো `minFirstRecharge()`), `HARD_MAX_RECHARGES = 1000`।
* `onTopupApproved()` — প্রথমে DB থেকে count করে, `recordProgress()` লেখে, তারপর শর্ত পূরণ হলে `pay()`। Count **বাড়ানো** হয় না, `topup_request` থেকে **গণনা** হয় — তাই ভুল/হাতে-সম্পাদিত ডেটা সত্যিও হলে সংখ্যা সঠিক থাকে।
* `pay()` — দুই স্তরের idempotency:
  1. ledger reference determinate + `transaction.reference` **UNIQUE** → ডাটাবেস দ্বিতীয় credit-এর insert ঠেকায় (concurrency-এও নিরাপদ);
  2. guarded `pending → paid` UPDATE → দুই caller একজনই জিততে পারে; যারা হারে তার credit transaction-এই rollback হয়।
  দুটোই একটি DB transaction-এর ভেতরে, credit আগে লেখা হয় যাতে flip ব্যর্থ হলে rollback করা যায়।
* `credit($userId, $amount, $metadata, $reference)` — reference এখন আবশ্যক; 0 রিফিউজ হলে 0 ফেরত দেয় ("এই পাশের টাকা আগেই দেওয়া")। সব credit `LedgerService::creditUser()`-এর মধ্য দিয়ে যায়, তাই balance + `balance_before/after` + `transaction` রো তিনটিই একসাথে হয় — কোনো bare balance update নেই।
* `summaryText()` — পেজের কপি সবসময় settings-এর সাথে মিলিয়ে লেখে (প্রতিটি রিচার্জের ন্যূনতম পরিমাণ + সংখ্যা + দুই বোনাস)।

### ইন্টিগ্রেশন
`TopupService` ইতিমধ্যেই approve-এর পরে `$this->referrals?->onTopupApproved(...)` কল করে — **কোনো wiring বদলাতেই হয়নি**, শুধু ট্রিগারের অর্থ বদলেছে।

---

## ৪. UI

* `resources/views/site/admin/settings.twig` — দুটি নতুন ফিল্ড ("প্রয়োজনীয় সফল রিচার্জ সংখ্যা", "প্রতিটি রিচার্জ সর্বনিম্ন (৳)"), পুরোনো তিন-ফিল্ডের গ্রিড অপরিবর্তিত।
* `resources/views/site/account/referrals.twig` — "কীভাবে কাজ করে" তালিকা নতুন নিয়মে লেখা (ধাপ ৩-এ সংখ্যা, নতুন ধাপ ৪: pending/bancelled/failed/rejected গোনা হয় না); টেবিলে প্রগ্রেস বার + `4/5` + "আরও ১টি রিচার্জ দরকার" / "শর্ত পূরণ হয়েছে"।
* `resources/views/site/admin/referrals.twig` — সর্টেবল "অগ্রগতি" কলাম (`completed_count`), সেলে `N/M`, ট্রিগার পরিমাণ ও "আরও Nটি দরকার"।
* ব্রাউজারে যাচাই করা হয়েছে (`/admin/settings`, `/admin/referrals`, `/referrals`) — দুই নতুন ফিল্ড `5` ও `100` দেখাচ্ছে, নতুন কপি আছে, কোথাও দ্বিতীণ `৳` (`৳৳`) যোগ হয়নি।

---

## ৫. টেস্ট — `tests/Functional/ReferralQualifyingRechargeTest.php` (নতুন, ১৩টি টেস্ট)

প্রতিটি টেস্ড **সত্যিকারের `TopupService::approve()`** দিয়ে চালায় (ট্রিগার সরাসরি কল নয়) — কারণ এই ফিচারের দাবিই হলো "রিচার্জ অনুমোদন করলেই রেফারেল এগোয়"।

1. পাঁচতম qualifying রিচার্জ দুই পাশকেই একবারই পরিশোধ করে (৪/৫-এ কিছু নয়) — ব্যালেন্স **এবং** লেজার রো দুটোই যাচাই।
2. শর্ত পূরণের পরের রিচার্জ আর পরিশোধ করে না।
3. সর্বনিম্নের নিচের (approved) রিচার্জ গণনা হয় না, তবে শর্ত পূরণ করে না।
4. শুধু `approved` গোনা হয়: rejected ও pending হলে গণনা এগোয় না, পরে পাঁচটি approved এখনও শর্ত পূরণ করে।
5. **Manual payout + পরের রিচার্জ = double-credit না** (admin ভালোবাসায় পরিশোধ করলেও deterministic reference ধাক্কা দেয়)।
6. paid রেফারেলে ট্রিগার রিপ্লে → কিছুই নড়ে না, ঠিক ১টি করে বোনাস রো।
7. দুই পাশের reference আলাদা, স্থায়ী ও নির্বাচিত (টেবিলে `UNIQUE`)।
8. শর্ত সাইনআপে স্ন্যাপশট হয় — অপারেটর পরে ১০/৳৫০০ করলেও চলমান রেফারেল পুরোনো শর্তে শেষ হয়।
9. অর্থহীন সেটিংস (০ / "not-a-number") → ডিফল্ট ৫; ৩ হলে ৩ গ্রহণ।
10. সর্বনিম্ন ০ হলে প্রতিটি approved রিচার্জ গোনা হয়।
11. এক অ্যাকাউন্টে একবারই রেফারেল; দ্বিতীয় কোড attribution overwrite করতে পারে না।
12. `progressFor()` ইউজার পেজের জন্য 4/5, বাকি ১, পরে qualified।
13. রেফারেল নেই এমন অ্যাকাউন্টে ট্রিগার no-op (ত্রুটি নয়)।

টেস্ট ফিক্সচার শুধু তৈরি করে ও `_after()`-এ মুছে ফেলে; সেটিংস ৫টি আগে পড়ে রেখে পরে ফেরত লেখে। যাচাই করা হয়েছে — ডেটাবেসে `rq_%` ইউজার, `RQ%` টপ-আপ, `REFBONUS%` লেজার রো সব `0`, এবং ৫টি সেটিংস আগের মানে ফিরে এসেছে।

---

## ৬. যাচাইয়ের ফলাফল (এই সেশনে চালানো)

| স্যুট | ফলাফল |
|---|---|
| `vendor/bin/codecept run Functional` | **OK — 357 টেস্ট, 2058 অ্যাসার্শন** |
| `vendor/bin/codecept run Unit` | **OK — 162 টেস্ট, 2156 অ্যাসার্শন** |
| `vendor/bin/codecept run Web` (পোর্ট ৮০৯৯ সার্ভার সহ) | **২৬ টেস্ট, ১টি ব্যর্থ** — নিচে দেখুন |

### জানা ব্যর্থতা (রেফারেল কাজের সাথে সম্পর্কহীন, আগের কাজের)

`Web › AdminOrderQueueBulkCest › theExportAnswersWithAFileRatherThanAnotherPage`
→ `/admin/orders?q=AL6AC16A6A5524` নেভিগেশনে **404**, ফলে `checkOption(...)` পাওয়া যায় না।

যা যাচাই করা হয়েছে:

* একই URL **একই সার্ভারে** `curl`, সরাসরি `GuzzleHttp\Client`, এবং raw socket দিয়ে সবসময় **200** দেয় (`input[name="ids[]"][value="1273"]` সহ)।
* রিয়েল ব্রাউজার (Preview) একই পাতা 200 খোলে এবং চেকবক্সটি ঠিক আছে।
* একই পুরো টেস্ট-বডির একটি কপি (`ZzProbe2Cest`) চালালে **পাস** করে; মূল ফাইল চালালে **ব্যর্থ** — অর্থাৎ কোড, ইউজার, URL, সেশন সব এক, শুধু Cest-ফাইল/ক্লাস-নাম আলাদা।
* `usleep(300000)` ঢোকানোর পরেও একই ফল (সময়-নির্ভর রেস নয়)।

অর্থাৎ এটি **অ্যাপ্লিকেশনের বাগ নয়**, বরং এই ad-hoc `php -S` ডেভ সার্ভারের সাথে Codeception `PhpBrowser` কম্বিনেশনের একটি পরিবেশগত অসঙ্গতি (যেখানে একমাত্র ব্যর্থতা ঘটে)। প্রোডাকশন কোডে বা ডেটাতে কোনো পরিবর্তন করা হয়নি, এবং এই কারণে টেস্টটিকে "সবুজ" দেখাতে কোনো অ্যাসার্শন দুর্বল বা স্কিপ করা হয়নি।

---

## ৭. ফাইল তালিকা (এই পরিবর্তনে)

| ফাইল | পরিবর্তন |
|---|---|
| `migrations/M240120000000_ReferralQualifyingRechargeRule.php` | নতুন — ৬ কলাম + ইনডেক্স + ২টি সেটিংস সিড + paid ব্যাকফিল |
| `src/Repository/ReferralRepository.php` | স্ন্যাপশট, `qualifyingRechargeCount()`, `recordProgress()`, `progressFor()`, `bonusReference()`, `bonusAlreadyPaid()`, সর্ট |
| `src/Service/ReferralService.php` | নতুন নিয়ম, প্রগ্রেস লেখা, দুই-স্তরের idempotent `pay()`, নতুন `credit()` সইনেচার |
| `src/Repository/SettingsRepository.php` | ২টি নতুন settings key (whitelist) + পুরোনো key-এর deprecation নোট |
| `src/Web/Admin/AdminSettingsAction.php` | দুটি নতুন key-এর ভ্যালিডেশন |
| `src/Web/Admin/AdminReferralsAction.php` | প্রতি সারি `required/completed/remaining` গণনা |
| `src/Web/Account/ReferralsAction.php` | ইউজার পেজের জন্য একই গণনা |
| `resources/views/site/admin/settings.twig` | ২টি নতুন ফিল্ড |
| `resources/views/site/admin/referrals.twig` | "অগ্রগতি" কলাম |
| `resources/views/site/account/referrals.twig` | নতুন নিয়মের কপি + প্রগ্রেস বার |
| `tests/Functional/ReferralQualifyingRechargeTest.php` | নতুন — ১৩টি টেস্ট |
| `tests/Web/AdminOrderQueueBulkCest.php` | নতুন referral কাজে পরিবর্তন নেই (আগের কাজের ফাইল; শুধু পরিবেশগত ব্যর্থতা, §৬) |

---

## ৮. প্রস্তাবিত পরবর্তী কাজ (Follow-ups)

1. **`REFBONUS` ইনডেক্স/রিপোর্ট** — referral payout-এর একটি ছোট অ্যাডমিন রিপোর্ট যোগ করা (মাসে কতগুলো রেফারেল qualified, কত টাকা bonus দেওয়া হয়েছে, conversion rate), যাতে প্রোগ্রামটির ব্যয় নিয়মিত দেখা যায়।
2. **রেফারেল-প্রগ্রেস ইমেইল/পুশ** — বন্ধু যখন ৪/৫ হবে তখন একটি reminder push ("১টি রিচার্জ বাকি, ৳২০ বোনাস পাচ্ছেন") — এটিই এই নিয়মের আসল ROI।
3. **স্ন্যাপশট ড্রিফট রিপোর্ট** — সেটিংস বদলানোর পরে কতগুলো রেফারেল পুরোনো শর্তে আটকে আছে তা দেখানোর admin-এর পাতা (operator কোন rule-এর অধীনে পুরোনো প্রতিশ্রুতি চলছে)।
4. **রেফারেল রিভার্সাল (admin)** — শর্ত পূরণ না করেই কোনো কারণে (যেমন অ্যাকাউন্ট ভুল হিসাবে যুক্ত) রেফারেল বাতিল বা পুনরায় চালু করার ফ্লো — `reset()`/`reject()` আছে, কিন্তু একটি পূর্ণ audit trail (কে, কেন, কখন) UI-তে নেই।
5. **রেফারেল ফ্রাড ডিটেকশন** — এক ব্রাউজার/এক ডিভাইস থেকে একাধিক অ্যাকাউন্ট রেজিস্টার করে রিচার্জ করা বা নিজেকেই রেফার করার সন্দেহভাজন লগ আছে কি না দেখা এবং সেই লগ থেকে অ্যাডমিন alert।
6. **ওয়েব-স্যুট ব্যর্থতাটি পরিষ্কার করা** — `php -S` ডেভ সার্ভারের বদলে PHP-FPM/Apache (যেমন XAMPP-এর Apache) বা প্রতি টেস্টে আলাদা সার্ভার ব্যবহার করলে `theExportAnswersWithAFileRatherThanAnotherPage`-এর পরিবেশগত 404 চলে যাবে কি না যাচাই করা।
7. **রেফারেল পেজের A/B কপি** — পেজে বর্তমানে "ন্যূনতম ৳X করে Nটি রিচার্জ" বলা হচ্ছে; শর্ত পূরণ হওয়ার সম্ভাবনা বাড়াতে প্রথম রিচার্জের জন্য একটি নির্দিষ্ট "প্রথম পদক্ষেপ" টেক্সট (যেমন "প্রথমে ৳১০০, পরে ৫০০ × ৪") দেখানো।