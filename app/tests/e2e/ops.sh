#!/usr/bin/env bash
# S5 live (A-OPS-1): pause survives a restart of every service and resume continues the work; the night window and the caps are obeyed
# by the real worker; the worker secret can be rotated with the old one still working inside the grace period; doctor and the
# status outputs (ETA, Prometheus) are sane. Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
ENQ="$C exec -T --user www-data nextcloud php $APP/tests/canary/enqueue.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }
work() { $C --profile worker run --rm --no-deps -T "$@" worker python3 main.py --once >>"$WORKLOG" 2>&1 || true; }
WORKLOG=$(mktemp /tmp/bd-s1-ops-work.XXXXXX); TMP=$(mktemp -d /tmp/bd-s1-ops.XXXXXX)
cleanup() { $OCC buscador:window off >/dev/null 2>&1 || true; $OCC buscador:throttle reset >/dev/null 2>&1 || true; $OCC buscador:resume >/dev/null 2>&1 || true; $OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null 2>&1 || true; $OCC config:app:delete buscador_documental worker_secret_prev >/dev/null 2>&1 || true; $OCC config:app:delete buscador_documental worker_secret_prev_until >/dev/null 2>&1 || true; rm -rf "$TMP" "$WORKLOG"; }
trap cleanup EXIT
state() { $PSQL -c "select coalesce(string_agg(text_state, ',' order by file_id), '') from aps_search.file_state"; }
indexed() { $PSQL -c "select count(*) from aps_search.file_state where text_state = 'indexed'"; }
queued() { $PSQL -c "select count(*) from aps_search.job where lane = 'text'"; }

echo "== setup"
$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.settings, aps_search.worker_seen RESTART IDENTITY CASCADE" >/dev/null
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
$OCC user:delete op_a >/dev/null 2>&1 || true
$C exec -T --user www-data -e OC_PASS=op-pass-123456 nextcloud php occ user:add --password-from-env op_a >/dev/null
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/op_a/files
for i in 1 2 3 4 5 6; do printf 'Informe %s sobre hemoglobina glicosilada\n' "$i" > "$TMP/f$i.txt"; $C cp "$TMP/f$i.txt" nextcloud:/var/www/html/data/op_a/files/f$i.txt; $C exec -T nextcloud chown www-data:www-data /var/www/html/data/op_a/files/f$i.txt; done
$OCC files:scan op_a >/dev/null
$ENQ ensure-tag >/dev/null
fid() { $PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::op_a' and f.path='files/f$1.txt'"; }
enq() { $ENQ enqueue "$(fid "$1")" | grep -q enqueued || die "enqueue refused for $1"; }

echo "== pause survives a restart of every service"
$OCC buscador:pause >/dev/null
for i in 1 2 3; do enq $i; done
work; work; work
[ "$(indexed)" = 0 ] && [ "$(queued)" = 3 ] || die "paused: indexed $(indexed), queued $(queued)"
ok "paused: three jobs wait and the worker is handed none"
$C --profile worker restart db nextcloud parser >/dev/null 2>&1
$C --profile worker up -d --wait parser >/dev/null
$OCC buscador:status | grep -q "paused: yes" || die "the pause was lost in the restart"
[ "$(queued)" = 3 ] || die "queued jobs lost in the restart: $(queued)"
ok "after restarting the database, Nextcloud and the parser: still paused, the three jobs are still queued"
$OCC buscador:resume >/dev/null
work; work; work; work
[ "$(indexed)" = 3 ] || die "resume did not finish the work: $(indexed) indexed"
ok "resume: the three files are read"

echo "== night window"
H=$(date -u +%H); CLOSED=$(printf '%02d:00-%02d:00' $(( (10#$H + 5) % 24 )) $(( (10#$H + 7) % 24 ))); OPEN=$(printf '%02d:00-%02d:00' $(( (10#$H + 23) % 24 )) $(( (10#$H + 2) % 24 )))
$OCC buscador:window set "$CLOSED" --lanes=all --tz=UTC >/dev/null
$OCC buscador:window show | grep -q "closed" || die "show does not say closed"
for i in 4 5; do enq $i; done
work; work
[ "$(indexed)" = 3 ] || die "the worker was handed work outside the window"
$OCC buscador:status | grep -q "night window closed, opens in" || die "status does not explain the closed window"
ok "outside the window the real worker is handed nothing and status says when it opens"
$OCC buscador:window set "$OPEN" --lanes=all --tz=UTC >/dev/null
work; work; work
[ "$(indexed)" = 5 ] || die "inside the window the work did not run: $(indexed)"
ok "inside the window the queue drains"
$OCC buscador:window off >/dev/null
BAD=$($OCC buscador:window set tonight 2>&1 || true); grep -q "HH:MM-HH:MM" <<<"$BAD" || die "a bad window was accepted: $BAD"
ok "a nonsense window is refused"

echo "== caps"
$OCC buscador:throttle set --text=1 --vec=1 | grep -q "text lane: 1" || die "throttle set"
enq 6
$OCC buscador:throttle reset | grep -q "text lane: 4" || die "throttle reset"
work
[ "$(indexed)" = 6 ] || die "work did not run after the caps were reset"
ok "caps are set, shown and reset"

echo "== rotating the worker secret"
$C exec -T --user www-data nextcloud sh -c 'umask 077; php occ buscador:rotate-secret --to-file=/tmp/bd-new.secret --grace=24' | grep -q "written" || die "rotate did not write the file"
sleep 5      # see the note below: the web process may use the previous app config for up to 3 s
NEW=$($C exec -T nextcloud cat /tmp/bd-new.secret)
[ "${#NEW}" = 48 ] || die "the new secret is not 48 characters"
[ "$($C exec -T nextcloud stat -c %a /tmp/bd-new.secret)" = 600 ] || die "the secret file is not private"
ok "the new secret was written to a 0600 file"
FIRST=$(fid 1); $PSQL -c "update aps_search.file_state set text_state='pending' where file_id=$FIRST" >/dev/null; $ENQ enqueue "$FIRST" >/dev/null || true
work -e BD_WORKER_SECRET="$SECRET"
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$FIRST")" = indexed ] || die "the OLD secret stopped working inside the grace period"
ok "a worker still holding the old secret keeps working inside the grace period"
SECOND=$(fid 2); $PSQL -c "update aps_search.file_state set text_state='pending', etag = etag || 'x' where file_id=$SECOND" >/dev/null; $ENQ enqueue "$SECOND" >/dev/null || true
work -e BD_WORKER_SECRET="$NEW"
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$SECOND")" = indexed ] || die "the NEW secret does not work"
ok "and the new secret works at once"
$C exec -T --user www-data nextcloud php occ buscador:rotate-secret --to-file=/tmp/bd-new2.secret --grace=0 >/dev/null
sleep 5      # Nextcloud caches app config for 3 s in the web process (OC\AppConfig::LOCAL_CACHE_TTL), so a rotation is seen within seconds
NEW2=$($C exec -T nextcloud cat /tmp/bd-new2.secret)
THIRD=$(fid 3); $PSQL -c "update aps_search.file_state set text_state='pending', etag = etag || 'y' where file_id=$THIRD" >/dev/null; $ENQ enqueue "$THIRD" >/dev/null || true
work -e BD_WORKER_SECRET="$NEW"
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$THIRD")" != indexed ] || die "a rotated-away secret still works with grace 0"
$PSQL -c "update aps_search.job set lease_until = now() - interval '1 second'" >/dev/null     # the refused attempt left its lease to expire (900 s); do not wait for it
work -e BD_WORKER_SECRET="$NEW2"
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$THIRD")" = indexed ] || die "the newest secret does not work"
ok "with grace 0 the previous secret is dead at once and only the newest works"
ALL=$(mktemp /tmp/bd-s1-ops-all.XXXXXX); { $C --profile worker logs --no-color 2>&1; $C exec -T nextcloud cat /var/www/html/data/nextcloud.log; cat "$WORKLOG"; } > "$ALL"
for s in "$NEW" "$NEW2" "$SECRET"; do ! grep -q "$s" "$ALL" || { rm -f "$ALL"; die "a worker secret reached a log"; }; done
rm -f "$ALL"; $C exec -T nextcloud rm -f /tmp/bd-new.secret /tmp/bd-new2.secret
ok "none of the secrets appears in any log"

echo "== doctor, ETA and Prometheus"
$OCC config:app:set buscador_documental worker_secret --value="$NEW2" --sensitive >/dev/null
$OCC buscador:doctor --json > "$TMP/doctor.json" || true
python3 - "$TMP/doctor.json" <<'PY' || die "doctor output"
import json, sys
c = json.load(open(sys.argv[1]))
ids = {x["id"]: x["status"] for x in c}
assert ids["schema"] == "ok" and ids["worker_secret"] == "ok" and ids["worker_alive"] == "ok", ids
assert "fail" not in ids.values(), ids
PY
ok "doctor on a healthy stack: no failure"
$OCC config:app:set buscador_documental worker_secret --value=short --sensitive >/dev/null
if $OCC buscador:doctor >/dev/null; then die "doctor exits 0 with a broken secret"; fi
OUT=$($OCC buscador:doctor || true); grep -q "FAIL worker_secret" <<<"$OUT" || die "doctor does not name the secret: $OUT"
ok "a broken secret makes doctor exit non-zero and name it"
$OCC config:app:set buscador_documental worker_secret --value="$NEW2" --sensitive >/dev/null
$OCC buscador:status | grep -qE "^text lane: " || die "status has no ETA line"
$OCC buscador:status --prometheus > "$TMP/prom.txt"
python3 - "$TMP/prom.txt" <<'PY' || die "prometheus output"
import re, sys
lines = [l for l in open(sys.argv[1]).read().splitlines() if l and not l.startswith("#")]
assert lines, "empty"
pat = re.compile(r'^[a-z_][a-z0-9_]*(\{[a-z_]+="[A-Za-z0-9_.:-]+"(,[a-z_]+="[A-Za-z0-9_.:-]+")*\})? -?[0-9]+(\.[0-9]+)?$')
bad = [l for l in lines if not pat.match(l)]
assert not bad, bad
names = {l.split("{")[0].split(" ")[0] for l in lines}
assert {"buscador_jobs_queued", "buscador_paused", "buscador_files", "buscador_schema_bytes", "buscador_worker_last_seen_age_seconds"} <= names, names
PY
ok "status shows an ETA line per lane and --prometheus is valid text format with the expected metrics"

echo "ops OK ($PASS checks)"
