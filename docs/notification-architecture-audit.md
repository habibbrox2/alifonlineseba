# OnlineSheba — Communication & Notification Architecture Audit

> **Audit-only deliverable.** No source file, migration, dependency, route or env var
> was created or modified while producing this document. Branch: `main`.
> Every claim below is cited against the working tree as it exists in this checkout.

---

## 1. Executive Summary

**What OnlineSheba actually is today:** a **Yii 3** (not Yii 2) PHP 8.2–8.5 monolith
built on the `yiisoft/app` template — PSR-15 middleware, FastRoute routing, Twig templates,
Tailwind CSS compiled at build time, vendored Alpine.js, Lucide icon sprite, MySQL 8 via
`yiisoft/db-mysql`. It targets **shared hosting** (cPanel/Apache): compiled static assets,
PSR-16 file cache, file-backed sessions, and explicitly **no Redis, no Node, no resident
worker** in production (`docs/architecture-plan.md:5`, `docs/deployment.md`).

**The single most important finding:** an in-app notification system **already exists** and
is production-worthy for the in-app channel. It is *not* greenfield.

```
src/Repository/NotificationRepository.php   ← the entire notification system: 5 methods, 1 table
migrations/M240101000000_CreateCoreTables.php:95-105   ← {{%notification}} table
```

It is currently **user-facing only**. `grep -rn "notifications->create" src` returns exactly
**six call sites, all notifying a `$user->id` customer** — `src/Service/ServiceManager.php:280,
331, 364, 432` and `src/Service/TopupService.php:237, 295`. **No admin is ever notified.**
The recharge review queue at `/admin/topups` is poll-only; `src/Web/Admin/AdminDashboardAction.php`
shows a pending counter but nothing alerts a reviewer that a payment is waiting.

**Four hard blockers shape the roadmap (details in §27):**

1. **There is no machine-to-machine auth.** `src/Auth/ApiAuthMiddleware.php` delegates to
   `AuthMiddleware`, which reads `$session->get('user_id')`. The entire `/api/*` group
   (`config/common/routes.php:78-89`) is **session-cookie-only**. An Android APK cannot log
   in. This is Phase 1 work, not Phase 2 — nothing mobile can be built before it exists.
2. **There is no queue, no cron, and no worker.** `config/configuration.php:35` has
   `'events' => []`. The only console commands are `src/Console/HelloCommand.php` and
   `src/Console/SeedCommand.php`. All six notifications are written **synchronously inside
   the balance-mutating request path** — adding a WhatsApp HTTP call there would put a
   third-party network timeout on the critical path of money movement.
3. **No outbound HTTP client is wired.** `yiisoft/http` is installed (transitively, for
   `yii-http`) but `grep -rn "HttpClient\|ClientInterface\|curl" src config` returns zero
   results. There is no PSR-18 client in the DI container.
4. **No Firebase, no FCM, no PWA, no service worker, no Telegram, no bot, no email, no
   SMS.** Confirmed by exhaustive grep across `src`, `config`, `resources`, `migrations`.
   The only trace is two display-only URL settings — `whatsapp_url` and `telegram_url` in
   `src/Repository/SettingsRepository.php:23-24` — rendered as a floating support link.

**Recommendation in one line:** extend the existing `NotificationRepository` into a
`NotificationManager` + channel-driver design, add a database-backed queue drained by a
cron-invoked console worker, add token (bearer) auth for the API before anything mobile,
then add channels in order: in-app → FCM → Telegram → WhatsApp → interactive bot.

---

## 2. Existing Architecture

| Layer | Actual technology | Evidence |
|---|---|---|
| Framework | **Yii 3** (`yiisoft/app` 1.4) | `composer.json` `extra.config-plugin-file` |
| PHP | `8.2 - 8.5` | `composer.json` `require.php` |
| HTTP entry | `public/index.php` → `Yiisoft\Yii\Web\Application` | `public/`, `config/configuration.php` |
| Routing | `yiisoft/router` + `yiisoft/router-fastroute` | `config/common/routes.php` |
| Middleware | PSR-15 pipeline, DI-resolved by class name | `config/common/routes.php` groups |
| Views | Twig (`yiisoft/view-twig`), templates in `resources/views` | `resources/views/layouts/*.twig` |
| DB | MySQL 8, `ConnectionInterface` injected into every repository | `src/Repository/*` constructors |
| Migrations | `yiisoft/db-migration`, plain PHP classes in `migrations/` | `M240101000000_CreateCoreTables.php` |
| Auth | Session cookie + `{{%user}}.role` column | `src/Auth/AuthMiddleware.php`, `AdminMiddleware.php` |
| CSRF | `yiisoft/csrf` | `composer.json` |
| Throttling | File-backed sliding window | `src/Auth/AuthThrottle.php` |
| Audit log | `{{%activity_log}}` table + `ActivityLogRepository` | `src/Repository/ActivityLogRepository.php` |
| Assets | Tailwind (build-time) + vendored JS, no Node in prod | `docs/architecture-plan.md:5` |
| Tests | Codeception (Unit / Functional / Web / Console) | `tests/`, `codeception/c3` |

**Request flow today:**

```
public/index.php
  → Yiisoft\Yii\Web\Application
  → middleware (ErrorHandler, Session, Csrf, Flash, SecurityHeaders, ForwardedProto)
  → config/common/routes.php  (Group::create()->middleware(...))
  → src/Web/**/*Action.php::__invoke(ServerRequestInterface, CurrentRoute)
  → src/Service/* (business logic)  →  src/Repository/* (SQL)
  → Twig render (src/Twig/TwigExtension.php supplies helpers)
```

**Key architectural conventions to follow**

- Actions are **invokable classes** with constructor DI; no controllers, no base class.
- Repositories are **final classes** taking `ConnectionInterface` and using raw SQL with
  `{{%table}}` and `[[column]]` quoting.
- Services are **final** and take repositories + collaborators via constructor.
- Tests are Codeception **Cest/Test** classes under `tests/`.
- Business copy is **Bengali**; identifiers and code are English.

---

## 3. Current Service Request Flow

Traced end to end, with exact call sites.

### 3.1 Submission

```
User opens /services/view/{slug}
  → src/Web/Services/ServiceDetailAction.php::__invoke()
  → renders form built from ServiceManager::formFieldConfig()  (ServiceManager.php:131)

User POSTs the form
  → src/Web/Services/ServiceDetailAction.php
  → src/Service/ServiceManager::submit()          (ServiceManager.php:236)
      1. pickConfiguredInput()                     — whitelists the configured fields
      2. required-field validation → ServiceResult::fail($fieldErrors)
      3. UserRepository::adjustBalance($user->id, -$price)   ← MONEY MOVES HERE
      4. ServiceManager::newReference()
      5. TransactionRepository::create([... status = StatusPresenter::PENDING ...])
      6. NotificationRepository::create($user->id, 'অনুরোধ গৃহীত হয়েছে', …)  ← line 280
      7. ActivityLogRepository::create('service.submit', …)
  → back to ServiceDetailAction → Twig
```

### 3.2 Execution

```
/service-history  →  src/Web/Account/ServiceHistoryAction.php
  POST /api/service-requests/{id}/{action}
  → src/Web/Api/ServiceRequestApiAction.php::__invoke()      (routes.php:83)
  → ServiceManager::start()      (line 302)  → status PROCESSING, notify user (line 331)
  → ServiceManager::retry()      (line 385)
  → ServiceManager::cancel()     (line 352)  → refund, notify user (line 364)
  → provider: src/ServiceProvider/{MockNidService,MockTinService,MockVoterService}.php
       via ServiceProviderInterface → ServiceResult
  → settle: COMPLETED or FAILED(+refund), notify user (line 432)
```

Status vocabulary is centralised in `src/Service/StatusPresenter.php`:
`PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`, `CANCELLED` (lines 17-21).

### 3.3 Recharge (the money path)

```
/recharge → src/Web/Account/RechargeAction.php
  → TopupService::request()                (TopupService.php:80)
      validates amount within SettingsRepository::topup_min_amount/max_amount
      stores the receipt via src/Service/ReceiptStorage.php
      INSERT {{%topup_request}} (status = PENDING)
  → admin sees it at /admin/topups          (src/Web/Admin/AdminTopupsAction.php)
  → TopupService::claim()      (line 368)  — soft claim, claimed_by/claimed_at
  → TopupService::approve()    (line 197)  — adjustBalance(+, amount) then notify (line 237)
  → TopupService::reject()     (line 269)  — reason is MANDATORY, notify user (line 295)
  → TopupService::cancel()     (line 331)  — user-side
  → TopupService::approveMany()(line 435)  — bulk approve
```

> **Architectural note for notifications:** every one of these state changes already writes
> an `{{%activity_log}}` row. That log is a ready-made, *already-idempotent* event source.
> Phase 1 should emit domain events from exactly these six points — no new call sites needed
> beyond inserting a dispatcher beside the existing `notifications->create()` calls.

---

## 4. Existing Notification Infrastructure

**Verdict: reuse and extend. Do not rebuild.**

| Capability | Status | File |
|---|---|---|
| In-app storage | **Exists** | `src/Repository/NotificationRepository.php` |
| In-app read/unread | **Exists** (`forUser`, `unreadCount`, `markRead`, `markAllRead`) | same |
| Web UI (`/notifications`) | **Exists** | `src/Web/Account/NotificationsAction.php` |
| JSON API | **Exists** (`GET /api/notifications`, `PATCH …/read`, `POST …/read-all`) | `src/Web/Api/NotificationsApiAction.php` |
| Header bell + unread badge | **Exists** | `resources/views/layouts/dashboard.twig` |
| Live push (in-app polling) | **Partially** — `resources/js/app.js` has a notification poller | `resources/js/app.js` |
| Email | **Absent** | — |
| SMS | **Absent** | — |
| WhatsApp | **Absent** (support hyperlink only) | `SettingsRepository.php:23` |
| Telegram | **Absent** (support hyperlink only) | `SettingsRepository.php:24` |
| FCM / push | **Absent** | — |
| Queue / worker / cron | **Absent** | `config/configuration.php:35` `'events' => []` |
| Retry / backoff / delivery log | **Absent** | — |
| Per-user preferences | **Absent** | — |
| Admin-side notifications | **Absent** | — |

**What must change about the existing code**

1. `NotificationRepository::create()` returns only the insert id and cannot be extended to
   fan out. Keep the method (six call sites + two live tests depend on it) and add a
   `NotificationManager` **above** it that writes the in-app row *and* enqueues channel jobs.
2. Message copy is **hardcoded Bengali strings at the call site**. Multi-channel delivery
   needs templates, so copy should move into a `notification_template` table keyed by event
   name, with the existing literals kept as the fallback for one release cycle.
3. The table has **no `event`, no `channel`, no `link`, no `priority`, no `read` per channel**
   columns. Add them by migration — do not create a second notifications table.
4. `{{%notification}}` has no FK cascade policy and no `expires_at`. Add retention.

---

## 5. Firebase / FCM Audit

**Finding: Firebase is not used for anything.** `grep -rni "firebase\|fcm\|apns\|webpush"`
across `src`, `config`, `resources`, `migrations` returns **zero** matches. There is no
`firebase-admin` dependency, no service account, no device-token table.

**What must be added (all server-side only):**

```
composer require kreait/firebase-php   (maintains the official FCM v1 REST client)
```

- **Server credential:** a Firebase service-account JSON stored **outside the web root**
  (shared hosting: `/home/user/private/firebase-service-account.json`, path from
  `Env::get('FIREBASE_CREDENTIALS_PATH')`). Never in `public/`, never committed.
- **Authentication already exists** — `src/Auth/AuthService.php` + `src/Auth/Identity.php`
  own login/registration. FCM needs nothing new here; device registration is a
  *post-login* call.

**`notification_devices` is the right table and does not yet exist.** The brief's proposed
shape is right; the only change I'd make is adding `user_id` FK + a unique index on
`device_token` (a token can move between accounts on a shared family device, and FCM's own
docs require de-duplication) and a `platform` enum check.

**Token lifecycle rules that must be honoured:**

| Event | Action |
|---|---|
| Login | `INSERT … ON DUPLICATE KEY UPDATE last_seen_at` |
| App launch / resume | upsert `last_seen_at` |
| `UNREGISTERED` / `INVALID_ARGUMENT` from FCM | set `is_active = 0` — never retry |
| Logout | deactivate the current device only (`/api/devices/current` or all for a user) |
| Token rotation | unique index makes the upsert idempotent |

**Web (`webpush`) is not recommended for this project.** There is no service worker, no
manifest, and `resources/js/app.js` is deliberately build-free. Skip the Web platform in
Phase 2; Android + optional iOS later.

---

## 6. Android APK Recommendation

### 6.1 Options compared

| Criterion | A. Native Kotlin | B. Flutter | C. React Native | D. WebView wrapper | E. PWA + TWA |
|---|---|---|---|---|---|
| Existing frontend reuse | none | none | none | total, but crippled | total |
| FCM support | first-class | first-class (plugin) | first-class (needs firebase SDK) | poor, no real background | good (TWA) |
| Background handling | excellent | good | good | **none** | none |
| Camera / receipt upload | excellent | good | good | **broken by default** | none |
| Repo has a matching toolchain | no (no Gradle) | no (no Dart) | **no (no Node toolchain in prod)** | trivial | trivial |
| Build/deploy complexity | high | high | high | very low | very low |
| APK size | ~8 MB | ~18 MB | ~25 MB | ~4 MB | ~3 MB |
| Play Store long-term fit | excellent | excellent | good | poor | **cannot list a TWA on Play** |

### 6.2 Recommendation: **Option A — native Android / Kotlin**, with FCM

Rationale grounded in this repository:

- The backend already exposes a clean JSON API surface (`config/common/routes.php:78-89`:
  dashboard, services, service-by-slug, transactions, service-request actions,
  notifications, profile). A native client maps onto it one-to-one with no rewrite.
- The app's hardest UI requirement is **receipt photo capture and upload**
  (`TopupService::request()` consumes an `UploadedFileInterface`; receipts are stored by
  `src/Service/ReceiptStorage.php`). Camera + multipart + background upload is native
  Kotlin's core competency and the weakest part of a WebView wrapper.
- Flutter/React Native would add a second toolchain (Dart toolchain / Node) to a project
  that is **deliberately build-free in production** (`docs/architecture-plan.md:5`) and
  whose web assets are committed compiled output. That is a maintenance burden, not a win.
- A TWA is the cheapest *pilot* — it would let you validate deep links and FCM within days
  — but it is **not** the end state: it cannot ship to Play as a differentiated app, it
  cannot own a notification permission prompt, and its WebView cannot upload a camera file
  to `/recharge` reliably.

**Practical recommendation:** ship a **native Kotlin app** (`minSdk 24`, Kotlin 2.x,
Jetpack Compose, Retrofit/OkHttp, Hilt-lite manual DI, FCM). Keep the two source trees
(`src/` PHP, `android/`) independent; the contract is the JSON API only. Treat the API as
versioned from day one.

---

## 7. Deep Linking Design

**Goal:** tap on a push → land on the exact screen.

```
FCM data payload { "event": "service_request.completed", "tx_id": 10245 }
        ↓
Kotlin MainActivity reads intent extras
        ↓
NavController.navigate(Route.ServiceDetail(txId = 10245))
```

**Required change:** the existing API exposes requests by **numeric id only inside the
action route** (`POST /api/service-requests/{id}/{action}`), and there is **no
`GET /api/service-requests/{id}`**. A push cannot deep-link to a detail screen that the
API cannot render. Add:

```
GET  /api/service-requests/{id}        → 200 + transaction row, 404 if not owned by caller
```

and emit a **stable public reference** in every push payload (e.g. `AL-8F2A19C3`), never
the raw auto-increment `id` — that is internal enumeration and leaks row counts
(requirement 14 of the brief; the repository already generates human references via
`ServiceManager::newReference()` and stores them in `{{%transaction}}.reference`).

**Android manifest (app side):** `intent-filter` on a custom scheme
`https://allseba.dgtts.org/requests/*` (App Links) **plus** a fallback custom
scheme for the non-Play pilot build. Notification tap must carry `PendingIntent` with
`FLAG_IMMUTABLE` and a unique request code per notification id (otherwise Android merges
taps and opens the wrong screen).

**Server side, no change needed for App Links** — but `src/Environment.php` /
`APP_URL` must be the canonical HTTPS origin or the links break.

---

## 8. WhatsApp Integration Recommendation

### 8.1 Audit result

**No WhatsApp integration exists.** The only trace is `whatsapp_url` in
`src/Repository/SettingsRepository.php:23`, a `url`-type site setting rendered as a
floating support link (`resources/views/partials/whatsapp-float.twig`). It is a static
hyperlink to a human — a support widget, **not** an integration. There is no phone
collection, no opt-in record, no API call, no webhook, no delivery status.

**Do not use WhatsApp Web automation libraries (whatsapp-web.js, Baileys, WWebJS) in
production.** They violate the ToS, get the sending number banned, break without notice,
and cannot satisfy template/opt-in requirements.

### 8.2 Recommended architecture — official Cloud API only

```
Admin profile
   ↓ "Connect WhatsApp"
POST /api/admin/channels/whatsapp/connect   →  E.164 phone + OTP
   ↓
whatsapp_connection  (user_id, phone_e164, verified_at, status)
   ↓
NotificationManager → whatsapp_queue → WahaWorker/Cloud API client
   ↓
Webhook  POST /webhooks/whatsapp   ← status callbacks
   ↓
webhook_events (idempotency by provider message id) → notification_delivery.status
```

Non-negotiables for the Cloud API:

- Business account must be **verified**; outbound messages outside a 24-hour customer
  service window must use **approved templates** (`{{1}}` placeholders only, no free text).
- Opt-in must be **recorded and provable** — store `opt_in_at`, `opt_in_source`,
  `opt_in_text` in `whatsapp_connection`, and honour opt-out (`STOP`) via webhook.
- Long-term quality degrades if template categories are misused; keep payment notices and
  marketing strictly separated.
- Phone numbers must be stored **E.164**, encrypted at rest, never rendered in the UI
  (requirement: "do not expose phone numbers unnecessarily") — mask to `+880****1234`
  in every admin table and log.

**Realistic cost note (mark as requiring live verification — do not quote numbers):**
Meta charges per conversation and rates differ by country and message category; template
approvals and the display-name review also take business days. Confirm current rates at
developers.facebook.com before committing.

---

## 9. Telegram Integration Recommendation

**No Telegram code exists** — only `telegram_url` (`SettingsRepository.php:24`) as a
support link, and hard-coded `telegram`/`support` params in `config/common/params.php:21-22`.

### 9.1 Connection flow (no polling, no credential storage of user data)

```
Admin → Profile → "Connect Telegram"
  ↓ bot button / deep link: https://t.me/<bot>?start=<one-time-code>
  ↓
Bot receives /start <code>  (webhook POST /webhooks/telegram)
  ↓ server verifies HMAC secret_token header
  ↓ code is single-use, TTL 10 min → bot_connections row (chat_id, user_id, verified_at)
  ↓ admin sees "Telegram connected ✓"
```

**Use a webhook, not polling** — cPanel shared hosting has no resident process, and
`yiisoft/yii-runner-console` cannot stay alive there. Telegram's `setWebhook` is a single
HTTP call and the provider pushes to you.

**Security requirements:**

- The bot token lives **only** server-side in env (`TELEGRAM_BOT_TOKEN`). Never in
  Telegram messages, never in the admin UI, never in the APK.
- Verify the `X-Telegram-Bot-Api-Secret-Token` header on every webhook call (Telegram
  supports this since Bot API 7.0) — this is the anti-spoofing control.
- `chat_id` is the addressing primitive; it is **not** proof of identity. Bind it to a
  logged-in admin session during connect, and re-verify on every unlink.
- Store the minimum: `chat_id`, `username` (nullable, for display), `user_id` link,
  `verified_at`. Do **not** store phone numbers, first/last names, or locale.

**Commands for the admin bot (Phase 4):** `/start`, `/help`, `/pending` (count of
unreviewed top-ups), `/link` (deep link to a request), `/unlink`.

**Rate limits:** Telegram allows ~30 msg/s per bot globally and ~1 msg/s per chat. Batch
admin digests rather than sending one message per event.

---

## 10. Bot Architecture — two distinct systems

The brief is right to insist these stay separate. They have different audiences, different
security models, different lifetimes, and different failure modes.

### 10.1 Notification Bot (Phase 4) — **admins only, read-mostly**

One bot, admin-only recipients. Delivers events; accepts only `/start`, `/help`,
`/pending`, `/unlink`. No user data is ever returned over Telegram beyond a reference
code the admin already owns. Low risk, ship early.

### 10.2 Interactive Customer Service Bot (Phase 6) — **defer**

Do **not** build this in the current cycle. It is a second product: conversation state,
authentication over an untrusted channel, payment initiation, human handoff, and abuse
control. Concretely it would need:

```
/start → ask phone → OTP (never accept a phone number as proof of identity)
/services → list from ServiceRepository::all()
/orders  → requires a completed OTP session, scoped to that user_id only
/recharge → deep link into the web app or /recharge, NOT in-bot payment
/support  → hand off to a human
```

**Hard rules** (these are the security findings that bite hardest in bot projects):

- **Never** return a balance, order list, or request detail to a chat that has not
  completed OTP verification for that account. Knowing a phone number must never be
  sufficient.
- OTP attempts: max 3, then lock 15 minutes, then require support.
- The bot must be able to say "I don't have access to that" — never partially answer.
- `bot_conversations` must have a TTL purge (30 days) and store no message bodies beyond
  what is needed for the active session.

**Recommendation: ship 10.1 in Phase 4, defer 10.2 to a separate project phase with its own
security review.**

---

## 11. Event-Driven Notification Architecture

### 11.1 Why events, and why *now*

`config/configuration.php:35` declares `'events' => []`. Yii 3 supports a PSR-14
event dispatcher through `yiisoft/event-dispatcher`; it is not yet required. Rather than
introduce a dispatcher for its own sake, emit **domain events explicitly** from the six
existing `notifications->create()` call sites via a `NotificationManager` façade. That keeps
the diff small, keeps the existing tests green, and is trivially replaceable by a real
dispatcher later.

```
Business action (ServiceManager / TopupService)
        │
        ├─ DB write  (transaction / balance change)      ← unchanged, still the source of truth
        │
        └─ NotificationManager::dispatch(NotificationEvent)
                 │
                 ├─► INSERT {{%notification}}          (in-app, synchronous, cheap)
                 └─► INSERT {{%notification_queue}} ×N (one row per channel, best-effort)
                                │
                       cron (every minute)
                                │
                     app:notification:work  (console command)
                                │
                   channel driver ──► FCM / Telegram / WhatsApp
                                │
                   UPDATE delivery status, attempts, provider_message_id
```

**Rule: the in-app row and the queue rows are written *after* the business transaction
commits, in the same request, but they are plain INSERTs — no network I/O. All network
I/O happens in the cron worker.**

### 11.2 Event catalogue (mapped to actual code, not hypotheticals)

Only events that correspond to a real state transition in this codebase are listed.

| Event | Emitted from | Recipient | Channels | Priority | Retry |
|---|---|---|---|---|---|
| `user.registered` | `AuthService::register()` | user | in-app | low | 1 |
| `service_request.created` | `ServiceManager::submit():280` | user + **all admins** | in-app, push, telegram | high | 3 |
| `service_request.processing` | `ServiceManager::start():331` | user | in-app, push | normal | 3 |
| `service_request.completed` | `ServiceManager.php:432` region | user | in-app, push, whatsapp | high | 3 |
| `service_request.failed` | `ServiceManager` settle path | user | in-app, push | high | 3 |
| `service_request.cancelled` | `ServiceManager::cancel():364` | user | in-app, push | normal | 3 |
| `topup.requested` | `TopupService::request()` | **admins** | in-app, push, telegram | high | 3 |
| `topup.approved` | `TopupService::approve():237` | user | in-app, push, whatsapp | high | 3 |
| `topup.rejected` | `TopupService::reject():295` | user | in-app, push, whatsapp | high | 3 |
| `topup.cancelled` | `TopupService::cancel()` | user + admins | in-app | normal | 2 |
| `system.alert` | admin/manual | admins | in-app, telegram | high | 3 |

`USER_REGISTERED`, `SERVICE_REQUEST_ACCEPTED`, `ADDITIONAL_INFORMATION_REQUIRED`,
`PAYMENT_PENDING`, `PAYMENT_FAILED`, `NEW_SUPPORT_MESSAGE`, `ADMIN_REPLY` from the brief's
generic list **do not exist in this application** and are deliberately omitted. Re-check
this table when a support-ticket feature is added.

### 11.3 Per-channel decision rules

- **in-app** — always on, always synchronous, never fails.
- **push (FCM)** — user-configurable; off for `topup.requested` on the *user* side
  (admins get push; users don't need to know their top-up hit the queue).
- **telegram** — **admins only.** Never customers. Keeps customer PII off a third-party
  platform and keeps the bot scope to §10.1.
- **whatsapp** — opt-in only, and **payment-critical events only**
  (`topup.approved`, `topup.rejected`, `service_request.completed`). A template message
  that says your recharge was approved is worth its cost; a  marketing-grade message is not.

---

## 12. Notification Preferences

Two audiences, two surfaces, one table.

**Users** — `/profile` (`src/Web/Account/ProfileAction.php` already exists and already hosts
the channel-looking `whatsapp_url`/`telegram_url` links, so this is the natural home).

```
Notification Preferences

In-app       [ ON ]  (locked ON)
Push (FCM)   [ ON ]
WhatsApp     [ OFF]  → requires verified E.164 number
Telegram     [ -- ]  (not offered to users; admin channel only)
```

**Admins** — `/admin/settings`.

```
In-app       [ ON ]
Push (FCM)   [ ON ]
Telegram     [ ON ]  → requires connected, verified bot chat
WhatsApp     [ OFF]  → requires connected, verified E.164 number
```

**Schema:** one row per `(user_id, event, channel)` with a boolean `enabled`. Store only
the **overrides** — absence of a row means "use the global default", which keeps the table
tiny and makes the global default changeable without a migration.

**Defaults must be conservative for money events.** `topup.approved` and `topup.rejected`
default to push ON (the user is owed a prompt answer), WhatsApp OFF (opt-in only), Telegram
N/A. `topup.requested` defaults to admin push ON.

Precedence: `explicit override > per-user default > global channel default > channel disabled`.

---

## 13. Queue / Retry Architecture

### 13.1 Constraint: no resident workers

`docs/architecture-plan.md:5` states the target is shared hosting with **no Redis/Node/worker**.
That rules out a resident worker, and rules out `supervisor` in the general case. A cPanel
host *does* provide **cron**, and Yii 3 already ships `yii-console`, so a **database-backed
queue drained by a cron-invoked command** is the correct fit. It is also portable: if the
app later moves to a VPS, the same worker becomes a supervised resident process with no
code change.

```
cPanel cron:  * * * * *  cd /home/user/alif_tools && php yii app:notification:work --limit=200
```

```
app:notification:work
  1. SELECT ... FROM {{%notification_queue}}
       WHERE status IN ('queued') AND available_at <= NOW()
         OR (status = 'failed' AND attempts < max_attempts AND available_at <= NOW())
       ORDER BY priority DESC, id ASC
       LIMIT 200
       FOR UPDATE SKIP LOCKED            ← safe if two cron ticks overlap
  2. For each row: resolve channel driver → attempt send → record result
  3. Exit 0 always (a single bad row must not kill the batch)
```

`SKIP LOCKED` requires MySQL 8 (satisfied — `docs/deployment.md` requires MySQL 8, 5.7 is
listed as tolerable but then the worker must use `GET_LOCK()` instead). Handle both.

### 13.2 Retry policy

```
attempt 0  → queued
attempt 1 fails → status=failed, available_at = now + 1 min
attempt 2 fails → status=failed, available_at = now + 5 min
attempt 3 fails → status=failed, available_at = now + 30 min
attempts >= max → status=dead        (never retried, surfaced in admin UI)
```

Exponential backoff with a cap, plus a **jitter** so a provider outage does not produce a
thundering herd when cron resumes. `max_attempts` per channel: FCM 3 (its own SDK already
retries transport), Telegram 3, WhatsApp 5 (payment notices matter more).

**Permanent failures must not be retried.** Classify before retrying:
`4xx` other than 408/429 → dead immediately. `401/403` → dead (credential problem; alert).
`UNREGISTERED` token from FCM → dead + deactivate device. Only `5xx`, timeouts, and
`429` are retryable.

### 13.3 Idempotency

Every queue row carries a `dedupe_key` (e.g. `sha1(event|user_id|channel|entity_id)`) with
a **UNIQUE index**. Enqueue uses `INSERT IGNORE` / `ON DUPLICATE KEY`, so a retried business
action cannot double-send. This satisfies the brief's idempotency requirement without a
distributed lock.

---

## 14. Failure Handling & Observability

### 14.1 State machine per delivery

```
queued ──attempt──► sent ──provider ack──► delivered
   │                   │
   │                   └──nack/expired──► failed (terminal)
   ├──retryable error──► failed ──backoff──► queued
   └──max attempts─────► dead        (terminal, needs human)
```

`notification_delivery` columns (the brief's list, all justified):

| Column | Purpose |
|---|---|
| `id` | pk |
| `queue_id` | FK to the job row |
| `channel` | enum: `in_app`, `fcm`, `telegram`, `whatsapp` |
| `status` | enum: `queued`,`sent`,`delivered`,`failed`,`dead` |
| `attempts` | int, capped |
| `max_attempts` | int, snapshot at enqueue |
| `last_error` | string(500), **sanitised** |
| `provider_response` | JSON, **truncated + redacted** |
| `provider_message_id` | string(190) — FCM message name, Telegram `message_id`, WABA id |
| `sent_at`, `delivered_at`, `failed_at` | datetime NULL |
| `latency_ms` | int — provider round trip |
| `created_at`, `updated_at` | datetime |

### 14.2 What to log, and what never to log

Log: event name, channel, recipient **user id** (not phone/chat_id), status, latency,
attempt count, provider id, error class.

**Never log:** FCM device tokens, Telegram bot token or chat_id, WhatsApp phone numbers,
WhatsApp message bodies containing OTPs, `FIREBASE_CREDENTIALS` contents, `Authorization`
headers, or full provider response bodies. `ActivityLogRepository` is the right sink for
the auditable subset — it already stores `action`, `ip_address`, `user_agent`, and a JSON
`metadata` column, and is already exposed at `/admin/activity-logs`.

### 14.3 Admin surface

`/admin/notifications` with tabs **All / Unread / Failed / Dead / Settings**, plus a
per-channel health card (last success, failure rate last 24 h, queue depth). A dead-letter
row must be re-runnable by hand. This replaces the "poll the admin page" model entirely.

---

## 15. Security Review

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| 1 | **No machine auth on `/api/*`** — `ApiAuthMiddleware` is session-only | **Critical** | Token (bearer) auth: `api_token` table, `hash('sha256', …)` storage, `Authorization: Bearer`, 30-day sliding expiry, per-device revocation. **Blocks the APK.** |
| 2 | **Provider credentials in the APK** | Critical | Never. The APK talks only to the backend. `FIREBASE_CREDENTIALS_PATH`, `TELEGRAM_BOT_TOKEN`, `WHATSAPP_TOKEN` stay in env, outside the web root. |
| 3 | **Telegram webhook spoofing** | High | Verify `X-Telegram-Bot-Api-Secret-Token`; reject mismatches before any processing. |
| 4 | **WhatsApp webhook spoofing / replay** | High | Meta's `X-Hub-Signature-256` HMAC with the app secret; store provider message id in `webhook_events` with a UNIQUE index; ignore duplicates. |
| 5 | **IDOR on request/topup detail** | High | Every new `GET /api/service-requests/{id}` must scope by `user_id` in the WHERE clause, not filter after fetch. Reuse the pattern in `TopupService::cancel()`. |
| 6 | **ID enumeration via push payloads** | Medium | Push only the human `reference`, never the auto-increment id. |
| 7 | **Rate-limit bypass on OTP / bot** | High | Reuse `src/Auth/AuthThrottle.php` (already file-backed, already env-configurable). Max 3 OTP attempts, then 15-minute lock. |
| 8 | **CSRF on new write endpoints** | Medium | `yiisoft/csrf` already present; token-authenticated API requests are exempt by design, but must require the header rather than silently skipping. |
| 9 | **PII leakage to third parties** | High | Telegram = admins only. WhatsApp = opt-in only. Never send a phone number, email, or national ID over a bot. |
| 10 | **Sensitive data in `activity_log`** | Medium | Metadata allow-list per event; never pass raw provider payloads. |
| 11 | **Deep-link abuse** | Medium | Unguessable reference tokens, and every deep-linked screen re-checks session auth server-side. |
| 12 | **Stored XSS via push/notification title** | Medium | `src/Web/SecurityHeadersMiddleware.php` exists; templates must escape `title`/`message` — treat provider text as untrusted. |
| 13 | **Queue-table poisoning / runaway growth** | Medium | Retention purge cron; cap on `dead` rows; a poison row must be `dead` after `max_attempts`, never retried forever. |
| 14 | **Secret leakage via error pages** | High | `APP_DEBUG=false` in prod (already in `.env.example`); provider exceptions must be caught and reduced to an error *class* before storage. |
| 15 | **Least privilege on the service account** | Medium | The Firebase service account needs only FCM send; the WhatsApp token only the `waba_id` it owns. |

---

## 16. Performance & Scalability

| Scale | Assessment |
|---|---|
| **10 users** | Non-issue. The cron worker handles the whole backlog in one tick. |
| **100 users** | Fine. FCM multicast (`sendEachForMulticast`, 500 tokens/call) is the only optimisation worth making. |
| **1 000 users** | Fine **if** fan-out is not done synchronously. The risk is `service_request.created` → notify *all* admins. Cap it: notify only `role IN ('admin','staff')` with an active device, and prefer a **daily digest** over per-event admin messages. |
| **10 000 users** | First real pressure. Needs: `notification` index on `(user_id, id DESC)`; queue index `(status, available_at)`; the `COUNT(*)` in `forUser()` becomes a bottleneck — move unread counts to a cached counter (PSR-16 file cache is already wired). |
| **100 000 users** | Shared hosting is the ceiling, not the design. Move to a VPS + supervised worker + Redis queue at this point. The schema and channel-driver design carry over unchanged. |

**Concrete bottlenecks to fix, in order:**

1. **Synchronous fan-out** — the #1 risk. All six call sites write one row today; the moment
   one writes N queue rows plus does an HTTP call, request latency becomes provider latency.
2. **`forUser()` does `COUNT(*)` + full row fetch on every page load.** Unread count is
   called on *every* dashboard render. Cache it.
3. **Admin fan-out** is O(number of admins) per event. Cap or digest.
4. **No index on `{{%notification}}.created_at`** — retention purges and "unread, newest
   first" both need one.
5. **Webhook processing must not do provider calls inline** — enqueue and return 200 within
   ms, or providers will time out and retry, causing duplicate work.

---

## 17. Cost Considerations

**No prices are quoted here — all current rates require live verification with the provider.**

| Component | Cost profile | Note |
|---|---|---|
| In-app notifications | **Free** | Pure MySQL rows. Already in place. |
| Telegram Bot API | **Free** | No per-message charge. The cheapest escalation channel by far. |
| FCM | Free tier exists; charges beyond it | Verify current free quota with Firebase. |
| WhatsApp Cloud API | **Paid, per conversation** | Verify current rates by country/category with Meta. Template approval is free but takes business days. |
| Email | Free via a transactional provider's tier | No email infrastructure exists; needs a provider. |
| SMS | Paid per message | Not recommended; FCM + Telegram cover it. |
| Database queue + cron | **Free** | Uses the existing MySQL plan and cPanel cron. |
| Android app | Free tooling; Play Store $25 one-off | Store listing, not code, is the real cost. |
| Hosting | Unchanged | No Redis/worker required by this design. |

**Cost-minimising ordering:** in-app (free) → FCM (free tier) → **Telegram (free, unlimited)**
→ WhatsApp (paid, therefore opt-in and restricted to payment-critical events only).

---

## 18. Recommended Architecture Diagram

```
                        ┌───────────────────────┐
                        │   OnlineSheba Web     │  Twig + Alpine (existing)
                        └───────────┬───────────┘
                                    │
                        ┌───────────┴───────────┐
                        │   Android APK (Kotlin)│  Phase 3
                        │  Compose + Retrofit   │
                        └───────────┬───────────┘
                                    │  HTTPS  (JSON)
                        ┌───────────┴───────────┐
                        │  /api/*  +  /webhooks/*│
                        │  TokenAuthMiddleware  │  ← NEW, Phase 1
                        │  (bearer; session still│
                        │   works for the web)  │
                        └───────────┬───────────┘
                                    │
        ┌───────────────────────────┴───────────────────────────┐
        │                                                       │
┌───────▼────────┐                                     ┌────────▼────────┐
│  ServiceManager │                                     │   TopupService   │
│  AuthService    │  ← business events (existing calls) │                  │
└───────┬────────┘                                     └────────┬────────┘
        └───────────────────────┬─────────────────────────────┘
                                │
                    ┌───────────▼────────────┐
                    │   NotificationManager  │  ← NEW façade
                    │  + NotificationEvent   │
                    └───────────┬────────────┘
                     INSERT     │     INSERT (one row per channel)
              ┌─────────────────┴──────────────────┐
              │                                    │
    ┌─────────▼──────────┐            ┌────────────▼─────────────┐
    │ {{%notification}}  │            │ {{%notification_queue}}  │
    │ (in-app, existing) │            │ dedupe_key UNIQUE        │
    └────────────────────┘            └────────────┬─────────────┘
                                                  │ cron every minute
                                     ┌────────────▼─────────────┐
                                     │ app:notification:work    │
                                     │ (yii console command)    │
                                     └────────────┬─────────────┘
                                                  │
              ┌───────────────┬───────────────────┼───────────────────┐
              │               │                   │                   │
      ┌───────▼──────┐ ┌──────▼───────┐  ┌────────▼────────┐ ┌────────▼────────┐
      │ FcmChannel   │ │TelegramChannel│  │WhatsAppChannel  │ │ (EmailChannel)  │
      │  kreait/     │ │ Bot API      │  │  Cloud API      │ │  future         │
      │  firebase-php│ │  webhook     │  │  HMAC webhook   │ │                 │
      └───────┬──────┘ └──────┬───────┘  └────────┬────────┘ └─────────────────┘
              └────────────────┴───────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │ notification_delivery│  status, attempts, latency, provider id
                    │ webhook_events       │  idempotency by provider message id
                    │ notification_preference
                    │ notification_device / channel_connection
                    └─────────────────────┘
```

---

## 19. Exact Files That Need Modification

| File | Reason | What should change | Risk | Depends on |
|---|---|---|---|---|
| `composer.json` | Need FCM + PSR-18 client + event dispatcher | Add `kreait/firebase-php`, `php-http/guzzle7-adapter` (or curl client), optionally `yiisoft/event-dispatcher` | Low — dev-only install | §24 |
| `config/common/di/services.php` | New services need factories (path-based, env-based) | Register `NotificationManager`, channel drivers, `HttpClientInterface`, `receipts` path, `TelegramClient` | Low | new classes |
| `config/configuration.php:35` | `'events' => []` is dead | Either wire `yiisoft/event-dispatcher` here or leave empty and dispatch explicitly | Low | composer |
| `config/common/routes.php` | Needs auth, webhook, device and admin-notification routes | Add `POST /api/auth/login`, `/api/auth/refresh`, `/api/devices`, `GET /api/service-requests/{id}`; a **CSRF-exempt, signature-verified** `/webhooks/*` group; `/admin/notifications` | **Medium** — the webhook group must be outside `AuthMiddleware` and `AdminMiddleware` | `ApiTokenMiddleware`, webhook actions |
| `src/Env.php` | New secrets | Add defaults for `FIREBASE_CREDENTIALS_PATH`, `TELEGRAM_BOT_TOKEN`, `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `API_TOKEN_TTL_DAYS`, `NOTIFY_WORK_BATCH`, `NOTIFY_MAX_ATTEMPTS` | Low | — |
| `.env.example` | Document the new vars with empty values | Add the same keys, all blank | Low | `Env.php` |
| `src/Repository/NotificationRepository.php` | Fan-out + event columns | Add `createFromEvent()`, `unreadCountCached()`; **keep `create()` intact** (6 call sites + `tests/Functional/NotificationsApiTest.php` depend on it) | **Medium** — live tests | migration |
| `src/Service/ServiceManager.php` (lines 280, 331, 364, 432) | Replace direct repo calls with the manager | Swap `notifications->create(...)` → `notifications->dispatch(...)`, moving the Bengali copy into a template key | **Medium** — money path | `NotificationManager` |
| `src/Service/TopupService.php` (lines 237, 295) | Same, plus admin-side events | Same swap; add `topup.requested` fan-out to admins on `request()` | **Medium** — money path | `NotificationManager` |
| `src/Auth/ApiAuthMiddleware.php` | Session-only ⇒ no APK | Accept a bearer token, fall back to session; keep the JSON error shape identical | **High** — this is the auth boundary | `ApiTokenRepository`, new middleware |
| `src/Auth/AuthService.php` | Needs token issue/rotate/revoke | Add `issueApiToken()`, `refreshApiToken()`, `revokeApiToken()` | Medium | new repository |
| `src/Repository/SettingsRepository.php` | `whatsapp_url`/`telegram_url` are links, not config | Keep them (they are the support widget), but do **not** store credentials here. Add `notify_*` global defaults as **notification_preferences** rows, not `site_setting` | Low | migration |
| `src/Web/Account/ProfileAction.php` | Needs a preferences section + phone for WhatsApp opt-in | Add the preferences form; validate E.164; record `opt_in_at`/`opt_in_text` | Medium | new actions/views |
| `src/Web/Admin/AdminSettingsAction.php` | Admin channel toggles | Add admin-side preferences + channel status | Low | new views |
| `src/Web/Admin/AdminDashboardAction.php` | Queue depth + failed count on the dashboard | Add two counters beside the existing pending-topup counter | Low | new repository |
| `src/Web/Api/NotificationsApiAction.php` | Needed by the APK | Add `markAllRead` (already routed), add `GET /api/notifications/unread-count` for badge polling | Low | — |
| `resources/views/site/account/profile.twig` | Render the new preferences UI | Add the section | Low | action |
| `resources/views/partials/sidebar.twig` | Link the new admin page | Add "Notifications" | Low | route |
| `resources/js/app.js` | FCM web token registration (optional, deferred) | Do **not** add web push in Phase 2; leave as-is | None | — |
| `docs/deployment.md` | The cron entry is a new operational requirement | Document the cPanel cron command, the env vars, and the service-account file location | Low | §25 |

**Explicitly NOT modified:** `public_html`/`.htaccess`, `ReceiptStorage.php`, any
`ServiceProvider` mock, `StatusPresenter.php` (status vocabulary is already correct and
should stay the single source of truth).

---

## 20. New Files That Should Be Created

```
src/Notification/
├── NotificationEvent.php           immutable value object: event, userId, entity, data
├── NotificationManager.php         façade: dispatch(), resolves prefs, writes in-app + queue
├── ChannelInterface.php            send(NotificationEvent, Channel $c): DeliveryResult
├── Channel/DeliveryResult.php      ok/failed/permanent + providerId + errorClass
├── Channel/FcmChannel.php
├── Channel/TelegramChannel.php
├── Channel/WhatsAppChannel.php
├── TemplateRenderer.php           event + locale → text (DB template, hard-coded fallback)
├── QueueRepository.php             enqueue (idempotent), claimBatch, markSent/Failed/Dead, purge
├── DeliveryRepository.php          delivery rows, stats for the admin health cards
├── DeviceRepository.php            notification_devices CRUD + deactivate on logout
├── PreferenceRepository.php        per (user, event, channel) overrides + defaults
├── WebhookEventRepository.php      idempotency by provider message id
├── Template/MessageTemplates.php   the seed copy (bn), so the fallback is not scattered
└── Bot/TelegramCommandRouter.php   /start /help /pending /unlink (admin-only, Phase 4)

src/Auth/
├── ApiTokenRepository.php          hashed tokens, expiry, revoke
└── ApiTokenMiddleware.php          bearer first, session fallback

src/Console/
├── NotificationWorkCommand.php     the queue worker (cron)
├── NotificationPurgeCommand.php    retention purge
└── WebhookSetupCommand.php         one-time Telegram setWebhook / WhatsApp register

src/Web/Api/
├── AuthApiAction.php               login/refresh for the APK
├── DeviceApiAction.php             register/refresh/unregister device tokens
├── ServiceRequestApiAction+ detail  GET /api/service-requests/{id}
└── NotificationPreferenceApiAction.php

src/Web/Webhook/
├── TelegramWebhookAction.php       verifies X-Telegram-Bot-Api-Secret-Token
└── WhatsAppWebhookAction.php       verifies X-Hub-Signature-256

src/Web/Admin/
├── AdminNotificationsAction.php    list / retry / dead-letter
└── AdminNotificationSettingsAction.php

migrations/
├── M240201000000_AddEventColumnsToNotification.php
├── M240201000001_CreateNotificationQueue.php
├── M240201000002_CreateNotificationDelivery.php
├── M240201000003_CreateNotificationDevice.php
├── M240201000004_CreateNotificationPreference.php
├── M240201000005_CreateNotificationTemplate.php
├── M240201000006_CreateChannelConnection.php
├── M240201000007_CreateBotConnection.php
├── M240201000008_CreateWebhookEvent.php
├── M240201000009_CreateApiToken.php
└── M240201000010_SeedNotificationTemplates.php

config/console/
└── commands.php                    (if console params are split per environment)

android/                              Phase 3 — separate toolchain, separate repo preferred
```

---

## 21. New Database Tables / Migrations

Nine new tables + one additive migration. **No existing table is replaced.**

### 21.1 `M240201000000_AddEventColumnsToNotification` (additive)

| Column | Type | Why |
|---|---|---|
| `event` | `varchar(64) NULL` | Template lookup key; NULL for legacy rows |
| `link` | `varchar(255) NULL` | Deep-link target, e.g. `/requests/AL-8F2A19C3` |
| `priority` | `tinyint NOT NULL DEFAULT 5` | 1 = highest |
| `read_at` | *already exists* | — |

Index: `ix_notification_user_id_desc (user_id, id DESC)`.
Retention: 180 days for read rows; unread rows kept until read + 30 days.

### 21.2 `notification_queue`

`id PK · notification_id FK NULL · event varchar(64) · user_id int · channel varchar(16) ·
status varchar(16) default 'queued' · attempts tinyint default 0 · max_attempts tinyint
default 3 · available_at datetime · payload json · dedupe_key char(40) ·
last_error varchar(500) NULL · created_at / updated_at datetime`

- `UNIQUE (dedupe_key)` → **idempotent enqueue**.
- `INDEX ix_queue_poll (status, available_at)` → the worker's only hot query.
- `FK user_id → user.id`.
- Retention: purge `dead` rows after 30 days; purge delivered rows after 7.

### 21.3 `notification_delivery`

As tabled in §14.1. `INDEX (status, created_at)` for the admin failed list,
`UNIQUE (provider_message_id)` where not null (dedupes provider callbacks).

### 21.4 `notification_device`

`id PK · user_id FK · device_token varchar(512) · platform varchar(16) · device_name
varchar(120) NULL · app_version varchar(32) NULL · is_active tinyint default 1 ·
last_seen_at datetime NULL · created_at / updated_at`

- `UNIQUE (device_token)` — FCM tokens are the identity of a device; this is the
  single most important index in the whole design (upsert + deactivation both need it).
- `INDEX ix_device_user (user_id, is_active)` for fan-out.
- Never expose `device_token` in any API response or admin view — truncate to `…abcd`.
- Retention: deactivate on logout; purge inactive rows after 90 days.

### 21.5 `notification_preference`

`id PK · user_id FK · event varchar(64) · channel varchar(16) · enabled tinyint ·
created_at / updated_at · UNIQUE (user_id, event, channel)`

Stores **overrides only**. A missing row inherits the global default.

### 21.6 `notification_template`

`id PK · event varchar(64) · channel varchar(16) · locale varchar(8) default 'bn' ·
title varchar(190) · body text · external_id varchar(190) NULL (e.g. WhatsApp template name)
· UNIQUE (event, channel, locale)`

`external_id` is the Meta-approved template name — a template is **not** usable until Meta
approves it, so this column is the integration point. Seeded by
`M240201000010_SeedNotificationTemplates.php` with the copy currently hard-coded in
`ServiceManager.php` / `TopupService.php`, so removing the literals is a no-op for users.

### 21.7 `channel_connection`

`id PK · user_id FK · channel varchar(16) · address varchar(64) (E.164 or Telegram chat id) ·
status varchar(16) (pending/verified/disabled) · verified_at datetime NULL ·
opt_in_at datetime NULL · opt_in_source varchar(32) NULL · opt_in_text varchar(255) NULL ·
created_at / updated_at · UNIQUE (user_id, channel)`

`address` holds PII → **encrypt at rest** (libsodium or `openssl_encrypt` with a key from
env), and mask everywhere it is displayed. `opt_in_text` is the **provable** opt-in record
Meta requires; it is a legal artefact, not debug data.

### 21.8 `bot_connection`

`id PK · user_id FK · chat_id bigint · username varchar(64) NULL · verified_at datetime ·
is_active tinyint · created_at / updated_at · UNIQUE (chat_id) · UNIQUE (user_id)`

Deliberately minimal — no phone, no name, no locale. One admin row per Telegram account.

### 21.9 `webhook_event`

`id PK · provider varchar(16) · external_id varchar(190) · payload_hash char(64) ·
received_at datetime · processed_at datetime NULL · UNIQUE (provider, external_id)`

The uniqueness **is** the idempotency guarantee. A duplicate callback hits the constraint
and is a 1-line no-op.

### 21.10 `api_token`

`id PK · user_id FK · token_hash char(64) · device_label varchar(120) NULL ·
expires_at datetime · last_used_at datetime NULL · revoked_at datetime NULL ·
created_at · UNIQUE (token_hash)`

Store `hash('sha256', $token)`, never the token. Expiry enforced on every request.

---

## 22. API Changes

### 22.1 New — authentication (the unblocker)

```
POST /api/auth/login        {username, password, device_label} → {token, refresh, expires_at, user}
POST /api/auth/refresh      Authorization: Bearer <refresh> → new pair (rotates both)
POST /api/auth/logout       revokes the presented token
```

Token format: `<selector>.<verifier>` — selector indexes the row, verifier is
`hash_hmac('sha256', selector, APP_KEY)`, compared with `hash_equals`. Rotating the refresh
token on every use makes replay detectable.

### 22.2 New — devices

```
POST   /api/devices              {token, platform, device_name, app_version} → 201 (idempotent upsert)
DELETE /api/devices/{id}         deactivate
GET    /api/devices              list the caller's own devices (tokens masked)
```

### 22.3 New — request detail (required for deep links)

```
GET /api/service-requests/{id}   → 200 transaction (scoped to the caller) | 404
```

### 22.4 New — preferences

```
GET  /api/notification-preferences            → effective prefs (overrides + defaults)
PUT  /api/notification-preferences            → upsert overrides
```

### 22.5 New — webhooks (outside every auth group, CSRF-exempt, signature-verified)

```
POST /webhooks/telegram    X-Telegram-Bot-Api-Secret-Token
POST /webhooks/whatsapp    X-Hub-Signature-256
POST /webhooks/fcm         (delivery receipts, if used)
```

Each must: verify signature → check `webhook_events` uniqueness → **enqueue and return 200
within milliseconds** → process in the worker. Never call a provider inline.

### 22.6 New — admin

```
GET  /admin/notifications              list + filters (status, channel, event)
POST /admin/notifications/{id}/retry   re-queue a dead delivery
GET  /admin/notifications/health       per-channel success rate, queue depth, avg latency
```

### 22.7 Unchanged

All eight existing `/api/*` routes keep their paths, response shapes, and session auth.
`ApiAuthMiddleware` gains token support as a **first branch**; the session path is untouched.

---

## 23. Admin Panel Changes

Only what the existing admin area justifies. `src/Web/Admin/` already has
dashboard, users, categories, services, transactions, topups, recharges, settings, logs.

```
/admin/notifications            Notifications
├── All                         every {{%notification}}
├── Unread                      read_at IS NULL
├── Failed                      delivery status = failed, retryable
├── Dead                        exhausted retries — re-runnable
├── Delivery Logs               per-channel detail incl. latency + provider id
└── Settings                    global defaults, per-event matrix
```

`/admin/settings` gains an **Admin Channels** block: Telegram connect/disconnect, WhatsApp
connect/verify, push device list. Credentials are **never** editable in the UI — a form
that can write a bot token will eventually leak one. Env only.

`/admin` dashboard gains two tiles beside the existing pending-topup counter:
**Queue depth** and **Dead letters** — each links to `/admin/notifications`.

---

## 24. User Dashboard Changes

`/profile` (`src/Web/Account/ProfileAction.php`) gains a **Notifications** section:
push toggle, WhatsApp number + OTP verify (opt-in), Telegram shown as *admin-only, not
available*. That mirrors the existing `whatsapp_url`/`telegram_url` links already rendered
there, so no new navigation is needed.

The existing `resources/views/layouts/dashboard.twig` bell keeps working unchanged — the
`{{%notification}}` table is not being replaced.

The APK's screen list maps onto existing endpoints with **no new user-facing pages**:
dashboard, services, service detail + form, service history, recharge + receipt upload,
transactions, notifications, profile.

---

## 25. Required External Services

| Service | Phase | Blocker |
|---|---|---|
| **Firebase project + FCM** | 2 | Needs a Firebase project, a service-account JSON, and an `google-services.json` (or manual `google_app_id`) for the APK |
| **Telegram BotFather** | 4 | Needs a bot token; a public HTTPS webhook URL (satisfied) |
| **WhatsApp Business** | 5 | Needs Meta Business verification, a WABA, a phone number not on WhatsApp, and **approved message templates** |
| **Android signing key** | 3 | Needs a keystore; Play Store upload needs a Google Play developer account ($25) |
| **cPanel cron** | 1 | Must be confirmed available on the host |
| **PSR-18 HTTP client** | 1 | Composer dependency |

**Credentials must never be committed.** `.env` is already git-ignored; extend
`.env.example` with empty keys only. The Firebase JSON lives outside the web root.

---

## 26. Required Environment Variables

```
# --- Phase 1: core ---
NOTIFY_QUEUE_BATCH=200            # rows per worker tick
NOTIFY_MAX_ATTEMPTS=3             # default cap
NOTIFY_BACKOFF_BASE=60            # seconds; doubles per attempt
NOTIFY_RETENTION_DAYS=180         # read notifications
API_TOKEN_TTL_DAYS=30
APP_KEY=                          # required for token HMAC + PII encryption

# --- Phase 2: FCM ---
FIREBASE_CREDENTIALS_PATH=/home/user/private/firebase-service-account.json
FIREBASE_PROJECT_ID=

# --- Phase 3: Android ---
ANDROID_PACKAGE=broxlab.onlinesheba
DEEP_LINK_HOST=allseba.dgtts.org   # must match APP_URL

# --- Phase 4: Telegram ---
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=          # secret_token for setWebhook
TELEGRAM_ADMIN_CHAT_ID=           # optional bootstrap for the first admin

# --- Phase 5: WhatsApp ---
WHATSAPP_TOKEN=                   # permanent/system-user token
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_APP_SECRET=              # HMAC verification
WHATSAPP_BUSINESS_ACCOUNT_ID=

# --- PII encryption (channel_connection.address) ---
PII_ENCRYPTION_KEY=
```

All belong in `src/Env.php::DEFAULTS` (empty defaults only) and `.env.example`.

---

## 27. Phased Implementation Roadmap

### Phase 1 — Core notification infrastructure *(no external services required)*

> **STATUS (2026-09-28): DONE.** Implemented as described, with three deliberate
> deviations: FCM rows are enqueued and queued-dormant rather than skipped when no
> Firebase credentials exist (nothing is lost; `FcmChannel::isAvailable()` guards the
> actual send); `app:notification:purge` also prunes dead-letter queue rows beyond
> retention; tests harden `_after()` cleanup with a time-window sweep because admin
> fan-out lands on users outside a test's own fixture list.

- Additive migration: event columns + indexes on `{{%notification}}`.
- `NotificationEvent`, `NotificationManager`, `TemplateRenderer`, `QueueRepository`,
  `DeliveryRepository`, `PreferenceRepository`.
- `notification_queue` + `notification_delivery` + `notification_preference` tables.
- `app:notification:work` console command + cPanel cron + `app:notification:purge`.
- **Refactor** the six call sites in `ServiceManager.php` / `TopupService.php` to
  `dispatch()`; seed templates with the current copy so **no user-visible change**.
- Admin notification list + dead-letter retry.
- ✅ Exit criteria: an admin receives an in-app notification for a new top-up; the six
  existing functional tests still pass unchanged.

### Phase 1.5 — API token authentication *(hard prerequisite for anything mobile)*

> **STATUS (2026-09-28): DONE.** Token scheme hardened during review: the verifier is
> true random (not derivable from the DB), the stored hash is
> `sha256(selector:verifier:APP_KEY)`, and a presented-but-invalid bearer gets 401 with
> **no** session fallback (bearer-first, per the audit's intent).

- `api_token` table, `ApiTokenMiddleware` (bearer-first, session fallback),
  `ApiAuthAction` login/refresh/logout, `GET /api/service-requests/{id}`.
- ✅ Exit criteria: a CLI client can obtain a token, call every existing `/api/*` route, and
  is rejected with 401 when the token is expired or revoked.

### Phase 2 — FCM push

- `notification_device` table, `DeviceApiAction`, `DeviceRepository`.
- `kreait/firebase-php`, `FcmChannel` with token deactivation on `UNREGISTERED`.
- Token deactivation on logout (`src/Web/Auth/LogoutAction.php`).
- ✅ Exit criteria: a push arrives on a real device; a revoked token is auto-deactivated.

### Phase 3 — Android APK (Kotlin)

> **STATUS (2026-09-28): SCAFFOLD IN REPO.** `android/` Gradle + Kotlin 2.0 + Compose +
> Retrofit/OkHttp + WorkManager + FCM service; package `online.broxlab.aliftools`
> (manifest/README note: audit's `broxlab.onlinesheba` was never a valid package id —
> Java packages can't start with a digit); deep links `aliftools://requests/{id}` +
> App Links; refresh-on-401 wired in the OkHttp interceptor. Remaining for a shippable
> build: receipt capture + multipart upload screen, Play signing, `google-services.json`.

- `android/` project: Compose, Retrofit, FCM service, `ApiTokenStore` (EncryptedSharedPreferences upgrade path noted below).
- Screens mapped to existing endpoints; receipt capture + multipart upload.
- Deep links: `onlinesheba:request/AL-XXXX` + App Links; `PendingIntent` per notification.
- Play Store signing key; internal-test track first.
- ✅ Exit criteria: submit a recharge with a receipt photo from the phone; a push deep-links
  to the right request.

### Phase 4 — Telegram (admin-only)

- `bot_connection`, `bot_connection` connect flow, `TelegramWebhookAction` with
  secret-token verification, `TelegramChannel`, `WebhookSetupCommand` (one-time `setWebhook`),
  admin commands `/start /help /pending /unlink`.
- Admin preference toggles in `/admin/settings`.
- ✅ Exit criteria: a new top-up pings the connected admin within one cron tick; an unsigned
  webhook POST is rejected 403.

### Phase 5 — WhatsApp

- `channel_connection` with E.164 + OTP verification + **provable opt-in record**.
- `WhatsAppChannel` with Meta-approved templates only; `WhatsAppWebhookAction` with
  `X-Hub-Signature-256`; delivery-status callbacks feeding `notification_delivery`.
- Restricted to `topup.approved`, `topup.rejected`, `service_request.completed`.
- ✅ Exit criteria: an opted-in user gets a template message on approval; an unverified or
  tampered webhook is rejected; a `STOP` reply deactivates the channel.

### Phase 6 — Interactive customer bot *(separate project, separate security review)*

- Only after Phases 1-5 are stable. OTP-gated identity, 30-day conversation TTL,
  human handoff, no balance or order data without a completed OTP session. See §10.2.

### Phase 7 — Monitoring & optimisation

- Per-channel health cards, dead-letter dashboard, latency percentiles.
- Cache the unread count (removes the `COUNT(*)` on every dashboard render).
- Admin digest instead of per-event admin messages.
- Capacity review before 10 000 users; plan the VPS + Redis migration well before 100 000.

---

## 28. Risks & Blockers

**Cannot be implemented without external input**

| Blocker | Blocks | Needed from |
|---|---|---|
| Firebase project + service-account JSON | Phase 2 | Firebase console access |
| Android signing keystore | Phase 3 | You — **generate once, back up, never commit** |
| Play Store developer account | Phase 3 | Google account + $25 |
| Telegram bot token (BotFather) | Phase 4 | You |
| Meta Business verification + WABA | Phase 5 | Business documents; takes business days |
| WhatsApp **approved** templates | Phase 5 | Meta review, per template |
| cPanel cron availability | Phase 1 | Host confirmation |
| A test phone number for E.164 verification | Phase 5 | You |
| `APP_KEY` + `PII_ENCRYPTION_KEY` | Phases 1.5/5 | You, generated |

**Architectural risks**

1. **Shared hosting has no worker.** The cron design assumes a ≤1-minute tick; on a very
   cheap host cron may be throttled to 5- or 15-minute intervals. Not fatal, but the
   "instant push" promise becomes "within N minutes". Confirm before promising latency.
2. **The money path is the blast radius.** Both `ServiceManager::submit()` and
   `TopupService::approve()` debit and credit balance. Every change there needs the
   functional tests (`tests/Functional/RechargeTest.php`, `ServiceHistoryTest.php`) green.
3. **Admin fan-out is O(admins) per event** and there is no admin notification today, so
   there is no precedent for how many admins exist. Cap it, and prefer a digest.
4. **WhatsApp template approval is outside your control** and gates Phase 5 entirely.
   Nothing else depends on it, which is exactly why it is Phase 5.
5. **This working tree already has uncommitted modifications** (a 30-file change set
   present before this audit). Do not start Phase 1 on top of it without committing or
   stashing first, or the notification refactor will be impossible to review.

---

## 29. Recommended Next Step — Implementation Order

1. **Commit or stash the existing uncommitted work** so the notification work is reviewable
   in isolation. *(Prerequisite, not optional.)*
2. **Phase 1** — event columns, `NotificationManager`, queue, worker, cron, admin list.
   Refactor the six call sites. No behaviour change; all existing tests stay green.
3. **Phase 1.5** — API token auth. Verify with a CLI client against all eight existing
   `/api/*` routes. This is the gate for mobile.
4. **Phase 2** — FCM devices + `FcmChannel`. Test with one real Android device.
5. **Phase 3** — the Kotlin APK against the now-authenticated API, receipt capture first.
6. **Phase 4** — Telegram for admins (free, fastest win, no approval process).
7. **Phase 5** — WhatsApp, started *now* as an approval process: create the WABA and submit
   templates during Phase 4 so Meta's review runs in parallel.
8. **Phase 6** — the interactive bot as its own scoped project.
9. **Phase 7** — health metrics and the unread-count cache.

**The single most valuable next action is Phase 1.5.** Everything mobile is blocked on it,
it is small, and it is entirely within your control — no vendor, no approval, no spend.

