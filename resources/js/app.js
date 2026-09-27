/* ============================================================
 * TH Tools — shared frontend utilities (vanilla ES module).
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
