"""m10: head-term mitigation (run only when m6 head-term p95 misses the keyword budget).
Compares the m6 baseline (rank every match, then top-K) with a candidate pre-limit (rank only the newest CAP matches
that pass the ACL prefilter). Reports latency and top-10 chunk overlap with the baseline (the quality cost).
"""
import sys
import time

import psycopg

sys.path.insert(0, "/work/bench")
from lib import connect, write_result  # noqa: E402
from lib_load import PRINCIPALS, load_gold, pct  # noqa: E402

BASE = """
SELECT c.chunk_id FROM bd_spike.chunk c JOIN bd_spike.file_acl a USING (file_id),
     (SELECT websearch_to_tsquery('public.spanish_unaccent', %(q)s) AS tq) q
WHERE c.tsv @@ q.tq AND a.principals && %(p)s::text[]
ORDER BY ts_rank(c.tsv, q.tq) DESC LIMIT 50"""
PRELIMIT = """
SELECT chunk_id FROM (
  SELECT c.chunk_id, c.tsv FROM bd_spike.chunk c JOIN bd_spike.file_acl a USING (file_id)
  WHERE c.tsv @@ websearch_to_tsquery('public.spanish_unaccent', %(q)s) AND a.principals && %(p)s::text[]
  ORDER BY c.chunk_id DESC LIMIT %(cap)s) cand
ORDER BY ts_rank(cand.tsv, websearch_to_tsquery('public.spanish_unaccent', %(q)s)) DESC LIMIT 50"""


def timed(con, sql, params):
    t = time.perf_counter()
    try:
        rows = [r[0] for r in con.execute(sql, params).fetchall()]
    except psycopg.errors.QueryCanceled:                      # statement_timeout: keep the sample as censored at the timeout
        rows = []
    return rows, (time.perf_counter() - t) * 1000


def realistic_terms(con, n=24):
    """Terms matching 10-30 % of chunks (closer to real Spanish content-word document frequency than the
    generator's Zipf head, which reaches 30-99.9 % of chunks); df estimated on a 1 % sample, deterministic order."""
    rows = con.execute("SELECT word, ndoc FROM ts_stat('SELECT tsv FROM bd_spike.chunk TABLESAMPLE SYSTEM (1) REPEATABLE (1)') "
                       "ORDER BY word").fetchall()
    total = con.execute("SELECT count(*) FROM bd_spike.chunk TABLESAMPLE SYSTEM (1) REPEATABLE (1)").fetchone()[0]
    return [w for w, nd in rows if 0.10 <= nd / total <= 0.30 and len(w) >= 5][:n]


def main():
    stress = [q for q in load_gold() if q["type"] == "head"]
    out = []
    with connect() as con:
        con.execute("SET statement_timeout = 120000")
        real = [{"q": t, "user": "u_admin200" if i % 2 else "u_narrow"} for i, t in enumerate(realistic_terms(con))]
        for cls, queries in (("stress_head", stress), ("realistic_head", real)):
            if not queries:
                print("skip", cls, "(no queries)")
                continue
            base = {}
            for q in queries:
                p = {"q": q["q"], "p": PRINCIPALS[q["user"]], "cap": None}
                timed(con, BASE, p)                                    # first run warms the cache
                base[q["q"], q["user"]] = timed(con, BASE, p)
            for variant, sql, cap in [("baseline", BASE, None), ("prelimit_2000", PRELIMIT, 2000), ("prelimit_10000", PRELIMIT, 10000)]:
                lat, overlap, by = [], [], {"u_narrow": [], "u_admin200": []}
                for q in queries:
                    p = {"q": q["q"], "p": PRINCIPALS[q["user"]], "cap": cap}
                    rows, ms = base[q["q"], q["user"]] if variant == "baseline" else (timed(con, sql, p), timed(con, sql, p))[1]
                    lat.append(ms)
                    by[q["user"]].append(ms)
                    overlap.append(len(set(rows[:10]) & set(base[q["q"], q["user"]][0][:10])) / 10)
                out.append({"class": cls, "variant": variant, "n": len(lat), "p50_ms": round(pct(lat, .5), 1), "p95_ms": round(pct(lat, .95), 1),
                            "p95_narrow_ms": round(pct(by["u_narrow"], .95), 1) if by["u_narrow"] else None,
                            "p95_admin_ms": round(pct(by["u_admin200"], .95), 1) if by["u_admin200"] else None,
                            "top10_overlap_with_baseline": round(sum(overlap) / len(overlap), 3)})
                print(out[-1], flush=True)
                write_result("m10", {"variants": out})
    write_result("m10", {"variants": out})


if __name__ == "__main__":
    main()
