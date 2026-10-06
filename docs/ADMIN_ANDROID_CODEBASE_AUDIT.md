# Alif Tools — Codebase Audit for the Admin / Super Admin Native Android App

**Audit date:** 2026-10-06
**Audited by:** Senior Software Architect / Android Engineer / Yii 3 Backend Engineer / Security Engineer
**Purpose:** Full codebase audit before building the **Alif Tools Admin** (`com.aliftools.admin`) native Android application. Every finding below is backed by an exact file path, class, method, route, table, or configuration key — no assumed architecture.

**Headline finding:** there is **no `android/` module in this repository**. The only Android artifact is `twa/` (a Trusted Web Activity wrapper for the user-facing site). The prior plan in `docs/android-admin-architecture-plan.md` assumes a pre-existing `android/` module with Retrofit/Compose/FCM/DataStore — **that assumption is stale**. The admin Android app must be created from scratch, reusing the Yii 3 backend as its API.

---

## 1. Current architecture

**Stack:** PHP 8.2–8.5, Yii 3 framework (`yiisoft/*` packages), MySQL/MariaDB, shared-hosting constraints (no composer build step on the host, no shell access assumptions), mpdf for PDFs, phpdotenv, symfony/console.

**Layout** (PSR-4 `App/` → `src/`):

```text
config/
  common/routes.php      — the entire route table (single file, no module routing)
  common/di/             — DI wiring (repositories, services, channels)
  web/di/application.php — middleware pipeline for the web app
  console/               — console DI
  environments/          — env-specific params
migrations/              — 23 Yii migrations, M240101000000 → M240125000000
public/                  — web root (index.php, .htaccess, assets)
resources/               — css/js/views (server-rendered admin + user UI)
src/
  Auth/                  — Identity, repositories, middleware (authn + authz)
  Console/               — 12 yii console commands
  Notification/          — event → queue → channel delivery system
  Push/                  — VAPID key handling (web push)
  Repository/            — 17 repositories, all DB access lives here
  Service/               — 10 services, all business rules live here
  Web/                   — request handlers (single-action classes, no controllers)
    Admin/               — 17 server-rendered admin pages
    Api/                 — 12 user API actions + Admin/ (10 admin API actions)
    Auth/ Dashboard/ HomePage/ NotFound/ Services/ Shared/ Site/ View/
twa/                     — Trusted Web Activity (user-facing site wrapper) — NOT an admin app
docs/                    — architecture plans, deployment, notification audits
web/                     — static web assets
```

**Request flow** (`config/web/di/application.php` middleware pipeline):

```text
ErrorCatcher → SecurityHeadersMiddleware → NoIndexMiddleware → ForwardedProtoMiddleware
→ RequestCatcherMiddleware → SessionMiddleware → FlashMiddleware → CsrfTokenMiddleware
→ JsonBodyMiddleware → Router → (action) → NotFoundHandler fallback
```

Note the deliberate ordering: **CSRF runs before `JsonBodyMiddleware`**, so an invalid CSRF token is rejected before the body is parsed.

**Handler pattern:** every route maps to a single `__invoke(ServerRequestInterface): ResponseInterface` class (PSR-15 style), not MVC controllers. DI is constructor injection; the identity is read from `$request->getAttribute('identity')`.

**Key architectural facts:**

- All business logic is in `src/Service/*` — repositories do SQL only, actions do HTTP only. The Android app must call the API layer, never duplicate service logic.
- All JSON responses use one envelope: `{success, message, data, errors}` (`src/Service/Api.php`). `fail()` puts `$errors` in **both** `data` and `errors` keys.
- Table prefix is configurable (`{{%table}}` in all SQL), charset is explicitly utf8mb4.

---

## 2. Backend architecture

**Repositories (17, all in `src/Repository/`):**

| Repository | Tables | Key methods |
|---|---|---|
| `UserRepository` | `user` | findById, findByIdentifier, create, adjustBalance (atomic signed UPDATE), paginate (SORTABLE whitelist), softDelete/restore/countTrashed, setRole, hasOtherSuperAdmin, pushEnabled/setPushEnabled, firstStaffId |
| `IdentityRepository` | `user`, `api_token`, `activity_log` | login($identifier,$password,$ip,$ua,$remember) → error string or null; logout; register (role='user') |
| `ApiTokenRepository` | `api_token` | issue (access+refresh pair), resolve, rotate, revoke, revokeAllForUser |
| `TopupRepository` | `topup_request` | adminList (unresolved floats to top), stats, hasOpenRequest, pendingIds |
| `AdminWithdrawRepository` | `admin_withdraw_request` | adminList, stats, paged, claim (1800s stale expiry), release, markReviewed (`reviewed_by IS NULL` idempotency gate), hasOpenRequest |
| `ServiceOrderRepository` | `service_order` | create, update, findOwned, adminList (SORTABLE whitelist), openOrders, claim/release |
| `TransactionRepository` | `transaction` | ledger reads, paged, stats, forAdmin |
| `ServiceRepository` | `service`, `service_category` | catalog, slug lookup, purge (detaches orders) |
| `ServiceSubmissionRepository` | `service_submission` | byOrder (1:1), byUser |
| `SettingsRepository` | `site_setting` | all() (defaults+DB merge), get, putMany (whitelisted KEYS only), isSafeUrl |
| `ActivityLogRepository` | `activity_log` | create, forUser, all (searches action/description/ip), paged |
| `NotificationRepository` | `notification` | unread count, list, markRead, markAllRead |
| `QueueRepository` | `notification_queue`, `notification_delivery` | stats, drain (batch), requeue (dead letters) |
| `DeviceRepository` | `notification_device` | upsert (idempotent), deactivateTokens, activeTokens, purgeInactive |
| `PushSubscriptionRepository` | `push_subscription` | web push subscriptions |
| `BotConnectionRepository` | `bot_connection` | Telegram chat bindings (one active chat per user) |
| `AppReleaseRepository` | `app_release` | published (newest version_code wins), publish (demote+promote in one transaction), minVersionCode |
| `BulkJobRepository` | `admin_bulk_job` | enqueue, load, saveProgress, claim/markDone (job-theft detection) |
| `ReferralRepository` | `referral`, `referral_reward` | tracking, qualification, rewards |

**Services (10, all in `src/Service/`):** `Api` (JSON envelope), `AuthMiddleware` helpers, `TopupService` (request/approve/reject/cancel/claim/release/approveMany), `AdminWithdrawService` (request/approve/reject with self-approval refusal), `LedgerService` (creditUser/creditAdmin/debit with balance_before/after), `BulkJobService` (chunked async settle), `ServiceRequestAdminService` (settle/settleMany with refund guards), `PasswordResetService` (per-identifier throttling), `NotificationManager` (dispatch + fan-out), `ReceiptStorage` (file upload + relocation), `FCM` (in `Notification/Channel/FcmChannel`).

**Console commands (12, `src/Console/`):** `app:super-admin` (SuperAdminCommand — the ONLY way to create a superadmin, `--promote`, `--dry-run`), `app:staff`, `app:bulk-work` (drains admin_bulk_job), `app:notification-work` (drains notification_queue), `app:notification-purge`, `app:fcm:check` (credential dry-run, `--token` test send), `app:web-push:check`, `app:push-watch` (SYSTEM_ALERT → firstStaffId), `app:seed`, `app:order-seed`, `app:api-key-regenerate`, `app:twa-fingerprint`, `app:hello`.

---

## 3. Database architecture

**23 migrations**, all additive-friendly (guarded DDL, `safe()` helpers that swallow duplicate-object errors). No destructive migration exists; the one big refactor (M240117) moved rows with ids preserved.

**Core tables:**

| Table | Purpose | Notable columns |
|---|---|---|
| `user` | all accounts (users + staff + admin + superadmin in one table) | id, username(64 UNIQUE), phone(20 UNIQUE), email(190 UNIQUE NULL), password_hash, status(16, default 'active'), role(16, default 'user'), avatar, balance decimal(12,2), api_key, free_searches, full_name, date_of_birth, referral_code, push_enabled, last_login_at, deleted_at (soft delete), created_at, updated_at |
| `service_category` | catalog categories | slug, name, accent |
| `service` | catalog entries | slug, name, price, variants/rules JSON, form_fields JSON, deleted_at |
| `service_order` | one row per order request (split from ledger in M240117) | user_id, service_id NULL, reference(32 UNIQUE), amount, status, metadata JSON, claimed_by/at, approved_by/at (revenue-paid marker), admin_note, cancel_reason, deliverable_* (path/name/mime/size/uploaded_at/uploaded_by) |
| `transaction` | **the ledger** — append-only money movements | user_id NULL (admin rows have none), admin_id, type(32), direction(8), amount, balance_before/after, description, service_order_id, metadata |
| `topup_request` | user recharge requests | user_id, amount, method, sender_number, reference, receipt_*, status, reviewed_by/at, claimed_by/at, admin_note, reject_reason |
| `admin_withdraw_request` | staff/admin payout requests (money held at request time) | admin_id, amount, method [bank,bkash,nagad,rocket], account_details, status, transaction_id, claimed_by/at, reviewed_by/at, review_note, reject_reason |
| `api_token` | machine-auth tokens | user_id, token_hash char(64) UNIQUE, device_label, expires_at, last_used_at, revoked_at |
| `activity_log` | audit trail | user_id, action(64), description(500), ip_address(45), user_agent(512), metadata JSON |
| `notification` | in-app notifications | user_id, title, message, type, event, link, priority, read_at |
| `notification_queue` | outbound channel jobs (idempotent via dedupe_key UNIQUE) | notification_id, event, user_id, channel, status, attempts, max_attempts, available_at, payload JSON, dedupe_key char(40) UNIQUE, last_error |
| `notification_delivery` | per-attempt delivery record | queue_id, channel, status, attempts, provider_response JSON, provider_message_id UNIQUE, latency_ms |
| `notification_device` | FCM device tokens | user_id, device_token(512) UNIQUE, platform, device_name, app_version, is_active, last_seen_at |
| `notification_preference` | per-user channel overrides (no row = default) | user_id, event, channel, enabled |
| `notification_template` | seeded Bengali templates | event, channel, locale, title, body |
| `push_subscription` | web push (VAPID) | user_id, endpoint, keys |
| `bot_connection` | Telegram admin chats | user_id, chat_id bigint UNIQUE, username, verified_at |
| `site_setting` | key/value settings | setting_key, setting_value, updated_by, updated_at |
| `admin_bulk_job` | async bulk settle jobs | payload JSON {ids,status}, status, attempts, cursor, total, chunk_size, requested_by |
| `app_release` | APK releases | version_code, version_name, apk_path (outside web root), apk_size, sha256, min_version_code, release_notes, is_published |
| `service_submission` | 1:1 with service_order — form answers | service_order_id UNIQUE, user_id, service_id NULL, provider, field_values JSON |
| `referral`, `referral_reward` | referral system | referrer, referee, code, status, qualifying_recharge_count |

**Soft-delete convention:** `deleted_at IS NULL` = live; soft-deleted rows are hidden from every lookup until restored. UNIQUE indexes reserve usernames/phones of deleted accounts (restore conflicts are a real edge case).

**Money convention:** all money ops are atomic signed `UPDATE ... SET balance = balance + :delta` (`UserRepository::adjustBalance`); ledger rows carry `balance_before`/`balance_after`; approvals are guarded by `reviewed_by IS NULL` / `approved_by IS NULL` idempotency gates so a double-approve cannot double-pay.

---

## 4. Authentication architecture

**Source of truth:** `src/Auth/Identity.php`, `src/Auth/IdentityRepository.php`, `src/Auth/ApiTokenRepository.php`.

**Web session auth:** `IdentityRepository::login($identifier, $password, $ip, $ua, $remember)` — identifier is username OR phone. Failure paths return error strings: unknown identifier, deleted account (`deleted_at`), wrong password, disabled (`status !== 'active'`). On success: session regeneration (fixation defense), `touchLastLogin`, `activity_log` write (`auth.login`). Throttling via `AuthThrottle` (env `THROTTLE_MAX_ATTEMPTS=5`, `THROTTLE_DECAY_SECONDS=300`).

**Machine/token auth (the API the Android app will use):**

- Route: `POST /api/auth/{action}` → `src/Web/Api/AuthApiAction.php` (`login`, `refresh`, `logout`).
- Token wire format: `<selector>.<verifier>`; selector = `bin2hex(random_bytes(8))`, verifier = `bin2hex(random_bytes(32))`.
- Stored hash: `hash('sha256', selector.':'.verifier.':'.APP_KEY)` — the APP_KEY is a pepper, so DB leaks alone cannot forge tokens.
- `issue()` creates **two rows**: an access token (TTL `API_TOKEN_TTL_DAYS`, default 30 days) and a refresh token (2× TTL, `device_label` suffixed ` [refresh]`).
- `resolve()` validates format, revocation, expiry, and re-reads the user row (active + not deleted); stamps `last_used_at`.
- `rotate()` resolves the refresh token, revokes it, issues a new pair.
- `logout` revokes the presented token; `revokeAllForUser()` exists for "logout all devices".
- `ApiAuthMiddleware` (`src/Auth/ApiAuthMiddleware.php`): **bearer-first** — `Authorization: Bearer <selector.verifier>` → resolve → identity attribute. An *invalid presented* token returns `Api::unauthorized()` (401) with **no session fallback** (prevents token/session confusion). Absent bearer → inner session `AuthMiddleware` with role USER.

**Login response contract** (`AuthApiAction`): `{token, refresh, expires_at, user: {id, username, phone, balance, role}}`; logs `auth.api_token_issued`.

**Gap for Android:** access tokens live ~30 days (not "short-lived" in the OAuth sense). This is acceptable for an admin app but should be noted; the refresh rotation path already exists, so the Android auth flow (login → store → refresh on 401 → logout) is fully supported by the current backend with **zero backend changes**.

---

## 5. Authorization / RBAC architecture

**Source of truth:** `src/Auth/Identity.php` — **the actual role model, not an invented one:**

```text
Roles (user.role varchar(16)):  superadmin | admin | staff | user
STAFF_ROLES = ['admin', 'staff', 'superadmin']

Identity::isSuperAdmin()  → role === 'superadmin'
Identity::isAdmin()       → role === 'admin' || 'superadmin'
Identity::isStaff()       → role === 'staff'
Identity::canAccessAdmin()→ isAdmin() || isStaff()
Identity::isActive()      → status === 'active'
```

**There are exactly four roles. No MODERATOR/SUPPORT/FINANCE roles exist — the plan must not invent them.** Permission granularity is role-level, not permission-level: there is no `users.view`/`orders.approve` permission table; authority is derived from the role string plus per-action guards inside services (e.g. `AdminWithdrawService` refuses self-approval, `UserRepository::hasOtherSuperAdmin` guards demotion/trash of the last superadmin).

**Middleware:**

- `AuthMiddleware` (role USER) — user pages.
- `AdminMiddleware` — readonly wrapper constructing inner `AuthMiddleware` with role 'admin'; admits `canAccessAdmin()`.
- `SuperAdminMiddleware` — re-reads the **live DB row** via `findById` (closes the demotion window between login and request); on denial sets flash error, logs `admin.super_denied` with ip/ua/path, 302-redirects to the admin dashboard.
- `OptionalAuthMiddleware` — session auth if present, otherwise anonymous (used for web-push subscribe).

**Web route groups** (`config/common/routes.php`):

```text
/admin            AdminMiddleware          → dashboard, users, users/{id}, categories, services,
                                             orders, orders/{id}, transactions (redirect),
                                             transactions/{id}, ledger, topups, recharges/{id},
                                             referrals, notifications, notifications/{id}/retry,
                                             activity-logs
/admin            Admin+SuperAdmin         → withdraws, withdraws/{id}, staff, settings
```

**API route group** `AdminMiddleware` (admits admin/staff/superadmin; individual actions re-check):

```text
GET    /api/admin/dashboard            AdminDashboardApiAction   (canAccessAdmin)
GET|POST /api/admin/users              AdminUsersApiAction        (canAccessAdmin)
GET|POST /api/admin/orders             AdminOrdersApiAction       (canAccessAdmin)
POST   /api/admin/orders/bulk          AdminOrdersApiAction       (canAccessAdmin)
GET    /api/admin/topups               AdminTopupsApiAction       (canAccessAdmin)
GET|POST /api/admin/recharges/{id}     AdminRechargesApiAction    (canAccessAdmin)
GET    /api/admin/withdraws            AdminWithdrawsApiAction    (isSuperAdmin inside action)
GET    /api/admin/staff                AdminStaffApiAction        (isSuperAdmin)
GET|POST /api/admin/settings           AdminSettingsApiAction     (isSuperAdmin)
GET    /api/admin/logs                 AdminLogsApiAction         (canAccessAdmin)
GET    /api/admin/notifications        AdminNotificationsApiAction(canAccessAdmin)
POST   /api/admin/notifications/{id}/retry  (canAccessAdmin)
```

**Permission map (from actual code):**

| Capability | Who (role) | Enforcement point |
|---|---|---|
| View admin area | admin, staff, superadmin | `AdminMiddleware` / `canAccessAdmin()` |
| Dashboard metrics | admin, staff, superadmin | `AdminDashboardApiAction` |
| User list/create/toggle/role/reset/delete/restore | admin, staff, superadmin | `AdminUsersApiAction`; deleting users with role=admin and self-delete forbidden in-action |
| Order queue, claim, settle, bulk settle | admin, staff, superadmin | `AdminOrdersApiAction` → `BulkJobService` → `ServiceRequestAdminService` |
| Topup list, approve/reject recharge | admin, staff, superadmin | `AdminTopupsApiAction`, `AdminRechargesApiAction` → `TopupService` |
| Withdrawal list/approve/reject | **superadmin only** | `AdminWithdrawsApiAction::isSuperAdmin()` (GET only — see §9 gap); web: SuperAdminMiddleware group |
| Staff list | superadmin only | `AdminStaffApiAction` |
| Settings (site rules, wallet numbers, referral rules) | superadmin only | `AdminSettingsApiAction` |
| Activity logs | admin, staff, superadmin | `AdminLogsApiAction` |
| Notification queue view + dead-letter retry | admin, staff, superadmin | `AdminNotificationsApiAction` |
| Operator ledger (own earnings), own withdrawal | any staff-role account, self only | web `AdminLedgerAction`, `AdminWithdrawService` (self-approval refused) |
| Promote to superadmin | CLI only (`php yii app:super-admin`) | `SuperAdminCommand` |

---

## 6. Admin architecture (web panel)

The existing admin panel is **server-rendered** by 17 single-action classes in `src/Web/Admin/` (views in `resources/views/`): dashboard, users, user-edit, categories, services, orders (queue), order desk, transactions (redirect), transaction detail, ledger, topups, recharge desk, referrals, notifications, activity logs, withdraws, staff, settings.

The admin panel is the **functional spec for the Android app**: every screen the Android app needs already exists as a web page, and 10 of them already have JSON API twins (§8).

---

## 7. Super Admin architecture

Superadmin is a **`user.role` value, not a separate table** (confirmed by M240117 docblock: "Super-admin is a `user.role` value rather than a table"). Creation is **CLI-only** (`php yii app:super-admin [--promote=admin] [--dry-run]`) — there is no web or API path to create a superadmin. `hasOtherSuperAdmin($exceptId)` guards demotion/trash of the last superadmin.

Superadmin-only surface: withdraw approval (money out), staff management, system settings, platform-wide ledger view. `SuperAdminMiddleware` re-reads the live row per request so a demoted admin loses access immediately (and the denial is audited as `admin.super_denied`).

**Android implication:** a superadmin logs into the same app with the same token flow; the role string in the login response drives which screens/actions the UI offers, and **every privileged API call re-checks the role server-side** (the API actions already do this).

---

## 8. Existing API inventory

**Public (no auth):**

```text
GET  /api/push/key                       — VAPID public key
POST /api/push/subscribe | /unsubscribe  — web push (OptionalAuth)
GET  /api/app/version                    — installed-app update check (AppReleaseRepository)
POST /api/auth/{login|refresh|logout}    — token auth
```

**User API** (`ApiAuthMiddleware`: bearer OR session):

```text
GET  /api/dashboard                      — user dashboard stats
GET  /api/services, /api/services/{slug}
GET  /api/transactions
GET  /api/service-requests/{id}
POST /api/service-requests/watch         — poll rows shown on history page
POST /api/service-requests/{id}/{action}
GET|POST /api/devices, DELETE /api/devices/{id}   — FCM device registry
GET  /api/notifications, PATCH /api/notifications/{id}/read, POST /api/notifications/read-all
GET  /api/profile
```

**Admin API** (`AdminMiddleware`, then per-action role checks) — the 10 actions in `src/Web/Api/Admin/`:

| Endpoint | Action | Data returned |
|---|---|---|
| `GET /api/admin/dashboard` | `AdminDashboardApiAction` | userCount, categoryCount, serviceCount, txStats, openOrders, recentLogs(8), topupStats, unresolvedCount, unresolvedAmount |
| `GET /api/admin/users` | `AdminUsersApiAction` | paginated (PER_PAGE=15, q, sort, dir, trashed) + trashedCount |
| `POST /api/admin/users` | same | do=create (validated), toggle, role, reset (returns one-time `temporary_password`, 14 chars), delete (soft; no self/role=admin delete), restore, restore_all |
| `GET /api/admin/orders` | `AdminOrdersApiAction` | adminList (PER_PAGE=20, scopes all/mine/taken, SORTABLE whitelist) + claimedBy + openOrders |
| `POST /api/admin/orders` | same | do=bulk_status → `BulkJobService::enqueueSettle` (async job, chunked, job-theft detection) |
| `GET /api/admin/topups` | `AdminTopupsApiAction` | adminList (PER_PAGE=20, status filter) |
| `GET /api/admin/recharges/{id}` | `AdminRechargesApiAction` | topup row |
| `POST /api/admin/recharges/{id}` | same | do=approve → `TopupService::approve`; do=reject → `TopupService::reject` |
| `GET /api/admin/withdraws` | `AdminWithdrawsApiAction` | adminList (PER_PAGE=20, status, q) + stats — **superadmin only** |
| `GET /api/admin/staff` | `AdminStaffApiAction` | paginateStaff (PER_PAGE=20) |
| `GET\|POST /api/admin/settings` | `AdminSettingsApiAction` | GET → settings->all(); POST validates whitelisted KEYS, isSafeUrl for urls, numeric bounds → `putMany` |
| `GET /api/admin/logs` | `AdminLogsApiAction` | activity_log paged (PER_PAGE=25, q) |
| `GET /api/admin/notifications` | `AdminNotificationsApiAction` | notification_queue paged (PER_PAGE=20, status whitelist) + `QueueRepository::stats()` |
| `POST /api/admin/notifications/{id}/retry` | same | `requeue` (dead letters only) |

**Envelope** (`src/Service/Api.php`): `Api::ok($data, $message)`, `Api::fail($message, $errors, 400)`, `Api::unauthorized()` (401), `Api::forbidden()` (403); JSON with `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`.

---

## 9. Existing notification / FCM system

**Fully implemented, no SDK dependency** (shared-hosting build-free constraint):

- `src/Notification/NotificationEvent.php` — 19 event constants (USER_REGISTERED, SERVICE_REQUEST_*, TOPUP_*, REFERRAL_*, ADMIN_WITHDRAW_*, SYSTEM_ALERT).
- `src/Notification/NotificationManager.php` — `dispatch($event, $userId, $params, $link, $pushData, $adminLink)`: writes the in-app `notification` row **synchronously** (never fails the caller), then enqueues one `notification_queue` row per channel (best-effort, drained by `app:notification-work` cron). `contactChannels()` adds whatsapp (profile number = consent) and telegram (active bot chat). Admin fan-out is capped to staff/admin with self-skip (except SYSTEM_ALERT, where `PushWatchCommand` targets `UserRepository::firstStaffId()`). Dedupe key = `sha1(event|user|channel|entity)`.
- `src/Notification/Channel/FcmChannel.php` — FCM HTTP v1 via curl + OAuth2 service-account JWT (RS256, `kid` header, scope `firebase.messaging`, `urn:ietf:params:oauth:grant-type:jwt-bearer`). `isAvailable()` = `FIREBASE_CREDENTIALS_PATH` readable + `FIREBASE_PROJECT_ID` set. `send($userId, $payload)` multicasts all active device tokens, **deactivates dead tokens** (404/400 permanent, 5xx/429 transient), all-dead = success. `sendToToken` for `app:fcm:check --token=...`.
- `DeviceRepository` — `notification_device` upsert is idempotent; a token moving devices re-points the owner.
- `AppReleaseRepository` + `GET /api/app/version` — the installed-app update check (version_code/min_version_code, APK served from outside the web root via `/app/apk`).

**Admin-targeted push:** the infrastructure is per-user (`notification_device.user_id`), so an admin account with the admin app installed registers its FCM token under its own user id and receives exactly the events `NotificationManager` already fans out to staff/admin roles. **No backend change is needed for admin push** — only the Android-side registration (POST /api/devices) and the app deciding which events to surface.

---

## 10. Existing mobile-related functionality

- **`twa/`** — Trusted Web Activity wrapper (`applicationId online.broxlab.aliftools.twa`, minSdk 24, targetSdk 36, Java 17, gradle wrapper 8.13, androidbrowserhelper + androidx.core 1.17.0, signing via `keystore.properties` or `TWA_STORE_*` env). This wraps the **user-facing** site. It is a template for gradle/signing conventions only — it is NOT the admin app and must not be extended into one.
- **`GET /api/app/version`** + `app_release` table — update-check + signed APK distribution already exist and can serve the admin APK the same way (a second published release row, or a separate distribution channel).
- **`POST /api/devices`** — FCM token registration endpoint already exists and is role-agnostic.
- **`docs/android-admin-architecture-plan.md`** — a prior 18-section plan (constraints, reuse map, /api/admin route list, Android module layout, auth flow, feature mapping, screen contracts, offline strategy, push, security table, testing, CI/CD, 6-week phased plan, risks, open decisions). **Stale on one critical point:** it assumes an existing `android/` module. Everything else (reuse map, route list, screen contracts) is still accurate and should be updated, not rewritten.
- **`_upcom_raw/`** — reference UI captures (nid-pdf.html, pdf-form.html, server copy v2.html) for the user-facing flows, not admin.

---

## 11. Reusable components (directly, zero backend changes)

| Android need | Existing backend component |
|---|---|
| Login / token storage / refresh / logout / logout-all | `POST /api/auth/{login,refresh,logout}` + `ApiTokenRepository` (has `revokeAllForUser`) |
| Admin dashboard | `GET /api/admin/dashboard` (real metrics: userCount, openOrders, txStats, topupStats, unresolvedCount/Amount, recentLogs) |
| User management (list/search/filter/toggle/role/reset-password/delete/restore) | `GET|POST /api/admin/users` |
| Order queue + claim + bulk settle | `GET|POST /api/admin/orders`, `POST /api/admin/orders/bulk` |
| Recharge review (approve/reject) | `GET|POST /api/admin/recharges/{id}`, `GET /api/admin/topups` |
| Activity/audit log view | `GET /api/admin/logs` |
| Notification queue + retry | `GET /api/admin/notifications`, `POST /api/admin/notifications/{id}/retry` |
| Staff list (superadmin) | `GET /api/admin/staff` |
| Settings (superadmin) | `GET|POST /api/admin/settings` |
| FCM push to admins | `POST /api/devices` + `FcmChannel` + `NotificationManager` admin fan-out |
| App update check + APK download | `GET /api/app/version` + `GET /app/apk` + `AppReleaseRepository` |
| Withdrawal **view** (superadmin) | `GET /api/admin/withdraws` |

---

## 12. Missing components (gaps the implementation must close)

1. **No `android/` module at all** — the admin app is greenfield. Gradle/signing conventions can mirror `twa/app/build.gradle.kts`.
2. **`POST /api/admin/withdraws/{id}` does not exist.** `AdminWithdrawsApiAction` implements **GET only** (confirmed in file). The web route `/admin/withdraws/{id}` (GET+POST) and `AdminWithdrawService::approve()/reject()` both exist — only the API wiring is missing. This is the single confirmed backend gap; it must be added additively (new POST handling in the existing action or a new action, same service, same `isSuperAdmin` guard, same audit logging).
3. **No superadmin creation API** — by design (CLI-only). Android cannot and should not get one; a superadmin is promoted via `php yii app:super-admin`.
4. **No role-management UI/API** — role changes are limited to `POST /api/admin/users` do=role (user/staff/admin only; superadmin is CLI-only). No separate "roles" or "permissions" entity exists to build a management screen against.
5. **No wallet adjustment endpoint** — balance moves only through `LedgerService` inside business flows (topup approve, order settle, withdraw reject refund). There is no manual "adjust balance" API; the Android app must not invent one.
6. **No support-ticket system** — nothing to build a Support screen against.
7. **No per-permission grants** — RBAC is role-string only; no permission table to sync to Android.
8. **Access token TTL is 30 days** (`API_TOKEN_TTL_DAYS`) — long-lived by design; the Android app should still implement proactive refresh-on-401 using the existing refresh endpoint.
9. **No dedicated admin notification preferences** — `notification_preference` is per-user and generic; admins get the same events as the fan-out rules define.
10. **No deep-link route map for admin screens** — notification `link` values point at web routes (e.g. `/admin/topups`); the Android app needs a client-side route→screen mapping for those paths.

---

## 13. Security risks (existing system)

| Risk | Evidence | Severity |
|---|---|---|
| Admin API admits staff to user/order/recharge endpoints | `AdminMiddleware` admits `canAccessAdmin()`; staff role is intended for the queue desk, but staff can list users and see logs | Low (by design; staff is a trusted role) |
| Withdrawal approval API missing → today only the web form can approve payouts | §12.2 | Medium (API parity, not a vulnerability) |
| Soft-deleted username/phone reserved by UNIQUE index | `UserRepository` docs | Low (restore-conflict edge case) |
| 30-day access tokens | `API_TOKEN_TTL_DAYS` default | Low (refresh rotation + revocation exist) |
| Receipt files stored on disk under request-owned dirs | `ReceiptStorage` | Low (path handling internal) |
| `fail()` duplicates `$errors` into `data` | `src/Service/Api.php` | Info (client must read `errors` key) |
| CI: `android-actions/setup-android@v3` fails on legacy `sdkmanager tools` | user-provided CI log | Info (CI infra, not app code) |

**No hardcoded secrets found.** `APP_KEY` (token pepper), `FIREBASE_CREDENTIALS_PATH` (service-account JSON outside web root), `VAPID_*` keys are all env-driven (`.env.example` documents the contract). The Android app must source its API base URL from `BuildConfig`/local properties, never from a committed secret.

---

## 14. Technical debt

- `docs/android-admin-architecture-plan.md` is stale re: `android/` module existence (must be revised, not blindly followed).
- `ServiceOrderRepository` is 841 lines — large but cohesive; the Android app consumes it only via the API.
- The web admin is server-rendered; the Android app will be the second client of the same `/api/admin` surface, so **any behavior fix must be made in the shared service layer** to keep both clients consistent (user's §20 requirement).
- `queryOne()` returns null (not false) for no-row — a recurring trap already fixed in `DeviceRepository`; new code must not assume boolean false.
- Migrations rely on `safe()` swallowing duplicate-object errors — re-runnable, but masks real duplicate-key bugs if message matching drifts.

---

## 15. Recommended architecture

**Android (greenfield, `android/` module, package `com.aliftools.admin`, app name "Alif Tools Admin"):**

```text
Native Android (Kotlin, Jetpack Compose, Material 3)
MVVM + Clean Architecture:
  ui/        — Compose screens, ViewModels (StateFlow)
  data/
    remote/  — Retrofit + OkHttp (HTTPS only, bearer token interceptor,
               refresh-on-401 with synchronous rotation, 30s timeouts)
    local/   — EncryptedDataStore (tokens via androidx.security EncryptedSharedPreferences
               or DataStore with encryption; NEVER plaintext), DAO-less (no local DB mirror
               of business data — server is authoritative)
  domain/    — use cases (thin; all rules stay server-side)
  push/      — FCM (FirebaseMessaging; token → POST /api/devices; click → deep-link map)
  update/    — GET /api/app/version check + APK install flow
  biometric/ — BiometricPrompt gates app entry + re-auth for sensitive ops
```

Rationale vs. alternatives: the user spec's default stack (Kotlin/Compose/Retrofit/DataStore/FCM) matches the project (TWA already proves Android toolchain works on this machine; JDK 17 + SDK 36 available). No Hilt dependency needed beyond what's justified — constructor injection keeps the module small. No local Room mirror of admin data: the spec forbids stale/fake data and the backend is the single source of truth; offline = read-only cached last-success + explicit offline state, no offline write queue (spec §13).

**Backend:** additive only — one new POST endpoint family for withdrawals reusing `AdminWithdrawService`; nothing else changes. No new tables, no migrations required for the Android app itself.

```text
Admin Android App
    ↓ HTTPS + Bearer
Yii 3 /api/admin/*  (AdminMiddleware + per-action role checks)
    ↓
Services (TopupService, AdminWithdrawService, BulkJobService, ...)
    ↓
Repositories → MySQL/MariaDB
```

---

## 16. Migration risks

- **Zero database migrations required** for the Android app (all tables exist; `notification_device`, `api_token`, `app_release` are already role-agnostic).
- The only backend change (withdraw approve/reject API) is **additive**: a new route/action behavior reusing an existing service. Web admin routes are untouched → zero regression risk to the existing panel.
- Risk of touching `AdminMiddleware`: none planned — it is reused as-is.
- APK distribution: `app_release.publish()` promotes one release; if the admin APK is distributed through the same table, `min_version_code` semantics apply to the user TWA too — recommend a separate `app_release` row with its own versioning or a separate distribution endpoint to avoid bricking either app.
- Shared-hosting constraint: backend code must remain composer-free at deploy time (pure PHP, no compile step) — the FCM channel's curl-only design is the house style to follow.

---

## 17. Implementation dependencies

**Machine (verified):** Windows 10 + Git Bash; JDK 17 at `/c/Users/Alif/tools/jdk17`; Android SDK at `/c/Users/Alif/tools/android-sdk` (platforms/android-36, build-tools 35.0.0, cmdline-tools/latest); Gradle 8.13/9.2.0 dists cached in `~/.gradle`; adb 37.0.1 at `/c/Users/Alif/Desktop/platform-tools/adb.exe`. **No `java` on PATH** — every gradle invocation must set `JAVA_HOME`. TWA `assembleRelease` already verified working (BUILD SUCCESSFUL, signed APK 3.7 MB).

**Unverified:** PHP runtime availability (needed later for `composer test` / Codeception suites — 26 tests currently passing per README).

**Backend dependencies:** none new (all reuse).
**Android dependencies:** Kotlin, Compose BOM, Retrofit/OkHttp, DataStore, androidx.security (EncryptedSharedPreferences), Biometric, Firebase Messaging (FCM), Navigation-Compose, Material 3.
**Secrets:** none committed; signing via `keystore.properties` / env vars (mirror `twa` pattern); FCM credentials stay server-side (`FIREBASE_CREDENTIALS_PATH`), Android only needs the project ID for the client SDK.

**Critical workflow tests to run later:** admin login, superadmin login, unauthorized user (403), forbidden permission, token expiration, refresh token, logout, order approval, recharge approval, notification receive, notification deep link.

---

## Appendix A — Exact route table (admin-relevant subset)

```text
POST   /api/auth/{login|refresh|logout}                    AuthApiAction
GET    /api/app/version                                     AppVersionApiAction
POST   /api/devices ; GET /api/devices ; DELETE /api/devices/{id}   DeviceApiAction
GET    /api/admin/dashboard                                 AdminDashboardApiAction
GET|POST /api/admin/users                                   AdminUsersApiAction
GET|POST /api/admin/orders ; POST /api/admin/orders/bulk    AdminOrdersApiAction
GET    /api/admin/topups                                    AdminTopupsApiAction
GET|POST /api/admin/recharges/{id}                          AdminRechargesApiAction
GET    /api/admin/withdraws                                 AdminWithdrawsApiAction (superadmin)
GET    /api/admin/staff                                     AdminStaffApiAction (superadmin)
GET|POST /api/admin/settings                                AdminSettingsApiAction (superadmin)
GET    /api/admin/logs                                      AdminLogsApiAction
GET    /api/admin/notifications ; POST /api/admin/notifications/{id}/retry   AdminNotificationsApiAction
GET    /api/push/key ; POST /api/push/subscribe|unsubscribe PushApiAction
```

## Appendix B — Role capability matrix (source: `src/Auth/Identity.php` + route groups)

| Screen/action | user | staff | admin | superadmin |
|---|:-:|:-:|:-:|:-:|
| Admin login (token) | — | ✔ | ✔ | ✔ |
| Dashboard | — | ✔ | ✔ | ✔ |
| Users management | — | ✔ | ✔ | ✔ |
| Orders queue / bulk settle | — | ✔ | ✔ | ✔ |
| Topups / recharge approve-reject | — | ✔ | ✔ | ✔ |
| Activity logs | — | ✔ | ✔ | ✔ |
| Notification queue + retry | — | ✔ | ✔ | ✔ |
| Withdrawals approve/reject | — | — | — | ✔ |
| Staff list | — | — | — | ✔ |
| System settings | — | — | — | ✔ |
| Platform ledger | — | — | — | ✔ |
| Become superadmin | CLI only (`app:super-admin`) | | | |

## Appendix C — Confirmed gaps → implementation work items

1. **Backend (additive):** add `POST /api/admin/withdraws/{id}` (do=approve / do=reject) in `AdminWithdrawsApiAction`, guarded by `isSuperAdmin()`, delegating to `AdminWithdrawService::approve()/reject()`, activity-logged. Mirror the web `AdminWithdrawsAction` contract.
2. **Android (greenfield):** `android/` module per §15.
3. **Docs:** revise `docs/android-admin-architecture-plan.md` (stale `android/` assumption); produce the six deliverable docs after implementation.
