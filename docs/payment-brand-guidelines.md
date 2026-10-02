# Payment Brand Guidelines — bKash, Nagad, Rocket

> **Scope.** The three mobile financial services accepted as recharge methods. This
> document records what we ship, where each value comes from, and the rules that
> keep a brand recognisable inside the product.
>
> **It is not a trademark licence.** bKash, Nagad and Airtel Rocket are third-party
> marks. We use them nominatively, to name the service a customer is about to pay.
> Nothing here grants permission to redesign, stretch, or set a mark on a background
> the operator has not approved. See §8.

Branch: `main`. Every claim below cites the working tree in this checkout.

---

## 1. The one rule that governs all of this

**There is exactly one place a brand colour is written by hand:**
[`src/Service/PaymentBrand.php:56`](../../src/Service/PaymentBrand.php#L56).

```php
public const BRANDS = [
    'bkash'  => ['color' => '#E2136E', 'logo' => '/assets/brand/payment/bkash.svg'],
    'nagad'  => ['color' => '#EE1C25', 'logo' => '/assets/brand/payment/nagad.svg'],
    'rocket' => ['color' => '#8B3392', 'logo' => '/assets/brand/payment/rocket.svg'],
];
```

Everything else — the `.pay-*` CSS custom properties, the step-1 picker card, the
step-2 payment panel, the history dots — is derived from that table. To change a
brand, edit `BRANDS` and rebuild:

```bash
php scripts/generate-pay-palette.php   # or: npm run build
```

`PaymentBrandPaletteTest` asserts the committed CSS still matches the table, so a
changed colour **cannot** merge without its stylesheet following. That test is the
reason the rest of this document is short.

---

## 2. Brand summary

| Key | Display label | Base colour | Logo file | CSS class |
|---|---|---|---|---|
| `bkash` | bKash | `#E2136E` — magenta-pink | `bkash.svg` | `.pay-bkash` |
| `nagad` | Nagad | `#EE1C25` — red | `nagad.svg` | `.pay-nagad` |
| `rocket` | Rocket | `#8B3392` — purple | `rocket.svg` | `.pay-rocket` |
| *(any other)* | — | `#0D8F68` — site primary | none | `.pay-default` |

- **Keys** must match `TopupRepository::METHODS`, the list the submit path validates
  against. `PaymentBrandTest::testKeysMatchTheRepository` enforces it, and
  `PaymentBrand::missingFromRepository()` reports drift.
- **Labels** deliberately live in `SettingsRepository::METHOD_LABELS`, not here.
  That constant was already the single source of truth for the display name;
  duplicating "bKash" here would give the two spellings a way to drift.
  `PaymentBrand::all()` merges them.
- **`.pay-default`** is the unbranded fallback so `cssClass()` can return a class
  name for a method it does not know, and the card still renders in the site
  primary rather than with every `var(--pay)` resolving to nothing.

---

## 3. Logo sources and provenance

All three marks are local files under `public/assets/brand/payment/`, referenced by
the `logo` key above and served from `/assets/brand/payment/<file>`.

> **These files are currently untracked in git** (`git status` reports
> `?? public/assets/brand/`). `public/assets/*` is gitignored; `brand/` and `img/`
> are explicitly un-ignored in `public/assets/.gitignore` so the marks can be
> committed. **They must be added to the repository or every deploy loses them.**

### Verification status — read this before trusting the table in §2

None of these three operators publishes a downloadable brand-asset kit for
merchant integrations; their brand pages are marketing sites. The values below were
therefore verified by **extracting the hex literals out of the official vector
artwork itself** — a stronger check than a third-party colour-picker site, because
it is the mark's own file, not a description of it.

| Brand | Stored value | Hex found in the artwork | Verdict |
|---|---|---|---|
| bKash | `#E2136E` | `#e2136e` ×3 (plus `#d12053` ×3, `#9e1638` ×2, `#231f20` ×1) | **Exact match** |
| Rocket | `#8B3392` | `rgb(54.509807%,20%,57.254905%)` = `#8B3392` | **Exact match** |
| Nagad | `#EE1C25` | `#ed1c24` ×5, `#f7941d` ×5 (also `#eb2329` ×2, `#f89621` ×2) | **Near-miss — see §7** |

`#231f20` is a warm near-black shared by the bKash and Nagad wordmarks; it is text
colour inside the artwork, not a brand colour, and is not in the brand table.

### Per-file detail

| File | Bytes | `viewBox` | Notes |
|---|---|---|---|
| `bkash.svg` | 2,410 | `-5.254 9.785 257.587 93.839` | Flat `fill` attributes, no `<style>`. The four-colour wordmark. |
| `nagad.svg` | 4,600 | `-6.385 13.739 312.759 103.553` | Illustrator export; carries **two** competing `<style>` blocks defining `.st0`/`.st1` at `#eb2329`/`#f89621` and `#ed1c24`/`#f7941d`. Both are live; the later one wins per cascade. This is the source of the §7 ambiguity. |
| `rocket.svg` | 32,573 | `0 0 200 126` | Illustrator export with inline `style="fill:rgb(…%)"` on every path. **Not optimised** — see the note below. |

**`rocket.svg` should be run through an SVG optimiser before a bandwidth-sensitive
deploy.** At 32 KB it is roughly 14× the size of the bKash mark for one logo on one
page. `npx svgo --multipass public/assets/brand/payment/rocket.svg` typically cuts
this by an order of magnitude with no visual change. The other two are already
hand-flattened and are not worth it.

### Re-acquiring a mark

If a mark ever needs replacing, take it from the operator's own site and re-run the
§3 verification: download the SVG, `grep -oE '#[0-9a-f]{6}'` it, and confirm the
primary fill still matches the `color` key in `BRANDS`. If the artwork has moved to a
new colour, that is a brand change, not a file swap — update `BRANDS`, rebuild, and
run the palette test.

---

## 4. The generated token palette

Only `--pay` is the brand colour. The other four tokens are **fixed tints of the
brand's own hue**, computed by
[`PaymentBrandPalette`](../../src/Service/PaymentBrandPalette.php) — never written by
hand, because a hand-written table drifts against its own source of truth.

| Token | Light mode | Dark mode | Role |
|---|---|---|---|
| `--pay` | the brand colour, verbatim | lifted to a readable tint | dots, borders, accents |
| `--pay-soft` | `H 94% S 62%` | brand shade pushed toward black | selected-card / amount-panel fill |
| `--pay-line` | `H 84% S 80%` | 70% of brand lightness | border on `--pay-soft` |
| `--pay-ink` | brand lightness − 0.22, clamped `0.18–0.38` | lightness + 0.40 at `S 0.15` | text drawn on `--pay-soft` |
| `--pay-on` | white, unless dark wins on contrast | dark end of the hue | text on a solid `--pay` fill |

Resolved values, verbatim from the generated block in
[`resources/css/app.css`](../../resources/css/app.css) between the
`generated by scripts/generate-pay-palette.php` markers:

**Light**

```css
.pay-bkash  { --pay: #e2136e; --pay-soft: #f8e8ef; --pay-line: #f2bbd3; --pay-ink: #780d3c; --pay-on: #ffffff; }
.pay-nagad  { --pay: #ee1c25; --pay-soft: #f8e8e8; --pay-line: #f2babd; --pay-ink: #8c0e13; --pay-on: #ffffff; }
.pay-rocket { --pay: #8b3392; --pay-soft: #f4ebf4; --pay-line: #e4c6e6; --pay-ink: #401943; --pay-on: #ffffff; }
.pay-default{ --pay: #0d8f68; --pay-soft: #e8f8f3; --pay-line: #bbf1e1; --pay-ink: #0a523c; --pay-on: #ffffff; }
```

**Dark** — same hues, re-lit for a dark card:

```css
.dark .pay-bkash  { --pay: #f075ab; --pay-soft: #390c20; --pay-line: #901c4f; --pay-ink: #e4dde0; --pay-on: #4a0c27; }
.dark .pay-nagad  { --pay: #f3878c; --pay-soft: #3e0d0f; --pay-line: #9d1d22; --pay-ink: #eee8e9; --pay-on: #510c0f; }
.dark .pay-rocket { --pay: #c472ca; --pay-soft: #241126; --pay-line: #5c2a60; --pay-ink: #ccc5cc; --pay-on: #2f1431; }
.dark .pay-default{ --pay: #51ebbd; --pay-soft: #08241b; --pay-line: #125b45; --pay-ink: #abbdb8; --pay-on: #082f23; }
```

The `.pay-*` rules are **outside `@layer` components** on purpose: their selectors
are assembled at runtime (`pay-{{ key }}` in Twig), and a Tailwind utility in
`@layer` would outrank them regardless of source order.

---

## 5. Contrast — measured, not asserted

Ratios below are produced by `PaymentBrandPalette::contrastRatio()` (WCAG 2.x) against
the values actually shipped above.

| Brand | White on `--pay` (light) | `--pay-ink` on `--pay-soft` | `--pay` on `--pay-soft` (border) | `--pay-on` on `--pay` (dark) |
|---|---|---|---|---|
| bKash | **4.61** ✅ | 9.25 | 3.91 | 5.71 |
| Nagad | **4.35** ⚠️ | 8.09 | 3.67 | 6.14 |
| Rocket | **7.03** ✅ | 12.54 | 6.04 | 5.22 |

- Text pairs clear **AA (4.5:1)**, except Nagad.
- Border pairs clear **AA non-text (3:1)** everywhere.
- Dark-mode text pairs clear **AA** everywhere with a wide margin.

### The Nagad exception, deliberately

`.pay-accent-bg` is a `text-sm font-semibold` button, so AA requires 4.5:1 — and
Nagad's published red reaches only **4.35:1 against white**, and 2.9:1 against the
dark tint of itself. The fallback rule
([`PaymentBrandPalette.php:136`](../../src/Service/PaymentBrandPalette.php#L136))
picks the more readable of white / a dark tint of the same hue, so it keeps white and
stops at 4.35 rather than recolouring the brand.

**Recolouring Nagad to reach 4.5 is not an option** — it would mean shipping a red
the operator does not use. The accepted trade-off is recorded here and pinned by a
test floor, so a future tweak to the threshold fails loudly rather than silently
regressing the worst case.

---

## 6. Usage rules

### Do

1. **Identify by logo + brand colour together.** The picker draws the mark above the
   label rather than tinting a generic list item
   ([`recharge.twig:76`](../../resources/views/site/account/recharge.twig#L76)).
2. **Let `payment_brand()` supply the mark.** It checks `is_file()` and returns
   `null` for a missing logo, so a deploy that shipped the code without the assets
   shows the initials tile instead of a broken image in the middle of a payment form
   ([`TwigExtension.php:38`](../../src/Twig/TwigExtension.php#L38)).
3. **Build the class name with `cssClass()`**, never by string-concatenating the key
   in a template — it is what routes an unknown method to `.pay-default`.
4. **Keep the brand colour as a `var(--pay)` consumer.** Components read the token,
   never a hex literal. The tokens are the only reason a recolour is one edit.
5. **Show the operator's own instructions verbatim on the pay step.** The number the
   customer sends to is the one thing we cannot style or validate.

### Don't

1. **Don't recolour a brand to fit a layout.** A method painted in the site green
   reads as an anonymous form; picking the wrong wallet is an expensive support
   ticket because the money lands in a number the operator never checks.
2. **Don't hand-write a tint.** Every `--pay-soft` / `--pay-line` / `--pay-ink` /
   `--pay-on` in the stylesheet is generated. A literal there is a drift bug.
3. **Don't draw the mark on a brand colour.** `--pay-soft` is a fill *behind* the
   logo, never a background for it — the wordmarks carry their own dark text and
   lose it on a saturated fill.
4. **Don't use `--pay` for body copy.** It is an accent token, not a text token; text
   belongs on `--pay-ink` or `--pay-on`.
5. **Don't stretch, outline, add effects to, or crop a mark.** The `.pay-mark` and
   `.pay-mark-sm` classes fix the rendered size; `object-fit` must stay `contain`.
6. **Don't cache a brand colour in a constant elsewhere.** The one-table rule in §1 is
   only enforceable while it is true.

---

## 7. Open item: the Nagad colour does not match its own artwork

**This is unresolved and needs an operator decision. It was not changed unilaterally.**

- `BRANDS['nagad']['color']` is `#EE1C25`.
- `nagad.svg` contains **`#ed1c24`** — one green step away, and the value that the
  *later* of its two `<style>` blocks assigns to `.st0`.
- A third-party brand reference gives the Nagad red as **`#EB2329`**, which is the
  *earlier* style block in the same file.

So the artwork ships two reds and we are using neither. Additionally, the comment at
[`PaymentBrand.php:48`](../../src/Service/PaymentBrand.php#L48) says the Nagad mark
is "paired with #F6921E orange" — **that hex does not appear in the artwork**, whose
orange is `#F7941D`.

Three options, in order of preference:

1. **Change the table to `#ED1C24`** — the value in our own official artwork, one step
   from what we ship, and the one Nagad's live site uses. Cheapest, and it makes the
   stored colour machine-verifiable by the §3 check. Then update the comment's
   orange to `#F7941D`.
2. **Ask the operator** for their published merchant-asset values, and treat those as
   authoritative over anything in this repository.
3. **Leave it.** A 1/255 delta is not perceptible; the risk is that the next person
   to "fix" it picks `#EB2329` from a third-party site and drifts further.

Whatever is chosen, the change is one edit to `BRANDS`, one rebuild, and one green
palette test.

---

## 8. Trademark note

The marks belong to bKash Limited, a2i Technologies Ltd (Nagad) and Bharti Airtel
Ltd (Rocket). Using them here is **nominative** — naming the service the customer is
paying — and nothing more. Concretely, that means:

- no modification of the artwork, no recolouring, no effects, no re-typesetting;
- no suggestion of partnership, sponsorship or endorsement beyond "we accept this";
- no use of the marks as the site's own identity, in the `og:image`, or in the app
  icon;
- marks appear only where a payment method is actually being chosen or shown.

Merchants integrating bKash, Nagad or Rocket are normally bound by the operating
agreement signed with each operator, which governs mark usage in detail and takes
precedence over this document. **This document is not that agreement** — it records
what the code does, so that the code stays defensible, not what the contract permits.
