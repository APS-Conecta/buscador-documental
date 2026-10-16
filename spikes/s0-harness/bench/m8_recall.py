"""m8: filtered ANN recall vs exact scan, shortfall rate and the exact-scan switch (A-QUAL-3, indicative).

  python3 bench/m8_recall.py --schema bd_spike  --queries random --n 60    # 3M random vectors (worst case, no cluster structure)
  python3 bench/m8_recall.py --schema bd_recall --queries table  --n 200   # real e5-small embeddings (prep_recall_corpus.py)

For each user view (F1 narrow, F2 admin200, unfiltered) the ground truth is the exact top-10 inside the readable set
(HNSW disabled -> sequential/bitmap scan). ANN configs vary iterative_scan, ef_search and max_scan_tuples. Recall@10 is
computed after re-sorting the ANN rows client-side by distance (relaxed_order is only approximately ordered). Shortfall =
request for LIMIT 50 returned fewer rows. A second section sweeps the number of readable files (file_id = ANY) to find
where exact scan on the btree beats ANN or ANN starts to fall short.
"""
import argparse
import sys
import time

import numpy as np

sys.path.insert(0, "/work/bench")
from lib import connect  # noqa: E402
from lib_load import PRINCIPALS, pct  # noqa: E402
from lib import write_result  # noqa: E402

K, LIMIT = 10, 50
ANN_CONFIGS = [  # (iterative_scan, ef_search, max_scan_tuples)
    ("off", 100, 20000), ("relaxed_order", 100, 5000), ("relaxed_order", 100, 20000), ("relaxed_order", 100, 100000),
    ("relaxed_order", 40, 20000), ("relaxed_order", 200, 20000), ("relaxed_order", 400, 20000), ("strict_order", 100, 20000),
]
# Force the plan under test: an ordered HNSW scan (nested loop to file_acl / filtered on file_id), never the exact
# btree path. enable_sort=off removes "btree + Sort"; the plan is then verified with EXPLAIN (plan_uses_hnsw).
ANN_FORCE = ["SET LOCAL enable_seqscan = off", "SET LOCAL enable_bitmapscan = off", "SET LOCAL enable_sort = off",
             "SET LOCAL enable_hashjoin = off", "SET LOCAL enable_mergejoin = off"]
VIEWS = {"F1_narrow": PRINCIPALS["u_narrow"], "F2_admin200": PRINCIPALS["u_admin200"], "unfiltered": None}


def where(view_p, schema):
    if view_p is None:
        return "", {}
    return f"JOIN {schema}.file_acl a USING (file_id) WHERE a.principals && %(p)s::text[]", {"p": view_p}


def uses_hnsw(con, schema, qv, filt, params, ann):
    hnsw = [r[0] for r in con.execute("SELECT indexname FROM pg_indexes WHERE schemaname = %s AND indexdef ILIKE %s", (schema, "%hnsw%"))]
    sql = (f"EXPLAIN SELECT v.chunk_id FROM {schema}.chunk_vec v {filt} ORDER BY v.vec <=> %(v)s::halfvec LIMIT {LIMIT}")
    with con.transaction():
        for st in ANN_FORCE:
            con.execute(st)
        it, ef, mst = ann
        con.execute(f"SET LOCAL hnsw.iterative_scan = {it}")
        plan = "\n".join(r[0] for r in con.execute(sql, {"v": qv, **params}).fetchall())
    return any(i in plan for i in hnsw)


def knn(con, schema, qv, filt, params, limit, ann, force):
    sql = (f"SELECT v.chunk_id, v.vec <=> %(v)s::halfvec AS d FROM {schema}.chunk_vec v {filt} "
           f"ORDER BY v.vec <=> %(v)s::halfvec LIMIT {limit}")
    with con.transaction():
        for s in force:
            con.execute(s)
        if ann:
            it, ef, mst = ann
            con.execute(f"SET LOCAL hnsw.iterative_scan = {it}")
            con.execute(f"SET LOCAL hnsw.ef_search = {ef}")
            con.execute(f"SET LOCAL hnsw.max_scan_tuples = {mst}")
        t = time.perf_counter()
        rows = con.execute(sql, {"v": qv, **params}).fetchall()
        return rows, (time.perf_counter() - t) * 1000


def mean(xs):
    xs = [x for x in xs if x is not None]
    return round(float(np.mean(xs)), 4) if xs else None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--schema", required=True)
    ap.add_argument("--queries", choices=["random", "table"], required=True)
    ap.add_argument("--n", type=int, default=60)
    a = ap.parse_args()
    sch = a.schema
    # Random vectors have no neighbour structure (distance concentration): recall on them is meaningless, so it is
    # only computed for real embeddings; the random 3M run still reports shortfall and latency.
    recall_valid = a.queries == "table"
    exact_force = ["SET LOCAL enable_indexscan = off", "SET LOCAL max_parallel_workers_per_gather = 4"]
    with connect() as con:
        con.execute("SET statement_timeout = 600000")
        if a.queries == "random":
            rng = np.random.default_rng([1, 99])
            v = rng.standard_normal((a.n, 384)).astype(np.float32)
            v /= np.linalg.norm(v, axis=1, keepdims=True)
            qvs = ["[" + ",".join(f"{x:.6f}" for x in r) + "]" for r in v]
        else:
            qvs = [r[0] for r in con.execute(f"SELECT vec::text FROM {sch}.query_vec ORDER BY qid LIMIT {a.n}").fetchall()]
        out = {"schema": sch, "queries": len(qvs), "k": K, "limit": LIMIT, "recall_valid": recall_valid, "views": {}}
        for vname, vp in VIEWS.items():
            filt, params = where(vp, sch)
            truth = []
            t0 = time.time()
            if recall_valid:
                for qv in qvs:
                    rows, _ = knn(con, sch, qv, filt, params, K, None, exact_force)
                    truth.append({r[0] for r in rows})
            else:
                truth = [set()] * len(qvs)
            res = {"exact_seconds_per_query": round((time.time() - t0) / len(qvs), 2) if recall_valid else None, "configs": []}
            for it, ef, mst in ANN_CONFIGS:
                rec, lat, short50, short10 = [], [], 0, 0
                hnsw_plan = uses_hnsw(con, sch, qvs[0], filt, params, (it, ef, mst))
                for qv, tr in zip(qvs, truth):
                    rows, ms = knn(con, sch, qv, filt, params, LIMIT, (it, ef, mst), ANN_FORCE)
                    top = {r[0] for r in sorted(rows, key=lambda r: r[1])[:K]}
                    rec.append(len(top & tr) / max(len(tr), 1) if recall_valid else None)
                    lat.append(ms)
                    short50 += len(rows) < LIMIT
                    short10 += len(rows) < K
                res["configs"].append({"iterative_scan": it, "ef_search": ef, "max_scan_tuples": mst, "plan_uses_hnsw": hnsw_plan,
                                       "recall_at_10_mean": mean(rec), "recall_at_10_min": round(min(rec), 4) if recall_valid else None,
                                       "shortfall_lt50": round(short50 / len(qvs), 3), "shortfall_lt10": round(short10 / len(qvs), 3),
                                       "p50_ms": round(pct(lat, .5), 1), "p95_ms": round(pct(lat, .95), 1)})
                print(vname, res["configs"][-1], flush=True)
            out["views"][vname] = res
            write_result(f"m8_{sch}", out)

        # Exact-scan switch: vary the number of readable files (file_id = ANY).
        n_files = con.execute(f"SELECT count(*) FROM {sch}.file_acl").fetchone()[0]
        rng = np.random.default_rng([1, 98])
        sweep = []
        for n in [5, 20, 100, 500, 2000, 10000]:   # 50000 files: exact scan takes ~4 min per query, no new information
            if n > n_files:
                continue
            ids = sorted(int(x) + 1 for x in rng.choice(n_files, n, replace=False))
            filt, params = "WHERE v.file_id = ANY(%(ids)s)", {"ids": ids}
            # exact = btree on file_id + sort, HNSW unusable
            exact_lat, ann_lat, ann_rec, short = [], [], [], 0
            hnsw_plan = uses_hnsw(con, sch, qvs[0], filt, params, ("relaxed_order", 100, 20000))
            for qv in qvs[:30]:
                rows, ms = knn(con, sch, qv, filt, params, K, None, ["SET LOCAL enable_indexscan = off", "SET LOCAL enable_seqscan = off"])
                exact_lat.append(ms)
                tr = {r[0] for r in rows}
                rows, ms = knn(con, sch, qv, filt, params, LIMIT, ("relaxed_order", 100, 20000), ANN_FORCE)
                top = {r[0] for r in sorted(rows, key=lambda r: r[1])[:K]}
                ann_rec.append(len(top & tr) / max(len(tr), 1) if recall_valid else None)
                ann_lat.append(ms)
                short += len(rows) < LIMIT
            sweep.append({"readable_files": n, "plan_uses_hnsw": hnsw_plan,
                          "exact_p50_ms": round(pct(exact_lat, .5), 1), "exact_p95_ms": round(pct(exact_lat, .95), 1),
                          "ann_p50_ms": round(pct(ann_lat, .5), 1), "ann_p95_ms": round(pct(ann_lat, .95), 1),
                          "ann_recall_at_10": mean(ann_rec), "ann_shortfall_lt50": round(short / 30, 3)})
            print(sweep[-1], flush=True)
        out["exact_switch_sweep"] = sweep
        print(write_result(f"m8_{sch}", out))


if __name__ == "__main__":
    main()
