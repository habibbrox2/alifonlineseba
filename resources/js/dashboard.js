/* ============================================================
 * TH Tools — dashboard interactions (Alpine.js data providers).
 * ============================================================ */
import { debounce, money } from './app.js';

window.thDashboard = function () {
  return {
    query: '',
    activeCategory: 'all',
    services: [],
    loading: false,
    money,

    async init() {
      const grid = document.getElementById('service-grid');
      if (grid) {
        this.services = JSON.parse(grid.getAttribute('data-services') || '[]');
      }
      window.addEventListener('keydown', (e) => {
        if (e.key === '/' && document.activeElement?.tagName !== 'INPUT') {
          e.preventDefault();
          this.$refs.search?.focus();
        }
      });
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
