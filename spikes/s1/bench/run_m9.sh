#!/usr/bin/env bash
# m9 corrected: REPS fresh PHP processes per (user, variant), variants interleaved per rep so box drift hits all alike.
# Needs the bd-s1 stack with the app enabled and the fixture seeded (seed/seed_f3_bulk_s1.sh). Check `uptime` first.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OUT=spikes/s1/results-s1
REPS=${REPS:-30}
VARIANTS=${VARIANTS:-"V0 V1 V2 V3 prod"}
$C cp spikes/s1/bench/m9_isreadable.php nextcloud:/tmp/m9_isreadable.php
$C cp "$OUT/f3_hits.json" nextcloud:/tmp/f3_hits.json
{ echo "{\"start\": \"$(date -u +%FT%TZ)\", \"load_before\": \"$(cut -d' ' -f1-3 /proc/loadavg)\", \"nproc\": $(nproc), \"reps\": $REPS}"; } > "$OUT/m9_env.json"
: > "$OUT/m9_raw.jsonl"
# warm-up: one throwaway run per user so the first sample is not a cold opcache/APCu outlier
for uid in u_narrow u_admin200 u_shared; do $C exec -T --user www-data nextcloud php /tmp/m9_isreadable.php "$uid" V2 /tmp/f3_hits.json >/dev/null; done
for i in $(seq 1 "$REPS"); do
	for uid in u_narrow u_admin200 u_shared; do
		for v in $VARIANTS; do
			$C exec -T --user www-data nextcloud php /tmp/m9_isreadable.php "$uid" "$v" /tmp/f3_hits.json >> "$OUT/m9_raw.jsonl"
		done
	done
done
python3 - <<PY
import json
p = "$OUT/m9_env.json"
d = json.load(open(p)); d["end"] = "$(date -u +%FT%TZ)"; d["load_after"] = "$(cut -d' ' -f1-3 /proc/loadavg)"
json.dump(d, open(p, "w"))
PY
python3 spikes/s1/bench/summarize.py "$OUT"
