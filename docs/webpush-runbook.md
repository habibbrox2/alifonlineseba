# Web Push Runbook (Operations)

Deploying, verifying and debugging the `webpush` channel on shared hosting.
Feature design: [`push-notifications.md`](push-notifications.md) ·
End-user guide: [`push-notifications-bn.md`](push-notifications-bn.md).

---

## 1. Requirements

| Requirement | Why |
|---|---|
| HTTPS on the site origin | The Push API, service workers and `crypto.subtle` are all secure-context-only. `http://127.0.0.1` counts as secure; `http://your-domain.tld` does not. |
| PHP 8.2+ with `openssl`, `pdo_mysql`, `mbstring` | ECDH (`openssl_pkey_derive`), AES-128-GCM, the DB |
| `proc_open` allowed | The ephemeral-key fallback shells out to the `openssl` CLI (see §7) |
| A VAPID key pair | Generated with `web-push`/`openssl` — never by hand |

Push services used in practice: **Mozilla autopush** (Firefox), **Google FCM** (Chrome/Edge),
**Apple APNs Web** (Safari 16.4+, requires an *installed* PWA on iOS).

---

## 2. Environment

In `.env`:

```dotenv
# Contact address the push services use to reach the operator about a key
# that starts failing. Must be mailto: or https:// — a bare address is rejected.
VAPID_SUBJECT=mailto:support@example.com

# Base64url **PKCS#8 / SEC1 EC PRIVATE KEY** for P-256. One line, no newlines.
VAPID_PRIVATE_KEY=w-GRzoEuqzJjxFKzLKwUNYOprFNon1RzAG98rVlVmVI
```

`.env.example` carries the same two keys with empty values. **The private key is a secret** —
it is the identity every browser trusts. It belongs on the server and in nothing else: not in
git, not in a doc, not in a screenshot.

### Generating a key pair

```bash
# Node
npx web-push generate-vapid-keys

# Or OpenSSL: 32 random bytes, forced into [1, n-1]
openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem
```

`VapidKeys::fromPrivateKey()` accepts PKCS#8 or SEC 1 PEM, with or without `BEGIN/END` lines,
and tolerates literal `\n` sequences — because a single-line `.env` value is otherwise
impossible to paste.

### The public key

`VapidKeys::publicKey()` derives it; it is **not** an env var. If you need to hardcode it
somewhere (Android app, docs), take it from `GET /api/push/key`.

### Absent keys are not an error

`VapidKeys::fromEnv()` returns `null`, `credentialsError()` returns a human string, and the
`/app` card renders as "not configured" instead of a broken button. `NotificationManager::pushAllowed()`
also requires keys, so no job is queued and nothing fails. A deployment without VAPID is a valid
deployment that simply does not push.

The site-wide prompt stays out of the way too, and it does so through the same missing key rather
than through a special case: the layout renders `content=""`, and `shouldAsk()` treats an empty key
as "never ask" — so there is no bar, no click that does nothing, and no 503 from
`POST /api/push/subscribe` to debug. See §4.

---

## 3. Migration

```bash
php yii migrate:up --no-interaction
```

* `M240114000000_CreatePushSubscriptionAndAppRelease` — the `{{%push_subscription}}` table
  (`endpoint` unique, `p256dh`, `auth`, `user_id` nullable, `user_agent`, `is_active`,
  `last_seen_at`).
* `M240116000000_AddPushPreferenceToUser` — `{{%user}}.push_enabled`, `NOT NULL DEFAULT TRUE`,
  revertible.

`isPushEnabled()` survives a database the second migration has not reached yet (missing column →
`true`), so the window between `git pull` and `migrate` does not 500 the queue worker. Run the
migration anyway; the tolerant read is a courtesy, not a plan.

### Housekeeping

Dead endpoints accumulate. A weekly cron:

```bash
php yii app:notification:purge
```

It takes no flags — retention is env-driven, because a cron line with a magic number in it is a
number nobody remembers six months later:

| Env | Default | What it controls |
|---|---|---|
| `NOTIFY_RETENTION_DAYS` | `180` | how long a **sent** queue row is kept as the delivery audit trail |
| *(hard-coded)* | `30` | how long a **dead** queue row is kept (nothing can act on it) |
| *(hard-coded)* | `90` | how long a **deactivated device** row is kept |

`PushSubscriptionRepository::purgeInactive(90)` is what drops rows deactivated longer than 90
days, so "I turned this off on my old phone in March" survives long enough to be recognisable
and not forever. Subscriptions a push service retires (404/410) are deactivated by the channel
on the next send, so the window is about audit history, not correctness.

---

## 4. Verification

```bash
php yii app:webpush:check                      # keys + service worker + subscription census, no network
php yii app:webpush:check --user=12            # one real push to every active browser of account 12
php yii app:webpush:check --endpoint='https://…' # one real push to one stored subscription
```

With no options the command walks four stages in order and stops at the first that is broken:

| Stage | Proves | Fails when |
|---|---|---|
| 1. Config | `VAPID_SUBJECT` and the public key are printed; blank subject is called out | keys absent or unparseable |
| 2. Crypto | the pair loads as P-256, so encryption and signing will work | not a matching P-256 pair |
| 3. Service worker | `public/push-sw.js` exists and is non-empty | missing (404 at `/push-sw.js`) or empty |
| 4. Census | how many subscriptions exist, split by account vs anonymous | `{{%push_subscription}}` unreadable — names the migration |

Stage 3 is the one worth calling out: it is the only stage every other check waves through.
Keys load, the table is populated, the push service answers `201 Created` — and the browser still
shows nothing, because there is no worker to hand the message to. An *empty* worker is reported
separately from a missing one, because an empty file parses without complaint and only fails to
ever call `showNotification`, which is what a botched deploy tends to leave behind.

The census is deliberately printed before any send, so "no active subscription" and "the send
failed" stay distinguishable — the two outages people confuse, which need opposite fixes. With
`--user` it also reports that account's own push switch, so an off account is diagnosed as an
opt-out rather than as a delivery failure. With `--endpoint` it sends to exactly one browser,
bypassing the queue.

A successful end-to-end run reports the push service's numeric `Location` id (`Created`), and the
`{{%notification_delivery}}` row lands in `sent` rather than `failed`. To watch the queue
itself, drain it:

```bash
php yii app:notification:work --limit=200      # drain the queue and print each result
```

**A real device test cannot be faked in CI.** The permission prompt is a user gesture. In
Chrome DevTools, *Application → Service Workers → Notifications* lets you inspect the
subscription; it cannot grant permission on your behalf outside a test profile.

`app:webpush:check` tells you whether push *works*. To be told when somebody *starts using* it,
see §5.

### The permission prompt is site-wide

Permission used to be asked for on exactly one page — the `/app` download card. That meant a
person with no reason to install an APK was never asked, and so never heard that their own
order had finished. The offer now rides in the base layout and appears on whichever page the
visitor is actually on.

| Piece | Where | Why there |
|---|---|---|
| `<meta name="vapid-public-key">` | `layouts/base.twig` `<head>` | The key has to reach the module on *every* page. `PushViewInjection` supplies it; an unconfigured deployment renders `content=""` (§2) |
| `import { initPushPrompt } …` | `layouts/base.twig`, inline module | Same treatment as `app-install.js`: kept inline and tiny rather than added to `app.js`, which loads on pages that have no push UI and would then carry a second entry point for nothing |
| `{% include 'partials/push-prompt.twig' %}` | `layouts/base.twig`, after `{% block body %}` | Outside the block on purpose, so it ships on public pages, signed-in pages and `/app` alike |

`shouldAsk()` is exported separately from the DOM wiring so the rule is assertable without a
browser, and so one function both decides and reveals — two answers to one question is how a
prompt ends up rendered on a page that decided not to show it. It returns `false` unless **all**
of these hold:

* `isPushSupported()` — no Push API, no prompt.
* UA does not match `/aliftools/i` — that is the app's own WebView. It has no Push API, and
  advertising notifications it cannot deliver is worse than silence.
* `Notification.permission === 'default'` — granted needs nothing, and denied cannot be
  re-asked programmatically. The browser remembers, and every further attempt is a lie in the UI.
* No `[data-push-root]` or `[data-push-settings]` on the page — a page that already carries a
  push button owns this decision, and two prompts for one decision is a nag. `/app` and
  `/profile` are the two that ship those markers today.
* A non-empty VAPID key.
* `isDismissed()` is false. The X button stamps `aliftools.push.prompt.dismissed` and the bar
  stays gone for `DISMISS_DAYS = 14`; nagging is how a prompt becomes something people click
  past without reading.

**The module never calls `Notification.requestPermission()` on load.** Firefox requires a user
gesture and Chrome's prompt is modal on some builds; a permission box that appears because
someone *navigated* is the fastest route to a permanent deny. The partial ships with `hidden`
and the click is the gesture, so the browser's own prompt can only ever be an answer to a real
one. Every `localStorage` helper is wrapped — private mode and "block third-party cookies" both
make it throw, and the failure mode is always "ask again", because a re-shown banner is cheaper
than a page that cannot render.

### Repairing a rotated subscription

`syncExistingSubscription()` runs on every page load and re-POSTs the browser's current
subscription to `/api/push/subscribe` when — and only when — permission is `granted` and the
`aliftools.push.prompt.synced` stamp is older than `SYNC_HOURS = 6`. A browser rotates a
subscription silently, and a rotation the server never hears about leaves a device subscribed
but undeliverable: the worst state, because nothing errors. Reaching the repair from any page is
what removes the "visit `/app` first" dependency for repairs as well as for the initial grant.
It is throttled rather than unconditional because it runs on every navigation, and a POST per
page view to a no-op endpoint is a cost with no benefit.

The endpoint sits outside auth, so an anonymous visitor's row lands with `user_id = NULL` and the
watcher reports it as `new` — one of the four kinds in §5. CSRF is *not* exempt: a POST without a
token is answered `422`, so a cross-site form cannot quietly enrol somebody's browser.

---

## 5. Watching for new subscriptions

`app:webpush:check` proves push works. This is the other half: knowing that somebody
*new* just started using it.

```bash
php yii app:webpush:watch                    # watch, polling every 5s
php yii app:webpush:watch --interval=30      # ...every 30s (default: WEBPUSH_WATCH_INTERVAL, 5)
php yii app:webpush:watch --once             # one poll and exit — for cron
php yii app:webpush:watch --replay           # report the existing table on the first run
php yii app:webpush:watch --notify=admins    # also raise a staff alert
```

Leave it running in a second terminal. It rings the terminal bell on a real TTY and prints one
line per change:

```
  NEW      #57  Chrome on Windows  anonymous  fcm.googleapis.com  14:22:07
  CLAIMED  #57  Chrome on Windows  user #12  fcm.googleapis.com  14:31:52
  OFF      #41  Firefox on Android user #3   fcm.googleapis.com  15:02:19
```

### Why it diffs flags and not writes

The obvious query — `SELECT … WHERE id > :last` — is wrong here, and wrong in a way that
disqualifies the whole command. `subscribe()` is an **upsert keyed on `endpoint`**, and
`resources/js/push-subscribe.js` re-posts the same subscription on **every page load**. So
`updated_at` ticks several times a minute per browser and means nothing. A watcher keyed on
writes reports the same browser over and over, and an operator who reads that for a week stops
reading it.

So the watcher remembers the last known state of every row and reports *transitions*:

| Event | Transition | What actually happened |
|---|---|---|
| `NEW` | row appeared | a browser granted permission for the first time |
| `REVIVED` | `is_active` 0 → 1 | a browser we had written off came back |
| `CLAIMED` | `user_id` NULL → somebody | the visitor who took the `/app` banner has now logged in |
| `OFF` | active → inactive | a `404/410` retirement, which has no other trace anywhere |

A row that is already active and already owned is the every-page-load re-post, and it is
deliberately silent. At most one line per row per tick: a row that comes back *and* gets claimed
is one `CLAIMED` line, not two lines saying half each.

**The first run seeds instead of reporting.** On a site with a few hundred subscribers, a
watcher that announced them all as new would bury the one event worth interrupting for. The
first tick reads the table, records it, says `Seeded from N existing subscription(s)`, and goes
quiet. `--replay` is the deliberate override for when you *do* want the census. A browser that
subscribes in the same instant as the seed is folded into the seed — there is no way to tell it
from a browser that was already there, and the command says so rather than guessing.

### The cursor

State is one small JSON file, `runtime/webpush-watch.json` by default (`--state` to move it):

```json
{"v":1,"rows":{"57":3,"41":2}}
```

* **It is the current table, not a history of it.** Rows somebody purges drop out on the next
  tick, so the file does not grow forever.
* **The first run on a missing or mangled cursor re-seeds**, and says
  `The cursor at … was unreadable and has been re-seeded` — a silent re-seed looks like the
  watcher missed a week of events, and you would have no way to know.
* **It is held under `flock(LOCK_EX|LOCK_NB)`.** Two watchers on one cursor each save the state
  they last saw, so every event the other handled is reported twice or not at all. The second
  one exits `1` with `Another watcher already holds …` rather than corrupting the first. To run
  a genuinely second watcher, give it its own `--state` file.

### Staff alerts

`--notify=admins` also dispatches a `system.alert`, so the news reaches staff who are not
watching a terminal. It is off by default — a debugging watcher that writes to the notification
tables on its own is a surprise, not a feature — and the header always says which mode is on.

> **Telegram does carry these alerts, but only by way of the admin fan-out.** The matrix gives
> `system.alert` `['in_app', 'webpush', 'telegram']` at priority 1, and the two gates that would
> normally have suppressed the copy are both deliberately open for this one event:
>
> * `wantsChannel()` hard-returns `false` for `telegram` — that helper exists to keep customer
>   copy off a third-party chat platform, and the admin loop **skips calling it entirely** so
>   staff are not affected by a rule written about users. What still applies inside that loop is
>   the `webpush` gate, because an admin's own `push_enabled` switch binds exactly as a user's
>   does.
> * The fan-out normally skips the account the event was dispatched for, on the grounds that an
>   admin approving their own recharge must not be told about it. `PushWatchCommand` picks
>   `firstStaffId()` precisely so somebody *is* told, so that skip would discard the only
>   recipient on a single-staff box. `system.alert` therefore sets `$targetIsRecipient` and the
>   target stays in the loop.
>
> The target is still not double-notified in app: their in-app row was written before the fan-out
> runs, so the loop only writes a copy for the *other* recipients. Net result for the staff member
> the watcher chose: one in-app row, one Telegram row, one webpush row if they have push on.

`OFF` events are never pushed: nobody asked to be told a browser left.

The alert's `reference` is `pushwatch-{id}-{kind}` and that is load-bearing.
`{{%notification_queue}}` has `uk_queue_dedupe` **UNIQUE** on `dedupe_key`, and
`dedupeKey()` hashes event + recipient + channel + reference. A `system.alert` has no id of its
own, so without a reference every alert for this event hashes *identically* and `enqueue()`
silently drops all but the first — forever. Two browsers subscribing would produce exactly one
notification.

`runtime/` is where the cursor lives, so it is out of the web root and untracked; nothing in it
is a secret except the endpoints, which the cursor does not store.

---

## 6. Multi-device verification checklist

The fan-out is the part most likely to be silently wrong, because a single subscription still
passes every single-device test.

```
1. Subscribe the same account on two browsers (or one normal + one private profile).
2. Confirm TWO rows for that user_id in push_subscription.
   php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=th_tools","root","");
            foreach($p->query("SELECT id,user_id,is_active,LEFT(user_agent,40) ua
                               FROM push_subscription ORDER BY id DESC LIMIT 5") as $r)
              print_r($r);'
3. Trigger an event → BOTH browsers must show it.
4. flip push_enabled to 0 for that user, trigger again → NEITHER fires.
   (Two gates check this: NotificationManager::pushAllowed() and
    WebPushChannel::send(). The second one is what covers already-queued rows.)
5. flip back to 1 → delivery resumes.
```

Step 4 is the one that catches a missing gate. If a notification still arrives with
`push_enabled = 0`, the check inside `WebPushChannel::send()` is not on that code path.

Note what step 5 does *not* need: the flip is read at queue time and again at send time, so no
re-subscribe and no re-post is involved. `syncExistingSubscription()` (§4) exists for a different
problem — the browser silently rotating its own subscription — and it only re-POSTs when
permission is `granted` and the 6-hour throttle has expired, so it will not paper over a gate
that is supposed to be closed.

---

## 7. Host-specific OpenSSL behaviour (mapped on this box)

This host has no OpenSSL config file, which produces failures that look like a coding bug and
are not. All three are handled in code; this table is here so the next person does not
re-diagnose them.

| Symptom | Cause | Where it is handled |
|---|---|---|
| `openssl_pkey_new()` returns `false` | no `openssl.cnf` | `WebPushChannel::generateEphemeralKey()` falls back to the `openssl` CLI via `proc_open` |
| `openssl_pkey_get_details($k)['ec']` has **no `x`/`y`** after a *failed* `openssl_pkey_new()` | PHP does not clear the array | same fallback; the function returns `array{key, point}` rather than a details array |
| `openssl_pkey_get_public()` prints "PEM routines::no start line" — **on success too** | SPKI is hand-built in `VapidKeys` and the parser complains regardless | ignore the warning; the return value is what matters |
| `openssl_pkey_derive($public, $private, 32)` works, but the reverse order returns `false` | argument order is semantically fixed | `deriveSharedSecret()` always passes `(public, private)` |

The ephemeral-key fallback is not paranoia: a silently missing `x`/`y` produces a subscription
the **push service** rejects, hours later, with an error that points nowhere near the sender.

Quick probe of what the host can actually do:

```bash
php -r '$k=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_EC,"curve_name"=>"prime256v1"]);
        var_dump($k === false);'
```

---

## 8. Failure → symptom table

| Reported by | Message | Meaning | Fix |
|---|---|---|---|
| push service | `400 Bad Subscription` | `p256dh`/`auth` do not match the endpoint | The browser rotated the keys; re-post the subscription |
| push service | `404/410 Gone` | Endpoint retired | Already deactivated on receipt; row is now inert |
| push service | `401 Unauthorized` on the VAPID header | Key pair mismatch, expired JWT, bad `aud` | `aud` = endpoint **origin**, not the full URL; `exp` ≤ 24 h |
| push service | `403` | `VAPID_SUBJECT` is not `mailto:`/`https://` | Fix `.env` |
| Browser console | `NotSupportedError` from `subscribe()` | Worker not activated yet | Await `navigator.serviceWorker.ready` (already done) |
| Browser console | `TypeError: Invalid value for applicationServerKey` | key not base64url-decoded | Use `urlBase64ToUint8Array()` |
| Browser | No permission prompt at all | Permission already decided | Must be changed in browser site settings |
| `app:webpush:check` | `public/push-sw.js is missing` | Worker not deployed; `/push-sw.js` 404s | Deploy the file; every push is otherwise a silent no-op |
| `app:webpush:check` | `public/push-sw.js is empty` | Truncated deploy | Redeploy the file |
| `app:webpush:check` | `Could not read {{%push_subscription}}` | Migration not run | `php yii migrate:up --no-interaction` |
| `{{%notification_delivery}}` | `failed` with `transient` | 5xx / timeout / crypto hiccup | Retried by the worker; investigate the network |
| `{{%notification_delivery}}` | `failed` with `permanent` | 400 / bad subscription | Not retried — dead-lettered |
| `app:webpush:watch` | `Another watcher already holds …` | A second watcher on the same cursor | Stop the first, or pass `--state` to use a separate cursor |
| `app:webpush:watch` | `The cursor at … was unreadable and has been re-seeded` | Cursor file truncated or hand-edited | Nothing to fix — events during that window were not reported; `--replay` backfills the table |
| `app:webpush:watch` | Nothing at all, for hours | Working as designed: the re-post is silent | Run it with `-v` to print `no changes` per tick, proving it is polling |
| Nothing at all | — | `push_enabled = 0`, or no VAPID keys | Check `/profile` and `GET /api/push/key` |

---

## 9. Scheduling

The queue has no resident worker on shared hosting; drain it from cron:

```cron
* * * * *  cd /home/user/app && php yii app:notification:work >> runtime/notify.log 2>&1
0 4 * * 0   cd /home/user/app && php yii app:notification:purge >> runtime/notify.log 2>&1
```

One row per `(user, channel)` means one account with three browsers produces three jobs — that is
intended. The worker is idempotent per row, so an overlapping cron tick re-sends rather than
corrupts.

**Do not also put `app:webpush:watch` in cron.** `--once` is single-poll by design and is there
for a host where a resident process is impossible — but cron's minimum resolution is a minute, so
a `--once` run says nothing about a five-second window, and the cursor it advances makes the *next*
scheduled run blind to the gap in between. If you cannot keep a process resident, `--notify=admins`
plus `app:notification:work` is the correct shape, and the cron entry is
`php yii app:webpush:watch --once --notify=admins >> runtime/watch.log 2>&1`.

---

## 10. Privacy

* `push_subscription.endpoint` is a live, secret URL. It is stored and never rendered in any
  page, log line or API response.
* The endpoint list a user sees is derived from `user_agent` and shown as a friendly label
  (`describeBrowser()`) — the raw UA is never exposed.
* Rotating the VAPID key pair invalidates every existing subscription: browsers re-prompt, and
  the old rows sit as dead weight until `app:notification:purge` clears them (90 days after
  deactivation). To reclaim them immediately, deactivate by hand:

```sql
UPDATE push_subscription SET is_active = 0 WHERE is_active = 1;
```
