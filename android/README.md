# Alif Tools — Android client (scaffold)

Native Kotlin app per `docs/notification-architecture-audit.md` §6.2: **Option A —
native Android / Kotlin** (`minSdk 24`, Kotlin 2.x, Jetpack Compose, Retrofit/OkHttp,
manual DI, FCM). The contract with the PHP backend is **the JSON API only**; the two
source trees stay independent.

> **Status: scaffold.** The navigation, API layer, session/token handling, deep links
> and push pipeline are wired and compile-ready; the screens are intentionally minimal.
> Building requires Android Studio (Koala+) / JDK 17 — nothing Android-toolchain lives
> in this repo's CI.

## Project layout

```
android/
├── settings.gradle.kts / build.gradle.kts / gradle/libs.versions.toml   pinned catalog
└── app/src/main/java/online/broxlab/aliftools/
    ├── AlifToolsApp.kt / MainActivity.kt     manual DI root + single-activity nav
    ├── data/
    │   ├── AppContainer.kt                 Retrofit client, bearer interceptor,
    │   │                                   one-shot refresh-on-401, session state
    │   └── ApiJson.kt                      shared Json + envelope decode helpers
    ├── push/
    │   ├── DeepLink.kt                     push/link → route (audit §7)
    │   ├── AlifToolsFcmService.kt            data-payload rendering, FLAG_IMMUTABLE
    │   │                                   per-id PendingIntents
    │   └── DeviceRegistrar.kt              WorkManager retry for POST /api/devices
    └── ui/screens/                         login / dashboard / request detail / notifications
```

## API surface consumed (Phase 1 + 1.5 backend)

| Endpoint | Used for |
|---|---|
| `POST /api/auth/login` | `{identifier, password}` → token pair + user |
| `POST /api/auth/refresh` | bearer = refresh token → rotated pair |
| `POST /api/auth/logout` | revoke presented token |
| `GET /api/notifications?page=N` | `{notifications[], total, unread}` |
| `POST /api/notifications/read-all` | mark all read |
| `GET /api/service-requests/{id}` | deep-link target; 404 unless owned by caller |
| `POST /api/devices` | FCM token upsert (idempotent) |
| `GET /api/devices` / `DELETE /api/devices/{id}` | manage own devices |

Every response uses the `App\Service\Api` envelope `{success, message, data, errors}`.

## Deep linking

`AlifToolsFcmService` renders the worker's **data payloads**
(`{event, title, body, link, tx_id}`) and builds a tap `PendingIntent` with
`FLAG_IMMUTABLE` and a per-notification request code, targeting either

- `https://onlinesheba.broxlab.online/requests/{id}` (App Links), or
- `aliftools://requests/{id}` (custom-scheme fallback for the pilot).

`DeepLink` maps both onto the `request/{id}` nav route, which fetches
`GET /api/service-requests/{id}`.

## Enabling FCM in a real build

1. Create a Firebase project, add an Android app with package `online.broxlab.aliftools`.
2. Drop the downloaded `google-services.json` into `android/app/`.
3. Uncomment the `alias(libs.plugins.google.services)` line in `android/app/build.gradle.kts`.
4. Optionally set `FIREBASE_*` env vars server-side (`src/Env.php`) so the worker
   actually sends; FCM queue rows already accumulate regardless of credentials.

The in-app `DeviceRegistrar` no-ops safely when Firebase isn't configured.

## Dev server URL

`debug` builds point at `http://10.0.2.2:8099/` (host loopback from the emulator,
where this repo's dev server runs). `release` points at the canonical HTTPS origin —
required for App Links (audit §7).
