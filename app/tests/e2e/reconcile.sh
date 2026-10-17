#!/usr/bin/env bash
# S3 phase 6, live (A-OPS-3): every change below loses its events (the outbox is truncated right after), and the nightly
# reconciliation job, run through `occ background-job:execute`, repairs the index anyway. Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
CTL="$C exec -T --user www-data nextcloud php $APP/tests/canary/ctl.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
DAV=http://127.0.0.1:8191/remote.php/dav
AUTH="-u rc_a:rc-pass-12345"
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.scope_rule, aps_search.audit_log, aps_search.settings, aps_search.pseudonym, aps_search.reconcile_run, aps_search.reconcile_node RESTART IDENTITY"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
for id in $($PSQL -c "select id from oc_systemtag where name = 'rag-excluded'"); do $OCC tag:delete "$id" >/dev/null; done
$OCC tag:add rag-excluded public >/dev/null
$OCC user:delete rc_a >/dev/null 2>&1 || true
$C exec -T --user www-data -e OC_PASS=rc-pass-12345 nextcloud php occ user:add --password-from-env rc_a >/dev/null
$PSQL -c "insert into aps_search.settings (key, value) values ('reconcile_window', '00:00-00:00')"      # the whole day, so the test can run any time

job() { $OCC background-job:list --class="$1" --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])'; }
OUTBOX_JOB=$(job 'OCA\BuscadorDocumental\Job\ProcessOutbox'); RECON_JOB=$(job 'OCA\BuscadorDocumental\Job\Reconcile')
tick() { $OCC background-job:execute "$OUTBOX_JOB" --force-execute >/dev/null; }
lose_events() { $PSQL -c "truncate aps_search.outbox" >/dev/null; }
reconcile() {   # one full nightly run: make it "due" (not yet done today) and execute the job until the run is finished
	$PSQL -c "update aps_search.reconcile_run set finished_at = finished_at - interval '2 days' where finished_at is not null" >/dev/null
	for _ in 1 2 3 4 5 6; do
		$OCC background-job:execute "$RECON_JOB" --force-execute >/dev/null
		[ "$($PSQL -c "select count(*) from aps_search.reconcile_run where finished_at is null")" = 0 ] && return 0
	done
	die "the reconciliation did not finish"
}
work() { for _ in 1 2 3 4; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null; done; }
fid() { $PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id = f.storage where s.id = 'home::rc_a' and f.path = 'files/$1'"; }
state() { $PSQL -c "select coalesce(text_state || ':' || coalesce(skip_reason, ''), 'none') from (select 1) x left join aps_search.file_state s on s.file_id = $1"; }
chunks() { $PSQL -c "select count(*) from aps_search.chunk where file_id = $1"; }
found() { $CTL search rc_a "$1" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)["hits"]))'; }
put() { curl -s -o /dev/null -w '%{http_code}' $AUTH -T "$2" "$DAV/files/rc_a/$1"; }

TMP=$(mktemp -d /tmp/bd-s1-rc.XXXXXX); trap 'rm -rf "$TMP"' EXIT
curl -s -o /dev/null $AUTH -X MKCOL "$DAV/files/rc_a/Docs"; curl -s -o /dev/null $AUTH -X MKCOL "$DAV/files/rc_a/Otros"
$OCC files:scan rc_a >/dev/null
$OCC buscador:scope:add --folder-id="$(fid Docs)" >/dev/null
echo "Informe sobre hemoglobina glicosilada" > "$TMP/a.txt"; echo "Acta sobre nutricion infantil" > "$TMP/b.txt"

echo "== 1. files created, events lost"
put Docs/a.txt "$TMP/a.txt" >/dev/null; put Docs/b.txt "$TMP/b.txt" >/dev/null
A=$(fid Docs/a.txt); B=$(fid Docs/b.txt)
lose_events
[ "$(state "$A")" = none ] || die "nothing should be known yet"
reconcile; tick; work
[ "$(state "$A")" = "indexed:" ] && [ "$(state "$B")" = "indexed:" ] && [ "$(found hemoglobina)" = 1 ] || die "missed creations not repaired: $(state "$A") $(state "$B")"
ok "files whose creation events were lost are found and indexed"

echo "== 2. file edited, event lost"
echo "Informe revisado sobre vacunacion invierno" > "$TMP/a.txt"; put Docs/a.txt "$TMP/a.txt" >/dev/null
lose_events
reconcile; tick; work
[ "$(found vacunacion)" = 1 ] && [ "$(found hemoglobina)" = 0 ] || die "edit not repaired"
ok "an edit whose event was lost is re-read"

echo "== 3. file moved out of the rule, event lost"
curl -s -o /dev/null $AUTH -X MOVE -H "Destination: $DAV/files/rc_a/Otros/b.txt" "$DAV/files/rc_a/Docs/b.txt"
lose_events
reconcile; tick
[ "$(state "$B")" = "skipped:out_of_scope" ] && [ "$(chunks "$B")" = 0 ] && [ "$(found nutricion)" = 0 ] || die "move not repaired: $(state "$B")"
ok "a move out of the rule whose event was lost is erased"

echo "== 4. file removed from Nextcloud behind its back (no events at all)"
$PSQL -c "delete from oc_filecache where fileid = $A" >/dev/null
lose_events
[ "$(state "$A")" = "indexed:" ] || die "precondition"
reconcile; tick
[ "$(state "$A")" = none ] && [ "$(chunks "$A")" = 0 ] || die "gone file not purged: $(state "$A")"
[ "$($PSQL -c "select count(*) from aps_search.page where file_id = $A")" = 0 ] || die "pages remain"
ok "a file that vanished without any event is purged (the user-deletion residue case)"

echo "== 5. the outbox settles and the run is recorded"
tick
[ "$($PSQL -c "select count(*) from aps_search.outbox where processed_at is null and attempts < 5")" = 0 ] || die "rows waiting"
$OCC buscador:status | grep -q "last reconciliation: 20" || die "status does not show the last run"
COUNTS=$($PSQL -c "select counts::text from aps_search.reconcile_run where finished_at is not null order by run_id desc limit 1")
grep -q '"forward"' <<<"$COUNTS" && grep -q '"reverse"' <<<"$COUNTS" || die "counts missing: $COUNTS"
ok "the last run's counts are recorded and shown by status"
echo "reconcile OK ($PASS checks)"
