#!/usr/bin/env bash
# S1 end to end, one file: scanned PDF owned by alice -> enqueue -> worker (OCR) -> indexed rows -> Spanish tsquery hit.
# Runs against the bd-s1 stack only. Needs `make instance-migrate` first (done below).
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
APP=/var/www/html/custom_apps/buscador_documental

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$C exec -T --user www-data -e OC_PASS=alice-pass-123 nextcloud php occ user:add --password-from-env --display-name=Alice alice >/dev/null 2>&1 || true

# Fresh state for this file; the index is rebuilt from scratch.
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl RESTART IDENTITY"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
mkdir -p /tmp/bd-s1-e2e
docker run --rm --user root -v /tmp/bd-s1-e2e:/out --entrypoint python3 bd-s1-worker make_scan.py /out/scan.pdf
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/alice/files
$C cp /tmp/bd-s1-e2e/scan.pdf nextcloud:/var/www/html/data/alice/files/scan.pdf
$C exec -T nextcloud chown www-data:www-data /var/www/html/data/alice/files/scan.pdf
$OCC files:scan alice >/dev/null
FID=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::alice' and f.path='files/scan.pdf'")
echo "file id: $FID"

$C exec -T --user www-data nextcloud php "$APP/tests/canary/enqueue.php" ensure-tag
$C exec -T --user www-data nextcloud php "$APP/tests/canary/enqueue.php" enqueue "$FID"
[ "$($PSQL -c "select count(*) from aps_search.job where file_id=$FID")" = 1 ] || { echo "no job queued"; exit 1; }

# S3: while paused the worker is handed nothing, the job stays, and status says so; resume restores normal work.
$OCC buscador:pause | grep -q paused
$C --profile worker run --rm --no-deps -T worker python3 main.py --once
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$FID")" = pending ] || { echo "paused run changed the file"; exit 1; }
[ "$($PSQL -c "select count(*) from aps_search.chunk where file_id=$FID")" = 0 ] || { echo "paused run indexed"; exit 1; }
[ "$($PSQL -c "select count(*) from aps_search.job where file_id=$FID")" = 1 ] || { echo "paused run lost the job"; exit 1; }
$OCC buscador:status | grep -q "paused: yes" || { echo "status does not show the pause"; exit 1; }
$OCC buscador:resume | grep -q resumed
$OCC buscador:status | grep -q "paused: no" || { echo "status does not show the resume"; exit 1; }
$C --profile worker run --rm --no-deps -T worker python3 main.py --once

STATE=$($PSQL -c "select text_state from aps_search.file_state where file_id=$FID")
CHUNKS=$($PSQL -c "select count(*) from aps_search.chunk where file_id=$FID")
HIT=$($PSQL -c "select count(*) from aps_search.chunk where file_id=$FID and tsv @@ websearch_to_tsquery('aps_search.spanish_unaccent','hemoglobina glicosilada nutrición')")
JOBS=$($PSQL -c "select count(*) from aps_search.job where file_id=$FID")
CONF=$($PSQL -c "select count(*) from aps_search.page where file_id=$FID and source='ocr' and ocr_conf between 1 and 100")
[ "$CONF" -ge 1 ] || { echo "no OCR confidence stored"; exit 1; }
echo "state=$STATE chunks=$CHUNKS hit=$HIT jobs_left=$JOBS"
[ "$STATE" = indexed ] && [ "$CHUNKS" -gt 0 ] && [ "$HIT" -ge 1 ] && [ "$JOBS" = 0 ]
# The channel refuses unauthenticated and badly signed callers with an empty body.
URL=http://127.0.0.1:8191/apps/buscador_documental/worker/v1/files/$FID/meta
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL")" = 401 ]
[ "$(curl -s -H "Authorization: Bearer w1.$(printf 'a%.0s' {1..64})" -H "X-BD-Timestamp: $(date +%s)" -H "X-BD-Nonce: aaaaaaaaaaaaaaaaaaaa" -H "X-BD-Job-Id: 1" -H "X-BD-Lease-Epoch: 1" "$URL")" = '[]' ]
echo "e2e-one OK"
