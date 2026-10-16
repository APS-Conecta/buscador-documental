# SPDX-License-Identifier: AGPL-3.0-or-later
"""Placeholder chunker. STUB(S2): the real chunker (headings, tables, overlap) replaces this.

Splits each page on blank lines and packs paragraphs up to `target` characters. The app caps a payload at 2000 chunks of
20 000 characters; if a document would exceed the count, the target doubles until it fits."""

CHUNKER_VER = "placeholder-1"
MAX_CHUNKS = 2000
MAX_CHARS = 20000


def _split_page(text: str, target: int) -> list[str]:
    out: list[str] = []
    cur = ""
    for para in (p.strip() for p in text.replace("\r", "").split("\n\n")):
        if not para:
            continue
        while len(para) > MAX_CHARS:                 # a single huge paragraph
            out.append(para[:MAX_CHARS])
            para = para[MAX_CHARS:]
        if cur and len(cur) + len(para) + 2 > target:
            out.append(cur)
            cur = para
        else:
            cur = f"{cur}\n\n{para}" if cur else para
    if cur:
        out.append(cur)
    return out


def split(pages: list[str], target: int = 1000) -> list[dict]:
    """pages[i] is the text of page i+1. Returns [{page_no, ord, heading, text}] with ord increasing over the document."""
    while True:
        chunks = []
        for i, text in enumerate(pages, start=1):
            for piece in _split_page(text, target):
                chunks.append({"page_no": i, "ord": len(chunks), "heading": None, "text": piece})
        if len(chunks) <= MAX_CHUNKS or target >= MAX_CHARS:
            return chunks[:MAX_CHUNKS]
        target *= 2
