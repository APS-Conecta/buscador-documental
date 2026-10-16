"""Aggregate results/m9_raw.jsonl: p50/p95 of setup, check and total per (user, variant); A-PERF-3 verdict on check_ms."""
import json
import sys

sys.path.insert(0, "/work/bench")
from lib import write_result  # noqa: E402
from lib_load import pct  # noqa: E402

rows = [json.loads(l) for l in open("/work/results/m9_raw.jsonl")]
out = {}
for r in rows:
    out.setdefault(f"{r['uid']}/{r['variant']}", []).append(r)
summary = {}
for k, rs in out.items():
    summary[k] = {"runs": len(rs), **{f"{m}_{q}": round(pct([x[m] for x in rs], p), 1) for m in ("setup_ms", "check_ms", "total_ms")
                                       for q, p in (("p50", .5), ("p95", .95))},
                  "outcome_example": {x: rs[0][x] for x in ("ok", "denied", "excluded", "share_download_off")}}
    print(k, summary[k])
EXPECTED = {"u_narrow": {"ok": 35, "denied": 5, "excluded": 10, "share_download_off": 10},       # fixture: 25 bulk + 10 shared + 5 restricted + 10 tagged
            "u_admin200": {"ok": 40, "denied": 0, "excluded": 10, "share_download_off": 0},
            "u_shared": {"ok": 10, "denied": 40, "excluded": 0, "share_download_off": 10}}
for r in rows:                                          # every run of both variants must decide as the fixture says
    got = {x: r[x] for x in ("ok", "denied", "excluded", "share_download_off")}
    assert got == EXPECTED[r["uid"]], f"{r['uid']}/{r['variant']} decided {got}, expected {EXPECTED[r['uid']]}"
write_result("m9", {"hits": 50, "summary": summary, "a_perf_3_threshold_ms": 300,
                    "naive_check_p95_max_ms": max(v["check_ms_p95"] for k, v in summary.items() if k.endswith("naive")),
                    "batched_check_p95_max_ms": max(v["check_ms_p95"] for k, v in summary.items() if k.endswith("batched")),
                    "note": "check_ms excludes FS setup (setup_ms reported separately; it dominates for the 200-group user)"})

best = max(v["check_ms_p95"] for k, v in summary.items() if k.endswith("batched"))
if best > 300:
    print(f"DESIGN_NEEDED: best variant (batched per-folder cache) check p95 {best} ms > 300 ms for 50 hits; "
          "document the recheck design (per-parent batching / filecache+ACL resolution) before S1 exits")
