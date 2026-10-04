/* ============================================================
 * All Seba — shared frontend utilities (vanilla ES module).
 * No jQuery. Modern browser APIs only.
 * ============================================================ */

/** Debounce helper for search inputs. */
export function debounce(fn, delay = 300) {
  let t = null;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

/** Read the CSRF token from the meta tag injected by the backend. */
export function csrfToken() {
  const meta =
    document.querySelector('meta[name="csrf"]') ||
    document.querySelector('meta[name="_csrf"]');
  return meta ? meta.getAttribute('content') : '';
}

/**
 * fetch wrapper returning parsed JSON with the standard envelope
 * { success, message, data, errors }.
 */
export async function api(path, { method = 'GET', body = null, signal } = {}) {
  const headers = { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken() };
  if (body !== null) {
    headers['Content-Type'] = 'application/json';
  }
  const response = await fetch(path, { method, headers, body: body === null ? null : JSON.stringify(body), signal });
  let payload = null;
  try {
    payload = await response.json();
  } catch (e) {
    payload = { success: false, message: 'Invalid server response.', data: null, errors: [] };
  }
  return { status: response.status, ...payload };
}

/** Format an amount in Bangladeshi Taka. */
export function money(amount) {
  return '৳ ' + Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Initialize lucide icons against the sprite (no-op if sprite missing). */
export function initIcons() {
  document.querySelectorAll('use[data-lucide]')?.forEach((u) => {
    const name = u.getAttribute('data-lucide');
    if (name) u.setAttribute('href', `/assets/icons/lucide-sprite.svg#${name}`);
  });
}

/* ============================================================
 * Live header balance.
 *
 * The chip in the topbar is rendered from the identity resolved per
 * request, so it is already correct on load. This keeps it honest
 * afterwards: an admin can approve a recharge while the user sits on
 * a page, and the figure should move without a manual reload.
 * No-op on public pages, where the chip does not exist.
 * ============================================================ */

/** How often the balance is re-read while the tab is visible. */
export const BALANCE_POLL_MS = 45000;

export function initHeaderBalance() {
  const chip = document.getElementById('header-balance');
  const output = chip?.querySelector('[data-balance-text]');
  let shown = Number(chip?.dataset.balance);
  if (!chip || !output || !Number.isFinite(shown)) {
    return;
  }

  const apply = (value) => {
    if (!Number.isFinite(value) || value === shown) {
      return;
    }
    shown = value;
    output.textContent = money(value);
    chip.dataset.balance = String(value);
    // Restart the CSS highlight so two changes in a row both flash.
    chip.classList.remove('balance-flash');
    void chip.offsetWidth;
    chip.classList.add('balance-flash');
  };

  const poll = async () => {
    if (document.hidden) {
      return;
    }
    try {
      const result = await api('/api/profile');
      if (result.success && result.data) {
        apply(Number(result.data.balance));
      }
    } catch {
      // Offline or session expired — keep the server-rendered figure.
    }
  };

  setInterval(poll, BALANCE_POLL_MS);
  // Coming back to the tab is the moment a stale figure is most visible.
  document.addEventListener('visibilitychange', () => !document.hidden && poll());
  window.addEventListener('focus', poll);
}

// Module scripts are deferred, so the DOM is normally already parsed.
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHeaderBalance);
  } else {
    initHeaderBalance();
  }
}

/* ============================================================
 * Header notification dropdown.
 *
 * Alpine owns the open/close state; this component only feeds it.
 * The list is never rendered into the page HTML — it is fetched by a
 * background poller and kept warm in memory, so opening the panel is a
 * pure state flip with no request on the critical path.
 * ============================================================ */

const NOTIFICATION_ICONS = {
  info: 'info',
  success: 'check-circle-2',
  warning: 'alert-triangle',
  danger: 'alert-circle',
};

const NOTIFICATION_TONES = {
  info: 'bg-info-50 text-info-600',
  success: 'bg-success-50 text-success-600',
  warning: 'bg-warning-50 text-warning-600',
  danger: 'bg-danger-50 text-danger-600',
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * How often the background poller refreshes the list while the tab is open.
 * This replaces the old 15s stale window: rather than deciding on open that
 * the cached copy is too old, the copy is simply never allowed to get old.
 */
const NOTIFICATION_POLL_MS = 45000;

/**
 * After the tab has been hidden, a copy older than this is refetched the
 * moment the user comes back. Below the threshold the poll loop was already
 * running and the data is fresh enough to skip the request.
 */
const NOTIFICATION_RESUME_MS = 20000;

/**
 * Defer work until the browser is idle so the warm-up fetch never competes
 * with first paint — a slow request must not delay the header rendering.
 */
function whenIdle(fn) {
  if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(fn, { timeout: 2000 });
  } else {
    setTimeout(fn, 400);
  }
}

/**
 * "আজ, ২:৩০ পূর্ণাহ্ণ" for today, "গতকাল, …" for yesterday and a plain
 * date beyond that. Mirrors the bndate Twig filter used on the full page,
 * with the two recent days spelled out because they are the ones people
 * actually recognise.
 */
export function notificationTime(value) {
  if (!value) {
    return '—';
  }
  const ts = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(ts.getTime())) {
    return '—';
  }
  const time = ts.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  const now = new Date();
  if (ts.toDateString() === now.toDateString()) {
    return 'আজ, ' + time;
  }
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (ts.toDateString() === yesterday.toDateString()) {
    return 'গতকাল, ' + time;
  }
  return ts.getDate() + ' ' + MONTHS[ts.getMonth()] + ' ' + ts.getFullYear() + ', ' + time;
}

window.thNotifications = function (unread = 0) {
  return {
    open: false,
    loading: false,
    loaded: false,
    error: '',
    items: [],
    total: 0,
    unread: Number(unread) || 0,
    fetchedAt: 0,

    // Timer handles and the in-flight guard are deliberately not reactive:
    // Alpine would otherwise walk these objects on every tick and every
    // mutation. They are still fine as plain properties.
    _timer: 0,
    _inflight: false,
    _onResume: null,

    get badge() {
      return this.unread > 9 ? '9+' : String(this.unread);
    },

    get isEmpty() {
      return this.loaded && !this.loading && this.items.length === 0;
    },

    /** Alpine lifecycle: start polling as soon as the header is on screen. */
    init() {
      whenIdle(() => this.poll());

      // Jitter keeps a crowd of sessions from refreshing in lockstep after a
      // deploy, which would otherwise spike the API on the same second.
      this._timer = setInterval(() => this.poll(), NOTIFICATION_POLL_MS + Math.random() * 5000);

      this._onResume = () => this.poll(true);
      document.addEventListener('visibilitychange', this._onResume);
      window.addEventListener('focus', this._onResume);
    },

    /** Alpine lifecycle: without this the listeners outlive the component. */
    destroy() {
      clearInterval(this._timer);
      this._timer = 0;
      if (this._onResume) {
        document.removeEventListener('visibilitychange', this._onResume);
        window.removeEventListener('focus', this._onResume);
        this._onResume = null;
      }
    },

    /**
     * One background tick. A hidden tab is not a user, and an open panel is
     * being read — repainting the list in either case costs more than it
     * gains, so the request is skipped and caught up on later.
     *
     * @param {boolean} resume Set on focus/visibility return, where a copy
     *   that missed ticks while the tab slept is worth replacing even if it
     *   is not yet a full poll interval old.
     */
    poll(resume = false) {
      if (document.hidden || this.open) {
        return;
      }
      const staleAfter = resume ? NOTIFICATION_RESUME_MS : NOTIFICATION_POLL_MS;
      if (this.loaded && Date.now() - this.fetchedAt < staleAfter) {
        return;
      }
      this.load();
    },

    toggle() {
      this.open = !this.open;
      if (this.open) {
        // Normally a no-op: the poller has the list ready. It only fires
        // when the warm-up has not landed yet (idle never ran, or the
        // first request was slower than the click).
        if (!this.loaded) {
          this.load();
        }
      } else {
        // Catch up in the background now that the panel is out of the way.
        this.poll();
      }
    },

    async load(force = false) {
      if (this._inflight) {
        return;
      }
      if (!force && this.loaded && Date.now() - this.fetchedAt < NOTIFICATION_POLL_MS) {
        return;
      }

      // `loading` drives the skeleton, so it is raised only when there is
      // nothing on screen yet. A background refresh has to stay invisible.
      const firstLoad = !this.loaded;
      this._inflight = true;
      if (firstLoad) {
        this.loading = true;
      }
      this.error = '';
      try {
        const result = await api('/api/notifications');
        if (!result.success || !result.data) {
          throw new Error(result.message || 'Request failed');
        }
        this.items = result.data.notifications || [];
        this.total = Number(result.data.total) || 0;
        this.unread = Number(result.data.unread) || 0;
        this.loaded = true;
        this.fetchedAt = Date.now();
      } catch {
        // A failed background tick must not replace a list the user can
        // already read, so the error only surfaces when we have nothing.
        if (firstLoad) {
          this.error = 'নোটিফিকেশন লোড করা যায়নি।';
        }
      } finally {
        this._inflight = false;
        if (firstLoad) {
          this.loading = false;
        }
      }
    },

    /** Optimistic: the item dims and the badge drops before the round trip. */
    async markRead(item) {
      if (item.read_at) {
        return;
      }
      const before = item.read_at;
      item.read_at = new Date().toISOString();
      this.unread = Math.max(0, this.unread - 1);

      const result = await api(`/api/notifications/${item.id}/read`, { method: 'PATCH' });
      if (!result.success) {
        item.read_at = before;
        this.unread += 1;
        return;
      }
      if (result.data && typeof result.data.unread === 'number') {
        this.unread = result.data.unread;
      }
    },

    async markAllRead() {
      if (this.unread === 0) {
        return;
      }
      const result = await api('/api/notifications/read-all', { method: 'POST' });
      if (!result.success) {
        return;
      }
      const stamp = new Date().toISOString();
      this.items = this.items.map((item) => (item.read_at ? item : { ...item, read_at: stamp }));
      this.unread = 0;
    },

    iconHref: (type) => `/assets/icons/lucide-sprite.svg#${NOTIFICATION_ICONS[type] || NOTIFICATION_ICONS.info}`,
    toneClass: (type) => NOTIFICATION_TONES[type] || NOTIFICATION_TONES.info,
    time: notificationTime,
  };
};
