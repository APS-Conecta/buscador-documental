# SPDX-License-Identifier: AGPL-3.0-or-later
"""Per-page OCR policy (ADR-0012). Runs inside the parser container only.

Each PDF page is classified from its own text layer:
  native      plausible text: kept as is (source "layer", no confidence)
  image-only  no usable text: OCR (ocrmypdf --skip-text) (source "ocr")
  untrusted   text that fails the plausibility tests: OCR with --force-ocr, the layer is thrown away
OCR runs on one-page PDFs, so a timeout or failure is attributed to a page and nothing depends on how ocrmypdf lays out
its sidecar for a whole document. Confidence is the mean Tesseract word confidence (0-100) of the OCR'd page.
Known limit: an invisible layer that looks like real prose passes the cheap tests and is kept."""
import csv
import io
import os
import re
import subprocess
import tempfile
from functools import lru_cache

import ocr
from ocr import LANGS, OcrFailed, OcrTimeout

POLICY = "perpage-1"
ROTATE_THRESHOLD = "2.0"       # ocrmypdf default 14 ignores sparse pages (OSD confidence ~4.5 on a four-line page)
PAGE_TIMEOUT = int(os.environ.get("BD_PAGE_TIMEOUT", "300"))
TESSERACT_TIMEOUT = 180
MIN_CHARS = 5
MAX_BAD_SHARE = 0.02          # U+FFFD, private-use and control characters
MIN_LETTER_SHARE = 0.5
STOPWORD_CHECK_FROM = 30      # letters; shorter snippets ("Página 3 de 10") are not judged by vocabulary

STOPWORDS = set("""
de la el en y a los que del las por con una para es se un al lo como mas o su no sus este esta son pero ha fue si
ya entre sobre sin desde muy hay cada tambien segun ser han tiene hasta donde cuando nos le les me mi te tu
the of and to in is that for it with as was on are by this be or from at have an not but they his her their which
will would can has had were been one all there when what so if out about into than them these those
""".split())


def classify(text: str) -> str:
    chars = [c for c in text if not c.isspace()]
    if len(chars) < MIN_CHARS:
        return "image-only"
    bad = sum(1 for c in chars if c == "�" or 0xE000 <= ord(c) <= 0xF8FF or (ord(c) < 32) or 0x7F <= ord(c) < 0xA0)
    letters = sum(1 for c in chars if c.isalpha())
    if bad / len(chars) > MAX_BAD_SHARE or letters / len(chars) < MIN_LETTER_SHARE:
        return "untrusted"
    if letters >= STOPWORD_CHECK_FROM:
        words = re.findall(r"[^\W\d_]+", text.lower())
        hits = sum(1 for w in words if _fold(w) in STOPWORDS)
        if hits == 0:
            return "untrusted"
    return "native"


def _fold(w: str) -> str:
    import unicodedata
    return "".join(c for c in unicodedata.normalize("NFD", w) if not unicodedata.combining(c))


@lru_cache(maxsize=1)
def version() -> str:
    return f"{ocr.version()}; policy={POLICY}"


def ocr_page(src: str, force: bool, workdir: str, image: bool = False) -> tuple:
    """OCRs a one-page PDF (or an image). Returns (text, output_pdf)."""
    sidecar = os.path.join(workdir, "side.txt")
    out_pdf = os.path.join(workdir, "out.pdf")
    cmd = ["ocrmypdf", "--sidecar", sidecar, "--force-ocr" if force else "--skip-text", "--deskew", "--rotate-pages",
           "--rotate-pages-threshold", ROTATE_THRESHOLD, "-l", LANGS, "--output-type", "pdf", "--optimize", "0", "--jobs", "1",
           "--tesseract-timeout", str(TESSERACT_TIMEOUT), "-q"]
    if image:
        cmd += ["--image-dpi", "300"]
    try:
        r = subprocess.run(cmd + [src, out_pdf], capture_output=True, text=True, timeout=PAGE_TIMEOUT)
    except subprocess.TimeoutExpired as e:
        raise OcrTimeout() from e
    if r.returncode != 0:
        raise OcrFailed(f"ocrmypdf exit {r.returncode}: {r.stderr[-200:]}")
    with open(sidecar, encoding="utf-8", errors="replace") as fh:
        text = fh.read().replace("\f", "").strip()
    return text, out_pdf


def page_confidence(pdf: str, workdir: str) -> float:
    """Mean Tesseract word confidence over the rasterised first page of `pdf`."""
    png = os.path.join(workdir, "page.png")
    r = subprocess.run(["gs", "-q", "-dSAFER", "-dBATCH", "-dNOPAUSE", "-dFirstPage=1", "-dLastPage=1", "-sDEVICE=pnggray",
                        "-r200", f"-sOutputFile={png}", pdf], capture_output=True, timeout=PAGE_TIMEOUT)
    if r.returncode != 0 or not os.path.exists(png):
        raise OcrFailed("rasterising for confidence failed")
    try:
        t = subprocess.run(["tesseract", png, "stdout", "-l", LANGS, "tsv"], capture_output=True, text=True,
                           timeout=PAGE_TIMEOUT)
    except subprocess.TimeoutExpired as e:
        raise OcrTimeout() from e
    if t.returncode != 0:
        raise OcrFailed("tesseract tsv failed")
    confs = []
    for row in csv.DictReader(io.StringIO(t.stdout), delimiter="\t", quoting=csv.QUOTE_NONE):
        try:
            if int(row["level"]) == 5 and float(row["conf"]) >= 0 and (row.get("text") or "").strip():
                confs.append(float(row["conf"]))
        except (ValueError, KeyError, TypeError):
            continue
    return round(sum(confs) / len(confs), 1) if confs else 0.0


def _layer_texts(path: str) -> list:
    import extract
    return extract.extract(path)


def _single_page(src: str, index: int, dst: str) -> None:
    import pikepdf
    with pikepdf.open(src) as pdf, pikepdf.new() as one:
        one.pages.append(pdf.pages[index])
        one.save(dst)


def _is_image(path: str) -> bool:
    with open(path, "rb") as fh:
        return fh.read(4).startswith((b"\x89PNG", b"\xff\xd8\xff", b"II*\x00", b"MM\x00*"))


def run(path: str, layer: list = None) -> dict:
    """Returns {"pages": [...], "conf": [... or None], "source": ["layer"|"ocr", ...]}."""
    pages, conf, source = [], [], []
    with tempfile.TemporaryDirectory() as tmp:
        if _is_image(path):
            text, out = ocr_page(path, False, tmp, image=True)
            return {"pages": [text], "conf": [page_confidence(out, tmp)], "source": ["ocr"]}
        layer = layer if layer is not None else _layer_texts(path)
        for i, text in enumerate(layer):
            kind = classify(text)
            if kind == "native":
                pages.append(text)
                conf.append(None)
                source.append("layer")
                continue
            work = os.path.join(tmp, f"p{i}")
            os.makedirs(work)
            one = os.path.join(work, "in.pdf")
            _single_page(path, i, one)
            ocr_text, out = ocr_page(one, force=(kind == "untrusted"), workdir=work)
            pages.append(ocr_text)
            conf.append(page_confidence(out, work))
            source.append("ocr")
    return {"pages": pages, "conf": conf, "source": source}
