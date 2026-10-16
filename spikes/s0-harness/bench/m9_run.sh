#!/usr/bin/env bash
# m9: isReadable/getById x50 hits. 30 fresh PHP processes per (user, variant); aggregated into results/m9.json.
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker compose -f compose.yaml"
REPS=${REPS:-30}
$C cp bench/m9_isreadable.php nextcloud:/tmp/m9_isreadable.php
$C cp results/f3_hits.json nextcloud:/tmp/f3_hits.json
: > results/m9_raw.jsonl
for uid in u_narrow u_admin200 u_shared; do
  for variant in naive batched; do
    for i in $(seq 1 "$REPS"); do
      $C exec -T --user www-data nextcloud php /tmp/m9_isreadable.php "$uid" "$variant" /tmp/f3_hits.json >> results/m9_raw.jsonl
    done
  done
done
$C exec -T worker python3 bench/m9_aggregate.py
