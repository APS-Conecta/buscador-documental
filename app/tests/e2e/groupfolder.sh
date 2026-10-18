#!/usr/bin/env bash
# Team folders (groupfolders >= 4: own storage, files under `files/`): a file in a team folder carries the folder's group
# principals, so a member finds it and a non-member does not (and the prefilter, not only the recheck, excludes them).
# Found by S3 research: S1's detector looked for `__groupfolders/<id>/` in the path and never matched on NC 34.
# Runs against the bd-s1 stack only. Needs network once, for `occ app:install groupfolders`.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
CTL="$C exec -T --user www-data nextcloud php $APP/tests/canary/ctl.php"
ENQ="$C exec -T --user www-data nextcloud php $APP/tests/canary/enqueue.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$C exec -T nextcloud chown www-data:www-data /var/www/html/custom_apps
$OCC app:list 2>/dev/null | grep -q "groupfolders" || $OCC app:install groupfolders >/dev/null
$OCC app:enable groupfolders >/dev/null 2>&1 || true
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl RESTART IDENTITY CASCADE"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null

for u in gf_member gf_outsider; do $OCC user:delete "$u" >/dev/null 2>&1 || true; done
$OCC group:delete gf_team >/dev/null 2>&1 || true
$OCC group:add gf_team >/dev/null
$C exec -T --user www-data -e OC_PASS=gf-pass-12345 nextcloud php occ user:add --password-from-env --group=gf_team gf_member >/dev/null
$C exec -T --user www-data -e OC_PASS=gf-pass-12345 nextcloud php occ user:add --password-from-env gf_outsider >/dev/null
for old in $($OCC groupfolders:list --output=json | python3 -c 'import json,sys; print(*[f["id"] for f in json.load(sys.stdin) if f["mountPoint"] == "gf_team_folder"])'); do
	$OCC groupfolders:delete "$old" --force >/dev/null       # a leftover from an aborted run
done
FOLDER=$($OCC groupfolders:create gf_team_folder | tail -1)
$OCC groupfolders:group "$FOLDER" gf_team write >/dev/null

TMP=$(mktemp -d /tmp/bd-s1-gf.XXXXXX); trap 'rm -rf "$TMP"' EXIT
echo "Acta del equipo sobre hemoglobina glicosilada en pacientes" > "$TMP/acta.txt"
code=$(curl -s -o /dev/null -w '%{http_code}' -u gf_member:gf-pass-12345 -T "$TMP/acta.txt" "http://127.0.0.1:8191/remote.php/dav/files/gf_member/gf_team_folder/acta.txt")
[ "$code" = 201 ] || die "upload failed ($code)"
$OCC files:scan --all >/dev/null 2>&1
FID=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id like '%__groupfolders/$FOLDER/' and f.path='files/acta.txt'")
[ -n "$FID" ] || die "file not found in the team folder storage"
STORAGE=$($PSQL -c "select s.id from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.fileid=$FID")
ok "file $FID lives on $STORAGE with path files/acta.txt (not under __groupfolders/ in the path)"

$ENQ ensure-tag >/dev/null
$ENQ enqueue "$FID" | grep -q enqueued || die "enqueue refused"
$C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$FID")" = indexed ] || die "file not indexed"

PR=$($PSQL -c "select array_to_string(principals, ',') from aps_search.file_acl where file_id=$FID")
[ "$PR" = "g:gf_team" ] || die "stored principals are '$PR', expected exactly g:gf_team"
ok "stored principals are exactly g:gf_team (a team folder has no owner)"

M=$($CTL search gf_member hemoglobina)
python3 - "$M" "$FID" <<'PY' || die "member search :: $M"
import json, sys
j = json.loads(sys.argv[1]); fid = int(sys.argv[2])
sys.exit(0 if j["status"] == "ok" and [h["file_id"] for h in j["hits"]] == [fid] and j["dropped"] == 0 else 1)
PY
ok "a member finds it"

O=$($CTL search gf_outsider hemoglobina)
python3 - "$O" <<'PY' || die "outsider search :: $O"
import json, sys
j = json.loads(sys.argv[1])
sys.exit(0 if j["status"] == "ok" and j["hits"] == [] and j["dropped"] == 0 else 1)
PY
ok "an outsider does not, and the prefilter excluded them before the recheck (dropped 0)"
echo "groupfolder OK ($PASS checks)"
