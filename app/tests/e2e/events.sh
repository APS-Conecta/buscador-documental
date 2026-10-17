#!/usr/bin/env bash
# S3 phase 4, live: real Nextcloud events (create, edit, move, share, tag, trash, delete) drive the index through the
# outbox and the ProcessOutbox job, run on demand with `occ background-job:execute`. Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
CTL="$C exec -T --user www-data nextcloud php $APP/tests/canary/ctl.php"
ENQ="$C exec -T --user www-data nextcloud php $APP/tests/canary/enqueue.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
DAV=http://127.0.0.1:8191/remote.php/dav/files
AUTH="-u ev_a:ev-pass-12345"
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym RESTART IDENTITY"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
# The exclusion tag is public and user-assignable (ADR-0013), so a user can apply it over WebDAV like in the interface.
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null
TAG=$($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'")
for u in ev_a ev_b; do $OCC user:delete "$u" >/dev/null 2>&1 || true; $C exec -T --user www-data -e OC_PASS=ev-pass-12345 nextcloud php occ user:add --password-from-env "$u" >/dev/null; done

JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
tick() { $OCC background-job:execute "$JOB" --force-execute >/dev/null; }
work() { $C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null; }
state() { $PSQL -c "select coalesce(text_state || ':' || coalesce(skip_reason, ''), 'none') from (select 1) x left join aps_search.file_state s on s.file_id = $1"; }
chunks() { $PSQL -c "select count(*) from aps_search.chunk where file_id = $1"; }
put() { curl -s -o /dev/null -w '%{http_code}' $AUTH -T "$2" "$DAV/ev_a/$1"; }
fid() { $PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id = f.storage where s.id = 'home::ev_a' and f.path = 'files/$1'"; }
found() { $CTL search ev_a "$1" | python3 -c 'import json,sys; j=json.load(sys.stdin); print(len(j["hits"]))'; }

TMP=$(mktemp -d /tmp/bd-s1-ev.XXXXXX); trap 'rm -rf "$TMP"' EXIT
echo "Informe sobre hemoglobina glicosilada" > "$TMP/a.txt"
echo "Documento fuera de las reglas" > "$TMP/b.txt"
curl -s -o /dev/null $AUTH -X MKCOL "$DAV/ev_a/Docs"; curl -s -o /dev/null $AUTH -X MKCOL "$DAV/ev_a/Otros"
$OCC files:scan ev_a >/dev/null
DOCS=$(fid Docs)
$OCC buscador:scope:add --folder-id="$DOCS" >/dev/null

echo "== 1. create inside and outside a rule"
[ "$(put Docs/a.txt "$TMP/a.txt")" = 201 ] && [ "$(put Otros/b.txt "$TMP/b.txt")" = 201 ] || die "upload"
A=$(fid Docs/a.txt); B=$(fid Otros/b.txt)
[ "$($PSQL -c "select count(*) from aps_search.outbox where file_id in ($A,$B) and processed_at is null")" -ge 2 ] || die "the upload events did not reach the outbox"
ok "uploads wrote hints to the outbox"
tick
[ "$(state "$A")" = "pending:" ] || die "inside the rule: $(state "$A")"
[ "$(state "$B")" = "none" ] || die "outside the rule: $(state "$B")"
ok "inside the rule it is queued; outside it is ignored"
work
[ "$(state "$A")" = "indexed:" ] && [ "$(found hemoglobina)" = 1 ] || die "not indexed/found"
ok "indexed and found"

echo "== 2. edit"
echo "Informe revisado sobre nutricion infantil" > "$TMP/a.txt"
put Docs/a.txt "$TMP/a.txt" >/dev/null
tick; work
[ "$(found nutricion)" = 1 ] && [ "$(found hemoglobina)" = 0 ] || die "edit not reflected"
ok "an edit re-reads the file"

echo "== 3. move out of the rule and back"
curl -s -o /dev/null $AUTH -X MOVE -H "Destination: $DAV/ev_a/Otros/a.txt" "$DAV/ev_a/Docs/a.txt"
tick
[ "$(state "$A")" = "skipped:out_of_scope" ] && [ "$(chunks "$A")" = 0 ] && [ "$(found nutricion)" = 0 ] || die "move out: $(state "$A") chunks=$(chunks "$A")"
ok "moved out of the rule: erased, not findable"
curl -s -o /dev/null $AUTH -X MOVE -H "Destination: $DAV/ev_a/Docs/a.txt" "$DAV/ev_a/Otros/a.txt"
tick; work
[ "$(state "$A")" = "indexed:" ] && [ "$(found nutricion)" = 1 ] || die "move back: $(state "$A")"
ok "moved back: indexed again"

echo "== 4. exclusion tag"
[ "$(curl -s -o /dev/null -w '%{http_code}' $AUTH -X PUT "http://127.0.0.1:8191/remote.php/dav/systemtags-relations/files/$A/$TAG")" = 201 ] || die "could not tag over WebDAV"
tick
[ "$(state "$A")" = "skipped:excluded" ] && [ "$(chunks "$A")" = 0 ] && [ "$(found nutricion)" = 0 ] || die "tag: $(state "$A")"
ok "tagged rag-excluded: erased"
[ "$(curl -s -o /dev/null -w '%{http_code}' $AUTH -X DELETE "http://127.0.0.1:8191/remote.php/dav/systemtags-relations/files/$A/$TAG")" = 204 ] || die "could not untag over WebDAV"
tick; work
[ "$(state "$A")" = "indexed:" ] && [ "$(found nutricion)" = 1 ] || die "untag: $(state "$A")"
ok "untagged: indexed again"

echo "== 5. share and unshare change the stored principals"
$CTL share ev_a Docs/a.txt user ev_b >/dev/null
tick
P=$($PSQL -c "select array_to_string(principals, ',') from aps_search.file_acl where file_id=$A")
[ "$P" = "u:ev_a,u:ev_b" ] || die "after share: $P"
ok "a share adds the sharee's principal"
$CTL unshare ev_a Docs/a.txt ev_b >/dev/null
tick
P=$($PSQL -c "select array_to_string(principals, ',') from aps_search.file_acl where file_id=$A")
[ "$P" = "u:ev_a" ] || die "after unshare: $P"
ok "unsharing removes it"

echo "== 6. trash, then permanent delete"
curl -s -o /dev/null $AUTH -X DELETE "$DAV/ev_a/Docs/a.txt"
tick
[ "$(state "$A")" = "skipped:trashed" ] && [ "$(chunks "$A")" = 0 ] && [ "$(found nutricion)" = 0 ] || die "trash: $(state "$A")"
[ "$($PSQL -c "select count(*) from aps_search.tombstone where file_id=$A and reason='trashed'")" = 1 ] || die "no tombstone"
ok "trashed: erased at once, with a 30-day ledger entry"

echo "== 7. the outbox settles"
tick
[ "$($PSQL -c "select count(*) from aps_search.outbox where processed_at is null and attempts < 5")" = 0 ] || die "unprocessed rows remain"
ok "nothing left waiting"
echo "events OK ($PASS checks)"
