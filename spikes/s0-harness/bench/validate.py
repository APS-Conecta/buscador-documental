"""S0 validation: re-runs the plan's success-criteria commands and the acceptance checks; prints a markdown table.
Host python (needs docker). Exit 1 if any check fails. A-PERF-3 is reported as FAIL on purpose: S0 measured it as not met."""
import json
import os
import subprocess
import sys

# The planning docs sit two levels up when the harness lives in spikes/s0-harness/ of the product repo.
BUSCADOR = os.environ.get("BUSCADOR_DIR", os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..", "buscador"))
C = "docker compose -f compose.yaml"
W = f"{C} exec -T worker"
rows = []


def sh(cmd, timeout=900):
    p = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=timeout)
    return p.returncode, (p.stdout + p.stderr).strip()


def check(id_, desc, ok, out):
    rows.append((id_, desc, "PASS" if ok else "FAIL", out.replace("\n", " / ")[:160].replace("|", "/")))


rc, o = sh(f"{C} ps --format '{{{{.Service}}}}={{{{.Health}}}}'")
check("P1", "db and nextcloud healthy", "db=healthy" in o and "nextcloud=healthy" in o, o)
rc, o = sh(f"{C} exec -T db psql -U nextcloud -tAc \"select extversion from pg_extension where extname='vector'\"")
check("P1", "pgvector >= 0.8", o and tuple(map(int, o.split(".")[:2])) >= (0, 8), o)
rc, o = sh(f"{C} exec -T --user www-data nextcloud php occ app:list | grep groupfolders")
check("P1", "groupfolders enabled", rc == 0, o)
rc, o = sh(f"{W} sh -c 'tesseract --list-langs | grep spa && ocrmypdf --version'")
check("P1", "tesseract spa + ocrmypdf", rc == 0, o)
rc, o = sh("bash seed/verify_seed.sh")
check("P2", "seed fixtures verified (F1, F2, F3 ACL, tag)", rc == 0 and "FAIL" not in o, o.splitlines()[-1] if o else "")
rc, o = sh("bash seed/seed_nc.sh > /dev/null && cp results/seed_folders.json results/seed_ids.2 && diff results/seed_ids.1 results/seed_ids.2")
check("P2", "re-seed gives identical ids (idempotent)", rc == 0, o or "identical")
rc, o = sh("bash corpus/determinism_check.sh")
check("P3", "corpus and vector batches deterministic", rc == 0, o.splitlines()[-1] if o else "")
rc, o = sh(f"{W} sh -c 'cd corpus && python3 selfcheck.py out/functional out/functional/gold.json'")
check("P3", "corpus and gold-set self-checks", rc == 0 and "FAIL" not in o, o.splitlines()[-1] if o else "")
rc, o = sh(f"{C} exec -T db psql -U nextcloud -d nextcloud -tAc 'select count(*) from bd_spike.chunk'")
check("P4", "bd_spike.chunk >= 3 000 000 rows", int(o or 0) >= 3_000_000, o)
rc, o = sh(f"{W} python3 bench/check_results.py")
check("P5-7", "all result JSON valid, host load recorded, >= 3 repeats", rc == 0 and "FAIL" not in o, f"{o.count('ok ')} files ok")
rc, o = sh(f"{W} python3 bench/m9_aggregate.py | tail -1")
check("P7", "m9 prints DESIGN_NEEDED when p95 > 300 ms", "DESIGN_NEEDED" in o, o[:100])

R = lambda n: json.load(open(f"results/{n}.json"))
cap = R("m6_capped")["configs"]
worst = max(c["p95_median_ms"] for c in cap if c["conc"] <= 20)
check("A-PERF-1", "keyword p95 < 1 s, 3M chunks, gold set, pre-limit, up to 20 searchers (indicative)", worst < 1000, f"worst median p95 {worst:.0f} ms")
unc = R("m6")["configs"][0]["p95_median_ms"]
check("A-PERF-1", "keyword p95 < 1 s WITHOUT pre-limit (documents why the pre-limit is required)", unc < 1000, f"{unc:.0f} ms at 1 searcher")
h = max(c["p95_median_ms"] for c in R("m7")["configs"])
check("A-PERF-2", "hybrid p95 < 2 s (indicative)", h < 2000, f"worst median p95 {h:.0f} ms")
m9 = R("m9")
check("A-PERF-3", "isReadable x50 p95 < 300 ms (naive)", m9["naive_check_p95_max_ms"] < 300, f"{m9['naive_check_p95_max_ms']} ms")
check("A-PERF-3", "isReadable x50 p95 < 300 ms (per-request folder cache)", m9["batched_check_p95_max_ms"] < 300, f"{m9['batched_check_p95_max_ms']} ms; cache design owed to S1")
sheet = open(f"{BUSCADOR}/capacity-sheet.md").read()
check("A-PERF-4", "capacity sheet has no empty cells", "| |" not in sheet and "|  |" not in sheet, f"{sheet.count(chr(10))} lines")
for name in ("m8_bd_recall", "m8_bd_spike", "m8_bd_gmm"):
    d = R(name)
    bad = [c for v in d["views"].values() for c in v["configs"] if not c["plan_uses_hnsw"]] + [r for r in d["exact_switch_sweep"] if not r["plan_uses_hnsw"]]
    check("m8", f"{name}: every ANN config ran an HNSW plan (EXPLAIN)", not bad, f"{len(bad)} configs without HNSW")
check("A-PERF-1", "m6 capped: no timeouts or errors in any config", all(c["pooled"]["errors"] == 0 for c in cap), "errors " + str([c["pooled"]["errors"] for c in cap]))
g = R("m8_bd_gmm")["views"]
pick = lambda v, ef=200: next(c for c in g[v]["configs"] if (c["iterative_scan"], c["ef_search"], c["max_scan_tuples"]) == ("relaxed_order", ef, 20000))["recall_at_10_mean"]
gap = (pick("unfiltered") - pick("F1_narrow")) * 100
check("A-QUAL-3", "narrow user, ANN path, 3M clustered, ef 200: within 5 points of unfiltered (indicative)", gap <= 5, f"gap {gap:.1f} points (ef 400: {(pick('unfiltered', 400) - pick('F1_narrow', 400)) * 100:.1f}); admin = unfiltered by construction")
sw = {r["readable_files"]: r for r in R("m8_bd_gmm")["exact_switch_sweep"]}
check("A-QUAL-3", "exact path < 100 ms at 2 000 readable files (switch rule)", sw[2000]["exact_p50_ms"] < 100, f"{sw[2000]['exact_p50_ms']} ms; ANN recall {sw[2000]['ann_recall_at_10']}")
acc = open(f"{BUSCADOR}/acceptance.md").read()
check("P8", "no bare [M0] placeholder left in the acceptance table", "`[M0]`" not in acc.split("\n", 4)[4], "only the legend uses it")
adr = open(f"{BUSCADOR}/adr/0001-engine-postgres-first.md").read()
check("P8", "ADR-0001 outcome recorded", "Amendment (2026-10-16, S0)" in adr, "present")

print("| id | check | result | evidence |\n|---|---|---|---|")
for r in rows:
    print("| " + " | ".join(r) + " |")
sys.exit(1 if any(r[2] == "FAIL" for r in rows) else 0)
