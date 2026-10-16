"""Build schema bd_recall: ~30k real-text chunks (Spanish Wikipedia sample, wikimedia/wikipedia 20231101.es via the HF
datasets-server) embedded with e5-small, with the same ACL model as the synthetic corpus (F1 ~1.6 % of files).
Used by m8 (recall needs real embedding geometry) and as the embedding workload for m4. Deterministic offsets.

  python3 bench/prep_recall_corpus.py --chunks 30000
"""
import argparse
import json
import re
import sys
import time
import urllib.parse
import urllib.request

import numpy as np

sys.path.insert(0, "/work/bench")
sys.path.insert(0, "/work/corpus")
from embed import Embedder, sha256  # noqa: E402
from gen_vectors import file_acl_rows  # noqa: E402
from lib import connect, write_result  # noqa: E402

API = "https://datasets-server.huggingface.co/rows"
WORDS = 150
CHUNKS_PER_FILE = 30


def articles(n_articles, seed=1):
    rng = np.random.default_rng([seed, 5])
    got = 0
    while got < n_articles:
        off = int(rng.integers(0, 1_800_000))
        q = urllib.parse.urlencode({"dataset": "wikimedia/wikipedia", "config": "20231101.es", "split": "train", "offset": off, "length": 100})
        for attempt in range(5):
            try:
                rows = json.load(urllib.request.urlopen(f"{API}?{q}", timeout=60))["rows"]
                break
            except Exception:
                time.sleep(2 * (attempt + 1))
        else:
            continue
        for r in rows:
            yield r["row"]["text"]
            got += 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--chunks", type=int, default=30000)
    ap.add_argument("--threads", type=int, default=6)
    a = ap.parse_args()

    chunks = []
    for text in articles(10 ** 6):
        words = re.sub(r"\s+", " ", text).split()
        for i in range(0, len(words) - WORDS // 2, WORDS):
            chunks.append(" ".join(words[i:i + WORDS]))
        if len(chunks) >= a.chunks:
            break
    chunks = chunks[:a.chunks]
    print(f"{len(chunks)} chunks collected", flush=True)

    emb = Embedder(threads=a.threads)
    t0 = time.time()
    vecs, tokens = emb.embed(chunks, "passage: ")
    secs = time.time() - t0
    qtexts = [" ".join(c.split()[:14]) for c in chunks[::max(1, len(chunks) // 300)][:300]]
    qvecs, _ = emb.embed(qtexts, "query: ")
    print(f"embedded {tokens} tokens in {secs:.0f} s = {tokens / secs:.0f} tok/s", flush=True)

    n_files = (len(chunks) + CHUNKS_PER_FILE - 1) // CHUNKS_PER_FILE
    with connect() as con, con.cursor() as cur:
        cur.execute("DROP SCHEMA IF EXISTS bd_recall CASCADE; CREATE SCHEMA bd_recall")
        cur.execute("CREATE TABLE bd_recall.chunk_vec (chunk_id bigint PRIMARY KEY, file_id bigint NOT NULL, vec halfvec(384) NOT NULL)")
        cur.execute("CREATE TABLE bd_recall.file_acl (file_id bigint PRIMARY KEY, principals text[] NOT NULL, acl_ver int NOT NULL DEFAULT 1)")
        cur.execute("CREATE TABLE bd_recall.query_vec (qid int PRIMARY KEY, vec halfvec(384) NOT NULL)")
        with cur.copy("COPY bd_recall.file_acl (file_id, principals) FROM STDIN") as cp:
            for fid, pr in file_acl_rows(1, n_files):
                cp.write(f"{fid}\t{pr}\n".encode())
        with cur.copy("COPY bd_recall.chunk_vec (chunk_id, file_id, vec) FROM STDIN") as cp:
            for i, v in enumerate(vecs):
                cp.write(f"{i + 1}\t{i // CHUNKS_PER_FILE + 1}\t[{','.join(f'{x:.6f}' for x in v)}]\n".encode())
        with cur.copy("COPY bd_recall.query_vec (qid, vec) FROM STDIN") as cp:
            for i, v in enumerate(qvecs):
                cp.write(f"{i + 1}\t[{','.join(f'{x:.6f}' for x in v)}]\n".encode())
        t = time.time()
        cur.execute("CREATE INDEX ON bd_recall.chunk_vec USING hnsw (vec halfvec_cosine_ops) WITH (m = 16, ef_construction = 64)")
        cur.execute("CREATE INDEX ON bd_recall.chunk_vec (file_id)")
        cur.execute("ANALYZE bd_recall.chunk_vec")
        hnsw_s = time.time() - t
    write_result("m4_embed_recall_corpus", {"chunks": len(chunks), "tokens": tokens, "seconds": round(secs, 1), "tokens_per_s": round(tokens / secs, 1),
                                            "threads": a.threads, "batch": 32, "model": "Xenova/multilingual-e5-small int8 (dynamic)",
                                            "model_sha256": sha256(emb.paths["model"]), "hnsw_seconds": round(hnsw_s, 1)})


if __name__ == "__main__":
    main()
