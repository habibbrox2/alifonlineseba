#!/usr/bin/env python3
"""Generate one 1200x600 Open Graph card per service, in its category accent.

The site-wide card (`scripts/generate-og-image.py` -> og-cover.png) is the right
artefact for the home page, but it is the wrong one for a service link. Sharing
/services/view/nid-copy from Telegram then shows the generic green cover, and
the single most useful thing the preview could say -- what kind of service this
is -- is the one thing it does not say. So each service gets a 1200x600 card
painted in its category accent, carrying the service name, its category and
its price. That is the same signal the UI already gives: CategoryAccent maps a
category to one of twelve hues and the cards, chips and buttons follow it, so
the share image and the page it links to are the same colour.

1200x600, not 1200x630: Facebook/Twitter render 1.91:1, and a 1200x630 card is
letterboxed inside it. A 2:1 card is the ratio both actually show, so the text
lands where the reader is looking instead of behind the caption overlay.

Bengali is rasterised by headless Chrome, not Pillow. This Pillow build has no
HarfBuzz (`PIL.features.check("raqm")` is False), so it cannot shape a Bengali
conjunct: ক্ষ comes out the same width as ক + ষ, which is visibly wrong text on
the single largest text element of the card. Chrome has the full shaping
stack, so the card is laid out as HTML, screenshotted, and only the raster
lands in the repo.

Output: public/assets/img/og/service-<slug>.png plus manifest.json
(the slug -> file map the Twig helper reads; see TwigExtension::shareCard).

Run: python scripts/generate-service-og-image.py [--limit N] [--force]
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import re
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT_DIR = ROOT / "public" / "assets" / "img" / "og"
WORK = ROOT / "runtime" / "og"
CSS = ROOT / "resources" / "css" / "app.css"
DATA = WORK / "services.json"

W, H = 1200, 600

# Brand, matching the values in generate-og-image.py.
INK_DEEP = "#05261D"
INK = "#083F31"
BANGLA = "C:/Windows/Fonts/Nirmala.ttf"
# The light lockup, inlined as a data URI so the card needs no second request
# and no file:// path that a future sandbox might refuse. It is the variant the
# auth pages already use, for the same reason: the default lockup's wordmark is
# primary-900 and would vanish on a dark card.
LOGO_LIGHT = "public/assets/brand/logo-horizontal-light.svg"

CHROME_CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/usr/bin/google-chrome",
    "/usr/bin/chromium",
]

# The one token the card really needs from each accent set. Taken from the DARK
# table, because the card background is a near-black green: the light table's
# 500-shade --accent is a solid button colour that all but disappears here, and
# --accent-soft is a near-white fill that would glare. The dark table's --accent
# is the 300-400 shade, which is exactly a "readable mark on a dark surface"
# token.
ACCENT_RE = re.compile(
    r"^\.dark \.accent-(?P<key>[a-z]+)\s*\{(?P<body>[^}]*)\}", re.MULTILINE
)
TOKEN_RE = re.compile(r"--accent:\s*(#[0-9a-fA-F]{3,8})")

# Ordered by how legible each hue is against the #083F31 card background. The
# raw dark-table values are picked for dark *panels*; the very light ones
# (lime, amber, cyan) can wash out, so those get a nudge toward the accent ink.
NEEDS_TONE_DOWN = {"lime", "amber", "cyan", "orange"}


def load_accents() -> dict[str, str]:
    """Pull the dark-mode --accent hexes straight out of the built stylesheet.

    Parsed rather than hard-coded on purpose: CategoryAccent::PALETTE and the
    two .accent-* tables in app.css are kept in sync by AccentPaletteCssTest,
    and a third copy here would be the one nobody tests. If a hue is retuned
    in CSS the cards follow.
    """
    css = CSS.read_text(encoding="utf-8")
    out: dict[str, str] = {}
    for m in ACCENT_RE.finditer(css):
        tok = TOKEN_RE.search(m.group("body"))
        if tok:
            out[m.group("key")] = tok.group(1)
    if not out:
        sys.exit("no .dark .accent-* rules found in resources/css/app.css")
    return out


def tone_down(hex_color: str) -> str:
    """Mix a too-light hue 30% toward the card background."""
    r, g, b = (int(hex_color[i : i + 2], 16) for i in (1, 3, 5))
    br, bg, bb = (int(INK[i : i + 2], 16) for i in (1, 3, 5))
    mix = lambda a, c: round(a * 0.7 + c * 0.3)  # noqa: E731
    return "#%02X%02X%02X" % (mix(r, br), mix(g, bg), mix(b, bb))


def find_chrome() -> str:
    for c in CHROME_CANDIDATES:
        if os.path.exists(c):
            return c
    sys.exit("no Chrome/Chromium found; set one in CHROME_CANDIDATES")


def money(v: float) -> str:
    return "৳{:,.0f}".format(v) if v else "ফ্রি"


def load_services(limit: int | None) -> list[dict]:
    if not DATA.is_file():
        sys.exit(
            "runtime/og/services.json missing -- run:\n"
            "  php scripts/service-og-data.php > runtime/og/services.json"
        )
    rows = json.loads(DATA.read_text(encoding="utf-8"))
    return rows[:limit] if limit else rows


CARD = """<!DOCTYPE html>
<html lang="bn"><head><meta charset="utf-8">
<style>
  @font-face {{ font-family: Nirmala; src: url("file:///{font}");
                font-weight: 100 900; }}
  * {{ margin:0; padding:0; box-sizing:border-box; }}
  html, body {{ width:{w}px; height:{h}px; }}
  body {{
    font-family: Nirmala, sans-serif;
    background: linear-gradient(135deg, {ink} 0%, {deep} 100%);
    color: #fff; position: relative; overflow: hidden;
  }}
  .glow {{ position:absolute; border-radius:50%; }}
  /* Deliberately small and hard-edged rather than a big blurred blob: a
     600px blur at 40% alpha desaturates the whole card to grey and the brand
     green disappears. What has to survive is the card *reading as green with an
     accent in it*, so the glow is a corner tint, not a wash. */
  .g1 {{ width:300px; height:300px; right:-90px; top:-110px;
         background: radial-gradient(circle,{accent}3d,{accent}00 70%); }}
  .g2 {{ width:260px; height:260px; left:-90px; bottom:-120px;
         background: radial-gradient(circle,#0D8F6840,#0D8F6800 70%); }}
  .wrap {{ position:relative; height:100%; padding:52px 68px;
           display:flex; flex-direction:column; }}
  .rail {{ position:absolute; left:0; top:0; bottom:0; width:12px;
           background:{accent}; }}
  .top {{ display:flex; align-items:center; }}
  .logo {{ height:56px; }}
  .cat {{ margin-left:auto; font-size:25px; padding:9px 26px; border-radius:999px;
          background:{accent}22; color:{accent};
          border:2px solid {accent}59; white-space:nowrap; }}
  .body {{ flex:1; display:flex; flex-direction:column; justify-content:center; }}
  .name {{ font-size:{name_size}px; line-height:1.24; font-weight:700;
           max-width:1020px; display:-webkit-box; -webkit-line-clamp:2;
           -webkit-box-orient:vertical; overflow:hidden; }}
  .desc {{ margin-top:20px; font-size:27px; line-height:1.45; color:#cfe7de;
           max-width:990px; display:-webkit-box; -webkit-line-clamp:2;
           -webkit-box-orient:vertical; overflow:hidden; }}
  .foot {{ display:flex; align-items:center; gap:22px;
           font-size:25px; color:#9dc4b7; }}
  .price {{ font-size:31px; font-weight:700; color:{ink};
            background:{accent}; padding:9px 28px; border-radius:12px; }}
  .dot {{ opacity:.45; }}
</style></head>
<body>
  <div class="glow g1"></div><div class="glow g2"></div>
  <div class="rail"></div>
  <div class="wrap">
    <div class="top">
      <img class="logo" src="{logo}">
      <div class="cat">{category}</div>
    </div>
    <div class="body">
      <div class="name">{name}</div>
      <div class="desc">{desc}</div>
    </div>
    <div class="foot">
      <span class="price">{price}</span>
      <span class="dot">·</span>
      <span>অনলাইনে সাথে সাথে</span>
      <span class="dot">·</span>
      <span>aliftools.com</span>
    </div>
  </div>
</body></html>
"""


def esc(s: str) -> str:
    return (
        s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    )


def name_size(name: str) -> int:
    """Shrink the headline for long service names so it keeps its two lines.

    Fixed sizes either overflow the two-line clamp (the name is silently cut
    off, which for a share card means the reader cannot tell what they are
    looking at) or waste half the card on a two-word name.
    """
    n = len(name)
    if n <= 18:
        return 76
    if n <= 26:
        return 66
    if n <= 34:
        return 58
    if n <= 42:
        return 52
    return 46


def build_html(svc: dict, accent: str) -> str:
    desc = (svc.get("description") or "").strip()
    if not desc:
        desc = "অনলাইনে অর্ডার করুন, মিনিটের মধ্যে রেজাল্ট নিন।"
    svg = (ROOT / LOGO_LIGHT).read_text(encoding="utf-8").strip()
    logo = "data:image/svg+xml;base64," + base64.b64encode(
        svg.encode("utf-8")).decode("ascii")

    return CARD.format(
        w=W, h=H, font=BANGLA, ink=INK, deep=INK_DEEP, logo=logo,
        accent=accent,
        category=esc(svc.get("category") or "সেবা"),
        name=esc(svc["name"]),
        name_size=name_size(svc["name"]),
        desc=esc(desc),
        price=esc(money(svc.get("price") or 0)),
    )


def shoot(chrome: str, html_path: Path, png_path: Path) -> None:
    png_path.unlink(missing_ok=True)
    cmd = [
        chrome, "--headless", "--disable-gpu", "--no-sandbox",
        "--hide-scrollbars", "--force-device-scale-factor=1",
        f"--window-size={W},{H}",
        f"--screenshot={png_path}", html_path.as_uri(),
    ]
    # headless=new can spawn a first run that races the screenshot; a virtual
    # time budget makes the wait deterministic and lets webfonts settle.
    cmd.insert(2, "--virtual-time-budget=2000")
    subprocess.run(cmd, check=True, capture_output=True, timeout=90)
    if not png_path.is_file() or png_path.stat().st_size < 1000:
        sys.exit(f"chrome produced no usable png for {html_path.name}")


def shrink(png_path: Path) -> None:
    """Quantise the rendered card in place.

    Chrome writes truecolour PNGs, which come out around 280 KB a card — 4.5 MB
    of share banners in the repository for artwork that is two gradients, a
    handful of rules and some text. These are deliberately versioned (they are
    source, not build output) so the size actually matters.

    `Image.quantize` with the default method picks the palette from the image's
    own histogram, so the brand green and the accent are kept exactly and only
    the dithering noise on the gradients is lost. `dither=FLOYDSTEINBERG` keeps
    the gradients smooth, which matters because a banded diagonal gradient is
    the first thing visible on a 1200px card.
    """
    from PIL import Image

    with Image.open(png_path) as im:
        im.convert("RGB").quantize(
            colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG,
        ).save(png_path, optimize=True)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=None)
    ap.add_argument("--force", action="store_true",
                    help="re-render cards that already exist")
    ap.add_argument("--jobs", type=int, default=4)
    args = ap.parse_args()

    try:
        from PIL import Image  # noqa: F401
    except ImportError:
        sys.exit("Pillow is required to verify the rendered cards")

    accents = load_accents()
    services = load_services(args.limit)
    if not services:
        sys.exit("no services in the dump")
    chrome = find_chrome()

    WORK.mkdir(parents=True, exist_ok=True)
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    todo = []
    for svc in services:
        key = svc["accent"]
        if key not in accents:
            sys.exit(f"accent '{key}' for {svc['slug']} has no .dark CSS rule")
        png = OUT_DIR / f"service-{svc['slug']}.png"
        if png.is_file() and not args.force:
            continue
        todo.append((svc, png))

    print(f"{len(services)} services, {len(todo)} to render, "
          f"{len(services) - len(todo)} cached")

    def one(job):
        svc, png = job
        key = svc["accent"]
        raw = accents[key]
        accent = tone_down(raw) if key in NEEDS_TONE_DOWN else raw
        html = WORK / f"card-{svc['slug']}.html"
        html.write_text(build_html(svc, accent), encoding="utf-8")
        shoot(chrome, html, png)
        shrink(png)
        with Image.open(png) as im:
            if im.size != (W, H):
                sys.exit(f"{png.name} came out {im.size}, expected {(W, H)}")
            if im.mode != "P":
                sys.exit(f"{png.name} was not quantised (mode {im.mode})")
        return svc, accent, png.stat().st_size

    manifest = {}
    with ThreadPoolExecutor(max_workers=args.jobs) as pool:
        for svc, accent, size in pool.map(one, todo):
            manifest[svc["slug"]] = {
                "file": f"/assets/img/og/service-{svc['slug']}.png",
                "accent": svc["accent"],
            }
            print(f"  {svc['slug']:<34} {svc['accent']:<8} {size / 1024:.0f} KB")

    # Merge into whatever is already on disk, so a --limit run does not throw
    # away the cards it skipped.
    path = OUT_DIR / "manifest.json"
    merged = {}
    if path.is_file():
        merged = json.loads(path.read_text(encoding="utf-8"))
    merged.update(manifest)
    path.write_text(
        json.dumps(dict(sorted(merged.items())), ensure_ascii=False, indent=2)
        + "\n", encoding="utf-8",
    )
    print(f"manifest: {len(merged)} cards")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
