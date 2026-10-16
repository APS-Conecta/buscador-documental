"""Synthetic 1M-page-equivalent chunk stream for the S0 latency runs (random vectors: latency only).

Layout: 60 chunks per file (20 pages x 3 chunks), ~150 words per chunk, so 3M chunks -> 50k files.
Batches are pure functions of (seed, batch_idx): the load can run on any number of processes.
`vec_copy_bytes` builds Postgres BINARY COPY rows for (chunk_id int8, file_id int8, vec halfvec(384)) with
numpy, since text-formatting 1.15 G floats in Python would dominate the load.
"""
import argparse
import struct

import numpy as np

from gen_corpus import N_FOLDERS, NARROW_EVERY, principals_for
from zipf import TextModel

DIM = 384
CHUNKS_PER_PAGE = 3
PAGES_PER_FILE = 20
CHUNKS_PER_FILE = CHUNKS_PER_PAGE * PAGES_PER_FILE
BATCH = 20_000
COPY_SIG = b"PGCOPY\n\xff\r\n\x00" + struct.pack(">ii", 0, 0)


def file_acl_rows(seed: int, n_files: int) -> list[tuple[int, str]]:
    """(file_id, principals as a Postgres text[] literal); same folder/share model as the functional corpus."""
    rng = np.random.default_rng([seed, 7])
    folder = rng.integers(2, N_FOLDERS + 1, n_files)
    rows = []
    for i in range(n_files):
        f = 1 if (i + 1) % NARROW_EVERY == 1 else int(folder[i])
        rows.append((i + 1, "{" + ",".join(f'"{x}"' for x in principals_for(f)) + "}"))
    return rows


def chunk_rows(model: TextModel, seed: int, batch_idx: int, n_chunks: int):
    """Yield (chunk_id, file_id, page, text) for one batch (1-based ids)."""
    rng = np.random.default_rng([seed, 1, batch_idx])
    first = batch_idx * BATCH
    n = min(BATCH, n_chunks - first)
    counts = rng.integers(100, 201, n)
    words = model.words(rng, int(counts.sum()))
    pos = 0
    for k in range(n):
        cid = first + k + 1
        text = " ".join(words[pos:pos + counts[k]])
        pos += counts[k]
        yield cid, (cid - 1) // CHUNKS_PER_FILE + 1, ((cid - 1) % CHUNKS_PER_FILE) // CHUNKS_PER_PAGE + 1, text


def text_copy_bytes(model: TextModel, seed: int, batch_idx: int, n_chunks: int) -> bytes:
    """TEXT-format COPY lines for bd_spike.chunk(chunk_id, file_id, page, text); words never contain tabs or backslashes."""
    return "".join(f"{c}\t{f}\t{p}\t{t}\n" for c, f, p, t in chunk_rows(model, seed, batch_idx, n_chunks)).encode()


def vec_copy_bytes(seed: int, batch_idx: int, n_chunks: int) -> bytes:
    """BINARY COPY rows (no header/trailer) for bd_spike.chunk_vec(chunk_id, file_id, vec)."""
    first = batch_idx * BATCH
    n = min(BATCH, n_chunks - first)
    rng = np.random.default_rng([seed, 2, batch_idx])
    v = rng.standard_normal((n, DIM), dtype=np.float32)
    v /= np.linalg.norm(v, axis=1, keepdims=True)
    row = np.dtype([("nf", ">i2"), ("l1", ">i4"), ("cid", ">i8"), ("l2", ">i4"), ("fid", ">i8"),
                    ("l3", ">i4"), ("dim", ">i2"), ("unused", ">i2"), ("vec", ">f2", (DIM,))])
    a = np.zeros(n, dtype=row)
    ids = np.arange(first + 1, first + n + 1, dtype=np.int64)
    a["nf"], a["l1"], a["l2"], a["l3"] = 3, 8, 8, 4 + 2 * DIM
    a["cid"], a["fid"] = ids, (ids - 1) // CHUNKS_PER_FILE + 1
    a["dim"], a["unused"], a["vec"] = DIM, 0, v.astype(np.float16)
    return a.tobytes()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--dry-run", type=int, default=1000, help="chunks to generate and measure")
    a = ap.parse_args()
    m = TextModel(a.seed)
    t = text_copy_bytes(m, a.seed, 0, a.dry_run)
    v = vec_copy_bytes(a.seed, 0, a.dry_run)
    import hashlib
    print(f"text {len(t) / a.dry_run:.0f} B/chunk, vec row {len(v) // a.dry_run} B, acl sample {file_acl_rows(a.seed, 3)}")
    print("sha256 text", hashlib.sha256(t).hexdigest()[:16], "vec", hashlib.sha256(v).hexdigest()[:16],
          "acl", hashlib.sha256(repr(file_acl_rows(a.seed, 2000)).encode()).hexdigest()[:16])
