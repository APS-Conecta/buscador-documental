"""Bulk-load the synthetic chunk stream, then build indexes (timed). m5 and m11 inputs.

  python3 bench/load.py --chunks 100000 --tag dry      # dry run
  python3 bench/load.py --chunks 3000000 --tag full    # 1M-page equivalent
Writes results/m5_<tag>.json (load + index build times, WAL bytes) and m11_<tag>.json (sizes).
Free disk is checked first; the load aborts cleanly if there is less than 4x the estimated footprint.
"""
import argparse
import multiprocessing as mp
import os
import shutil
import struct
import sys
import time

sys.path.insert(0, "/work/corpus")
sys.path.insert(0, "/work/bench")
from gen_vectors import BATCH, CHUNKS_PER_FILE, COPY_SIG, file_acl_rows, text_copy_bytes, vec_copy_bytes  # noqa: E402
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
        with cur.copy("COPY bd_spike.chunk_vec (chunk_id, file_id, vec) FROM STDIN (FORMAT BINARY)") as cp:
            cp.write(COPY_SIG + vec_copy_bytes(SEED, idx, n_chunks) + struct.pack(">h", -1))
        con.commit()
    return idx


def timed(cur, label, sql, out):
    t = time.time()
    cur.execute(sql)
    out[label] = {"seconds": round(time.time() - t, 1)}
    print(f"{label}: {out[label]['seconds']} s", flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--chunks", type=int, required=True)
    ap.add_argument("--tag", required=True)
    ap.add_argument("--procs", type=int, default=4)
    ap.add_argument("--mwm", default="8GB", help="maintenance_work_mem for the index builds")
    a = ap.parse_args()

    est = a.chunks * 5_000                       # ~5 kB/chunk all-in incl. indexes (to be replaced by m11)
    free = shutil.disk_usage("/work").free
    if free < 4 * est:
        sys.exit(f"abort: free disk {free / 1e9:.0f} GB < 4x estimated {est / 1e9:.0f} GB")

    with connect() as con, con.cursor() as cur:
        cur.execute(open("/work/db/schema.sql").read())
        cur.execute("SELECT pg_current_wal_lsn()")
        lsn0 = cur.fetchone()[0]
        t0 = time.time()
        n_files = (a.chunks + CHUNKS_PER_FILE - 1) // CHUNKS_PER_FILE
        with cur.copy("COPY bd_spike.file_acl (file_id, principals) FROM STDIN") as cp:
            for fid, pr in file_acl_rows(SEED, n_files):
                cp.write(f"{fid}\t{pr}\n".encode())
        n_batches = (a.chunks + BATCH - 1) // BATCH
        with mp.Pool(a.procs) as pool:
            for i, _ in enumerate(pool.imap_unordered(load_batch, [(b, a.chunks) for b in range(n_batches)])):
                if i % 10 == 0:
                    print(f"loaded {i + 1}/{n_batches} batches, {time.time() - t0:.0f} s", flush=True)
        load_s = round(time.time() - t0, 1)

        res = {"chunks": a.chunks, "files": n_files, "load_seconds": load_s, "load_procs": a.procs,
               "maintenance_work_mem": a.mwm, "indexes": {}}
        cur.execute(f"SET maintenance_work_mem = '{a.mwm}'")
        cur.execute("SET max_parallel_maintenance_workers = 6")
        for label, sql in [
            ("chunk_pkey", "ALTER TABLE bd_spike.chunk ADD PRIMARY KEY (chunk_id)"),
            ("chunk_file_idx", "CREATE INDEX chunk_file_idx ON bd_spike.chunk (file_id)"),
            ("chunk_tsv_gin", "CREATE INDEX chunk_tsv_gin ON bd_spike.chunk USING gin (tsv)"),
            ("chunk_vec_pkey", "ALTER TABLE bd_spike.chunk_vec ADD PRIMARY KEY (chunk_id)"),
            ("chunk_vec_file_idx", "CREATE INDEX chunk_vec_file_idx ON bd_spike.chunk_vec (file_id)"),
            ("file_acl_pkey", "ALTER TABLE bd_spike.file_acl ADD PRIMARY KEY (file_id)"),
            ("file_acl_gin", "CREATE INDEX file_acl_gin ON bd_spike.file_acl USING gin (principals)"),
            ("chunk_vec_hnsw", "CREATE INDEX chunk_vec_hnsw ON bd_spike.chunk_vec USING hnsw (vec halfvec_cosine_ops) WITH (m = 16, ef_construction = 64)"),
        ]:
            timed(cur, label, sql, res["indexes"])
        t = time.time()
        cur.execute("VACUUM (ANALYZE) bd_spike.chunk")
        cur.execute("VACUUM (ANALYZE) bd_spike.chunk_vec")
        cur.execute("VACUUM (ANALYZE) bd_spike.file_acl")
        res["vacuum_analyze_seconds"] = round(time.time() - t, 1)
        cur.execute("SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), %s)", (lsn0,))
        res["wal_bytes"] = int(cur.fetchone()[0])
        res["index_build_minutes_per_100k_chunks"] = round(
            sum(v["seconds"] for v in res["indexes"].values()) / 60 / (a.chunks / 100_000), 3)
        write_result(f"m5_{a.tag}", res)

        sizes = {}
        for rel in ["chunk", "chunk_vec", "file_acl"]:
            cur.execute("SELECT pg_table_size(%s), pg_indexes_size(%s)", (f"bd_spike.{rel}", f"bd_spike.{rel}"))
            sizes[rel] = dict(zip(("table_bytes", "indexes_bytes"), cur.fetchone()))
        cur.execute("SELECT indexrelid::regclass::text, pg_relation_size(indexrelid) FROM pg_index "
                    "WHERE indrelid::regclass::text LIKE 'bd_spike.%'")
        sizes["per_index_bytes"] = dict(cur.fetchall())
        cur.execute("SELECT avg(pg_column_size(tsv)), avg(pg_column_size(text)) FROM (SELECT tsv, text FROM bd_spike.chunk TABLESAMPLE SYSTEM (1)) s")
        avg_tsv, avg_text = cur.fetchone()
        sizes["avg_tsv_bytes"], sizes["avg_text_bytes"] = float(avg_tsv), float(avg_text)
        cur.execute("SELECT pg_database_size(current_database())")
        sizes["database_bytes"] = cur.fetchone()[0]
        sizes["bytes_per_chunk_total"] = round(sum(sizes[r]["table_bytes"] + sizes[r]["indexes_bytes"]
                                                   for r in ("chunk", "chunk_vec", "file_acl")) / a.chunks, 1)
        write_result(f"m11_{a.tag}", {"chunks": a.chunks, **sizes})
    print("done", res["indexes"])


if __name__ == "__main__":
    main()
