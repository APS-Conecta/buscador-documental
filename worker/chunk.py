# SPDX-License-Identifier: AGPL-3.0-or-later
"""Structure-aware chunker (S2). Splits extracted pages into chunks for the index.

Units are articles ("Artículo 5º.") and the text between section lines (Título, Capítulo, Párrafo, Libro, disposiciones
transitorias); documents without structure form one unit. Inside a unit, sentences (table rows whole) are packed up to
TARGET tokens without crossing a page, every chunk repeats the unit's header as its first line, and consecutive chunks of the same unit overlap by
whole trailing sentences up to OVERLAP_MAX tokens. Nothing is ever split inside a whitespace-separated token except a
single token longer than the limits. A document that cannot fit MAX_CHUNKS is refused (TooManyChunks), never truncated.

Tokens are counted conservatively as max(1.3 x words, characters / 4) until the embedding profile's tokenizer arrives in S4.
The header regexes are adapted from the legal-corpus converter in the org's Databases/Legal tooling."""
import math
import re

CHUNKER_VER = "struct-1"
MAX_CHUNKS = 2000
MAX_CHARS = 20000
TARGET = 350
MAX_TOKENS = 500
OVERLAP_MAX = 52                 # about 15 % of TARGET
MAX_HEADER_TOKENS = 60

# "Artículo" is capitalised when it opens an article and lower-case in a cross-reference ("el artículo 173 del Código"),
# and the label must be followed by punctuation: both keep in-sentence references from opening a new article.
RE_ARTICULO = re.compile(
    r"^((?:Art[íi]culo|ART[ÍI]CULO)\s+"
    r"(?i:(\d+|primero|segundo|tercero|cuarto|quinto|sexto|séptimo|septimo"
    r"|octavo|noveno|décimo|decimo|undécimo|undecimo|duodécimo|duodecimo"
    r"|[IVXLC]+)(?![A-Za-záéíóúñüÁÉÍÓÚÑÜ])"
    r"(?:\s*(?:º|°|\.º|bis|ter|quáter|quater|quinquies|sexies|septies|octies|nonies|decies))*)"
    r"\s*[.\-–]+(?!\d))")      # not "Artículo 21.719": a number with a thousands dot is a reference, not a header
RE_ESTRUCTURA = re.compile(
    r"^(?P<kw>T[ÍI]TULO|Cap[íi]tulo|CAP[ÍI]TULO|P[áa]rrafo|P[ÁA]RRAFO|LIBRO)"
    r"\s+(?:\d+|[IVXLC]+|Preliminar|Final|[ÚU]nico)(?:\s*[º°])?"
    r"(?=\s*$|\s*[.:\-–—]|\s+[A-ZÁÉÍÓÚÑ])"          # a numeral, then nothing, punctuation or a capital: not "Capítulo 3 del presente..."
    r"|^(?P<tr>[Dd]isposiciones\s+[Tt]ransitorias|DISPOSICIONES\s+TRANSITORIAS|[Aa]rt[íi]culos?\s+[Tt]ransitorios?)\b")
LEVELS = {"LIBRO": 0, "TÍTULO": 1, "TITULO": 1, "CAPÍTULO": 2, "CAPITULO": 2, "PÁRRAFO": 3, "PARRAFO": 3}
MAX_HEADING_LINE = 100
RE_SENTENCE = re.compile(r"(?<=[.!?;:])\s+(?=[A-ZÁÉÍÓÚÑ¿¡«\"(])")


class TooManyChunks(Exception):
    """The document cannot fit the chunk cap; it must be refused, not cut."""


_SURROGATES = re.compile("[\ud800-\udfff]")


def clean(text: str) -> str:
    """jsonb rejects NUL, and PostgreSQL rejects a lone UTF-16 surrogate (a malformed PDF string can produce one)."""
    return _SURROGATES.sub("\ufffd", text.replace("\x00", ""))


def tokens(s: str) -> int:
    return max(math.ceil(len(s.split()) * 1.3), math.ceil(len(s) / 4))


class _Atom:
    __slots__ = ("page", "text", "row", "para")

    def __init__(self, page, text, row=False, para=False):
        self.page, self.text, self.row, self.para = page, text, row, para


class _Unit:
    def __init__(self, section, label, page=1):
        self.section, self.label, self.page, self.atoms = section, label, page, []


def _pieces(text: str, limit: int) -> list:
    """Splits one oversize atom at whitespace into pieces of at most `limit` tokens; a single over-long token is cut by characters."""
    out, cur = [], []
    for w in text.split():
        if tokens(w) > limit:
            if cur:
                out.append(" ".join(cur))
                cur = []
            step = max(1, limit * 4)
            out.extend(w[i:i + step] for i in range(0, len(w), step))
            continue
        if cur and tokens(" ".join(cur + [w])) > limit:
            out.append(" ".join(cur))
            cur = []
        cur.append(w)
    if cur:
        out.append(" ".join(cur))
    return out


def _units(pages: list) -> list:
    units, levels, pending = [], {}, []
    section = None
    cur = None
    para = []                                    # buffered lines of the current paragraph, with the page of the first one

    def flush():
        nonlocal para, cur
        if not para:
            return
        page, lines = para[0][0], [ln for _, ln in para]
        para = []
        text = " ".join(" ".join(lines).split())
        if not text:
            return
        if cur is None:
            cur = _Unit(section, None, page)
            cur.atoms.extend(pending)
            pending.clear()
            units.append(cur)
        for i, s in enumerate(RE_SENTENCE.split(text)):
            cur.atoms.append(_Atom(page, s, para=(i == 0)))

    for page_no, text in enumerate(pages, start=1):
        flush()                                  # a paragraph never runs across a page, so every atom has its own page
        for raw in clean(text).split("\n"):
            line = raw.strip()
            if not line:
                flush()
                continue
            m = RE_ARTICULO.match(line)
            if m:
                flush()
                cur = _Unit(section, " ".join(m.group(1).split()), page_no)   # label keeps its own punctuation: every input token survives
                cur.atoms.extend(pending)
                pending.clear()
                units.append(cur)
                rest = line[m.end():].strip()
                if rest:
                    para = [(page_no, rest)]
                continue
            hm = RE_ESTRUCTURA.match(line) if len(line) <= MAX_HEADING_LINE else None
            if hm:
                flush()
                cur = None
                level = LEVELS.get((hm.group("kw") or "").upper(), 1)      # transitory provisions sit at title level
                levels = {k: v for k, v in levels.items() if k < level}
                levels[level] = line
                section = " › ".join(levels[k] for k in sorted(levels))
                pending.append(_Atom(page_no, line, para=True))
                continue
            if "\t" in raw:
                flush()
                if cur is None:
                    cur = _Unit(section, None, page_no)
                    cur.atoms.extend(pending)
                    pending.clear()
                    units.append(cur)
                cur.atoms.append(_Atom(page_no, raw.strip("\r\n"), row=True, para=True))
                continue
            para.append((page_no, line))
    flush()
    if pending:                                   # section lines with nothing after them
        u = _Unit(section, None, pending[0].page)
        u.atoms.extend(pending)
        units.append(u)
    return units


def _heading(u: _Unit):
    parts = [p for p in (u.section, u.label.rstrip(".-– ") if u.label else None) if p]
    return " › ".join(parts)[:200] or None


def _render(prefix: str, atoms: list) -> str:
    out = []
    for i, a in enumerate(atoms):
        if i:
            out.append("\n" if a.row or a.para or atoms[i - 1].row else " ")
        out.append(a.text)
    body = "".join(out)
    return f"{prefix}\n{body}" if prefix and body else (prefix or body)


def _chunks_for(u: _Unit, base_ord: int) -> list:
    prefix = u.label or ""
    if tokens(prefix) > MAX_HEADER_TOKENS:
        prefix = " ".join(prefix.split()[:40])
    p = tokens(prefix) + 1 if prefix else 0
    limit = max(40, TARGET - p)
    atoms = []
    for a in u.atoms:
        if tokens(a.text) > limit:
            if a.row:
                cells = a.text.split("\t")
                if len(cells) > 1:
                    group, size = [], 0
                    expanded = []
                    for c in cells:
                        expanded.extend(_pieces(c, limit) if tokens(c) > limit else [c])
                    for c in expanded:
                        if group and size + tokens(c) > limit:
                            atoms.append(_Atom(a.page, "\t".join(group), row=True, para=True))
                            group, size = [], 0
                        group.append(c)
                        size += tokens(c)
                    atoms.append(_Atom(a.page, "\t".join(group), row=True, para=True))
                    continue
            for i, piece in enumerate(_pieces(a.text, limit)):
                atoms.append(_Atom(a.page, piece, row=a.row, para=(a.para and i == 0)))
        else:
            atoms.append(a)
    if not atoms:
        return [{"page_no": u.page, "ord": base_ord, "heading": _heading(u), "text": prefix}] if prefix else []

    out, i = [], 0
    while i < len(atoms):
        start = i
        batch = []
        used = p
        while i < len(atoms):
            t = tokens(atoms[i].text) + 1
            if batch and (used + t > TARGET or atoms[i].page != batch[0].page):
                break                         # chunks never cross a page: "open at page N" must land on the text
            batch.append(atoms[i])
            used += t
            i += 1
        text = _render(prefix, batch)
        out.append({"page_no": batch[0].page, "ord": base_ord + len(out), "heading": _heading(u), "text": text})
        if base_ord + len(out) > MAX_CHUNKS:
            raise TooManyChunks(base_ord + len(out))
        if i < len(atoms) and atoms[i].page == batch[-1].page:
            # overlap: step back over whole trailing sentences that fit the budget, but never to the chunk start
            # (and not across a page break, where the next chunk starts clean)
            back, budget = 0, OVERLAP_MAX
            for a in reversed(batch[1:]):
                t = tokens(a.text) + 1
                if t > budget:
                    break
                budget -= t
                back += 1
            i -= back
    return out


def split(pages: list) -> list:
    """pages[i] is the text of page i+1. Returns [{page_no, ord, heading, text}] with ord increasing over the document."""
    chunks = []
    for u in _units(pages):
        chunks.extend(_chunks_for(u, len(chunks)))
        if len(chunks) > MAX_CHUNKS:
            raise TooManyChunks(len(chunks))
    for c in chunks:                              # last guard on the physical limit
        if len(c["text"]) > MAX_CHARS:
            raise TooManyChunks("chunk over the character cap")
    return chunks
