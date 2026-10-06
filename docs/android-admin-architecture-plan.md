# Native Android Admin / Super Admin App — Architecture & Implementation Plan

**Project:** Alif Tools (All Seba)  
**Platform:** Yii 3 PHP backend + MySQL + Native Android (Kotlin, Jetpack Compose)  
**Audience:** Ops staff, admins, and the platform owner (superadmin)  
**Constraint:** Zero duplication of business rules — all validation, RBAC, and money movement stay on the server.

---

## 1. Goal & Non-Goals

### 1.1 Goal
Ship a production-ready Android application that gives **admin**, **staff**, and **superadmin** roles first-class mobile access to the existing Alif Tools admin workflows, using the current backend as the single source of truth.

### 1.2 Non-Goals
- A user-facing consumer app (the existing Android APK and TWA already cover that).
- Porting backend business logic into Kotlin. The Android client is a thin, authenticated API consumer.
- Replacing the web admin panel. The web UI remains canonical for complex workflows (bulk exports, deep log inspection, settings editing).

---

## 2. Constraints & Hard Rules

| Rule | Rationale |
|---|---|
| **Server-side RBAC mandatory** | Every admin endpoint must re-check `identity->role` regardless of what the APK asks for. |
| **No secrets hardcoded** | `APP_KEY`, Firebase credentials, VAPID keys, and API base URL come from env / `BuildConfig` / NDK keystore properties only. |
| **No destructive DB changes** | New columns/tables must be nullable or have safe defaults; no dropping or renaming production data. |
| **No breaking existing web admin** | New routes are additive (`/api/admin/*`); existing HTML admin routes are untouched. |
| **Reuse repositories and services** | New admin API actions resolve the same `UserRepository`, `ServiceOrderRepository`, `TopupRepository`, etc., used by the web actions. |
| **Envelope contract preserved** | All JSON responses use `{success, message, data, errors}` via `App\Service\Api`. |

---

## 3. Existing Backend Assets to Reuse

- **Auth:** `App\Auth\ApiTokenRepository` (selector + HMAC-verifier tokens, refresh rotation, revoke/revoke-all).
- **Middleware:** `ApiAuthMiddleware`, `AdminMiddleware`, `SuperAdminMiddleware`.
- **Repositories:** `UserRepository`, `ServiceOrderRepository`, `TopupRepository`, `TransactionRepository`, `NotificationRepository`, `SettingsRepository`, `ActivityLogRepository`.
- **Services:** `BulkJobService`, `OrderExportService`, `StatusPresenter`, `NotificationManager`.
- **Routes:** `config/common/routes.php` — the `/api` group already authenticates via bearer token or session.
- **Env:** `App\Env` already exposes `API_TOKEN_TTL_DAYS`, `APP_KEY`, Firebase/VAPID/telegram, TWA config.
- **Android baseline:** `android/` module already has Retrofit + OkHttp, Kotlinx Serialization, Jetpack Compose, FCM, DataStore token storage, and an in-app update checker.

---

## 4. High-Level Architecture

```
┌─────────────────────┐       Bearer / Refresh        ┌───────────────────────┐
│  Android Admin APK  │ ─────────────────────────────▶ │  Yii 3 Backend        │
│  (Compose UI)       │ ◀───────────────────────────── │  /api/auth/*          │
│                     │   JSON envelope {success,…}     │  /api/admin/* ← NEW   │
│  - AuthInterceptor  │                                │  - AdminMiddleware    │
│  - TokenStore       │                                │  - SuperAdminMw       │
│  - OkHttp + Retrofit│                                │  - Repositories       │
│  - FCM + DataStore  │                                │  - Services           │
└─────────────────────┘                                └───────────────────────┘
        │  │                                                      │
        │  │  Push (FCM data messages)                            │
        ▼  ▼                                                      ▼
   Android System                                   MySQL (unchanged schema)
```

**Key principle:** The Android app renders screens; the backend enforces rules.

---

## 5. Backend Changes (Additive Only)

### 5.1 New Admin JSON API Namespace

Add a new route group in `config/common/routes.php`:

```
POST   /api/auth/login          (existing)
POST   /api/auth/refresh        (existing)
POST   /api/auth/logout         (existing)

GET    /api/admin/dashboard
GET    /api/admin/users
POST   /api/admin/users
GET    /api/admin/orders
POST   /api/admin/orders/bulk
GET    /api/admin/topups
POST   /api/admin/topups/{id}
GET    /api/admin/recharges
POST   /api/admin/recharges/{id}
GET    /api/admin/withdraws
POST   /api/admin/withdraws/{id}
GET    /api/admin/staff
POST   /api/admin/staff
GET    /api/admin/settings
POST   /api/admin/settings
GET    /api/admin/logs
GET    /api/admin/notifications
POST   /api/admin/notifications/{id}/retry
```

**Rules:**
- Mounted under `Group::create('/api/admin')->middleware(AdminMiddleware::class)`.
- Superadmin-only routes (`/withdraws`, `/staff`, `/settings`) also chain `SuperAdminMiddleware`.
- Each action returns `Api::ok()` / `Api::forbidden()` envelopes.
- No new DB tables; only new route + action classes.

### 5.2 New Action Classes (Thin Wrappers)

Each new `Admin*ApiAction` delegates to the existing web action's private methods or extracts shared logic into a small service class.

| New Action | Reuses |
|---|---|
| `AdminDashboardApiAction` | `AdminDashboardAction` stats via repositories directly |
| `AdminUsersApiAction` | `AdminUsersAction::createUser()`, `trashUser()`, `restoreUser()`, `resetPassword()` |
| `AdminOrdersApiAction` | `AdminOrdersAction` queue logic + `BulkJobService::enqueueSettle()` |
| `AdminTopupsApiAction` | `AdminTopupsAction` |
| `AdminRechargesApiAction` | `AdminRechargeAction` |
| `AdminWithdrawsApiAction` | `AdminWithdrawsAction` |
| `AdminStaffApiAction` | `AdminStaffAction` |
| `AdminSettingsApiAction` | `AdminSettingsAction` |
| `AdminLogsApiAction` | `AdminLogsAction` + `ActivityLogRepository` |
| `AdminNotificationsApiAction` | `AdminNotificationsAction` |

**Implementation hint:** If `AdminOrdersAction` is too tightly coupled to Twig rendering, extract the query/filter/state logic into `AdminOrderQuery` and call it from both the HTML and API actions. This keeps the single-responsibility rule intact without duplicating the 60-line `state()` normalizer.

### 5.3 Pagination & Filter Contract

Standardize admin list responses:

```json
{
  "success": true,
  "data": {
    "rows": [...],
    "total": 142,
    "page": 1,
    "perPage": 20
  }
}
```

---

## 6. Android App Architecture

### 6.1 Module Layout (within `android/app/src/main/java/online/broxlab/aliftools/`)

```
data/
  remote/
    AlifToolsApi.kt          ← Retrofit interfaces
    AuthInterceptor.kt       ← Bearer + 401 refresh retry
    ApiEnvelope.kt           ← {success, message, data, errors}
  local/
    TokenStore.kt            ← Encrypted SharedPreferences / DataStore
    Session.kt               ← Observable logged-in state
  AppContainer.kt            ← Dependency graph (single CompositionRoot)

domain/                       ← Optional; useful if screen count grows
  model/
    User.kt, Order.kt, …
  repository/
    AdminRepository.kt       ← Single source for admin data

ui/
  theme/                     ← Material3 theme, color scheme
  navigation/
    AdminNavGraph.kt         ← Bottom nav / drawer for admin screens
  screens/
    admin/
      AdminDashboardScreen.kt
      AdminUsersScreen.kt
      AdminOrdersScreen.kt
      AdminTopupsScreen.kt
      AdminRechargesScreen.kt
      AdminWithdrawsScreen.kt
      AdminStaffScreen.kt
      AdminSettingsScreen.kt
      AdminLogsScreen.kt
  components/                ← Reusable: stat cards, filters, badges, loading

push/
  DeepLink.kt                ← Route push payloads to correct screen
  DeviceRegistrar.kt         ← FCM token → backend
  AlifToolsFcmService.kt     ← FirebaseMessagingService

update/
  UpdateChecker.kt
  UpdatePrompt.kt
```

### 6.2 Tech Choices (already present in `android/app/build.gradle.kts`)

| Concern | Choice | Notes |
|---|---|---|
| UI | Jetpack Compose + Material3 | Existing baseline; use ` Scaffold`, `BottomNavigation`, `ModalDrawer`. |
| Navigation | Navigation Compose | `navGraph` per role; superadmin sees extra destinations. |
| Networking | Retrofit + OkHttp | Existing `AuthInterceptor` handles bearer + refresh. |
| Serialization | Kotlinx Serialization | Existing `ApiEnvelope` model. |
| Concurrency | Kotlin Coroutines + Flow | UI state exposed as `StateFlow`. |
| Persistence | EncryptedSharedPreferences | `TokenStore` upgrades from plain `SharedPreferences` to encrypted storage. |
| Push | Firebase Cloud Messaging | `AlifToolsFcmService` handles data messages; deep-link into admin screens. |
| DI | Manual (AppContainer) | Keep it simple; no Dagger/Hilt needed at this scale. |
| Logging | Timber (optional) | Replace `HttpLoggingInterceptor` debug logs in production. |

---

## 7. Authentication & RBAC Flow

### 7.1 Login
1. User opens app → `MainActivity` checks `Session.isLoggedIn`.
2. If false → `LoginScreen`.
3. `POST /api/auth/login` with `identifier`, `password`, `device_label`.
4. Backend mints token pair via `ApiTokenRepository::issue()`.
5. `TokenStore` saves bearer + refresh (encrypted).
6. `Session.onLogin()` flips state; `NavHost` navigates to role-gated destination.

### 7.2 Token Refresh (Transparent)
- `AuthInterceptor` intercepts every call.
- On `401` with a refresh token present:
  - Calls `POST /api/auth/refresh` with refresh bearer.
  - Backend calls `ApiTokenRepository::rotate()`: revokes old refresh, issues new pair.
  - APK stores new tokens, replays original request with new bearer.
- On 401 after refresh fails → `Session.clear()` → login screen.

### 7.3 RBAC Enforcement
- **Backend:** Each `/api/admin/*` action reads `$request->getAttribute('identity')` and checks `Identity::isAdmin()`, `isStaff()`, `isSuperAdmin()`.
- **Android:** After login, `GET /api/admin/dashboard` returns the user's role. `Session.role` stores it. UI hides screens for which the user is unauthorized. **This is UX only; the backend still rejects unauthorized access with 403.**

### 7.4 Logout
- `POST /api/auth/logout` with current bearer.
- Backend calls `ApiTokenRepository::revoke()`.
- APK clears `TokenStore`, navigates to login.

---

## 8. Feature Mapping: Web Admin → Admin API → Android Screen

| Web Admin Route | New API Endpoint | Android Screen | Primary Repository |
|---|---|---|---|
| `/admin` (dashboard) | `GET /api/admin/dashboard` | `AdminDashboardScreen` | `UserRepository`, `TopupRepository`, `ServiceOrderRepository`, `ActivityLogRepository` |
| `/admin/users` | `GET/POST /api/admin/users` | `AdminUsersScreen` | `UserRepository` |
| `/admin/orders` | `GET/POST /api/admin/orders` | `AdminOrdersScreen` | `ServiceOrderRepository`, `BulkJobService` |
| `/admin/topups` | `GET/POST /api/admin/topups` | `AdminTopupsScreen` | `TopupRepository` |
| `/admin/recharges/{id}` | `GET/POST /api/admin/recharges/{id}` | `AdminRechargesScreen` | (embedded in topup detail) |
| `/admin/withdraws` (super) | `GET/POST /api/admin/withdraws` | `AdminWithdrawsScreen` | `WithdrawRepository` (or `TopupRepository` if shared) |
| `/admin/staff` (super) | `GET/POST /api/admin/staff` | `AdminStaffScreen` | `UserRepository` |
| `/admin/settings` (super) | `GET/POST /api/admin/settings` | `AdminSettingsScreen` | `SettingsRepository` |
| `/admin/activity-logs` | `GET /api/admin/logs` | `AdminLogsScreen` | `ActivityLogRepository` |
| `/admin/notifications` | `GET/POST /api/admin/notifications/{id}/retry` | In-app notification center | `NotificationRepository` |

**UI/UX rule:** Every screen shows `Loading` → `Content` → `Error` states using the same `ApiEnvelope.success` flag. No business logic decides when to show what; the server decides.

---

## 9. Admin Screen Contracts

### 9.1 Dashboard
- **Endpoints:** `GET /api/admin/dashboard`
- **Displays:** total users, categories, services, open orders, recent logs, unresolved topup count/amount.
- **Interaction:** Tap a card → navigates to the corresponding list with a pre-filter.

### 9.2 Users
- **Endpoints:** `GET /api/admin/users?page=&q=&sort=&dir=&trashed=` ; `POST /api/admin/users` with `do=create|toggle|role|reset|delete|restore|restore_all`
- **UI:** Search bar, sortable headers, role chips, swipe-to-toggle status, FAB for new user.
- **Security:** Reset password returns a temporary plaintext in the response. The APK shows it once in a dialog and never stores it.

### 9.3 Orders
- **Endpoints:** `GET /api/admin/orders?page=&status=&q=&scope=&sort=&dir=` ; `POST /api/admin/orders/bulk` with `do=bulk_export|bulk_status&ids=[]&status=`
- **UI:** Scope tabs (All / Mine / Taken), status chips, bulk action bar, CSV export share sheet.
- **Note:** Bulk settle is async (BulkJobService). The API should return `{job_id}` and the APK polls `GET /api/admin/jobs/{id}` or uses a lightweight WebSocket/SSE if added later.

### 9.4 Topups & Recharges
- **Endpoints:** `GET /api/admin/topups?page=&status=` ; `GET /api/admin/recharges/{id}` ; `POST /api/admin/recharges/{id}`
- **UI:** Filter by status (pending, approved, rejected), detail screen with approve/reject actions.

### 9.5 Withdraws (Superadmin Only)
- **Endpoints:** `GET /api/admin/withdraws?page=` ; `POST /api/admin/withdraws/{id}`
- **UI:** List of withdrawal requests; approve/reject with note.

### 9.6 Staff (Superadmin Only)
- **Endpoints:** `GET /api/admin/staff` ; `POST /api/admin/staff`
- **UI:** List staff, add/remove staff role, same validation as user creation.

### 9.7 Settings (Superadmin Only)
- **Endpoints:** `GET /api/admin/settings` ; `POST /api/admin/settings`
- **UI:** Form fields for min/max recharge, wallet numbers, receipt rules. Values are plain text / numbers; no file upload in v1.

### 9.8 Logs
- **Endpoints:** `GET /api/admin/logs?page=&q=`
- **UI:** Searchable read-only list. Metadata shown as JSON tree in a dialog.

---

## 10. Offline & Resilience Strategy

| Need | Approach |
|---|---|
| Auth state | EncryptedSharedPreferences; token survives process death. |
| Cached lists | In-memory only (Compose `StateFlow`). Do not persist admin data offline — the data changes too fast and is too sensitive to risk stale reads. |
| Retry | OkHttp retry on `POST /api/auth/refresh` with exponential backoff capped at 2 retries. |
| Connectivity | Show `Snackbar` with "Retry" action when `IOException` occurs. |
| Background sync | No background sync for admin data. The APK is a foreground tool; push notifications alert the admin to new work. |

---

## 11. Push Notifications for Admins

Leverage the existing `NotificationManager` and FCM stack:

1. **Subscribe:** When an admin logs in, `POST /api/devices` registers the FCM token with `platform=android_admin`.
2. **Dispatch:** Backend `NotificationManager` fans out to FCM for events:
   - New topup request
   - New withdrawal request (superadmin only)
   - Bulk job completed
   - Order claimed by another operator
3. **Receive:** `AlifToolsFcmService` receives the data message, builds a `DeepLink`, and navigates to the relevant screen.
4. **Tap:** If the app is cold, the deep link is parsed in `MainActivity.onCreate` after `Session` resolves; if warm, `NavController` handles it immediately.

---

## 12. Security Considerations

| Threat | Mitigation |
|---|---|
| Token theft | Selector + HMAC-verifier tokens; only hash stored. Backend checks `status=active` on every resolve. |
| Replay | Refresh rotation invalidates old refresh tokens immediately. |
| Device loss | Admin can `POST /api/devices/{id}/delete` to revoke a device from the web panel; APK also shows device list with revoke action. |
| MITM | Enforce HTTPS on all API calls. OkHttp `CertificatePinner` can be added in v1 if the production cert is stable. |
| Screenshot leak | No secret data shown in notifications; deep links route to auth-gated screens. |
| Backup extraction | Use `EncryptedSharedPreferences` with Android Keystore-backed keys for token storage. |
| Role escalation | Backend `AdminMiddleware` and `SuperAdminMiddleware` enforce RBAC on every endpoint; APK role is purely cosmetic. |

---

## 13. Testing Strategy

### 13.1 Backend Tests (PHPUnit / Codeception)
- **Unit:** `ApiTokenRepository` rotate/revoke/expire flows.
- **Functional:** New admin API endpoints return 403 for non-admin users, 200 for admins, correct envelope shape.
- **Regression:** Existing web admin routes still pass; run `tests/Functional/Admin*Test.php` after each new API action.

### 13.2 Android Tests
- **Unit:** `TokenStore` encryption round-trip, `AuthInterceptor` refresh logic, `ApiEnvelope` parsing.
- **Compose UI Tests:** Login → dashboard → order detail flow using `createComposeRule`.
- **Integration:** MockWebServer with recorded admin JSON fixtures for orders/users/topups.

### 13.3 Manual QA Checklist
1. Admin login → dashboard loads → navigate to each tab.
2. Non-admin login → admin screens are hidden → API returns 403 → app shows friendly error.
3. Token expiry → refresh → seamless retry.
4. Logout → tokens revoked → re-login succeeds.
5. Push notification → tap → lands on correct screen.
6. In-app update flow (existing) still works.

---

## 14. CI/CD & Release

### 14.1 Build Variants
- **Debug:** `API_BASE_URL=http://10.0.2.2:8099/` (local dev server).
- **Release:** `API_BASE_URL=https://allseba.online/`.
- **AdminFlavor:** A product flavor (optional) that disables user-facing screens and enables admin-only navigation. In v1, a single APK with role-based nav is sufficient.

### 14.2 Signing & Distribution
- Use the same keystore properties pattern as `twa/app/build.gradle.kts`: `TWA_STORE_FILE`, `TWA_STORE_PASSWORD`, `TWA_KEY_ALIAS`, `TWA_KEY_PASSWORD` env vars or `keystore.properties`.
- Upload signed APK/AAB to the existing Play Console track or distribute internally via Firebase App Distribution.

### 14.3 Automation
- GitHub Actions matrix: `lint`, `test`, `assembleDebug`, `assembleRelease` (unsigned).
- Backend: `composer ci` or equivalent runs tests + static analysis (`psalm.xml` present).

---

## 15. Phased Implementation Plan

### Phase 0: Foundation (Week 1)
- [ ] Add `/api/admin` route group skeleton with `AdminMiddleware` + `SuperAdminMiddleware` guards.
- [ ] Create `AdminDashboardApiAction` returning the same stats as the web dashboard.
- [ ] Extend `AlifToolsApi` Retrofit interface with admin endpoints.
- [ ] Upgrade `TokenStore` to `EncryptedSharedPreferences`.
- [ ] Build role-aware `AdminNavGraph` with placeholder screens.

### Phase 1: Read-Only Admin Surface (Week 2)
- [ ] `AdminUsersApiAction` (GET list).
- [ ] `AdminOrdersApiAction` (GET list with filters).
- [ ] `AdminTopupsApiAction` (GET list).
- [ ] `AdminLogsApiAction` (GET list).
- [ ] Android screens: Users list, Orders list, Topups list, Logs list.
- [ ] Pull-to-refresh, pagination, empty states.

### Phase 2: Write Actions (Week 3)
- [ ] `AdminUsersApiAction` POST (create, toggle, role, reset, delete, restore).
- [ ] `AdminOrdersApiAction` POST (claim, settle, bulk settle, bulk export — export returns a shareable file URI).
- [ ] `AdminTopupsApiAction` POST (approve/reject).
- [ ] Android detail screens: User edit, Order desk, Topup desk.

### Phase 3: Superadmin Surfaces (Week 4)
- [ ] `AdminWithdrawsApiAction` (GET + POST).
- [ ] `AdminStaffApiAction` (GET + POST).
- [ ] `AdminSettingsApiAction` (GET + POST).
- [ ] Android screens: Withdraws, Staff, Settings.

### Phase 4: Hardening & Polish (Week 5)
- [ ] FCM data-message routing for all admin events.
- [ ] In-app update checker (already present, verify against new API base URL).
- [ ] Error boundary UI for 403/401/network failures.
- [ ] Accessibility labels, dark mode, Bangla locale polish.
- [ ] Load testing: 100 concurrent admin API calls against `/api/admin/orders`.

### Phase 5: Beta & Release (Week 6)
- [ ] Closed beta with 3–5 staff devices.
- [ ] Crashlytics / Firebase Analytics integration (optional).
- [ ] Signed release build, internal testing track.
- [ ] Runbook for `POST /api/auth/revoke-all` if a device is lost.

---

## 16. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Backend business logic is too coupled to Twig | Medium | High | Extract shared query/state logic into small service classes; reuse from both HTML and API actions. |
| Token refresh race condition | Low | Medium | One refresh per 401, serialize with a mutex in `AuthInterceptor`. |
| Push payload too large for FCM data message | Medium | Low | Send only IDs in the payload; APK fetches full details on tap. |
| Admin accidentally approves own transaction | Low | High | `AdminWithdrawService::approve()` already separates `admin` and `superadmin` roles; keep that boundary in the API. |
| App size bloat | Low | Low | Keep admin APK as a flavor or separate signing config; reuse the user APK module where possible. |

---

## 17. Open Decisions (for the user)

1. **Single APK vs. Flavors:** Should the admin app share code with the user APK, or live as a separate flavor/build variant?
2. **Biometric unlock:** Should the admin APK require device biometric auth before showing the token, adding a second layer of device-level protection?
3. **Bulk export format:** CSV is fine for v1, but do you want PDF invoices for recharges/withdrawals on mobile?
4. **Offline queue:** Do admins need to draft actions (approve/reject) while offline and sync when connectivity returns?

---

## 18. Acceptance Criteria

- [ ] Admin logs in with existing bearer token flow → 200.
- [ ] Non-admin hits `/api/admin/dashboard` → 403.
- [ ] Superadmin sees withdraws/staff/settings tabs; admin/staff do not.
- [ ] Every admin mutation is replayable from the web panel and produces identical `activity_logs` rows.
- [ ] Android APK passes `./gradlew assembleRelease` with no secrets in `BuildConfig` or source.
- [ ] No web admin route, template, or repository method is removed or renamed.
