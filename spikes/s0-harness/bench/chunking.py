"""Naive token-bounded chunker for S0 (m3/m4). Target 350 e5 tokens (architecture: 300-400 incl. heading/page prefix),
paragraph-aware, 10 % overlap. S4 owns the real chunker; this only sizes chunks/page and the embedding workload."""
from embed import Embedder

TARGET, OVERLAP = 350, 35


def chunk_page(tok, text: str) -> list[str]:
    words = text.split()
    chunks, cur, n = [], [], 0
    for w in words:
        t = len(tok.encode(w, add_special_tokens=False).ids)
        if n + t > TARGET and cur:
            chunks.append(" ".join(cur))
            keep, kn = [], 0
            for x in reversed(cur):                          # carry the last ~OVERLAP tokens
                kt = len(tok.encode(x, add_special_tokens=False).ids)
                if kn + kt > OVERLAP:
                    break
                keep.insert(0, x)
                kn += kt
            cur, n = keep, kn
        cur.append(w)
        n += t
    if cur:
        chunks.append(" ".join(cur))
    return chunks
