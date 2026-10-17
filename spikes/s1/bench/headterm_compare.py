"""Side-by-side of S0's m10 (PostgreSQL 17.11) and the PG 18 re-run: p50/p95 per class and variant. Usage: <pg18 m10.json> <pg17 m10.json>"""
import json
import sys

new, old = (json.load(open(p))["variants"] for p in sys.argv[1:3])
key = lambda v: (v["class"], v["variant"])
old_by = {key(v): v for v in old}
print(f"{'class/variant':36} {'17 p50':>9} {'18 p50':>9} {'17 p95':>9} {'18 p95':>9}")
for v in new:
    o = old_by.get(key(v))
    print(f"{v['class'] + '/' + v['variant']:36} {o['p50_ms'] if o else '-':>9} {v['p50_ms']:>9} {o['p95_ms'] if o else '-':>9} {v['p95_ms']:>9}")
