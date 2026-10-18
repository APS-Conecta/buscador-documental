"""Summarise results-s7/raw/*.jsonl into results-s7/latency.json and summary.md (p50/p95/p99 in ms, recall@10)."""
import glob
import json
import os
import re

import numpy as np

raw = "/s7/results-s7/raw"
rows, recall = [], []
for f in sorted(glob.glob(f"{raw}/*.jsonl")):
    m = re.match(r"(\w+?)_(\w+?)_c(\d+)_p(\d+)\.jsonl", os.path.basename(f))
    mode, user, conc, page = m.group(1), m.group(2), int(m.group(3)), int(m.group(4))
    recs = [json.loads(l) for l in open(f) if l.strip()]
    if mode == "recall":
        k = sum(r["k"] for r in recs)
        recall.append({"user": user, "queries": len(recs), "recall_at_10": round(sum(r["hit"] for r in recs) / k, 4) if k else None})
        continue
    ms = np.array([r["ms"] for r in recs])
    rows.append({"mode": mode, "user": user, "concurrency": conc, "page": page, "samples": len(ms),
                 "p50_ms": round(float(np.percentile(ms, 50)), 1), "p95_ms": round(float(np.percentile(ms, 95)), 1), "p99_ms": round(float(np.percentile(ms, 99)), 1),
                 "empty_results": int(sum(1 for r in recs if r["n"] == 0))})
json.dump({"latency": rows, "recall": recall}, open("/s7/results-s7/latency.json", "w"), indent=2)
lines = ["| mode | user | conc | page | n | p50 ms | p95 ms | p99 ms |", "|---|---|---|---|---|---|---|---|"]
lines += [f"| {r['mode']} | {r['user']} | {r['concurrency']} | {r['page']} | {r['samples']} | {r['p50_ms']} | {r['p95_ms']} | {r['p99_ms']} |" for r in rows]
lines += ["", "| user | queries | recall@10 (HNSW with iterative scan vs exact scan) |", "|---|---|---|"] + [f"| {r['user']} | {r['queries']} | {r['recall_at_10']} |" for r in recall]
open("/s7/results-s7/summary.md", "w").write("\n".join(lines) + "\n")
print("\n".join(lines))
