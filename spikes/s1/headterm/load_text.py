"""Text-only copy of S0's bench/load.py: the 3M-chunk synthetic text stream and the file_acl rows, GIN indexes, no vectors.
The head-term query touches chunk.tsv and file_acl only, so skipping chunk_vec/HNSW changes nothing for it.
Same generator, seed and batch layout as S0 => the same rows as the PostgreSQL 17.11 run.
Usage (inside the py service): python3 /s1/load_text.py --chunks 3000000
"""
import argparse
import multiprocessing as mp
import sys
import time

sys.path.insert(0, "/work/corpus")
sys.path.insert(0, "/work/bench")
from gen_vectors import BATCH, CHUNKS_PER_FILE, file_acl_rows, text_copy_bytes  # noqa: E402
from lib import connect, write_result  # noqa: E402
from zipf import TextModel  # noqa: E402

SEED = 1
_model = None


def load_batch(args):
    idx, n_chunks = args
    global _model
    if _model is None:
        _model = TextModel(SEED)
    with connect(autocommit=False) as con, con.cursor() as cur:
        with cur.copy("COPY bd_spike.chunk (chunk_id, file_id, page, text) FROM STDIN") as cp:
            cp.write(text_copy_bytes(_model, SEED, idx, n_chunks))
        con.commit()
    return idx


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--chunks", type=int, required=True)
    ap.add_argument("--procs", type=int, default=4)
    a = ap.parse_args()
    t0 = time.time()
    with connect() as con, con.cursor() as cur:
        cur.execute(open("/s1/schema_text.sql").read())
        n_files = (a.chunks + CHUNKS_PER_FILE - 1) // CHUNKS_PER_FILE
        with cur.copy("COPY bd_spike.file_acl (file_id, principals) FROM STDIN") as cp:
            for fid, pr in file_acl_rows(SEED, n_files):
                cp.write(f"{fid}\t{pr}\n".encode())
        n_batches = (a.chunks + BATCH - 1) // BATCH
        with mp.Pool(a.procs) as pool:
            for i, _ in enumerate(pool.imap_unordered(load_batch, [(b, a.chunks) for b in range(n_batches)])):
                if i % 20 == 0:
                    print(f"loaded {i + 1}/{n_batches} batches, {time.time() - t0:.0f} s", flush=True)
        load_s = round(time.time() - t0, 1)
        idx = {}
        cur.execute("SET maintenance_work_mem = '2GB'")
        for label, sql in [
            ("chunk_pkey", "ALTER TABLE bd_spike.chunk ADD PRIMARY KEY (chunk_id)"),
            ("chunk_file_idx", "CREATE INDEX chunk_file_idx ON bd_spike.chunk (file_id)"),
            ("chunk_tsv_gin", "CREATE INDEX chunk_tsv_gin ON bd_spike.chunk USING gin (tsv)"),
            ("file_acl_pkey", "ALTER TABLE bd_spike.file_acl ADD PRIMARY KEY (file_id)"),
            ("file_acl_gin", "CREATE INDEX file_acl_gin ON bd_spike.file_acl USING gin (principals)"),
        ]:
            t = time.time()
            cur.execute(sql)
            idx[label] = round(time.time() - t, 1)
            print(label, idx[label], "s", flush=True)
        cur.execute("VACUUM (ANALYZE) bd_spike.chunk")
        cur.execute("VACUUM (ANALYZE) bd_spike.file_acl")
        cur.execute("SELECT version()")
        write_result("load_text_pg18", {"chunks": a.chunks, "load_seconds": load_s, "index_seconds": idx, "server": cur.fetchone()[0]})


if __name__ == "__main__":
    main()
