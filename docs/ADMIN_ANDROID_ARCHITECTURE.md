# Alif Tools Admin Android — Architecture, API Gap Analysis, Security & Implementation Plan

**Status:** Phase 2 plan (post-audit). Supersedes `docs/android-admin-architecture-plan.md` — that document's core reuse map and screen contracts remain valid, but its assumption of a pre-existing `android/` module is **stale** (see `docs/ADMIN_ANDROID_CODEBASE_AUDIT.md` §1). This document is the single source of truth going forward.

**Decisions are evidence-backed:** every reused component cites the exact file/class/method from the audit.

---

# Part 1 — Architecture Plan

## 1.1 System architecture

```text
             Yii 3 Backend (existing, additive changes only)
              /                                            \
             /                                              \
    Admin Web (server-rendered,                    Admin Android (new, native)
    resources/views)                               com.aliftools.admin
             \                                              /
              \                                            /
               Services (shared business rules — the ONLY place logic lives)
                    ↓
               Repositories → MySQL/MariaDB
```

Both clients hit the same `/api/admin` surface and the same services. A permission change or business-rule fix made in the service layer is automatically consistent on both clients (user requirement §20).

## 1.2 Android architecture (chosen stack)

```text
Native Android, Kotlin, minSdk 26, targetSdk 36
Jetpack Compose + Material 3
MVVM + Clean Architecture (UI / Domain / Data)
Repository pattern; ViewModel + StateFlow; Kotlin Coroutines
Retrofit + OkHttp (HTTPS-only, bearer interceptor, refresh-on-401)
DataStore (protobuf-free, preferences) + EncryptedSharedPreferences for tokens
Navigation-Compose (role-aware nav graph)
Firebase Cloud Messaging (client SDK only; server side already exists)
BiometricPrompt (app unlock + re-auth for sensitive operations)
No Hilt/Dagger beyond what's needed — constructor injection, small module
No Room mirror of business data — server is the single source of truth
```

**Why this stack:** the user spec's default recommendation (Kotlin / Compose / MVVM / Retrofit / Coroutines / StateFlow / Navigation / DataStore / FCM) matches the verified toolchain on this machine (JDK 17, SDK 36, Gradle cached) and the existing `twa/` module proves the Android build works here. Alternatives rejected:

- **Flutter / React Native / PWA-only** — rejected: user mandated native; the TWA already exists for the user-facing site and is explicitly not the admin app.
- **Room local mirror** — rejected: spec forbids stale/mock data; admin data is authoritative server-side; an offline write queue is explicitly forbidden unless the backend supports it (it doesn't — §13 of the spec).
- **Hilt** — rejected as unnecessary weight for a focused admin app; constructor injection suffices.

## 1.3 Module layout

```text
android/
  settings.gradle.kts            — include :app
  build.gradle.kts               — root: plugin versions, JDK 17 toolchain
  gradle.properties              — org.gradle.jvmargs, android.useAndroidX
  app/
    build.gradle.kts             — applicationId com.aliftools.admin, signing from
                                   keystore.properties or ADMIN_STORE_* env (mirrors twa)
    src/main/
      AndroidManifest.xml        — INTERNET, usesCleartextTraffic=false
      java/com/aliftools/admin/
        AlifToolsAdminApp.kt
        di/AppModule.kt          — manual DI (Retrofit, repos, ViewModel factory)
        data/remote/
          ApiService.kt          — Retrofit interface (all endpoints)
          ApiResponse.kt         — {success,message,data,errors} envelope
          AuthInterceptor.kt     — bearer attach + 401 → refresh + retry
          ApiClient.kt
          dto/                   — request/response models (Gson/Moshi)
        data/local/
          SecureTokenStore.kt    — EncryptedSharedPreferences (access/refresh)
          Prefs.kt               — DataStore (role, username, biometric flag)
        domain/repository/       — AdminRepository (use-case style wrappers)
        ui/
          auth/                  — Login, (biometric unlock)
          dashboard/
          users/                 — list, detail, create, edit
          orders/                — queue, detail, bulk settle
          recharges/             — topup list, detail approve/reject
          withdraws/             — superadmin: list, approve/reject
          notifications/         — queue, retry
          logs/                  — activity log viewer
          staff/                 — superadmin: staff list
          settings/              — superadmin: site settings
          components/            — status badges, dialogs, empty/error states
        push/AdminFcmService.kt  — token registration (POST /api/devices), deep links
        update/UpdateChecker.kt  — GET /api/app/version → prompt → APK install
        biometric/BiometricGate.kt
    src/test/                    — JVM unit tests (ViewModel, repository, API)
```

**Package:** `com.aliftools.admin` (user spec default; branding-consistent with `online.broxlab.aliftools.twa`'s "aliftools" stem — kept lowercase-reversed per Android convention). **App name:** "Alif Tools Admin".

## 1.4 Auth flow (reuses existing backend verbatim)

```text
Login screen (username/phone + password)
  → POST /api/auth/login {identifier, password}
  → {token, refresh, expires_at, user:{id,username,phone,balance,role}}
  → store in EncryptedSharedPreferences (never plaintext; password never stored)
  → role drives nav graph (staff/admin vs superadmin screens)
  → FCM token registered under this user id (POST /api/devices)

API calls: Authorization: Bearer <token>
  → 401 → POST /api/auth/refresh {refresh} → new pair → retry once
  → refresh fails/revoked → clear store → Login

Logout → POST /api/auth/logout (revokes presented token)
Logout-all-devices → (new) uses existing revokeAllForUser via logout-all
  endpoint OR superadmin clears tokens server-side — see API doc §4.

App open (biometric enabled) → BiometricPrompt → decrypt token → Dashboard
```

Backend facts this relies on (`src/Auth/ApiTokenRepository.php`, `src/Web/Api/AuthApiAction.php`): token = `<selector>.<verifier>`, stored as `sha256(selector:verifier:APP_KEY)`; access TTL `API_TOKEN_TTL_DAYS` (30d default), refresh = 2×; `resolve()` re-reads the live user row (deleted/disabled accounts stop working immediately); rotation revokes the used refresh token.

## 1.5 Screen ↔ API ↔ web-page contract

| Android screen | API | Web equivalent (parity proof) |
|---|---|---|
| Dashboard | `GET /api/admin/dashboard` | `AdminDashboardAction` /admin |
| Users list/search/filter | `GET /api/admin/users` | AdminUsersAction /admin/users |
| User create/toggle/role/reset/delete/restore | `POST /api/admin/users` | same + AdminUserEditAction /admin/users/{id} |
| Orders queue (all/mine/taken, sort) | `GET /api/admin/orders` | AdminOrdersAction /admin/orders |
| Bulk settle | `POST /api/admin/orders/bulk` | same (async job) |
| Topups list | `GET /api/admin/topups` | AdminTopupsAction /admin/topups |
| Recharge approve/reject | `GET\|POST /api/admin/recharges/{id}` | AdminRechargeAction /admin/recharges/{id} |
| Withdrawals (superadmin) | `GET /api/admin/withdraws` + **NEW** `POST /api/admin/withdraws/{id}` | AdminWithdrawsAction /admin/withdraws/{id} |
| Staff (superadmin) | `GET /api/admin/staff` | AdminStaffAction /admin/staff |
| Settings (superadmin) | `GET\|POST /api/admin/settings` | AdminSettingsAction /admin/settings |
| Activity logs | `GET /api/admin/logs` | AdminLogsAction /admin/activity-logs |
| Notification queue + retry | `GET /api/admin/notifications`, `POST /api/admin/notifications/{id}/retry` | AdminNotificationsAction /admin/notifications |

## 1.6 Offline / network UX (spec §13)

States per screen: Loading (skeleton) → Success / Empty / Error (retry) / Offline (clear message) / Unauthorized (re-login) / Forbidden (role notice) / Server Error. **No offline write queue.** Read-only last-success cache is acceptable for dashboard numbers only, clearly labeled as cached.

## 1.7 UI/UX (spec §17–18)

Material 3, dark mode (follow system), Bengali strings already arrive from the server (JSON_UNESCAPED_UNICODE) — the app UI itself is English-first with server-provided Bengali labels where the backend sends them (e.g. `ROLE_LABELS`, status labels). Pull-to-refresh, pagination (perPage values from each action), search, filters, confirmation dialogs for every mutating action, status badges matching backend status enums. No decorative animation.

---

# Part 2 — API Gap Analysis

## 2.1 Coverage of every required admin capability

| Capability | Existing endpoint | Verdict |
|---|---|---|
| Login/refresh/logout | `POST /api/auth/{login,refresh,logout}` | ✅ reuse |
| Dashboard metrics (real data only) | `GET /api/admin/dashboard` | ✅ reuse |
| Users CRUD-lite (list/create/toggle/role/reset/delete/restore) | `GET\|POST /api/admin/users` | ✅ reuse |
| Orders list/claim-context/bulk settle | `GET\|POST /api/admin/orders`, `POST /api/admin/orders/bulk` | ✅ reuse |
| Topups list | `GET /api/admin/topups` | ✅ reuse |
| Recharge approve/reject | `GET\|POST /api/admin/recharges/{id}` | ✅ reuse |
| Withdrawals **list** | `GET /api/admin/withdraws` | ✅ reuse |
| Withdrawals **approve/reject** | — | ❌ **GAP 1** (only backend change needed) |
| Staff list | `GET /api/admin/staff` | ✅ reuse |
| Settings read/write | `GET\|POST /api/admin/settings` | ✅ reuse |
| Activity logs | `GET /api/admin/logs` | ✅ reuse |
| Notification queue + dead-letter retry | `GET /api/admin/notifications`, `POST .../{id}/retry` | ✅ reuse |
| FCM token register/list/delete | `GET\|POST /api/devices`, `DELETE /api/devices/{id}` | ✅ reuse |
| App update check | `GET /api/app/version` | ✅ reuse |
| Logout all devices | `revokeAllForUser()` exists, no endpoint | ⚠️ **GAP 2** (optional; add `POST /api/auth/logout-all`) |
| Balance adjustment | none (by design — money moves only via LedgerService flows) | 🚫 not building (spec forbids inventing) |
| Role/permission management UI | role change exists via users POST (user/staff/admin); superadmin CLI-only | 🚫 no new surface |
| Support tickets | none exists | 🚫 not building |

## 2.2 GAP 1 — Withdraw approve/reject API (the only mandatory backend change)

**Evidence:**
- `src/Web/Api/Admin/AdminWithdrawsApiAction.php` implements **GET only** (no POST branch).
- Web route exists: `Route::methods(['GET','POST'], '/admin/withdraws/{id}')` → `AdminWithdrawsAction` (superadmin group).
- Service exists: `src/Service/AdminWithdrawService.php` — `approve($id, $reviewer)` (refuses self-approval, `markReviewed` idempotency gate `reviewed_by IS NULL AND status IN (pending,review)`, dispatches `ADMIN_WITHDRAW_APPROVED`, logs `withdraw.approved`) and `reject($id, $reviewer, $reason)` (requires non-empty reason, refunds via `LedgerService::creditAdmin` **before** `markReviewed`, dispatches `ADMIN_WITHDRAW_REJECTED`).

**Planned change (additive, zero regression risk):**

```text
Route:  POST /api/admin/withdraws/{id}        (new route line in the existing
                                              /api/admin group — AdminMiddleware
                                              already guards the group)
Action: AdminWithdrawsApiAction::__invoke     (add POST branch; keep GET as-is)
        ├─ isSuperAdmin() guard (403 otherwise — same as GET)
        ├─ do=approve → AdminWithdrawService::approve(id, identity)
        └─ do=reject  → AdminWithdrawService::reject(id, identity, reason)
        Response: Api::ok([...fresh row via findById...])
```

Contract mirrors `AdminRechargesApiAction` exactly (same `do=` convention, same service-delegation pattern, same envelope). No new table, no migration, no change to web routes.

## 2.3 GAP 2 (optional) — Logout all devices

`ApiTokenRepository::revokeAllForUser($userId)` exists but has no route. Low-value for v1 (admin can revoke by logging out per device; token theft is handled by password reset + logout). **Decision: defer.** If added later: `POST /api/auth/logout-all` → `revokeAllForUser(identity->id)` + activity log `auth.api_token_revoked_all`.

## 2.4 API versioning decision

Existing API is unversioned (`/api/...`). Introducing `/api/v1/` now would fork every route and both clients. **Decision: keep the existing unversioned paths** — the envelope + whitelisted params already make it stable; breaking changes would require a v2 group later, which is a cleaner break than retrofitting v1 now. Documented as an open decision.

---

# Part 3 — Security Plan

## 3.1 Transport & client

- HTTPS only: `android:usesCleartextTraffic="false"`, OkHttp enforces TLS, base URL from `BuildConfig`/local properties (never a committed secret).
- No secrets in the APK: no API keys, no Firebase server credentials, no APP_KEY. FCM client SDK needs only the project number (public identifier, not a secret). Server credentials (`FIREBASE_CREDENTIALS_PATH`) stay server-side.
- Tokens: `EncryptedSharedPreferences` (AndroidX Security Crypto) — keys in Android Keystore. Passwords never stored. Biometric unlocks the keystore-backed encryption; token is decrypted only after biometric success.

## 3.2 Authentication & authorization (server-side, existing)

- Bearer-first `ApiAuthMiddleware`: invalid presented token → 401, no session confusion.
- `AdminMiddleware` on the whole `/api/admin` group (admin/staff/superadmin only).
- Per-action role re-checks: `isSuperAdmin()` inside withdraw/staff/settings actions — Android must never rely on hiding UI alone; the server is the enforcement point (spec §28).
- `SuperAdminMiddleware` semantics (live row re-read) apply to web; the API actions re-read identity from the token's user row on every request via `resolve()`, so demotion/disable takes effect immediately for API too.

## 3.3 Sensitive-operation re-authentication (spec §8)

Biometric re-prompt before: withdrawal approve/reject (money out), user delete, role change, settings save, bulk settle. Implemented client-side as a gate on the confirmation dialog; the server-side authority remains the token. (A server-issued step-up challenge is a possible future hardening — not required by the current backend.)

## 3.4 Audit logging (backend-authoritative, existing)

Every mutating flow already writes `activity_log` via `ActivityLogRepository::create()` (user_id, action, description, ip, user_agent, metadata): `auth.login`, `auth.api_token_issued`, `topup.approved/rejected`, `withdraw.approved/rejected`, `admin.super_denied`, bulk settle summaries, settings updates (`putMany` records `updated_by`). The Android app adds no client-side audit log — the server is the audit trail.

## 3.5 Rate limiting & brute force

Login throttle exists (`AuthThrottle`, env `THROTTLE_MAX_ATTEMPTS=5` / `THROTTLE_DECAY_SECONDS=300`) inside the login flow (per-identifier, so it covers the API login too). Android shows the server's error message verbatim.

## 3.6 Input/output validation

Server-side validation is already whitelist-driven (username regex, phone `^01[3-9][0-9]{8}$`, status/role/sort whitelists, settings KEYS whitelist, `isSafeUrl`). Android sends only documented fields; it never constructs SQL. Output: JSON with `JSON_UNESCAPED_UNICODE`; Android renders server strings as text (no HTML), so XSS surface is nil.

## 3.7 Token lifecycle

- Access token 30d, refresh 60d (env-tunable). Rotation on every refresh; used refresh token revoked.
- Logout revokes the presented token; password reset / account disable invalidates tokens at `resolve()` (live row check).
- Device registry (`notification_device`) lets an admin see registered devices via the web; FCM dead tokens are auto-deactivated by `FcmChannel`.

## 3.8 Threat model summary

| Threat | Mitigation (existing/new) |
|---|---|
| Token theft | EncryptedSharedPreferences + Keystore; revocation; live-row checks; optional per-device logout |
| MITM | HTTPS-only, cleartext disabled |
| Reverse engineering | No secrets in APK; R8 minify for release; server-side authority |
| CSRF | N/A for bearer API (no session cookies used by Android); web panel keeps its CSRF middleware |
| Privilege escalation | Server-side role checks per action; superadmin CLI-only creation |
| Fake data | No mock layer — every screen reads the live API (spec requirement) |

---

# Part 4 — Implementation Plan (phases)

## Phase 1 — Codebase audit ✅
**Deliverable:** `docs/ADMIN_ANDROID_CODEBASE_AUDIT.md`. No code changes.

## Phase 2 — Plans ✅ (this document)
**Deliverables:** this doc + `docs/ADMIN_ANDROID_API.md` + `docs/ADMIN_ANDROID_SECURITY.md`.

## Phase 3 — Backend (additive only)
**Files:**
- `config/common/routes.php` — add `Route::methods(['POST'], '/withdraws/{id}')->action(AdminWithdrawsApiAction::class)->name('api-admin-withdraw')` inside the existing `/api/admin` group.
- `src/Web/Api/Admin/AdminWithdrawsApiAction.php` — add POST branch (do=approve/do=reject) delegating to `AdminWithdrawService`; keep GET untouched.
**Routes:** `POST /api/admin/withdraws/{id}`.
**DB changes:** none.
**Dependencies:** none (service + repository already wired in DI).
**Risks:** none to web admin (separate action class; web routes untouched).
**Tests:** PHPUnit/Codeception functional test — superadmin approves/rejectes via API; admin (non-super) gets 403; double-approve is idempotent (second call no-ops via `markReviewed` gate); reject requires reason; reject refunds ledger before status stamp.

## Phase 4 — Android project setup
**Files:** `android/` module skeleton per §1.3; `app/build.gradle.kts` (applicationId `com.aliftools.admin`, minSdk 26, targetSdk 36, Java 17, signing via `keystore.properties`/`ADMIN_STORE_*` mirroring `twa`); manifest with `usesCleartextTraffic=false`; debug + release build types; R8 for release.
**Dependencies:** Compose BOM, Retrofit+OkHttp+Moshi, DataStore, EncryptedSharedPreferences, Navigation-Compose, Material 3, Firebase BOM (messaging), Biometric, JUnit, Turbine (Flow testing).
**Verification:** `./gradlew :app:assembleDebug` with `JAVA_HOME=/c/Users/Alif/tools/jdk17`.

## Phase 5 — Auth + Dashboard
Login (POST /api/auth/login), secure token store, auth interceptor with refresh-on-401, role-aware nav graph, dashboard screen (real metrics from `GET /api/admin/dashboard`), states (loading/empty/error/offline), pull-to-refresh.
**Tests:** ViewModel tests (login success/failure/401-refresh), token store encryption round-trip.

## Phase 6 — Users
List (pagination, search, sort, trashed filter), detail, create (validated forms matching server regexes), toggle status, role change, password reset (display one-time `temporary_password`), soft delete, restore. Confirmation dialogs; biometric re-auth for delete/role-change.

## Phase 7 — Orders/Services
Queue (all/mine/taken tabs, sort whitelist), order detail, bulk settle (POST /api/admin/orders/bulk → job accepted → poll list for progress), status badges (pending/review/processing/completed/failed/cancelled).

## Phase 8 — Recharge/Transactions
Topup list (status filter), recharge detail approve/reject (with note/reason), ledger/transaction views where the API exposes them.

## Phase 9 — Notifications + FCM
In-app notification list (user API), admin queue list + dead-letter retry, FCM service (token → POST /api/devices; message click → deep-link map of web route paths → Compose screens), notification preference toggle (PATCH via user API where applicable).

## Phase 10 — Super Admin
Withdrawals (list + **approve/reject** via the new Phase-3 endpoint), staff list, settings editor (whitelisted keys, checkbox/url/number types), role-aware UI gating (superadmin-only screens hidden for admin/staff; server still 403s if forced).

## Phase 11 — Security hardening
R8 full mode, network security config, token encryption audit, biometric gate for sensitive ops, lint + dependency check, no-logging-of-tokens rule, ProGuard rules for Retrofit/Moshi.

## Phase 12 — Testing
**Backend:** PHP syntax lint on changed files; Codeception functional tests for the new withdraw API (approve/reject/forbidden/idempotency).
**Android:** JVM unit tests — ViewModels (every screen state), AdminRepository (success/error mapping), AuthInterceptor (refresh flow), dto parsing (envelope incl. the `errors`-in-`data` quirk).
**Critical workflows (must pass):** admin login, superadmin login, unauthorized user (403), forbidden permission, token expiration, refresh token, logout, order approval, recharge approval, notification receive, notification deep link.
**Manual:** on-device install via adb (platform-tools verified), real login against the deployment, approve/reject a test withdrawal.

## Phase 13 — Production build + documentation
Signed release APK (and AAB) with a dedicated release keystore (never committed; `keystore.properties` gitignored); versionCode/versionName scheme; `docs/ADMIN_ANDROID_SETUP.md` + `docs/ADMIN_ANDROID_DEPLOYMENT.md`; final deliverables report.

## Dependency graph

```text
Phase 3 (backend)  ──┐
                     ├─→ Phase 5 (auth+dashboard) → 6 → 7 → 8 → 9 → 10
Phase 4 (setup) ─────┘                                                      ↓
                                                                      11 → 12 → 13
```

Phases 3 and 4 are independent and can run in parallel; everything else is sequential.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Shared-hosting deploy of backend change | Change is 2 files, pure PHP, no composer rebuild needed |
| CI `setup-android@v3` legacy sdkmanager failure (seen in user CI log) | Use `android-actions/setup-android@v4`+ or cmdline-tools install in workflow; local build already verified working |
| 30-day access token vs "short-lived" ideal | Documented; refresh rotation exists; env-tunable (`API_TOKEN_TTL_DAYS`) |
| Admin app distributed via same `app_release` table as user TWA | Use a distinct release row/channel; `min_version_code` semantics reviewed so neither app bricks |
| Bengali text rendering | Compose handles Unicode natively; server already emits unescaped UTF-8 JSON |

## Open decisions (flagged, not blocking)

1. APK vs AAB distribution for the admin app (AAB requires Play Store; APK allows direct install — recommend APK + `app_release` row, matching the existing `/app/apk` flow).
2. Separate `app_release` channel per app (see §2.4/risks).
3. API versioning (§2.4 — keep unversioned for now).
4. Step-up server challenge for biometric re-auth (client-side gate for v1).
