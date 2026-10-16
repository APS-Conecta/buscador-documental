"""Shared synthetic-Spanish text model for the S0 spike (corpus, vectors, gold set).

Vocabulary comes from the Debian `wspanish` dictionary (public, UTF-8). Content words are drawn
Zipf-distributed over a seed-shuffled rank order, so the head terms are arbitrary but fixed per seed;
~40 % of tokens are Spanish function words (dropped by the FTS stopword list, as in real text).
Everything derives from numpy's PCG64 so equal seeds give equal text.
"""
import re
import textwrap

import numpy as np

DICT = "/usr/share/dict/spanish"
STOP = ("de la que el en y a los se del las un por con no una su para es al lo como más pero sus le ya "
        "o este sí porque esta entre cuando muy sin sobre también me hasta hay donde quien desde todo "
        "nos durante todos uno les ni contra otros ese eso ante ellos e esto mí antes algunos qué").split()
ZIPF_S = 1.0
STOP_SHARE = 0.40
ROMAN = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII", "XIII", "XIV", "XV", "XVI"]


def load_vocab(seed: int) -> list[str]:
    stop = set(STOP)
    with open(DICT, encoding="utf-8") as fh:
        words = [w.strip() for w in fh]
    words = sorted({w for w in words if w.isalpha() and 4 <= len(w) <= 14 and w.islower() and w not in stop})
    rng = np.random.default_rng(seed)
    order = rng.permutation(len(words))
    return [words[i] for i in order]


class TextModel:
    def __init__(self, seed: int):
        self.vocab = load_vocab(seed)
        n = len(self.vocab)
        p = 1.0 / np.arange(1, n + 1) ** ZIPF_S
        self.cdf = np.cumsum(p / p.sum())
        sp = 1.0 / np.arange(1, len(STOP) + 1)
        self.stop_cdf = np.cumsum(sp / sp.sum())

    def words(self, rng: np.random.Generator, n: int) -> list[str]:
        is_stop = rng.random(n) < STOP_SHARE
        content = np.minimum(np.searchsorted(self.cdf, rng.random(n)), len(self.vocab) - 1)
        stops = np.minimum(np.searchsorted(self.stop_cdf, rng.random(n)), len(STOP) - 1)
        return [STOP[s] if st else self.vocab[c] for st, c, s in zip(is_stop, content, stops)]

    def sentence(self, rng: np.random.Generator) -> str:
        w = self.words(rng, int(rng.integers(8, 19)))
        w[0] = w[0].capitalize()
        return " ".join(w) + "."

    def document_lines(self, rng: np.random.Generator, n_lines: int, width: int = 78) -> list[str]:
        """Articles with Capítulo headings, wrapped to `width` columns, at least n_lines lines."""
        lines: list[str] = []
        art = 0
        while len(lines) < n_lines:
            if art % 6 == 0:
                lines += ["", f"CAPÍTULO {ROMAN[(art // 6) % len(ROMAN)]}", ""]
            art += 1
            body = " ".join(self.sentence(rng) for _ in range(int(rng.integers(3, 8))))
            lines += textwrap.wrap(f"Artículo {art}. {body}", width) + [""]
        return lines[:n_lines]

    def chunk_text(self, rng: np.random.Generator, n_words: int) -> str:
        return " ".join(self.words(rng, n_words))


def norm_tokens(text: str) -> list[str]:
    return re.findall(r"\w+", text.lower())
