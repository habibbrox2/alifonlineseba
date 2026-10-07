# Alif Tools Admin Android — API Contract

Base URL: `https://<host>` (from `BuildConfig`/local properties — never hardcoded, never a secret).
Transport: HTTPS only. Auth: `Authorization: Bearer <selector>.<verifier>` (except the login endpoint).

## Envelope (every response — `src/Service/Api.php`)

```json
{ "success": true, "message": "...", "data": { } }
```

```json
{ "success": false, "message": "...", "errors": { }, "data": { } }
```

> **Client quirk:** `Api::fail()` puts `$errors` in **both** `data` and `errors`. Read `errors`.

**Status codes:** 200 ok · 400 validation/business failure · 401 `Api::unauthorized()` (invalid/expired presented token — no session fallback) · 403 `Api::forbidden()` (role check failed) · 404 route.

---

## 1. Auth — `POST /api/auth/{action}` (`src/Web/Api/AuthApiAction.php`)

### `POST /api/auth/login`
Request: `{ "identifier": "username or phone", "password": "..." }`
Response 200:
```json
{
  "success": true,
  "data": {
    "token": "<selector>.<verifier>",
    "refresh": "<selector>.<verifier>",
    "expires_at": "datetime",
    "user": { "id": 1, "username": "admin", "phone": "...", "balance": "5000.00", "role": "admin" }
  }
}
```
Errors (string message): unknown identifier · deleted account · wrong password · disabled account. Throttled per identifier (5 attempts / 300 s, `AuthThrottle`). Logs `auth.api_token_issued`.

### `POST /api/auth/refresh`
Request: `{ "refresh": "<refresh token>" }` → rotates: the presented refresh token is revoked, a new pair is returned (same shape as login).

### `POST /api/auth/logout`
Request: `Authorization: Bearer <access token>` → revokes the presented token.

**Android handling:** on 401 from any API call → attempt one refresh → retry the original request once → if refresh fails, clear the secure store and return to Login.

---

## 2. Admin API — group `AdminMiddleware` (`config/common/routes.php`)

All require `canAccessAdmin()` (role ∈ admin/staff/superadmin). Individual actions re-check (noted below).

### `GET /api/admin/dashboard` — `AdminDashboardApiAction`
```json
{ "userCount": 0, "categoryCount": 0, "serviceCount": 0,
  "txStats": {}, "openOrders": 0, "recentLogs": [ ...8 rows ],
  "topupStats": {}, "unresolvedCount": 0, "unresolvedAmount": "0.00" }
```
All values are live database aggregates — no client-side computation.

### `GET /api/admin/users` — `AdminUsersApiAction`
Query: `page` (default 1), `q` (searches full_name/username/phone/email), `sort` ∈ `UserRepository::SORTABLE` [id, full_name, username, balance, role, status, last_login_at, created_at], `dir` (asc/desc), `trashed` (0/1).
Response: `{ rows: [...], total, page, perPage: 15, trashedCount }`.

### `POST /api/admin/users` — same action
Body field `do`:
- `create` — `{username: /^[a-zA-Z0-9._-]{3,32}$/, phone: /^01[3-9][0-9]{8}$/, password: ≥8 chars, role: user|staff|admin}` (superadmin is NOT creatable here — CLI only).
- `toggle` — `{id}` — flips status active/disabled.
- `role` — `{id, role: user|staff|admin}`.
- `reset` — `{id}` → returns one-time `temporary_password` (14 chars, unambiguous alphabet). Show it once; force the user to copy it.
- `delete` — `{id}` — soft delete; **server forbids** self-delete and deleting role=admin rows.
- `restore` — `{id}` · `restore_all`.

### `GET /api/admin/orders` — `AdminOrdersApiAction`
Query: `page`, `scope` ∈ all|mine|taken, `sort` ∈ `ServiceOrderRepository::SORTABLE`, `dir`, `q`.
Response: `{ rows: [...adminList with claimedBy...], total, page, perPage: 20, openOrders: [...] }`.

### `POST /api/admin/orders` — bulk settle
Body: `{ do: "bulk_status", ids: [int], status }` → `BulkJobService::enqueueSettle` (validated against `StatusPresenter::isRequestStatus`, capped at `ServiceRequestAdminService::BULK_LIMIT`, chunked at `BULK_CHUNK_SIZE`=25). Returns job acceptance; **settle is async** — poll `GET /api/admin/orders` for progress. The job runs with the requester's rebuilt identity; refunds/notifications/audit logs are handled inside the service.

### `GET /api/admin/topups` — `AdminTopupsApiAction`
Query: `page`, `status` ∈ pending|review|approved|rejected, `q` (username/phone/reference/sender/receipt). Unresolved rows (review before pending) float to the top. `perPage: 20`.

### `GET /api/admin/recharges/{id}` — `AdminRechargesApiAction`
Returns the topup row.

### `POST /api/admin/recharges/{id}`
Body: `{ do: "approve" }` → `TopupService::approve` (idempotent via `OPEN_STATUSES` gate; credits ledger first, then stamps the row; dispatches TOPUP_APPROVED; referral bonus side-effects are no-op-safe).
Body: `{ do: "reject", reason: "<non-empty>" }` → `TopupService::reject` (reason is shown to the user; dispatches TOPUP_REJECTED).

### `GET /api/admin/withdraws` — `AdminWithdrawsApiAction` (**superadmin only** — 403 otherwise)
Query: `page`, `status` ∈ pending|review|approved|rejected, `q`.
Response: `{ rows: [...adminList with admin_balance...], total, page, perPage: 20, stats }` — `stats` from `AdminWithdrawRepository::stats()`.

### `POST /api/admin/withdraws/{id}` — **NEW (Phase 3)** — superadmin only
Body:
```json
{ "do": "approve" }
```
```json
{ "do": "reject", "reason": "non-empty reason (shown to the admin)" }
```
Behavior (delegates to `src/Service/AdminWithdrawService.php`):
- `approve` — refuses self-approval (`reviewer->id === admin_id`); idempotent (`reviewed_by IS NULL AND status IN (pending,review)` gate — a second approve is a safe no-op); dispatches `ADMIN_WITHDRAW_APPROVED`; logs `withdraw.approved`. Money was **held at request time** (balance debited on creation), so approval records the payout decision.
- `reject` — requires non-empty `reason`; refunds the admin via `LedgerService::creditAdmin` (TYPE_WITHDRAW_REFUND) **before** stamping the review, so a failed reject cannot lose the refund; dispatches `ADMIN_WITHDRAW_REJECTED`.
Response: `{ success: true, data: { ...fresh withdrawal row via findById... } }`.
Errors: 403 non-superadmin · 400 business refusal (self-approval, already reviewed, missing reason) with message.

### `GET /api/admin/staff` — `AdminStaffApiAction` (**superadmin only**)
Query: `page`, `q`. `perPage: 20`. Superadmin listed first (`paginateStaff`).

### `GET /api/admin/settings` — `AdminSettingsApiAction` (**superadmin only**)
Returns `SettingsRepository::all()` — defaults merged with `site_setting` rows. Keys are whitelisted (`SettingsRepository::KEYS`) with types text|url|multiline|number|checkbox, e.g. `site_tagline`, `contact_email`, `topup_min_amount` (10), `topup_max_amount` (100000), `topup_receipt_required` (checkbox), `wallet_bkash/nagad/rocket`, `referral_enabled`, `referrer_bonus_amount`, `order_window_enabled/start/end`, `maintenance_enabled` (checkbox) + `maintenance_message`.

### `POST /api/admin/settings`
Body: whitelisted keys only. Server validates: URL keys via `isSafeUrl` (http/https), email/phone formats, topup min/max > 0, referral bonuses 0–100000, `min ≤ max` thresholds, `maintenance_message` ≤ 300 chars; checkbox keys handled explicitly; unsafe URLs silently dropped. `putMany(body, identity->id)` records `updated_by`.

`maintenance_enabled = 1` closes the public site (see `App\Web\MaintenanceMiddleware`): every non-admin path answers 503 with `maintenance_message` as JSON, while `/admin` and `/api/admin/*` — this endpoint included — stay reachable, so the app can always switch it back off. Unchecking it (or omitting the key, as a form does) reopens the site.

### `GET /api/admin/logs` — `AdminLogsApiAction`
Query: `page`, `perPage` (≤25), `q` (searches action/description/ip_address). Response: `{ rows, total, page, perPage, q }` from `ActivityLogRepository::all()` (newest first).

### `GET /api/admin/notifications` — `AdminNotificationsApiAction`
Query: `page`, `status` ∈ queued|processing|sent|dead. Response: `{ rows, total, page, perPage: 20, stats: QueueRepository::stats() }`.

### `POST /api/admin/notifications/{id}/retry`
Requeues a **dead-letter** row only (`QueueRepository::requeue`).

---

## 3. Device / FCM API (`ApiAuthMiddleware`: bearer OR session)

### `POST /api/devices` — `DeviceApiAction`
`{ device_token, platform: android|ios (default android), device_name, app_version }` → idempotent `DeviceRepository::upsert` (a token moving devices re-points the owner). Call from `FirebaseMessagingService.onNewToken` and on login.

### `GET /api/devices` — the user's registered devices.
### `DELETE /api/devices/{id}` — remove a device registration.

Admin-targeted push requires **no new backend**: `NotificationManager` already fans out staff/admin events to each staff-role user's active device tokens; the admin app registers its token under the admin's own user id.

## 4. Update check — `GET /api/app/version` (`AppVersionApiAction`)
Unauthenticated on purpose. Returns the newest **published** `app_release` row (`version_code`, `version_name`, `min_version_code`, `release_notes`, apk path). `minVersionCode()` returns 0 when nothing is published (an unpublished release must not brick old apps). The APK bytes are served from outside the web root via `GET /app/apk`.

> **Distribution note:** if the admin APK shares the `app_release` table, publish it as a distinct row and verify `min_version_code` semantics so neither app is forced to update into the other's APK. Recommended: separate distribution channel/endpoint for the admin app (open decision in the architecture doc).

## 5. Web push (not needed by the native admin app, listed for completeness)
`GET /api/push/key` (VAPID public key), `POST /api/push/subscribe|unsubscribe` (OptionalAuthMiddleware) — browser push only.

---

## Android error-handling matrix

| HTTP | Meaning | App behavior |
|---|---|---|
| 200 | success | render `data` |
| 400 | business/validation failure | show `message` (+ `errors` map on fields) |
| 401 | token invalid/expired/revoked | refresh once → retry → else logout to Login |
| 403 | role insufficient | show "superadmin required" notice; hide the screen |
| 404 | unknown route/row | error state with retry |
| no network | — | offline state, no writes queued |
