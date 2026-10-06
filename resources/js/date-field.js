/**
 * Date fields — the widget behind every `date` form row.
 *
 * The contract, in one place:
 *
 *   what a person sees and types  →  06-10-2026   (DD-MM-YYYY)
 *   what the row stores           →  2026-10-06   (Y-m-d)
 *
 * `App\Service\ServiceDate` is the server half of that contract and this file
 * is the client half; both accept the same three shapes (`Y-m-d`, `d-m-Y`,
 * `d/m/Y`) and both refuse an impossible date with `checkdate`-equivalent
 * arithmetic instead of rolling 31-02 into March. Nothing else in the app is
 * allowed to decide a date format — which is exactly why there is no
 * date-picker library here: a dependency would bring its own format, its own
 * locale rules and its own validation, and the promise above would become a
 * coincidence.
 *
 * No jQuery, no calendar component, no build step beyond the copy this file
 * already gets from `scripts/sync-js.js`:
 *
 * - typing is digits only, and the dashes are inserted for you (a phone's
 *   number pad has no hyphen key, which is the whole point of `inputmode`);
 * - the caret is put back where the digits left off, so editing the middle of
 *   a date works instead of jumping to the end;
 * - the calendar button is a real `<input type="date">` sitting transparently
 *   over the icon, so tapping it opens the OS picker on any phone and still
 *   works in a browser that has never heard of `showPicker()`;
 * - an invalid or half-typed date is reported through `setCustomValidity()`,
 *   which means the browser blocks the submit and shows the *same* Bengali
 *   message the server would have sent, in the same place.
 */

const ERROR_MESSAGE = 'সঠিক তারিখ লিখুন — DD-MM-YYYY (যেমন: 06-10-2026)।';

/** Only digits survive a keystroke, and never more than a year's worth. */
function digitsOf(value) {
    return String(value).replace(/\D+/g, '').slice(0, 8);
}

/** `06102026` → `06-10-2026`, one dash per group, nothing else. */
function format(value) {
    const d = digitsOf(value);
    if (d.length <= 2) {
        return d;
    }
    if (d.length <= 4) {
        return d.slice(0, 2) + '-' + d.slice(2);
    }
    return d.slice(0, 2) + '-' + d.slice(2, 4) + '-' + d.slice(4);
}

/**
 * Parse any shape the server accepts into `YYYY-MM-DD`, or null.
 *
 * The round trip through `Date` is the leap-year check: `new Date(2023, 1, 29)`
 * overflows to 1 March, so comparing the parts back tells 29 February 2023
 * apart from 29 February 2024 without a table of month lengths.
 */
function toIso(value) {
    const raw = String(value == null ? '' : value).trim();
    let m = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (m) {
        return verified(m[1], m[2], m[3]);
    }
    m = raw.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (m) {
        return verified(m[3], m[2], m[1]);
    }
    return null;
}

function verified(year, month, day) {
    const y = Number(year);
    const mo = Number(month);
    const d = Number(day);
    const probe = new Date(Date.UTC(y, mo - 1, d));
    if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d) {
        return null;
    }
    const pad = (n) => String(n).padStart(2, '0');
    return y + '-' + pad(mo) + '-' + pad(d);
}

/** `2026-10-06` → `06-10-2026`. Input here is already verified. */
function toDisplay(iso) {
    const parts = String(iso).split('-');
    return parts[2] + '-' + parts[1] + '-' + parts[0];
}

/**
 * The display form of any value: `2026-10-06` and `06-10-2026` both come
 * back as `06-10-2026`, and anything that is not a date comes back trimmed
 * and unchanged.
 *
 * The client half of `ServiceDate::display()`, exported for the pages that
 * print a stored value rather than type into a field — the "recent searches"
 * panel, a result entry the server sent without labels. Those used to render
 * `1990-05-04` to the person who had typed `05-06-1990`, which is the one
 * thing this whole feature exists to prevent.
 */
export function displayDate(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const iso = toIso(value);
    return iso === null ? String(value).trim() : toDisplay(iso);
}

/** Wire one `.date-field` wrapper. Safe to call twice on the same node. */
function wire(wrapper) {
    const input = wrapper.querySelector('[data-date-input]');
    if (!input || input.dataset.dateReady === '1') {
        return;
    }
    input.dataset.dateReady = '1';

    // An ISO value can arrive from a `?date_of_birth=1990-05-04` deep link or
    // from browser autofill; the field only ever shows the typed form.
    const initial = toIso(input.value);
    if (initial !== null && input.value !== toDisplay(initial)) {
        input.value = toDisplay(initial);
    }

    const validate = () => {
        const raw = input.value.trim();
        if (raw === '') {
            // Empty is "not answered" — the `required` attribute, not the
            // format, is what has to say something about that.
            input.setCustomValidity('');
            input.removeAttribute('aria-invalid');
            return;
        }
        if (toIso(raw) === null) {
            input.setCustomValidity(ERROR_MESSAGE);
            input.setAttribute('aria-invalid', 'true');
            return;
        }
        input.setCustomValidity('');
        input.removeAttribute('aria-invalid');
    };

    input.addEventListener('input', () => {
        // Count the digits before the caret, reformat, put the caret back
        // after the same number of digits: backspacing over a dash or typing
        // `0610` in the middle of another date both keep working.
        const caret = input.selectionStart == null ? input.value.length : input.selectionStart;
        const digitsBefore = (input.value.slice(0, caret).match(/\d/g) || []).length;

        input.value = format(input.value);

        let seen = 0;
        let at = digitsBefore === 0 ? 0 : input.value.length;
        if (digitsBefore > 0) {
            for (let i = 0; i < input.value.length; i++) {
                if (/\d/.test(input.value[i])) {
                    seen++;
                }
                if (seen === digitsBefore) {
                    at = i + 1;
                    break;
                }
            }
        }
        try {
            input.setSelectionRange(at, at);
        } catch (err) { /* some inputs refuse; the value is still right */ }

        // Re-check only while an error is standing, so a half-typed date is
        // never flagged mid-keystroke but clears the moment it becomes real.
        if (input.validationMessage) {
            validate();
        }
    });

    input.addEventListener('blur', validate);

    // ——— The calendar ———
    // A real native date input, transparent, covering only the icon. Tapping
    // it opens the OS picker (wheel on iOS, calendar on Android and desktop)
    // with no library and no `showPicker()` support check; its `change` comes
    // back as `YYYY-MM-DD` and is the only way a picked date reaches the field.
    const picker = document.createElement('span');
    picker.className = 'date-field-picker';

    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '2');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<rect width="18" height="18" x="3" y="4" rx="2"></rect>'
        + '<path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h21"></path>';

    const native = document.createElement('input');
    native.type = 'date';
    native.className = 'date-field-native';
    // Decorative duplicate of the text field: it must not be tabbed into and
    // must not be announced twice by a screen reader.
    native.tabIndex = -1;
    native.setAttribute('aria-hidden', 'true');

    picker.appendChild(icon);
    picker.appendChild(native);
    wrapper.appendChild(picker);

    // Open on the date already typed rather than on today.
    picker.addEventListener('pointerdown', () => {
        const iso = toIso(input.value);
        native.value = iso === null ? '' : iso;
    });

    native.addEventListener('change', () => {
        const iso = toIso(native.value);
        if (iso === null) {
            return;
        }
        input.value = toDisplay(iso);
        validate();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    validate();
}

/**
 * Wire every date field on the page.
 *
 * Called once from `layouts/base.twig`; the fields themselves are rendered by
 * Twig, so there is nothing later to observe.
 */
export function initDateFields(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-date-field]').forEach(wire);
}
