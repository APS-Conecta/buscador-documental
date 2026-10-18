#!/usr/bin/env bash
# S3 phase 2, live: the `occ buscador:scope:*` commands against the real stack and database.
# Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }
expect() { # expect <label> <substring> <command...>: the command's combined output contains the substring
	local label=$1 want=$2; shift 2
	local out; out=$("$@" 2>&1) || true
	grep -qF -- "$want" <<<"$out" || die "$label :: wanted '$want' in: $out"
	ok "$label"
}

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$PSQL -c "TRUNCATE aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym RESTART IDENTITY CASCADE"
$C exec -T --user www-data -e OC_PASS=scope-pass-12345 nextcloud php occ user:add --password-from-env scope_a >/dev/null 2>&1 || true
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/scope_a/files/Informes/2026
$C exec -T --user www-data nextcloud bash -c 'echo hola > /var/www/html/data/scope_a/files/Informes/2026/a.txt; echo chao > /var/www/html/data/scope_a/files/suelto.txt'
$OCC files:scan scope_a >/dev/null

expect "list: empty, opt_in" "rules: none" $OCC buscador:scope:list
expect "preview with no rules indexes nothing" "files: 0" $OCC buscador:scope:preview
expect "refuses two targets at once" "exactly one" $OCC buscador:scope:add --folder-id=1 --groupfolders
expect "refuses an unknown folder id" "No such folder" $OCC buscador:scope:add --folder-id=99999999
PLAIN=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::scope_a' and f.path='files/suelto.txt'")
expect "refuses a plain file as a scope" "not a folder" $OCC buscador:scope:add --folder-id="$PLAIN"
STORE=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::scope_a' and f.path=''")
expect "refuses the storage root as a scope" "not a folder" $OCC buscador:scope:add --folder-id="$STORE"
expect "refuses a file as a folder" "not a folder" $OCC buscador:scope:add --path=scope_a:suelto.txt
expect "adds a folder by path" "folder " $OCC buscador:scope:add --path=scope_a:Informes
expect "adding it again is the same rule" "rule 1:" $OCC buscador:scope:add --path=scope_a:Informes
expect "preview counts the file below, not the sibling" "files: 1" $OCC buscador:scope:preview
expect "adds the team-folder rule" "all team folders" $OCC buscador:scope:add --groupfolders
expect "list shows both rules" "rule 2: all team folders" $OCC buscador:scope:list
expect "all_readable is refused without the legal gate" "counsel" $OCC buscador:scope:mode all_readable
expect "an unknown mode is refused" "Unknown scope mode" $OCC buscador:scope:mode everything
expect "the mode is still opt_in" "mode: opt_in" $OCC buscador:scope:list
expect "removing a rule works" "rule removed" $OCC buscador:scope:remove 2
expect "removing a missing rule fails" "No such rule" $OCC buscador:scope:remove 2

[ "$($PSQL -c "select string_agg(action, ',' order by id) from aps_search.audit_log")" = "scope.add,scope.add,scope.remove" ] || die "audit actions"
ok "every change is audited"
LOG=$($PSQL -c "select actor || ' ' || coalesce(subject_token,'') || ' ' || detail::text from aps_search.audit_log")
grep -qiE "Informes|scope_a|suelto|a\.txt" <<<"$LOG" && die "audit holds a name: $LOG"
ok "the audit holds no folder, file or user names"
echo "scope OK ($PASS checks)"
