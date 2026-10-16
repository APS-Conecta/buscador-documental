"""Concurrent load driver shared by m6/m7/m10: N searcher threads replay the gold set, latencies are
collected per request. Results keep per-repeat p95 so run-to-run spread on the shared VPS is visible."""
import json
import statistics
import threading
import time

from lib import connect, write_result

GROUPS_ADMIN = [f"g:g{i:03d}" for i in range(1, 201)]
PRINCIPALS = {
    "u_narrow": ["u:u_narrow", "g:g001"],                 # F1: one group
    "u_admin200": ["u:u_admin200"] + GROUPS_ADMIN,        # F2: 200 groups
}


def load_gold(path="/work/corpus/out/functional/gold.json"):
    return json.load(open(path, encoding="utf-8"))["queries"]


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(round(p * (len(xs) - 1))))] if xs else None


def summarize(lat, errors):
    ms = [x["ms"] for x in lat]
    return {"n": len(ms), "errors": errors, "p50_ms": pct(ms, 0.50), "p95_ms": pct(ms, 0.95), "p99_ms": pct(ms, 0.99),
            "max_ms": max(ms) if ms else None}


def run_once(fn, queries, conc, page, timeout_s=60):
    """One pass: each of `conc` searchers replays all queries (rotated start) on its own connection."""
    lat, errors, lock = [], [], threading.Lock()

    def searcher(k):
        with connect() as con:
            con.execute(f"SET statement_timeout = {timeout_s * 1000}")
            rot = (k * len(queries)) // max(conc, 1)
            for q in queries[rot:] + queries[:rot]:
                t = time.perf_counter()
                try:
                    fn(con, q, PRINCIPALS[q["user"]], page)
                    ms = (time.perf_counter() - t) * 1000
                    with lock:
                        lat.append({"ms": ms, "user": q["user"], "type": q["type"], "id": q["id"]})
                except Exception as e:                      # timeouts are results, not crashes
                    with lock:
                        errors.append({"id": q["id"], "err": type(e).__name__})
    threads = [threading.Thread(target=searcher, args=(k,)) for k in range(conc)]
    t0 = time.perf_counter()
    [t.start() for t in threads]
    [t.join() for t in threads]
    return lat, errors, time.perf_counter() - t0


def run_config(name, fn, queries, conc, page, repeats=3, warmup=True):
    if warmup:
        run_once(fn, queries, 1, page)                       # warm pass, discarded
    reps, all_lat, all_err = [], [], []
    for r in range(repeats):
        lat, err, wall = run_once(fn, queries, conc, page)
        reps.append({**summarize(lat, len(err)), "repeat": r, "wall_s": round(wall, 1)})
        all_lat += lat
        all_err += err
        print(f"{name} conc={conc} page={page} rep={r}: p50={reps[-1]['p50_ms']:.0f} p95={reps[-1]['p95_ms']:.0f} err={len(err)}", flush=True)
    p95s = [x["p95_ms"] for x in reps]
    by = {}
    for key in ("user", "type"):
        for v in sorted({x[key] for x in all_lat}):
            by[f"{key}={v}"] = summarize([x for x in all_lat if x[key] == v], 0)
    return {"name": name, "conc": conc, "page": page, "state": "warm", "repeats": reps,
            "p95_median_ms": statistics.median(p95s), "p95_min_ms": min(p95s), "p95_max_ms": max(p95s),
            "pooled": summarize(all_lat, len(all_err)), "breakdown": by,
            "worst": sorted(all_lat, key=lambda x: -x["ms"])[:10], "errors": all_err[:20]}


def save(name, configs, extra=None):
    return write_result(name, {"configs": configs, **(extra or {})})
