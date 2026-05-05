"""Generate SVG files from drawio XML for W2_ARCHITECTURE.md."""
import xml.etree.ElementTree as ET
import re
import html as html_module
import os

DIAGRAMS_DIR = os.path.dirname(os.path.abspath(__file__))

DRAWIO_FILES = [
    "01-component-overview.drawio",
    "02-seq-document-ingestion.drawio",
    "03-collab-supervisor-workers.drawio",
    "04-seq-rag.drawio",
    "05-component-citations.drawio",
    "06-seq-eval-gate.drawio",
    "07-component-observability.drawio",
    "08-deployment-topology.drawio",
    "09-eval-rubric-data-flow.drawio",
]


def parse_style(style_str):
    style = {}
    if not style_str:
        return style
    for part in style_str.split(";"):
        part = part.strip()
        if "=" in part:
            key, val = part.split("=", 1)
            style[key.strip()] = val.strip()
    return style


def clean_value(value):
    """Convert mxGraph HTML value to plain text lines."""
    if not value:
        return []
    text = value.replace("&#xa;", "\n").replace("<br>", "\n").replace("<br/>", "\n")
    text = re.sub(r"<[^>]+>", "", text)
    text = html_module.unescape(text)
    lines = [ln for ln in (line.strip() for line in text.split("\n")) if ln]
    return lines


def esc(text):
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def get_rect(cell):
    geo = cell.find("mxGeometry")
    if geo is None:
        return None
    return {
        "x": float(geo.get("x", 0)),
        "y": float(geo.get("y", 0)),
        "w": float(geo.get("width", 0)),
        "h": float(geo.get("height", 0)),
    }


def render_text_block(x, y, w, h, lines, font_size, font_color, align="middle", valign="middle", bold=False):
    if not lines:
        return ""
    parts = []
    lh = font_size * 1.45
    total_h = len(lines) * lh
    if valign == "top":
        start_y = y + font_size + 6
    else:
        start_y = y + h / 2 - total_h / 2 + font_size

    if align == "left":
        tx = x + 8
        anchor = "start"
    else:
        tx = x + w / 2
        anchor = "middle"

    fw = "bold" if bold else "normal"
    for i, line in enumerate(lines):
        ty = start_y + i * lh
        parts.append(
            f'  <text x="{tx:.1f}" y="{ty:.1f}" text-anchor="{anchor}" '
            f'font-family="Arial,Helvetica,sans-serif" font-size="{font_size}" '
            f'font-weight="{fw}" fill="{esc(font_color)}">{esc(line)}</text>'
        )
    return "\n".join(parts)


ARROW_COLORS = {
    "#16a34a": "ag",
    "#e11d48": "ar",
    "#9f1239": "ar",
    "#0284c7": "ab",
    "#0f766e": "at",
    "#d97706": "aa",
    "#7c3aed": "ap",
    "#c2410c": "ao",
}


def defs_block():
    markers = {
        "ad": "#475569",
        "ag": "#16a34a",
        "ar": "#e11d48",
        "ab": "#0284c7",
        "at": "#0f766e",
        "aa": "#d97706",
        "ap": "#7c3aed",
        "ao": "#c2410c",
    }
    parts = ["  <defs>"]
    for mid, color in markers.items():
        parts.append(
            f'    <marker id="{mid}" viewBox="0 0 10 10" refX="9" refY="5" '
            f'markerUnits="strokeWidth" markerWidth="7" markerHeight="7" orient="auto">'
            f'<path d="M0,0 L10,5 L0,10 z" fill="{color}"/></marker>'
        )
    parts.append("  </defs>")
    return "\n".join(parts)


def marker_for(stroke):
    return ARROW_COLORS.get(stroke, "ad")


def drawio_to_svg(drawio_path, svg_path):
    tree = ET.parse(drawio_path)
    root = tree.getroot()
    diagram = root.find("diagram")
    gm = diagram.find("mxGraphModel")

    pw = int(gm.get("pageWidth", 1480))
    ph = int(gm.get("pageHeight", 1080))

    cells = {}
    vertices = []
    edges = []

    for cell in gm.iter("mxCell"):
        cid = cell.get("id")
        if cid:
            cells[cid] = cell
        if cell.get("vertex") == "1":
            vertices.append(cell)
        elif cell.get("edge") == "1":
            edges.append(cell)

    parts = [
        f'<svg xmlns="http://www.w3.org/2000/svg" '
        f'width="{pw}" height="{ph}" viewBox="0 0 {pw} {ph}">',
        defs_block(),
        f'  <rect width="{pw}" height="{ph}" fill="white"/>',
    ]

    def cell_area(c):
        r = get_rect(c)
        return (r["w"] * r["h"]) if r else 0

    for cell in sorted(vertices, key=cell_area, reverse=True):
        rect = get_rect(cell)
        if not rect:
            continue
        x, y, w, h = rect["x"], rect["y"], rect["w"], rect["h"]
        value = cell.get("value", "")
        sstr = cell.get("style", "")
        st = parse_style(sstr)

        fill = st.get("fillColor", "#ffffff")
        stroke = st.get("strokeColor", "#000000")
        fc = st.get("fontColor", "#000000")
        fsz = int(float(st.get("fontSize", 12)))
        dashed = st.get("dashed", "0") == "1"
        sw = float(st.get("strokeWidth", "1"))
        rounded = st.get("rounded", "0") == "1"
        shape = st.get("shape", "")
        font_style = int(st.get("fontStyle", "0"))
        bold = bool(font_style & 1)

        da = "8,4" if dashed else "none"
        rx = 6 if rounded else 0
        fv = "none" if fill == "none" else fill

        is_text_only = "text;" in sstr and "strokeColor=none" in sstr

        if "umlLifeline" in shape:
            box_h = min(h * 0.13, 80)
            cx = x + w / 2
            parts.append(
                f'  <rect x="{x}" y="{y}" width="{w}" height="{box_h:.1f}" '
                f'rx="4" fill="{fv}" stroke="{esc(stroke)}" stroke-width="1.5"/>'
            )
            parts.append(
                f'  <line x1="{cx:.1f}" y1="{y+box_h:.1f}" '
                f'x2="{cx:.1f}" y2="{y+h:.1f}" '
                f'stroke="{esc(stroke)}" stroke-width="1.5" stroke-dasharray="6,3"/>'
            )
            lines = clean_value(value)
            txt = render_text_block(x, y, w, box_h, lines, fsz, fc, "middle", "middle")
            if txt:
                parts.append(txt)
        elif is_text_only:
            lines = clean_value(value)
            txt = render_text_block(x, y, w, h, lines, fsz, fc, "middle", "middle", bold)
            if txt:
                parts.append(txt)
        else:
            if stroke == "none":
                stroke_attr = f'stroke="none"'
            else:
                stroke_attr = f'stroke="{esc(stroke)}" stroke-width="{sw}" stroke-dasharray="{da}"'
            parts.append(
                f'  <rect x="{x}" y="{y}" width="{w}" height="{h}" '
                f'rx="{rx}" fill="{esc(fv)}" {stroke_attr}/>'
            )
            lines = clean_value(value)
            if lines:
                valign = "top" if "verticalAlign=top" in sstr else "middle"
                align = "left" if "align=left" in sstr else "middle"
                txt = render_text_block(x, y, w, h, lines, fsz, fc, align, valign, bold)
                if txt:
                    parts.append(txt)

    # Edges
    for cell in edges:
        sid = cell.get("source")
        tid = cell.get("target")
        sstr = cell.get("style", "")
        st = parse_style(sstr)
        value = cell.get("value", "")

        stroke = st.get("strokeColor", "#475569")
        sw = float(st.get("strokeWidth", "1"))
        dashed = st.get("dashed", "0") == "1"
        da = "8,4" if dashed else "none"
        end_arrow = st.get("endArrow", "block")

        geo = cell.find("mxGeometry")

        sp_x = sp_y = tp_x = tp_y = None

        if geo is not None:
            for pt in geo.findall("mxPoint"):
                pt_as = pt.get("as")
                px = pt.get("x")
                py = pt.get("y")
                if pt_as == "sourcePoint":
                    sp_x = float(px) if px is not None else None
                    sp_y = float(py) if py is not None else None
                elif pt_as == "targetPoint":
                    tp_x = float(px) if px is not None else None
                    tp_y = float(py) if py is not None else None

        def lifeline_cx(cell_id):
            if cell_id and cell_id in cells:
                r = get_rect(cells[cell_id])
                if r:
                    return r["x"] + r["w"] / 2
            return None

        def cell_center(cell_id):
            if cell_id and cell_id in cells:
                r = get_rect(cells[cell_id])
                if r:
                    return r["x"] + r["w"] / 2, r["y"] + r["h"] / 2
            return None, None

        def is_lifeline(cell_id):
            if cell_id and cell_id in cells:
                return "umlLifeline" in cells[cell_id].get("style", "")
            return False

        # Resolve source coordinates
        if is_lifeline(sid):
            cx = lifeline_cx(sid)
            if cx is not None:
                sx = cx
            else:
                sx = sp_x or 0
            sy = sp_y
            if sy is None:
                _, sy = cell_center(sid)
        else:
            sc_x, sc_y = cell_center(sid)
            sx = sp_x if sp_x is not None else (sc_x or 0)
            sy = sp_y if sp_y is not None else (sc_y or 0)

        # Resolve target coordinates
        if is_lifeline(tid):
            cx = lifeline_cx(tid)
            if cx is not None:
                tx2 = cx
            else:
                tx2 = tp_x or 0
            ty2 = tp_y
            if ty2 is None:
                _, ty2 = cell_center(tid)
        else:
            tc_x, tc_y = cell_center(tid)
            tx2 = tp_x if tp_x is not None else (tc_x or 0)
            ty2 = tp_y if tp_y is not None else (tc_y or 0)

        if sx is None or sy is None or tx2 is None or ty2 is None:
            continue

        mid = marker_for(stroke)
        if end_arrow in ("none", "open"):
            mend = ""
        else:
            mend = f'marker-end="url(#{mid})"'

        parts.append(
            f'  <line x1="{sx:.1f}" y1="{sy:.1f}" x2="{tx2:.1f}" y2="{ty2:.1f}" '
            f'stroke="{esc(stroke)}" stroke-width="{sw}" stroke-dasharray="{da}" {mend}/>'
        )

        if value:
            lines = clean_value(value)
            mx = (sx + tx2) / 2
            my = (sy + ty2) / 2 - 7
            for i, line in enumerate(lines):
                parts.append(
                    f'  <text x="{mx:.1f}" y="{(my + i*13):.1f}" '
                    f'text-anchor="middle" font-family="Arial,Helvetica,sans-serif" '
                    f'font-size="11" fill="{esc(stroke)}">{esc(line)}</text>'
                )

    parts.append("</svg>")

    with open(svg_path, "w", encoding="utf-8") as f:
        f.write("\n".join(parts))


for filename in DRAWIO_FILES:
    src = os.path.join(DIAGRAMS_DIR, filename)
    dst = os.path.join(DIAGRAMS_DIR, filename.replace(".drawio", ".svg"))
    drawio_to_svg(src, dst)
    print(f"  OK  {os.path.basename(dst)}")

print("All SVGs generated.")
