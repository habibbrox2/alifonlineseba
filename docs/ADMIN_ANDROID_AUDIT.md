# Admin Android App — Full Audit (Backend + Frontend + UI + JS)

**Scope:** `android-admin/` (Kotlin/Compose) and its backend contract `src/Web/Api/Admin/*`, web admin panel `src/Web/Admin/*` + `resources/views/site/admin/*`, auth/security, notifications, release infra.
**Method:** static inspection of source + attempted compile/test runs. Items marked ✅ verified by command, ⚠️ static-only, ❌ broken/not verified.

## 1. Executive summary

- The launch-readiness plan (Phases 0–4) is **mostly landed** in git history (commits `c0b8eee` … `18da2e7`): notification delete routing, orders sort whitelist, login throttle, 403 permission denials, receipt streaming, channel-scoped `app_release`, `app:release:publish`, docs sync, in-app update check, receipt viewing, R8 keep-rules, release BASE_URL guard, Firebase optional plugin, CI workflow, setup/deployment docs.
- **Two security hardening items were explicitly deferred** (commit `1bb7c50`): EncryptedSharedPreferences token storage and biometric gate wiring. Tokens still live in (insecure) DataStore/plain prefs — SECURITY.md L26 not satisfied.
- **The Android app currently does NOT compile** (verified): `:app:compileDebugKotlin` fails in `NotificationRetryHandler.kt` and `DashboardScreen.kt`. Root cause appears to be syntax/annotation issues in newly added "accessibility" code (`@Serializer`, `.semantics { heading { … } }` misuse, `av` out-of-scope reference). This blocks all unit tests and release builds.
- Backend PHP side is largely coherent with the documented contract; a few mismatches and dead code remain (see §3).

## 2. Build & test status (verified)

| Check | Command | Result |
|---|---|---|
| Android compile (debug) | `gradlew :app:compileDebugKotlin` | ❌ FAILED — `NotificationRetryHandler.kt`, `DashboardScreen.kt` |
| Android unit tests | `gradlew :app:testDebugUnitTest` | ❌ not run (blocked by compile) |
| Android release build | `gradlew :app:assembleRelease` | ❌ not run (blocked) |
| PHP lint (touched files) | `php -l` | ⚠️ not executed in this pass; recommend per-file |
| Composer test suite | `composer test` | ⚠️ not executed in this pass (requires test DB) |

## 3. Backend API audit

### 3.1 Verified contracts (app ↔ server agree)
- `usersAction`: sends `user_id`, backend reads `user_id` ✅ (`AdminUsersApiAction` L73).
- Recharge reject: app sends `note`, backend reads `note` ✅.
- Withdraws: app sends `reason` for reject, backend reads `reason` ✅.
- Devices: app sends `token`, backend reads `token` ✅.
- Orders bulk: app sends `ids[]`, backend builds array ✅.
- Notifications: merged route `POST /notifications/{id}/{action}` ✅; unknown action → 400 ✅.
- Permission denials now return 403 (not 400) ✅ (`AdminUsersApiAction`).
- Login throttle 5/300s → 429 ✅ (`AuthApiAction`).

### 3.2 Findings
1. **Staff endpoint role gate (doc vs code).** `docs/ADMIN_ANDROID_API.md` L114 says staff is `admin-staff` permission (any admin). Code `AdminStaffApiAction` allows `canAccessAdmin()`. Verify superadmin-only claims in docs; mismatch if docs still say SA-only. ⚠️ confirm.
2. **`AdminRechargesApiAction::approve` ignores `[$ok]` tuple second element.** It destructures only `$ok`; if `TopupService::approve` returns `[bool, string]` message, the richer failure reason is dropped. Minor. ⚠️
3. **`AdminWithdrawsApiAction` GET returns `status`/`q` echoed** but Android `PagedResponse` may not model them — harmless extra fields. ⚠️
4. **`AppVersionApiAction` channel whitelist** `['twa','admin']` — Android defaults `admin` ✅; unknown → 400 ✅. Ensure TWA default unchanged (regression tests exist).
5. **Receipt streaming** returns raw bytes (documented binary exception) ✅; `Content-Disposition` sanitization must be checked against path traversal in `receipt_name`. ⚠️ verify.
6. **`app:release:publish`** parses version from `build.gradle.kts` by regex — fragile if formatting changes. ⚠️
7. **No server-side step-up for biometrics** — deferred by design (SECURITY.md). Acceptable, but document clearly. ✅ intentional.

### 3.3 Missing / deferred backend capabilities
- Encrypted token storage is client-side only; no backend change needed.
- Biometric step-up is client-side gate only; server remains authoritative ✅.

## 4. Android client audit

### 4.1 Compile blockers (❌)
- `NotificationRetryHandler.kt` L42: `val serializer: Serializer<Map<String, Any?>> = Json.serializer()` — `Json.serializer()` requires a reified type; usage with `Serializer<…>` target is invalid. Also `@Serializer` annotation (line 12) is deprecated/removed in kotlinx-serialization 1.x; should use `@Serializable` or remove. `Log` import missing (`android.util.Log`).
- `DashboardScreen.kt`: compile errors at L168/L248/L522 — likely from `av` reference inside `updateRequired` block where `av` is not in scope (should be `update`), and malformed `.semantics { heading { … } }` nested calls. Also `rememberContext` import (L35) is not a standard Compose API.
- `QuickActions` L522 `)` mismatch — possible brace imbalance.

### 4.2 Security gaps (deferred, ❌ not implemented)
- Tokens stored in plain DataStore/SharedPreferences, not `EncryptedSharedPreferences` (SECURITY.md L26). Commit `1bb7c50` deferred this. **Must implement before production** for a financial admin app.
- Biometric gate (`BiometricHelper` exists but not wired into `MainActivity` unlock-on-open or step-up before the 5 sensitive ops). Deferred.
- `local.properties` / keystore handling: gitignored ✅; release BASE_URL guard ✅.

### 4.3 Runtime correctness
- `AuthInterceptor` refresh flow: uses `runBlocking` on main — acceptable for OkHttp Authenticator. One-retry via `responseCount` ✅.
- `safeApiCall` clears tokens on any 401 ✅, but does not surface the refreshed retry's final error distinctly — minor.
- `registerDevice` sends `app_version = BuildConfig.VERSION_NAME` (string) while doc implies version code; backend stores as-is. ⚠️ verify `DeviceApiAction` accepts string.
- `rechargeReceipt` (raw ResponseBody) not yet in `AdminApiService`? Actually added per plan T3.2 — verify present. ⚠️ check.

### 4.4 UI/UX
- Hardcoded Bengali strings throughout (deferred localization) — acceptable per plan.
- `DashboardScreen` has excessive/incorrect `.semantics { heading { … } }` blocks that break compile and add noise; accessibility misuse (`semanticsIndex`, `annouce` typo).
- `Alignment` referenced but not imported? `Alignment.CenterHorizontally` used L128/L145 — need `import androidx.compose.ui.Alignment`. ⚠️ likely missing.
- Touch targets 48dp ✅ in QuickActions.

### 4.5 Tests
- 4 unit test files: `AdminRepositoryTest`, `ApiParsingTest`, `AuthInterceptorTest`, `AuthFlowIntegrationTest`. `DashboardViewModelTest` added by plan — verify present.
- No instrumented tests for biometric/crypto (deferred).

## 5. Web admin (browser) panel

- `src/Web/Admin/*` actions + Twig templates exist; bulk actions recently fixed (commit `4ee502f`).
- Server-side authorization via `PermissionService::canAccessRoute` ✅.
- CSRF: skipped for bearer, enforced for cookie ✅.
- Financial invariants: uses `LedgerService`/`TopupService`/`AdminWithdrawService` ✅; no bare balance updates seen in audited files.
- `AdminWithdraws` superadmin-only ✅.

## 6. Cross-cutting security

- No secrets in repo (keystore/gitignored) ✅; `google-services.json` gitignored ✅.
- HTTPS-only networking config ✅ (`network_security_config.xml`).
- `data_extraction_rules.xml` / `backup_rules.xml` present ✅.
- Token hygiene: backend strips `password_hash`/`remember_token` ✅.
- Logging: `HttpLoggingInterceptor` BODY level in DEBUG only ✅.

## 7. Documentation

- `docs/ADMIN_ANDROID_API.md` aligned with code per Phase 2 ✅.
- `docs/ADMIN_ANDROID_SETUP.md` / `DEPLOYMENT.md` added ✅.
- README counts updated ✅.
- `ADMIN_ANDROID_SECURITY.md` L26 (encrypted storage) should be marked **not satisfied** until T3.3 lands; L30–31 biometric **not satisfied**.

## 8. Prioritized recommendations

**P0 (block release):**
1. Fix compile errors in `NotificationRetryHandler.kt` and `DashboardScreen.kt` (remove bad accessibility code, fix imports, fix `av` scope).
2. Implement EncryptedSharedPreferences token storage (deferred P0 security).
3. Wire biometric gate or formally descope + update SECURITY.md.

**P1 (quality):**
4. Add missing imports (`Alignment`, `Log`, etc.) and remove unused `rememberContext`.
5. Clean up `.semantics` misuse; accessibility claims should be honest.
6. Add `DashboardViewModelTest` if missing; run full unit suite.

**P2 (hardening):**
7. Sanitize `receipt_name` in Content-Disposition; verify `DeviceApiAction` version field.
8. Make `app:release:publish` version parsing robust.
9. Run `composer test` + `npm run build` (if CSS touched) and update counts.

## 9. Verification commands to run after fixes

```
cd android-admin
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
.\gradlew.bat :app:testDebugUnitTest --no-daemon
.\gradlew.bat :app:assembleDebug --no-daemon
.\gradlew.bat :app:assembleRelease --no-daemon
cd ..
composer test
git diff --check
git status --short --branch
```

## 10. Honest status

- I did **not** run `composer test` or `npm run build` in this pass (test DB / build env not verified). PHP findings above are static.
- Android compile was attempted and **failed**; no unit tests ran.
- Encrypted storage & biometric remain **unimplemented** per git history.
