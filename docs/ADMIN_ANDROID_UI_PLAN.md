# Alif Tools Admin Android — UI Layout & Visual System Plan

**Status:** Design contract **v1.0 — locked 2026-10-29** (§0). Phases 1–3 are implemented; progress lives in [`ADMIN_ANDROID_UI_CHANGELOG.md`](ADMIN_ANDROID_UI_CHANGELOG.md), not in this file.
**Companion:** `docs/ADMIN_ANDROID_ARCHITECTURE.md` (system, API, security), `docs/reference-ui-analysis.md` (observed web structure), `resources/css/app.css` (web design tokens).
**Visual board:** `docs/design/admin-app-ui-board.html` — the same tokens and layouts rendered as phone frames for review.
**Implementation log:** [`ADMIN_ANDROID_UI_CHANGELOG.md`](ADMIN_ANDROID_UI_CHANGELOG.md) — phase status, what shipped, deviations, next phase.

---

## 0. Design contract — locked v1.0

This document is the **authority** for the Android admin UI, and it is intentionally frozen so later work cannot drift into a different-looking app. Read this section before touching any screen.

**Locked on:** 2026-10-29 · **Version:** 1.0 · **Reviewed against:** `docs/design/admin-app-ui-board.html`

### 0.1 What is frozen

| Area | Frozen value | Section |
|---|---|---|
| Palette | Every hex in the emerald/gold ramp, neutral surfaces and the six status tones | §4.1–4.2 |
| Typography | Hind Siliguri only; the 15-role Bengali scale; 12sp floor; `MoneyStyle` at 15sp/700 tabular | §4.3 |
| Destination model | 15 destinations, Bengali labels, the four web sidebar groups | §3.1 |
| Tabs | Max 5 slots, **হোম first, আরও last**, stable per role; active tab keeps its label | §3.2 |
| Chrome | One emerald gradient top bar; tab roots have no back arrow; sheets for money actions | §3.3 |
| Component anatomy | The kit surfaces in §5 (token-driven, no screen-local variants) | §5 |
| Status vocabulary | Bengali label + tone + in-flight flag per status, mirroring `StatusPresenter` | §4.1, §6.3 |
| Money | `৳ 1,250.00`, Latin digits, tabular figures, 2 decimals, right-aligned in lists | §4.3 |
| Shape & density | 20dp sheets, 14dp cards, 12dp rows, 4dp spacing grid, 48dp touch targets | §4.4–4.5 |
| Accessibility floor | 48dp targets, 12sp body floor, contrast ≥4.5:1, reduce-motion respected | §8 |

### 0.2 What may still change inside a phase

Screen copy wording, list ordering when the API dictates it, icon substitutions from the bundled Lucide-style set, spacing adjusted within ±4dp to fix real overflow, empty/error text, and the content of a screen that the phase owns. These are **not** contract changes.

### 0.3 Change protocol (no ad-hoc design)

1. **Stop.** A change to anything in §0.1 is a contract change, not a screen fix.
2. **Write it down.** Add an entry to the changelog (`§4` there) with: old value → new value, the screen/state that proved the old value wrong, and the affected screens.
3. **Bump the version** here (1.0 → 1.1) and update the board in the same change.
4. **Only then** update tokens/code. A PR that adds a hex, a font family or a status colour in a screen file is a defect even if it looks good.
5. New shared component ⇒ add it to §5 and the changelog first; one-off composables stay private to their screen.

### 0.4 Token ownership in code

| Contract | Must be read from |
|---|---|
| Colours, status tones | `ui/theme/Color.kt` (`MaterialTheme.colorScheme`, `LocalAlifColors`) |
| Type, money styles | `ui/theme/Type.kt` |
| Radii, spacing, sizes | `ui/theme/Shape.kt` (`Shapes`, `Dimens`) |
| Tabs, role gating | `ui/navigation/RoleCapabilities.kt` |
| Status label/tone | `ui/model/StatusStyle.kt` (`Statuses`) |
| Amounts | `ui/format/Money.kt` (`Money.format`, `MoneyText`) |

### 0.5 Where progress is recorded

Phase status, shipped components, deviations with reasons, verification results and the next phase's checklist live **only** in [`ADMIN_ANDROID_UI_CHANGELOG.md`](ADMIN_ANDROID_UI_CHANGELOG.md). §11 below describes the phases; it does not track them.

---

## 1. Design brief

| | |
|---|---|
| **Product** | Native admin console for the Digital Sheba / Alif Tools platform |
| **Users** | Staff, admin, superadmin. Shifts are long; the phone is held one-handed in a shop, often in poor light. |
| **Job to be done** | Answer two questions in under three seconds: *what needs me now?* and *what happened to this order?* Then act — approve, reject, settle, search a user — without a desktop. |
| **Language** | Bengali UI, Latin digits everywhere and `৳` for money (the server's `money` filter emits `৳ 1,250.00`). |
| **Platform** | Kotlin + Compose + Material 3, minSdk 26. Phone first, tablet second. |
| **Authority** | Server is the single source of truth for prices, balances, roles and approvals. The UI may only present and confirm. |

### What the web frontend already establishes (evidence, not assumptions)

| Web source | What it fixes in the brand |
|---|---|
| `tailwind.config.js` | Emerald `#0d8f68` primary ramp (50→900), gold `#f5b301` accent, green-tinted shadows, 14/18px card radii |
| `resources/css/app.css` | `surface-100` page, white cards with 1px neutral border, pill badges, `.nav-link-active` **3px left rail + tinted row**, `.chip` / `.chip-active`, `.badge-loading` spinner inside in-flight statuses, `.skeleton` shimmer |
| `resources/views/layouts/dashboard.twig` | Emerald gradient top bar (`primary-900 → 700`), balance chip, bell with gold count dot, avatar menu; sidebar on desktop, drawer on mobile |
| `resources/views/partials/sidebar.twig` | Four permission-filtered groups: **পরিচালনা / অর্থনীতি / সেটিংস / নিরীক্ষণ**; groups start collapsed unless they hold the current page |
| `resources/views/site/admin/index.twig` | Header + description, 4 stat cards, one full-width **"work"** card for unresolved recharges (count + money + pending/review split), quick-action card grid, recent-activity table |
| `resources/views/components/*` | `page-header`, `stat-card` (48dp tinted icon tile + big number + small label), `status-badge`, `table`, `confirm-dialog` (tone medallion + amount band + bullet list) |
| `src/Service/StatusPresenter.php` | Canonical Bengali status labels and tones: pending=warning, processing/review=info, completed/approved/paid/active=success, failed/cancelled/rejected/deleted=danger, disabled/inactive=neutral |

### What the Android app looks like today (the gap this plan closes)

| Observation | File | Consequence |
|---|---|---|
| Dynamic colour is **on** by default | `ui/theme/Theme.kt` | On Android 12+ the app borrows the wallpaper palette, so nothing ties it to the emerald/gold brand |
| No bottom navigation or drawer | `ui/navigation/AdminNavGraph.kt`, `MainActivity.kt` | Eight destinations are buttons on one dashboard; six (categories, services, ledger, referrals, role hierarchy, permissions matrix) have callbacks passed in but no control, so they are currently unreachable |
| Dashboard = 2×2 metrics + 8 identical buttons | `ui/screens/dashboard/DashboardScreen.kt` | No queue-first entry point, no icons, no counts on actions, no priority |
| Error card has no scaffold padding; dead button "ত্রুটির বিচার" | same file | Draws under the status bar; an action that does nothing |
| Lists share one generic shell with `FilterChip` + `OutlinedTextField` | `ui/components/PagedListView.kt` | Consistent, but visually unrelated to the site; rows are plain M3 cards |
| Status is mapped ad hoc per screen | `ui/screens/recharges/RechargesScreen.kt` | Same status gets different wording/colour on different screens |
| No money formatter; amounts inline as `৳${row.amount}` | same file | No thousands separator, no tabular figures, no alignment |
| No bundled Bengali font; Material default | `res/`, `Theme.kt` | Bengali renders in Roboto/Noto fallback at inconsistent weight — the single biggest "not our brand" tell |
| 47 Bengali keys exist in `res/values/strings.xml`, but no screen calls `stringResource` | all screens | The resource file is dead weight today; the plan uses it for shared chrome and keeps screen copy inline for now (see §12.1) |
| `docs/ADMIN_ANDROID_ARCHITECTURE.md` still says "English-first UI" | doc §1.7 | Stale; the app is Bengali-first in practice |

---

## 2. Design direction — "কর্মডেস্ক" (Work Desk)

The website is a **storefront**: promos, hero banner, category chips, service cards. The admin app is a **work desk**. Copying the storefront wholesale would give an operator a pretty catalogue and no queue. So the plan borrows the brand's material language — deep emerald chrome, gold as attention, white cards on green-grey, 14–18px radii, pill badges, the 3px active rail, Bengali typesetting — and spends it on a queue-first information architecture.

**Three principles**

1. **The queue is the homepage.** The first thing under the greeting is live counts of work, with money attached. Metrics come second; navigation comes third.
2. **One accent, one action.** Emerald is chrome and the single primary action. Gold appears only on counts that need attention (max two gold marks per screen). Status colour lives inside a pill, never on a whole row or card.
3. **The phone is a desk, not a document.** Rows are 72–96dp, targets ≥48dp, actions live at the thumb, and nothing critical is reachable only by a horizontal table scroll.

**The one memorable element:** a white **queue rail** that overlaps the bottom edge of the emerald hero — three live tiles (অর্ডার / রিচার্জ / উত্তোলন) with count and a gold dot when non-zero. It is the app's signature, and it is the site's stat-card language re-pointed at work. Everything around it stays quiet.

**Deliberately not copied**

| Web pattern | Why not on Android |
|---|---|
| Dense `<table>` with 640px min-width | Horizontal scroll on a phone; replaced by stacked list rows + detail sheet |
| Hover-only affordances (`group-hover:opacity-100`, `card-hover` translate) | No hover state on touch; replaced by press ripple + chevron |
| Six sidebar groups rendered open | Drawer depth; replaced by 5-tab bar + permission-filtered "আরও" sheet |
| Promo/hero marketing cards, WhatsApp float, footer, notice modal on load | Customer-facing, not operator-facing |
| `ALL-CAPS` eyebrows / tracked-out labels | Bengali is a unicase script; forced case and letter-spacing are not part of it (see §4.3) |
| Gold-on-white body text | Fails contrast (§4.1); gold survives only as a fill, dot, or dark ink |

**Self-critique before building.** A white card overlapping a gradient header is a common dashboard trope, so the rail has to earn it: the tiles are the only tappable thing in the hero, they carry real counts and money, and they are the only place gold appears. The rest of Home is deliberately plain — flat 2×2 metric tiles, a short activity list. If the rail were removed, the screen would lose its purpose, not just its decoration.

---

## 3. Information architecture

### 3.1 Destination map

```text
Splash ─→ Login ─→ [ Tab shell ]
                       ├── হোম            Home / queue dashboard
                       ├── অর্ডার          Orders queue ─→ Order sheet
                       ├── রিচার্জ         Recharge queue ─→ Approve / Reject sheet
                       ├── (উত্তোলন)       Withdraw queue ─→ Approve / Reject sheet   [superadmin tab slot]
                       ├── (ইউজার)         Users ─→ User detail sheet
                       └── আরও            More sheet
                                            ├── পরিচালনা: সার্ভিস, বিভাগ, স্টাফ, ইউজার, অর্ডার
                                            ├── অর্থনীতি: আমার হিসাব, রিচার্জ, উত্তোলন, রেফারেল
                                            ├── সেটিংস: সাইট সেটিংস, ভূমিকার পদক্ষেপ, অনুমতি ম্যাট্রিক্স
                                            ├── নিরীক্ষণ: অ্যাক্টিভিটি লগ, নোটিফিকেশন কিউ
                                            └── লগআউট
```

Tab roots have **no back arrow**; everything pushed from "আরও" or from a row **does**. Back at a tab root returns to হোম, then exits — standard Android behaviour, implemented with per-tab back stacks in one nested graph.

### 3.2 Tab composition rule (role-aware, stable)

Bottom bar always has **হোম** first and **আরও** last. The middle slots are filled, in priority order, from queues the role can open:

`অর্ডার → রিচার্জ → উত্তোলন → ইউজার`

| Role (as sent by `/api/auth/login`) | Tabs |
|---|---|
| `staff` | হোম · অর্ডার · রিচার্জ · আরও |
| `admin` | হোম · অর্ডার · রিচার্জ · ইউজার · আরও |
| `superadmin` | হোম · অর্ডার · রিচার্জ · উত্তোলন · আরও |

Users stays in "আরও" for superadmin; the money-out queue outranks it. Tabs never change while navigating — a bar that reshuffles per screen is worse than a deep menu.

**Known API gap (documented, not invented around):** the login payload ships `id, username, phone, balance, role` and no permission list, so the app cannot reproduce the web sidebar's exact per-permission filtering. v1 uses one `RoleCapabilities` map (mirroring the role checks in the API actions). Follow-up: add `permissions: string[]` to the login/user payload, then drive both the tabs and "আরও" from it. Until then the server's `403` is the authority and the UI must show a permission screen, not a toast.

### 3.3 Chrome system

| Variant | Used on | Spec |
|---|---|---|
| **A — Home hero** | হোম only | Continuous emerald gradient from the status bar into a ~190dp block. Row 1: 36dp avatar (initials on gold) · greeting + role chip · bell with gold dot. Row 2: eyebrow "আজকের কাজ", 22sp bold summary, held-amount line. The queue rail card overlaps the bottom by 30dp. |
| **B — Queue bar** | Tab roots | 54dp emerald gradient bar (`brand-900 → brand-700`), white 17sp semibold title, right-side icon actions (search, refresh) and overflow. No back arrow. |
| **C — Pushed bar** | Anything from "আরও", detail, form | Same gradient bar with a white back arrow; title truncates to one line; actions collapse into overflow beyond two. |
| **D — Sheet** | Order/recharge/user detail, confirm | No app bar. Drag handle, 24dp top radius, title row inside the sheet, sticky action bar pinned above the navigation bar inset. |

Note: the site uses a *light* bar on list pages. The plan keeps the gradient on every bar because the app has no breadcrumb/sidebar context; a tinted bar is what tells the operator they are inside the admin console. The content surface stays light, as on the web.

---

## 4. Design tokens

Single source of truth: a `ui/theme/` package split from today's one-file theme.

```text
ui/theme/Color.kt     brand ramp, semantic colours, light + dark schemes
ui/theme/Type.kt      Hind Siliguri family + type scale + money style
ui/theme/Shape.kt     radii
ui/theme/Theme.kt     AlifAdminTheme(darkTheme, dynamicColor = false)
ui/theme/Dimens.kt    spacing, sizes, elevation
```

### 4.1 Colour — light

The hue is the website's; the *roles* are corrected for WCAG. Primary buttons move from the web's `primary-600` (`#0d8f68`, 4.1:1 with white) to `primary-700` (`#0b6e51`, 6.2:1) because button labels are 15sp, not large text.

| Token | Hex | Role | Contrast check |
|---|---|---|---|
| `brand900` | `#083F31` | Gradient start, hero, dark headings | 10.4:1 on white |
| `brand800` | `#0A5540` | Gradient mid, pressed primary | — |
| `brand700` | `#0B6E51` | **Primary action fill**, selected chip fill, active tab label | 6.2:1 with white ✔ |
| `brand600` | `#0D8F68` | Icons, active rail, progress, chip border | 4.1:1 — graphics/large only |
| `brand100` | `#D1FAE5` | Tonal container, selected row | — |
| `brand50` | `#ECFDF5` | Tint surface, amount band | — |
| `gold500` | `#F5B301` | Gold dot, accent fill, avatar background | 1.9:1 on white — **fills only** |
| `gold200` | `#FDE68A` | Attention border, hero highlight | — |
| `gold50` | `#FFFBEB` | Attention card surface | — |
| `goldInk` | `#8A5F00` | Gold **text** (labels, "যাচাইয়ের অপেক্ষায়") | 5.65:1 on white ✔ |
| `onGold` | `#2B1D00` | Text on gold fills | 10.9:1 on `gold500` ✔ |
| `surface` | `#FFFFFF` | Cards, sheets, nav bar | — |
| `surfaceDim` | `#F4F7F6` | Page background (site `surface-100`) | — |
| `surfaceSunken` | `#E8EEEC` | Search field, skeleton base | — |
| `line` | `#E5E7EB` | 1dp borders, dividers | — |
| `ink` | `#1F2A26` | Primary text | 13.9:1 ✔ |
| `inkSecondary` | `#525252` | Labels, meta, descriptions | 7.6:1 ✔ |
| `inkMuted` | `#737373` | Icons, disabled, non-text only | — |
| `success` / `successInk` | `#059669` / `#047857` | Completed | 5.4:1 ink ✔ |
| `warning` / `warningInk` | `#D97706` / `#B45309` | Pending | ink 4.84:1 ✔ |
| `danger` / `dangerInk` | `#DC2626` / `#B91C1C` | Failed, reject, destructive | 4.9 / 6.4 ✔ |
| `info` / `infoInk` | `#2563EB` / `#1D4ED8` | Processing, review | 5.2 / 7.1 ✔ |
| `neutralInk` | `#525252` | Disabled/inactive status text | ✔ |

**Usage budget (enforced in review):** emerald is allowed on (a) the hero/gradient bar, (b) one primary action per screen, (c) the active rail/label, (d) tinted icon tiles. Gold on **at most two** marks per screen. Status colour only inside a pill, a 3dp row rail, or an amount.

### 4.2 Colour — dark

Dark is a first-class theme, not an inversion: the site's dark rules already establish that near-white soft fills become dark tints.

| Token | Hex |
|---|---|
| `brandBg` | `#08120F` |
| `brandSurface` | `#0E1E19` |
| `brandSurfaceHi` | `#16302A` |
| `brandLine` | `#24443B` |
| `brandPrimary` | `#34D399` (action fill; `onPrimary #04231A`) |
| `brandTint` | `#0B2C22` |
| `goldAccent` | `#FBBF24` (dot/fill; `onGold #2A1D00`), gold text `#FCD34D` |
| `textPrimary` | `#E6EFEB` |
| `textSecondary` | `#A9BAB4` |
| status containers | warning `#3A2405`/`#FCD34D` · info `#0B2440`/`#93C5FD` · success `#062A20`/`#6EE7B7` · danger `#3A0D0D`/`#FCA5A5` · neutral `#1C2A25`/`#B8C6C0` |

Gradient in dark: `#04231C → #0A3D2F`. `dynamicColor` defaults to **false**; keep the parameter so a future "use device colours" preference is a one-line opt-in, but the brand is the default.

### 4.3 Typography

**Family:** Hind Siliguri — the website's first family — bundled at 400/500/600/700 in `res/font/` (~0.5 MB total, SIL OFL; include the licence file). Reasons: identical Bengali letterforms to the site, offline reliability, no Play Services font fetch, and predictable weight rendering (the current Roboto fallback is the app's biggest brand mismatch). `Noto Sans Bengali` stays as the system fallback for glyphs outside the subset.

**Bengali typesetting rules**

- `letterSpacing = 0.sp` everywhere. Tracking is not a Bengali typographic device and it separates a consonant from its matra.
- Body line-height **1.45–1.5**; headings **1.25–1.3**. The matra needs more air than Latin caps.
- Never all-caps-style labels. (Bengali has no case; the web's `.accent-label` uppercase tracking is intentionally dropped.)
- Minimum 12sp for any Bengali text on a phone; the web already floors 11px to 12px on mobile.
- Latin-only strings (IDs, references, amounts, log actions) use the same family but may take `+0.2sp` and `FontFeatureSettings = "tnum"`.

| Style | Size / line | Weight | Use |
|---|---|---|---|
| `displaySmall` | 30 / 36 | Bold | Amount in detail sheet, big queue numbers |
| `headlineMedium` | 26 / 32 | Bold | Hero summary |
| `headlineSmall` | 22 / 28 | Bold | Page/section hero |
| `titleLarge` | 20 / 26 | SemiBold | Bar title, sheet title |
| `titleMedium` | 17 / 24 | SemiBold | Row title, card title |
| `bodyLarge` | 15 / 22 | Regular | Body, form text |
| `bodyMedium` | 14 / 21 | Regular | Descriptions, secondary |
| `bodySmall` | 13 / 19 | Regular | Meta |
| `labelLarge` | 15 / 20 | SemiBold | Buttons, tabs |
| `labelMedium` | 12 / 16 | Medium | Pills, chips, labels |
| `labelSmall` | 12 / 16 | Medium | Timestamps (**floored at 12**, unlike the web's 11px desktop) |
| `money` | 17 / 22 | SemiBold + `tnum` | Row amounts |
| `moneyLarge` | 28 / 34 | Bold + `tnum` | Detail amount band |

**Money formatter** (mirrors `TwigExtension::money`): `"৳ " + NumberFormat.getInstance(Locale.US)` with 2 decimals, `minFractionDigits = maxFractionDigits = 2`. Latin digits, thousands separators — do **not** localise to Bengali digits. Expose `Money.format()` from `ui/format/` and use it in every screen.

### 4.4 Spacing, grid, density

4dp base scale: `2 · 4 · 8 · 12 · 16 · 20 · 24 · 32 · 40`.

| Context | Value |
|---|---|
| Screen gutter | 16dp (24dp on ≥600dp) |
| Card padding | 16dp (hero card 20dp) |
| List row padding | 14dp vertical · 16dp horizontal |
| Gap between related items | 8dp |
| Gap between rows | 8dp |
| Gap between sections | 24dp |
| Bottom nav | 60dp content + system navigation-bar inset |
| Bottom content padding | 96dp (nav + inset + 16dp) |
| Minimum touch target | 48×48dp, 8dp apart |
| List row height | 104–112dp (queue rows), 72dp (users/logs) |

### 4.5 Shape & elevation

| Token | Value | Use |
|---|---|---|
| `xs` | 8dp | Pills inside rows, tags |
| `sm` | 12dp | Inputs, small buttons, search |
| `md` | 14dp | Buttons, icon tiles |
| `lg` | 18dp | Cards, metric tiles |
| `xl` | 20dp | Queue rail, hero card |
| `sheet` | 24dp top only | Bottom sheets |
| `full` | 50% | Status pills, avatar, dots |

Shadows are **not** the separation device — the site only uses a soft green-tinted shadow on white cards, and Android renders it poorly on low-end panels. Cards use `surface` + 1dp `line` border. Elevation is reserved for: bottom nav (2dp), sticky action bar (3dp), bottom sheet (M3 default). If a shadow is needed, tint it: `0 6 20 -3 rgba(8,63,49,0.12)`.

### 4.6 Iconography

Material Symbols **Rounded**, 24dp default / 20dp in rows / 16dp inside pills, weight 500, `tint = currentColor`. This is a deliberate substitution for the site's Lucide sprite: `material-icons-extended` is already a dependency (no new library, no 1,600-icon asset copy), and the rounded terminals sit closer to Hind Siliguri than Lucide's sharp joins. If exact web parity is later required, ship a curated 24-icon vector set only — never the whole sprite.

Map: হোম `home` · অর্ডার `inbox` · রিচার্জ `account_balance_wallet` · উত্তোলন `send` · ইউজার `group` · সার্ভিস `layers` · বিভাগ `grid_view` · স্টাফ `badge` · হিসাব `receipt_long` · রেফারেল `card_giftcard` · সেটিংস `settings` · ভূমিকা `account_tree` · অনুমতি `table_chart` · লগ `history_edu` · নোটিফিকেশন `notifications_active` · বেল `notifications` · রিফ্রেশ `refresh` · সার্চ `search` · ফিল্টার `tune` · আরও `more_horiz`.

### 4.7 Motion

| Interaction | Spec |
|---|---|
| Screen push/pop | Fade + 8dp slide, 180ms, `FastOutSlowIn` |
| Bottom sheet | M3 default spring; scrim 32% |
| Tab switch | Cross-fade content 120ms; rail indicator moves 160ms |
| Queue count change | 200ms crossfade + 0.96→1.0 scale, once |
| Press | 8% tonal overlay, 90ms |
| In-flight pill | 700ms linear ring, **only** for pending/processing/review |
| Skeleton | 1200ms shimmer sweep, as `.skeleton` on the web |

No entrance animations on every section; no confetti; no looping pulse on the bell. Under `Settings.Global.ANIMATOR_DURATION_SCALE == 0` (or Compose's reduced-motion signal) the shimmer and rail stop, and transitions become cuts.

---

## 5. Component kit

Every component below is a new file under `android-admin/app/src/main/java/com/aliftools/admin/ui/components/`. Existing `AdminScaffold` and `PagedListView` are **evolved**, not replaced, so screens migrate one at a time.

| Component | Anatomy | States | Web origin |
|---|---|---|---|
| `AdminHomeHeader` | gradient block, avatar, greeting, bell, eyebrow, summary, held amount | loaded / skeleton | `dashboard.twig` topbar + greeting banner |
| `AdminTopBar` | gradient bar, title, back, actions, overflow | root / pushed | `.accent-bar`, page-header |
| `AdminBottomBar` | 5 slots, 3dp top rail on selected, icon + 12sp label | selected / unselected / badge | `.nav-link-active` rail |
| `QueueRail` + `QueueTile` | white card, 3 columns, hairline dividers, count, label, gold dot / check | >0 / zero / loading | admin `index.twig` unresolved-recharge card |
| `StatTile` | 32dp tinted icon square, value 18sp bold, label 12sp | normal / loading | `components/stat-card.twig` |
| `StatusPill` | 26dp pill, dot or ring, label | in-flight / settled | `status-badge.twig`, `.badge-loading` |
| `MoneyText` | `৳ 1,250.00`, tabular, optional size/emphasis | normal / muted / large | `money` filter |
| `ListRow` | optional 3dp rail, meta line, title, sub, money + time | default / pressed / in-flight | admin order rows |
| `FilterChipRow` | horizontal chips with counts, selected = solid brand | idle / selected | `.chip` / `.chip-active` |
| `SearchField` | 48dp, leading search, trailing clear, surface-sunken | idle / focused / filled | dashboard search bar |
| `SectionHeader` | 17sp semibold title + optional trailing text action | — | page-header / section rhythm |
| `EmptyState` | 56dp circle medallion, title, one-line body, optional action | — | admin empty state |
| `ErrorState` | medallion, explanation, retry, secondary "লগ দেখুন" (real destination) | — | inline error |
| `SkeletonRow` / `SkeletonTile` | shimmer blocks matching final layout | — | `.skeleton` |
| `OfflineBanner` | amber bar under the app bar, "ইন্টারনেট নেই · শেষ ডেটা দেখানো হচ্ছে" | — | none (new) |
| `ConfirmSheet` | tone medallion, title, **amount band**, 1–3 bullets, stacked actions | money / danger / warn / neutral | `confirm-dialog` component |
| `DetailSheet` | handle, header, amount band, key/value rows, attachments, sticky bar | loading / loaded / action-busy | recharge detail page |
| `AmountBand` | brand-50 band, 28sp amount, 12sp caption | — | `.th-confirm-amount` |
| `RoleChip` | 24dp pill, shield 14dp, role label | — | sidebar role context |

**Component rules**

- A row is tappable as a whole (`Card(onClick=…)`) with a visible chevron; no nested click targets except an explicit overflow.
- The 3dp left rail is reserved for **actionable** rows (`pending`, `review`); settled rows have no rail. It is information, not decoration.
- Status pills never replace the status word with colour alone; label always present (colour-blind safety).
- Loading never blanks a list that already has rows: stale rows + inline "আপডেট হয়নি · আবার চেষ্টা করুন" bar.
- Destructive/money confirmations always show the amount **before** the sentence, mirroring the web confirm component's amount band.

---

## 6. Screen layouts

All frames are 360×800dp content with a 16dp gutter. `▓` = emerald gradient, `▁` = hairline, `●` = gold attention mark.

### 6.1 Login

```text
┌────────────────────────────────────────────┐
│▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│
│▓   [mark]  Alif Tools                      ▓│
│▓           অ্যাডমিন প্যানেল                  ▓│
│▓                                          ▓│
│▓   অর্ডার, রিচার্জ আর ইউজার —                ▓│
│▓   এক জায়গায়।                              ▓│
│▓                                          ▓│
│▓  ┌────────────────────────────────────┐  ▓│
│▓  │ লগইন                               │  ▓│
│▓  │ ইউজারনেম বা মোবাইল                   │  ▓│
│▓  │ ┌────────────────────────────────┐ │  ▓│
│▓  │ │ 👤  আপনি কে?                    │ │  ▓│
│▓  │ └────────────────────────────────┘ │  ▓│
│▓  │ পাসওয়ার্ড                           │  ▓│
│▓  │ ┌────────────────────────────────┐ │  ▓│
│▓  │ │ 🔒  ••••••••              👁   │ │  ▓│
│▓  │ └────────────────────────────────┘ │  ▓│
│▓  │ ┌────────────────────────────────┐ │  ▓│
│▓  │ │           লগইন করুন             │ │  ▓│
│▓  │ └────────────────────────────────┘ │  ▓│
│▓  │      ফোন দিয়ে সাইন ইন করুন           │  ▓│
│▓  └────────────────────────────────────┘  ▓│
│▓        🔒 নিরাপদ সংযোগ · v1.0.0            ▓│
└────────────────────────────────────────────┘
```

- Brand gradient fills the top 46%; a white sheet (`radius 24`, `-24dp` overlap) holds the form. This is the site's auth page composition, not a stock centered card.
- Fields 56dp tall, `radius 12`, sunken surface, 1dp line; focus ring 2dp `brand600`.
- Primary button 52dp, full width, `brand700`; loading state = inline 18dp spinner + disabled, label unchanged.
- Errors appear under the field in 12sp `dangerInk`, with the server's Bengali message verbatim.
- Firebase phone sign-in stays as a text button, secondary to the password path.
- No "remember me" checkbox: the token store already persists the session. No social login — this is an admin surface.

### 6.2 Home — the queue desk

```text
┌────────────────────────────────────────────┐
│▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│
│▓ (RH)  শুভ সন্ধ্যা              🔔 ●        ▓│
│▓       রফিক হাসান · [🛡 অ্যাডমিন]           ▓│
│▓                                          ▓│
│▓  আজকের কাজ                                ▓│
│▓  5টি কাজ · ৳ 12,400 যাচাইয়ের অপেক্ষায়     ▓│
│▓ ┌─────────────┬─────────────┬───────────┐▓│
│▓ │ 4        ●  │ 2        ●  │ 0      ✓  │▓│  ← rail, -28dp overlap
│▓ │ অর্ডার       │ রিচার্জ      │ উত্তোলন    │▓│
│▓ └─────────────┴─────────────┴───────────┘▓│
│▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│
│                                            │
│  সংক্ষিপ্ত হিসাব                  সব দেখুন › │
│  ┌───────────────────┬───────────────────┐ │
│  │ 👥  মোট ইউজার       │ 📥  সক্রিয় অর্ডার    │ │
│  │ 1,284             │ 12                │ │
│  └───────────────────┴───────────────────┘ │
│  ┌───────────────────┬───────────────────┐ │
│  │ 💰  অপেক্ষমাণ ব্যালেন্স│ ✅  আজ অনুমোদিত      │ │
│  │ ৳ 8,900.00        │ ৳ 24,500.00       │ │
│  └───────────────────┴───────────────────┘ │
│                                            │
│  দ্রুত কাজ                                  │
│  ┌───────────────┬───────────────────────┐ │
│  │ 🧾 অর্ডার       │ 👥 ইউজার               │ │
│  │ 4টি অপেক্ষমাণ › │ খুঁজুন, রোল বদলান ›     │ │
│  └───────────────┴───────────────────────┘ │
│                                            │
│  সাম্প্রতিক কার্যক্রম            সব দেখুন ›  │
│  ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁ │
│  🧾  রিচার্জ অনুমোদিত · ৳ 500.00            │
│      রফিক · 2 মিনিট আগে                     │
│  ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁ │
│  🛡  ইউজার ব্লক করা হয়েছে · #1204          │
│                                            │
├────────────────────────────────────────────┤
│   ▎ হোম      অর্ডার     রিচার্জ     আরও       │
└────────────────────────────────────────────┘
```

**Copy decisions**

- Eyebrow "আজকের কাজ" is a real label (the user's job), not decoration.
- Summary line answers *how much money is waiting*, not only how many rows. `৳` uses `Money.format`; the count uses `NumberFormat` — both Latin digits.
- Rail tiles: count 26sp bold `brand900` (or `inkMuted` at zero), label 12sp. Zero state shows `0` plus a check icon, which is a result, not an empty box.
- "দ্রুত কাজ" is capped at four cards; the fifth destination is "আরও". Cards are 76dp tall (icon left, title + one line of copy), icon tile 32dp, chevron. No `→` glyph appended to label text (web tell).
- Activity rows show verb + object + amount; never raw action keys like `topup.approved` (the web's log table does show keys, but that table is for audit, not for a home glance).
- Pull-to-refresh on the whole column; skeleton mirrors the final layout exactly (header, rail, 4 tiles, 3 rows).

### 6.3 Queue list — অর্ডার / রিচার্জ / উত্তোলন

```text
┌────────────────────────────────────────────┐
│▓ ‹  রিচার্জ                       🔍  ⋮   ▓│
├────────────────────────────────────────────┤
│ ┌────────────────────────────────────────┐ │
│ │ 🔍  ইউজার, রেফারেন্স বা নম্বর খুঁজুন      │ │
│ └────────────────────────────────────────┘ │
│ (সব 12)  (পেন্ডিং 4)  (রিভিউ 2)  (অনুমোদিত)  │
│                                            │
│ ┌────────────────────────────────────────┐ │
│ │▎#482  ৳ 500.00            ⏳ পেন্ডিং    │ │
│ │ রফিক হাসান · 017•••••678                │ │
│ │ বিকাশ · TXN9F2K                 2 মিনিট │ │
│ └────────────────────────────────────────┘ │
│ ┌────────────────────────────────────────┐ │
│ │ #481  ৳ 1,200.00           যাচাই ধরা    │ │
│ │ সুমাইয়া আক্তার · 019•••••021              │ │
│ │ নগদ · প্রেরক 018•••••033                 │ │
│ └────────────────────────────────────────┘ │
│ ┌────────────────────────────────────────┐ │
│ │ #479  ৳ 300.00          ✅ অনুমোদিত     │ │
│ │ ...                                    │ │
│ └────────────────────────────────────────┘ │
│              সব দেখানো হয়েছে · 124          │
└────────────────────────────────────────────┘
```

**Row anatomy** (queue rows, 104–112dp)

1. Line 1: `#ID` 12sp `inkSecondary` tabular · status pill right-aligned.
2. Line 1 right: status pill. Line 2: amount `money` 17sp semibold `ink` (`goldInk` when unresolved and > 0). Line 3: `name · masked phone` — name `ink` 12sp medium, phone `inkSecondary`. Line 4: `method · reference` and relative time, both 12sp `inkSecondary`.
3. Masking: phone numbers show `017•••••678` (Latin digits, so the value stays copyable and matches the server). Receipt/reference is truncated to 12 chars with ellipsis; full value lives in the sheet.
4. The 3dp rail appears only on `pending`/`review` and uses `warning`/`info`.
5. Press: 8% overlay, **no** translate/scale (list rows should not jump while scrolling).
6. Filter chips scroll horizontally with 8dp gaps and show counts; selected chip is solid `brand700` with white 12sp label, unselected is white + 1dp line. Chips are 36dp visual, 48dp touch.
7. Search debounces 300ms; it filters the loaded page server-side (`q`), not client-side, and shows "খুঁজছি…" in the trailing slot.
8. Pagination: auto-load at 5 rows from the end; footer = 20dp spinner, then "সব দেখানো হয়েছে · মোট 124". On failure, footer becomes a "আবার চেষ্টা করুন" text button — never a red screen over existing rows.
9. Empty: medallion + "কোনো পেন্ডিং রিচার্জ নেই" + "সব অনুরোধ প্রক্রিয়া হয়েছে" + optional "সব দেখুন" to reset the filter.

### 6.4 Recharge detail — approval sheet

```text
┌────────────────────────────────────────────┐
│  (dimmed list behind 32% scrim)            │
│ ┌────────────────────────────────────────┐ │
│ │              ▬▬▬                       │ │
│ │ রিচার্জ #482                 ⏳ পেন্ডিং   │ │
│ │                                        │ │
│ │ ┌────────────────────────────────────┐ │ │
│ │ │ ৳ 500.00                            │ │ │
│ │ │ রফিক হাসান · বিকাশ                   │ │ │
│ │ └────────────────────────────────────┘ │ │
│ │                                        │ │
│ │ প্রেরক নম্বর        017•••••678          │ │
│ │ ▌রেফারেন্স          TXN9F2K             │ │
│ │ ▌মাধ্যম            বিকাশ                │ │
│ │ ▌জমা দেওয়ার সময়    29 অক্টো · 6:42 PM   │ │
│ │ ▌নোট              —                    │ │
│ │                                        │ │
│ │ ┌──────────────┐                       │ │
│ │ │ [রসিদের ছবি]  │  ট্যাপ করে বড় দেখুন     │ │
│ │ └──────────────┘                       │ │
│ │                                        │ │
│ │ ┌────────────────────────────────────┐ │ │
│ │ │        অনুমোদন করুন                 │ │ │
│ │ └────────────────────────────────────┘ │ │
│ │ ┌────────────────────────────────────┐ │ │
│ │ │           বাতিল করুন                │ │ │
│ │ └────────────────────────────────────┘ │ │
│ └────────────────────────────────────────┘ │
└────────────────────────────────────────────┘
```

- Sheet height: `wrapContent` up to 88% screen; content scrolls, **action bar is pinned** above the nav inset.
- The amount is the largest element on the screen (30sp). This is a money decision; the figure comes before the prose.
- Key/value rows: label 13sp `inkSecondary` left, value 14sp `ink` right, 44dp tall, hairline between.
- Receipt image: 72dp thumbnail, tap → full-screen zoomable viewer; loading shimmer; failure shows "ছবি লোড হয়নি" with retry. (Identity documents stay server-private; only what the API returns is displayed.)
- **Approve flow:** tap → biometric/confirm sheet (`money` tone) with amount band + bullets ("ব্যালেন্স যোগ হবে ৳ 500.00", "ইউজারকে নোটিফিকেশন যাবে") → server call → sheet stays open with a spinner on the button → success closes the sheet, row updates in place, snackbar "রিচার্জ অনুমোদিত হয়েছে". Idempotency errors from the server are shown verbatim; the client never retries a money POST.
- **Reject flow:** inline reason field (required, 2–4 lines), then `danger` confirm sheet whose confirm button reads "বাতিল নিশ্চিত করুন" and is disabled until the reason is non-blank.
- Withdraw sheet uses the same component with `danger` emphasis for reject and shows the ledger effect for approve.

### 6.5 Users

```text
┌────────────────────────────────────────────┐
│▓ ‹  ইউজার                         🔍  ⋮   ▓│
├────────────────────────────────────────────┤
│ ┌────────────────────────────────────────┐ │
│ │ 🔍  ইউজারনেম, মোবাইল বা আইডি            │ │
│ └────────────────────────────────────────┘ │
│ (সব) (সক্রিয়) (নিষ্ক্রিয়) (স্টাফ)          │
│ ┌────────────────────────────────────────┐ │
│ │ (RH)  রফিক হাসান          🛡 অ্যাডমিন  › │ │
│ │       @rafik · 017•••••678              │ │
│ │       ৳ 4,250.00                       │ │
│ └────────────────────────────────────────┘ │
│ ┌────────────────────────────────────────┐ │
│ │ (SA)  সুমাইয়া আক্তার      👤 ইউজার     › │ │
│ │       @sumaiya · 019•••••021             │ │
│ │       ৳ 120.00                        │ │
│ └────────────────────────────────────────┘ │
└────────────────────────────────────────────┘
```

- Avatar = 40dp initials circle, deterministic tint from a 6-entry brand-safe set (brand, teal, indigo, amber, rose, slate — the site's accent palette), chosen by `id % 6`. Cached photos are not part of the admin API; do not invent them.
- Balance is the row's money: right-aligned, tabular, `ink`; zero balance muted.
- Role chip: `superadmin` = gold-tinted `goldInk`; `admin` = brand-tinted; `staff` = neutral. Labels come from the server's role strings.
- Detail sheet: identity, balance, role/status, actions (toggle status, change role, reset password, delete/restore) grouped by risk, with the destructive ones after a hairline and in `dangerInk`. Every mutation uses `ConfirmSheet`; role change and delete also require biometric re-auth.

### 6.6 "আরও" sheet

```text
┌────────────────────────────────────────────┐
│              ▬▬▬                           │
│ আরও                                        │
│                                            │
│ পরিচালনা                                    │
│ 🧩  সার্ভিস                            ›   │
│ 🗂  বিভাগ                              ›   │
│ 🪪  স্টাফ                      3 জন     ›   │
│ 👥  ইউজার                              ›   │
│                                            │
│ অর্থনীতি                                    │
│ 🧾  আমার হিসাব                          ›   │
│ 💳  রিচার্জ                       2      ›   │
│ 📤  উত্তোলন                             ›   │
│ 🎁  রেফারেল                             ›   │
│                                            │
│ নিরীক্ষণ                                    │
│ 📜  অ্যাক্টিভিটি লগ                     ›   │
│ 🔔  নোটিফিকেশন কিউ                      ›   │
│                                            │
│ ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁ │
│ 🚪  লগআউট                       (danger)   │
└────────────────────────────────────────────┘
```

- Group order and Bengali labels mirror `partials/sidebar.twig` exactly. Rows and groups are permission-filtered the same way; the visual board shows an `admin` view, so it has three groups and no উত্তোলন row, while a superadmin sees all four.
- Rows 48dp, icon 13dp in a 26dp tinted square, count badges right-aligned before the chevron. An admin view (nine destinations + sign-out, three group labels) fits a 360×800 sheet without scrolling; a superadmin's extra group scrolls, with sign-out pinned below the scroll area.
- Sign-out separated by a hairline, after the last group, `dangerInk`, with a confirm sheet ("লগআউট করবেন? এই ডিভাইসে আর লগইন থাকবে না।").

### 6.7 Form screens (সেটিংস, সার্ভিস, বিভাগ, স্টাফ)

```text
┌────────────────────────────────────────────┐
│▓ ‹  সাইট সেটিংস                            ▓│
├────────────────────────────────────────────┤
│  সাধারণ                                     │
│  সাইটের নাম                                 │
│  ┌──────────────────────────────────────┐  │
│  │ Alif Tools                           │  │
│  └──────────────────────────────────────┘  │
│  সহায়তা মোবাইল                               │
│  ┌──────────────────────────────────────┐  │
│  │ 017•••••678                          │  │
│  └──────────────────────────────────────┘  │
│  ⓘ সংখ্যা লিখুন, ইংরেজি ডিজিটে।              │
│                                            │
│  পেমেন্ট                                     │
│  ┌──────────────────────────────────────┐  │
│  │ বিকাশ চালু                      [  ●]│  │
│  └──────────────────────────────────────┘  │
│                                            │
│ ┌──────────────────────────────────────┐   │
│ │            পরিবর্তন সংরক্ষণ করুন        │   │
│ └──────────────────────────────────────┘   │
└────────────────────────────────────────────┘
```

- 16dp gutter, field groups separated by 24dp and a 1dp hairline; group label 13sp semibold `inkSecondary` (not uppercase).
- Field height 56dp; helper text 12sp `inkSecondary`; error 12sp `dangerInk` and the field's border turns `danger`.
- Unsaved-changes state: the save bar becomes sticky above the nav inset with "সংরক্ষণ" + "বাতিল"; back triggers "পরিবর্তন সংরক্ষণ করেননি" confirm.
- Toggles use `Switch` with the whole row tappable; a switch never carries an immediate server call without a confirmation for money-adjacent settings.
- Long setting lists are grouped, not one flat scroll.

### 6.8 Activity log / notification queue

- Logs are **read-only rows**: action label (humanised, e.g. "রিচার্জ অনুমোদিত"), description (2 lines max), actor, relative time; tap opens a sheet with raw key, IP, metadata in 12sp `inkSecondary`. Raw keys stay in the sheet for audit fidelity, not in the row.
- Notification queue rows: channel icon, title, recipient, delivery state pill, retry button only for dead-letter rows; retry uses `warn` confirm.

### 6.9 States (every screen)

| State | Presentation |
|---|---|
| First load | Skeleton mirroring the final layout; never a bare centred spinner on Home or lists |
| Refresh | `PullToRefreshBox`; rows stay visible; 2dp `brand600` progress under the bar |
| Empty | Medallion 56dp (`surfaceSunken`, `inkMuted` icon), 17sp title, 13sp line, one action |
| Error | Medallion in `danger50`, one sentence naming what failed, "আবার চেষ্টা করুন" filled, "লগ দেখুন" text — a real destination |
| Offline | Amber `OfflineBanner` under the bar + "শেষ আপডেট 3 মিনিট আগে" caption; no write attempts |
| Unauthorized (401) | Session-expired sheet → Login; token cleared; message "সেশনের সময় শেষ, আবার লগইন করুন" |
| Forbidden (403) | Full-screen "এই বিভাগে আপনার অনুমতি নেই" + "হোমে ফিরুন"; never a snackbar |
| Action in progress | Button label unchanged + 18dp spinner; all other actions disabled; sheet cannot be dismissed by swipe |
| Action success | Sheet closes, row updates in place, snackbar uses the same verb as the button ("অনুমোদিত হয়েছে") |

---

## 7. Content rules (Bengali copy)

1. **Verbs are the labels.** "অনুমোদন করুন" → snackbar "রিচার্জ অনুমোদিত হয়েছে". Same vocabulary through the flow.
2. **Sentence case, no stylised tracking.** Bengali is unicase; no faux emphasis.
3. **Numbers**: every numeral is Latin — money `৳ 1,250.00`, counts `1,284`, relative time ("2 মিনিট আগে"), dates ("29 অক্টো, 6:42 PM"), phone masks `017•••••678`. The web emits Latin digits through `number_format`, so Bengali digits here would give one balance two spellings. Both formatters live in `ui/format/`.
4. **No English leakage** in user-facing labels — fix "অনরক্ষিত Amount" to "অপেক্ষমাণ ব্যালেন্স". Technical identifiers (reference, action key, IP) remain Latin and are labelled in Bengali ("রেফারেন্স", "আইপি").
5. **Errors explain, don't apologise.** "রিচার্জ অনুমোদন করা যায়নি — অনুরোধটি ইতিমধ্যে প্রক্রিয়া হয়েছে।" Prefer the server's Bengali message verbatim; fall back to a generated sentence that names the action.
6. **Empty states invite action.** "কোনো পেন্ডিং অর্ডার নেই" + "সব অর্ডার প্রক্রিয়া হয়েছে" (a result), not "কিছু নেই".
7. **Confirmation copy states consequences**, one per bullet, with the amount in the band above.
8. **Phone masking** in lists, full value in detail sheets (operator need, reduced shoulder-surfing risk).

---

## 8. Accessibility

| Requirement | Spec |
|---|---|
| Touch targets | ≥48×48dp; chips 48dp tall hit area; icon buttons 48dp |
| Contrast | Body text ≥4.5:1 (`ink`, `inkSecondary`); primary fill `brand700` (6.2:1); gold text `goldInk` only; status ink on tinted 50-container ≥4.5:1 |
| Status without colour | Label always present; in-flight also has the ring; rail is never the only signal |
| TalkBack | Queue tile: "4টি পেন্ডিং অর্ডার, খুলুন"; amount read as "পাঁচশত টাকা" via `contentDescription` including "টাকা"; sheet announce as dialog with title; decorative icons `null` |
| Focus order | Bar → filters → rows → pagination; `traversalIndex` on the rail tiles left-to-right |
| Font scale | Layouts use `wrapContentHeight` and no fixed text heights; test 100/130/200%; list rows grow; bottom nav labels never truncate (max 2 words) |
| Keyboard / switch | Tabs and chips reachable; visible 2dp focus ring `brand600` |
| Reduced motion | Shimmer/spinners stop; transitions cut; no marquee |
| Screen size | Bottom bar switches to `NavigationRail` at ≥600dp, list/content dual pane for orders at ≥840dp |
| Haptics | Light tick on approve/reject confirmation only |

---

## 9. Responsive behaviour

| Width | Layout |
|---|---|
| < 600dp | Bottom bar, single column, sheets at 88% height |
| 600–839dp | Navigation rail (72dp), content max 600dp centred, Home metrics 2×2 → 4×1 |
| ≥ 840dp | Rail + dual pane: queue list left (380dp), detail right (no sheet; inline detail card). Forms max 640dp. |

Landscape phone: hero collapses to a 96dp bar (greeting + rail in one row); sheets go side-sheet at ≥600dp width.

---

## 10. What this plan does **not** require from the backend

The design uses only fields the app already parses: dashboard counts/amounts, `status`, `amount`, `username`, `method`, `reference`, `senderNumber`, `createdAt`, role strings, logs, settings. No new endpoint, table, or migration is part of this plan. Two API notes are recorded as follow-ups, not blockers:

1. `permissions[]` in the login/user payload so the menu matches the web sidebar exactly (§3.2).
2. Optional `status_label` from `StatusPresenter` to remove the duplicated Bengali label map in Kotlin.

Until (2), Android keeps one `StatusStyle` object as the **only** place status colour/label is decided — never per screen — mirroring `StatusPresenter`'s map.

---

## 11. Implementation plan

Ordered so the app is usable after every phase. Each phase names the files to touch and how to verify. No phase changes financial behaviour: all approvals still go through the existing ViewModel → repository → server paths.

> **Progress is not tracked here.** Phase status, what actually shipped, deviations and the next checklist live in [`ADMIN_ANDROID_UI_CHANGELOG.md`](ADMIN_ANDROID_UI_CHANGELOG.md). As of 2026-10-29, Phases 1–3 are implemented; Phase 4 is next. Read the changelog before starting any phase so the work continues from the shipped state rather than the original text.

### Phase 1 — Theme foundation

**Files:** `ui/theme/Color.kt` (new), `ui/theme/Type.kt` (new), `ui/theme/Shape.kt` (new), `ui/theme/Dimens.kt` (new), `ui/theme/Theme.kt` (rewrite around the tokens), `res/font/*` (new, 4 weights + licence), `res/values/themes.xml` (window background, no action bar).
**Change:** `dynamicColor` default `false`; brand light/dark schemes; Hind Siliguri family; type scale; `Money`/`Date` formatters in `ui/format/`.
**Acceptance:** app builds; Home renders Bengali in Hind Siliguri; switching system dark mode flips every surface; `Theme.kt` contains no hex literals.

### Phase 2 — Component kit

**Files:** `ui/components/design/` (new): `AdminTopBar.kt`, `AdminBottomBar.kt`, `QueueRail.kt`, `StatTile.kt`, `StatusPill.kt`, `MoneyText.kt`, `ListRow.kt`, `FilterChipRow.kt`, `SearchField.kt`, `SectionHeader.kt`, `EmptyState.kt`, `ErrorState.kt`, `Skeleton.kt`, `ConfirmSheet.kt`, `AmountBand.kt`, `RoleChip.kt`, `StatusStyle.kt`; new keys in `res/values/strings.xml` for chrome labels that do not have one yet.
**Acceptance:** each component has a `@Preview` for light, dark, and 200% font scale; `StatusPill` covers all statuses from `StatusPresenter`; no screen imports `dynamicColorScheme`.

### Phase 3 — Shell and navigation

**Files:** `ui/navigation/AdminNavGraph.kt` (nested graph + `Screen` sealed class extension), `ui/AdminApp.kt` (new: `Scaffold` with bar + bottom rail), `MainActivity.kt` (hosts `AdminApp`), `ui/components/AdminScaffold.kt` (delegates to `AdminTopBar`).
**Change:** tab composition from `RoleCapabilities`; "আরও" sheet with the four groups; deep links keep working (existing `EXTRA_ROUTE` allow-list extended to new routes); per-tab back stacks.
**Acceptance:** staff/admin/superadmin see the tab sets in §3.2; back from a pushed screen returns to its tab; notification deep link still lands on the right screen; no destination is unreachable.

### Phase 4 — Home

**Files:** `ui/screens/dashboard/DashboardScreen.kt`, `DashboardViewModel.kt` (add derived queue counts + recent activity if already in payload; otherwise render only available fields).
**Acceptance:** hero + rail + 4 stat tiles + 4 action cards + activity list; pull-to-refresh; skeletons; error state inside the scaffold; zero-count rail state; no dead buttons; 200% font scale does not clip the rail.

### Phase 5 — Queues

**Files:** `PagedListView.kt` → decomposed into `AdminListScaffold` (bar, search, chips, states, paging) + screen rows; `OrdersScreen.kt`, `RechargesScreen.kt`, `WithdrawsScreen.kt`, `UsersScreen.kt`, `LogsScreen.kt`, `NotificationsScreen.kt`.
**Acceptance:** rows match §6.3 anatomy; counts in chips; relative timestamps; masked phones; auto-load + footer; empty/error/offline states; stale-rows-on-refresh preserved.

### Phase 6 — Detail and money actions

**Files:** `ui/components/design/DetailSheet.kt`, `ConfirmSheet.kt`; recharge/withdraw/order/user detail composables.
**Acceptance:** amount band above prose; pinned action bar; approve/reject idempotency errors surfaced; no retry on money POST; reject reason required; biometric gate invoked exactly once per confirmation; row reflects the new status without a full reload.

### Phase 7 — Remaining screens

**Files:** `settings/SettingsScreen.kt`, `services/ServicesScreen.kt`, `categories/CategoriesScreen.kt`, `staff/StaffScreen.kt`, `ledger/LedgerScreen.kt`, `referrals/ReferralsScreen.kt`, `rolehierarchy/RoleHierarchyScreen.kt`, `permissions/PermissionsMatrixScreen.kt`, `login/LoginScreen.kt`, `splash/SplashScreen.kt`.
**Acceptance:** login matches §6.1; forms follow §6.7; every screen has the eight states; visual audit against the board.

### Phase 8 — Polish and verification

**Files:** `ui/theme/*` final pass; `androidTest/.../LoginScreenUiTest.kt` extended; new `HomeUiTest`, `QueueListUiTest`, `ConfirmSheetUiTest`.
**Checks:**
- `cd android-admin && ./gradlew.bat :app:assembleDebug`
- `./gradlew.bat testDebugUnitTest` (existing ViewModel/repository tests must stay green)
- New Compose UI tests: tab set per role, status pill tone coverage, confirm-sheet amount rendering, empty/error states
- Manual: 360dp phone + 800dp tablet, light/dark, font scale 100/130/200%, TalkBack pass on Home and one approval flow
- R8 release build still strips nothing the new theme needs (fonts are resources, not reflection)

---

## 12. Decisions and risks

### 12.1 Decisions taken in this plan

| Decision | Why | Cost if wrong |
|---|---|---|
| Brand palette, `dynamicColor = false` | The app must look like the platform, not the wallpaper; dynamic colour is why it currently feels unrelated | Users who like Material You lose it; reversible with one flag |
| Bottom tabs + "আরও" sheet instead of the web sidebar | Five thumb-reachable roots beat fourteen hidden links; groups survive in the sheet | One extra tap to a deep page; the tab set needs review once the permission payload exists |
| Bengali-first copy; no mass `strings.xml` migration | The file exists but is unused, and a 600-string refactor would bury a visual task. New shared chrome uses `stringResource` where a key already fits and adds keys for new labels; screen copy stays inline | Two conventions coexist until a dedicated extraction task; that task is cheaper than doing it badly here |
| Bundled Hind Siliguri | Offline, exact web letterforms, predictable weights | +~0.5 MB APK; font subsetting can trim it |
| Money in Latin digits | Matches the server's `money` output exactly; avoids two representations of a balance | Bengali-numeral preference would need a formatter flag |
| Material Symbols Rounded | Already a dependency; no new icon library | Small difference from the web's Lucide; a curated vector set can close it |
| Cards without shadows | Site's shadows are subtle; Android elevation renders muddy on cheap panels | Less "floating" feel; borders carry separation |
| Gold limited to attention marks | Gold-on-white fails contrast; scarcity makes it meaningful | Slightly less gold than the web's accent usage |
| No charts on Home | The API returns counts, not series; inventing one would fake data | A future dashboard chart needs new API fields |

### 12.2 Risks

| Risk | Mitigation |
|---|---|
| Role-only gating diverges from web permissions | `RoleCapabilities` map mirrors the API's checks; a 403 always shows the permission screen; follow-up adds `permissions[]` |
| Status labels duplicated in Kotlin | One `StatusStyle` file + a unit test enumerating every status in `StatusPresenter`; optional server `status_label` |
| 200% font scale breaks the queue rail | Rail uses weights + `IntrinsicSize` and drops to a 2+1 layout below 320dp equivalent; verified in Phase 4 |
| Bottom bar with 4 items + "আরও" on small phones (320dp) | Labels ≤2 words, 12sp, `NavigationBar` measured at 320dp; falls back to 3 tabs + "আরও" |
| Sticky action bar overlapping gesture inset | `Modifier.imePadding()`/`navigationBarsPadding()` on the bar; sheet content padded by the bar height |
| Design drifts during implementation | The board is the review artifact; phases end with a side-by-side check |

---

## 13. Review checklist (for the PR that implements each phase)

- [ ] Emerald is used for chrome + one action; gold appears at most twice per screen.
- [ ] No status is conveyed by colour alone; in-flight states use the ring.
- [ ] Every amount goes through `Money.format`; every timer through `Date.relative`.
- [ ] No screen invents a value the API does not return.
- [ ] All taps ≥48dp; TalkBack labels include counts, amounts and the verb.
- [ ] Light, dark, and 200% font scale checked for the changed screen.
- [ ] Sheet dismiss cannot drop an in-flight money action.
- [ ] No new backend call added to a critical balance path; no retries on money POST.
- [ ] Existing unit tests untouched and green; no token/PII in logs.
