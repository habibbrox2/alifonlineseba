/* ============================================================
 * Site-wide "turn on notifications" prompt.
 *
 * Until now the only place permission could be asked for was the
 * `/app` page, which means a person who never had a reason to
 * download an APK never got asked — and then never heard about
 * their own order finishing. This module asks on *every* page a
 * signed-in person reaches, once, and stays quiet after that.
 *
 * Signed-in is the whole condition, and it is the server that
 * decides: `partials/push-prompt.twig` renders the prompt on public
 * pages too — the subscription repair below still needs those — but
 * marks it `data-signed-in="0"` so the offer stays hidden.
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
 *   - a page that already has a push card (`/app`, `/profile`) or
 *     already carries the standing reminder: two asks for one
 *     decision.
 *   - the visitor is not signed in: the permission would bind to no
 *     account, so the only thing it can ever feed is the anonymous
 *     broadcast list.
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

/**
 * Is the person looking at this page signed in?
 *
 * The prompt partial renders `data-signed-in`, and this is the only place
 * that reads it. The distinction matters because the module has two jobs:
 * the *ask*, which is worth nothing without an account to attach the
 * resulting subscription to, and the repair, which an anonymous subscriber
 * still needs from any page.
 *
 * A missing root reads as signed out, so a page that ships no prompt at all
 * asks for nothing — the same direction as the guest case.
 */
export function isSignedIn() {
  const root = document.querySelector('[data-push-prompt]');
  return root !== null && root.dataset.signedIn === '1';
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
  // No account means nowhere to record the subscription. The endpoint accepts
  // guests on purpose (an anonymous row is still delivered to), but the *ask*
  // is not theirs to answer — see the partial for why the markup still ships.
  if (!isSignedIn()) {
    return false;
  }
  // The app's own WebView: no Push API, and nothing to gain by asking.
  if (/aliftools/i.test(navigator.userAgent || '')) {
    return false;
  }
  if (Notification.permission !== 'default') {
    return false;
  }
  // A page that already carries a push button — or the standing reminder for
  // an account that has switched push off — owns this decision.
  if (document.querySelector('[data-push-root], [data-push-settings], [data-push-reminder]')) {
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
 * Unhide the standing reminder for a browser that has already refused push
 * permission (`partials/push-reminder.twig`, which ships it `hidden`).
 *
 * The server renders the bar on every eligible dashboard page with no way to
 * know the browser's answer, so the decision is made here:
 *
 *   - `permission === 'denied'` is the case the dismissible offer can never
 *     reach again — the browser will not show its dialog twice, and
 *     `shouldAsk()` bails out for anything but `'default'`. Without this bar
 *     that visitor is never told why their order updates stopped.
 *   - `push_enabled = 1` is what makes it the browser's fault rather than the
 *     account switch's: the account bar owns the other branch in the partial,
 *     and the server only renders this one when the switch is on.
 *   - Anything else (fine, or not yet asked) keeps it hidden: a warning about
 *     broken notifications on a page where they work is how warnings become
 *     invisible.
 *
 * The TWA user agent is excluded because the Android app's WebView is not a
 * browser site with permission toggles — there is nothing to point at.
 *
 * @returns {boolean} whether the bar is now showing
 */
export function revealDeniedReminder() {
  const bar = document.querySelector('[data-push-denied]');
  if (!bar || !bar.hidden) {
    return false;
  }
  if (!isPushSupported() || /aliftools/i.test(navigator.userAgent || '')) {
    return false;
  }
  if (Notification.permission !== 'denied') {
    return false;
  }
  bar.hidden = false;
  return true;
}

/**
 * Wire the prompt. Safe to call on a page that has none — it returns
 * without touching the DOM, which is what lets `base.twig` ship it
 * unconditionally.
 */
export async function initPushPrompt() {
  // The standing denied-permission bar has its own marker and its own rules,
  // and runs before the prompt-root guard: dashboard pages ship it whether or
  // not the dismissible offer is on screen.
  revealDeniedReminder();

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

