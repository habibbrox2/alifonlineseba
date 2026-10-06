/* ============================================================
 * Reorderable list for the admin "form field configuration".
 *
 * The whole arrangement is carried by one hidden `field_order[]`
 * input *inside* each row, so nothing here has to keep a model in
 * sync with the DOM: moving a row element moves its name along
 * with it, and the browser serialises the form in document order.
 * That is the reason this file is as small as it is — there is no
 * state to reconcile, and no way for the two to disagree.
 *
 * Two ways in, because a drag handle alone is not an interface:
 *
 *   - Pointer: the native HTML5 drag events, with `draggable` set
 *     only on the handle. Dragging is opt-in from the handle rather
 *     than from the row, because a row contains text inputs, and a
 *     draggable row makes selecting a label or moving the caret
 *     inside one impossible.
 *   - Keyboard: the up/down buttons on each row, for anyone not
 *     using a pointer, and for anyone whose browser drops a drag.
 *
 * Both end up calling `move()`. Without JavaScript the list is
 * still correct and still submittable — the order is simply the
 * order the server rendered, which is the order the fields were
 * already in. Dragging is an addition to that, never a
 * requirement for it.
 * ============================================================ */

const ROW = '[data-sortable-row]';

/** The row an event happened inside, or null when it hit the gaps between rows. */
function rowOf(node) {
  return node && node.closest ? node.closest(ROW) : null;
}

window.fieldSorter = function fieldSorter() {
  return {
    /** The row being dragged right now; null when nothing is. */
    dragging: null,

    /**
     * The browser starts a drag only when some data is attached, and
     * Firefox drops the operation entirely if there is none — so this
     * is not decoration.
     */
    start(event) {
      const row = rowOf(event.target);
      if (!row) {
        return;
      }

      this.dragging = row;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', row.dataset.sortableRow);
      // Drag the whole card, not the little grip inside it.
      event.dataTransfer.setDragImage(row, 20, 20);
    },

    /**
     * Reorder live, as the pointer moves, rather than waiting for the
     * drop. A list that only jumps when you let go gives no sense of
     * where the row is heading, which is most of what makes dragging
     * feel like guessing.
     *
     * The hovered row's midpoint decides the side: above it the dragged
     * row lands before it, below it after. `preventDefault()` is what
     * marks this element as a valid drop target at all.
     */
    over(event) {
      if (!this.dragging) {
        return;
      }

      const target = rowOf(event.target);
      if (!target || target === this.dragging) {
        return;
      }

      const box = target.getBoundingClientRect();
      const after = event.clientY > box.top + box.height / 2;
      target.parentNode.insertBefore(this.dragging, after ? target.nextSibling : target);
    },

    drop(event) {
      // The move has already happened in `over()`; all that is left is
      // to make sure a browser that ignored it does not also act on
      // the default (which would drop the row text into the page).
      event.preventDefault();
      this.end();
    },

    end() {
      this.dragging = null;
    },

    /** Move a row one place up, or down, and report when there is no room. */
    step(row, direction) {
      if (!row) {
        return;
      }

      const sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
      if (!sibling || !rowOf(sibling)) {
        return;
      }

      if (direction < 0) {
        row.parentNode.insertBefore(row, sibling);
      } else {
        row.parentNode.insertBefore(sibling, row);
      }

      // Focus follows the row the admin just moved, or the next Tab
      // would resume from the top of the form and re-walking the list
      // to carry on would be the only way to get back to it.
      const button = row.querySelector(direction < 0 ? '[data-sortable-up]' : '[data-sortable-down]');
      if (button) {
        button.focus();
      }
    },
  };
};

// `fieldSorter` is used as a plain global in x-data rather than through
// Alpine.data(), exactly like iconPicker: the file is included with `defer`
// ahead of Alpine, so the global is there when the component is evaluated.
// This listener is the safety net for the day that stops being true.
document.addEventListener('alpine:init', () => {
  if (window.Alpine && !window.fieldSorterRegistered) {
    window.Alpine.data('fieldSorter', window.fieldSorter);
    window.fieldSorterRegistered = true;
  }
});