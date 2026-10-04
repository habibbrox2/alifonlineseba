/* ============================================================
 * /push-sw.js — the service worker that receives Web Push messages.
 *
 * Served from the web root so its scope is `/`: a notification click opens
 * /dashboard/orders/12, and a worker scoped to its own file could not
 * control that navigation. Keeping it at the root also means the Push API's
 * "same-origin script" requirement is met with no CDN hop.
 *
 * This file is NOT an ES module. It is registered with a plain
 * `navigator.serviceWorker.register()` call, and `importScripts` is the
 * service-worker idiom — an `import` statement here would fail to parse.
 *
 * ## It deliberately makes no fetch() calls
 *
 * Every POST route here sits behind `CsrfTokenMiddleware`, and the token
 * lives in a `<meta>` tag the worker cannot read. A `pushsubscriptionchange`
 * handler that tried to re-register itself would therefore be rejected with a
 * 403 every time. It does not: `initPushSubscribe()` re-posts the browser's
 * current subscription on the next page load, which is a moment where the
 * token *is* available, and is a fraction of a second later anyway.
 * ============================================================ */

const VERSION = 'v1';
const CACHE = `aliftools-push-${VERSION}`;

/** Where a notification with no usable link should land. */
const FALLBACK_URL = '/';

/**
 * Same-origin, path-only targets are allowed; anything else becomes null.
 *
 * A push service is a third party that chooses the payload it hands us, so
 * treating `data.url` as a link target without this check would make every
 * push a phishing vector: click, land somewhere else, done.
 */
function isSafeUrl(raw) {
  if (typeof raw !== 'string' || raw === '') {
    return null;
  }
  try {
    const url = new URL(raw, self.location.origin);
    if (url.origin !== self.location.origin) {
      return null;
    }
    return url.pathname + url.search + url.hash;
  } catch (e) {
    return null;
  }
}

/**
 * Unpack the JSON body sent by `WebPushChannel::encodePayload()`.
 *
 * The channel already truncates to what aes128gcm can carry. A parse failure
 * here means a push from somewhere else — a browser default, or a worker left
 * over from a previous deploy — and falling back to showing the raw text
 * still gives the person something rather than a silent failure.
 */
function readPayload(event) {
  const fallback = { title: 'All Seba', body: 'আপনার জন্য একটি নতুন আপডেট আছে।', url: null };

  if (!event.data) {
    return fallback;
  }

  let text;
  try {
    text = event.data.text();
  } catch (e) {
    return null;
  }

  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch (e) {
    return { title: 'All Seba', body: String(text).slice(0, 400), url: null };
  }

  return {
    title: String(parsed.title || 'All Seba').slice(0, 200),
    body: String(parsed.body || '').slice(0, 400),
    url: isSafeUrl(parsed.data && parsed.data.url),
  };
}

self.addEventListener('install', (event) => {
  // Take over immediately: a visitor who has been on the site for a while
  // should not need a second reload for notifications to start working after
  // a deploy changes this file.
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys();
      await Promise.all(
        names
          .filter((name) => name.startsWith('aliftools-push-') && name !== CACHE)
          .map((name) => caches.delete(name)),
      );
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('push', (event) => {
  const payload = readPayload(event);
  if (!payload) {
    return;
  }

  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      // Android ignores an SVG for the icon and falls back to the site icon, so
      // the shipped PNGs are what actually get used here.
      icon: '/icon-192.png',
      badge: '/icon-192.png',
      // A tag collapses repeats: a dozen stacked "your order is ready" banners
      // is worse than one.
      tag: 'aliftools',
      renotify: true,
      dir: 'ltr',
      lang: 'bn',
      data: { url: payload.url || FALLBACK_URL },
      // Long-short pair: buzz once and stop, which is what you want when the
      // phone is in a pocket.
      vibrate: [120, 60, 120],
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = isSafeUrl(event.notification.data && event.notification.data.url) || FALLBACK_URL;

  event.waitUntil(
    (async () => {
      // Reuse a tab that is already on the site. Focusing and navigating an
      // existing tab is what makes a notification feel like it opened the
      // thing you were already looking at, rather than spawning a duplicate.
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const client of windows) {
        if (!('focus' in client)) {
          continue;
        }
        await client.focus();
        if ('navigate' in client) {
          await client.navigate(target).catch(() => {});
        }
        return;
      }

      if (self.clients.openWindow) {
        await self.clients.openWindow(target);
      }
    })(),
  );
});
