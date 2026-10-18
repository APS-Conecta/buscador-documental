#!/usr/bin/env bash
# Runs the S7 matrix against the loaded bd-s7 database: LoadTest (the product's CandidateQuery) in N concurrent processes.
#   ./run.sh            full matrix (latency, then filtered recall)        ./run.sh quick   one pass of each, to check the plumbing
set -euo pipefail
cd "$(dirname "$0")"
ROOT=$(cd ../.. && pwd)
OUT=results-s7/raw; mkdir -p "$OUT"
QUICK=${1:-}
REPS=1   # 200 samples per configuration are enough for a p95; the full matrix is long (hybrid queries touch the vector index)
one() {  # mode user conc page
	local mode=$1 user=$2 conc=$3 page=$4 f="$OUT/${1}_${2}_c${3}_p${4}.jsonl"
	rm -f "$f"; : > "$f"
	for p in $(seq 0 $((conc - 1))); do
		docker run --rm --network bd-s7_default -v "$ROOT/app":/app -v "$PWD":/spikes/s7 -v "$PWD/$OUT":/out -w /app \
			-e S7_HOST=db -e S7_EF=400 -e S7_MODE="$mode" -e S7_USER="$user" -e S7_PAGE="$page" -e S7_PART="$p" -e S7_PARTS="$conc" -e S7_REPS="$REPS" \
			-e S7_OUT="/out/$(basename "$f")" bd-app-test vendor/bin/phpunit -c phpunit.s7.xml >/dev/null 2>"$f.err.$p" &
	done
	wait
	echo "$mode $user conc=$conc page=$page: $(wc -l < "$f") samples"
}
# warm the cache with one pass (not recorded)
for mode in exacta mixta; do for user in narrow admin200; do one "$mode" "$user" 1 25; done; done
rm -f "$OUT"/*.jsonl
for mode in exacta mixta; do
	for user in narrow admin200; do
		for conc in 1 5 10 20; do one "$mode" "$user" "$conc" 25; done
		for page in 50 100; do one "$mode" "$user" 5 "$page"; done
	done
	one "$mode" mid 5 25
done
for user in narrow mid admin200; do one recall "$user" 1 25; done
docker run --rm -v "$PWD":/s7 bd-s7-loader python3 /s7/report.py
