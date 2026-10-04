# Reference UI Analysis — tools.totthohub.com

> **Ethics note.** This document records only *publicly observable UX structure* (layout,
> navigation, component hierarchy, responsive patterns). No proprietary source code, no
> private credentials, no real users' NID/voter/TIN data and no scraping of sensitive
> records was captured. All data used in this project is clearly-marked dummy data.
> Reference inspected: public landing/auth pages and the owner-provided dashboard
> (account supplied by the site owner), observed on 2026-09-26 at desktop/mobile widths.

---

## 1. Global design language

| Aspect | Observation |
|---|---|
| Language | Bengali (bn-BD) UI copy, right-to-left-friendly numerals |
| Font | Bengali-capable sans (Noto Sans Bengali family look), bold headings |
| Brand color | Deep emerald/green gradient (header/hero/footer), gold/amber accent |
| Surfaces | White cards on very light gray background |
| Radius | Medium-large (10–16px), pill badges |
| Shadows | Soft, low-opacity green-tinted shadows |
| Icons | Small monochrome inline icons in buttons/nav; emoji in promo banners |
| Buttons | Solid green primary, gold accent, ghost/outline on dark, small size variant |
| Badges | Pill-shaped status/tag chips (e.g. "ইনস্ট্যান্ট ডেলিভারি", "PDF কপি") |
| Density | Compact: tight card padding, small text, high information density |

## 2. Page inventory

### 2.1 Auth page (`/auth.php`)
- **Purpose:** Combined login/registration entry + product pitch.
- **Layout:** Dark green gradient full-screen; top brand bar (logo left, "টেলিগ্রাম চ্যানেল" link right); centered single-column content; footer with copyright + About/Privacy/Refund links.
- **Components:**
  - Brand bar (logo, telegram channel button).
  - Marketing block: platform name, big headline, descriptive paragraph.
  - Three feature highlights (secure / instant delivery / 24-7 support) with icons.
  - Two secondary link-buttons (official channel, support center).
  - **Tabbed login/registration card** (two pill toggle buttons লগইন / নিবন্ধন).
  - Login form: identifier field (user / mobile / API key), password (with visibility toggle), "remember me 30 days" checkbox, forgot-password link, wide primary submit.
  - Urgent-notice modal on load ("জরুরি নোটিশ") with body text, telegram link, accept button.
- **Interactions:** tab switch login↔register; modal dismiss; password visibility; checkbox default-checked.
- **Responsive:** single column at all sizes; card becomes near full-width on mobile; feature highlights stack.
- **Backend data:** session auth, CSRF, remember-me token, notice content (admin-manageable).

### 2.2 Dashboard / service hub (`/index.php`, logged in)
- **Purpose:** Main hub — stats, promotions, service discovery and quick access.
- **Layout:** Dark green **top navbar** (no persistent sidebar!): logo left; right side balance chip "৳ 0.00", notification bell, avatar dropdown. Below navbar, a green **welcome banner**: "স্বাগতম, {user}!", description, quick-link buttons (রিচার্জ করুন / অফিসিয়াল কপি / স্ক্রিপ্ট শপ / আমার অর্ডার). Content on light background.
- **Components:**
  - Two **promo cards** (offer badge, title, blurb, CTA link) side by side.
  - Three **stat cards** (balance, free searches left, successful searches) + total orders card — small white cards, big number, muted label.
  - **Search bar** with placeholder + a search icon button.
  - **Category filter chips** (সকল সার্ভিস / এনআইডি ও ভোটার / টিন সার্টিফিকেট / লোকেশন ও ট্র্যাকিং / বায়োমেট্রিক ও সিম / মোবাইল ব্যাংকিং / কল লিস্ট (CDR) / জন্ম নিবন্ধন / পাসপোর্ট ও BMET) — horizontal wrap, active state.
  - **Service card grid** (2–4 columns): each card = price (৳, accent color), title, 1–2 line description, small pill tag (delivery type), CTA link ("বের করুন →" / "অর্ডার দিন →" etc.), thin top accent or icon.
  - Footer: copyright + telegram/support links.
- **Interactions:** category chip filters grid (instant, client-side); search narrows cards; avatar dropdown (profile/logout); notification dropdown; promo CTAs navigate.
- **Responsive:** navbar collapses (hamburger at mobile); banner buttons wrap; cards 1-col mobile / 2-col tablet / 3–4-col desktop; chips scroll or wrap on mobile.
- **Backend data:** user balance, unread notification count, stats (orders, searches), service categories, services (name, slug, price, description, badge, icon, sort).

### 2.3 Service action pages (inferred pattern)
- **Purpose:** Per-service order form → result.
- **Layout:** Same navbar + banner; breadcrumb-ish back link; centered card with service title, price, fields, submit; result panel after submit; history table of previous orders.
- **Components:** labeled inputs, helper text, price box, submit with loading state, result block (copyable), history table.
- **Backend data:** service definition, field schema, user balance, order history.

### 2.4 Account pages (inferred pattern)
- Profile (update contact info, password change), Orders/Transactions table (id, service, amount, status, date), Search history, Notifications list, Recharge/balance page.
- **Components:** reusable responsive table (sortable columns, pagination, empty state), forms, status tag chips.

## 3. Component vocabulary (to re-implement independently)

1. **Navbar** — brand, balance chip, bell w/ dropdown, avatar menu, mobile hamburger drawer.
2. **Welcome banner** — greeting, subtitle, action buttons.
3. **Stat card** — icon, value, label.
4. **Promo card** — badge, title, blurb, CTA.
5. **Service card** — price, title, description, tag, CTA.
6. **Category chips** — filter row with active state.
7. **Search input** — icon, placeholder, debounced filtering.
8. **Table** — header row, status tags, hover, empty state, mobile overflow.
9. **Form controls** — label, input, focus ring (green), error text, submit button with spinner.
10. **Modal** — notice/confirm dialogs, dark overlay.
11. **Toast/alert** — success/error feedback.
12. **Footer** — copyright + links.
13. **Empty state** — icon + text ("no orders yet").
14. **Loading state** — button spinner / skeleton rows.

## 4. Typography hierarchy
- Page/hero title: 28–40px extra-bold.
- Section title: 20–24px bold. Card title: 16–18px semibold.
- Body: 14–16px regular, muted gray for descriptions. Meta/labels: 12–13px.

## 5. Spacing & rhythm
- Page horizontal padding 16–24px; card padding 16–24px; grid gap 16–24px;
  vertical section rhythm 24–48px; radius 10–16px; borders 1px light.

## 6. Color tokens observed
- `primary` deep green (~#0f4c38 family), `primary-strong` darker gradient stop,
  `accent` gold/amber, `surface` white, `background` light gray-green,
  `muted-text` gray, `border` light gray-green, `danger` red, `success` green,
  `warning` amber, `info` blue. Status tag colors: amber (pending), blue (processing),
  green (done), red (cancelled).

## 7. Responsive behavior notes
- Breakpoints effectively: <640 (stack everything, hamburger), 640–1024 (2-col cards),
  ≥1024 (3–4-col cards, full navbar). Tables scroll horizontally on mobile.
- Touch targets remain ≥40px; chips wrap into rows on mobile.

## 8. Page → backend data requirements (summary)

| Page | URL pattern | Required data |
|---|---|---|
| Landing/About/Terms/Privacy | `/`, `/about`, `/privacy`, `/terms` | static copy, SEO meta |
| Login/Register | `/login`, `/register` | CSRF, throttle counters, session |
| Dashboard | `/dashboard` | user, balance, stats, categories, services |
| Category listing | `/services/{category}` | categories, filtered services |
| Service detail | `/services/view/{slug}` | service, field schema, balance, history |
| Orders | `/transactions` | paginated transactions |
| Notifications | `/notifications` | paginated notifications, unread count |
| Profile | `/profile` | user record, activity log |
| Admin | `/admin/*` | users, categories, services, transactions, logs |

## 9. Deviations for our build (deliberate)
- Add a **persistent left sidebar** for dashboard sections (the brief requires sidebar +
  collapsible groups); reference uses top-nav only. We keep the top navbar *and* add the
  sidebar for information architecture, matching the requested "Service hub / Account" groups.
- English + Bengali copy mixed: keep Bengali-flavored UI text for authenticity but
  structure labels in English code.
- Service execution is mocked — results carry an explicit notice, no real government lookups.
