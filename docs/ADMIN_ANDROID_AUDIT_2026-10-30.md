# Admin Android Audit Report — 2026-10-30

Repository: `habibbrox2/alifonlineseba`
Audited baseline: `main` at `a36a9cd1cb9f0d654a14dd2043ff5a067e1384e0`
Scope: All 18 Admin Android screens (`ui/screens/*`) plus shared crash-prone infrastructure — navigation, scaffold, paged list, design components, ViewModels, repository, interceptors, token/session storage, notification retry queue, FCM service and update helper.
Method: parallel sub-agent static audit per screen group, consolidated and verified against source, then fix + build verification.

## Executive summary

All 18 screens and the shared infrastructure were audited for crash-class defects (unguarded framework calls, unbounded `LazyColumn` in `Column`, index/lifecycle bugs, unparseable timestamps). Three confirmed crash defects were found; all three are fixed in the working tree. The app compiles and the unit-test suite passes after the fixes.

## Confirmed findings

### P0 — Dashboard update dialog crashes with `ActivityNotFoundException`

Evidence: `DashboardScreen.kt` update-offer dialog built `Intent(ACTION_VIEW, Uri.parse(offer.downloadUrl))` and called `startActivity` directly.

The API returns a relative path whenever `APP_URL` is unset on the server, and `Uri.parse` on a relative string yields an intent with no matching handler. Tapping "আপডেট করুন" threw `ActivityNotFoundException` and crashed the app. The dialog is shown proactively on launch, so any stale/relative update URL reproduced it.

Fix: the click now delegates to `AppUpdateHelper.downloadAndInstall(...)` with `context.applicationContext` (the download outlives the dialog, and the receiver must not be pinned to a finishing Activity), which validates the URL and falls back to the browser instead of throwing.

### P0 — `NotificationRetryHandler` retry loop could spin forever

Evidence: `NotificationRetryHandler.kt` `retryPending()` decremented `i` only at the end of the loop body, while several `continue` paths (`id`/`payload` missing) and the removal branches skipped it.

A queue entry without a numeric `id` or `payload` pinned `i` at the same index, so the `while (i >= 0)` loop never advanced — an ANR-grade infinite loop on the main path that drains the retry queue after a push failure.

Fix: the index is captured and decremented at the top of the body before any `continue`, and all `removeAt`/`set` calls use the captured index.

### P1 — `TokenStorage.timeUntilExpiry()` throws `DateTimeParseException` on real API timestamps

Evidence: `TokenStorage.kt` parsed `expires_at` with `java.time.Instant.parse(...)`, but the backend stamps it as `Y-m-d H:i:s` (no ISO-8601 offset), which `Instant.parse` rejects outright.

Any session-expiry check (`isTokenExpiringSoon`, session timeout sheet, auto-logout timing) threw an uncaught `DateTimeParseException` out of a composable/ViewModel path and crashed the screen.

Fix: `parseExpiry` tries ISO instant first, then `yyyy-MM-dd HH:mm:ss` in the system zone, and returns `null` on both failures — an unreadable timestamp now reads as "already expired" (`-1L`) instead of throwing.

## Non-crash issues noted during the audit (not changed)

- `LazyColumn`-inside-`Column` sizing is consistently handled via `weight(1f)`/`PagedListView` across screens — no remaining instance of the historical unbounded-height bug.
- Settings/Profile data contracts are validated against the PHP backend (`src/Web/Api/Admin/*`) and match; no crash shape found.
- Hardcoded Bengali strings remain (localization deferred, per the 2026-10-27 report).

## Changes made (working tree, uncommitted)

- `android-admin/app/src/main/java/com/aliftools/admin/ui/screens/dashboard/DashboardScreen.kt` — safe update download/install path, app context for the receiver.
- `android-admin/app/src/main/java/com/aliftools/admin/data/local/NotificationRetryHandler.kt` — index decrement before `continue`/removal paths.
- `android-admin/app/src/main/java/com/aliftools/admin/data/local/TokenStorage.kt` — tolerant `expires_at` parsing, never throws.

## Validation status

- `./gradlew :app:compileDebugKotlin` — **passed** after fixes.
- `./gradlew testDebugUnitTest` — **passed** after fixes.
- `git diff --check` — clean (no whitespace errors).
- Not verified by this audit: on-device install, real push delivery/retry, and the update dialog against a server with `APP_URL` unset.

## Release safety

No commit, push, merge, or APK release was performed; the three fixes remain in the working tree. Do not treat the APK as production-ready until CI passes and a device smoke test confirms login, session-expiry handling, notification retry, and the in-app update flow.
