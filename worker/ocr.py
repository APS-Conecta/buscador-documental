# SPDX-License-Identifier: AGPL-3.0-or-later
"""OCR through ocrmypdf with a sidecar text file. Real extractors for born-digital formats are S2."""
import os
import subprocess
import tempfile
from functools import lru_cache

TIMEOUT = int(os.environ.get("BD_OCR_TIMEOUT", "900"))
LANGS = os.environ.get("BD_OCR_LANGS", "spa+eng")


class OcrFailed(Exception):
    pass


class OcrTimeout(Exception):
    pass


@lru_cache(maxsize=1)
def version() -> str:
    """Tool versions recorded with every result (ocr_ver)."""
    def first(cmd):
        r = subprocess.run(cmd, capture_output=True, text=True)
        return (r.stdout or r.stderr).strip().splitlines()[0] if (r.stdout or r.stderr) else "unknown"
    return f"ocrmypdf {first(['ocrmypdf', '--version'])}; {first(['tesseract', '--version'])}; langs={LANGS}"


def run(pdf_path: str) -> list[str]:
    """Returns the text of each page. Pages that already carry a text layer are kept (--skip-text)."""
    with tempfile.TemporaryDirectory() as tmp:
        sidecar = os.path.join(tmp, "side.txt")
        out_pdf = os.path.join(tmp, "out.pdf")
        cmd = ["ocrmypdf", "--sidecar", sidecar, "--skip-text", "--deskew", "--rotate-pages",
               "-l", LANGS, "--output-type", "pdf", "-q", pdf_path, out_pdf]
        try:
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=TIMEOUT)
        except subprocess.TimeoutExpired as e:
            raise OcrTimeout() from e
        if r.returncode != 0:
            raise OcrFailed(f"ocrmypdf exit {r.returncode}")
        with open(sidecar, encoding="utf-8", errors="replace") as fh:
            raw = fh.read()
        pages = raw.split("\f")
        if pages and pages[-1].strip() == "":
            pages.pop()
        return _fill_skipped(pdf_path, _align(pdf_path, pages))


def _page_count(pdf_path: str) -> int:
    import pikepdf
    with pikepdf.open(pdf_path) as pdf:
        return len(pdf.pages)


def _align(pdf_path: str, pages: list[str]) -> list[str]:
    """The sidecar must have one entry per page. When every page was skipped ocrmypdf writes a single marker for all of
    them ("[OCR skipped on page(s) 1-2]"); any other mismatch cannot be mapped safely and is refused (retryable)."""
    n = _page_count(pdf_path)
    if len(pages) == n:
        return pages
    if len(pages) == 1 and pages[0].strip().startswith("[OCR skipped"):
        return [pages[0]] * n
    raise OcrFailed(f"sidecar has {len(pages)} entries for {n} pages")


def _is_skipped(page: str) -> bool:
    """ocrmypdf 14 writes "[OCR skipped on page(s) N]" (older/other builds "[skipped page]") for pages it left alone."""
    t = page.strip()
    return t == "" or t == "[skipped page]" or t.startswith("[OCR skipped")


def _fill_skipped(pdf_path: str, pages: list[str]) -> list[str]:
    """With --skip-text, pages that already had text come back as a marker; read their text layer instead."""
    if not any(_is_skipped(p) for p in pages):
        return pages
    try:
        from pypdf import PdfReader
        reader = PdfReader(pdf_path)
    except Exception:
        return [("" if _is_skipped(p) else p) for p in pages]
    out = []
    for i, p in enumerate(pages):
        if _is_skipped(p) and i < len(reader.pages):
            try:
                out.append(reader.pages[i].extract_text() or "")
                continue
            except Exception:
                pass
        out.append(p)
    return out
