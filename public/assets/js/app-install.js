/* ============================================================
 * Android install banner.
 *
 * A fixed bar offering the APK, shown only where it can actually
 * be acted on: an Android *phone*, in a browser, that has not
 * already dismissed it and is not already running the app.
 *
 * Why the checks are this strict — each one is a case where the
 * banner would be wrong, not merely redundant:
 *
 *   - Desktop: the person is not the one who will install it.
 *   - iOS: there is no sideloading path to point them at.
 *   - The app itself (`aliftools://` UA): it would be advertising
 *     itself to its own WebView.
 *   - Standalone display: already installed — that is the success
 *     state, not a failure to announce.
 *
 * Dismissal lives in localStorage rather than a cookie because it
 * is a device preference, not something the account needs to
 * carry, and a visitor who has no account is exactly the one this
 * banner is for.
 * ============================================================ */

const DISMISS_KEY = 'aliftools.install.dismissed';
const DISMISS_DAYS = 30;
const SW_URL = '/push-sw.js';

/** True on an Android phone that is not the app's own WebView. */
export function isAndroidPhone() {
  const ua = navigator.userAgent || '';
  if (/aliftools/i.test(ua)) {
    return false;
  }
  if (!/Android/i.test(ua)) {
    return false;
  }
  // "Android" appears on tablets too. A tablet is a fine place to *download*
  // an APK but a terrible place for a bottom bar that covers content, so the
  // banner stays away and /app carries the QR code instead. The absence of
  // "Mobile" is the standard phone signal; "Silk" is Amazon's Fire OS, which
  // reports Android but has no Play Protect prompt to fall through to.
  return !/Tablet|Silk/i.test(ua) && /Mobile/i.test(ua);
}

/** Already running as an installed app (display-mode: standalone). */
export function isStandalone() {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true
  );
}

function isDismissed() {
  try {
    const raw = window.localStorage.getItem(DISMISS_KEY);
    if (!raw) {
      return false;
    }
    const stamp = Number(raw);
    if (!Number.isFinite(stamp) || stamp <= 0) {
      return false;
    }
    if (Date.now() - stamp > DISMISS_DAYS * 86400000) {
      window.localStorage.removeItem(DISMISS_KEY);
      return false;
    }
    return true;
  } catch (e) {
    // Private mode / storage disabled: treat as "not dismissed" and let the
    // banner show. Re-asking costs one banner; blocking the page does not.
    return false;
  }
}

function dismiss() {
  try {
    window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
  } catch (e) {
    /* nothing to do — the banner simply reappears next load */
  }
}

/**
 * If a service worker for push is already installed, the visitor has the app.
 * Cheap best-effort: on failure we assume they do not, which only costs a
 * banner they can dismiss.
 */
async function hasInstalledWorker() {
  if (!('serviceWorker' in navigator)) {
    return false;
  }
  try {
    const registrations = await navigator.serviceWorker.getRegistrations();
    return registrations.some((r) => r.active && r.scope === new URL(SW_URL, location.origin).pathname);
  } catch (e) {
    return false;
  }
}

export function initAppInstall() {
  const el = document.querySelector('[data-app-install]');
  if (!el) {
    return;
  }

  const close = el.querySelector('[data-app-install-close]');
  if (close) {
    close.addEventListener('click', () => {
      dismiss();
      hide(el);
    });
  }

  if (!isAndroidPhone() || isStandalone() || isDismissed()) {
    return;
  }

  hasInstalledWorker().then((installed) => {
    if (installed) {
      hide(el);
      return;
    }
    el.hidden = false;
    document.body.classList.add('app-install-visible');
  });
}

function hide(el) {
  el.remove();
  document.body.classList.remove('app-install-visible');
}
