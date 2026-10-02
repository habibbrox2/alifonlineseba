#!/usr/bin/env python3
"""Generate the maskable PWA icons from the shipped brand mark.

Why a maskable variant at all: the plain icon-192/icon-512 pair is a rounded
tile, and Android is free to crop *any* launcher icon to whatever shape the
OEM picked -- a circle on Pixel, a squircle on Samsung, and a full-bleed
rectangle when the same icon is used as a share-sheet attachment (Telegram,
WhatsApp). A rounded tile loses its corners to that crop, and the mark inside
it ends up off-centre or clipped.

The maskable contract is the fix: paint the background edge to edge so any
crop still lands on brand colour, and keep all meaningful content inside the
safe zone -- the centred circle whose diameter is 80% of the icon, which the
harshest mask (a full circle) will never cut into.

The foreground is the mark's glyph only (alif, dot, baseline); the 64x64 tile
rect is deliberately dropped, because against an edge-to-edge background of the
same green it would be invisible and would only eat into the safe-zone margin.

Geometry is read from the real asset (public/assets/brand/logo-mark.svg) using
the parser that generates the OG card, so a tweak to the vector propagates here
too rather than silently drifting.

Output: public/icon-maskable-192.png, public/icon-maskable-512.png

Requires Pillow. Run: python scripts/generate-maskable-icons.py
"""

from __future__ import annotations

import importlib.util
import math
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
MARK = ROOT / "public" / "assets" / "brand" / "logo-mark.svg"

SIZES = (192, 512)

# primary-900, the same token the manifest's theme_color and background_color
# use. Matching them is what makes a masked icon blend into the launcher's
# backdrop instead of showing a seam where the crop edge falls.
BG = (8, 63, 49)

# The glyph occupies roughly x 17..47, y 11..55 of the 64x64 viewBox, so its
# half-diagonal is ~26.6 viewBox units about the content centre (32, 33).
#
# Scale is chosen so that half-diagonal lands on 0.33 of the canvas: content
# then spans 66% of the icon, comfortably inside the 80% safe circle rather
# than the ~74% a larger scale would give, which left only ~3% of slack and
# clipped at the first hint of a different mask shape.
GLYPH_HALF_DIAGONAL = 26.6
CONTENT_RADIUS = 0.33
CONTENT_CX, CONTENT_CY = 32.0, 33.0

SAFE_DIAMETER = 0.80  # maskable spec: centred circle at 80% of the icon

SVG_NS = "{http://www.w3.org/2000/svg}"
NUM = re.compile(r"-?\d*\.?\d+(?:e[-+]?\d+)?")


def load_og_module():
    """Reuse the OG script's SVG reader instead of duplicating ~100 lines.

    The filename is not a legal module name, hence importlib rather than a
    plain import. Only the pure helpers are needed; its main() is guarded.
    """
    spec = importlib.util.spec_from_file_location(
        "_og", ROOT / "scripts" / "generate-og-image.py"
    )
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def read_glyph(og) -> list[tuple]:
    """Parse logo-mark.svg, returning every element except the background tile."""
    root = ET.parse(MARK).getroot()
    shapes: list[tuple] = []
    for el in root.iter():
        tag = el.tag.replace(SVG_NS, "")
        fill = el.get("fill", "#000")
        if tag == "path":
            shapes.append(("path", og.path_subpaths(el.get("d", "")), fill))
        elif tag == "rect":
            geometry = (
                float(el.get("x", 0)),
                float(el.get("y", 0)),
                float(el.get("width", 0)),
                float(el.get("height", 0)),
                float(el.get("rx", 0)),
            )
            # The 0,0 64x64 rounded rect is the tile, not part of the glyph.
            if geometry[0] == 0 and geometry[1] == 0 and geometry[2] == 64:
                continue
            shapes.append(("rect", geometry, fill))
        elif tag == "circle":
            shapes.append(
                (
                    "circle",
                    (
                        float(el.get("cx", 0)),
                        float(el.get("cy", 0)),
                        float(el.get("r", 0)),
                    ),
                    fill,
                )
            )
    return shapes


def build(size: int, shapes: list[tuple], og) -> Image.Image:
    """Paint one maskable icon: flat brand fill, glyph centred in the safe zone."""
    # 4x supersample; the mark is straight edges and circles, so this removes
    # the stair-stepping a 512px downscale would otherwise leave.
    ss = 4
    big = Image.new("RGBA", (size * ss, size * ss), BG + (255,))
    scale = (CONTENT_RADIUS * size * ss) / GLYPH_HALF_DIAGONAL
    # Origin places the glyph's content centre on the canvas centre.
    origin = (
        (size * ss) / 2 - CONTENT_CX * scale,
        (size * ss) / 2 - CONTENT_CY * scale,
    )
    og.draw_shapes(big, shapes, scale, origin)
    return big.resize((size, size), Image.LANCZOS).convert("RGB")


def check_safe_zone(path: Path) -> tuple[float, float]:
    """Measure the glyph's furthest pixel from centre against the safe circle.

    Asserting this in the generator is the point of the exercise: a maskable
    icon that quietly drifts out of the safe zone still builds, still installs,
    and only shows up as a clipped logo on someone else's launcher. Returning
    the measurement also keeps the scale honest if the vector is ever redrawn.
    """
    im = Image.open(path).convert("RGB")
    w, h = im.size
    cx, cy = (w - 1) / 2, (h - 1) / 2
    # The safe circle's *diameter* is 80% of the icon, so its radius is 80% of
    # the half-width -- not 40% of it.
    safe_radius = SAFE_DIAMETER * min(cx, cy)

    worst = 0.0
    px = im.load()
    for y in range(h):
        for x in range(w):
            r, g, b = px[x, y]
            # Anything not within a small tolerance of the flat background is
            # glyph. The tolerance is generous, which makes this err toward
            # over-reporting the extent -- the safe direction for a guard.
            if abs(r - BG[0]) + abs(g - BG[1]) + abs(b - BG[2]) > 24:
                worst = max(worst, math.hypot(x - cx, y - cy))

    return worst, safe_radius


def main() -> int:
    if not MARK.exists():
        print(f"missing brand asset: {MARK}", file=sys.stderr)
        return 1

    og = load_og_module()
    shapes = read_glyph(og)
    if not shapes:
        print(f"no drawable shapes parsed from {MARK}", file=sys.stderr)
        return 1

    failed = False
    for size in SIZES:
        out = ROOT / "public" / f"icon-maskable-{size}.png"
        build(size, shapes, og).save(out, "PNG", optimize=True)

        check = Image.open(out)
        if check.size != (size, size):
            print(f"ERROR: expected {size}x{size}, got {check.size}", file=sys.stderr)
            failed = True
            continue

        worst, safe = check_safe_zone(out)
        ok = worst <= safe
        failed = failed or not ok
        print(
            f"wrote {out.relative_to(ROOT)} -> {check.size[0]}x{check.size[1]} "
            f"{check.mode} {out.stat().st_size // 1024} KB | "
            f"glyph r={worst:.1f}px safe r={safe:.1f}px "
            f"{'OK' if ok else 'OUTSIDE SAFE ZONE'}"
        )

    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
