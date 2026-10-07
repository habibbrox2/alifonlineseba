/* ============================================================
 * All Seba — dashboard interactions (Alpine.js data providers).
 * ============================================================ */
import { money } from './app.js';

/** Palette shows at most this many rows — a command palette is a
 *  shortlist, not the catalogue. The full list lives behind Enter. */
const PALETTE_LIMIT = 8;

/**
 * Kept outside the component so Alpine's reactive proxy never wraps it — an
 * observer handed to Alpine becomes a proxied object, and the DOM handle it
 * carries is not data.
 */
let moreObserver = null;

window.thDashboard = function () {
  return {
    activeCategory: 'all',
    services: [],
    // True until the catalogue lands, so the grid says "loading" (as
    // skeleton cards) rather than "no services" while the fetch is in flight.
    loading: true,
    // A failed fetch is not an empty catalogue, and the two must not read the
    // same on screen.
    failed: false,
    // How much of `filtered` is rendered. 3 135 <article> elements at once is
    // a multi-megabyte DOM the browser has to lay out before the first scroll,
    // and nobody reaches the end of a service hub anyway. The *filtering*
    // still runs over the whole catalogue — only drawing is windowed.
    page: 1,
    pageSize: 12,
    // The next window is appended after a beat, not instantly: the skeleton
    // cards (see the grid markup) need a frame or two on screen for "more
    // services are coming" to register as motion rather than a layout jump.
    loadingMore: false,

    // Command palette (Ctrl+K or /): a floating search over the whole
    // catalogue that jumps straight to a service, so the grid below can stay
    // a browsable shortlist instead of every card the database has.
    paletteOpen: false,
    paletteQuery: '',
    paletteIndex: 0,

    money,

    async init() {
      // A new chip restarts the window: a category with fewer services must
      // not inherit a page number that overruns it.
      this.$watch('activeCategory', () => {
        this.page = 1;
      });
      // Typing in the palette restarts the highlight — results reorder, and
      // keeping an index that may now point past the end is a stale selection.
      this.$watch('paletteQuery', () => {
        this.paletteIndex = 0;
      });

      window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
          e.preventDefault();
          this.paletteOpen ? this.closePalette() : this.openPalette();
          return;
        }
        // "/" is the classic shortcut, but only outside a field that wants it.
        if (
          e.key === '/' &&
          !this.paletteOpen &&
          !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
        ) {
          e.preventDefault();
          this.openPalette();
        }
      });

      await this.loadServices();

      // After the first paint: the sentinel only exists once the grid has
      // something to page through, and $nextTick is what guarantees its refs
      // are mounted by the time we ask for them.
      this.$nextTick(() => this.watchForMore());
    },

    /**
     * Fetch the catalogue instead of reading it off the DOM.
     *
     * It used to live in a `data-services` attribute, which cost every
     * dashboard render ~2.7 MB of HTML before the first card could draw.
     * `/api/catalog` answers with the same rows — eight columns a card uses
     * rather than the whole table — and only when the grid actually boots.
     */
    async loadServices() {
      this.loading = true;
      this.failed = false;

      try {
        const response = await fetch('/api/catalog', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
          throw new Error(`catalogue request failed: HTTP ${response.status}`);
        }

        const rows = (await response.json())?.data?.services;
        if (!Array.isArray(rows)) {
          throw new Error('catalogue response had no services in it');
        }

        this.services = rows;
      } catch (error) {
        // Logged, not swallowed: an empty grid and a broken request must stay
        // distinguishable in the console as well as on the page.
        console.error(error);
        this.services = [];
        this.failed = true;
      } finally {
        this.loading = false;
      }
    },

    /**
     * Load the next window as the "see more" button comes near the viewport,
     * so scrolling reaches the end without a click. Browsers without
     * IntersectionObserver simply keep the button — same feature, one click
     * per window.
     */
    watchForMore() {
      const sentinel = this.$refs.more;
      if (!sentinel || typeof IntersectionObserver === 'undefined') {
        return;
      }

      moreObserver?.disconnect();
      moreObserver = new IntersectionObserver(
        (entries) => {
          if (entries.some((entry) => entry.isIntersecting)) {
            this.loadMore();
          }
        },
        // Start the next window before the reader hits the bottom — loading
        // after the last card is visible reads as a stalled page.
        { rootMargin: '600px 0px' },
      );
      moreObserver.observe(sentinel);
    },

    /**
     * Append the next window of cards — but visibly. The skeleton cards
     * rendered while `loadingMore` is true are what tell the reader the grid
     * is growing; without that beat the cards just pop in and the scroll
     * feels like a hiccup. The observer can fire again while the timeout is
     * pending, so the flag doubles as a re-entry guard.
     */
    loadMore() {
      if (!this.hasMore || this.loadingMore) {
        return;
      }

      this.loadingMore = true;
      window.setTimeout(() => {
        this.page += 1;
        this.loadingMore = false;
      }, 400);
    },

    // ——— Command palette ————————————————————————————————————————————

    openPalette() {
      this.paletteOpen = true;
      this.paletteQuery = '';
      this.paletteIndex = 0;
      // The input must exist before it can take focus; $nextTick is what
      // guarantees Alpine has mounted the overlay.
      this.$nextTick(() => this.$refs.paletteInput?.focus());
    },

    closePalette() {
      this.paletteOpen = false;
    },

    /** Arrow-key navigation, wrapping at both ends of the shortlist. */
    paletteMove(delta) {
      const count = this.paletteResults.length;
      if (count === 0) {
        return;
      }
      this.paletteIndex = (this.paletteIndex + delta + count) % count;

      // Keep the highlighted row on screen without scrolling the page itself.
      this.$nextTick(() => {
        this.$refs.paletteList
          ?.querySelector('[aria-selected="true"]')
          ?.scrollIntoView({ block: 'nearest' });
      });
    },

    paletteChoose() {
      const service = this.paletteResults[this.paletteIndex];
      if (service) {
        window.location.href = '/services/view/' + service.slug;
      }
    },

    /**
     * The palette's shortlist. Empty query shows the head of the catalogue
     * ("popular services" for practical purposes) so opening the palette
     * with nothing typed is a menu, not a void.
     */
    get paletteResults() {
      const q = this.paletteQuery.trim().toLowerCase();
      const pool =
        q === ''
          ? this.services
          : this.services.filter(
              (s) =>
                (s.name || '').toLowerCase().includes(q) ||
                (s.description || '').toLowerCase().includes(q),
            );
      return pool.slice(0, PALETTE_LIMIT);
    },

    /**
     * How many skeleton cards the grid draws right now: eight while the
     * catalogue fetch is in flight, four while the next window appends,
     * nothing at all when the grid is settled. Returning 0 from a getter is
     * what an `x-for="i in skeletons"` range of zero renders — no wrapper
     * `x-show` needed on a `<template>`, which has no display to toggle.
     */
    get skeletons() {
      if (this.loading) {
        return 8;
      }
      return this.loadingMore ? 4 : 0;
    },

    /** Grid rows after the category chips have had their say. */
    get filtered() {
      return this.activeCategory === 'all'
        ? this.services
        : this.services.filter((s) => s.category_slug === this.activeCategory);
    },

    /** The slice actually drawn. Never longer than the filtered list. */
    get visible() {
      return this.filtered.slice(0, this.page * this.pageSize);
    },

    /** How much of the matching catalogue is still undrawn. */
    get remaining() {
      return Math.max(0, this.filtered.length - this.visible.length);
    },

    get hasMore() {
      return this.remaining > 0;
    },
  };
};
