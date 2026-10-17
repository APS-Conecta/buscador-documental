#!/usr/bin/env bash
# S1 permission canary (A-SEC-1..3, M2, M4, M5, D1, M3/M7 carry). One scanned PDF owned by A goes through the real
# worker; every check asserts on the FULL search JSON. Every ctl call is its own PHP process on purpose.
# Runs against the bd-s1 stack only. Fresh canary users on every run (deleted first, which drops their shares).
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
CTL="$C exec -T --user www-data nextcloud php $APP/tests/canary/ctl.php"
ENQ="$C exec -T --user www-data nextcloud php $APP/tests/canary/enqueue.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
QUERY=hemoglobina
PASS=0

ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }
# check <label> <python expression over j=search json>
search() { $CTL search "$1" "$QUERY"; }
check() { # check "<json>" "<label>" "<python boolean expr on j>"
	python3 - "$1" "$3" <<'PY' || die "$2 :: $1"
import json, os, sys
j = json.loads(sys.argv[1])
FID = int(os.environ['FID'])
sys.exit(0 if eval(sys.argv[2]) else 1)
PY
	ok "$2"
}
principals() { $PSQL -c "select array_to_string(principals, ',') from aps_search.file_acl where file_id=$FID"; }
counts() { $PSQL -c "select (select count(*) from aps_search.page where file_id=$FID) || '/' || (select count(*) from aps_search.chunk where file_id=$FID)"; }
index_it() {
	$ENQ enqueue "$FID" | grep -q enqueued || die "enqueue refused"
	$C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null
	[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$FID")" = indexed ] || die "file not indexed"
}
FULL='sorted(j["hits"][0].keys()) == ["file_id","heading","page_no","snippet","title"] and j["hits"][0]["file_id"] == FID and "<mark>" in j["hits"][0]["snippet"] and j["hits"][0]["title"] == "scan.pdf"'

echo "== setup"
$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
for u in canary_a canary_b canary_c; do $OCC user:delete "$u" >/dev/null 2>&1 || true; done
# A public tag left by another suite (the events suite creates one) must go BEFORE setup reuses or creates the restricted one.
$PSQL -c "delete from oc_systemtag where name='rag-excluded' and visibility=1" >/dev/null   # leftover duplicate from an aborted run
$CTL setup
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl RESTART IDENTITY"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
OUT=$(mktemp -d /tmp/bd-s1-canary.XXXXXX)
docker run --rm --user root -v "$OUT":/out --entrypoint python3 bd-s1-worker make_scan.py /out/scan.pdf
H=/var/www/html/data/canary_a/files/top/mid
$C exec -T --user www-data nextcloud mkdir -p "$H"
$C cp "$OUT/scan.pdf" nextcloud:$H/scan.pdf
$C exec -T nextcloud chown -R www-data:www-data /var/www/html/data/canary_a
$OCC files:scan canary_a >/dev/null
rm -rf "$OUT"
FID=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::canary_a' and f.path='files/top/mid/scan.pdf'")
export FID
index_it
echo "   file id $FID"

echo "== 1. baseline (A-SEC-1)"
check "$(search canary_a)" "A finds it with the full hit shape" "$FULL"
check "$(search canary_b)" "B does not (prefilter never matches, nothing dropped)" 'j["hits"] == [] and j["dropped"] == 0 and j["status"] == "ok"'

echo "== 2. share, then revoke without touching file_acl (A-SEC-2, M4)"
$CTL share canary_a top/mid user canary_b
$CTL acl "$FID" >/dev/null
check "$(search canary_b)" "B finds it through the share" "$FULL"
$CTL unshare canary_a top/mid canary_b
principals | grep -q 'u:canary_b' || die "file_acl lost u:canary_b"
ok "stored principals still contain u:canary_b"
check "$(search canary_b)" "B no longer finds it: SQL returned the id, recheck dropped it" 'j["hits"] == [] and j["dropped"] == 1'

echo "== 4. download-disabled share (M2)"
$CTL share canary_a top/mid/scan.pdf user canary_c nodownload
$CTL acl "$FID" >/dev/null
check "$(search canary_c)" "C gets the title only, full JSON" 'j["hits"] == [{"file_id": FID, "title": "scan.pdf"}] and j["dropped"] == 0'
$CTL unshare canary_a top/mid/scan.pdf canary_c

echo "== 6. removed from the group (M3/M7 carry)"
$CTL share canary_a top/mid group canary_team
$CTL group add canary_team canary_b
$CTL acl "$FID" >/dev/null
check "$(search canary_b)" "B finds it through the group" "$FULL"
$CTL group remove canary_team canary_b
principals | grep -q 'g:canary_team' || die "file_acl lost g:canary_team"
check "$(search canary_b)" "B no longer finds it after leaving the group (stored g:canary_team stays, B's own set shrinks)" 'j["hits"] == [] and j["dropped"] == 0'
$CTL unshare canary_a top/mid canary_team

echo "== 5. D1: B reaches the file below the owner's tagged parent"
$CTL share canary_a top/mid user canary_b
$CTL acl "$FID" >/dev/null
check "$(search canary_b)" "B finds it before the tag" "$FULL"
$CTL tag canary_a top
check "$(search canary_b)" "tag on A's parent folder above B's mount: hit absent" 'j["hits"] == [] and j["dropped"] == 1'
check "$(search canary_a)" "...and absent for the owner too" 'j["hits"] == [] and j["dropped"] == 1'
$CTL untag canary_a top
check "$(search canary_b)" "tag removed: hit back" "$FULL"
$CTL unshare canary_a top/mid canary_b

echo "== 3. exclusion tag and eraser (A-SEC-3, M5)"
$CTL tag canary_a top/mid/scan.pdf
check "$(search canary_a)" "tag on the file: hit absent immediately" 'j["hits"] == [] and j["dropped"] == 1'
$CTL untag canary_a top/mid/scan.pdf
$PSQL -c "insert into oc_systemtag (name, visibility, editable, etag, color) values ('rag-excluded', 1, 1, 'dup', null)" >/dev/null
DUP=$($PSQL -c "select max(id) from oc_systemtag where name='rag-excluded'")
$PSQL -c "insert into oc_systemtag_object_mapping (objectid, objecttype, systemtagid) values ('$FID', 'files', $DUP)" >/dev/null
check "$(search canary_a)" "duplicate public-flag tag alone excludes the file" 'j["hits"] == [] and j["dropped"] == 1'
$PSQL -c "delete from oc_systemtag_object_mapping where systemtagid=$DUP" >/dev/null
$PSQL -c "delete from oc_systemtag where id=$DUP" >/dev/null
check "$(search canary_a)" "duplicate removed: hit back" "$FULL"

$CTL tag canary_a top/mid/scan.pdf
$CTL erase "$FID" excluded
[ "$(counts)" = "0/0" ] || die "page/chunk rows left after erase: $(counts)"
ok "eraser: page/chunk count is 0 for the file"
R=$($ENQ enqueue "$FID" || true)
[ "$R" = refused ] || die "enqueue accepted a tagged file: $R"
ok "enqueue refuses a file that is already excluded (D4)"
$CTL untag canary_a top/mid/scan.pdf

index_it
check "$(search canary_a)" "re-indexed after the tag was lifted" "$FULL"
$CTL tag canary_a top
check "$(search canary_a)" "tag on the parent folder: hit absent immediately" 'j["hits"] == [] and j["dropped"] == 1'
$CTL erase "$FID" excluded
[ "$(counts)" = "0/0" ] || die "page/chunk rows left after erase (parent tag): $(counts)"
ok "eraser with the tag on the parent folder: count is 0"
$CTL untag canary_a top

echo "canary OK ($PASS checks)"
