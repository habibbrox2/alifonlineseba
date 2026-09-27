/* ============================================================
 * TH Tools — service history: status changes without a reload.
 *
 * Each table row is its own Alpine component seeded from the JSON the
 * server rendered into data-request. The API answers with the whole
 * re-rendered row (new status, label, badge and the buttons valid from
 * there on), so the client never has to guess the next state.
 * ============================================================ */
import { api } from './app.js';

/** Human-friendly text for a raw provider value. */
function displayValue(value) {
  if (value === null || value === undefined) return '—';
  if (Array.isArray(value)) return value.join(', ');
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

window.thServiceRequest = function thServiceRequest(raw) {
  let row = {};
  try {
    row = JSON.parse(raw || '{}');
  } catch (e) {
    console.error('service-history: bad row payload', e);
  }

  return {
    id: row.id,
    status: row.status,
    label: row.status_label || row.status || '',
    badge: row.status_badge || 'badge-neutral',
    actions: Array.isArray(row.actions) ? row.actions : [],
    entries: Array.isArray(row.result_entries) ? row.result_entries : [],
    error: row.error || null,
    initialUpdatedAt: row.updated_at || '',
    updatedAt: row.updated_at || '',
    showResult: false,
    loading: null,
    confirming: null,
    notice: null,

    /** Re-derive the panel from an API row payload. */
    apply(data) {
      if (!data) return;
      this.status = data.status;
      this.label = data.status_label || data.status;
      this.badge = data.status_badge || 'badge-neutral';
      this.actions = Array.isArray(data.actions) ? data.actions : [];
      this.error = data.error || null;
      this.updatedAt = data.updated_at || this.updatedAt;

      this.entries = Array.isArray(data.result_entries)
        ? data.result_entries
        : flattenResult(data.result);
    },

    async run(action) {
      if (!action || this.loading !== null) return;

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
  };
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
