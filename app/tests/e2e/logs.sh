#!/usr/bin/env bash
# S3 phase 7 (A-SEC-5): no document or query text in logs, errors or history. Unique sentinels travel through the whole
# pipeline (a document's text and file name, search queries, hostile and corrupt files, erasure); then every sink is
# searched. Every sink also gets a CONTROL line that must be found, so the search cannot pass by silence.
# Scope: this app's sinks. Files are placed with `docker cp` + files:scan, not WebDAV, because Nextcloud's own access log
# prints WebDAV URLs (file names) and the app's future search endpoint must be POST-only for the same reason.
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

R=$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')
S_DOC="SENTDOC${R}"; S_NAME="SENTNAME${R}"; S_QUERY="SENTQUERY${R}"; S_BAD="SENTBAD${R}"; S_ERR="SENTERR${R}"

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym, aps_search.reconcile_run, aps_search.reconcile_node RESTART IDENTITY"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null
$OCC user:delete lg_a >/dev/null 2>&1 || true
$C exec -T --user www-data -e OC_PASS=lg-pass-12345 nextcloud php occ user:add --password-from-env lg_a >/dev/null
JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
WORKLOG=$(mktemp /tmp/bd-s1-lg-work.XXXXXX); TMP=$(mktemp -d /tmp/bd-s1-lg.XXXXXX); trap 'rm -rf "$TMP" "$WORKLOG"' EXIT
tick() { $OCC background-job:execute "$JOB" --force-execute >/dev/null; }
work() { for _ in 1 2 3 4; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >>"$WORKLOG" 2>&1 || true; done; }

# documents: text and name carry sentinels; one hostile (zip bomb) and one corrupt file carry sentinels too
printf 'Informe sobre hemoglobina %s con datos del paciente\n' "$S_DOC" > "$TMP/informe-$S_NAME.txt"
docker run --rm --user root -v "$TMP":/out --entrypoint python3 bd-s1-worker -c "
import sys; sys.path.insert(0, 'tests')
import fixtures as fx, pathlib
o = pathlib.Path('/out')
fx.zip_bomb_docx(o / 'bomba-$S_BAD.docx')
(o / 'roto-$S_ERR.docx').write_bytes(b'PK\x03\x04 $S_ERR truncated')
"
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/lg_a/files/Docs
for f in "$TMP"/*; do
	b=$(basename "$f"); $C cp "$f" "nextcloud:/var/www/html/data/lg_a/files/Docs/$b"
	$C exec -T nextcloud chown www-data:www-data "/var/www/html/data/lg_a/files/Docs/$b"
done
$OCC files:scan lg_a >/dev/null
DOCS=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::lg_a' and f.path='files/Docs'")
$OCC buscador:scope:add --folder-id="$DOCS" >/dev/null
$PSQL -c "insert into aps_search.outbox (file_id, kind) select f.fileid, 'file' from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::lg_a' and f.path like 'files/Docs/%' on conflict do nothing"
tick; work; work
N=$($PSQL -c "select count(*) from aps_search.file_state where text_state='indexed'")
[ "$N" -ge 1 ] || die "the document was not indexed"
SK=$($PSQL -c "select string_agg(skip_reason, ',' order by skip_reason) from aps_search.file_state where text_state='skipped'")
[ "$SK" = "corrupt,unsafe" ] || die "hostile/corrupt files should be skipped (got '$SK')"
ok "indexed 1 document; the hostile and corrupt files were skipped (unsafe, corrupt)"

# searches: one that matches by the document's sentinel, one that matches nothing
$CTL search lg_a "$S_DOC" | python3 -c 'import json,sys; assert len(json.load(sys.stdin)["hits"]) == 1' || die "the document is not found"
$CTL search lg_a "$S_QUERY" >/dev/null
ok "searched by the document's sentinel and by one that matches nothing"
# erasure path: trash it
$C exec -T --user www-data nextcloud rm "/var/www/html/data/lg_a/files/Docs/informe-$S_NAME.txt"
$OCC files:scan lg_a >/dev/null; tick
ok "the document was removed"

# controls: a line deliberately written into every sink we read
$C exec -T nextcloud sh -c 'echo CONTROL-NEXTCLOUD-STDOUT > /proc/1/fd/1'
$C exec -T nextcloud sh -c 'echo CONTROL-NEXTCLOUD-LOGFILE >> /var/www/html/data/nextcloud.log'
$C exec -T parser sh -c 'echo CONTROL-PARSER-STDOUT > /proc/1/fd/1' 2>/dev/null || $C --profile worker exec -T parser sh -c 'echo CONTROL-PARSER-STDOUT > /proc/1/fd/1'
$PSQL -c "do \$\$ begin raise warning 'CONTROL-DB-LOG'; end \$\$" >/dev/null 2>&1
ALL=$(mktemp /tmp/bd-s1-lg-all.XXXXXX); trap 'rm -rf "$TMP" "$WORKLOG" "$ALL"' EXIT
{ $C --profile worker logs --no-color 2>&1; $C exec -T nextcloud cat /var/www/html/data/nextcloud.log; cat "$WORKLOG"; } > "$ALL"
for c in CONTROL-NEXTCLOUD-STDOUT CONTROL-NEXTCLOUD-LOGFILE CONTROL-PARSER-STDOUT CONTROL-DB-LOG; do
	grep -q "$c" "$ALL" || die "control '$c' was not found: that sink is not being read, so a clean result would mean nothing"
done
grep -q "indexed" "$WORKLOG" || die "the worker output is empty: nothing to check"
ok "every sink was read: controls found in Nextcloud stdout and log file, parser, database and worker output ($(wc -l < "$ALL") lines)"
for s in "$S_DOC" "$S_NAME" "$S_QUERY" "$S_BAD" "$S_ERR"; do
	if grep -q "$s" "$ALL"; then die "sentinel $s leaked: $(grep -m2 "$s" "$ALL" | cut -c1-200)"; fi
done
ok "none of the five sentinels (document text, file name, query, hostile name, corrupt content) appears in any log"
# the history too: the audit log and the status output hold no names
if $PSQL -c "select actor || coalesce(subject_token,'') || detail::text from aps_search.audit_log" | grep -q "SENT"; then die "the audit log holds a sentinel"; fi
if $OCC buscador:status | grep -q "SENT"; then die "status prints a sentinel"; fi
ok "the audit log and the status output are clean too"
echo "logs OK ($PASS checks)"
