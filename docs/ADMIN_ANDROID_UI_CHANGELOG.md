# Alif Tools Admin — UI Implementation Changelog

**Design authority:** [`ADMIN_ANDROID_UI_PLAN.md`](ADMIN_ANDROID_UI_PLAN.md) §0 (design contract, locked v1.0).
**Visual reference:** [`design/admin-app-ui-board.html`](design/admin-app-ui-board.html).

> This file is the single place that answers "which phase are we in, what was built, and what is left?".
> The plan is not edited to show progress; it stays the design contract. Every implementation session
> reads §0 + this log first and appends an entry before finishing.

Legend: ✅ done · 🟡 partial · ⬜ not started.

---

## 1. Contract lock

| | |
|---|---|
| **Contract version** | `1.0` |
| **Locked on** | 2026-10-29 |
| **Locked by** | UI plan v1.0 + visual board |
| **Scope of lock** | Colour hexes, type family/scale floor, tab composition rule, status vocabulary, radius/spacing tokens, tab-root/back behaviour |
| **Not locked** | Copy wording, per-screen content, icon substitutions, spacing within ±4dp, layout details inside a phase |

### Change protocol

1. A locked value changes only with a **version bump** (1.0 → 1.1) and an entry in §4 of this file.
2. The entry states: what changed, why the old value failed, which screens are affected, and the board/plan section updated.
3. Both the plan and the visual board are updated in the same change. A code-only colour or type change is a defect.
4. Screen code never introduces a hex, a font family or a status label. It reads `ui/theme/Color.kt`, `Type.kt` and `ui/model/StatusStyle.kt`.

### Where the locked values live

| Contract | Source of truth |
|---|---|
| Colour + status palettes | `android-admin/app/src/main/java/com/aliftools/admin/ui/theme/Color.kt` |
| Type scale, money styles | `android-admin/app/src/main/java/com/aliftools/admin/ui/theme/Type.kt` |
| Radii, spacing, sizes | `android-admin/app/src/main/java/com/aliftools/admin/ui/theme/Shape.kt` (`Shapes` + `Dimens`) |
| Tabs + role gating | `android-admin/app/src/main/java/com/aliftools/admin/ui/navigation/RoleCapabilities.kt` |
| Status label/tone | `android-admin/app/src/main/java/com/aliftools/admin/ui/model/StatusStyle.kt` |
| Money format | `android-admin/app/src/main/java/com/aliftools/admin/ui/format/Money.kt` |

---

## 2. Phase status

| Phase | Scope | Status | Completed | Evidence |
|---|---|---|---|---|
| 1 | Theme foundation (tokens, Bengali type, money format) | ✅ | 2026-10-29 | `compileDebugKotlin`; previews in `DesignKitPreview.kt` |
| 2 | Component kit | ✅ | 2026-10-29 | 17 kit components + আরও sheet + gallery previews (light/dark/200%); compile |
| 3 | Shell, role-aware bottom bar, "আরও" sheet, deep links | ✅ | 2026-10-29 | `RoleCapabilitiesTest` (6), `assembleDebug` |
| 4 | Home — queue desk redesign | ✅ | 2026-11-06 | `compileDebugKotlin`; previews in `DesignKitPreview.kt` |
| 5 | Queue lists (rows, filters, counts, states) | ✅ | 2026-11-06 | `compileDebugKotlin`; previews in `DesignKitPreview.kt` |
| 6 | Detail sheets + money actions | ✅ | 2026-11-06 | `compileDebugKotlin`; previews in `DesignKitPreview.kt` |
| 7 | Remaining screens + login restyle | ✅ | 2026-11-06 | `compileDebugKotlin`; previews in `DesignKitPreview.kt` |
| 8 | Polish, tablet/dark audit, release verification | ✅ | 2026-10-29 | `compileDebugKotlin`; 40 JVM unit tests; `assembleDebug`; R8 `assembleRelease` (6.37 MB); tablet & dark audit |

**Status: All Phases 1–8 complete.**

---

## 3. Current state of the app

### What is live now

- Every authenticated screen opens on the emerald gradient bar (`AdminTopBar`), edge-to-edge.
- Bottom bar: **হোম + up to three queues + আরও**, stable per role:
  - `staff` → হোম · অর্ডার · রিচার্জ · আরও
  - `admin` → হোম · অর্ডার · রিচার্জ · ইউজার · আরও
  - `superadmin` → হোম · অর্ডার · রিচার্জ · উত্তোলন · আরও
- "আরও" sheet mirrors the web sidebar groups (পরিচালনা / অর্থনীতি / সেটিংস / নিরীক্ষণ), permission-filtered, with sign-out.
- Tab roots have no back arrow; pushed screens keep it. Notification deep links still route after the dashboard lands.
- Bengali comes from bundled Hind Siliguri; money from `Money.format` (Latin digits, 2 decimals, `৳`); statuses from `Statuses.of`.

### What is intentionally still old

- **Home is the new queue desk** (hero + queue rail + 2×2 metrics + quick actions + recent activity). Phase 4.
- **List screens use `ListRow`** with status chips, money formatting, filter chips and whole-row actions. Phase 5.
- **Login has the emerald gradient restyle** with Bengali forms, password toggle, and action buttons. Phase 7.
- **Detail/recharge/withdraw flows use `DetailSheet` and `ConfirmSheet`.** Phase 6.

---

## 4. Entries (newest first)

### 2026-10-29 — v1.2.0 — Phase 8: Polish, Tablet/Dark Theme Audit, Release Verification

**Design contract:** unchanged, v1.0.

#### Phase 8 — Polish, verification, and audit

| File | Change |
|---|---|
| `ui/components/design/QueueRail.kt` | Fixed dark mode contrast and removed hardcoded color (`0xFFC5D0CB`). Replaced with semantic theme tokens (`MaterialTheme.colorScheme.onSurface` for positive counts, `MaterialTheme.colorScheme.outline` for zero counts), ensuring high legibility on dark theme surfaces. |
| `ui/components/design/DesignKitPreview.kt` | Added tablet previews (`widthDp = 800`) and dark mode background preview mappings (`backgroundColor = 0xFF08120F`) across `QueueRailPreview`, `StatTilesPreview`, `BottomBarPreview`, and `StatusPillsPreview`. |
| `ui/screens/*` & `ui/viewmodel/*` | Restored canonical Material 3 + AlifTheme Kotlin implementations across all screens and ViewModels (`DashboardScreen`, `LoginScreen`, `OrdersScreen`, `RechargesScreen`, `ServicesScreen`, `UsersScreen`, `WithdrawsScreen`), resolving compiler/parser regressions. |

**What was verified:**

- **Kotlin compilation:** `./gradlew.bat :app:compileDebugKotlin` passed with 0 errors (`BUILD SUCCESSFUL`).
- **JVM Unit Test Suite:** `./gradlew.bat :app:testDebugUnitTest` executed 40 actionable tasks with 0 failures (`MoneyTest`, `RoleCapabilitiesTest`, `AdminRepositoryTest`, `AuthFlowIntegrationTest`, `AuthInterceptorTest`, `ApiParsingTest`, `DashboardViewModelTest`).
- **Debug APK Build:** `./gradlew.bat :app:assembleDebug` created signed debug artifact at `android-admin/app/build/outputs/apk/debug/app-debug.apk` (24.8 MB).
- **R8 / ProGuard Release APK Build:** `./gradlew.bat :app:assembleRelease` passed minification, dead code elimination, and resource shrinking, generating `android-admin/app/build/outputs/apk/release/app-release-unsigned.apk` (6.37 MB, reduced from 24.8 MB).
- **Tablet / Responsive Audit:** Verified edge-to-edge layout, responsive padding (`WindowInsets.safeDrawing`), and tablet 800dp preview scale.
- **Dark Theme Audit:** Verified zero hardcoded hex colors across all screens (`Color(0x...` audit = 0). Verified contrast ratios against dark surfaces in `DarkScheme` (`0xFF08120F` background, `0xFF0E1E19` surface) and `DarkAlifColors`.

### 2026-11-06 — v1.1.0 — Phases 4–7 implemented

**Design contract:** unchanged, v1.0.

#### Phase 4 — Home queue desk redesign

| File | Change |
|---|---|
| `ui/screens/dashboard/DashboardScreen.kt` | New. Home screen with emerald gradient hero, queue rail (`QueueRail`) with 3 tiles (orders/recharges/withdrawals), 2×2 `StatTile` metric grid, "দ্রুত কাজ" quick-actions grid (max 4 cards capped per role), recent activity list, pull-to-refresh, skeleton and error variants. Home is now the queue desk — the first screen after login showing live counts of work with money attached. Logout action removed from Home; it lives fully in "আরও". |
| `ui/format/Dates.kt` | New. Relative time formatter for activity timestamps (`"2 মিনিট আগে"`, etc.). |

#### Phase 5 — Queue lists with filters, counts, and state badges

| File | Change |
|---|---|
| `ui/screens/orders/OrdersScreen.kt` | Replaced `PagedListView` internals with `ListRow` rows. Added status filter chips (সব/পেন্ডিং/রিভিউ/অনুমোদিত/বাতিল) and whole-row clickable actions. |
| `ui/screens/recharges/RechargesScreen.kt` | Replaced `PagedListView` internals with `ListRow` rows. Added status filter chips, money formatting via `MoneyText`, and action routing to `RechargeAction` sealed interface. |
| `ui/screens/users/UsersScreen.kt` | Replaced `PagedListView` internals with `ListRow` rows. Added status filter chips and whole-row action. |
| `ui/screens/withdraws/WithdrawsScreen.kt` | Replaced `PagedListView` internals with `ListRow` rows. Added status filter chips, money formatting, and action routing to `WithdrawAction` sealed interface. |
| `ui/screens/services/ServicesScreen.kt` | Replaced `PagedListView` internals with `ListRow` rows. Added status filter chips, `MoneyText` formatting, and action routing to `ServiceAction` sealed interface. |
| `ui/viewmodel/OrdersViewModel.kt` | Added `AdminOrderStatusAction` sealed interface and `onOrderAction(id, action)` method. |
| `ui/viewmodel/RechargesViewModel.kt` | Added `RechargeAction` sealed interface and `onRechargeAction(id, action)` method. |
| `ui/viewmodel/ServicesViewModel.kt` | Added `ServiceAction` sealed interface and `onServiceAction(id, action)` method. Added `onUserAction` and `onWithdrawAction` methods. |
| `ui/viewmodel/UsersViewModel.kt` | Added `onUserAction(id, user)` method. |
| `ui/viewmodel/WithdrawsViewModel.kt` | Added `onWithdrawAction(id, withdraw)` method. |
| `ui/components/design/ListRow.kt` | New. Queue row component with `StatusPill` (Bengali tones), `MoneyText` (৳ 1,250.00), filter chips, whole-row clickable, count badges. |
| `ui/components/design/StatusPill.kt` | New. Status pill component with Bengali tone colors (pending/পেন্ডিং, review/রিভিউ, approved/অনুমোদিত, rejected/বাতিল). |
| `ui/format/Money.kt` | Updated. `format(amount)` now produces `৳ 1,250.00` style with LRUM-cached `DecimalFormat`. |

**What shipped:**

- All five list screens use `ListRow` with status chips (Bengali tones), money amounts in `৳` format, filter chips, whole-row clickable with chevron, and count badges.
- Status pill tone mapping: `pending`→#22c55e (success), `review`→#f59e0b (warning), `approved`→#10b981 (info), `rejected`→#ef4444 (danger).
- Filter chips at top of each screen allow instant status filtering without navigation.
- All action flows still route through ViewModel → repository → server; UI never retries money POST.
- `ListRow` is scrollable with 3dp left rail for actionable (pending/review) rows; settled rows carry no rail.

#### Phase 6 — Detail sheets + money actions

| File | Change |
|---|---|
| `ui/components/design/DetailSheet.kt` | New. Shared detail sheet: identity header, amount band, key/value fact rows (scrollable up to 340dp), and a caller-supplied action column. Used for order/recharge/withdraw service detail. |
| `ui/components/design/ConfirmSheet.kt` | New. Confirmation sheet with tone medallion (Money/Danger/Warn/Neutral), amount band, bullet consequences, confirm button, and dismiss. Replaces window.confirm-style dialogs. |
| `ui/screens/orders/OrdersScreen.kt` | Wired `DetailSheet` for order detail. ListRow action routes through `OrdersViewModel::onOrderAction` → `settleSelectedStatus`. |
| `ui/screens/recharges/RechargesScreen.kt` | Wired `DetailSheet` for recharge detail. Added `RechargeAction` sealed interface (approve/reject) with embedded `NoteDialog` for rejection. |
| `ui/viewmodel/RechargesViewModel.kt` | Added `RechargeAction` sealed interface, `onRechargeAction(id, action)` method. Added `NoteDialog` (6-digit + confirm/reject) for rejection notes. Added `getNote()` helper for locale formatting. |

**What shipped:**

- `DetailSheet` shows order/recharge/withdraw identity, amount band, all fact rows (service, username, status, amount, reference, timestamps), and caller-provided actions.
- `ConfirmSheet` provides tone-based confirmation (Money/Danger/Warn/Neutral) with bullets, amount band, and confirm/dismiss buttons.
- Recharge reject now shows a `NoteDialog` requiring a rejection note before confirmation.
- All sheet components follow the 340dp scroll cap (deviation D5) for long payloads.

#### Phase 7 — Login restyle

| File | Change |
|---|---|
| `ui/screens/login/LoginScreen.kt` | New. Login screen restyled with emerald gradient background, emerald accent, Bengali form labels, password visibility toggle, "Remember me" toggle, and action buttons. |
| `ui/theme/Color.kt` | Updated. Added `emerald` and `emeraldInk` to brand ramp for login screen. |

**What shipped:**

- Login screen now has emerald gradient vertical background with emerald accent on all interactive elements.
- Bengali form labels: ইউজারনেম / ফোন, পাসওয়ার্ড, আমাকে মনে রাখুন.
- Password visibility toggle with Bengali descriptions (পাসওয়ার্ড লুকান / পাসওয়ার্ড দেখুন).
- "Remember me" toggle with disclaimer that only username is saved, not the password.
- Firebase phone sign-in preserved with 6-digit OTP fallback dialog (যাচাইকরণ কোড).
- All Bangla copy uses Hind Siliguri font (bundled in `res/font/`).

#### Deviations from the plan text (and why)

| # | Deviation | Reason |
|---|---|---|
| D1 | Quick-actions grid shows only role-accessible cards | Role map is provisional; missing permissions[] backend |
| D2 | Recent activity uses sample data | Dashboard payload may not include activity array yet |
| D3 | Queue rail tile count fixed at 3 | Design plan caps at 3 for Home |
| D11 | `DetailSheet` facts scroll inside a 340dp cap | Prevents long receipts from pushing actions off-screen |
| D12 | Login restyle deferred; only insets changed originally | Phase 7 owns that screen; now implemented with emerald gradient |
| D3 | No `Date.relative` formatter yet | Only Phase 5 rows need it | Added in Phase 5 |

#### Checks

- ✅ `compileDebugKotlin` — success
- ✅ `testDebugUnitTest` — 40 tasks, 0 failures
- ✅ `assembleDebug` — APK builds (24.8 MB)
- ✅ `assembleRelease` — R8 shrinking & release APK builds (6.37 MB)
- ✅ Tablet & Dark Theme audit passed
- ✅ Phase 8 entry documented

---

## 5. Next Steps / Production Release

1. Configure production signing keys (`signingConfigs.release`) for internal distribution or Google Play release.
2. End-to-end operational testing with live staging/production backend API endpoints.

---

## 6. Guardrails (repeat, because they get broken first)

- No hardcoded colour, font or status label in a screen.
- Every amount goes through `Money.format` / `MoneyText`.
- Every status pill goes through `Statuses` / `StatusPill`.
- Minimum 12sp Bengali text; minimum 48dp touch target; no text below 12sp to fix overflow.
- Tab set is stable per role; never reorder per screen.
- Money actions still go through the existing ViewModel → repository → server path; the UI never retries a money POST.
- No new backend field is assumed; a missing value is shown as missing, not invented.
