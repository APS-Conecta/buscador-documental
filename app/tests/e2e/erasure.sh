#!/usr/bin/env bash
# S3 phase 5, live: erasure (A-SEC-3, A-SEC-6). A tag on a FOLDER erases the files below it; deleting a user erases
# their indexed files and leaves an audit trail that cannot be linked back; `occ buscador:purge-user` works on a user
# who still exists. Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
DAV=http://127.0.0.1:8191/remote.php/dav
PW=er-pass-12345
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym, aps_search.purge_pending RESTART IDENTITY CASCADE"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null
TAG=$($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'")
for u in er_a er_c er_d; do $OCC user:delete "$u" >/dev/null 2>&1 || true; $C exec -T --user www-data -e OC_PASS=$PW nextcloud php occ user:add --password-from-env "$u" >/dev/null; done
$PSQL -c "TRUNCATE aps_search.audit_log, aps_search.pseudonym, aps_search.purge_pending"

JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
tick() { $OCC background-job:execute "$JOB" --force-execute >/dev/null; }
work() { for _ in 1 2 3 4; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null; done; }
fid() { $PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id = f.storage where s.id = 'home::$1' and f.path = 'files/$2'"; }
state() { $PSQL -c "select coalesce(text_state || ':' || coalesce(skip_reason, ''), 'none') from (select 1) x left join aps_search.file_state s on s.file_id = $1"; }
chunks() { $PSQL -c "select count(*) from aps_search.chunk where file_id = $1"; }
dav() { curl -s -o /dev/null -w '%{http_code}' -u "$1:$PW" "${@:2}"; }

TMP=$(mktemp -d /tmp/bd-s1-er.XXXXXX); trap 'rm -rf "$TMP"' EXIT
echo "Informe uno sobre hemoglobina" > "$TMP/1.txt"; echo "Informe dos sobre nutricion" > "$TMP/2.txt"
for u in er_a er_c er_d; do dav $u -X MKCOL "$DAV/files/$u/Docs" >/dev/null; done
$OCC files:scan --all >/dev/null
for u in er_a er_c er_d; do $OCC buscador:scope:add --folder-id="$(fid $u Docs)" >/dev/null; done

echo "== 1. a tag on a FOLDER erases everything below it"
dav er_a -T "$TMP/1.txt" "$DAV/files/er_a/Docs/1.txt" >/dev/null; dav er_a -T "$TMP/2.txt" "$DAV/files/er_a/Docs/2.txt" >/dev/null
tick; work
F1=$(fid er_a Docs/1.txt); F2=$(fid er_a Docs/2.txt); D=$(fid er_a Docs)
[ "$(state "$F1")" = "indexed:" ] && [ "$(state "$F2")" = "indexed:" ] || die "setup: $(state "$F1") $(state "$F2")"
[ "$(dav er_a -X PUT "$DAV/systemtags-relations/files/$D/$TAG")" = 201 ] || die "tagging the folder"
tick; tick
[ "$(state "$F1")" = "skipped:excluded" ] && [ "$(state "$F2")" = "skipped:excluded" ] || die "after tag: $(state "$F1") $(state "$F2")"
[ "$(chunks "$F1")" = 0 ] && [ "$(chunks "$F2")" = 0 ] || die "text remains"
[ "$($PSQL -c "select count(*) from aps_search.page where file_id in ($F1,$F2)")" = 0 ] || die "pages remain"
ok "the tagged folder's files are erased, not just hidden (0 pages, 0 chunks)"
[ "$(dav er_a -X DELETE "$DAV/systemtags-relations/files/$D/$TAG")" = 204 ] || die "untagging"
tick; tick; work
[ "$(state "$F1")" = "indexed:" ] && [ "$(state "$F2")" = "indexed:" ] || die "after untag: $(state "$F1") $(state "$F2")"
ok "removing the tag brings them back"

echo "== 2. the storage is recorded"
[ "$($PSQL -c "select count(*) from aps_search.file_meta where file_id = $F1 and storage_id is not null")" = 1 ] || die "file_meta has no storage id"
ok "file_meta carries the storage id"

echo "== 3. deleting a user erases their files and anonymises the audit"
echo "Informe del usuario sobre hemoglobina" > "$TMP/c.txt"
dav er_c -T "$TMP/c.txt" "$DAV/files/er_c/Docs/c.txt" >/dev/null
tick; work
FC=$(fid er_c Docs/c.txt)
[ "$(state "$FC")" = "indexed:" ] || die "er_c file: $(state "$FC")"
$OCC buscador:scope:add --folder-id="$(fid er_c Docs)" >/dev/null     # same rule, so the audit has a subject with a token
$OCC user:delete er_c >/dev/null
[ "$($PSQL -c "select count(*) from aps_search.file_state where file_id = $FC")" = 0 ] || die "state remains after user deletion"
[ "$(chunks "$FC")" = 0 ] && [ "$($PSQL -c "select count(*) from aps_search.page where file_id = $FC")" = 0 ] && [ "$($PSQL -c "select count(*) from aps_search.file_meta where file_id = $FC")" = 0 ] || die "text remains after user deletion"
ok "the deleted user's file left no trace in the index"
[ "$($PSQL -c "select count(*) from aps_search.audit_log where action = 'purge_user'")" = 1 ] || die "no purge_user audit row"
[ "$($PSQL -c "select detail::text from aps_search.audit_log where action = 'purge_user'")" = '{"count": 1, "reason": "user_deleted"}' ] || die "audit detail"
LOG=$($PSQL -c "select actor || ' ' || coalesce(subject_token, '') || ' ' || detail::text from aps_search.audit_log")
grep -q "er_c" <<<"$LOG" && die "the audit names the user: $LOG"
[ "$($PSQL -c "select count(*) from aps_search.pseudonym where uid = 'er_c'")" = 0 ] || die "the uid mapping survived"
[ "$($PSQL -c "select count(*) from aps_search.purge_pending")" = 0 ] || die "bookkeeping row left"
ok "audited with a count, the uid is nowhere in the audit, the mapping and bookkeeping are gone"
[ "$(state "$F1")" = "indexed:" ] || die "another user's file was touched"
ok "another user's files are untouched"

echo "== 4. occ buscador:purge-user for a user who still exists"
echo "Informe de otro usuario sobre hemoglobina" > "$TMP/d.txt"
dav er_d -T "$TMP/d.txt" "$DAV/files/er_d/Docs/d.txt" >/dev/null
tick; work
FD=$(fid er_d Docs/d.txt)
[ "$(state "$FD")" = "indexed:" ] || die "er_d file: $(state "$FD")"
$OCC buscador:purge-user er_d | grep -q "erased: 1" || die "purge-user output"
[ "$(chunks "$FD")" = 0 ] && [ "$($PSQL -c "select count(*) from aps_search.file_state where file_id = $FD")" = 0 ] || die "purge-user left data"
$OCC user:info er_d >/dev/null || die "the user must still exist"
[ "$($PSQL -c "select count(*) from oc_filecache where fileid = $FD")" = 1 ] || die "the file itself must be untouched"
ok "purge-user erased the index data; the user and the file are untouched"
OUT=$($OCC buscador:purge-user 2>&1 || true)
grep -q "Give a user id" <<<"$OUT" || die "purge-user must refuse without a target :: $OUT"
ok "purge-user refuses without a target"
echo "erasure OK ($PASS checks)"
