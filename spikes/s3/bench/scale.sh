#!/usr/bin/env bash
# S3 phase 6: reconciliation at scale. Loads N synthetic files into the REAL oc_filecache of the throwaway bd-s1 stack
# (a rule covers them all), seeds the index state to a mix of up-to-date, stale and orphan rows, then runs the real
# Reconciler and EXPLAIN (ANALYZE, BUFFERS). Honest limits: one box, shared with other containers; synthetic paths.
set -euo pipefail
cd "$(dirname "$0")/../../.."
N=${N:-300000}
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
OUT=spikes/s3/results-s3/reconcile_scale_$N.json

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$PSQL -c "TRUNCATE aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.scope_rule, aps_search.reconcile_run, aps_search.reconcile_node, aps_search.audit_log RESTART IDENTITY"

# mime ids (insert the ones a fresh instance may not have yet)
$PSQL -c "insert into oc_mimetypes (mimetype) select m from (values ('httpd/unix-directory'), ('application'), ('application/pdf')) v(m) where not exists (select 1 from oc_mimetypes x where x.mimetype = v.m)"
DIR=$($PSQL -c "select id from oc_mimetypes where mimetype = 'httpd/unix-directory'")
PDF=$($PSQL -c "select id from oc_mimetypes where mimetype = 'application/pdf'")
APPPART=$($PSQL -c "select id from oc_mimetypes where mimetype = 'application'")
STORAGE=$($PSQL -c "insert into oc_storages (id, available) values ('home::bench', 1) on conflict (id) do update set available = 1 returning numeric_id")

$PSQL -c "delete from oc_filecache where storage = $STORAGE"
row() { echo "$STORAGE, '$1', md5('$1'), $2, '$3', $4, $5, $6, 1700000000, 1700000000, 0, 0, '$7', 31"; }
ROOT=$($PSQL -c "insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, encrypted, unencrypted_size, etag, permissions) values ($(row files -1 '' "$DIR" "$DIR" 0 root)) returning fileid")
TOP=$($PSQL -c "insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, encrypted, unencrypted_size, etag, permissions) values ($(row files/bench "$ROOT" bench "$DIR" "$DIR" 0 top)) returning fileid")
$PSQL -c "insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, encrypted, unencrypted_size, etag, permissions)
  select $STORAGE, 'files/bench/d' || g, md5('files/bench/d' || g), $TOP, 'd' || g, $DIR, $DIR, 0, 1700000000, 1700000000, 0, 0, 'fe' || g, 31 from generate_series(1, 300) g"
$PSQL -c "insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, encrypted, unencrypted_size, etag, permissions)
  select $STORAGE, 'files/bench/d' || (1 + g % 300) || '/f' || g || '.pdf', md5('files/bench/d' || (1 + g % 300) || '/f' || g || '.pdf'), d.fileid, 'f' || g || '.pdf', $PDF, $APPPART, 1000, 1700000000, 1700000000, 0, 0, 'e' || g, 27
  from generate_series(1, $N) g join oc_filecache d on d.storage = $STORAGE and d.path = 'files/bench/d' || (1 + g % 300)"
FILES=$($PSQL -c "select count(*) from oc_filecache where storage = $STORAGE and mimetype = $PDF")
echo "loaded $FILES files"

# index state: 60% up to date, 5% stale etag, 40% never indexed; plus 2% orphans (files that no longer exist)
$PSQL -c "insert into aps_search.file_state (file_id, etag, text_state)
  select f.fileid, case when f.fileid % 20 = 0 then 'OLD' else f.etag end, 'indexed' from oc_filecache f where f.storage = $STORAGE and f.mimetype = $PDF and f.fileid % 10 < 6"
$PSQL -c "insert into aps_search.file_meta (file_id, name, storage_id) select file_id, 'f', $STORAGE from aps_search.file_state"
$PSQL -c "insert into aps_search.file_state (file_id, etag, text_state) select 900000000 + g, 'x', 'indexed' from generate_series(1, $((N / 50))) g"
$PSQL -c "analyze oc_filecache; analyze aps_search.file_state; analyze aps_search.file_meta"
$OCC buscador:scope:add --folder-id="$TOP" >/dev/null
$PSQL -c "insert into aps_search.settings (key, value) values ('reconcile_cap', '100000000') on conflict (key) do update set value = excluded.value"      # time the whole pass, not the nightly cap

LOAD_BEFORE=$(cut -d' ' -f1-3 /proc/loadavg)
$C exec -T --user www-data nextcloud php /var/www/html/custom_apps/buscador_documental/tests/canary/reconcile_bench.php > "$OUT.raw"
LOAD_AFTER=$(cut -d' ' -f1-3 /proc/loadavg)
PGV=$($PSQL -c "show server_version"); SB=$($PSQL -c "show shared_buffers")
python3 - "$OUT.raw" "$OUT" "$N" "$FILES" "$LOAD_BEFORE" "$LOAD_AFTER" "$PGV" "$SB" <<'PY'
import json, sys
raw, out, n, files, lb, la, pgv, sb = sys.argv[1:]
d = json.load(open(raw))
d.update({"requested_files": int(n), "loaded_files": int(files), "load_average_before": lb, "load_average_after": la,
          "postgres": pgv, "shared_buffers": sb,
          "limits": ["one shared 8-core box", "synthetic paths", "all files under one rule", "no tags, no groupfolders",
                     "oc_filecache rows are minimal; a real one has more columns and indexes"]})
json.dump(d, open(out, "w"), indent=1)
print(f"files={files} total={d['seconds_total']}s phases={d['seconds_by_phase']} counts={d['counts']}")
PY
rm -f "$OUT.raw"
$PSQL -c "delete from oc_filecache where storage = $STORAGE; delete from oc_storages where numeric_id = $STORAGE" >/dev/null
