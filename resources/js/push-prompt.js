/* ============================================================
 * Site-wide "turn on notifications" prompt.
 *
 * Until now the only place permission could be asked for was the
 * `/app` page, which means a person who never had a reason to
 * download an APK never got asked — and then never heard about
 * their own order finishing. This module asks on *every* page,
 * once, and stays quiet after that.
 *
 * ## What it deliberately does not do
 *
 * It never calls `Notification.requestPermission()` on load. Firefox
 * requires a user gesture and Chrome's prompt is modal on some
 * builds; a permission box that appears because someone navigated
 * is the fastest way to get permanently denied. So the module only
 * *offers*, and the click is the gesture.
 *
 * ## Why the checks are this strict
 *
 * The prompt is worse than no prompt when it is wrong:
 *
 *   - `permission !== 'default'`: granted needs nothing, and denied
 *     cannot be re-asked programmatically — the browser remembers
 *     and every further attempt is a lie in the UI.
 *   - a page that already has a push card (`/app`, `/profile`):
 *     two prompts for one decision.
 *   - the app's own WebView: it has no Push API, and advertising
 *     notifications it cannot deliver is worse than silence.
 *   - dismissed recently: nagging is how a prompt becomes a
 *     nuisance people learn to click past without reading.
 *
 * ## Storage can throw
 *
 * Private mode and "block third-party cookies" both make
 * localStorage throw on access. Every helper below catches, and the
 * failure mode is always "ask again" — re-showing a banner is
 * cheaper than a page that cannot render.
 * ============================================================ */

import { getSubscription, isPushSupported, subscribe } from './push-subscribe.js';
import { api } from './app.js';

const DISMISS_KEY = 'aliftools.push.prompt.dismissed';
const SYNC_KEY = 'aliftools.push.prompt.synced';
const DISMISS_DAYS = 14;
const SYNC_HOURS = 6;
const SUBSCRIBE_URL = '/api/push/subscribe';

/** The VAPID public key, rendered into every page by `PushViewInjection`. */
export function vapidKey() {
  const meta = document.querySelector('meta[name="vapid-public-key"]');
  return meta ? meta.getAttribute('content') || '' : '';
}

function readStamp(key) {
  try {
    const stamp = Number(window.localStorage.getItem(key));
    return Number.isFinite(stamp) && stamp > 0 ? stamp : 0;
  } catch (e) {
    return 0;
  }
}

function writeStamp(key) {
  try {
    window.localStorage.setItem(key, String(Date.now()));
  } catch (e) {
    /* nothing to do — the offer simply reappears next load */
  }
}

/** Dismissed recently enough to stay quiet. */
export function isDismissed(now = Date.now()) {
  const stamp = readStamp(DISMISS_KEY);
  return stamp > 0 && now - stamp <= DISMISS_DAYS * 86400000;
}

export function dismiss(now = Date.now()) {
  writeStamp(DISMISS_KEY, now);
}

/** Already re-synced with the server recently enough to skip the POST. */
function syncedRecently(now = Date.now()) {
  const stamp = readStamp(SYNC_KEY);
  return stamp > 0 && now - stamp <= SYNC_HOURS * 3600000;
}

/**
 * Should this page offer permission?
 *
 * Exported separately from the DOM wiring so the rule can be asserted
 * without a browser, and so the same rule is used for the decision and
 * for the reveal — two answers to one question is how a prompt ends up
 * rendered on a page that decided not to show it.
 */
export function shouldAsk(now = Date.now()) {
  if (!isPushSupported()) {
    return false;
  }
  // The app's own WebView: no Push API, and nothing to gain by asking.
  if (/aliftools/i.test(navigator.userAgent || '')) {
    return false;
  }
  if (Notification.permission !== 'default') {
    return false;
  }
  // A page that already carries a push button owns this decision.
  if (document.querySelector('[data-push-root], [data-push-settings]')) {
    return false;
  }
  if (!vapidKey()) {
    return false;
  }
  return !isDismissed(now);
}

function say(hint, message) {
  if (hint) {
    hint.textContent = message || '';
    hint.hidden = !message;
  }
}

/**
 * Re-post the browser's current subscription so the server's view of
 * this device cannot drift from the browser's.
 *
 * The browser rotates a subscription silently, and a rotation the
 * server never hears about is a device that is subscribed but
 * undeliverable — the worst state, because nothing errors. Doing it
 * from *any* page is what removes the "you have to visit /app first"
 * dependency for repairs as well as for the initial grant.
 *
 * Throttled rather than unconditional: this runs on every page load,
 * and a POST per navigation to a no-op endpoint is a cost with no
 * benefit when the answer has not changed.
 */
async function syncExistingSubscription() {
  if (!isPushSupported() || Notification.permission !== 'granted' || syncedRecently()) {
    return;
  }

  let existing;
  try {
    existing = await getSubscription();
  } catch (e) {
    return;
  }

  writeStamp(SYNC_KEY);

  if (!existing) {
    return;
  }

  try {
    await api(SUBSCRIBE_URL, { method: 'POST', body: existing.toJSON() });
  } catch (e) {
    // Nothing to repair and nobody waiting on it: the next load retries.
  }
}

/**
 * Wire the prompt. Safe to call on a page that has none — it returns
 * without touching the DOM, which is what lets `base.twig` ship it
 * unconditionally.
 */
export async function initPushPrompt() {
  const root = document.querySelector('[data-push-prompt]');
  if (!root) {
    return;
  }

  const allow = root.querySelector('[data-push-prompt-allow]');
  const close = root.querySelector('[data-push-prompt-close]');
  const hint = root.querySelector('[data-push-prompt-hint]');
  const key = vapidKey();

  const hide = () => {
    root.remove();
    document.body.classList.remove('push-prompt-visible');
  };

  if (close) {
    close.addEventListener('click', () => {
      dismiss();
      hide();
    });
  }

  // Not an offer — a repair. Runs regardless of the banner, and before
  // it, so a person who granted permission on an earlier page and
  // navigates elsewhere gets the server repaired either way.
  syncExistingSubscription();

  if (!shouldAsk()) {
    return;
  }

  // Granted between this page's load and this line (another tab).
  if (Notification.permission !== 'default') {
    return;
  }

  root.hidden = false;
  document.body.classList.add('push-prompt-visible');

  if (!allow) {
    return;
  }

  allow.addEventListener('click', async () => {
    allow.disabled = true;
    say(hint, 'অনুমতি দেওয়া হচ্ছে…');

    const result = await subscribe(key);
    if (result.ok) {
      dismiss();
      hide();
      return;
    }

    allow.disabled = false;
    say(hint, result.reason === 'denied' ? 'ব্রাউজারে অনুমতি দেওয়া হয়নি।' : 'নোটিফিকেশন চালু করা যায়নি। পরে আবার চেষ্টা করুন।');
  });
}

