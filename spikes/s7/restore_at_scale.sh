#!/usr/bin/env bash
# A-OPS-2 at scale: encrypted backup of the 1M-page aps_search schema, restore into a fresh database, time to the first correct search.
# Runs inside the bd-s7 database container. Writes results-s7/restore_at_scale.json.
set -euo pipefail
cd "$(dirname "$0")/../.."
C="docker compose -f spikes/s7/compose.yaml"
DBX() { $C exec -T -e PGUSER=postgres -e PGDATABASE=nextcloud -e BD_BACKUP_KEY_FILE=/tmp/s7.key db "$@"; }
$C cp tools/backup.sh db:/tmp/backup.sh >/dev/null; $C cp tools/restore.sh db:/tmp/restore.sh >/dev/null
DBX sh -c 'rm -f /tmp/s7.bak* ; head -c 48 /dev/urandom | base64 > /tmp/s7.key; chmod 600 /tmp/s7.key'
t0=$(date +%s)
DBX bash /tmp/backup.sh /tmp/s7.bak
t1=$(date +%s)
BYTES=$(DBX stat -c %s /tmp/s7.bak)
$C exec -T -e PGUSER=postgres db sh -c 'dropdb --if-exists bd_s7_restore; createdb -O nextcloud bd_s7_restore; psql -d bd_s7_restore -qc "CREATE EXTENSION unaccent; CREATE EXTENSION vector"'
t2=$(date +%s)
$C exec -T -e PGUSER=postgres -e BD_BACKUP_KEY_FILE=/tmp/s7.key db bash /tmp/restore.sh /tmp/s7.bak bd_s7_restore
t3=$(date +%s)
# the first search on the restored database: the same keyword query the load test uses, ranked, with a result
HITS=$($C exec -T -e PGUSER=postgres db psql -d bd_s7_restore -tAq -c "WITH q AS (SELECT websearch_to_tsquery('aps_search.spanish_unaccent'::regconfig, 'beocio') AS tq) SELECT count(*) FROM (SELECT chunk_id FROM aps_search.chunk, q WHERE tsv @@ q.tq LIMIT 25) s")
VEC=$($C exec -T -e PGUSER=postgres db psql -d bd_s7_restore -tAq -c "SELECT count(*) FROM aps_search.chunk_vec_1")
t4=$(date +%s)
cat > spikes/s7/results-s7/restore_at_scale.json <<JSON
{"pages": 1000000, "chunks": 3000000, "backup_seconds": $((t1-t0)), "backup_bytes": $BYTES, "restore_seconds": $((t3-t2)), "first_search_seconds_after_restore": $((t4-t3)), "first_search_hits": $HITS, "restored_vectors": $VEC}
JSON
cat spikes/s7/results-s7/restore_at_scale.json
$C exec -T -e PGUSER=postgres db sh -c 'dropdb --if-exists bd_s7_restore; rm -f /tmp/s7.bak*'
