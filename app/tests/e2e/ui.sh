#!/usr/bin/env bash
# S6 phase 9: the real browser against the real stack. Playwright (pinned image) drives the search page, the administration view
# and the Files tab: keyboard-only path, URL state, 360 px without horizontal scroll, reduced motion, high contrast, axe.
# Documents are placed with docker cp + files:scan and indexed by the worker, like the other live suites.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
PLAYWRIGHT=mcr.microsoft.com/playwright:v1.56.1-noble@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }

R=$(head -c 3 /dev/urandom | od -An -tx1 | tr -d ' \n')
WORD="vacunaxyz${R}"

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC app:disable firstrunwizard >/dev/null 2>&1 || true
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym, aps_search.reconcile_run, aps_search.reconcile_node RESTART IDENTITY CASCADE"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null
for u in ui_a ui_b; do
	$OCC user:delete $u >/dev/null 2>&1 || true
	$C exec -T --user www-data -e OC_PASS=ui-pass-12345 nextcloud php occ user:add --password-from-env $u >/dev/null
done
JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
WORKLOG=$(mktemp /tmp/bd-s1-ui-work.XXXXXX); TMP=$(mktemp -d /tmp/bd-s1-ui.XXXXXX); trap 'rm -rf "$TMP" "$WORKLOG"' EXIT
tick() { $OCC background-job:execute "$JOB" --force-execute >/dev/null; }
work() { for _ in 1 2 3 4; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >>"$WORKLOG" 2>&1 || true; done; }

for i in 1 2 3; do printf 'Circular %s: campaña de %s para adultos mayores.\nSegunda línea con hemoglobina.\n' "$i" "$WORD" > "$TMP/circular-$i.txt"; done
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/ui_a/files/Docs
for f in "$TMP"/*; do
	b=$(basename "$f"); $C cp "$f" "nextcloud:/var/www/html/data/ui_a/files/Docs/$b"
	$C exec -T nextcloud chown www-data:www-data "/var/www/html/data/ui_a/files/Docs/$b"
done
$OCC files:scan ui_a >/dev/null
DOCS=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::ui_a' and f.path='files/Docs'")
$OCC buscador:scope:add --folder-id="$DOCS" >/dev/null
$PSQL -c "insert into aps_search.outbox (file_id, kind) select f.fileid, 'file' from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::ui_a' and f.path like 'files/Docs/%' on conflict do nothing"
tick; work; work
N=$($PSQL -c "select count(*) from aps_search.file_state where text_state='indexed'")
[ "$N" -ge 3 ] || die "the documents were not indexed ($N)"
ok "three documents indexed for the browser to find"
FID=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::ui_a' and f.path='files/Docs/circular-1.txt'")

BASE=http://127.0.0.1:8191
rm -rf "$TMP/pw"; mkdir -p "$TMP/pw"; cp app/tests/ui/*.json app/tests/ui/*.mjs "$TMP/pw/"
docker run --rm --network host --user root -e BASE="$BASE" -e UI_WORD="$WORD" -e UI_FILE_URL="/apps/files/files/$FID?dir=/Docs&opendetails=true" \
	-v "$TMP/pw":/work -w /work "$PLAYWRIGHT" sh -c 'npm install --no-audit --no-fund --silent && npx playwright test' \
	|| die "the browser checks failed"
ok "browser checks passed"
echo "ui OK ($PASS checks)"
