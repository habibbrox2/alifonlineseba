/* ============================================================
 * All Seba — confirmation dialog.
 *
 * Replaces window.confirm() on the admin desks and the account pages.
 * Vanilla ES module, no dependency on Alpine: several of the call sites are
 * plain forms and inline scripts that would otherwise have to grow an Alpine
 * data object just to render a box.
 *
 * Two entry points:
 *
 *   1. Declarative — a `data-confirm` attribute on a form or a submit button.
 *      The value is either a plain string (the message) or a JSON object
 *      (the full set of options). Submission is held until the answer comes
 *      back, so the server sees the form exactly as it would have.
 *
 *   2. Programmatic — `window.thConfirm(options)` returns a Promise<boolean>.
 *      Needed where the message depends on state read at click time (the bulk
 *      bars count their own checkboxes).
 *
 * The template lives in components/confirm-dialog.twig and is cloned on first
 * use; see the note there on why it is a <template>.
 * ============================================================ */

/** Sprite glyph and button label per tone. */
const TONES = {
  // A delete: the record is gone.
  danger: { glyph: 'triangle-alert', confirm: 'মুছে ফেলুন' },
  // A ledger move — the figure is the point of the dialog.
  money: { glyph: 'banknote', confirm: 'নিশ্চিত করুন' },
  // Irreversible, but nothing is destroyed.
  warn: { glyph: 'circle-alert', confirm: 'হ্যাঁ, করুন' },
  // A routine state change that is still worth a pause.
  neutral: { glyph: 'circle-help', confirm: 'হ্যাঁ, করুন' },
};

const DEFAULT_TITLE = 'নিশ্চিত করবেন?';
const DEFAULT_CANCEL = 'বাতিল';
const SPRITE = '/assets/icons/lucide-sprite.svg#';

/** The live dialog, plus the pending answer and what to restore focus to. */
let dialog = null;
let settle = null;
let restoreFocusTo = null;

/**
 * Builds the dialog from the page's <template> on first use, and wires the
 * three ways it can be dismissed: the backdrop, Escape, and the buttons.
 */
function ensureDialog() {
  if (dialog) {
    return dialog;
  }

  const template = document.getElementById('th-confirm-template');
  if (!template) {
    // No template means no dialog. Degrade to the native one rather than
    // silently dropping the confirmation — these guard money and deletions.
    return null;
  }

  dialog = template.content.firstElementChild.cloneNode(true);
  document.body.appendChild(dialog);

  // Backdrop click dismisses, but a click that started inside the card and
  // ended on the backdrop (a text selection dragged outwards) should not.
  dialog.addEventListener('mousedown', (event) => {
    if (event.target === dialog) {
      dismiss(false);
    }
  });

  dialog.querySelector('[data-confirm-accept]').addEventListener('click', () => dismiss(true));
  dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => dismiss(false));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !dialog.hidden) {
      event.preventDefault();
      dismiss(false);
    }
  });

  return dialog;
}

/** Closes the dialog and answers whoever opened it. */
function dismiss(accepted) {
  if (!dialog || dialog.hidden || !settle) {
    return;
  }
  const resolve = settle;
  settle = null;
  dialog.hidden = true;
  document.body.style.removeProperty('overflow');
  resolve(accepted);
  // The button that opened the dialog keeps focus, so keyboard users are put
  // back where they were instead of at the top of the document.
  restoreFocusTo?.focus?.();
  restoreFocusTo = null;
}

/** Reads one `data-confirm` value into an options object. */
function readOptions(raw) {
  const value = (raw || '').trim();
  if (value.startsWith('{')) {
    try {
      return JSON.parse(value);
    } catch {
      // A malformed JSON blob must not become a silent no-op, so it degrades
      // to being the message itself rather than disappearing.
      return { message: value };
    }
  }
  return { message: value };
}

/**
 * Opens the dialog and resolves with the admin's answer.
 *
 * @param {string|object} options Message string, or
 *   `{title, message, tone, amount, amountLabel, bullets, confirm, cancel}`.
 * @returns {Promise<boolean>}
 */
export function thConfirm(options) {
  const opts = typeof options === 'string' ? { message: options } : (options || {});
  const el = ensureDialog();

  if (!el) {
    return Promise.resolve(window.confirm(opts.message || DEFAULT_TITLE));
  }

  const tone = TONES[opts.tone] ? opts.tone : 'danger';
  el.dataset.tone = tone;

  el.querySelector('[data-confirm-glyph]').setAttribute('href', SPRITE + TONES[tone].glyph);
  el.querySelector('[data-confirm-title]').textContent = opts.title || DEFAULT_TITLE;
  el.querySelector('[data-confirm-message]').textContent = opts.message || '';
  el.querySelector('[data-confirm-accept]').textContent = opts.confirm || TONES[tone].confirm;
  el.querySelector('[data-confirm-cancel]').textContent = opts.cancel || DEFAULT_CANCEL;

  // The amount band is the reason this component exists, so it is hidden only
  // when there is genuinely no figure to show.
  const amountBox = el.querySelector('[data-confirm-amount-box]');
  amountBox.hidden = !opts.amount;
  if (opts.amount) {
    el.querySelector('[data-confirm-amount]').textContent = opts.amount;
    el.querySelector('[data-confirm-amount-label]').textContent = opts.amountLabel || '';
  }

  const bullets = Array.isArray(opts.bullets) ? opts.bullets.filter(Boolean) : [];
  const list = el.querySelector('[data-confirm-bullets]');
  list.hidden = bullets.length === 0;
  list.replaceChildren(...bullets.map((text) => {
    const li = document.createElement('li');
    li.textContent = text;
    return li;
  }));

  restoreFocusTo = document.activeElement;
  dialog = el;
  el.hidden = false;
  // Stop the page behind the card from scrolling under the admin's cursor.
  document.body.style.overflow = 'hidden';
  // The card is two tab stops; Tab on the last one must cycle, not escape to
  // the page underneath, which is still in the tab order.
  el.addEventListener('keydown', trapTab);
  el.querySelector('[data-confirm-accept]').focus();

  return new Promise((resolve) => {
    settle = resolve;
  });
}

/** Keeps Tab inside the card while it is open. */
function trapTab(event) {
  if (event.key !== 'Tab') {
    return;
  }
  const focusable = dialog.querySelectorAll('button');
  if (focusable.length === 0) {
    return;
  }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

/**
 * Holds back a submission until the answer arrives, then lets it through.
 *
 * `requestSubmit(submitter)` rather than `submit()`: the buttons on these
 * desks carry `name="do" value="approve"`, and a bare submit() drops the
 * submitter, so the action would arrive blank. The guard flag stops the
 * second pass from opening a second dialog.
 */
let replaying = false;

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (replaying || !(form instanceof HTMLFormElement)) {
    return;
  }

  // A button's own message wins over the form's: a row can offer a delete that
  // the surrounding form does not confirm at all.
  const submitter = event.submitter;
  const source = submitter?.dataset?.confirm !== undefined ? submitter : form;
  const raw = source.dataset?.confirm;
  if (raw === undefined) {
    return;
  }

  event.preventDefault();

  thConfirm(readOptions(raw)).then((accepted) => {
    if (!accepted) {
      return;
    }
    replaying = true;
    form.requestSubmit(submitter || undefined);
    replaying = false;
  });
});

// The programmatic entry point for inline scripts and Alpine expressions, which
// are evaluated with the page as their scope rather than as a module.
window.thConfirm = thConfirm;

// Mount eagerly so the template is in the DOM before the first click, and so
// the very first confirmation does not pay for a layout shift.
if (typeof document !== 'undefined') {
  ensureDialog();
}