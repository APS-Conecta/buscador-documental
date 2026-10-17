# SPDX-License-Identifier: AGPL-3.0-or-later
"""Tier 1-2 extractors with hostile-input caps (S2). Runs inside the parser container only.

extract(path) -> list[str]  one string per page (sheet, slide, or the whole text for flowing formats).
Anything unusable raises a Refusal subclass whose `reason` is a value of the database skip-reason enum. The input file is
only ever opened for reading. Caps are the ones in buscador/02-performance.md."""
import io
import re
import zipfile

import defusedxml.ElementTree as DET
from defusedxml import DefusedXmlException
from xml.etree.ElementTree import ParseError

MAX_BYTES = 250 * 1024 * 1024
MAX_PAGES = 300
MAX_PIXELS = 50_000_000
MAX_ZIP_TOTAL = 1024 ** 3
MAX_ZIP_RATIO = 100
MAX_ZIP_ENTRIES = 10_000
RATIO_FLOOR = 1024 * 1024          # tiny entries compress legitimately well; only large ones are judged by ratio
MAX_XML_DEPTH = 100
MAX_XML_PART = 100 * 1024 * 1024
MAX_CELLS = 1_000_000
RASTER_DPI = 200                   # pages are rasterised at this density for OCR confidence and force-OCR
OLE2 = bytes.fromhex("d0cf11e0a1b11ae1")


class Refusal(Exception):
    reason = "internal"


class Unsafe(Refusal):
    reason = "unsafe"


class Corrupt(Refusal):
    reason = "corrupt"


class Encrypted(Refusal):
    reason = "encrypted"


class TooLarge(Refusal):
    reason = "too_large"


class Unsupported(Refusal):
    reason = "unsupported"


def extract(path: str) -> list:
    import os
    size = os.path.getsize(path)
    if size == 0:
        raise Corrupt("empty file")
    if size > MAX_BYTES:
        raise TooLarge("file over the size cap")
    with open(path, "rb") as fh:
        head = fh.read(16)
    if head.startswith(OLE2):
        raise Encrypted("OLE2 container: a password-protected Office file")      # the supported Office types are all zip-based
    if head.startswith(b"%PDF"):
        return _pdf(path)
    if head.startswith(b"PK\x03\x04"):
        return _zip_document(path)
    if head.startswith((b"\x89PNG", b"\xff\xd8\xff", b"II*\x00", b"MM\x00*")):
        return _image(path)
    return _text(path)


# ---- text ----
def _text(path):
    with open(path, "rb") as fh:
        data = fh.read()
    # Deterministic on purpose: statistical detectors guess wrong on short Spanish text. A UTF-16 BOM first (its NUL bytes
    # must not be mistaken for binary data), then UTF-8, then Windows-1252, which decodes every byte except five.
    if data.startswith((b"\xff\xfe", b"\xfe\xff")):
        try:
            return [data.decode("utf-16")]
        except UnicodeDecodeError as e:
            raise Corrupt("truncated UTF-16 text") from e
    if data.count(b"\x00") > len(data) // 100:
        raise Corrupt("binary data")
    try:
        return [data.decode("utf-8-sig")]
    except UnicodeDecodeError:
        pass
    try:
        return [data.decode("cp1252")]
    except UnicodeDecodeError:
        raise Corrupt("undecodable text")


# ---- images ----
def _image(path):
    from PIL import Image
    try:
        with Image.open(path) as im:
            w, h = im.size
            frames = getattr(im, "n_frames", 1)
    except Image.DecompressionBombError as e:
        raise Unsafe("pixel bomb") from e
    except Exception as e:
        raise Corrupt("unreadable image") from e
    if w * h > MAX_PIXELS:
        raise Unsafe(f"{w}x{h} pixels")
    if frames > 1:
        raise Unsupported("multi-frame image")      # later frames would go unchecked and be read as one page
    return [""]                                   # text comes from OCR


# ---- PDF ----
def page_images(page, _depth=0, _seen=None):
    """Yields (width, height) of every image reachable from a page, through nested Form XObjects. A non-numeric size is
    corrupt, not a crash."""
    import pikepdf
    seen = _seen if _seen is not None else set()
    res = page.get("/Resources") if hasattr(page, "get") else None
    xobjects = (res.get("/XObject") if res is not None else None) or {}
    for _name, xo in xobjects.items():
        key = xo.objgen if getattr(xo, "is_indirect", False) else None
        if key is not None:
            if key in seen:
                continue
            seen.add(key)
        sub = xo.get("/Subtype")
        if sub == "/Image":
            try:
                yield int(xo.get("/Width", 0)), int(xo.get("/Height", 0))
            except (TypeError, ValueError, pikepdf.PdfError) as e:
                raise Corrupt("image with a non-numeric size") from e
        elif sub == "/Form" and _depth < 8:
            yield from page_images(xo, _depth + 1, seen)


def _raster_pixels(page) -> float:
    box = [float(x) for x in page.MediaBox]
    return abs(box[2] - box[0]) * abs(box[3] - box[1]) * (RASTER_DPI / 72.0) ** 2


def _pdf(path):
    import pikepdf
    from pypdf import PdfReader
    try:
        pdf = pikepdf.open(path)
    except pikepdf.PasswordError as e:
        raise Encrypted("password required") from e
    except pikepdf.PdfError as e:
        raise Corrupt("unreadable PDF") from e
    with pdf:
        n = len(pdf.pages)
        if n > MAX_PAGES:
            raise TooLarge(f"{n} pages")
        for page in pdf.pages:
            try:
                if _raster_pixels(page) > MAX_PIXELS:
                    raise TooLarge("page area beyond the raster cap")
            except (TypeError, ValueError, AttributeError, pikepdf.PdfError) as e:
                raise Corrupt("unreadable page size") from e
            for w, h in page_images(page):
                if w * h > MAX_PIXELS:
                    raise Unsafe("pixel bomb in PDF")
        buf = io.BytesIO()
        pdf.save(buf)                              # decrypted copy of an owner-password-only file, in memory
    try:
        reader = PdfReader(io.BytesIO(buf.getvalue()))
        return [(p.extract_text() or "") for p in reader.pages]
    except Exception as e:
        raise Corrupt("unreadable text layer") from e


# ---- zip containers ----
def _open_zip(path):
    try:
        z = zipfile.ZipFile(path)
    except (zipfile.BadZipFile, OSError) as e:
        raise Corrupt("not a readable zip") from e
    infos = z.infolist()
    if len(infos) > MAX_ZIP_ENTRIES:
        raise Unsafe("too many zip entries")
    if sum(i.file_size for i in infos) > MAX_ZIP_TOTAL:
        raise Unsafe("zip expands past 1 GB")
    for i in infos:
        if i.file_size >= RATIO_FLOOR and i.file_size / max(1, i.compress_size) > MAX_ZIP_RATIO:
            raise Unsafe("zip expansion ratio")
    return z


def _zip_document(path):
    z = _open_zip(path)
    with z:
        names = set(z.namelist())
        try:
            if "word/document.xml" in names:
                return _docx(z)
            if any(n.startswith("ppt/slides/slide") for n in names):
                return _pptx(z)
            if "content.xml" in names and "mimetype" in names:
                return _odt(z)
            if "xl/workbook.xml" in names:
                sheets = [n for n in names if re.fullmatch(r"xl/worksheets/sheet\d+\.xml", n)]
                if len(sheets) > MAX_PAGES:
                    raise TooLarge(f"{len(sheets)} sheets")
                if any(z.getinfo(n).file_size > MAX_XML_PART for n in sheets):
                    raise Unsafe("worksheet part too large")
            else:
                raise Corrupt("unknown zip document")
        except (zipfile.BadZipFile, EOFError, OSError) as e:
            raise Corrupt("damaged zip entry") from e
    return _xlsx(path)


def _events(z, name):
    """Streams (event, element) with the depth cap and the entity ban enforced; the part size is capped as it is read."""
    info = z.getinfo(name)
    if info.file_size > MAX_XML_PART:
        raise Unsafe("XML part too large")

    class Capped(io.RawIOBase):
        def __init__(self, fh):
            self.fh, self.n = fh, 0

        def readable(self):
            return True

        def readinto(self, b):
            data = self.fh.read(len(b))
            self.n += len(data)
            if self.n > MAX_XML_PART:
                raise Unsafe("XML part larger than declared")
            b[: len(data)] = data
            return len(data)

    depth = 0
    try:
        with z.open(name) as fh:
            for ev, el in DET.iterparse(io.BufferedReader(Capped(fh)), events=("start", "end")):
                if ev == "start":
                    depth += 1
                    if depth > MAX_XML_DEPTH:
                        raise Unsafe("XML nesting too deep")
                else:
                    depth -= 1
                yield ev, el
    except DefusedXmlException as e:
        raise Unsafe("XML entities are not allowed") from e
    except ParseError as e:
        raise Corrupt("malformed XML") from e


def _local(tag):
    return tag.rsplit("}", 1)[-1]


def _docx(z):
    """Paragraphs, table rows (cells tab-joined) and text-box content, with stacks so nesting never loses text: a table
    inside a cell, or a text box inside a paragraph, is read in place and the outer text continues afterwards."""
    sinks = [[]]                  # where finished paragraphs/rows go: the body, or the cell being read
    rows, cells = [], []          # row stack (cells of the row being built), cell stack (sink of the cell)
    paras = []                    # paragraph stack (text parts)
    for ev, el in _events(z, "word/document.xml"):
        t = _local(el.tag)
        if ev == "start":
            if t == "p":
                if paras:
                    paras[-1].append(" ")            # a paragraph inside a paragraph (text box): keep the words apart
                paras.append([])
            elif t == "tr":
                rows.append([])
            elif t == "tc":
                cells.append([])
                sinks.append(cells[-1])
        else:
            if t == "t" and paras:
                paras[-1].append(el.text or "")
            elif t in ("tab", "br", "cr") and paras:
                paras[-1].append(" ")
            elif t == "p" and paras:
                text = "".join(paras.pop())
                if paras:
                    paras[-1].append(" ")
                if text.strip():
                    sinks[-1].append(text.strip())
            elif t == "tc" and cells:
                sinks.pop()
                cell = cells.pop()
                if rows:
                    rows[-1].append(" ".join(cell))
            elif t == "tr" and rows:
                sinks[-1].append("\t".join(rows.pop()))
            el.clear()
    return ["\n".join(sinks[0])]


def _pptx(z):
    slides = sorted((n for n in z.namelist() if re.fullmatch(r"ppt/slides/slide\d+\.xml", n)),
                    key=lambda n: int(re.findall(r"\d+", n)[0]))
    if len(slides) > MAX_PAGES:
        raise TooLarge(f"{len(slides)} slides")
    pages = []
    for name in slides:
        paras, cur = [], None
        for ev, el in _events(z, name):
            t = _local(el.tag)
            if ev == "start" and t == "p":
                cur = []
            elif ev == "end" and t == "t" and cur is not None:
                cur.append(el.text or "")
            elif ev == "end" and t == "br" and cur is not None:
                cur.append(" ")
            elif ev == "end" and t == "p" and cur is not None:
                if "".join(cur).strip():
                    paras.append("".join(cur))
                cur = None
                el.clear()
        pages.append("\n".join(paras))
    return pages


def _odt_text(el):
    """Text of an element with ODF whitespace elements turned into spaces (depth is bounded by the XML depth cap)."""
    parts = [el.text or ""]
    for ch in el:
        parts.append(" " if _local(ch.tag) in ("tab", "line-break", "s") else _odt_text(ch))
        parts.append(ch.tail or "")
    return "".join(parts)


def _odt(z):
    paras = []
    for ev, el in _events(z, "content.xml"):
        if ev == "end" and _local(el.tag) in ("p", "h"):
            text = _odt_text(el).strip()
            if text:
                paras.append(text)
            el.clear()
    return ["\n".join(paras)]


def _xlsx(path):
    import openpyxl
    fh = open(path, "rb")                      # a file object: the parser's input name has no extension
    try:
        wb = openpyxl.load_workbook(fh, read_only=True, data_only=True)
    except DefusedXmlException as e:
        fh.close()
        raise Unsafe("XML entities are not allowed") from e
    except Exception as e:
        fh.close()
        raise Corrupt("unreadable workbook") from e
    cells, pages = 0, []
    try:
        for ws in wb.worksheets:
            lines = []
            for row in ws.iter_rows(values_only=True):
                cells += len(row)
                if cells > MAX_CELLS:
                    raise TooLarge("more than 1 000 000 cells")
                if any(c is not None for c in row):
                    lines.append("\t".join("" if c is None else str(c) for c in row).rstrip("\t"))
            pages.append("\n".join(lines))
    except Refusal:
        raise
    except Exception as e:
        raise Corrupt("unreadable sheet") from e
    finally:
        wb.close()
        fh.close()
    return pages
