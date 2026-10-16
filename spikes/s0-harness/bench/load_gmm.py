"""Schema bd_gmm: 3M clustered halfvec(384) + the same file_acl as bd_spike + 200 query vectors, HNSW built after load.
Used only by m8 (recall needs neighbour structure). python3 bench/load_gmm.py"""
import multiprocessing as mp
import struct
import sys
import time

import numpy as np

sys.path.insert(0, "/work/corpus")
sys.path.insert(0, "/work/bench")
from gen_vectors import BATCH, COPY_SIG, DIM  # noqa: E402
from gen_vectors_gmm import batch_vectors, centres, file_clusters, query_vectors  # noqa: E402
from lib import connect, write_result  # noqa: E402

SEED, N, FILES = 1, 3_000_000, 50_000
ROW = np.dtype([("nf", ">i2"), ("l1", ">i4"), ("cid", ">i8"), ("l2", ">i4"), ("fid", ">i8"),
                ("l3", ">i4"), ("dim", ">i2"), ("unused", ">i2"), ("vec", ">f2", (DIM,))])
_ctr = _fcl = None


def load_batch(idx):
    global _ctr, _fcl
    if _ctr is None:
        _ctr, _fcl = centres(SEED), file_clusters(SEED, FILES)
    ids, fid, v = batch_vectors(SEED, idx, N, _ctr, _fcl)
    a = np.zeros(len(ids), dtype=ROW)
    a["nf"], a["l1"], a["l2"], a["l3"], a["dim"] = 3, 8, 8, 4 + 2 * DIM, DIM
    a["cid"], a["fid"], a["vec"] = ids, fid, v.astype(np.float16)
    with connect() as con, con.cursor() as cur:
        with cur.copy("COPY bd_gmm.chunk_vec (chunk_id, file_id, vec) FROM STDIN (FORMAT BINARY)") as cp:
            cp.write(COPY_SIG + a.tobytes() + struct.pack(">h", -1))
    return idx


if __name__ == "__main__":
    t0 = time.time()
    with connect() as con, con.cursor() as cur:
        cur.execute("DROP SCHEMA IF EXISTS bd_gmm CASCADE; CREATE SCHEMA bd_gmm")
        cur.execute("CREATE TABLE bd_gmm.chunk_vec (chunk_id bigint NOT NULL, file_id bigint NOT NULL, vec halfvec(384) NOT NULL)")
        cur.execute("CREATE TABLE bd_gmm.file_acl AS SELECT * FROM bd_spike.file_acl")
        cur.execute("CREATE TABLE bd_gmm.query_vec (qid int PRIMARY KEY, vec halfvec(384) NOT NULL)")
        q = query_vectors(SEED, 200, centres(SEED))
        with cur.copy("COPY bd_gmm.query_vec (qid, vec) FROM STDIN") as cp:
            for i, v in enumerate(q):
                cp.write(f"{i + 1}\t[{','.join(f'{x:.6f}' for x in v)}]\n".encode())
        with mp.Pool(4) as pool:
            for i, _ in enumerate(pool.imap_unordered(load_batch, range((N + BATCH - 1) // BATCH))):
                if i % 25 == 0:
                    print(f"loaded {i + 1} batches, {time.time() - t0:.0f} s", flush=True)
        load_s = time.time() - t0
        cur.execute("SET maintenance_work_mem = '8GB'")
        cur.execute("SET max_parallel_maintenance_workers = 6")
        t = time.time()
        cur.execute("ALTER TABLE bd_gmm.chunk_vec ADD PRIMARY KEY (chunk_id)")
        cur.execute("CREATE INDEX ON bd_gmm.chunk_vec (file_id)")
        cur.execute("ALTER TABLE bd_gmm.file_acl ADD PRIMARY KEY (file_id)")
        cur.execute("CREATE INDEX ON bd_gmm.file_acl USING gin (principals)")
        cur.execute("CREATE INDEX chunk_vec_hnsw ON bd_gmm.chunk_vec USING hnsw (vec halfvec_cosine_ops) WITH (m = 16, ef_construction = 64)")
        cur.execute("VACUUM (ANALYZE) bd_gmm.chunk_vec")
        cur.execute("ANALYZE bd_gmm.file_acl")
        write_result("m8_gmm_load", {"chunks": N, "load_seconds": round(load_s, 1), "index_seconds": round(time.time() - t, 1)})
    print("done")
