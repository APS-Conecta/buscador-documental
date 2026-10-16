"""m6: keyword (Exacta) latency at the 3M-chunk scale. Per request = FTS top-K chunks under the ACL prefilter,
best chunk per file, then ts_headline for the final page only (the budget rows 'SQL' and 'snippets').

  python3 bench/m6_keyword.py            # warm matrix: conc 1/5/10/20 at page 25, page 50/100 at conc 5
  python3 bench/m6_keyword.py --cold N   # one cold-ish pass over N queries (run right after a db restart)
Cold means shared_buffers cold only; the OS page cache is left alone on this shared host (stated in the result).
"""
import argparse
import sys
import time

sys.path.insert(0, "/work/bench")
from lib import connect  # noqa: E402
from lib_load import load_gold, run_config, run_once, save, summarize  # noqa: E402

TOP = """
SELECT c.chunk_id, c.file_id, ts_rank(c.tsv, q.tq) AS r
FROM bd_spike.chunk c
JOIN bd_spike.file_acl a USING (file_id),
     (SELECT websearch_to_tsquery('public.spanish_unaccent', %(q)s) AS tq) q
WHERE c.tsv @@ q.tq AND a.principals && %(p)s::text[]
ORDER BY r DESC
LIMIT %(lim)s
"""
# Mitigation under test (m10): rank only the newest CAP ACL-passing matches.
TOP_CAPPED = """
SELECT cand.chunk_id, cand.file_id, ts_rank(cand.tsv, websearch_to_tsquery('public.spanish_unaccent', %(q)s)) AS r FROM (
  SELECT c.chunk_id, c.file_id, c.tsv FROM bd_spike.chunk c JOIN bd_spike.file_acl a USING (file_id)
  WHERE c.tsv @@ websearch_to_tsquery('public.spanish_unaccent', %(q)s) AND a.principals && %(p)s::text[]
  ORDER BY c.chunk_id DESC LIMIT %(cap)s) cand
ORDER BY r DESC LIMIT %(lim)s
"""
HEAD = """
SELECT ts_headline('public.spanish_unaccent', text, websearch_to_tsquery('public.spanish_unaccent', %(q)s),
                   'MaxFragments=1, MaxWords=30, MinWords=15')
FROM bd_spike.chunk WHERE chunk_id = ANY(%(ids)s)
"""


CAP = None   # set by --cap


def keyword(con, q, principals, page):
    if CAP:
        rows = con.execute(TOP_CAPPED, {"q": q["q"], "p": principals, "lim": page * 3, "cap": CAP}).fetchall()
    else:
        rows = con.execute(TOP, {"q": q["q"], "p": principals, "lim": page * 3}).fetchall()   # overfetch 3x, group by file
    best = {}
    for chunk_id, file_id, _ in rows:
        best.setdefault(file_id, chunk_id)
    ids = list(best.values())[:page]
    if ids:
        con.execute(HEAD, {"q": q["q"], "ids": ids}).fetchall()
    return len(ids)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--cold", type=int, default=0)
    ap.add_argument("--repeats", type=int, default=3)
    ap.add_argument("--cap", type=int, default=0, help="candidate pre-limit (head-term mitigation); result goes to m6_capped")
    a = ap.parse_args()
    global CAP
    CAP = a.cap or None
    queries = load_gold()
    if a.cold:
        lat, err, _ = run_once(keyword, queries[:a.cold], 1, 25)
        s = summarize(lat, len(err))
        s["first_ms"] = lat[0]["ms"] if lat else None
        save(f"m6_cold_{int(time.time())}", [{"name": "keyword", "state": "cold(shared_buffers only)", "conc": 1, "page": 25, "pooled": s}])
        print("cold", s)
        return
    configs = []
    for conc, page in [(1, 25), (5, 25), (10, 25), (20, 25), (5, 50), (5, 100)]:
        configs.append(run_config("keyword", keyword, queries, conc, page, a.repeats, warmup=True))
        save("m6_capped" if CAP else "m6", configs, {"cap": CAP})                                   # incremental: a crash keeps finished configs


if __name__ == "__main__":
    main()
