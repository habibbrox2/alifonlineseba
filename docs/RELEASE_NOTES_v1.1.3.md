# Alif Admin v1.1.3 — Release Notes (draft)

**Target tag:** `v1.1.3` (pending — CI creates the GitHub release from the tag)
**Base:** `v1.1.2` … `main` @ `3b38611`
**Verified by:** GitHub Actions run [41039281481](https://github.com/habibbrox2/alifonlineseba/actions/runs/41039281481) — Lint & Unit Tests ✅, Build Debug APK ✅, Build Release APK ✅

---

## Crash fixes

This release fixes the three screen-crash defects confirmed by the full 18-screen crash audit
(`docs/ADMIN_ANDROID_AUDIT_2026-10-30.md`).

### 1. Dashboard update dialog no longer crashes (P0)

Tapping **আপডেট করুন** could throw `ActivityNotFoundException` and close the app: when the server's
`APP_URL` is unset the API returns a relative path, and a relative `Uri` has no intent handler.
The action now goes through `AppUpdateHelper.downloadAndInstall()` with an application context, which
validates the URL and falls back to the browser instead of crashing — and the download keeps running
after the dialog closes.

### 2. Notification retry queue can no longer hang the app (P0)

A malformed queued entry (missing `id` or `payload`) pinned the retry loop's index, so the drain loop
never advanced — an infinite loop on the path that retries failed push notifications. The index now
steps down before any skip or removal path, so every entry is visited once and the loop always terminates.

### 3. Session-expiry checks no longer crash on real timestamps (P1)

`TokenStorage.timeUntilExpiry()` parsed the backend's `Y-m-d H:i:s` `expires_at` value with
`Instant.parse`, which rejects that format outright — throwing `DateTimeParseException` out of the
session-timeout sheet and auto-logout timing. Both ISO-instant and SQL-datetime shapes are now tried,
and an unreadable timestamp reads as "already expired" instead of throwing.

## Verification

| Check | Result |
|---|---|
| `./gradlew :app:compileDebugKotlin` | ✅ passed |
| `./gradlew testDebugUnitTest` | ✅ passed |
| CI `Lint & Unit Tests` (run 41039281481) | ✅ passed |
| CI `Build Debug APK` / `Build Release APK` | ✅ passed |
| On-device smoke test (login, session expiry, push retry, in-app update) | ⬜ not yet run |

## Upgrade notes

No schema, API-contract, permission, or server changes. Drop-in update from v1.1.2;
backend deployment is not required for this release.

**Full Changelog**: https://github.com/habibbrox2/alifonlineseba/compare/v1.1.2...v1.1.3
