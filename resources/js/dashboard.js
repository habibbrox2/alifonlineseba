/* ============================================================
 * All Seba — dashboard interactions (Alpine.js data providers).
 * ============================================================ */
import { debounce, money } from './app.js';

window.thDashboard = function () {
  return {
    query: '',
    activeCategory: 'all',
    services: [],
    // True until the catalogue lands, so the grid says "loading" rather than
    // "no services" during the window the fetch is in flight.
    loading: true,
    // A failed fetch is not an empty catalogue, and the two must not read the
    // same on screen.
    failed: false,
    money,

    async init() {
      window.addEventListener('keydown', (e) => {
        if (e.key === '/' && document.activeElement?.tagName !== 'INPUT') {
          e.preventDefault();
          this.$refs.search?.focus();
        }
      });

      await this.loadServices();
    },

    /**
     * Fetch the catalogue instead of reading it off the DOM.
     *
     * It used to live in a `data-services` attribute, which cost every
     * dashboard render ~2.7 MB of HTML before the first card could draw.
     * `/api/catalog` answers with the same rows — seven columns a card uses
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

    get filtered() {
      const q = this.query.trim().toLowerCase();
      return this.services.filter((s) => {
        const inCategory = this.activeCategory === 'all' || s.category_slug === this.activeCategory;
        const matches =
          q === '' ||
          (s.name || '').toLowerCase().includes(q) ||
          (s.description || '').toLowerCase().includes(q);
        return inCategory && matches;
      });
    },
  };
};
