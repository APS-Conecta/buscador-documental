#!/usr/bin/env bash
# S3 phase 7 (A-SEC-1): the adversarial permission matrix on the live stack, with the REAL mechanisms: circles (Teams),
# a team folder granted only to a Team, team-folder advanced ACL deny, share expiry, a file moved out of a shared folder
# (stale index), a file reachable twice (multi-mount), an owner who is deleted. Shares go through the OCS HTTP API so
# Nextcloud's own events fire. Every check asserts on the FULL search JSON (status, hit shape, dropped), like the canary.
# Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
CTL="$C exec -T --user www-data nextcloud php $APP/tests/canary/ctl.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
PW=rb-pass-12345
BASE=http://127.0.0.1:8191
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$C exec -T nextcloud chown www-data:www-data /var/www/html/custom_apps
$OCC app:list 2>/dev/null | grep -q groupfolders || $OCC app:install groupfolders >/dev/null
$OCC app:enable groupfolders >/dev/null 2>&1 || true
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym, aps_search.reconcile_run, aps_search.reconcile_node RESTART IDENTITY CASCADE"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null

for u in rb_a rb_b rb_c rb_d rb_m1 rb_m2 rb_x; do $OCC user:delete "$u" >/dev/null 2>&1 || true; done
$OCC group:delete rb_grp >/dev/null 2>&1 || true; $OCC group:delete rb_staff >/dev/null 2>&1 || true
$OCC group:add rb_grp >/dev/null; $OCC group:add rb_staff >/dev/null
mk() { $C exec -T --user www-data -e OC_PASS=$PW nextcloud php occ user:add --password-from-env ${2:+--group=$2} "$1" >/dev/null; }
mk rb_a; mk rb_b rb_grp; mk rb_c; mk rb_d; mk rb_m1 rb_staff; mk rb_m2 rb_staff; mk rb_x
for id in $($OCC circles:manage:list --output=json | python3 -c 'import json,sys; [print(c["id"]) for c in json.load(sys.stdin) if c["name"]=="rbteam"]'); do $OCC circles:manage:destroy "$id" >/dev/null 2>&1 || true; done
CIRCLE=$($OCC circles:manage:create rb_a rbteam --type=user --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
$OCC circles:members:add "$CIRCLE" rb_b --type=1 >/dev/null || $OCC circles:members:add "$CIRCLE" rb_b >/dev/null

OUTBOX_JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
POLL_JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\GroupfolderPoll' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
tick() { $OCC background-job:execute "$OUTBOX_JOB" --force-execute >/dev/null; }
poll() { $OCC background-job:execute "$POLL_JOB" --force-execute >/dev/null; }
work() {   # run the worker until no job is waiting (one job per run), with a safety bound
	for _ in $(seq 1 40); do
		[ "$($PSQL -c "select count(*) from aps_search.job where lease_until is null or lease_until <= clock_timestamp()")" = 0 ] && return 0
		$C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null 2>&1 || true
	done
}
dav() { local u=$1; shift; curl -s -o /dev/null -w '%{http_code}' -u "$u:$PW" "$@"; }
ocs() { local u=$1; shift; curl -s -u "$u:$PW" -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$@"; }
share() { # share <owner> <path> <type> <with> [extra form data] -> share id
	ocs "$1" -X POST "$BASE/ocs/v2.php/apps/files_sharing/api/v1/shares" -d "path=$2" -d "shareType=$3" --data-urlencode "shareWith=$4" -d "permissions=1" ${5:+-d "$5"} | python3 -c 'import json,sys; print(json.load(sys.stdin)["ocs"]["data"]["id"])'
}
unshare() { ocs "$1" -X DELETE "$BASE/ocs/v2.php/apps/files_sharing/api/v1/shares/$2" >/dev/null; }
fid() { $PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id = f.storage where s.id = '$1' and f.path = '$2'"; }
principals() { $PSQL -c "select coalesce(array_to_string(principals, ','), '-') from (select 1) x left join aps_search.file_acl a on a.file_id = $1"; }
search() { $CTL search "$1" "$2"; }
check() { # check "<json>" "<label>" "<python boolean expr on j>"
	python3 - "$1" "$3" <<'PY' || die "$2 :: $1"
import json, sys
j = json.loads(sys.argv[1])
sys.exit(0 if j["status"] == "ok" and eval(sys.argv[2]) else 1)
PY
	ok "$2"
}
HITS1='len(j["hits"]) == 1 and sorted(j["hits"][0]) == ["file_id","heading","page_no","snippet","title"] and j["dropped"] == 0'
GONE_RECHECK='j["hits"] == [] and j["dropped"] == 1'      # the prefilter returned it and the live recheck dropped it
GONE_PREFILTER='j["hits"] == [] and j["dropped"] == 0'    # the prefilter never returned it

TMP=$(mktemp -d /tmp/bd-s1-rb.XXXXXX); trap 'rm -rf "$TMP"' EXIT
note() { printf '%s\n' "$2" > "$TMP/$1"; }
note circ.txt "Documento circulo hemoglobina"; note exp.txt "Documento caduco hemoglobina"; note mv.txt "Documento movido hemoglobina"
note mm.txt "Documento multiple hemoglobina"; note open.txt "Acta abierto hemoglobina"; note sec.txt "Acta confidencial hemoglobina"; note orf.txt "Documento huerfano hemoglobina"
note team.txt "Documento equipo hemoglobina"
for d in Docs Shared Priv; do dav rb_a -X MKCOL "$BASE/remote.php/dav/files/rb_a/$d" >/dev/null; done
dav rb_d -X MKCOL "$BASE/remote.php/dav/files/rb_d/Docs" >/dev/null
$OCC files:scan --all >/dev/null
for f in circ exp mm; do dav rb_a -T "$TMP/$f.txt" "$BASE/remote.php/dav/files/rb_a/Docs/$f.txt" >/dev/null; done
dav rb_a -T "$TMP/mv.txt" "$BASE/remote.php/dav/files/rb_a/Shared/mv.txt" >/dev/null
dav rb_d -T "$TMP/orf.txt" "$BASE/remote.php/dav/files/rb_d/Docs/orf.txt" >/dev/null
# rules on the test folders only: a new user also gets ~50 default files in the root, which must not queue ahead of ours
for f in Docs Shared Priv; do $OCC buscador:scope:add --folder-id="$(fid home::rb_a files/$f)" >/dev/null; done
$OCC buscador:scope:add --folder-id="$(fid home::rb_d files/Docs)" >/dev/null
# team folder with advanced permissions, enabled BEFORE indexing so the stored principals carry x:recheck
$OCC groupfolders:list --output=json | python3 -c 'import json,sys; [print(f["id"]) for f in json.load(sys.stdin) if f["mountPoint"] in ("rb_team", "rb_teamc")]' | while read -r old; do $OCC groupfolders:delete "$old" --force >/dev/null; done
TF=$($OCC groupfolders:create rb_team | tail -1)
$OCC groupfolders:group "$TF" rb_staff write >/dev/null
$OCC groupfolders:permissions "$TF" --enable >/dev/null
# team folder granted only to the Team, without advanced permissions: groupfolders stores group_id '' and the Team in circle_id
TF2=$($OCC groupfolders:create rb_teamc | tail -1)
$OCC groupfolders:group "$TF2" "$CIRCLE" write >/dev/null
$OCC buscador:scope:add --groupfolders >/dev/null
dav rb_m1 -X MKCOL "$BASE/remote.php/dav/files/rb_m1/rb_team/Open" >/dev/null; dav rb_m1 -X MKCOL "$BASE/remote.php/dav/files/rb_m1/rb_team/Secret" >/dev/null
dav rb_m1 -T "$TMP/open.txt" "$BASE/remote.php/dav/files/rb_m1/rb_team/Open/open.txt" >/dev/null
dav rb_m1 -T "$TMP/sec.txt" "$BASE/remote.php/dav/files/rb_m1/rb_team/Secret/sec.txt" >/dev/null
$OCC groupfolders:permissions "$TF" Secret -u rb_m2 -- -read >/dev/null || die "could not set the ACL deny"
[ "$(dav rb_a -T "$TMP/team.txt" "$BASE/remote.php/dav/files/rb_a/rb_teamc/team.txt")" = 201 ] || die "a Team member could not upload to the Team-only team folder"

echo "== 1. circle (Teams): a share nobody models in file_acl"
CIRC=$(fid home::rb_a files/Docs/circ.txt)
share rb_a /Docs/circ.txt 7 "$CIRCLE" >/dev/null
tick; work
[ "$(principals "$CIRC")" = "u:rb_a,x:recheck" ] || die "circle share principals: $(principals "$CIRC")"
ok "a circle share stores the catch-all marker (u:rb_a,x:recheck)"
check "$(search rb_b circulo)" "a circle member finds it" "$HITS1"
check "$(search rb_c circulo)" "a non-member does not: the prefilter let it through, the live recheck dropped it" "$GONE_RECHECK"
check "$(search rb_a circulo)" "the owner finds it" "$HITS1"

echo "== 2. team folder with advanced ACL: deny and narrowing"
tick; work
SEC=$(fid "local::/var/www/html/data/__groupfolders/$TF/" files/Secret/sec.txt)
[ "$(principals "$SEC")" = "g:rb_staff,x:recheck" ] || die "team-folder principals: $(principals "$SEC")"
ok "team-folder files carry the group and the catch-all (advanced ACL on)"
check "$(search rb_m1 confidencial)" "a member with full access finds the Secret file" "$HITS1"
check "$(search rb_m2 confidencial)" "a member denied by an ACL rule does not (prefilter passes, recheck drops)" "$GONE_RECHECK"
check "$(search rb_m2 abierto)" "the same member finds the Open file" "$HITS1"
check "$(search rb_x confidencial)" "a user outside the group does not" "$GONE_RECHECK"
poll                                       # the first poll only records a baseline of grants and rules
$OCC groupfolders:permissions "$TF" Open -u rb_m1 -- -read >/dev/null
check "$(search rb_m1 abierto)" "ACL narrowed after indexing: the hit disappears at once (stale index)" "$GONE_RECHECK"
poll
[ "$($PSQL -c "select count(*) from aps_search.file_state where acl_dirty")" -ge 1 ] || die "the poll did not notice the ACL change"
ok "the team-folder poll noticed the rule change and marked the files for a principals refresh"

echo "== 3. share expiry"
EXP=$(fid home::rb_a files/Docs/exp.txt)
SID=$(share rb_a /Docs/exp.txt 0 rb_b)
tick; work
check "$(search rb_b caduco)" "a user share is found" "$HITS1"
$PSQL -c "update oc_share set expiration = now() - interval '1 day' where id = $SID" >/dev/null
check "$(search rb_b caduco)" "after the share expires it is not (recheck drops it)" "$GONE_RECHECK"

echo "== 4. moved out of a shared folder (stale index)"
MV=$(fid home::rb_a files/Shared/mv.txt)
SH=$(share rb_a /Shared 0 rb_b)
tick; work
[ "$(principals "$MV")" = "u:rb_a,u:rb_b" ] || die "folder-share principals: $(principals "$MV")"
check "$(search rb_b movido)" "a file inside a shared folder is found" "$HITS1"
[ "$(dav rb_a -X MOVE -H "Destination: $BASE/remote.php/dav/files/rb_a/Priv/mv.txt" "$BASE/remote.php/dav/files/rb_a/Shared/mv.txt")" = 201 ] || die "move"
check "$(search rb_b movido)" "moved out, BEFORE any reindex: gone at once (stale prefilter, live recheck)" "$GONE_RECHECK"
tick; work
[ "$(principals "$MV")" = "u:rb_a" ] || die "principals after the move: $(principals "$MV")"
check "$(search rb_b movido)" "after reprocessing the prefilter no longer returns it" "$GONE_PREFILTER"
unshare rb_a "$SH"

echo "== 5. multi-mount: reachable through a user share AND a group share"
MM=$(fid home::rb_a files/Docs/mm.txt)
U=$(share rb_a /Docs/mm.txt 0 rb_b); G=$(share rb_a /Docs/mm.txt 1 rb_grp)
tick; work
check "$(search rb_b multiple)" "found through two shares" "$HITS1"
unshare rb_a "$U"; tick
check "$(search rb_b multiple)" "user share removed: still found through the group share" "$HITS1"
unshare rb_a "$G"; tick
check "$(search rb_b multiple)" "both removed: gone, and the stored principals already say so (nothing left to drop)" "$GONE_PREFILTER"
[ "$(principals "$MM")" = "u:rb_a" ] || die "principals after both shares were removed: $(principals "$MM")"

echo "== 6. the owner is deleted"
ORF=$(fid home::rb_d files/Docs/orf.txt)
share rb_d /Docs/orf.txt 0 rb_b >/dev/null
tick; work
check "$(search rb_b huerfano)" "shared by rb_d: found" "$HITS1"
$OCC user:delete rb_d >/dev/null
check "$(search rb_b huerfano)" "owner deleted: gone, and nothing left to drop" "$GONE_PREFILTER"
[ "$($PSQL -c "select count(*) from aps_search.chunk where file_id = $ORF")" = 0 ] || die "text of a deleted owner's file remains"
ok "no text of the deleted owner's file remains in the index"

echo "== 7. team folder granted only to a Team: no group to model"
tick; work
TEAM=$(fid "local::/var/www/html/data/__groupfolders/$TF2/" files/team.txt)
[ "$(principals "$TEAM")" = "x:recheck" ] || die "Team-only team-folder principals: $(principals "$TEAM")"
ok "a Team-only team folder stores only the catch-all marker (x:recheck), no empty group"
check "$(search rb_b equipo)" "a Team member finds it" "$HITS1"
check "$(search rb_c equipo)" "a non-member does not: the prefilter let it through, the live recheck dropped it" "$GONE_RECHECK"
echo "rbac OK ($PASS checks)"
