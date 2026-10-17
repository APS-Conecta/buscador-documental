#!/usr/bin/env bash
# S2 end to end: good files in every tier-1/2 format are indexed and findable, hostile files end `skipped` with the right
# reason, and no source file changes by a byte (A-SAFE-1). Runs against the bd-s1 stack only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
APP=/var/www/html/custom_apps/buscador_documental
DATA=/var/www/html/data/alice/files

$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$C exec -T --user www-data -e OC_PASS=alice-pass-123 nextcloud php occ user:add --password-from-env --display-name=Alice alice >/dev/null 2>&1 || true
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl RESTART IDENTITY"
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null

OUT=$(mktemp -d /tmp/bd-s1-formats.XXXXXX)
trap 'rm -rf "$OUT"' EXIT
docker run --rm --user root -v "$OUT":/out --entrypoint python3 bd-s1-worker -c "
import sys, pathlib
sys.path.insert(0, 'tests')
import fixtures as fx
o = pathlib.Path('/out')
fx.docx(o/'good.docx', ['Informe de vacunación invierno'])
fx.xlsx(o/'good.xlsx', {'Hoja': [['paciente', 'glicemia'], ['uno', 7]]})
fx.pptx(o/'good.pptx', [['Diapositiva sobre hipertensión']])
fx.odt(o/'good.odt', ['Acta de reunión del comité'])
fx.txt(o/'good.txt', 'Nota sobre nutrición infantil')
fx.pdf_text(o/'good.pdf', text='Receta de hemoglobina glicosilada')
fx.zip_bomb_docx(o/'bomb.docx')
fx.truncated_zip(o/'broken.docx')
fx.pdf_text(o/'locked.pdf', encrypt=('o', 'user-secret'))
fx.pixel_bomb_png(o/'pixels.png')
"
$C exec -T --user www-data nextcloud mkdir -p "$DATA"
declare -A WANT=( [good.docx]=indexed [good.xlsx]=indexed [good.pptx]=indexed [good.odt]=indexed [good.txt]=indexed [good.pdf]=indexed
                  [bomb.docx]=skipped:unsafe [broken.docx]=skipped:corrupt [locked.pdf]=skipped:encrypted [pixels.png]=skipped:unsafe )
declare -A SHA
for f in "${!WANT[@]}"; do
  $C cp "$OUT/$f" "nextcloud:$DATA/$f"
  $C exec -T nextcloud chown www-data:www-data "$DATA/$f"
  SHA[$f]=$(sha256sum "$OUT/$f" | cut -d' ' -f1)
done
$OCC files:scan alice >/dev/null
$C exec -T --user www-data nextcloud php "$APP/tests/canary/enqueue.php" ensure-tag
declare -A FID
for f in "${!WANT[@]}"; do
  FID[$f]=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::alice' and f.path='files/$f'")
  $C exec -T --user www-data nextcloud php "$APP/tests/canary/enqueue.php" enqueue "${FID[$f]}"
done
[ "$($PSQL -c "select count(*) from aps_search.job")" = "${#WANT[@]}" ] || { echo "FAIL: not every file was queued"; exit 1; }
for _ in "${!WANT[@]}"; do
  $C --profile worker run --rm --no-deps -T worker python3 main.py --once
done

fail=0
for f in "${!WANT[@]}"; do
  got=$($PSQL -c "select text_state || coalesce(':' || skip_reason, '') from aps_search.file_state where file_id=${FID[$f]}")
  after=$($C exec -T nextcloud sha256sum "$DATA/$f" | cut -d' ' -f1)
  [ "$got" = "${WANT[$f]}" ] || { echo "FAIL $f: want ${WANT[$f]} got $got"; fail=1; }
  [ "$after" = "${SHA[$f]}" ] || { echo "FAIL $f: file changed (A-SAFE-1)"; fail=1; }
done
hit() { $PSQL -c "select count(*) from aps_search.chunk c join aps_search.file_meta m using (file_id) where m.name='$1' and c.tsv @@ websearch_to_tsquery('aps_search.spanish_unaccent','$2')"; }
for pair in "good.docx:vacunacion invierno" "good.xlsx:glicemia" "good.pptx:hipertension" "good.odt:comite" "good.txt:nutricion infantil" "good.pdf:hemoglobina glicosilada"; do
  n=$(hit "${pair%%:*}" "${pair#*:}")
  [ "$n" -ge 1 ] || { echo "FAIL ${pair%%:*}: no hit for '${pair#*:}'"; fail=1; }
done
[ "$($PSQL -c "select count(*) from aps_search.job")" = 0 ] || { echo "FAIL jobs left behind"; fail=1; }
[ "$fail" = 0 ] && echo "formats OK (${#WANT[@]} files)"
exit $fail
