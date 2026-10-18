#!/usr/bin/env bash
# S7 failure modes that need the live stack (architecture section 9; the others are unit or database tests, see
# buscador/failure-modes.md): the index database refuses writes (what a full disk or a read-only replica does to a writer).
# Done per role, because a read-only database also stops Nextcloud itself: the worker's role is made read-only, and the search
# can no longer store its result snapshot. Search must say "unavailable" without crashing, the worker must stop with a short
# note that carries no text, and both must recover when writes come back, with nothing lost.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
CTL="$C exec -T --user www-data nextcloud php $APP/tests/canary/ctl.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }
R=$(head -c 3 /dev/urandom | od -An -tx1 | tr -d ' \n'); S="FAILWORD${R}"

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym, aps_search.reconcile_run, aps_search.reconcile_node, aps_search.search_snapshot RESTART IDENTITY CASCADE" >/dev/null
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null
$OCC user:delete fm_a >/dev/null 2>&1 || true
$C exec -T --user www-data -e OC_PASS=fm-pass-12345 nextcloud php occ user:add --password-from-env fm_a >/dev/null
TMP=$(mktemp -d /tmp/bd-s1-fm.XXXXXX)
restore_writes() { $PSQL -c "ALTER ROLE bd_worker RESET default_transaction_read_only" >/dev/null 2>&1 || true; $PSQL -c "GRANT SELECT, INSERT, UPDATE, DELETE ON aps_search.search_snapshot TO nextcloud" >/dev/null 2>&1 || true; }
trap 'restore_writes; rm -rf "$TMP"' EXIT
JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
tick() { $OCC background-job:execute "$JOB" --force-execute >/dev/null; }
work() { for _ in 1 2 3; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >>"$TMP/work.log" 2>&1 || true; done; }
put() { printf '%s\n' "$2" > "$TMP/$1"; $C cp "$TMP/$1" "nextcloud:/var/www/html/data/fm_a/files/Docs/$1" >/dev/null; $C exec -T nextcloud chown www-data:www-data "/var/www/html/data/fm_a/files/Docs/$1"; }

$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/fm_a/files/Docs
put one.txt "Informe uno $S con hemoglobina"
$OCC files:scan fm_a >/dev/null
DOCS=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::fm_a' and f.path='files/Docs'")
$OCC buscador:scope:add --folder-id="$DOCS" >/dev/null
$PSQL -c "insert into aps_search.outbox (file_id, kind) select f.fileid, 'file' from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::fm_a' and f.path like 'files/Docs/%' on conflict do nothing" >/dev/null
tick; work
hits() { $CTL api fm_a "{\"query\":\"$S\",\"mode\":\"exacta\"}" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["status"], len(d["hits"]))'; }
[ "$(hits)" = "ok 1" ] || die "baseline search did not find the document ($(hits))"
ok "baseline: one document indexed and found"

# a second document is waiting while the database refuses writes
put two.txt "Informe dos $S con glucosa"
$OCC files:scan fm_a >/dev/null
$PSQL -c "insert into aps_search.outbox (file_id, kind) select f.fileid, 'file' from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::fm_a' and f.path = 'files/Docs/two.txt' on conflict do nothing" >/dev/null
tick
$PSQL -c "ALTER ROLE bd_worker SET default_transaction_read_only = on" >/dev/null
$PSQL -c "REVOKE INSERT ON aps_search.search_snapshot FROM nextcloud" >/dev/null
OUT=$(hits 2>&1 || true)
case "$OUT" in "unavailable 0") ok "search answers 'unavailable' when the index cannot store its result (no crash, no results invented)";; *) die "search without write access to the snapshot: $OUT";; esac
: > "$TMP/work.log"
$C --profile worker run --rm --no-deps -T worker python3 main.py --once >"$TMP/ro.log" 2>&1 || true
grep -q "Traceback" "$TMP/ro.log" && die "the worker printed a traceback while writes are refused"
grep -q "$S" "$TMP/ro.log" && die "the worker log carries document text"
[ "$(grep -vcE "^ Container|^ Network|^ Volume" "$TMP/ro.log")" -le 3 ] || die "the worker was noisy while writes are refused: $(head -c 400 "$TMP/ro.log")"
ok "the worker neither crashes with a traceback nor logs text while writes are refused"

restore_writes
[ "$(hits)" = "ok 1" ] || die "search did not recover when writes came back ($(hits))"
work
[ "$(hits)" = "ok 2" ] || die "the waiting document was not indexed after recovery ($(hits))"
ok "after writes return, search works again and the document that waited is indexed (nothing lost)"

# the exclusion state unavailable fails closed: no tag, no results
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
OUT=$(hits 2>&1 || true)
case "$OUT" in "unavailable 0") ok "without the exclusion tag the search fails closed (unavailable, nothing shown)";; *) die "search without the exclusion tag: $OUT";; esac
$OCC tag:add rag-excluded public >/dev/null
[ "$(hits)" = "ok 2" ] || die "search did not recover after the tag came back"
echo "failures OK ($PASS checks)"
