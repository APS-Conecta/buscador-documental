"""Fill the real product schema (aps_search) with a synthetic 1M-page index, then build its indexes.

  python3 load.py --files 50000          # 50k files x 20 pages = 1M pages, 3M chunks (the 1M-page equivalent of A-PERF-1)
  python3 load.py --files 2000 --tag dry # quick check

Runs in the worker image (python, numpy, psycopg) on the bd-s7 network. The schema comes from the product's own migration files,
applied as the Nextcloud database user exactly like the app does; data goes in by COPY with the secondary indexes dropped and rebuilt.
"""
import argparse
import json
import multiprocessing as mp
import os
import re
import struct
import sys
import time

import numpy as np
import psycopg

sys.path.insert(0, "/s0/corpus")
sys.path.insert(0, "/s7")
from vec import DIM, N_FOLDERS, NARROW_EVERY, centres, file_cluster, principals_for, vectors  # noqa: E402
from zipf import TextModel  # noqa: E402

SEED = 1
PAGES, CHUNKS_PER_PAGE = 20, 3
FILES_PER_BATCH = 250
HOST = os.environ.get("S7_DB", "db")
SQL = "/sql"
SIG = b"PGCOPY\n\xff\r\n\x00" + struct.pack(">ii", 0, 0)
ROW = np.dtype([("nf", ">i2"), ("l1", ">i4"), ("cid", ">i8"), ("l2", ">i4"), ("fid", ">i8"), ("l3", ">i4"), ("dim", ">i2"), ("unused", ">i2"), ("vec", ">f2", (DIM,))])
_model = _ctr = _fcl = None


def connect(user="nextcloud", pw="dev", owner=True):
    """The Nextcloud DB user acts as bd_owner (it may SET ROLE to it), the way the migration does."""
    con = psycopg.connect(f"host={HOST} dbname=nextcloud user={user} password={pw}", autocommit=True)
    if owner and user == "nextcloud":
        con.execute("SET ROLE bd_owner")
    return con


def statements(path):
    parts = re.split(r"^-- @@[ \t]*$", open(path, encoding="utf-8").read(), flags=re.M)
    return [p.strip() for p in parts if p.strip() and any(l.strip() and not l.strip().startswith("--") for l in p.splitlines())]


def batch(args):
    """One batch = FILES_PER_BATCH whole files; ids are pure functions of the file number, so any process can build any batch."""
    global _model, _ctr, _fcl
    b, n_files = args
    if _model is None:
        _model, _ctr, _fcl = TextModel(SEED), centres(SEED), file_cluster(SEED, n_files)
    first = b * FILES_PER_BATCH
    files = range(first + 1, min(n_files, first + FILES_PER_BATCH) + 1)
    rng = np.random.default_rng([SEED, 21, b])
    folder = np.random.default_rng([SEED, 7]).integers(2, N_FOLDERS + 1, n_files)
    n = len(files)
    words = _model.words(rng, n * PAGES * CHUNKS_PER_PAGE * 150)
    pos = 0
    pages, chunks, acl, meta, state = [], [], [], [], []
    cid = (first) * PAGES * CHUNKS_PER_PAGE
    cids, fids = [], []
    for fid in files:
        f = 1 if fid % NARROW_EVERY == 1 else int(folder[fid - 1])
        acl.append(f'{fid}\t{{{",".join(principals_for(f))}}}\n')
        meta.append(f"{fid}\tdocumento-{fid}.pdf\tapplication/pdf\t{1700000000 + fid * 60}\t{{1,{100 + f},{fid // 1000 + 500}}}\tuser{fid % 50}\n")
        state.append(f"{fid}\te{fid}\tindexed\n")
        for pg in range(1, PAGES + 1):
            ptxt = []
            for ordn in range(CHUNKS_PER_PAGE):
                cid += 1
                k = int(rng.integers(100, 201))
                text = " ".join(words[pos:pos + k])
                pos += k
                ptxt.append(text)
                chunks.append(f"{cid}\t{fid}\t{pg}\t{ordn}\t{text}\n")
                cids.append(cid)
                fids.append(fid)
            pages.append(f"{fid}\t{pg}\t{' '.join(ptxt)}\t92\tlayer\n")
    v = vectors(SEED, b, _fcl[np.array(fids) - 1], _ctr)
    a = np.zeros(len(cids), dtype=ROW)
    a["nf"], a["l1"], a["l2"], a["l3"], a["dim"] = 3, 8, 8, 4 + 2 * DIM, DIM
    a["cid"], a["fid"], a["vec"] = cids, fids, v.astype(np.float16)
    with connect() as con, con.cursor() as cur:
        for table, cols, rows in [("file_state", "(file_id, etag, text_state)", state), ("file_meta", "(file_id, name, mime, mtime, ancestors, owner)", meta),
                                  ("file_acl", "(file_id, principals)", acl), ("page", "(file_id, page_no, text, ocr_conf, source)", pages),
                                  ("chunk", "(chunk_id, file_id, page_no, ord, text)", chunks)]:
            with cur.copy(f"COPY aps_search.{table} {cols} FROM STDIN") as cp:
                cp.write("".join(rows).encode())
        with cur.copy("COPY aps_search.chunk_vec_1 (chunk_id, file_id, vec) FROM STDIN (FORMAT BINARY)") as cp:
            cp.write(SIG + a.tobytes() + struct.pack(">h", -1))
    return b


def timed(cur, out, label, sql):
    t = time.time()
    cur.execute(sql)
    out[label] = round(time.time() - t, 1)
    print(f"{label}: {out[label]} s", flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--files", type=int, required=True)
    ap.add_argument("--procs", type=int, default=5)
    ap.add_argument("--tag", default="full")
    a = ap.parse_args()
    t0 = time.time()
    res = {"files": a.files, "pages": a.files * PAGES, "chunks": a.files * PAGES * CHUNKS_PER_PAGE, "dim": DIM, "seconds": {}}
    with connect("postgres", "dev") as su, su.cursor() as cur:
        cur.execute("DROP SCHEMA IF EXISTS aps_search CASCADE")
    with connect(owner=False) as con, con.cursor() as cur:
        for name in ["000_preflight.sql", "001_schema.sql", "002_functions.sql", "003_roles.sql", "004_s3.sql", "005_s4.sql", "006_s5.sql", "007_s6.sql"]:
            for st in statements(f"{SQL}/{name}"):
                cur.execute(st)
        cur.execute("SELECT aps_search.create_generation(1, 'bge-m3', 'bge-m3:567m', 'sha256:s7', %s, 'f16', '', '', 8192)", (DIM,))
        cur.execute("SET ROLE bd_owner")
        # bulk load without the secondary indexes and without the foreign key; they are rebuilt below, timed
        for idx in ["chunk_tsv_idx", "chunk_file_id_idx", "file_acl_principals_idx", "file_meta_ancestors_idx", "file_meta_owner_idx", "chunk_vec_1_file_idx"]:
            cur.execute(f"DROP INDEX IF EXISTS aps_search.{idx}")
        cur.execute("ALTER TABLE aps_search.chunk_vec_1 DROP CONSTRAINT IF EXISTS chunk_vec_1_chunk_id_fkey")
    n_batches = (a.files + FILES_PER_BATCH - 1) // FILES_PER_BATCH
    with mp.Pool(a.procs) as pool:
        for i, _ in enumerate(pool.imap_unordered(batch, [(b, a.files) for b in range(n_batches)])):
            if i % 20 == 0:
                print(f"loaded {i + 1}/{n_batches} batches, {time.time() - t0:.0f} s", flush=True)
    res["seconds"]["load"] = round(time.time() - t0, 1)
    with connect() as con, con.cursor() as cur:
        cur.execute("SELECT setval(pg_get_serial_sequence('aps_search.chunk', 'chunk_id'), max(chunk_id)) FROM aps_search.chunk")
        for label, sql in [
            ("chunk_tsv_idx", "CREATE INDEX chunk_tsv_idx ON aps_search.chunk USING gin (tsv)"),
            ("chunk_file_id_idx", "CREATE INDEX chunk_file_id_idx ON aps_search.chunk (file_id)"),
            ("file_acl_principals_idx", "CREATE INDEX file_acl_principals_idx ON aps_search.file_acl USING gin (principals)"),
            ("file_meta_ancestors_idx", "CREATE INDEX file_meta_ancestors_idx ON aps_search.file_meta USING gin (ancestors)"),
            ("file_meta_owner_idx", "CREATE INDEX file_meta_owner_idx ON aps_search.file_meta (owner)"),
            ("chunk_vec_1_file_idx", "CREATE INDEX chunk_vec_1_file_idx ON aps_search.chunk_vec_1 (file_id)"),
            ("chunk_vec_1_fk", "ALTER TABLE aps_search.chunk_vec_1 ADD CONSTRAINT chunk_vec_1_chunk_id_fkey FOREIGN KEY (chunk_id) REFERENCES aps_search.chunk (chunk_id) ON DELETE CASCADE NOT VALID"),
            ("hnsw", "SELECT aps_search.build_vec_index(1)"),
            ("analyze", "ANALYZE aps_search.chunk; ANALYZE aps_search.chunk_vec_1; ANALYZE aps_search.file_acl; ANALYZE aps_search.file_meta; ANALYZE aps_search.page; ANALYZE aps_search.file_state"),
        ]:
            timed(cur, res["seconds"], label, sql)
        cur.execute("SELECT aps_search.mark_generation_ready(1)")
        cur.execute("SELECT aps_search.activate_generation(1)")
        for k, v in [("semantic_enabled", "1"), ("vec_coverage", "1"), ("vec_coverage_1", "1")]:
            cur.execute("INSERT INTO aps_search.settings (key, value) VALUES (%s, %s) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", (k, v))
        cur.execute("SELECT pg_database_size(current_database())")
        res["database_bytes"] = cur.fetchone()[0]
        cur.execute("SELECT relname, pg_total_relation_size(c.oid) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'aps_search' AND relkind IN ('r', 'i') ORDER BY 2 DESC LIMIT 12")
        res["largest_relations_bytes"] = dict(cur.fetchall())
    res["seconds"]["total"] = round(time.time() - t0, 1)
    os.makedirs("/s7/results-s7", exist_ok=True)
    json.dump(res, open(f"/s7/results-s7/load_{a.tag}.json", "w"), indent=2)
    print(json.dumps(res, indent=2))


if __name__ == "__main__":
    main()
