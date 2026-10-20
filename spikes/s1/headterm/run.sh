#!/usr/bin/env bash
# Head-term re-run on PG 18: load the same 3M-chunk text stream as S0, then S0's m10 unchanged. Project bd-s1pg only.
# Needs ~12 GB of disk and a quiet box (check `uptime`). `docker compose -p bd-s1pg down -v` removes it (see `clean`).
set -euo pipefail
cd "$(dirname "$0")"
C="docker compose -f compose.yaml"
mkdir -p ../results-s1/headterm
[ "${1:-}" = clean ] && { $C --profile tools down -v --remove-orphans; exit 0; }
GOLD=../../s0-harness/corpus/out/functional/gold.json   # gitignored S0 output; regenerate with the S0 harness (make corpus) if absent
[ -f "$GOLD" ] || { echo "gold set missing: $GOLD"; exit 1; }
$C up -d --wait db
RUN="$C --profile tools run --rm -T -e PYTHONDONTWRITEBYTECODE=1 -v $PWD:/s1:ro py"
$RUN python3 /s1/load_text.py --chunks 3000000
$RUN python3 /work/bench/m10_headterm.py
python3 ../bench/headterm_compare.py ../results-s1/headterm/m10.json ../../s0-harness/results-s0/m10.json
