/* ============================================================
 * Web Push subscription (RFC 8030 / VAPID, RFC 8292).
 *
 * Three steps, and only the middle one is interesting:
 *
 *   1. ask the browser for permission  — a user gesture, cannot be scripted
 *   2. register /push-sw.js and call pushManager.subscribe()
 *   3. POST the resulting PushSubscription to /api/push/subscribe
 *
 * The VAPID *public* key is embedded in the page by the server (it is public
 * by definition, and shipping it from the template saves a round trip before
 * the permission prompt — the prompt is the expensive part, so it should not
 * wait on a fetch that can fail). The private key never leaves the server.
 *
 * ## Why step 3 is idempotent from the caller's point of view
 *
 * The browser hands out one subscription per origin+SW and rotates it
 * silently, so `subscribe()` can return a *different* endpoint than last time
 * without the user doing anything. The server upserts on the endpoint
 * (`PushSubscriptionRepository::subscribe`), so re-posting after a rotation is
 * the correct repair, not a duplicate. The `id` returned by the POST is kept
 * so the disable button can name the exact row it turned off.
 *
 * ## Every failure has to be a no-op, not a broken button
 *
 * Permission can be denied, PushManager can be missing (Firefox desktop on
 * some builds, iOS Safari which has no Web Push at all), and the SW
 * registration can fail on http:// origins. In each case the toggle has to go
 * back to its resting state and say why — a button that silently stops
 * working is the single most confusing outcome this code can produce.
 * ============================================================ */

import { api } from './app.js';

const SW_URL = '/push-sw.js';
const SUBSCRIBE_URL = '/api/push/subscribe';
const UNSUBSCRIBE_URL = '/api/push/unsubscribe';

/** Shown when the browser has no Push API at all. */
const UNSUPPORTED_HINT = 'এই ব্রাউজারে ওয়েব নোটিফিকেশন সাপোর্ট নেই। অ্যাপটি ইনস্টল করে নিন।';

/**
 * base64url → Uint8Array.
 *
 * `applicationServerKey` is the one place the raw key crosses into an
 * `ArrayBuffer`, and PushManager rejects a plain array. Padding is re-added
 * because `atob` needs a length that is a multiple of four; the 65-byte point
 * is *not* a multiple of four once encoded, so the missing `=` is the normal
 * case rather than a malformed key.
 */
export function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i);
  }
  return output;
}

/** Is this browser able to do Web Push at all? */
export function isPushSupported() {
  return (
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window
  );
}

/** 'granted' | 'denied' | 'default' — or null when there is no API. */
export function currentPermission() {
  return 'Notification' in window ? Notification.permission : null;
}

/**
 * Register the worker and resolve the existing subscription, if any.
 *
 * Registration is scoped to `/` rather than `/push-sw.js` on purpose: a
 * notification click navigates to `/dashboard/orders/12`, and a worker scoped
 * to its own file cannot control that navigation. `Service-Worker-Allowed` is
 * not needed for this because the script is already at the root.
 */
export async function ensureWorker() {
  const registration = await navigator.serviceWorker.register(SW_URL, { scope: '/' });

  // `register()` resolves before the worker reaches "activated" for a first
  // install, and pushManager.subscribe() throws NotSupportedError in that
  // window. Waiting here is the difference between a working toggle and a
  // permission prompt that then fails.
  if (registration.active && navigator.serviceWorker.controller) {
    return registration;
  }
  await navigator.serviceWorker.ready;
  return registration;
}

/** The current subscription, or null. Never throws. */
export async function getSubscription() {
  if (!isPushSupported()) {
    return null;
  }
  try {
    const registration = await ensureWorker();
    return await registration.pushManager.getSubscription();
  } catch (e) {
    return null;
  }
}

/**
 * Ask for permission, subscribe, and register with the server.
 *
 * Resolves to {ok:true, subscription} or {ok:false, reason}. The reason is a
 * short machine key the caller maps to a Bangla sentence; keeping the strings
 * out of here means this module has no user-facing copy to keep in sync with
 * the template.
 */
export async function subscribe(vapidPublicKey) {
  if (!isPushSupported()) {
    return { ok: false, reason: 'unsupported' };
  }
  if (!vapidPublicKey) {
    return { ok: false, reason: 'no-key' };
  }

  let permission = Notification.permission;
  if (permission === 'default') {
    // This must be the first thing that can prompt. Any await before it ends
    // the user-gesture window in some browsers and the prompt is auto-denied.
    permission = await Notification.requestPermission();
  }
  if (permission !== 'granted') {
    return { ok: false, reason: 'denied' };
  }

  let subscription;
  try {
    const registration = await ensureWorker();
    subscription = await registration.pushManager.subscribe({
      // Chrome will not deliver, and drops the subscription, if a push can be
      // silent. Everything this site sends is a thing a person asked to hear
      // about, so there is nothing to gain from the silent capability.
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });
  } catch (e) {
    return { ok: false, reason: e && e.name === 'NotAllowedError' ? 'denied' : 'failed' };
  }

  const result = await api(SUBSCRIBE_URL, {
    method: 'POST',
    body: subscription.toJSON(),
  });
  if (!result.success) {
    return { ok: false, reason: 'failed', detail: result.message };
  }

  return { ok: true, subscription, id: result.data && result.data.id };
}

/** Turn the subscription off, in the browser first and on the server second. */
export async function unsubscribe(subscriptionId) {
  const subscription = await getSubscription();
  if (subscription) {
    try {
      await subscription.unsubscribe();
    } catch (e) {
      /* the server call below is still worth making */
    }
  }

  // Always tell the server, even when the browser already dropped the
  // subscription: that is exactly the state a stale row is in, and leaving it
  // active is what produces notifications nobody can dismiss.
  const result = await api(UNSUBSCRIBE_URL, {
    method: 'POST',
    body: subscriptionId ? { id: subscriptionId } : { endpoint: subscription ? subscription.endpoint : '' },
  });

  return { ok: result.success, id: subscriptionId || null };
}

/* ============================================================
 * DOM wiring for the /app page card.
 * ============================================================ */

const REASONS = {
  unsupported: UNSUPPORTED_HINT,
  'no-key': 'এই সার্ভারে নোটিফিকেশন চালু করা হয়নি।',
  denied: 'ব্রাউজারে অনুমতি দেওয়া হয়নি। সাইট সেটিংস থেকে অনুমতি দিয়ে আবার চেষ্টা করুন।',
  failed: 'নোটিফিকেশন চালু করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।',
};

export function initPushSubscribe() {
  const root = document.querySelector('[data-push-root]');
  if (!root) {
    return;
  }

  const toggle = root.querySelector('[data-push-toggle]');
  const label = root.querySelector('[data-push-label]');
  const hint = root.querySelector('[data-push-hint]');
  const disable = root.querySelector('[data-push-disable]');
  const vapidKey = root.dataset.vapidKey || '';

  // The server rendered this as disabled; there is no key to subscribe with,
  // so the card is informational and the script has nothing to do.
  if (root.dataset.pushEnabled !== '1' || !toggle) {
    return;
  }

  let subscriptionId = 0;

  const say = (message) => {
    if (!hint) {
      return;
    }
    if (message) {
      hint.textContent = message;
      hint.hidden = false;
    } else {
      hint.textContent = '';
      hint.hidden = true;
    }
  };

  const showSubscribed = (on) => {
    toggle.disabled = false;
    label.textContent = on ? 'নোটিফিকেশন চালু আছে' : 'নোটিফিকেশন চালু করুন';
    say(on ? 'আপনার ব্রাউজারে নোটিফিকেশন চালু আছে।' : '');
  };

  // Reflect the *browser's* state on load. The server cannot know it, and
  // showing "enable" on a phone that is already subscribed is how people end up
  // with two subscriptions and one of them leaking.
  getSubscription().then((existing) => {
    if (!existing) {
      toggle.disabled = false;
      label.textContent = 'নোটিফিকেশন চালু করুন';
      say('');
      return;
    }
    // Re-post on load: the row may be missing, or the endpoint may have been
    // rotated since the last visit. Upserting is cheap and makes the server's
    // view converge on the browser's.
    api(SUBSCRIBE_URL, { method: 'POST', body: existing.toJSON() }).then((result) => {
      subscriptionId = (result.data && result.data.id) || 0;
      showSubscribed(true);
    });
  });

  toggle.addEventListener('click', async () => {
    toggle.disabled = true;
    label.textContent = 'অনুমতি দিন…';
    say('');

    const result = await subscribe(vapidKey);
    if (result.ok) {
      subscriptionId = result.id || subscriptionId;
      showSubscribed(true);
      return;
    }
    showSubscribed(false);
    say(REASONS[result.reason] || REASONS.failed);
  });

  if (disable) {
    disable.addEventListener('click', async () => {
      toggle.disabled = true;
      const result = await unsubscribe(subscriptionId);
      subscriptionId = 0;
      showSubscribed(false);
      if (!result.ok) {
        say(REASONS.failed);
      }
    });
  }

  if (!isPushSupported()) {
    toggle.disabled = true;
    say(UNSUPPORTED_HINT);
  }
}
