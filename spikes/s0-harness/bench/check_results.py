"""Validates results/*.json: parseable, carries host load, and (m6/m7) has >= 3 repeats per config. Exit 1 on failure."""
import glob
import json
import sys

fails = 0
for path in sorted(glob.glob("/work/results/m*.json")):
    d = json.load(open(path))
    ok = "host" in d and "loadavg" in d["host"] and "measured_at" in d
    if ok and "configs" in d and any("repeats" in c for c in d["configs"]):
        ok = all(len(c["repeats"]) >= 3 for c in d["configs"] if "repeats" in c)
    print(("ok   " if ok else "FAIL ") + path.rsplit("/", 1)[1])
    fails += not ok
sys.exit(1 if fails else 0)
