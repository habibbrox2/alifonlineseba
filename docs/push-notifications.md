# Browser Push Notifications — Architecture

> Channel `webpush` (RFC 8030 / VAPID RFC 8292, payload encryption RFC 8291 / RFC 8188),
> multi-device fan-out, a per-account opt-out that both customers and staff can flip,
> and a browser permission prompt on every device.
>
> Branch: `main`. Operational setup lives in [`webpush-runbook.md`](webpush-runbook.md);
> the customer/staff-facing guide is [`push-notifications-bn.md`](push-notifications-bn.md).

---

## 1. What was built, in one picture

```
                     ┌──────────────────────────────────────────┐
  event  ──────────► │ NotificationEvent   channel matrix        │
  (e.g. TOPUP_       │ in_app · fcm · webpush · telegram · …    │
   REQUESTED)        │ + `also_admins`                          │
                     └───────────────────┬──────────────────────┘
                                         │  one queue row per (user, channel)
                                         ▼
                     ┌──────────────────────────────────────────┐
                     │ NotificationManager                     │
                     │  wantsChannel()  ──► pushAllowed()      │
                     │  admin fan-out   ──► pushAllowed()      │
                     └───────────────────┬──────────────────────┘
                                         │  php yii app:notification:work
                                         ▼
                     ┌──────────────────────────────────────────┐
                     │ WebPushChannel::send($userId, $payload)  │
                     │  1. isPushEnabled()?  no  → sent()       │
                     │  2. activeForUser() → every row          │
                     │  3. per row: VAPID sign + encrypt + POST │
                     └───────────────────┬──────────────────────┘
                                         ▼
                     browser tab / phone / laptop  (push service → push-sw.js)
```

**One user, many devices.** A `push_subscription` row is *one browser on one device*, not one
account. An account that logs in on a phone, a laptop and the office PC holds three rows, and
`WebPushChannel::send()` POSTs to each of them. That is the whole reason the fan-out is inside
the channel and not in the notification queue: the queue's unit of work is a *person*, the
channel's unit of work is a *device*.

---

## 2. Files that make it work

| File | Role |
|---|---|
| `src/Notification/Channel/WebPushChannel.php` | Signing, encryption, per-device fan-out |
| `src/Notification/Push/VapidKeys.php` | VAPID key loading, ECDSA JWT (`ES256`), SPKI/DER by hand |
| `src/Repository/PushSubscriptionRepository.php` | Storage + `forUser()` device list + `deactivateAllForUser()` |
| `src/Repository/UserRepository.php` | `pushEnabled()` / `isPushEnabled()` / `setPushEnabled()` |
| `src/Notification/NotificationEvent.php` | `webpush` in the channel matrix of every event |
| `src/Notification/NotificationManager.php` | `pushAllowed()` gate, incl. the admin fan-out arm |
| `src/Notification/TemplateRenderer.php` | `webpush` borrows the `fcm` copy |
| `src/Console/NotificationWorkCommand.php` | `'webpush'` arm of the worker `match` |
| `config/common/di/services.php` | `WebPushChannel` registered next to `FcmChannel` |
| `src/Web/Api/PushApiAction.php` | `GET /api/push/key`, `POST /api/push/subscribe`, `POST /api/push/unsubscribe` |
| `src/Web/Account/ProfileAction.php` | `do=push` handler — the account-wide switch |
| `resources/js/push-subscribe.js` | permission prompt, subscribe, unsubscribe |
| `public/push-sw.js` | the service worker that shows the notification |
| `migrations/M240116000000_AddPushPreferenceToUser.php` | `{{%user}}.push_enabled` |

---

## 3. Why `webpush` sits next to `fcm` in every channel list

`NotificationEvent` (`src/Notification/NotificationEvent.php:58-81`) lists `webpush` for
**every** event, including the `also_admins` ones (`TOPUP_REQUESTED`, `TOPUP_CANCELLED`,
`SERVICE_REQUEST_CREATED`, `SYSTEM_ALERT`).

The temptation is to treat `fcm` as "the push channel" and `webpush` as an extra for people
without the APK. That is backwards. The APK is optional; **the browser is not.** A customer
who never installed the app, and a member of staff who never will, still has to hear about a
new recharge request waiting for review. An event list without `webpush` means that person
watches the `/admin/topups` poll until somebody happens to open the page — which is exactly
the failure the notification system exists to remove.

---

## 4. The browser permission prompt (customer and staff alike)

There is no server-side way to grant a browser permission. The sequence is:

1. The page renders the VAPID **public** key inline (`data-vapid-key`). Public by definition;
   shipping it from the template saves a round trip before the prompt, and the prompt is the
   expensive part.
2. `resources/js/push-subscribe.js` calls `Notification.requestPermission()` **as the first
   awaitable step** of the click handler. Any `await` before it ends the user-gesture window
   in some browsers and the prompt is auto-denied.
3. On `granted`: `navigator.serviceWorker.register('/push-sw.js', {scope: '/'})`, wait for
   `navigator.serviceWorker.ready` (a first-install `subscribe()` throws `NotSupportedError`
   while the worker is still activating), then `pushManager.subscribe()` with
   `userVisibleOnly: true` and the key as `applicationServerKey`.
4. `POST /api/push/subscribe` with `PushSubscription.toJSON()`. Identity is decoration: when
   signed in it is stored on the row (so notifications fan out to the account), when not it
   stays `NULL` (so broadcasts can still reach the tab).

Both roles use **the same page, the same JS, the same endpoint**. Staff are users; an admin
visiting `/app` gets the same card a customer does. There is no separate admin permission
path because there is no separate permission mechanism to wrap.

**The switch on `/profile` is the second gate.** Permission granted ≠ subscribed ≠ opted in.
`NotificationManager::pushAllowed()` requires *all three*: `user.push_enabled = 1`, VAPID keys
present, and an active subscription row.

### Why `PushApiAction` is not behind `ApiAuthMiddleware`

Browser push is meant to reach the person who has *not* installed the app and has no reason to
sign in yet. An unauthenticated write endpoint is not something to hand out lightly, so the two
guards are: **CSRF** (both POSTs run through `CsrfTokenMiddleware`, token already in the page
as `<meta name="_csrf">`) and **input validation** (scheme must be `https://`, `p256dh` must
decode to exactly 65 bytes, `auth` to exactly 16 — a row edited to `http://…` would turn a
stored subscription into a server-side request to a host of the row author's choosing, so the
scheme is checked at the only point where a caller can introduce one).

---

## 5. Multi-device fan-out

```php
// src/Notification/Channel/WebPushChannel.php
foreach ($this->subscriptions->activeForUser($userId) as $subscription) {
    $results[] = $this->sendToSubscription($subscription, $payload);
}
```

* **A failure on one device does not affect another.** `sendToSubscription()` returns a
  `DeliveryResult` per row; a `410 Gone` endpoint is marked inactive, a crypto hiccup is
  `transient` (retried), a bad subscription is `permanent` (dead-lettered). Three devices means
  three independent results, which is why the fan-out is a loop and not a single POST.
* **`is_active = 0` rows are invisible to the fan-out.** This is what makes the opt-out a real
  off switch (below).
* **Order / de-duplication** is left to the push service, which is authoritative for endpoint
  rotation: the browser silently rotates an endpoint under the same origin+SW, and
  `subscribe()` upserts on `endpoint` (`PushSubscriptionRepository::subscribe`). Re-posting
  after a rotation is a repair, not a duplicate — so `initPushSubscribe()` re-posts on every
  page load.

---

## 6. Turning notifications off — and why it is two writes

`ProfileAction::togglePush()` (`src/Web/Account/ProfileAction.php`) on `POST /profile` with
`do=push`:

```php
$this->users->setPushEnabled($identity->id, $on);                 // 1. the flag
$dropped = $on ? 0 : $this->subscriptions->deactivateAllForUser($identity->id);  // 2. the rows
```

**The flag alone is not an off switch.** A browser that still holds a live `PushSubscription`
keeps receiving anything broadcast to "every active device", and appears as "connected"
everywhere else. So the switch also deactivates the rows.

**The flag is checked twice, and the second check is the one that matters.** A queued
notification can sit in the table long after the switch is flipped:

* `NotificationManager::pushAllowed()` — keeps a webpush job from ever being queued.
* `WebPushChannel::send()` — re-checks at send time, which covers everything queued earlier.

`WebPushChannel::send()` returns `DeliveryResult::sent()` when opted out. That is deliberate
and not a lie: the job is *finished*, and reporting it as anything else would push a correctly
suppressed notification into the dead-letter table.

**Staff get the same switch.** `AdminSettingsAction` is site-wide only (`SettingsRepository::KEYS`),
so a per-person setting belongs on `/profile`, where every signed-in user — admin or customer —
already lands. There is no admin-specific notification preference, and adding one would mean two
switches that can disagree.

### `isPushEnabled()` vs `pushEnabled()`

| Method | Behaviour | Used by |
|---|---|---|
| `pushEnabled(int $id): bool` | Raw column read. Missing row / `NULL` → `true` | The settings page |
| `isPushEnabled(int $id): bool` | Wraps the above in `try/catch`, `true` on throw | `WebPushChannel`, `NotificationManager` |

The tolerant read exists for the window between `git pull` and `php yii migrate:up`, where the
column does not exist and every send would otherwise 500 inside the queue worker. **An absent
column reads as "on"**, which is the previous behaviour.

---

## 7. Copy: why `webpush` borrows the `fcm` template

`TemplateRenderer::render()` resolves, in order:

```
DB template for (event, channel)  →  seeded template for (event, channel)
                                  →  seeded 'fcm' template        [webpush only]
                                  →  seeded 'in_app' template    [final fallback]
```

The same sentence reaching a phone through FCM and a laptop through Web Push is a feature, not
duplication — it is what lets an admin read both channels side by side and recognise the
message as one. The borrow lives in the renderer rather than in a `MessageTemplates` alias
because an alias would be invisible to the template editor, and the editor is the one place
that would then silently leave `webpush` behind.

---

## 8. The crypto, briefly

* **VAPID JWT** — ES256 over the P-256 key pair, `aud` = endpoint origin, `exp` ≤ 24 h.
  `VapidKeys` hand-builds the SPKI DER and the SEC 1 `EC PRIVATE KEY` wrapper because PHP's
  OpenSSL binding is unreliable across the shared-hosting matrix here.
* **Body encryption** — `aes128gcm` content encoding: an ephemeral P-256 key pair per message,
  `ecdh` shared secret via `openssl_pkey_derive`, HKDF-SHA256 to split into salt/auth secret/
  CEK/nonce, payload truncated to 3993 bytes.
* **Why the ephemeral key is generated by `openssl ec` and not `openssl_pkey_new()`** — on this
  host `openssl_pkey_new()` returns `false` (no config file), and a *failed* key silently yields
  an `openssl_pkey_get_details()['ec']` array with no `x`/`y`, which produces a subscription that
  fails at the push service rather than at the sender. `generateEphemeralKey()` therefore returns
  `array{key, point}` and falls back to a `proc_open` call against the `openssl` CLI, which does
  work. See the runbook for the exact symptom list.

---

## 9. Diagnostics

```bash
php yii app:webpush:check                      # keys, audience, crypto readiness
php yii app:webpush:check --user=12            # + one real push to that account's devices
php yii app:webpush:check --endpoint=https://… # + one push to a raw subscription
```

Modelled on `app:fcm:check` (`src/Console/FcmCheckCommand.php`). Full output explanation and the
failure-to-symptom table are in [`webpush-runbook.md`](webpush-runbook.md).

---

## 10. Tests

`tests/Unit/WebPushEncryptionTest.php` decrypts a real `WebPushChannel` ciphertext with an
**independent** RFC 8291/8188 decryptor written from the spec, rather than asserting against the
producer's own helpers — a shared helper that is wrong in both directions passes its own test.

Covered: key derivation, HKDF key split, `aes128gcm` header layout, plaintext truncation at
3993 bytes, the auth-secret length guard, and the payload encoding.

---

## 11. Status

| Piece | State |
|---|---|
| Channel, crypto, queue arm, DI | done, unit-tested |
| Multi-device fan-out | done |
| Permission prompt (`/app` card) | done |
| `push_enabled` column + repositories | done, migration written |
| Admin fan-out arm (`also_admins` events) | done |
| `do=push` handler in `ProfileAction` | done |
| `/profile` push section + `initPushSettings()` | done, rendered in 4 states |
| `app:webpush:check` | done (`--user`, `--endpoint`) |
| Opt-out gates (`send()`, queue, admin arm) | done, `tests/Functional/WebPushOptOutTest.php` |
| Migration applied to the live database | done (`M240116000000_AddPushPreferenceToUser`) |

---

## 12. Security notes

* The VAPID **private key never leaves the server** and is not in this document. Only
  `VAPID_SUBJECT` and the derived public key belong in a repo.
* Push endpoints and subscription keys are secrets-adjacent: an endpoint is a live URL that
  accepts exactly one body's worth of push. They are stored, never rendered.
* `push_enabled` is per-account, so an admin cannot silently mute a customer's notifications and
  cannot silently unmute their own without the switch being visible in their own settings.
