"""Export the vector logo and transparent PNG/ICO assets.

Requires Python 3, Pillow and native Cairo (macOS: brew install cairo).
Does not run during application builds. The generated PNG concept is retained
separately; these paths are the simplified vector master for small-size use.
"""
from pathlib import Path
import ctypes as C
import ctypes.util
import json
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "public/brand"
OUT.mkdir(parents=True, exist_ok=True)
INK, GOLD, ORANGE, PAPER = "#24291f", "#f4ca38", "#e99728", "#f7f7ef"

# Path coordinates in the 1280 × 1280 master canvas, traced from the generated concept.
SHAPES = [
    ("feet", "M456 1030 L517 1042 L498 1116 C492 1138 506 1139 547 1150 C575 1158 587 1175 568 1176 L430 1176 C404 1176 408 1162 425 1152 C448 1136 448 1090 456 1030 Z"),
    ("feet", "M628 1033 L691 1020 L685 1104 C683 1127 702 1131 757 1144 C789 1152 814 1176 789 1176 L643 1176 C617 1176 616 1161 629 1148 C643 1128 640 1090 628 1033 Z"),
    ("orange", "M968 145 C997 171 1040 196 1080 218 C1112 235 1104 249 1077 251 L929 252 Z"),
    ("gold", "M164 690 C225 731 300 720 366 700 C460 671 506 605 606 594 C665 586 696 606 727 601 C768 595 742 509 719 452 C678 350 688 267 731 189 C766 123 818 87 873 87 C919 87 953 113 980 151 C975 203 965 240 940 252 C921 266 892 255 876 258 C836 265 816 306 817 353 C817 416 855 466 890 517 C951 603 973 681 959 758 C937 879 853 974 747 1026 C669 1065 576 1068 495 1064 C398 1061 324 1018 279 946 C250 899 202 866 181 813 C166 777 154 708 164 690 Z"),
    ("ink", "M263 815 C389 877 541 850 647 802 C719 769 769 712 781 660 C790 761 731 835 643 876 C503 943 359 925 263 815 Z"),
    ("ink", "M932 169 C932 181 922 191 910 191 C898 191 888 181 888 169 C888 157 898 147 910 147 C922 147 932 157 932 169 Z"),
]
(ROOT / "assets/brand").mkdir(parents=True, exist_ok=True)
(ROOT / "assets/brand/logo-paths.json").write_text(json.dumps({
    "viewBox": [0, 0, 1280, 1280], "palette": {"ink": INK, "gold": GOLD, "orange": ORANGE, "paper": PAPER},
    "paths": [{"color": color, "d": path} for color, path in SHAPES],
}, indent=2) + "\n")

def icon_svg(dark=False, adaptive=False):
    colors = {"feet": PAPER if dark else INK, "gold": GOLD, "orange": ORANGE, "ink": INK}
    style = '<style>@media(prefers-color-scheme:dark){.feet{fill:' + PAPER + '}}</style>' if adaptive else ''
    paths = ''.join('<path class="' + color + '" fill="' + colors[color] + '" d="' + path + '"/>' for color, path in SHAPES)
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1280 1280" role="img" aria-labelledby="title"><title id="title">Yellow-Goose</title>' + style + paths + '</svg>\n'

library = ctypes.util.find_library("cairo")
if not library:
    fallback = Path("/opt/homebrew/opt/cairo/lib/libcairo.2.dylib")
    library = str(fallback) if fallback.exists() else None
if not library:
    raise RuntimeError("Install native Cairo to export branding.")
cairo = C.CDLL(library)
def bind(name, result, *args):
    fn = getattr(cairo, name); fn.restype = result; fn.argtypes = list(args); return fn
P, D, I, S = C.c_void_p, C.c_double, C.c_int, C.c_char_p
image_surface = bind("cairo_image_surface_create", P, I, I, I)
svg_surface = bind("cairo_svg_surface_create", P, S, D, D)
create = bind("cairo_create", P, P)
destroy = bind("cairo_destroy", None, P)
finish = bind("cairo_surface_finish", None, P)
surface_destroy = bind("cairo_surface_destroy", None, P)
write_png = bind("cairo_surface_write_to_png", I, P, S)
scale = bind("cairo_scale", None, P, D, D)
translate = bind("cairo_translate", None, P, D, D)
save = bind("cairo_save", None, P)
restore = bind("cairo_restore", None, P)
move = bind("cairo_move_to", None, P, D, D)
line = bind("cairo_line_to", None, P, D, D)
curve = bind("cairo_curve_to", None, P, D, D, D, D, D, D)
close = bind("cairo_close_path", None, P)
fill = bind("cairo_fill", None, P)
rgb = bind("cairo_set_source_rgb", None, P, D, D, D)
font = bind("cairo_select_font_face", None, P, S, I, I)
font_size = bind("cairo_set_font_size", None, P, D)
text_path = bind("cairo_text_path", None, P, S)

def color(ctx, value):
    rgb(ctx, *(int(value[i:i+2], 16) / 255 for i in (1, 3, 5)))

def draw_icon(ctx, dark):
    import re
    colors = {"feet": PAPER if dark else INK, "gold": GOLD, "orange": ORANGE, "ink": INK}
    for tone, path in SHAPES:
        tokens = re.findall(r'[MLCZ]|-?\d+(?:\.\d+)?', path)
        i = 0
        while i < len(tokens):
            cmd = tokens[i]; i += 1
            n = {"M": 2, "L": 2, "C": 6, "Z": 0}[cmd]
            args = [float(v) for v in tokens[i:i+n]]; i += n
            {"M": move, "L": line, "C": curve, "Z": close}[cmd](ctx, *args)
        color(ctx, colors[tone]); fill(ctx)

def export(name, width, height, dark=False, wordmark=False, svg=False):
    path = OUT / (name + (".svg" if svg else ".png"))
    surface = svg_surface(str(path).encode(), width, height) if svg else image_surface(0, width, height)
    ctx = create(surface)
    if wordmark:
        save(ctx); translate(ctx, 0, 0); scale(ctx, 0.2, 0.2); draw_icon(ctx, dark); restore(ctx)
        color(ctx, PAPER if dark else INK)
        font(ctx, b"Arial", 0, 1)
        font_size(ctx, 110); move(ctx, 278, 142); text_path(ctx, b"Yellow-Goose"); fill(ctx)
        font(ctx, b"Arial", 0, 0); font_size(ctx, 33); move(ctx, 282, 198)
        text_path(ctx, b"PHP + React framework"); fill(ctx)
    else:
        scale(ctx, width / 1280, height / 1280); draw_icon(ctx, dark)
    destroy(ctx)
    if not svg:
        if write_png(surface, str(path).encode()) != 0: raise RuntimeError("PNG export failed")
    finish(surface); surface_destroy(surface)
    if svg:
        content = path.read_text()
        # Text was converted to paths by Cairo; no fonts are needed by consumers.
        index = content.index(">", content.index("<svg"))
        content = content[:index] + ' role="img" aria-label="Yellow-Goose — PHP + React framework"' + content[index:]
        path.write_text(content)

for dark, suffix in [(False, "light"), (True, "dark")]:
    (OUT / f"icon-{suffix}.svg").write_text(icon_svg(dark))
    export(f"icon-{suffix}", 512, 512, dark)
    export(f"logo-{suffix}", 1100, 256, dark, wordmark=True)
    export(f"logo-{suffix}", 1100, 256, dark, wordmark=True, svg=True)
(ROOT / "public/favicon.svg").write_text(icon_svg(adaptive=True))
for size in (16, 32, 180, 192, 512):
    export(f"icon-{size}", size, size)
Image.open(OUT / "icon-512.png").save(ROOT / "public/favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])
print("Exported vector icons, outlined wordmarks, transparent PNGs and favicon.")
