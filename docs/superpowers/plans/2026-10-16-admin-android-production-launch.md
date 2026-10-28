# Plan: Admin Android App — Production / Launch Readiness

> **REQUIRED SUB-SKILL: superpowers:subagent-driven-development**

## Summary

Make `android-admin/` and its backend contract complete, hardened, and releasable so a signed production APK can be built, published, and updated in the field. The work is one launch milestone delivered as five phases; every phase ends with green tests, a commit, and a push:

- **Phase 0** — Make the project compile and build on this machine (it currently cannot: `StaffScreen.kt` is missing two imports, and no Android SDK components are installed).
- **Phase 1** — Fix four real backend bugs + add two missing backend capabilities (receipt streaming, release channel), each test-first, ending with a full `composer test` and README count update.
- **Phase 2** — Bring `docs/ADMIN_ANDROID_API.md`, `ADMIN_ANDROID_ARCHITECTURE.md`, and `ADMIN_ANDROID_SECURITY.md` in line with the code (the app already matches the *code*; the docs are the stale side).
- **Phase 3** — Android completion: in-app update check, receipt viewing, EncryptedSharedPreferences token storage, biometric gate wiring, R8/Gson hardening, release BASE_URL guard, conditional Firebase plugin.
- **Phase 4** — Release infrastructure: keystore handling (gitignored), CI APK workflow, setup/deployment docs, README section, final verification.

## Key Decisions

1. **One plan, five phases** (not five plans): the subsystems are coupled through one launch milestone — the Android update check depends on the backend channel work, the docs depend on the Phase 1 fixes. Each phase is independently shippable, so execution can pause between phases.
2. **Notification `delete` bug** (verified): `routes.php` registers `/notifications/{id}/retry` and `/notifications/{id}/delete` as *static* suffixes, so `$route->getArgument('action')` is always null and `AdminNotificationsApiAction` defaults to `retry` — the Android app's delete button silently requeues. `AdminApiTest` masks this by injecting a synthetic `action` route argument into a hand-built `CurrentRoute` (its `call()` helper, line 254). Fix: merge the two routes into `POST /api/admin/notifications/{id}/{action}` (existing precedent: `/service-requests/{id}/{action}`, routes.php line 196), validate `action ∈ {retry, delete}` → else 400, and prove wiring with a **Web-suite** test that goes through the real router.
3. **Docs follow code where app + code already agree** (refresh token via `Authorization` header, `user_id`, `note`, device `token`, `trashed=exclude|only|all`) — changing those code paths would break the shipped app for no gain. **Code follows docs where docs encode intent** (API login throttle: the `AuthApiAction` docblock *claims* throttling that does not exist; permission denials answer 400 where the documented matrix says 403).
4. **`app_release` needs a `channel` column** (`'twa'` default, composite unique `(channel, version_code)`): publishing an admin APK into the shared table would make `/app/apk` and `/api/app/version` serve the *admin* APK to TWA users. Channel defaults keep every existing TWA response byte-identical.
5. **New console command `app:release:publish`**: `AppReleaseRepository::publish()` has **zero callers** in the repo today — there is currently no supported way to publish a release. Pattern: Symfony Console + `#[AsCommand]` + registration in `config/console/params.php`, exactly like `app:twa:fingerprints`.
6. **Receipt viewing** = new `GET /api/admin/recharges/{id}/receipt` streaming bytes (a documented binary exception to the JSON envelope, mirroring the web `ReceiptAction`'s `nosniff`/`no-store` hardening), because approving money without seeing the receipt is not production behavior. Android fetches to a cache file, shows images via Coil (`AsyncImage` on a `File`, which needs no auth and handles EXIF), and opens PDFs through a `FileProvider` intent.
7. **TokenStorage cutover**: only token material (access/refresh/expires/role) moves to `EncryptedSharedPreferences`; the non-secret `remembered_identifier` and `biometric_enabled` stay in DataStore. All public signatures unchanged — every test mocks `TokenStorage` with mockk, so only signatures matter. No migration path: pre-launch there are no production users (installed dev builds get logged out once; documented).
8. **Biometric is a client-side gate only** (server stays authoritative, per SECURITY.md L90): opt-in switch on the Dashboard, unlock-on-open in `MainActivity`, and step-up prompts before exactly the five ops SECURITY.md L31 lists (withdraw approve/reject, user delete, role change, settings save, bulk settle) — wired through an `@EntryPoint` accessor so composables can reach the injected `BiometricHelper` without changing ViewModel constructors.
9. **Firebase stays optional at build time**: `google-services` plugin declared `apply false` at the root, applied in `:app` only when `app/google-services.json` exists, file gitignored, provisioned locally + optionally in CI from a secret. The build must stay green without it (today's behavior: phone login and FCM no-op).
10. **BASE_URL placeholder → hard failure on the release variant only** (via `androidComponents.beforeVariants`), so a misconfigured release APK cannot be produced silently; debug builds keep working with no config.
11. **R8**: keep `com.aliftools.admin.data.model.**` intact (Gson reflects over field names; DTOs rely on default naming as well as `@SerializedName`) and prove it by grepping `mapping.txt` after `assembleRelease` — locally and in CI.
12. **Toolchain on this machine**: install `cmdline-tools` + `platforms;android-36` + `build-tools;35.0.0` via `sdkmanager`; use Android Studio's bundled JBR (`C:\Program Files\Android\Android Studio\jbr`, Java 21) as `JAVA_HOME` first, fall back to Temurin 17 if AGP/kapt object; create `android-admin/local.properties` with `sdk.dir` + `ADMIN_BASE_URL=http://127.0.0.1:8099/` (both gitignored).

## Goal

A signed, R8-hardened admin APK that: compiles and passes its unit tests on this machine; talks to a backend whose API contract is correct, tested at the router level, and accurately documented; stores tokens encrypted; gates sensitive actions behind opt-in biometrics; checks for updates per channel; can be published via a supported console command and a CI workflow — with setup/deployment documentation and a green full test suite.

## Architecture

No architectural changes. Additions stay inside existing boundaries:

- Bugs/fixes: `src/Web/Api/Admin/*`, `src/Web/Api/AuthApiAction.php`, `config/common/routes.php`.
- New capability: `src/Web/Api/Admin/AdminRechargeReceiptApiAction.php` (action) + `App\Console\AppReleasePublishCommand` (publish) + channel threading through `AppReleaseRepository → AppReleaseService → AppVersionApiAction/ApkDownloadAction`.
- Android: `TokenStorage` internals, `MainActivity` gate, `BiometricGate` component + `@EntryPoint`, Dashboard update UI, Recharges receipt UI, `build.gradle.kts` guards/plugins, `proguard-rules.pro`.
- Tests: TDD per task; router-level proof in the **Web** suite (PhpBrowser → `http://127.0.0.1:8099` → real `routes.php`), behavior tests in **Functional**, parsing tests in `ApiParsingTest`, update flow in a new `DashboardViewModelTest`.

## Tech Stack

PHP 8.2 / Yii 3 / FastRoute / Codeception (Unit·Console·Functional·Web) · Kotlin / Jetpack Compose / Hilt / Retrofit+Gson / Coil / androidx.biometric / security-crypto · Gradle 8.13 + AGP 8.13 (JDK 17 target, JBR 21 local) · GitHub Actions.

## Spec / Sources of Truth

- `docs/ADMIN_ANDROID_API.md`, `docs/ADMIN_ANDROID_ARCHITECTURE.md`, `docs/ADMIN_ANDROID_SECURITY.md` (Phase 2 reconciles them with code; SECURITY.md L26/L30–31/L42–44 drive tasks 3.3/3.4).
- `docs/ADMIN_ANDROID_SMOKE_TEST.md` (updated at the end).
- `AGENTS.md` §5 (API contracts), §9 (Android app), §11 (checks), §14 (docs).

## Global Constraints

- Never commit: keystores, `keystore.properties`, `local.properties`, `google-services.json`, `.env`, DB dumps (`.gitignore` entries first, examples kept).
- API changes stay backward-compatible with the shipped app: no field renames the app consumes; envelope untouched (receipt endpoint is a documented binary exception).
- TWA behavior must not change: default channel `'twa'` keeps `/app`, `/app/apk`, `/api/app/version` responses identical (regression tests assert this).
- No financial code paths are modified; Android approve/reject/withdraw screens are unchanged clients.
- Smallest coherent change per task; no drive-by refactors (e.g. do NOT touch `AdminUsersApiAction`'s business-rule 400s, only its permission denials).
- Bengali user-facing copy for new app strings; docs in English.
- Commit per task, push per phase. Never `git checkout -- .` / reset / clean.

## Review Focus

- Router fix: does any test still inject synthetic `action` arguments to prove routing? (That is exactly the bug's cause — router proofs must go through Web suite.)
- Channel isolation: any code path where an admin row could surface on `channel=twa` (or vice versa)?
- New 429/403 responses: does the Android client handle them without new failure modes? (App shows `message` for any non-401 error — verify, don't assume.)
- Docs: every edited line must be checked against the code, not the other way around.
- Secrets: `git status` must never show `*.jks`, `keystore.properties`, `local.properties`, `google-services.json`.
- Test honesty: report only commands actually run and their real output.

---

# Phase 0 — Compile & Build Baseline (prerequisite for everything Android)

## Task 0.1 — Machine toolchain + `local.properties`

**Goal:** `sdkmanager` available, Android SDK packages installed, `JAVA_HOME` set, `android-admin/local.properties` created.

**Files:** `android-admin/local.properties` (new, gitignored), machine SDK under `%LOCALAPPDATA%\Android\Sdk`.

**Steps:**
- [ ] `git status --short --branch` → expect `main`, only known-clean or user's in-progress files; do not disturb user changes.
- [ ] Verify gaps: `Test-Path "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools"` → `False`; `Test-Path "$env:LOCALAPPDATA\Android\Sdk\platforms\android-36"` → `False` (both verified missing).
- [ ] Download command-line tools: `Invoke-WebRequest -Uri https://dl.google.com/android/repository/commandlinetools-win-13114758_latest.zip -OutFile "$env:TEMP\cmdtools.zip"` → expect a zip. If 404 (version moved), get the current link from https://developer.android.com/studio#command-line-tools-only and use that URL; if download is impossible, open Android Studio → Settings → Languages & Frameworks → Android SDK → check "Android SDK Command-line Tools" instead.
- [ ] Expand: `Expand-Archive "$env:TEMP\cmdtools.zip" "$env:TEMP\cmdtools"` then create the layout sdkmanager requires: `New-Item -ItemType Directory -Force "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools"`; `Move-Item "$env:TEMP\cmdtools\cmdline-tools" "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools\latest"` → expect `...\cmdline-tools\latest\bin\sdkmanager.bat` exists.
- [ ] `$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'`; `$env:Path="$env:JAVA_HOME\bin;$env:Path"`; `java -version` → expect JBR 21.x. Fallback if a later Gradle step fails on 21: `winget install EclipseAdoptium.Temurin.17.JDK` and set `JAVA_HOME` to that instead (the project docs/CI use JDK 17).
- [ ] `& "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools\latest\bin\sdkmanager.bat" --licenses` → answer `y` (licenses dir already exists; this makes it idempotent).
- [ ] `& "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools\latest\bin\sdkmanager.bat" "platform-tools" "platforms;android-36" "build-tools;35.0.0"` → expect install summaries, no license prompt.
- [ ] Create `android-admin/local.properties` (UTF-8, via write tool — never PowerShell `Set-Content` on these files):
  ```
  sdk.dir=C\:\\Users\\Hr Habib\\AppData\\Local\\Android\\Sdk
  ADMIN_BASE_URL=http://127.0.0.1:8099/
  ```
  → expect `Test-Path android-admin\local.properties` → `True`. Note: `ADMIN_BASE_URL` must end with `/` (Retrofit `baseUrl` requirement); it is the dev value, production comes from env/keystore.properties.
- [ ] `git check-ignore android-admin/local.properties` → expect output (ignored) — confirms nothing here can be committed.

**Tests:** none (environment task). **Commit:** none.

## Task 0.2 — Fix `StaffScreen.kt` compile break; establish Gradle baseline

**Goal:** HEAD compiles; record the unit-test baseline.

**Files:** `android-admin/app/src/main/java/com/aliftools/admin/ui/screens/staff/StaffScreen.kt`.

**Steps:**
- [ ] Add the two missing imports after `import androidx.compose.material3.MaterialTheme` (verified: file uses `AlertDialog` at lines 131/155 and `TextButton` at 142/147/148/172/173 with neither imported):
  ```kotlin
  import androidx.compose.material3.AlertDialog
  import androidx.compose.material3.TextButton
  ```
- [ ] `cd android-admin; .\gradlew.bat :app:compileDebugKotlin --no-daemon` → expect `BUILD SUCCESSFUL`. (First run downloads Gradle 8.13 + dependencies; allow several minutes. If `gradlew.bat` is missing, run `gradle wrapper` — wrapper properties exist.) If the compiler reports any *other* unresolved symbol in this file, add only that import and re-run; report exactly what was needed.
- [ ] `.\gradlew.bat :app:testDebugUnitTest --no-daemon` → expect `BUILD SUCCESSFUL`; record the test count from `app/build/reports/tests/testDebugUnitTest/index.html`.

**Tests:** baseline unit suite (4 files: `AdminRepositoryTest`, `ApiParsingTest`, `AuthInterceptorTest`, `AuthFlowIntegrationTest`). **Commit:** `fix(android-admin): import AlertDialog and TextButton in StaffScreen` (this file only).

---

# Phase 1 — Backend Contract Fixes & Capabilities (TDD)

All tasks: write the failing test first, run it (red), implement (green), run the touched suites. Suites need the test DB the way `composer test` provisions it (inspect `codeception.yml`/suite configs if unsure); apply new migrations to the dev DB and the test DB if separate (report what you found).

## Task 1.1 — Notification delete reaches the router (Web suite)

**Goal:** `POST /api/admin/notifications/{id}/delete` deletes; unknown action → 400; wiring proven through the real router.

**Files:** `config/common/routes.php` (lines 223–224), `src/Web/Api/Admin/AdminNotificationsApiAction.php` (lines 34–58), new `tests/Web/AdminApiRouterCest.php`, `tests/Functional/AdminApiTest.php` (comment/routeArgs cleanup only).

**Steps:**
- [ ] Start the dev server (Web suite target): background `php ./yii serve --port=8099 --docroot=public` → expect `http://127.0.0.1:8099` answering (probe with `Invoke-WebRequest`).
- [ ] RED — write `tests/Web/AdminApiRouterCest.php`:
  - Constructor/`db()` DI follows `tests/Web/AdminOrderQueueBulkCest.php` (it resolves `ConnectionInterface`; PhpBrowser module only, `Web.suite.yml`).
  - Helper `_bearerAdmin()`: `sendAjaxPostRequest('/api/auth/login', ['identifier' => 'admin', 'password' => 'Admin1234!'])`, decode JSON, store `data.token`, `haveHttpHeader('Authorization', 'Bearer ' . $token)`. Use a unique `device_label`-free login; a *successful* login clears the throttle (task 1.3) so suites do not interfere.
  - `notificationDeleteIsRoutedThroughTheRealRouter`: plant a dead queue row via `$this->db()` insert into `{{%notification_queue}}` (copy column set from `AdminApiTest::testNotificationDeleteRemovesDeadRow`, status `dead`, unique `dedupe_key` marker); `sendAjaxPostRequest('/api/admin/notifications/'.$id.'/delete')` → assert status 200, `success=true`, `data.message === 'নোটিফিকেশনটি মুছে ফেলা হয়েছে।'`, and row gone (`SELECT id ... -> []`); purge the planted row in `_after` keyed by the marker.
  - `notificationDeleteOnMissingRowIs404ThroughRouter`: `POST /api/admin/notifications/999999999/delete` → assert 404 + `data.message === 'নোটিফিকেশনটি পাওয়া যায়নি।'` (today this returns **400** `শুধুমাত্র dead-letter ...` because the action falls into the retry branch — this is the red assertion).
  - `notificationUnknownActionIs400`: `POST /api/admin/notifications/1/oops` → assert 400 + message `Unknown notification action.` (today: no route matches → 404 route, red).
  - `notificationRetryIsStillRouted`: `POST /api/admin/notifications/1/retry` with no row → assert 400 `শুধুমাত্র dead-letter অনুরোধ আবার চালানো যায়নি।` (green before and after — guards against breaking retry).
  - Run: `vendor\bin\codecept.bat run Web AdminApiRouterCest --no-colors` → expect the three red assertions failing exactly as predicted (400 vs 404, 404 vs 400).
- [ ] Implement:
  - `config/common/routes.php`: replace the two lines with one:
    ```php
    Route::post('/notifications/{id}/{action}')->action(AdminNotificationsApiAction::class)->name('api-admin-notification-action'),
    ```
    (names `api-admin-notification-retry|delete` are referenced nowhere else — verified by grep).
  - `AdminNotificationsApiAction` POST branch:
    ```php
    $id = (int) $route->getArgument('id', '0');
    $action = (string) $route->getArgument('action', '');
    if ($action === 'delete') { ...existing delete branch unchanged... }
    if ($action !== 'retry') {
        return Api::fail('Unknown notification action.', [], 400);
    }
    ```
- [ ] GREEN — rerun `vendor\bin\codecept.bat run Web AdminApiRouterCest --no-colors` → expect all green.
- [ ] `vendor\bin\codecept.bat run Functional AdminApiTest --no-colors` → expect green unchanged (its `call()` injects route args directly, unaffected). Update the three notification tests' comments + drop the synthetic `action` value from `routeArgs` **only if** the suite stays green (keep `['id' => ...]`); if dropping it fails, leave routeArgs and only fix the comment to say the wiring is proven by `AdminApiRouterCest`.
- [ ] `vendor\bin\codecept.bat run Functional AdminMiddlewareTest --no-colors` → expect green (its `POST .../retry` still matches).

**Commit:** `fix(api): route notification delete/retry through {action} and reject unknown actions`.

## Task 1.2 — Orders `sort` whitelist uses `in_array`

**Goal:** `?sort=` on `GET /api/admin/orders` is honoured for valid columns and falls back to `id` otherwise (currently `isset(ServiceOrderRepository::SORTABLE[$sort])` indexes a **list** with a string key — always false → always `id`).

**Files:** `src/Web/Api/Admin/AdminOrdersApiAction.php` (line 99), `tests/Functional/AdminApiTest.php`.

**Steps:**
- [ ] RED — add to `AdminApiTest`:
  - `ordersSortIsEchoedWhenWhitelisted`: GET `/api/admin/orders?sort=amount&dir=desc` as admin → assert `payload['data']['sort'] === 'amount'` (today: `'id'`).
  - `ordersSortFallsBackToIdWhenUnknown`: `?sort=password` → assert `'id'` (documents the whitelist, guards over-permission).
  - Plant orders the way the file's existing order tests do (follow their fixture helper — do not invent a second one).
  - Run: `vendor\bin\codecept.bat run Functional AdminApiTest --no-colors --filter ordersSort` → expect red on the first.
- [ ] Implement line 99:
  ```php
  'sort' => in_array($sort, ServiceOrderRepository::SORTABLE, true) ? $sort : 'id',
  ```
- [ ] GREEN — rerun the filter, then the whole `AdminApiTest`.

**Commit:** `fix(api): whitelist admin orders sort with in_array`.

## Task 1.3 — API login throttle (the docblock already promises it)

**Goal:** `POST /api/auth/login` honours the same 5-attempts/300 s throttle as the web login; answers **429** with Bengali copy.

**Files:** `src/Web/Api/AuthApiAction.php`, `tests/Functional/ApiCsrfCest.php` or a new `tests/Functional/AuthApiThrottleCest.php` (choose whichever file already performs real `/api/auth/login` POSTs — verified that `AuthApiAction::login` has no throttle and `IdentityRepository` has none either; `AuthService::login` lines 142–153 is the pattern to mirror).

**Steps:**
- [ ] RED — test: plant a unique-identifier admin (unique per run, e.g. `'thr' . $this->suffix`, with a known password — read `AdminApiTest::makeUser` first; if it does not set a usable password, create the user through `IdentityRepository` the way the existing login tests do), then POST `/api/auth/login` with a **wrong** password 5 times → assert each returns 401; 6th attempt with the **correct** password → assert **429** and message `অনেকবার ভুল চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।` → today the 6th returns 200 (red). Unique identifier keeps the shared file-cache throttle from poisoning other tests.
- [ ] Implement: constructor gains `private \App\Auth\AuthThrottle $throttle`; inside `login()` right before `$this->identities->login(...)` (after the 422 empty-fields check, with `$ip` already computed):
  ```php
  $throttleKey = $identifier . '|' . $ip;
  if ($this->throttle->tooManyAttempts($throttleKey)) {
      return Api::fail('অনেকবার ভুল চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।', [], 429);
  }
  ```
  on `$error !== null` add `$this->throttle->hit($throttleKey);` before the 401 return; on success add `$this->throttle->clear($throttleKey);` before issuing tokens. Limits live inside `AuthThrottle` (5/300 defaults) — do not duplicate them.
- [ ] GREEN — rerun the new test; then `vendor\bin\codecept.bat run Functional ApiCsrfCest --no-colors` (and whichever login tests exist) → expect green (successful logins clear their keys).

**Commit:** `feat(api): throttle machine login like the web login path`.

## Task 1.4 — Permission denials answer 403, not 400

**Goal:** every *permission* denial in `AdminUsersApiAction` uses `Api::forbidden()` (documented matrix line 22); business-rule 400s (self-delete, already-trashed, missing id, unknown action) stay 400.

**Files:** `src/Web/Api/Admin/AdminUsersApiAction.php` (lines 85, 91–106 — keep line 68 which is already `Api::forbidden`, keep 75/80/107/192/195/198/201/210/213/224 as-is), `tests/Functional/AdminApiTest.php`.

**Steps:**
- [ ] Read lines 85 and 89–106 to identify the two permission checks (`role` change gate; per-action `canAccessRoute`-style gate) and their exact Bengali messages.
- [ ] RED — two tests: (a) a caller that fails the role gate asserts **403** with the message `এই ভূমিকার অ্যাকাউন্ট পরিবর্তন করার অনুমতি নেই।`; (b) a caller lacking the action permission POSTs `do=<action>` → assert **403** with `আপনার এই অ্যাকশন করার অনুমতি নেই।` — both currently 400 (red). Construct the callers from `PermissionService`'s role→route table (read it; do not guess roles).
- [ ] Implement: replace only those `Api::fail(...)` calls with `Api::forbidden(<same message>)`.
- [ ] GREEN — rerun both; run the full `AdminApiTest`; if any pre-existing assertion elsewhere expects 400 on these paths (grep found none), update it to 403 and say so in the report.

**Commit:** `fix(api): answer admin-users permission denials with 403`.

## Task 1.5 — Receipt streaming endpoint for the app

**Goal:** `GET /api/admin/recharges/{id}/receipt` streams the stored receipt (bearer-authenticated) so the app can verify payments.

**Files:** new `src/Web/Api/Admin/AdminRechargeReceiptApiAction.php`, `config/common/routes.php` (next to line 213), `tests/Functional/AdminApiTest.php` (+ reuse the receipt-planting pattern from `tests/Functional/RechargeTest.php` around lines 850–895).

**Steps:**
- [ ] Read `src/Web/Account/ReceiptAction.php` fully — its authorization sentence ("owner may see their own, admins/staff may see any"), `nosniff`/`no-store` header set, and how it turns `ReceiptStorage` into a stream are the template. Read how `RechargeTest` plants a receipt file.
- [ ] RED — tests in `AdminApiTest`:
  - `rechargeReceiptStreamsBytesForAdmin`: plant topup row (existing fixture) + receipt file; `GET /api/admin/recharges/{id}/receipt` as admin → 200, `Content-Type` equals the stored mime, body equals `hash_file('sha256', ...)` of the planted file, `X-Content-Type-Options: nosniff` present (red: today 404 route).
  - `rechargeReceipt404WithoutReceipt`: row with no `receipt_path` → 404.
  - `rechargeReceiptForbiddenForPlainUser`: caller role `user` → 403 (AdminApiMiddleware authenticates anyone; the action's gate rejects).
  - `rechargeReceiptUnauthorizedWithoutToken`: no bearer → 401.
- [ ] Implement the invokable action: gate `$identity->canAccessAdmin()` (same as `AdminRechargesApiAction` lines 26–27); load row via `TopupRepository` → 404 if missing; 404 if `receipt_path` empty or `ReceiptStorage` cannot resolve it; stream with `Content-Type` from `receipt_mime` (fallback `application/octet-stream`), `Content-Length`, `Content-Disposition: inline; filename="..."` (sanitised `receipt_name`), `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`. Route:
  ```php
  Route::get('/recharges/{id}/receipt')->action(AdminRechargeReceiptApiAction::class)->name('api-admin-recharge-receipt'),
  ```
  (static `receipt` suffix after `{id}` is a distinct FastRoute pattern — no conflict with `/recharges/{id}`).
- [ ] GREEN — rerun `AdminApiTest`.
- [ ] Confirm `GET /api/admin/recharges/{id}` rows still carry `receipt_path` (the DTO needs it; if the action whitelists columns, add `receipt_path` to its row payload — note it in the report).

**Commit:** `feat(api): stream recharge receipts to the admin app`.

## Task 1.6 — `app_release` channel isolation

**Goal:** admin and TWA releases coexist; default `'twa'` behavior byte-identical; unknown channel → 400.

**Files:**
- new `migrations/M261016000000_AddChannelToAppRelease.php`
- `src/Repository/AppReleaseRepository.php` — `published()`, `minVersionCode()`, `publish()`
- `src/Service/AppReleaseService.php` — `current()`, `versionPayload()`, `minVersionCode()` wrapper if present
- `src/Web/Api/AppVersionApiAction.php`, `src/Web/Site/ApkDownloadAction.php`
- `tests/Functional/AppReleaseTest.php` (+ its endpoint coverage)

**Steps:**
- [ ] Read `migrations/M240106000000_AddReceiptFieldsToTopupRequest.php` for the project's repeat-safe `addColumn` guard, and read `AppReleaseRepository::publish()` fully (line 118+) plus every caller of `published()`/`minVersionCode()` (grep) before editing.
- [ ] RED — tests first (extend `AppReleaseTest`, which already builds `new AppReleaseService($this->releases, $this->releaseDir)`):
  - `adminChannelPublishDoesNotDisturbTwaChannel`: publish a `twa` row (vN) and an `admin` row (vM) → `published('twa')` still returns vN; `published('admin')` returns vM (red: no channel concept).
  - `versionPayloadDefaultsToTwaAndKeepsOldDownloadUrl`: `versionPayload(0, 'android')` → `download_url === '/app/apk'` (back-compat regression guard).
  - `versionPayloadForAdminChannelCarriesChannelUrl`: `versionPayload(0, 'android', 'admin')` → flags computed against the admin row, `download_url === '/app/apk?channel=admin'`.
  - `sameVersionCodeAllowedAcrossChannels`: `publish()` twice with identical `version_code`, different `channel` → two rows (red: unique index).
  - Endpoint tests: `GET /api/app/version?channel=admin` → admin payload; `?channel=ios-typo` → **400**; `GET /app/apk?channel=admin` → streams the admin file (or 404 when absent) while `GET /app/apk` still serves TWA.
- [ ] Implement:
  - Migration: add `channel` `string(16) NOT NULL DEFAULT 'twa'`; drop index `ux_app_release_version_code`; create unique `ux_app_release_channel_version` (`channel`, `version_code`); guard both with the neighbour migration's repeat-safe pattern.
  - Repository: `published(string $channel = 'twa')` → `WHERE [[channel]] = :ch AND [[is_published]] = 1`; `minVersionCode(string $channel = 'twa')` likewise; `publish()` takes `$data['channel'] ?? 'twa'`, scopes both the "existing row" lookup and the unpublish-others `UPDATE` by channel.
  - Service: `current(string $channel = 'twa')`, `versionPayload(int $clientCode, ?string $platform, string $channel = 'twa')`, download_url `'twa' → '/app/apk'`, otherwise `'/app/apk?channel=' . rawurlencode($channel)`.
  - `AppVersionApiAction`: read `$query['channel'] ?? 'twa'`, whitelist `['twa', 'admin']` else `Api::fail('Unknown release channel.', [], 400)`, pass through.
  - `ApkDownloadAction`: change `__invoke()` → `__invoke(ServerRequestInterface $request)`, same whitelist/validation (400 text response is fine here), pass to `current()`.
  - `AppPageAction` untouched (implicitly `'twa'`).
- [ ] GREEN — `vendor\bin\codecept.bat run Functional AppReleaseTest --no-colors`, then the full Functional suite for TWA regressions.
- [ ] Apply to dev DB: `php yii migrate` → expect `M261016000000_AddChannelToAppRelease` applied (check codeception config for how test schema is provisioned; migrate the test DB too if separate).

**Commit:** `feat(api): scope app_release by channel so admin and TWA releases coexist`.

## Task 1.7 — `app:release:publish` console command

**Goal:** a supported, tested way to publish a release (currently `publish()` has no caller anywhere).

**Files:** new `src/Console/AppReleasePublishCommand.php`, `config/console/params.php` (line ~46 area), new `tests/Console/AppReleasePublishCest.php` (or an addition to `tests/Console/YiiCest.php` after reading its harness).

**Steps:**
- [ ] Read `src/Console/TwaFingerprintCommand.php` (attribute + `configure()`/`execute()` style) and `tests/Console/YiiCest.php` (how commands are invoked in tests).
- [ ] RED — test: create a marker `.apk` temp file; invoke the command with `--file=<path> --channel=admin --version-code=7 --version-name=1.1.0 --min-version-code=2 --notes="..."` → assert a row exists with `channel='admin'`, `version_code=7`, `sha256 === hash_file(...)` of the file, file now inside `web/releases/` under a safe name, `is_published=1`; second run same channel+version → still one row (update, not duplicate); a `twa` row planted before remains published (red: command missing).
  - Cleanup in `_after`: delete planted row(s) by unique version_code marker + the copied file.
- [ ] Implement `#[AsCommand('app:release:publish', '...')]` with arguments/options mirroring `TwaFingerprintCommand`'s `configure()` style: `--file` (required, absolute/relative path to the APK), `--channel` (default `twa`, whitelist `twa|admin`), `--version-code` (optional; default = parse `versionCode =` from `android-admin/app/build.gradle.kts`, error if unparseable), `--version-name` (same fallback parse), `--min-version-code` (default 0), `--notes` (default ''). Copy the file into `web/releases/` under a sanitised name (`alif-tools-<version_name>-<channel>.apk`), compute `sha256` + size, then call `AppReleaseRepository::publish([...])`. Refuse paths outside `web/releases` **after** the copy is decided (the copy source may be anywhere; only the stored relative name matters). Register in `config/console/params.php`: `'app:release:publish' => AppReleasePublishCommand::class,` (both attribute and registration, like `app:twa:fingerprints`).
- [ ] GREEN — rerun the console suite: `vendor\bin\codecept.bat run Console --no-colors`.

**Commit:** `feat(console): publish app releases with channel, checksum and notes`.

## Task 1.8 — Full suite + README count

**Steps:**
- [ ] `composer test` → expect OK; record exact test/assertion totals.
- [ ] Update `README.md` test-status line (currently ৯১৬ / ৫,৮৭৫) to the real numbers (Bengali digits, same format).

**Commit:** `docs: refresh README test totals after launch-readiness work`.

**Phase 1 push:** `git push` after T1.8.

---

# Phase 2 — Documentation Sync (code is the truth)

## Task 2.1 — `docs/ADMIN_ANDROID_API.md` corrections

**Goal:** every statement matches the code just verified/changed.

**Steps (edit each; verify against the named file before writing):**
- [ ] Line 45 (`POST /api/auth/refresh`): request is **not** a body — document `Authorization: Bearer <refresh>` (code: `AuthApiAction::refresh` line 120; app: `AdminApiService.refresh` header). Same for `logout` if described as body-based.
- [ ] Line 67 `trashed`: `(0/1)` → `exclude` (default) | `only` | `all` (code + `AdminApiService.users`).
- [ ] Line 74 `role` action body: `{id, role}` → `{user_id, role}` (code reads `user_id`).
- [ ] Line 94 recharge reject: `reason` → `note` (code branch + app field).
- [ ] Line 139 device register: `device_token` → `token`, JSON body (matches `DeviceApiAction`).
- [ ] Line 22 statuses: add `422` (validation) and `429` (login throttle, new in T1.3); note 400 = business/validation, 403 = permission (after T1.4 the matrix is real).
- [ ] Line 56 + staff section (line 114): `GET /api/admin/staff` is **not** superadmin-only — `AdminStaffApiAction` allows any `canAccessAdmin()` (verify before editing; if the code really is SA-only, leave the doc and say so in the report instead).
- [ ] Add missing endpoint entries: `POST /api/admin/staff` `do=reset` (temporary password; superadmin), withdraws `claim`/`release`, notifications `retry`/`delete` with `{action}` path + unknown-action 400, `POST /api/auth/firebase`, and the new `GET /api/admin/recharges/{id}/receipt` (document it as the deliberate binary exception: raw bytes, `nosniff`/`no-store`, same auth).
- [ ] `GET /api/app/version`: add `channel` (`twa` default | `admin`, unknown → 400) and the `download_url` field.
- [ ] Errors table (line 162 area): fix `openOrders` → actual field name; note `balance` arrives as float; settings save failures return 400 with `errors` (not silent); login failures collapse to one generic message.
- [ ] Status-code table (line 163): 403 → "role/permission insufficient" (now includes user-action denials).

**Commit:** `docs(api): align ADMIN_ANDROID_API.md with the implemented contract`.

## Task 2.2 — Architecture + Security docs stale claims

**Steps:**
- [ ] `docs/ADMIN_ANDROID_ARCHITECTURE.md`: delete/replace the stale gap text claiming "serverless/No routes registered" (verify the current wording first); refresh section says body `{refresh}` → header; note the release channel decision + `app:release:publish`; note biometric is now wired (Phase 3 completes before this commit? — **order note:** commit this task *after* Phase 3, or write only the Phase-1-true parts now and the biometric parts in T3.4's commit; do not document unwired features).
- [ ] `docs/ADMIN_ANDROID_SECURITY.md` line 35 (staff role claims) → align with the code reality found in T2.1; L26/L31 remain the requirements (satisfied in Phase 3 — tick only after T3.3/T3.4 verify).

**Commit:** `docs: remove stale gaps and record the release-channel design`.

---

# Phase 3 — Android Completion & Hardening

Baseline rule for every task: `. \gradlew.bat :app:testDebugUnitTest --no-daemon` must stay green.

## Task 3.1 — In-app update check (channel `admin`)

**Goal:** the app discovers updates against the admin channel and offers/asks for them.

**Files:** `data/api/AdminApiService.kt` (`appVersion` + `AppVersion`), `ui/viewmodel/DashboardViewModel.kt`, `ui/screens/dashboard/DashboardScreen.kt`, new `app/src/test/java/com/aliftools/admin/ui/viewmodel/DashboardViewModelTest.kt`, `data/model/ApiModels.kt` (add `AppVersion` parsing coverage in `ApiParsingTest` if the class stays in `AdminApiService.kt` — parse test goes wherever the sibling DTOs are tested).

**Steps:**
- [ ] `AdminApiService`:
  ```kotlin
  @GET("api/app/version")
  suspend fun appVersion(
      @Query("version_code") versionCode: Int,
      @Query("platform") platform: String = "android",
      @Query("channel") channel: String = "admin",
  ): Response<ApiResponse<AppVersion>>
  ```
  and `data class AppVersion` gains `@SerializedName("download_url") val downloadUrl: String? = null`.
- [ ] `AdminRepository.appVersion()` passes `BuildConfig.VERSION_CODE` (repository already has the context? — if not, pass the code in from the ViewModel; follow how `BuildConfig` is reached elsewhere in the module; do not add a Context dependency just for this).
- [ ] `DashboardViewModel`: add `private val _update = MutableStateFlow<AppVersion?>(null)` + `val update: StateFlow<AppVersion?>`; in `init` (alongside `load()`) launch `repository.appVersion(...)` → `onSuccess { _update.value = it.takeIf { av -> av.versionCode > BuildConfig.VERSION_CODE } }`, `onFailure { /* leave null — an update check must never break the dashboard */ }`. Expose `updateRequired: StateFlow<Boolean>` derived from `minVersionCode > BuildConfig.VERSION_CODE`.
- [ ] `DashboardScreen`: `update_available` → dismissible card (version name + notes + button "আপডেট করুন" → `Intent(ACTION_VIEW, Uri.parse(downloadUrl ?: "https://your-domain.com/app/apk"))`); `update_required` → `AlertDialog` (title "আপডেট প্রয়োজন", body release notes, confirm "ডাউনলোড করুন" opens the URL, dismiss "পরে" — dismissible on purpose so a bad `min_version_code` can never hard-brick a build; it reappears next launch).
- [ ] Tests (`DashboardViewModelTest.kt`, `Dispatchers.setMain` + mockk `AdminRepository` + turbine, mirroring `AdminRepositoryTest` style):
  - `updateAvailableSurfacesWhenServerOffersNewer` — repo returns `versionCode = BuildConfig.VERSION_CODE + 1` → `update` emits it.
  - `noUpdateWhenServerMatchesOrOlder` — returns same code → `update` stays null.
  - `updateCheckFailureLeavesDashboardIntact` — `appVersion` returns failure, `dashboard` returns success → `state` is `Success`, `update` null.
  - `requiredFlagFromMinVersionCode` — `minVersionCode = 999` → `updateRequired` true.
- [ ] `ApiParsingTest`: parse a version JSON incl. `download_url` → field non-null.
- [ ] Run: `.\gradlew.bat :app:testDebugUnitTest --no-daemon` → green.

**Commit:** `feat(android-admin): per-channel in-app update check with dashboard prompts`.

## Task 3.2 — Receipt viewing in the recharge detail

**Goal:** admins can look at the receipt before approving.

**Files:** `data/api/AdminApiService.kt`, `data/repository/AdminRepository.kt`, `data/model/ApiModels.kt` (`AdminTopup` + `@SerializedName("receipt_path") val receiptPath: String? = null`), `ui/screens/recharges/RechargesScreen.kt`, `AndroidManifest.xml` + new `res/xml/file_paths.xml`, new `util/ReceiptFileStore.kt` (or repository-private helpers).

**Steps:**
- [ ] API: `@GET("api/admin/recharges/{id}/receipt") suspend fun rechargeReceipt(@Path("id") id: Int): Response<okhttp3.ResponseBody>` (raw body — envelope does not apply, as documented in T2.1).
- [ ] Repository: `suspend fun rechargeReceipt(id: Int): Result<java.io.File>` — non-envelope path: `if (!response.isSuccessful) parse Api error envelope into Result.failure` else read `Content-Type` header → extension map (`image/jpeg→jpg`, `image/png→png`, `application/pdf→pdf`, fallback `bin`), write bytes to `context.cacheDir/receipts/<id>.<ext>` (create dirs; delete stale same-id file first), return the `File`. Needs `@ApplicationContext` — check how the repository already gets Context (it does for `appVersion`-adjacent concerns? if it has none, inject `@ApplicationContext` the same way `TokenStorage` does).
- [ ] `RechargesScreen` detail: when `receiptPath != null`, show button `রশিদ দেখুন`:
  - image mime → `AsyncImage(model = file, contentDescription = "রশিদ")` in an `AlertDialog` (Coil loads a local `File` — no auth, EXIF rotation handled);
  - pdf/other → `FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)` + `Intent(ACTION_VIEW).setDataAndType(uri, mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)` inside `try/catch (ActivityNotFoundException → snackbar "এই ফাইল খোলা যায়নি")`.
  - loading/error/empty states per project norms (button disabled + spinner while fetching).
- [ ] Manifest: `<provider android:authorities="${applicationId}.fileprovider" android:exported="false" android:grantUriPermissions="true"><meta-data android:name="android.support.FILE_PROVIDER_PATHS" android:resource="@xml/file_paths"/></provider>`; `res/xml/file_paths.xml`: `<cache-path name="receipts" path="receipts/"/>`.
- [ ] No JVM test can cover Coil/intents — verification: build green + backend tests (T1.5) + smoke item added in T4.5. Say this honestly in the report.

**Commit:** `feat(android-admin): view recharge receipts before approving`.

## Task 3.3 — Tokens in `EncryptedSharedPreferences`

**Goal:** SECURITY.md L26 satisfied; all signatures unchanged (mockk seam preserved).

**Files:** `gradle/libs.versions.toml`, `app/build.gradle.kts` (dependency), `data/local/TokenStorage.kt`.

**Steps:**
- [ ] Add to `libs.versions.toml` `[versions]` + `[libraries]`: `androidx-security-crypto = { group = "androidx.security", name = "security-crypto", version = "1.1.0-alpha06" }` (check the versions file's naming convention first; if a newer stable exists at implementation time, use it and note it).
- [ ] `implementation(libs.androidx.security.crypto)` in the Storage block of `app/build.gradle.kts`.
- [ ] Rewrite `TokenStorage`: `MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM)` + `EncryptedSharedPreferences.create(...)` holding ONLY `access_token`, `refresh_token`, `expires_at`, `user_role`. Keep DataStore for `remembered_identifier` + `biometric_enabled` (non-secrets). Preserve **every** public signature exactly:
  `accessTokenFlow`, `refreshTokenFlow`, `userRoleFlow`, `biometricEnabledFlow`, `rememberedIdentifierFlow`, `setRememberedIdentifier`, `saveTokens`, `clearTokens`, `setBiometricEnabled`.
  Flow exposure: back the ESP values with a `MutableStateFlow` initialised on first access and updated inside each mutation (ESP is synchronous; a cold `flow { }` re-read per collector is acceptable too — choose one, keep it simple).
- [ ] No migration from DataStore (pre-launch; installed dev builds get logged out once) — add one comment saying why, and one line in the deployment doc (T4.3).
- [ ] Run `.\gradlew.bat :app:testDebugUnitTest --no-daemon` → green (all `TokenStorage` consumers use mockk — signatures unchanged ⇒ tests unaffected). Device verification is smoke-only (T4.5): login → kill app → relaunch still authenticated.

**Commit:** `feat(android-admin): store API tokens in EncryptedSharedPreferences`.

## Task 3.4 — Biometric gate wiring

**Goal:** opt-in unlock-on-open + step-up before the five sensitive ops (SECURITY.md L30–31), client-side only.

**Files:** `data/repository/AdminRepository.kt` (flag passthroughs), new `ui/components/BiometricGate.kt` (incl. `@EntryPoint`), `MainActivity.kt`, `ui/screens/dashboard/DashboardScreen.kt` (toggle), `ui/screens/withdraws/WithdrawsScreen.kt`, `ui/screens/users/UsersScreen.kt`, `ui/screens/settings/SettingsScreen.kt`, `ui/screens/orders/OrdersScreen.kt`.

**Steps:**
- [ ] Repository passthroughs following the `userRoleFlow` pattern: `fun biometricEnabledFlow(): Flow<Boolean>` and `suspend fun setBiometricEnabled(enabled: Boolean)`.
- [ ] `BiometricGate.kt`:
  ```kotlin
  @EntryPoint @InstallIn(SingletonComponent::class)
  interface BiometricGateEntryPoint { fun biometricHelper(): BiometricHelper }

  @Composable fun rememberBiometricGate(onGranted: () -> Unit): () -> Unit
  ```
  Behaviour: read the enabled flag (repository flow via the screen's ViewModel or a small shared holder — pick the least invasive: pass the flag into the composable from the screen's existing VM state; add the flag to whichever VM already exposes flows, do not create a new VM just for this) → if disabled or `helper.isBiometricAvailable()` != SUCCESS, invoke `onGranted` directly; else `helper.showPrompt(activity, onSuccess = onGranted, onFailure = { /* keep current state; prompt already dismissed */ })` with `LocalContext.current as FragmentActivity`.
- [ ] Wrap exactly these five call sites (grep to confirm current names): `WithdrawsViewModel.approve`/`reject` callers in `WithdrawsScreen`; `UsersViewModel.changeRole` and `deleteUser` in `UsersScreen`; `SettingsViewModel.save` in `SettingsScreen`; `OrdersViewModel.settleSelected` in `OrdersScreen`. Each: capture `val gated = rememberBiometricGate { vm.approve(row) }` and call `gated()` from the existing confirm button. Nothing else changes (server-side checks remain authoritative).
- [ ] `MainActivity`: inject `BiometricHelper` + repository (repository already injected); add `var locked by mutableStateOf(false)`; in `LaunchedEffect(Unit)` before splash routing: if token present && flag enabled && available → `locked = true; helper.showPrompt(this, onSuccess = { locked = false }, onFailure = {})`. Render: `if (locked) BiometricLockScreen(onRetry = { prompt again }, onClose = { finish() })` else the existing `NavHost` content (a simple locked composition: app name + Bengali "অনুমোদন প্রয়োজন" + retry/close buttons).
- [ ] `DashboardScreen` toggle: `Switch` labelled `বায়োমেট্রিক লক` with subtitle `অ্যাপ খোলার ও গুরুত্বপূর্ণ অ্যাকশনে যাচাই চাইবে`; when the device has no biometric/credential enrolled, show helper text `ডিভাইসে বায়োমেট্রিক সেট করুন` and do not enable the flag (check `isBiometricAvailable()` before `setBiometricEnabled(true)`).
- [ ] Honest verification note: BiometricPrompt cannot run in JVM unit tests — rely on compile + unit suite green + the manual smoke steps (T4.5): enable toggle → relaunch prompts → cancel keeps locked → approve enters; step-up appears on withdraw approve with flag on, not with flag off.

**Commit:** `feat(android-admin): opt-in biometric unlock and step-up for sensitive actions`.

## Task 3.5 — R8 keeps the Gson DTOs

**Goal:** release JSON parsing cannot break by field renaming; provable via `mapping.txt`.

**Files:** `app/proguard-rules.pro`.

**Steps:**
- [ ] Append:
  ```
  # Gson reflects over these DTOs field-by-field; an obfuscated field name
  # becomes a JSON key the PHP server never sends.
  -keep class com.aliftools.admin.data.model.** { *; }
  ```
- [ ] `.\gradlew.bat :app:assembleRelease --no-daemon` → expect `BUILD SUCCESSFUL` (unsigned is fine locally).
- [ ] `Select-String -Path app\build\outputs\mapping\release\mapping.txt -Pattern '-> com\.aliftools\.admin\.data\.model\.AdminTopup:'` → expect a line (class name preserved). Also confirm no `data.model.* -> ` obfuscated-renaming line for that class.
- [ ] CI does the same grep (T4.2).

**Commit:** `fix(android-admin): keep Gson DTOs intact under R8`.

## Task 3.6 — Release builds refuse the placeholder BASE_URL

**Goal:** a release APK with `https://your-domain.com/` cannot be produced.

**Files:** `android-admin/app/build.gradle.kts`.

**Steps:**
- [ ] After the `android { }` block, register a variant guard (release only; debug unaffected):
  ```kotlin
  androidComponents {
      beforeVariants(selector().withBuildType("release")) {
          if (baseUrl == "https://your-domain.com/") {
              throw GradleException(
                  "ADMIN_BASE_URL is not set. Provide it via android-admin/local.properties or the ADMIN_BASE_URL environment variable (see docs/ADMIN_ANDROID_SETUP.md)."
              )
          }
      }
  }
  ```
  (Confirm the `androidComponents` accessor is available with the applied plugins; if the lambda parameter shape differs by AGP version, adapt — the invariant is: release fails, debug does not.)
- [ ] Verify red: temporarily comment out the `ADMIN_BASE_URL` line in `local.properties`, run `.\gradlew.bat :app:assembleRelease --no-daemon` → expect the readable failure; restore the line; run again (with T3.5's keep rules) → expect `BUILD SUCCESSFUL`. Report exactly what you observed.
- [ ] `.\gradlew.bat :app:assembleDebug --no-daemon` with `ADMIN_BASE_URL` absent from env → still green (guard must not leak into debug).

**Commit:** `build(android-admin): fail release builds missing ADMIN_BASE_URL`.

## Task 3.7 — Firebase: optional at build time, provisionable for release

**Goal:** FCM + phone login become activatable by dropping in `google-services.json`; repo builds without it.

**Files:** `gradle/libs.versions.toml` (`[plugins]`), `android-admin/build.gradle.kts` (root: `apply false`), `app/build.gradle.kts` (conditional apply), `.gitignore`.

**Steps:**
- [ ] `libs.versions.toml`: `[plugins] google-services = { id = "com.google.gms.google-services", version = "4.4.2" }` (verify latest 4.4.x at implementation).
- [ ] Root `android-admin/build.gradle.kts`: `alias(libs.plugins.google.services) apply false`.
- [ ] `app/build.gradle.kts` (after `plugins { }`): `if (file("google-services.json").exists()) { apply(plugin = libs.plugins.google.services.get().pluginId) }` — or the equivalent literal `"com.google.gms.google-services"` if the accessor is not available at that point.
- [ ] `.gitignore`: add `/android-admin/app/google-services.json`.
- [ ] Verify green without the file: `.\gradlew.bat :app:testDebugUnitTest :app:assembleDebug --no-daemon` → `BUILD SUCCESSFUL`.
- [ ] Provisioning steps (Firebase console: create/add apps `com.aliftools.admin` + `com.aliftools.admin.debug`, enable Phone auth, add SHA-1 of debug **and** release keystores, download json into `android-admin/app/`) are written into `docs/ADMIN_ANDROID_DEPLOYMENT.md` in T4.3 — the app tolerates absence either way (phone button no-ops, FCM silent), which stays the documented behavior until the file exists.

**Commit:** `build(android-admin): apply google-services plugin only when configured`.

**Phase 3 push:** `git push` after Phase 2 + 3 commits.

---

# Phase 4 — Release Infrastructure, Docs & Final Verification

## Task 4.1 — Keystore: ignore rules, example file, one local keystore

**Steps:**
- [ ] `.gitignore` additions (Android block near line 103):
  ```
  /android-admin/keystore.properties
  /android-admin/*.jks
  /android-admin/*.keystore
  ```
  Then `git check-ignore android-admin/keystore.properties android-admin/test.jks` → expect both echoed; `git check-ignore android-admin/local.properties.example android-admin/keystore.properties.example` → expect **no** output (examples stay tracked).
- [ ] Create `android-admin/keystore.properties.example` mirroring the keys `build.gradle.kts` actually reads (`secret()`/`localProperty()` — verified): `ADMIN_STORE_FILE`, `ADMIN_STORE_PASSWORD`, `ADMIN_KEY_ALIAS`, `ADMIN_KEY_PASSWORD`, `ADMIN_BASE_URL` (commented with "trailing slash required").
- [ ] Generate the real keystore **locally only**: ask the user for the store/key passwords (use the question tool — do NOT invent or echo passwords anywhere) then run `keytool -genkeypair -v -keystore G:\Web\allseba-online\android-admin\release.jks -alias alifadmin -keyalg RSA -keysize 2048 -validity 10000 -storepass <user-pass> -keypass <user-pass> -dname "CN=Alif Tools Admin"`, and write the matching `android-admin/keystore.properties` (gitignored) with `ADMIN_STORE_FILE=release.jks`.
- [ ] `keytool -list -v -keystore android-admin\release.jks -alias alifadmin -storepass <user-pass>` → capture the **SHA-1** (and SHA-256) — these go into the Firebase console (T3.7) and are quoted in the deployment doc *as fingerprints only* (fingerprints are safe to document; passwords are not).
- [ ] `git status --short` → expect `keystore.properties.example` + `.gitignore` staged; **no** `release.jks` / `keystore.properties`.

**Commit:** `build(android-admin): gitignore keystores and add signing template`.

## Task 4.2 — CI workflow `android-admin-apk.yml`

**Files:** new `.github/workflows/android-admin-apk.yml`.

**Steps:**
- [ ] Clone the structure of `.github/workflows/android-apk.yml` (verified: checkout → setup-java temurin 17 → `android-actions/setup-android@v3` → `sdkmanager --install "platform-tools" "platforms;android-36" "build-tools;35.0.0"` → `gradle/actions/setup-gradle@v4` → conditional keystore decode → assemble → lint → upload), changing:
  - `name: Android APK (Admin)`; paths: `android-admin/**` + the workflow file; concurrency group `android-admin-apk-${{ github.ref }}`; working-directory `android-admin`.
  - env: `ADMIN_STORE_PASSWORD`, `ADMIN_KEY_ALIAS`, `ADMIN_KEY_PASSWORD`, `ADMIN_BASE_URL` from secrets (empty on fork PRs, same comment style as the TWA file).
  - Step "Decode release keystore": guarded on `ADMIN_KEYSTORE_BASE64` (same `$RUNNER_TEMP/admin-release.jks` + `keytool -list` fail-fast pattern); writes `ADMIN_STORE_FILE=$key` to `$GITHUB_ENV` and `signed=true`.
  - Step "Require base URL when signing": if `signed == 'true'` and `ADMIN_BASE_URL == ''` → `exit 1` with a readable message (works *with* T3.6's guard, not against it).
  - Step "Provide google-services.json (optional)": if secret `ANDROID_GOOGLE_SERVICES_JSON` non-empty → `printf '%s' "$ANDROID_GOOGLE_SERVICES_JSON" > app/google-services.json`.
  - Steps: `.\./gradlew --no-daemon testDebugUnitTest` always; `assembleDebug` always; `assembleRelease` if signed; if release built: `grep -q '-> com.aliftools.admin.data.model.AdminTopup:' app/build/outputs/mapping/release/mapping.txt` (R8 keep proof) and the `keytool -printcert -jarfile` fingerprint printout (like the TWA workflow, for the Firebase SHA-1); lint `continue-on-error: true`; upload artifacts `admin-apk` (30 days) + `admin-mapping` + lint report.
  - Keep `permissions: contents: read`.
- [ ] Static check now; real validation happens on push: after `git push`, watch the Actions run → expect tests + debug assemble green on a runner without secrets (release steps skipped or failing *only* with the readable base-url message if keystore secret is set — whichever applies, report actual).
- [ ] If `gh` CLI is authenticated: `gh run list --workflow=android-admin-apk.yml` to confirm.

**Commit:** `ci: build and test the admin APK on android-admin changes`.

## Task 4.3 — Setup + Deployment docs

**Files:** new `docs/ADMIN_ANDROID_SETUP.md`, new `docs/ADMIN_ANDROID_DEPLOYMENT.md`.

**Steps:**
- [ ] `ADMIN_ANDROID_SETUP.md`: prerequisites (JDK 17+ / Android Studio JBR; Android SDK cmdline-tools + `platform-tools` `platforms;android-36` `build-tools;35.0.0`); `local.properties` keys (`sdk.dir` escaped-colon example, `ADMIN_BASE_URL` trailing slash); `keystore.properties` keys + pointer to the example file; optional `app/google-services.json`; commands (from `android-admin/`): `.\gradlew.bat testDebugUnitTest`, `assembleDebug`, `assembleRelease`; troubleshooting: AGP/JDK mismatch, `ADMIN_BASE_URL` release guard message, Hilt/kapt failures. Every path/command must exist as written (verify each).
- [ ] `ADMIN_ANDROID_DEPLOYMENT.md`: versioning (`versionCode` monotonic integer, bump for **every** published build; `versionName` human semver; both in `app/build.gradle.kts` lines 50–51); local keystore lifecycle (generate once, back up outside the repo, passwords in a password manager, SHA-1s → Firebase console); CI secrets table (`ADMIN_KEYSTORE_BASE64`, `ADMIN_STORE_PASSWORD`, `ADMIN_KEY_ALIAS`, `ADMIN_KEY_PASSWORD`, `ADMIN_BASE_URL`, optional `ANDROID_GOOGLE_SERVICES_JSON`); publish runbook: bump versions → push → download the `admin-apk` artifact → verify mapping check + fingerprint → copy APK to `web/releases/` → `php yii app:release:publish --file=web/releases/<name>.apk --channel=admin --version-code=<N> ...` → verify `GET /api/app/version?channel=admin` and `GET /app/apk?channel=admin` → smoke; **channel invariant**: never publish an admin row with `channel=twa`; rollback = publish previous APK again or flip `is_published` for the admin channel only; token-storage cutover note (T3.3: one-time logout on upgrade from pre-launch dev builds); Firebase console checklist (apps for both applicationIds, Phone auth on, SHA-1s added).
- [ ] Docs-readability pass: cross-links resolve (`README.md`, the three ADMIN_ANDROID_* docs, `docs/ADMIN_ANDROID_SMOKE_TEST.md`).

**Commit:** `docs: Android admin setup and deployment runbooks`.

## Task 4.4 — README section + final counts

**Steps:**
- [ ] Add a short "Android admin app" subsection next to the existing TWA/README coverage: purpose (staff/admin mobile console, distinct from `twa/`), build/test commands (from `android-admin/`), link to `docs/ADMIN_ANDROID_SETUP.md` / `ADMIN_ANDROID_DEPLOYMENT.md` / `ADMIN_ANDROID_API.md`.
- [ ] Re-run `composer test` if any PHP test changed since T1.8 (expected: none — Phase 3/4 add no PHP tests) and confirm the README counts still match reality; fix if not.

**Commit:** `docs: add Android admin section to README`.

## Task 4.5 — Final verification + smoke checklist

**Steps:**
- [ ] PHP: `composer test` → expect OK; record totals.
- [ ] Android: `.\gradlew.bat :app:testDebugUnitTest --no-daemon` → `BUILD SUCCESSFUL`; record count.
- [ ] Release: `.\gradlew.bat :app:assembleRelease --no-daemon` → `BUILD SUCCESSFUL`; mapping grep (T3.5) → found.
- [ ] `composer palette:check` → healthy (no CSS source touched; expect zero diff).
- [ ] No `npm run build` needed (no `resources/` changes in this plan) — state this rather than skipping silently; if any task unexpectedly edited a Twig/CSS file, run the build and report.
- [ ] `docs/ADMIN_ANDROID_SMOKE_TEST.md`: add device steps — install signed release APK, login/logout/refresh (token survives relaunch: ESP), update card appears when server `version_code` is higher (publish a test admin row then unpublish), receipt image + PDF paths, biometric toggle → relaunch gate → step-up on withdraw approve, push registration only with `google-services.json` present, FCM notification lands in Notifications screen.
- [ ] `docs/ADMIN_ANDROID_SECURITY.md` §10: tick only items actually verified here (token storage, cleartext, minify/strip, one-retry flow); leave device-only items explicitly unchecked with a note pointing at the smoke doc.
- [ ] `git diff --check` → no whitespace errors (ignore benign CRLF warnings from `core.autocrlf=true`); `git status --short --branch` → clean or only intended files; confirm no secrets (`git status` must not list `*.jks`, `keystore.properties`, `local.properties`, `google-services.json`).
- [ ] Push remaining commits.

**Commit:** `docs: final smoke checklist and security sign-off notes`.

---

## Compatibility / Regression Testing

| Area | Guard |
|---|---|
| TWA download/update flow | `AppReleaseTest` back-compat tests: default channel responses identical (`download_url === '/app/apk'`, `/app/apk` serves TWA) |
| Web admin (browser) | Full `composer test`; no Twig/CSS touched (no `npm run build` needed) |
| Android API client | No consumed field renamed; envelope untouched; 429/403 fall into the app's existing error-toast path (verify `AuthFlowIntegrationTest` + code path once) |
| Notification retry | Web Cest asserts retry still 400s on non-dead rows / routes correctly |
| Existing Android tests | 4 test files stay green after every Phase 3 task (mockk seam: `TokenStorage` signatures unchanged) |
| Debug builds | T3.6 guard verified not to leak into `assembleDebug` |

## Deferred (deliberate, out of this plan)

- `strings.xml` localization of hardcoded Bengali strings (consistent, not launch-blocking).
- Removing unused dependencies (kotlinx-serialization plugin, possibly coil usage audit) — separate hygiene pass.
- AAB / Play Store distribution (architecture doc already recommends direct APK).
- Server-side step-up challenge for biometrics (SECURITY.md explicitly defers this).
- Robolectric/instrumented tests for `TokenStorage` internals and BiometricPrompt (device smoke covers v1).

## Final verification commands (expected results)

```
php yii migrate                                  → M261016000000 applied (dev; test DB too if separate)
vendor\bin\codecept.bat run Web AdminApiRouterCest --no-colors   → OK
vendor\bin\codecept.bat run Functional --no-colors               → OK (all)
vendor\bin\codecept.bat run Console --no-colors                  → OK
composer test                                    → OK (new totals > 916 / 5875)
cd android-admin
.\gradlew.bat :app:testDebugUnitTest --no-daemon → BUILD SUCCESSFUL
.\gradlew.bat :app:assembleDebug --no-daemon     → BUILD SUCCESSFUL
.\gradlew.bat :app:assembleRelease --no-daemon   → BUILD SUCCESSFUL (ADMIN_BASE_URL + keystore present)
findstr "data.model.AdminTopup" app\build\outputs\mapping\release\mapping.txt → match
git status --short --branch                      → clean; no secrets
```
