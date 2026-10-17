"""Summarize m9_raw.jsonl: p50/p95 per (user, variant), decision agreement with V0, and the winner.

Usage: summarize.py <results dir>. Prints one line per (user, variant) and a final block with
`winner=<variant> p95_check_ms=<max over users>`; the same number goes into buscador/capacity-sheet.md.
Decision rule (design section 5): the winner is the cheapest variant whose per-hit decisions equal V0's for every user
(semantics are never loosened for speed); A-PERF-3 is met when its worst-user check p95 is < 300 ms (setup apart).
"""
import json
import sys
from pathlib import Path

d = Path(sys.argv[1])
rows = [json.loads(line) for line in (d / "m9_raw.jsonl").read_text().splitlines() if line.strip()]
THRESHOLD_MS = 300.0
NORMAL = {"o": "ok", "s": "ok-nosnippet", "d": "drop", "x": "drop", "x?": "drop"}


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(round(p * (len(xs) - 1))))] if xs else None


by = {}
for r in rows:
    by.setdefault((r["uid"], r["variant"]), []).append(r)

# Decision agreement: every run of every variant must decide like the V0 reference for that user, and be stable.
ref = {}
for uid in sorted({u for u, _ in by}):
    ref[uid] = {k: NORMAL[v] for k, v in by[(uid, "V0")][0]["decisions"].items()}
agree = {}
for (uid, v), rs in by.items():
    agree[(uid, v)] = all({k: NORMAL[x] for k, x in r["decisions"].items()} == ref[uid] for r in rs)

summary = {}
for (uid, v), rs in sorted(by.items()):
    s = {"runs": len(rs), "agrees_with_V0": agree[(uid, v)]}
    for m in ("setup_ms", "check_ms", "total_ms"):
        s[m + "_p50"] = round(pct([r[m] for r in rs], 0.5), 1)
        s[m + "_p95"] = round(pct([r[m] for r in rs], 0.95), 1)
    s["outcome"] = {k: rs[0][k] for k in ("ok", "snippet_off", "denied_or_excluded")}
    summary[f"{uid}/{v}"] = s
    print(f"{uid}/{v}", json.dumps(s))

variants = sorted({v for _, v in by})
worst = {}
for v in variants:
    users = [u for u, vv in by if vv == v]
    worst[v] = {
        "p95_check_ms": max(summary[f"{u}/{v}"]["check_ms_p95"] for u in users),
        "p95_setup_ms": max(summary[f"{u}/{v}"]["setup_ms_p95"] for u in users),
        "semantics_ok": all(agree[(u, v)] for u in users),
    }
good = {v: w for v, w in worst.items() if w["semantics_ok"]}
winner = min(good, key=lambda v: good[v]["p95_check_ms"]) if good else None
(d / "m9_summary.json").write_text(json.dumps({"threshold_ms": THRESHOLD_MS, "hits": 50, "per_variant_worst_user": worst,
                                               "winner": winner, "per_user": summary}, indent=1))
print()
for v, w in worst.items():
    print(f"{v}: p95_check_ms={w['p95_check_ms']} p95_setup_ms={w['p95_setup_ms']} semantics_ok={w['semantics_ok']}")
if winner is None:
    print("NO VARIANT KEEPS THE V0 SEMANTICS")
    sys.exit(1)
w = worst[winner]
verdict = "met" if w["p95_check_ms"] < THRESHOLD_MS else "not-met"
print(f"winner={winner} p95_check_ms={w['p95_check_ms']} p95_setup_ms={w['p95_setup_ms']} A-PERF-3={verdict}")
