/* ============================================================
 * Lucide icon picker (Alpine.js data provider) for the admin forms.
 * ============================================================
 *
 * Backs components/icon-picker.twig. Lets an admin browse the whole Lucide set
 * and click a glyph to use it, instead of typing a kebab-case name from memory
 * and hoping it matches a file on disk.
 *
 * Three decisions worth knowing before editing this:
 *
 * 1. The `<input>` is a real form field bound with x-model, and this component
 *    only ever writes to it. If the index fails to load, JS is off, or the whole
 *    bundle 404s, the form still submits exactly what was typed — the picker is
 *    an enhancement over a plain text box, never a replacement for one.
 *
 * 2. The index is fetched on first open, not on page load. It is ~140 KB of
 *    JSON (35 KB gzipped) and no admin page needs it until someone actually
 *    wants to pick something.
 *
 * 3. The grid renders `limit` tiles and grows on demand rather than drawing all
 *    1636 at once. Each tile is an <svg><use>, so the full set is ~6500 DOM
 *    nodes and thousands of symbol lookups; chunking keeps the open animation
 *    instant and the search box responsive. Every icon is still reachable — the
 *    grid is not truncated, only paged.
 */

/** Tiles drawn per chunk. Roughly six rows of the six-column grid. */
const TILE_CHUNK = 360;

/** A search narrow enough to fit this many results renders all of them. */
const NO_MORE_THRESHOLD = 400;

window.iconPicker = function (indexUrl, spriteUrl, initial = '') {
  return {
    value: initial,
    open: false,
    query: '',
    limit: TILE_CHUNK,

    /** Canonical entries as {n, t} once the index has been fetched. */
    icons: [],
    /** Flattened search haystacks, built once per load: {n, name, tags}. */
    search: [],
    /** Just the names, in index order. `search` entries, not entries. */
    names: [],
    /** Every renderable name, canonical + deprecated aliases. */
    valid: new Set(),
    /** Deprecated alias => canonical icon it renames, from the index's `aliasOf`. */
    aliasOf: {},

    loaded: false,
    loading: false,
    error: '',

    /** Memo for `filtered`, which one render reads half a dozen times. */
    _filterKey: null,
    _filterList: [],

    get indexUrl() {
      return indexUrl;
    },
    get spriteUrl() {
      return spriteUrl;
    },

    /** Alpine lifecycle. Nothing is fetched until the panel is opened. */
    init() {
      // Re-paginate on every new search so a wide query still starts small —
      // and skip the pager entirely once a search has narrowed things down,
      // because a "load more" button under twelve results is just noise.
      this.$watch('query', () => {
        const n = this.filtered.length;
        this.limit = n > 0 && n <= NO_MORE_THRESHOLD ? n : TILE_CHUNK;
      });
    },

    async openPanel() {
      this.open = true;
      await this.load();
      this.$nextTick(() => this.$refs.search?.focus());
    },

    close() {
      this.open = false;
      this.query = '';
      this.limit = TILE_CHUNK;
    },

    async load() {
      if (this.loaded || this.loading) {
        return;
      }
      if (!indexUrl) {
        // Build artefacts missing — `php yii` and the unit tests hit this.
        // Leave the field usable as a plain text box.
        this.error = 'আইকন লিস্ট পাওয়া যায়নি। নামটি নিজে লিখুন।';
        return;
      }

      this.loading = true;
      this.error = '';
      try {
        const res = await fetch(indexUrl, { headers: { Accept: 'application/json' } });
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        const data = await res.json();

        // generate-sprite.php encodes `icons` as a name → tags map rather than
        // an array of objects: the name is the key, so it is never repeated.
        const raw = data?.icons && typeof data.icons === 'object' ? data.icons : {};
        const icons = Object.entries(raw)
          .filter(([name, tags]) => name && Array.isArray(tags))
          .map(([n, t]) => ({ n, t }));
        const aliases = Array.isArray(data?.aliases) ? data.aliases : [];
        const aliasOf = data?.aliasOf && typeof data.aliasOf === 'object' ? data.aliasOf : {};

        this.icons = icons;
        this.search = icons
          .filter((e) => e && typeof e.n === 'string' && e.n)
          .map((e) => ({
            n: e.n,
            name: e.n.toLowerCase(),
            tags: (Array.isArray(e.t) ? e.t : []).join(' ').toLowerCase(),
          }));
        this.names = this.search.map((e) => e.n);
        this.valid = new Set([...this.names, ...aliases]);
        this.aliasOf = aliasOf;
        this.loaded = true;
      } catch (err) {
        this.error = 'আইকন লিস্ট লোড করা যায়নি। নামটি নিজে লিখুন।';
      } finally {
        this.loading = false;
      }
    },

    /**
     * Rank matches so the obvious answer is first.
     *
     * 0 exact name · 1 name prefix · 2 name substring · 3 search tag
     *
     * Name-before-tag matters: searching "arrow" should put `arrow-right` at the
     * top rather than alphabetically after every icon merely tagged "arrow".
     */
    rank(entry, q) {
      if (entry.name === q) return 0;
      if (entry.name.startsWith(q)) return 1;
      if (entry.name.includes(q)) return 2;
      if (entry.tags.includes(q)) return 3;
      return -1;
    },

    /**
     * Cached `filtered`, because a single render reads it from the empty
     * state, the grid, the count and `hasMore` — six full passes over 1636
     * entries per keystroke otherwise.
     */
    computeFiltered() {
      const q = this.loaded ? this.query.trim().toLowerCase() : '';
      const key = this.loaded ? q : null;
      if (this._filterKey === key) {
        return this._filterList;
      }

      let list;
      if (q === '') {
        // Names, not the {n, name, tags} entries: `filtered` is what the grid
        // binds to `:key`, `:title` and `spriteHref()`, so handing back objects
        // renders every tile's title as raw JSON and points every glyph at
        // "...#[object Object]".
        list = this.names;
      } else {
        list = this.search
          .map((e) => ({ e, r: this.rank(e, q) }))
          .filter((x) => x.r >= 0)
          .sort((a, b) => a.r - b.r || (a.e.name < b.e.name ? -1 : a.e.name > b.e.name ? 1 : 0))
          .map((x) => x.e.n);
      }

      this._filterKey = key;
      this._filterList = list;
      return list;
    },

    get filtered() {
      return this.computeFiltered();
    },

    get visible() {
      return this.filtered.slice(0, this.limit);
    },

    get hasMore() {
      return this.filtered.length > this.limit;
    },

    get remaining() {
      return Math.max(0, this.filtered.length - this.limit);
    },

    loadMore() {
      this.limit += TILE_CHUNK;
    },

    /** Total icons in the library, for the "showing n of m" readout. */
    get total() {
      return this.loaded ? this.names.length : 0;
    },

    /**
     * The current value resolved to a canonical name, or null if it has none.
     *
     * Tiles are titled with canonical names only, so a category saved years ago
     * as `grid` — a deprecated alias of `grid-3x3` — would otherwise open the
     * picker with every tile inactive and look like the value had been lost.
     * It has not: the sprite still draws `grid` fine. Mapping it forward lets
     * the right tile light up, and the next click quietly upgrades the row to
     * the current name.
     */
    get selected() {
      const v = this.value.trim();
      if (!v) {
        return null;
      }
      const canonical = this.aliasOf[v];
      return typeof canonical === 'string' ? canonical : v;
    },

    choose(name) {
      this.value = name;
      this.close();
      this.$nextTick(() => this.$refs.input?.focus());
    },

    spriteHref(name) {
      return spriteUrl + '#' + name;
    },

    /**
     * Whether the typed name can actually be drawn.
     *
     * Null while the index is unloaded, because "unknown" is not the same as
     * "not checked yet" — a warning that appears and then vanishes once the
     * index lands would be worse than no warning.
     */
    get unknown() {
      const v = this.value.trim();
      if (v === '' || !this.loaded) {
        return null;
      }
      return this.valid.has(v) ? null : v;
    },

    /** Resets a red-tinted field back to normal once the typo is cleared. */
    get fieldState() {
      return this.unknown ? ' icon-picker-input--invalid' : '';
    },
  };
};

// Safety net, not the primary mechanism: the component loads this file with
// `defer` ahead of Alpine, so `alpine:init` is the expected path. Registering
// through it as well means a provider is always visible to x-data even if this
// file is ever bundled or injected somewhere the document order is inverted.
document.addEventListener('alpine:init', () => {
  if (window.Alpine && !window.iconPickerRegistered) {
    window.Alpine.data('iconPicker', window.iconPicker);
    window.iconPickerRegistered = true;
  }
});
