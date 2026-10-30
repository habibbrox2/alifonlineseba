# Alif Tools Admin Android â€” Security Plan

Threat model, controls, and verification for the **Alif Tools Admin** app (`com.aliftools.admin`). Principle: **the server is the only authority** â€” the Android app is an untrusted client (spec Â§28: no permission enforcement only on Android-side).

---

## 1. Trust boundary

```text
[Untrusted]  Android device (rooted/jailbroken possible, APK extractable)
      â”‚  HTTPS + Bearer token
      â–¼
[Trusted]    Yii 3 backend  â”€â”€  AdminMiddleware + per-action role checks
      â”‚
      â–¼
[Trusted]    MySQL/MariaDB (Android NEVER touches this)
```

The app holds no secrets that would let an attacker re-derive authority. If the device is compromised, the blast radius is one admin account's token, which is revocable and re-validated against the live user row on every request (`ApiTokenRepository::resolve()` re-reads the user; disabled/deleted accounts stop working immediately).

## 2. Authentication controls

| Control | Implementation |
|---|---|
| Credential entry | Username/phone + password over HTTPS; throttled per identifier server-side (`AuthThrottle`: 5 attempts / 300 s) â€” the app displays the server's error verbatim |
| Token storage | `EncryptedSharedPreferences` (AndroidX Security Crypto), keys in Android Keystore; access + refresh tokens only â€” **password is never stored, in any form** |
| Token lifetime | Access 30 d, refresh 60 d (`API_TOKEN_TTL_DAYS`); refresh rotation revokes the used refresh token |
| 401 recovery | One refresh attempt â†’ one retry â†’ clear store â†’ Login (prevents infinite loops and token-replay storms) |
| Logout | `POST /api/auth/logout` revokes the presented token server-side |
| Biometric unlock | `BiometricPrompt` gates token decryption on app open (user opt-in); crypto object tied to the Keystore key so the token cannot be decrypted without biometric/PIN |
| Sensitive-operation re-auth | Biometric re-prompt before: withdrawal approve/reject, user delete, role change, settings save, bulk settle (client-side gate; server remains the authority) |

## 3. Authorization controls

- **Server-side, existing:** `AdminMiddleware` admits only role âˆˆ {admin, staff, superadmin}; `AdminWithdrawsApiAction` and `AdminSettingsApiAction` each re-check `isSuperAdmin()` â†’ 403, while `AdminStaffApiAction` gates on `PermissionService::canAccessRoute('admin-staff'/'admin-staff-password-reset')` (which admin/staff/superadmin with `staff.read` pass). The Android UI hides superadmin screens for non-superadmins purely as UX; forcing the call still returns 403.

- **Superadmin creation is CLI-only** (`php yii app:super-admin`) â€” no API path exists or will be added.
- **Self-approval of withdrawals** is refused inside `AdminWithdrawService::approve()` (reviewer id === admin id) â€” enforced server-side regardless of client.
- **Idempotency gates** prevent double-pay: `markReviewed` requires `reviewed_by IS NULL AND status IN (pending,review)`; order settle credits are written once inside guarded UPDATEs (`approved_by` marker).

## 4. Data-in-transit

- `android:usesCleartextTraffic="false"` + a network-security-config that permits only the configured base host over TLS.
- OkHttp with TLS enforced; no user-configurable proxy bypass inside the app.
- Base URL comes from `BuildConfig`/local properties â€” never from a string resource that ships a secret, never from user input.

## 5. Data-at-rest

- No business data is mirrored to a local database (no Room) â€” the server is the single source of truth; this also eliminates stale/fake data risk (spec requirement).
- Dashboard may cache the last successful response for offline *read-only* display, clearly labeled "cached"; **no cached writes**.
- Logs: the app never logs tokens, passwords, refresh tokens, or PII; release builds strip debug logging (R8).

## 6. Secrets management

- **In the APK: nothing.** No APP_KEY, no Firebase service-account JSON, no DB credentials, no admin master secret. FCM client SDK uses only the project identifier (public, not a secret).
- **Server-side (unchanged):** `APP_KEY` (token pepper), `FIREBASE_CREDENTIALS_PATH` (service-account JSON outside the web root), `VAPID_*` â€” all env-driven per `.env.example`.
- **Signing:** release keystore lives in `keystore.properties` (gitignored) or `ADMIN_STORE_*` env vars â€” the same pattern as the existing `twa/` module. The keystore never enters the repo.

## 7. Server-side audit (existing, backend-authoritative)

Every privileged flow already writes `activity_log` via `ActivityLogRepository::create()` â€” `auth.login`, `auth.api_token_issued`, `topup.approved/rejected`, `withdraw.approved/rejected`, `admin.super_denied` (with ip/ua/path), bulk-settle summaries, settings `updated_by`. The Android app adds **no** client-side audit trail and cannot forge one. The admin can review it in-app via `GET /api/admin/logs`.

## 8. Push security

- FCM tokens are registered per user id (`POST /api/devices`); `FcmChannel` auto-deactivates dead tokens (404/400) and retries transient errors (5xx/429).
- Notification payloads carry `event` + `url` (data payload); the app deep-links only to its own internal route map â€” the URL is matched against a whitelist of known admin route prefixes before navigation (no arbitrary URL opening).
- NotificationManager dedupes via `dedupe_key` (sha1 of event|user|channel|entity), so replayed business actions cannot spam a device.

## 9. Release build hardening

- R8 full mode for release; ProGuard rules kept for Retrofit/Moshi/Gson models.
- `debug` builds: no release signing, no cached production credentials, separate `BuildConfig` base URL.
- `versionCode` monotonic; `min_version_code` semantics respected when publishing to `app_release` (an unpublished or mismatched release must not brick either app).
- Dependency pinning + `./gradlew dependencies` review before release; no snapshot/alpha dependencies.

## 10. Security verification checklist (Phase 11â€“12)

- [ ] APK contains no secrets: `strings`/`apktool` sweep for key material, `APP_KEY`, credentials
- [ ] Cleartext traffic blocked (network-security-config test)
- [ ] Token absent from logcat in release builds
- [ ] 401 â†’ refresh â†’ retry path exercised in unit tests (including refresh-failure â†’ logout)
- [ ] Non-superadmin forced withdraw-approve call returns 403 (backend functional test)
- [ ] Double-approve idempotency test (second call no-ops, no double refund/payout)
- [ ] Reject-without-reason rejected 400
- [ ] Biometric gate cannot be bypassed on a rooted emulator without PIN (Keystore-bound key)
- [ ] Deep-link whitelist rejects unknown URLs
- [ ] Logout revokes the token server-side (subsequent API call â†’ 401)

## 11. Known limitations (accepted, documented)

1. **30-day access tokens** are long-lived by design (`API_TOKEN_TTL_DAYS`); compensating controls: revocation, live-row validation, refresh rotation, encrypted storage. Shortening the TTL is a one-line env change if operations requires it.
2. **Biometric re-auth is client-side** for v1; a server-issued step-up challenge (e.g. re-enter password for withdraw approval) is a possible future backend addition â€” flagged as an open decision.
3. **Staff role breadth:** staff can view users/logs by design (the queue desk role). If a narrower "operator" role is ever needed, that is a backend role-model change â€” out of scope, and the spec forbids inventing roles.


## Post-launch (deferred) security work

- Encrypted token storage and on-device Biometric gating are intentionally deferred from this launch. Until they ship, access/refresh tokens rest in Android DataStore (protobuf-free Preferences), which is **plaintext on disk** — not password-protected. Treat device theft as equivalent to token theft until the patch lands.
- **Mitigations in effect today:** tokens live only inside that app's DataStore; server-side revocation works through the live-row check in ApiTokenRepository::resolve(); password reset disables tokens. Behaviour is documented as an open exclusion in docs/ADMIN_ANDROID_ARCHITECTURE.md (Open Decisions / §3.1.3 threats).
- **Patch plan:** EncryptedSharedPreferences (AndroidX Security Crypto) + MasterKey in the Android Keystore, a one-shot plaintext-to-ciphertext migration on first launch, and a one-time logout on upgrade (cutover note in docs/ADMIN_ANDROID_DEPLOYMENT.md). This is a separate security follow-up — not part of the v1 contract.

## 12. Backend hardening applied

- Refresh-token rotation locks the presented token row with `SELECT … FOR UPDATE`, checks the owning account inside the same transaction, conditionally revokes exactly one row, and issues the replacement pair before commit. Replays cannot mint another pair.
- `app:seed` no longer contains or prints default administrator/demo passwords. Production seed never creates users; the first super-admin is created through `php yii app:super-admin`, which generates a one-time password. Optional demo accounts require explicit non-production `SEED_ADMIN_PASSWORD` and `SEED_DEMO_PASSWORD` values (12+ characters each).
- Admin API write actions enforce the permission matrix at the action boundary; unknown action names fail closed. Staff cannot reject or bulk-settle orders, mutate services/categories, or retry/delete failed notifications.
