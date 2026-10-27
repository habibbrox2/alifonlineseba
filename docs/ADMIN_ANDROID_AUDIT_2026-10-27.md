# Admin Android Audit Report — 2026-10-27

Repository: `habibbrox2/alifonlineseba`
Audited baseline: `main` at `d9050c0ce72cca0c9377969b3359ffb34768a54d`
Scope: Native Admin Android source, Gradle configuration, API contract, and recent GitHub Actions build evidence.

## Executive summary

The current Admin Android branch is not build-verified. The latest recorded CI run for baseline `d9050c0` failed during Kotlin compilation, so neither APK build job ran. Two earlier Android Admin workflow runs also failed in the same validation job.

## Confirmed findings

### P0 — Kotlin compilation failure blocks all APK builds

Evidence: GitHub Actions run [40381895777](https://github.com/habibbrox2/alifonlineseba/actions/runs/40381895777), job `Lint & Unit Tests`, step `Run unit tests`.

The log reports Kotlin type-inference failures in:
- `android-admin/app/src/main/java/com/aliftools/admin/ui/viewmodel/CategoriesViewModel.kt:32`
- `android-admin/app/src/main/java/com/aliftools/admin/ui/viewmodel/ServicesViewModel.kt:32`

The failures occur in the `Result.onFailure` chain after the preceding success handler. Explicitly typing the result and fold lambdas removes ambiguity; loading state is also reset in a `finally` block.

### P1 — CI calls Gradle tasks that are not configured

Evidence: the same run reports:
- `Task 'ktlintCheck' not found in root project 'alif-tools-admin'`
- `Task 'detekt' not found in root project 'alif-tools-admin'`

Neither plugin/task is configured in the Android Admin Gradle build. Both steps had `continue-on-error: true`, obscuring the unsupported-task failure while the later unit-test step still failed. The workflow now uses Android Gradle Plugin's `lintDebug` task instead.

### P2 — Refresh-token API documentation is inconsistent with implementation

Evidence:
- `src/Web/Api/AuthApiAction.php` reads the refresh token from the Authorization Bearer header.
- Android's `AuthApiService.refresh()` also sends `Authorization: Bearer <refresh token>`.
- The old `docs/ADMIN_ANDROID_API.md` text incorrectly described a JSON body.

The API documentation was aligned with the server and Android client.

## Changes made on isolated fix branch

Branch: `fix/admin-android-audit-2026-10-27`
Backup checkpoint branch: `backup/admin-android-before-fix-2026-10-27`

- `CategoriesViewModel.kt`: explicit `Result<Map<String, Any?>>`, explicit lambda types, guaranteed loading reset.
- `ServicesViewModel.kt`: same targeted compilation fix and loading-state cleanup.
- `.github/workflows/android-admin-build.yml`: replace unsupported lint tasks with `lintDebug`; allow the debug APK job to run for pull-request validation, while release builds remain excluded from pull requests.
- `docs/ADMIN_ANDROID_API.md`: correct refresh-token transport documentation.

## Validation status

- Baseline: **failed**. Kotlin compilation failed; debug/release APK jobs were skipped.
- Fix branch: pull-request CI was triggered to run Android lint, unit tests, and a debug APK build. Results must be checked in [PR #1](https://github.com/habibbrox2/alifonlineseba/pull/1) and its [Actions runs](https://github.com/habibbrox2/alifonlineseba/actions).
- Physical-device install, login against the deployed backend, notification delivery, and end-to-end role-specific workflows have not been verified by this source/CI audit.

## Release safety

No production deployment, APK release publication, or merge into `main` was performed. Do not treat the APK as production-ready until CI passes and a real-device smoke test confirms login, token refresh, dashboard loading, role restrictions, and critical admin actions.
