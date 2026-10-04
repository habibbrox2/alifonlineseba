/* ============================================================
 * All Seba — service history: status changes without a reload.
 *
 * Two independent things happen here.
 *
 * 1. The user drives their own row (start / cancel / retry) through the JSON
 *    API. Each table row is its own Alpine component seeded from the JSON the
 *    server rendered into data-request.
 *
 * 2. Somebody *else* changes the row. An operator settles the request or
 *    uploads the deliverable from the admin desk while the user is sitting on
 *    this page, and the status badge and the download button have to move on
 *    their own. `watchRequests()` polls one batched endpoint for every visible
 *    row and re-applies only the answers that actually differ.
 *
 * Both paths go through the same `apply()` and the same server-built payload,
 * so a status can never render one way after a click and another way after a
 * poll.
 * ============================================================ */
import { api } from './app.js';

/** How often the visible rows are re-read. */
const WATCH_INTERVAL_MS = 12000;

/**
 * Random padding on the interval, as a fraction of it.
 *
 * Every open history page would otherwise poll in lockstep, and the requests
 * would arrive at the web server as a spike once an interval boundary is hit
 * rather than spread out. ±20% is enough to break the synchronisation without
 * making "live" feel sluggish.
 */
const WATCH_JITTER = 0.2;

/** Human-friendly text for a raw provider value. */
function displayValue(value) {
  if (value === null || value === undefined) return '—';
  if (Array.isArray(value)) return value.join(', ');
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

/**
 * Every live row on the page, keyed by transaction id.
 *
 * The poller needs to push a server answer back onto a specific Alpine
 * component, and the server answers keyed by id. Registering here at
 * construction time is what lets one batched request update fifteen rows
 * instead of the client having to re-derive which element is which.
 */
const liveRows = new Map();

window.thServiceRequest = function thServiceRequest(raw) {
  let row = {};
  try {
    row = JSON.parse(raw || '{}');
  } catch (e) {
    console.error('service-history: bad row payload', e);
  }

  const component = {
    id: row.id,
    status: row.status,
    label: row.status_label || row.status || '',
    badge: row.status_badge || 'badge-neutral',
    actions: Array.isArray(row.actions) ? row.actions : [],
    entries: Array.isArray(row.result_entries) ? row.result_entries : [],
    error: row.error || null,
    hasDeliverable: Boolean(row.has_deliverable),
    deliverableName: row.deliverable_name || null,
    initialUpdatedAt: row.updated_at || '',
    updatedAt: row.updated_at || '',

    /**
     * Set when the change came from the poller rather than from a click.
     *
     * It exists purely to word the message: a user who cancelled their own
     * request should see the API's own confirmation, whereas an operator's
     * edit needs to be announced as *someone else did this while you waited*.
     */
    externalChange: false,
    showResult: false,
    loading: null,
    confirming: null,
    notice: null,

    /** The streaming endpoint for this row's deliverable. */
    get fileUrl() {
      return `/service-requests/${this.id}/file`;
    },

    /** Re-derive the panel from an API row payload. */
    apply(data) {
      if (!data) return;
      this.status = data.status;
      this.label = data.status_label || data.status;
      this.badge = data.status_badge || 'badge-neutral';
      this.actions = Array.isArray(data.actions) ? data.actions : [];
      this.error = data.error || null;
      this.updatedAt = data.updated_at || this.updatedAt;
      this.hasDeliverable = Boolean(data.has_deliverable);
      this.deliverableName = data.deliverable_name || null;

      this.entries = Array.isArray(data.result_entries)
        ? data.result_entries
        : flattenResult(data.result);
    },

    async run(action) {
      if (!action || this.loading !== null) return;

      // The download is a navigation, not a request. The template renders it as
      // an <a>, but handling it here too means a row can never end up with a
      // button that silently toggles the result panel instead of fetching bytes.
      if (action.key === 'download') {
        window.location.href = this.fileUrl;
        return;
      }

      // "ফলাফল দেখুন" only reveals data already on this row.
      if (!action.remote) {
        this.showResult = !this.showResult;
        return;
      }

      // Cancelling refunds the user, so it takes a deliberate second click.
      if (action.key === 'cancel' && this.confirming !== action.key) {
        this.confirming = action.key;
        return;
      }

      this.confirming = null;
      this.loading = action.key;
      this.notice = null;
      this.externalChange = false;

      let response;
      try {
        response = await api(`/api/service-requests/${this.id}/${action.key}`, { method: 'POST' });
      } catch (e) {
        this.loading = null;
        this.notice = { kind: 'danger', text: 'সার্ভারের সাথে সংযোগ ব্যর্থ। আবার চেষ্টা করুন।' };
        return;
      }
      this.loading = null;

      if (response.status === 419 || response.status === 440) {
        // CSRF token went stale — a reload picks up a fresh one.
        window.location.reload();
        return;
      }

      if (!response.success) {
        this.notice = { kind: 'danger', text: response.message || 'অনুরোধটি সম্পন্ন হয়নি।' };
        return;
      }

      // Captured before apply() overwrites it, so the chip counts can be moved
      // from the status this row *left* to the one it arrived in.
      const previous = this.status;

      this.apply(response.data);
      this.notice = { kind: 'success', text: response.message || 'হালনাগাদ হয়েছে।' };

      // A retry that succeeded should show its result straight away.
      if (this.status === 'completed' && this.entries.length) {
        this.showResult = true;
      }

      // Keep the filter chips honest without a full page load.
      bumpChipCounts(previous, this.status);
    },

    /**
     * Apply a row that changed underneath us, and say so.
     *
     * @param {object} data      a row payload from the watch endpoint.
     * @param {boolean} gotFile  true when a deliverable appeared in this update.
     */
    applyExternal(data, gotFile) {
      const previous = this.status;

      this.apply(data);
      this.externalChange = true;

      this.notice = {
        kind: 'success',
        text: gotFile
          ? 'ফাইল প্রস্তুত — ডাউনলোড বাটনটি এখন চাপা যাবে।'
          : `অবস্থা পরিবর্তন হয়েছে: ${this.label}`,
      };

      // A request that completed while the user was away should show its result
      // rather than making them hunt for a button that just appeared.
      if (this.status === 'completed' && this.entries.length) {
        this.showResult = true;
      }

      bumpChipCounts(previous, this.status);
    },
  };

  if (component.id) {
    liveRows.set(String(component.id), component);
  }

  return component;
};

/** Fallback for a row that only carries the raw provider result. */
function flattenResult(result) {
  if (!result || typeof result !== 'object') return [];
  return Object.keys(result)
    .filter((key) => !key.startsWith('_') && result[key] !== null && result[key] !== undefined)
    .map((key) => ({ key, label: key, value: displayValue(result[key]) }));
}

/**
 * Move a settled request between the status chips.
 *
 * The row is only ever *changing* status, never added or removed, so the
 * "all" chip is the total and must stay put: the old status loses one and the
 * new status gains one.
 */
function bumpChipCounts(previous, next) {
  if (!previous || !next || previous === next) return;

  document.querySelectorAll('[data-count-status]').forEach((chip) => {
    const key = chip.getAttribute('data-count-status');
    const out = chip.querySelector('[data-count]');
    if (!out || key === 'all') return;

    const delta = key === next ? 1 : key === previous ? -1 : 0;
    if (delta === 0) return;

    out.textContent = String(Math.max(0, Number(out.textContent || 0) + delta));
  });
}

/* ============================================================
 * The poller
 * ============================================================ */

/** One timer for the whole page, and one request in flight at most. */
let watchTimer = null;
let watchBusy = false;

function jitteredInterval() {
  const spread = WATCH_INTERVAL_MS * WATCH_JITTER;
  return WATCH_INTERVAL_MS - spread + Math.random() * spread * 2;
}

function scheduleWatch() {
  if (watchTimer !== null) return;
  watchTimer = setTimeout(async () => {
    watchTimer = null;
    await pollOnce();
    scheduleWatch();
  }, jitteredInterval());
}

async function pollOnce() {
  // A hidden tab is not being looked at, and its throttle queue is not the
  // place to spend the user's battery. Skipping is safe: the next tick after
  // the tab comes back reads the current state anyway, because every row is
  // compared against what is on screen rather than against the last poll.
  if (watchBusy || document.hidden) return;

  const ids = Array.from(liveRows.keys());
  if (!ids.length) return;

  watchBusy = true;
  let response;
  try {
    response = await api('/api/service-requests/watch', {
      method: 'POST',
      body: { ids: ids.map(Number) },
    });
  } catch (e) {
    // Offline, or the server restarted. The next tick retries; saying so in
    // the console is enough, since a visible error for a background refresh
    // would alarm someone whose request is fine.
    console.debug('service-history: watch poll failed', e);
    watchBusy = false;
    return;
  }
  watchBusy = false;

  if (!response.success || !response.data || !response.data.rows) return;

  Object.keys(response.data.rows).forEach((id) => {
    const row = liveRows.get(String(id));
    const data = response.data.rows[id];
    if (!row || !data) return;

    // A row with a click in flight is mid-transition. Re-applying a poll
    // answer over it would race the POST's own response and could briefly
    // show a status the user never chose.
    if (row.loading !== null) return;

    // The whole point is to touch the DOM only when something actually moved.
    // Re-rendering fifteen identical rows every twelve seconds would also
    // throw away the result panel the user has open.
    const changedStatus = row.status !== data.status;
    const gotFile = !row.hasDeliverable && Boolean(data.has_deliverable);
    const lostFile = row.hasDeliverable && !data.has_deliverable;

    if (!changedStatus && !gotFile && !lostFile) return;

    row.applyExternal(data, gotFile);
  });
}

/**
 * Start watching, once the rows on the page have registered themselves.
 *
 * Called on DOMContentLoaded rather than immediately: the row components are
 * created by Alpine as it initialises, and a tick fired before that would ask
 * about zero rows and quietly do nothing.
 */
function startWatch() {
  if (document.querySelector('[data-watch-rows]') === null) return;
  if (watchTimer !== null) return;

  scheduleWatch();

  // Coming back to a tab that was throttled should show the current state
  // straight away rather than after the remainder of a stale interval.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) pollOnce();
  });
  window.addEventListener('focus', () => pollOnce());
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', startWatch);
} else {
  startWatch();
}
