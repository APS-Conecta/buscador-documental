#!/usr/bin/env bash
# Same seed twice (1 vs 3 worker procs) -> identical sorted sha256 list. Scratch dirs live in the
# worker container's /tmp (ephemeral; nothing is deleted).
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose -f compose.yaml exec -T worker sh -c '
  set -e; cd /work/corpus
  a=$(mktemp -d); b=$(mktemp -d)
  python3 gen_corpus.py --seed 7 --pages 40 --procs 1 --out "$a" >/dev/null
  python3 gen_corpus.py --seed 7 --pages 40 --procs 3 --out "$b" >/dev/null
  (cd "$a" && find . -type f | sort | xargs sha256sum) > "$a.sha"
  (cd "$b" && find . -type f | sort | xargs sha256sum) > "$b.sha"
  diff "$a.sha" "$b.sha" && echo "deterministic: $(wc -l < "$a.sha") files identical across worker counts"
  python3 gen_vectors.py --seed 7 --dry-run 500 > "$a.v1"; python3 gen_vectors.py --seed 7 --dry-run 500 > "$a.v2"
  diff "$a.v1" "$a.v2" && echo "deterministic: text, vector and ACL batches byte-identical (sha256)"'
