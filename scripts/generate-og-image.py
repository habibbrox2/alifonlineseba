#!/usr/bin/env python3
"""Build the Open Graph / Twitter share card (1200x630) for Alif Tools.

Why a generator instead of a hand-drawn PNG: the share card is the one brand
surface that the browser never renders, so it silently drifts away from the
logo the first time somebody tweaks the vector. This script reads the real
asset (public/assets/brand/logo-horizontal.svg) and re-rasterises its geometry,
so regenerating always yields a card that matches the shipped logo.

Output: public/assets/img/og-cover.png  (1200x630, RGB)

Requires Pillow (and numpy, which Pillow's own wheel usually pulls in).
Run: python scripts/generate-og-image.py
"""

from __future__ import annotations

import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / "public" / "assets" / "brand" / "logo-horizontal.svg"
OUT = ROOT / "public" / "assets" / "img" / "og-cover.png"

W, H = 1200, 630
SS = 3  # supersampling factor; downscaled at the end for clean edges

# Brand palette, mirrored from tailwind.config.js (primary-*) and the logo SVG.
INK_DEEP = (5, 38, 29)       # darker than primary-900, for the gradient foot
INK = (8, 63, 49)            # primary-900
EMERALD = (13, 143, 104)     # primary-600
GOLD = (245, 179, 1)         # accent-500
WHITE = (255, 255, 255)

# Lockup is recoloured for a dark backdrop: the shipped wordmark is near-black
# green and would disappear. The mark tile is lifted one shade so the tile edge
# still reads against the background it now sits on.
TILE = (11, 110, 81)         # primary-700
WORDMARK = WHITE

SVG_NS = "{http://www.w3.org/2000/svg}"
NUM = re.compile(r"-?\d*\.?\d+(?:e[-+]?\d+)?")


# --------------------------------------------------------------------------- #
# Minimal SVG geometry reader
# --------------------------------------------------------------------------- #
def _tokenize_path(d: str) -> list[tuple[str, list[float]]]:
    """Split an SVG path 'd' attribute into (command, numbers) pairs."""
    out: list[tuple[str, list[float]]] = []
    for cmd, args in re.findall(r"([MmLlCcZz])([^MmLlCcZz]*)", d):
        out.append((cmd, [float(n) for n in NUM.findall(args)]))
    return out


def _flatten_cubic(p0, p1, p2, p3, steps: int = 24) -> list[tuple[float, float]]:
    """Sample a cubic bezier into a polyline (fine at 24 steps for these glyphs)."""
    pts = []
    for i in range(1, steps + 1):
        t = i / steps
        u = 1 - t
        x = u**3 * p0[0] + 3 * u**2 * t * p1[0] + 3 * u * t**2 * p2[0] + t**3 * p3[0]
        y = u**3 * p0[1] + 3 * u**2 * t * p1[1] + 3 * u * t**2 * p2[1] + t**3 * p3[1]
        pts.append((x, y))
    return pts


def path_subpaths(d: str) -> list[list[tuple[float, float]]]:
    """Return a path as a list of closed polygons, one per 'M ... Z' run.

    Only the commands the brand logo actually uses are supported (M/L/C/Z);
    anything else raises rather than rendering a silently wrong glyph.
    """
    subpaths: list[list[tuple[float, float]]] = []
    cur: list[tuple[float, float]] = []
    pos = (0.0, 0.0)
    start = (0.0, 0.0)

    for cmd, args in _tokenize_path(d):
        if cmd == "M":
            if len(cur) > 1:
                subpaths.append(cur)
            pos = (args[0], args[1])
            start = pos
            cur = [pos]
        elif cmd == "L":
            for i in range(0, len(args), 2):
                pos = (args[i], args[i + 1])
                cur.append(pos)
        elif cmd == "C":
            for i in range(0, len(args), 6):
                c1 = (args[i], args[i + 1])
                c2 = (args[i + 2], args[i + 3])
                end = (args[i + 4], args[i + 5])
                cur.extend(_flatten_cubic(pos, c1, c2, end))
                pos = end
        elif cmd == "Z":
            if cur:
                cur.append(start)
                subpaths.append(cur)
                cur = [start]
        else:
            raise ValueError(f"unsupported path command {cmd!r}")

    if len(cur) > 1:
        subpaths.append(cur)
    return subpaths


def draw_shapes(canvas: Image.Image, shapes: list[tuple], scale: float,
                origin: tuple[float, float]) -> None:
    """Draw parsed logo elements onto an RGBA canvas at `scale`.

    Paths are painted through a per-path mask: subpath 0 is the outer contour,
    subpath 1 its counter (the hole in 'A' and 'o'), and so on. Pillow's
    polygon fill has no even-odd mode, so counters are punched back out in
    black, which is equivalent for the nested-only outlines used here.
    """
    ox, oy = origin
    for kind, geometry, color in shapes:
        layer = Image.new("L", canvas.size, 0)

        if kind == "path":
            for idx, poly in enumerate(geometry):
                pts = [((x * scale) + ox, (y * scale) + oy) for x, y in poly]
                ImageDraw.Draw(layer).polygon(pts, fill=255 if idx % 2 == 0 else 0)

        elif kind == "rect":
            x, y, w, h, rx = geometry
            ImageDraw.Draw(layer).rounded_rectangle(
                [(x * scale) + ox, (y * scale) + oy,
                 (x + w) * scale + ox, (y + h) * scale + oy],
                radius=rx * scale, fill=255)

        elif kind == "circle":
            cx, cy, r = geometry
            ImageDraw.Draw(layer).ellipse(
                [((cx - r) * scale) + ox, ((cy - r) * scale) + oy,
                 ((cx + r) * scale) + ox, ((cy + r) * scale) + oy], fill=255)
        else:
            raise ValueError(f"unsupported shape {kind!r}")

        canvas.paste(Image.new("RGBA", canvas.size, color), (0, 0), layer)


def read_logo() -> tuple[list[tuple], float, float]:
    """Parse logo-horizontal.svg into drawable shapes plus its viewBox size."""
    root = ET.parse(LOGO).getroot()
    vb = [float(n) for n in NUM.findall(root.get("viewBox", "0 0 240 64"))]

    shapes: list[tuple] = []
    for el in root.iter():
        tag = el.tag.replace(SVG_NS, "")
        fill = el.get("fill", "#000")
        if tag == "path":
            shapes.append(("path", path_subpaths(el.get("d", "")), fill))
        elif tag == "rect":
            shapes.append(("rect", (float(el.get("x", 0)), float(el.get("y", 0)),
                                    float(el.get("width", 0)), float(el.get("height", 0)),
                                    float(el.get("rx", 0))), fill))
        elif tag == "circle":
            shapes.append(("circle", (float(el.get("cx", 0)), float(el.get("cy", 0)),
                                      float(el.get("r", 0))), fill))

    # Recolour for the dark card: the wordmark is the run of paths that starts
    # right of the mark (x > 60), and the tile is the 64x64 rect. The gold alif,
    # white dot and white baseline keep their shipped colours.
    for i, (kind, geometry, _f) in enumerate(shapes):
        if kind == "rect" and geometry[2] == 64:
            shapes[i] = (kind, geometry, "#%02x%02x%02x" % TILE)
        elif kind == "path" and geometry and min(p[0] for p in geometry[0]) > 60:
            shapes[i] = (kind, geometry, "#%02x%02x%02x" % WORDMARK)

    return shapes, vb[2], vb[3]


# --------------------------------------------------------------------------- #
# Card composition
# --------------------------------------------------------------------------- #
def diagonal_gradient(size: tuple[int, int], top_left, bottom_right) -> Image.Image:
    """Smooth two-stop diagonal gradient, built small and scaled up."""
    w, h = 160, 96
    ys, xs = np.mgrid[0:h, 0:w]
    t = ((xs / (w - 1)) + (ys / (h - 1))) / 2.0
    t = np.clip(t, 0, 1)[..., None]
    a = np.array(top_left, dtype=np.float64)
    b = np.array(bottom_right, dtype=np.float64)
    arr = (a + (b - a) * t).astype(np.uint8)
    return Image.fromarray(arr, "RGB").resize(size, Image.BICUBIC)


def radial_glow(size: tuple[int, int], center, radius, color, strength: float) -> Image.Image:
    """Soft radial highlight returned as an RGBA layer ready to alpha_composite."""
    w, h = 240, 126
    ys, xs = np.mgrid[0:h, 0:w]
    cx, cy = center[0] * w, center[1] * h
    d = np.sqrt(((xs - cx) / (radius * w)) ** 2 + ((ys - cy) / (radius * h)) ** 2)
    alpha = np.clip(1.0 - d, 0, 1) ** 2 * 255 * strength
    layer = np.zeros((h, w, 4), dtype=np.uint8)
    layer[..., 0], layer[..., 1], layer[..., 2] = color
    layer[..., 3] = alpha.astype(np.uint8)
    return Image.fromarray(layer, "RGBA").resize(size, Image.BICUBIC)


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    for name in (("segoeuib.ttf", "arialbd.ttf") if bold else ("segoeui.ttf", "arial.ttf")):
        path = Path("C:/Windows/Fonts") / name
        if path.exists():
            return ImageFont.truetype(str(path), size)
    return ImageFont.load_default()


def centered_text(img: Image.Image, y: int, text: str, fnt, fill) -> None:
    """Draw centred text with manual letterspacing (PIL has no tracking)."""
    draw = ImageDraw.Draw(img)
    tracking = 0
    widths = [draw.textlength(ch, font=fnt) for ch in text]
    total = sum(widths) + tracking * (len(text) - 1)
    x = (img.width - total) / 2
    for ch, w in zip(text, widths):
        draw.text((x, y), ch, font=fnt, fill=fill)
        x += w + tracking


def build() -> Image.Image:
    card = diagonal_gradient((W, H), INK, INK_DEEP).convert("RGBA")
    card.alpha_composite(radial_glow((W, H), (0.5, 0.42), 0.55, EMERALD, 0.42))
    card.alpha_composite(radial_glow((W, H), (0.5, 0.42), 0.22, GOLD, 0.16))

    # Oversized watermark of the mark itself, bleeding off the bottom-right
    # corner at ~13% alpha: brand texture without competing with the lockup.
    shapes, vb_w, vb_h = read_logo()
    mark_shapes = [s for s in shapes if not (s[0] == "rect" and s[1][2] == 64)
                   and not (s[0] == "path" and min(p[0] for p in s[1][0]) > 60)]
    wm = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    wm_big = Image.new("RGBA", (W * SS, H * SS), (0, 0, 0, 0))
    draw_shapes(wm_big, mark_shapes, 5.6 * SS, (W * SS * 0.815, H * SS * 0.50))
    wm.alpha_composite(wm_big.resize((W, H), Image.LANCZOS))
    wm_r, wm_g, wm_b, wm_a = wm.split()
    card = Image.alpha_composite(card, Image.merge(
        "RGBA", (wm_r, wm_g, wm_b, wm_a.point(lambda v: int(v * 0.13)))))

    # Centred lockup at native geometry, scaled up. The wordmark is drawn as
    # text rather than parsed out of the SVG: logo-horizontal.svg ships it as a
    # <text> run, because the hand-authored outline paths it used to carry were
    # missing the "lif" and the final "s" and rendered as "ATOLoi". Pillow and
    # the browser then use slightly different metrics for the same string, so
    # the lockup is cropped to its ink and centred on that rather than on the
    # nominal viewBox.
    lock_w = 420.0
    scale = (lock_w * SS) / vb_w
    lock = Image.new("RGBA", (int(vb_w * scale) + 4, int(vb_h * scale) + 4), (0, 0, 0, 0))
    draw_shapes(lock, shapes, scale, (0, 0))
    ImageDraw.Draw(lock).text(
        (84 * scale, 47 * scale), "Alif Tools", font=font(int(42 * scale), bold=True),
        fill=WORDMARK, anchor="ls",
    )
    # Crop to ink, then scale the cropped lockup so it occupies lock_w overall.
    lock = lock.crop(lock.getbbox())
    lock = lock.resize(
        (int(lock_w), max(1, round(lock.height * lock_w / lock.width))), Image.LANCZOS)

    card.alpha_composite(lock, ((W - lock.width) // 2, 214))

    # Gold rule + Latin tagline (no Bengali: this environment has no HarfBuzz,
    # so conjuncts would render as isolated, broken glyphs).
    d = ImageDraw.Draw(card)
    rule_w, rule_y = 96, 356
    d.rounded_rectangle(
        [(W - rule_w) / 2, rule_y, (W + rule_w) / 2, rule_y + 4],
        radius=2, fill=GOLD)

    centered_text(card, 384, "Instant Digital Services Hub",
                  font(30, bold=True), (255, 255, 255, 235))

    centered_text(card, 436, "NID  -  Voter  -  Birth Certificate  -  Mobile Banking",
                  font(20), (255, 255, 255, 165))

    d.rectangle([(0, H - 6), (W, H)], fill=GOLD)
    d.rectangle([(0, H - 6), (int(W * 0.28), H)], fill=EMERALD)

    return card.convert("RGB")


def main() -> int:
    if not LOGO.exists():
        print(f"missing brand asset: {LOGO}", file=sys.stderr)
        return 1

    OUT.parent.mkdir(parents=True, exist_ok=True)
    img = build()
    img.save(OUT, "PNG", optimize=True)

    check = Image.open(OUT)
    print(f"wrote {OUT.relative_to(ROOT)} -> {check.size[0]}x{check.size[1]} "
          f"{check.mode} {OUT.stat().st_size // 1024} KB")
    if check.size != (W, H):
        print(f"ERROR: expected {W}x{H}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
