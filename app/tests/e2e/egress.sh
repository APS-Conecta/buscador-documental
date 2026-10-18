#!/usr/bin/env bash
# A-OPS-4: no outbound network during indexing and search. The worker is on an internal network (no route to the internet) and the
# parser has no network at all; both are proved by trying to reach a public address, with positive controls for what they may reach.
# Then a file is indexed and searched on that very network. Runs against the bd-s1 stack only.
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
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl RESTART IDENTITY CASCADE" >/dev/null
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser embedstub >/dev/null

# A probe that exits 0 when the connection is made and 1 when it is not (no tool but the Python already in the image)
probe() { python3 -c "
import socket, sys
try:
    socket.create_connection((sys.argv[1], int(sys.argv[2])), timeout=4).close()
except OSError:
    sys.exit(1)
" "$@"; }
export -f probe 2>/dev/null || true
PROBE='import socket, sys
try:
    socket.create_connection((sys.argv[1], int(sys.argv[2])), timeout=4).close()
except OSError:
    sys.exit(1)'
wprobe() { $C --profile worker run --rm --no-deps -T --entrypoint python3 worker -c "$PROBE" "$@"; }

echo "== the worker cannot leave"
for target in "1.1.1.1 443" "8.8.8.8 53" "9.9.9.9 443"; do
	if wprobe $target; then die "the worker reached $target"; fi
done
ok "no route from the worker to three public addresses"
if wprobe example.com 443; then die "the worker resolved and reached a public name"; fi
ok "and a public name cannot be resolved or reached"

echo "== positive controls: it can reach what it needs"
wprobe db 5432 || die "the worker cannot reach the database"
wprobe nextcloud 80 || die "the worker cannot reach Nextcloud"
wprobe embedstub 11434 || die "the worker cannot reach the embedding endpoint"
ok "database, Nextcloud and the embedding endpoint are reachable by name"

echo "== the parser has no network at all"
if $C --profile worker exec -T --workdir /worker parser python3 -c "$PROBE" db 5432; then die "the parser reached the database"; fi
if $C --profile worker exec -T --workdir /worker parser python3 -c "$PROBE" 1.1.1.1 443; then die "the parser reached the internet"; fi
ok "the parser reaches neither the database nor the internet"

echo "== indexing and searching still work on that network"
TMP=$(mktemp -d /tmp/bd-s1-eg.XXXXXX); trap 'rm -rf "$TMP"' EXIT
$OCC user:delete eg_a >/dev/null 2>&1 || true
$C exec -T --user www-data -e OC_PASS=eg-pass-12345 nextcloud php occ user:add --password-from-env eg_a >/dev/null
printf 'Informe sobre hemoglobina glicosilada y control de pacientes\n' > "$TMP/nota.txt"
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/eg_a/files
$C cp "$TMP/nota.txt" nextcloud:/var/www/html/data/eg_a/files/nota.txt
$C exec -T nextcloud chown www-data:www-data /var/www/html/data/eg_a/files/nota.txt
$OCC files:scan eg_a >/dev/null
FID=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::eg_a' and f.path='files/nota.txt'")
$ENQ ensure-tag >/dev/null
$ENQ enqueue "$FID" | grep -q enqueued || die "enqueue refused"
$C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$FID")" = indexed ] || die "the file was not indexed on the internal network"
$CTL search eg_a hemoglobina | python3 -c 'import json,sys; assert len(json.load(sys.stdin)["hits"]) == 1' || die "the search found nothing"
ok "a file was read by the worker and found by search with the worker offline from the internet"

echo "egress OK ($PASS checks)"
