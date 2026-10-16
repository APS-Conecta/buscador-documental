"""Synthetic Spanish corpus for the S0 spike: PDFs and images with ground-truth text.

Kinds: native, scanned, photo (jpg), mixed, fake_visible, fake_invisible (garbage text layer over a scan).
Output: <out>/files/<kind>/*.{pdf,jpg} and <out>/manifest.jsonl (one JSON line per file, with the page texts
and ACL principals). Deterministic: every file derives its own RNG from (seed, file_id), so the worker
count does not change the bytes. 300 dpi A4 pages, DejaVu Sans 11 pt.
"""
import argparse
import io
import json
import multiprocessing as mp
import os

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont
from reportlab.lib.pagesizes import A4
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas

from zipf import TextModel

W, H = 2480, 3508                # A4 at 300 dpi
FONT_PX, LINE_PX, MARGIN = 46, 62, 220
LINES_PER_PAGE = 46
WIDTH_COLS = 78
FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
KINDS = ["native", "scanned", "photo", "mixed", "fake_visible", "fake_invisible"]
KIND_P = [0.40, 0.25, 0.12, 0.10, 0.07, 0.06]
N_FOLDERS = 100
NARROW_EVERY = 64            # every 64th file sits in gf001 (F1 reads ~1.6 %); stratified so small corpora hit the target

_model: TextModel | None = None


def folder_for(fid: int, rng) -> int:
    return 1 if fid % NARROW_EVERY == 1 else int(rng.integers(2, N_FOLDERS + 1))


def principals_for(folder: int) -> list[str]:
    p = [f"g:g{2 * folder - 1:03d}", f"g:g{2 * folder:03d}"]
    return sorted(p)


def render_page(lines: list[str]) -> Image.Image:
    img = Image.new("L", (W, H), 255)
    d = ImageDraw.Draw(img)
    f = ImageFont.truetype(FONT, FONT_PX)
    for i, ln in enumerate(lines):
        d.text((MARGIN, MARGIN + i * LINE_PX), ln, font=f, fill=20)
    return img


def scan_effect(img: Image.Image, rng) -> Image.Image:
    a = np.asarray(img, dtype=np.float32) + rng.normal(0, 6, (H, W)).astype(np.float32)
    return Image.fromarray(np.clip(a, 0, 255).astype(np.uint8))


def photo_effect(img: Image.Image, rng) -> Image.Image:
    img = img.rotate(float(rng.uniform(-4, 4)), resample=Image.BICUBIC, fillcolor=235, expand=False)
    img = img.filter(ImageFilter.GaussianBlur(float(rng.uniform(0.6, 1.2))))
    a = np.asarray(img, dtype=np.float32)
    grad = np.linspace(float(rng.uniform(0.8, 1.0)), 1.0, W, dtype=np.float32)[None, :]  # uneven light
    a = a * grad + rng.normal(0, 9, (H, W)).astype(np.float32)
    return Image.fromarray(np.clip(a, 0, 255).astype(np.uint8))


def jpeg(img: Image.Image, q: int) -> bytes:
    b = io.BytesIO()
    img.save(b, "JPEG", quality=q, optimize=False)
    return b.getvalue()


def native_page(c: canvas.Canvas, lines: list[str]) -> None:
    c.setFont("Helvetica", 11)
    y = A4[1] - 60
    for ln in lines:
        c.drawString(50, y, ln)
        y -= 14.2
    c.showPage()


def image_page(c: canvas.Canvas, jpg: bytes, garbage: str | None = None, visible: bool = True) -> None:
    c.drawImage(ImageReader(io.BytesIO(jpg)), 0, 0, width=A4[0], height=A4[1])
    if garbage:
        t = c.beginText(50, 30)
        t.setFont("Helvetica", 9)
        t.setTextRenderMode(0 if visible else 3)
        t.textLine(garbage)
        c.drawText(t)
    c.showPage()


def build_file(job: tuple) -> dict:
    global _model
    seed, fid, kind, n_pages, out = job
    if _model is None:
        _model = TextModel(seed)
    rng = np.random.default_rng([seed, fid])
    lines = _model.document_lines(rng, n_pages * LINES_PER_PAGE, WIDTH_COLS)
    pages = [lines[i * LINES_PER_PAGE:(i + 1) * LINES_PER_PAGE] for i in range(n_pages)]
    folder = folder_for(fid, rng)
    rel = f"files/{kind}/doc{fid:06d}.{'jpg' if kind == 'photo' else 'pdf'}"
    path = os.path.join(out, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)

    if kind == "photo":
        with open(path, "wb") as fh:
            fh.write(jpeg(photo_effect(render_page(pages[0]), rng), 55))
    else:
        c = canvas.Canvas(path, pagesize=A4, invariant=1, pageCompression=1)
        for i, pg in enumerate(pages):
            if kind == "native" or (kind == "mixed" and i % 2 == 0):
                native_page(c, pg)
                continue
            jpg = jpeg(scan_effect(render_page(pg), rng), 65)
            if kind.startswith("fake"):
                garbage = " ".join(_model.words(rng, 14))[::-1]   # reversed: plausible shape, wrong content
                image_page(c, jpg, garbage, visible=(kind == "fake_visible"))
            else:
                image_page(c, jpg)
        c.save()
    # Ground truth for CER: the text actually rendered. Mixed: even pages native (text layer), odd pages image-only.
    image_only = [kind in ("scanned", "photo") or kind.startswith("fake") or (kind == "mixed" and i % 2 == 1)
                  for i in range(n_pages)]
    return {"file_id": fid, "path": rel, "kind": kind, "pages": n_pages, "folder": f"gf{folder:03d}",
            "principals": principals_for(folder), "image_only": image_only,
            "page_text": ["\n".join(p) for p in pages]}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--pages", type=int, default=6000, help="target total pages")
    ap.add_argument("--out", required=True)
    ap.add_argument("--procs", type=int, default=4)
    a = ap.parse_args()
    os.makedirs(a.out, exist_ok=True)

    plan_rng = np.random.default_rng([a.seed, 0])
    jobs, total, fid = [], 0, 0
    while total < a.pages:
        fid += 1
        kind = KINDS[int(plan_rng.choice(len(KINDS), p=KIND_P))]
        n = 1 if kind == "photo" else int(np.clip(plan_rng.lognormal(2.6, 0.7), 2, 120))
        jobs.append((a.seed, fid, kind, n, a.out))
        total += n
    with mp.Pool(a.procs) as pool, open(os.path.join(a.out, "manifest.jsonl"), "w", encoding="utf-8") as mf:
        for rec in pool.imap(build_file, jobs, chunksize=4):   # imap keeps job order -> stable manifest
            mf.write(json.dumps(rec, ensure_ascii=False, sort_keys=True) + "\n")
    print(f"files={len(jobs)} pages={total} out={a.out}")


if __name__ == "__main__":
    main()
