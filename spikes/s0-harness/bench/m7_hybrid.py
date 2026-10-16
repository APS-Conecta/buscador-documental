"""m7: hybrid (Mixta) latency: FTS top-50 + HNSW top-50 under the ACL prefilter, fused with RRF (k=60) in SQL.
The vector leg was EXPLAINed for both users and both modes (HNSW + nested loop to file_acl). The hybrid page-100 config fuses two
50-row legs, so it returns at most 100 chunks (fewer files): its headline cost is understated.
Compares hnsw.iterative_scan = strict_order vs relaxed_order (+ MATERIALIZED re-sort, pgvector README) so
01-architecture/02-performance can be corrected from data. Query vectors are stored chunk vectors (latency
only; the embedding cost is m4). Run after m6.
"""
import argparse
import sys

sys.path.insert(0, "/work/bench")
from lib import connect  # noqa: E402
from lib_load import load_gold, run_config, save  # noqa: E402

_vecs: dict[int, str] = {}

FTS_LEG = """
 SELECT c.chunk_id, row_number() OVER (ORDER BY ts_rank(c.tsv, q.tq) DESC) AS rk
 FROM (SELECT c.chunk_id, c.tsv FROM bd_spike.chunk c JOIN bd_spike.file_acl a USING (file_id)
       WHERE c.tsv @@ websearch_to_tsquery('public.spanish_unaccent', %(q)s) AND a.principals && %(p)s::text[]
       ORDER BY c.chunk_id DESC LIMIT 10000) c,   -- head-term pre-limit (m10); the uncapped leg is m6's failing baseline
      (SELECT websearch_to_tsquery('public.spanish_unaccent', %(q)s) AS tq) q
 ORDER BY ts_rank(c.tsv, q.tq) DESC LIMIT 50"""
VEC_STRICT = """
 SELECT v.chunk_id, row_number() OVER (ORDER BY v.vec <=> %(v)s::halfvec) AS rk
 FROM bd_spike.chunk_vec v JOIN bd_spike.file_acl a USING (file_id)
 WHERE a.principals && %(p)s::text[]
 ORDER BY v.vec <=> %(v)s::halfvec LIMIT 50"""
VEC_RELAXED = """
 SELECT chunk_id, row_number() OVER (ORDER BY d + 0) AS rk FROM (
   SELECT v.chunk_id, v.vec <=> %(v)s::halfvec AS d
   FROM bd_spike.chunk_vec v JOIN bd_spike.file_acl a USING (file_id)
   WHERE a.principals && %(p)s::text[]
   ORDER BY d LIMIT 50) relaxed_results"""
FUSE = """
WITH fts AS MATERIALIZED ({fts}), vec AS MATERIALIZED ({vec}),
fused AS (SELECT chunk_id, sum(1.0 / (60 + rk)) AS s FROM (SELECT * FROM fts UNION ALL SELECT * FROM vec) u
          GROUP BY chunk_id ORDER BY s DESC LIMIT %(lim)s)
SELECT f.chunk_id, c.file_id, f.s FROM fused f JOIN bd_spike.chunk c USING (chunk_id) ORDER BY f.s DESC"""
HEAD = """
SELECT ts_headline('public.spanish_unaccent', text, websearch_to_tsquery('public.spanish_unaccent', %(q)s),
                   'MaxFragments=1, MaxWords=30, MinWords=15')
FROM bd_spike.chunk WHERE chunk_id = ANY(%(ids)s)"""


def qvec(con, qid):
    if qid not in _vecs:
        n = con.execute("SELECT count(*) FROM bd_spike.chunk_vec").fetchone()[0]
        cid = 1 + (qid * 7919) % n
        _vecs[qid] = con.execute("SELECT vec::text FROM bd_spike.chunk_vec WHERE chunk_id = %s", (cid,)).fetchone()[0]
    return _vecs[qid]


def make(mode):
    sql = FUSE.format(fts=FTS_LEG, vec=VEC_STRICT if mode == "strict_order" else VEC_RELAXED)

    def hybrid(con, q, principals, page):
        v = qvec(con, q["id"])
        with con.transaction():
            con.execute(f"SET LOCAL hnsw.iterative_scan = {mode}")
            con.execute("SET LOCAL hnsw.ef_search = 100")
            con.execute("SET LOCAL hnsw.max_scan_tuples = 20000")
            rows = con.execute(sql, {"q": q["q"], "p": principals, "v": v, "lim": page * 3}).fetchall()
        best = {}
        for chunk_id, file_id, _ in rows:
            best.setdefault(file_id, chunk_id)
        ids = list(best.values())[:page]
        if ids:
            con.execute(HEAD, {"q": q["q"], "ids": ids}).fetchall()
        return len(ids)
    return hybrid


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--repeats", type=int, default=3)
    a = ap.parse_args()
    queries = load_gold()
    with connect() as con:
        for q in queries:
            qvec(con, q["id"])
    configs = []
    for mode in ("strict_order", "relaxed_order"):
        for conc, page in [(1, 25), (5, 25), (10, 25), (20, 25), (5, 100)]:
            cfg = run_config(f"hybrid_{mode}", make(mode), queries, conc, page, a.repeats, warmup=True)
            cfg["iterative_scan"] = mode
            configs.append(cfg)
            save("m7", configs)


if __name__ == "__main__":
    main()
