#!/usr/bin/env bash
# S4 live: the vector lane and the search API on the real stack, with the deterministic embedding stub (it speaks Ollama's
# protocol; its vectors are hashed bags of words, so this proves plumbing, permissions and failure handling, not model quality).
# Covers: profile setup and digest, embedding by the worker, coverage and readiness, activation gate, the three modes, filters,
# the model-down fallback, permissions, erasure of vectors, and log hygiene for query and chunk text sent to the endpoint.
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
R=$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n'); S_DOC="SENTVECDOC${R}"; S_QUERY="SENTVECQRY${R}"

reset_vectors() {
	$PSQL -c "DO \$\$ DECLARE t text; BEGIN FOR t IN SELECT relname FROM pg_class WHERE relnamespace = 'aps_search'::regnamespace AND relkind = 'r' AND relname ~ '^chunk_vec_[0-9]+\$' LOOP EXECUTE format('DROP TABLE aps_search.%I', t); END LOOP; END \$\$; DELETE FROM aps_search.embedding_profile; DELETE FROM aps_search.settings WHERE key LIKE 'vec_%' OR key IN ('semantic_enabled', 'semantic_min_coverage')" >/dev/null 2>&1 || true
}
trap reset_vectors EXIT

# check "<json>" "<label>" "<python boolean over j>"
check() {
	python3 - "$1" "$3" <<'PY' || die "$2 :: $1"
import json, os, sys
j = json.loads(sys.argv[1])
FID = int(os.environ["FID"])
sys.exit(0 if eval(sys.argv[2]) else 1)
PY
	ok "$2"
}
api() { $CTL api "$1" "$2"; }

echo "== setup"
$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.settings RESTART IDENTITY CASCADE" >/dev/null
reset_vectors
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
$C --profile worker up -d --wait --force-recreate embedstub >/dev/null
for u in vec_a vec_b; do $OCC user:delete "$u" >/dev/null 2>&1 || true; $C exec -T --user www-data -e OC_PASS=vec-pass-123456 nextcloud php occ user:add --password-from-env "$u" >/dev/null; done
$ENQ ensure-tag >/dev/null
WORKLOG=$(mktemp /tmp/bd-s1-vec-work.XXXXXX); TMP=$(mktemp -d /tmp/bd-s1-vec.XXXXXX); trap 'rm -rf "$TMP" "$WORKLOG"; reset_vectors' EXIT
work() { $C --profile worker run --rm --no-deps -T worker python3 main.py --once >>"$WORKLOG" 2>&1; }

echo "== 1. profile setup (ADR-0014)"
OUT=$($OCC buscador:profile set bge-m3 --url=http://embedstub:11434) || die "profile set failed: $OUT"
grep -q "generation 1" <<<"$OUT" || die "unexpected output: $OUT"
ok "profile set: $(head -c 80 <<<"$OUT")"
[ "$($PSQL -c "select state || ':' || dim from aps_search.embedding_profile where gen = 1")" = "building:1024" ] || die "generation not building"
[ "$($PSQL -c "select count(*) from pg_class where relname = 'chunk_vec_1' and relkind = 'r'")" = 1 ] || die "no vector table"
ok "generation 1 is building; its table exists"
BAD=$($OCC buscador:profile set bge-m3 --url=http://127.0.0.1:9 2>&1 || true)
grep -qi "reach" <<<"$BAD" || die "an unreachable endpoint was not named: $BAD"
[ "$($OCC config:app:get buscador_documental embed_url)" = "http://embedstub:11434" ] || die "a failed setup did not restore the endpoint"
ok "an unreachable endpoint is refused and the working one is kept"
DUP=$($OCC buscador:profile set bge-m3 --url=http://embedstub:11434 2>&1 || true)
grep -q "already uses" <<<"$DUP" || die "a second identical generation was accepted: $DUP"
ok "the same model and digest is not built twice"

echo "== 2. a scanned PDF and a text file go through the real worker; the vector lane follows"
docker run --rm --user root -v "$TMP":/out --entrypoint python3 bd-s1-worker make_scan.py /out/scan.pdf
printf 'Informe sobre cadena de frio y vacunas %s con el detalle del control\n' "$S_DOC" > "$TMP/nota.txt"
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/vec_a/files
for f in scan.pdf nota.txt; do $C cp "$TMP/$f" "nextcloud:/var/www/html/data/vec_a/files/$f"; $C exec -T nextcloud chown www-data:www-data "/var/www/html/data/vec_a/files/$f"; done
$OCC files:scan vec_a >/dev/null
fid() { $PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::vec_a' and f.path='files/$1'"; }
FID=$(fid scan.pdf); export FID
NOTE=$(fid nota.txt)
$ENQ enqueue "$FID" | grep -q enqueued || die "enqueue refused"
$ENQ enqueue "$NOTE" | grep -q enqueued || die "enqueue refused (note)"
for _ in 1 2 3; do work; done
[ "$($PSQL -c "select text_state from aps_search.file_state where file_id=$FID")" = indexed ] || die "scan not indexed"
CH=$($PSQL -c "select count(*) from aps_search.chunk where file_id in ($FID, $NOTE)")
EM=$($PSQL -c "select count(*) from aps_search.chunk_vec_1")
[ "$CH" -ge 2 ] && [ "$CH" = "$EM" ] || die "chunks $CH, vectors $EM"
[ "$($PSQL -c "select count(*) from aps_search.file_state where file_id in ($FID, $NOTE) and vec_state = 'embedded'")" = 2 ] || die "vec_state not embedded"
[ "$($PSQL -c "select count(*) from aps_search.job where lane = 'vec'")" = 0 ] || die "vec jobs left behind"
ok "every chunk of both files has a vector ($EM) and no vec job is left"

echo "== 3. coverage, readiness and the activation gate"
OUT=$($OCC buscador:profile refresh)
grep -q "made ready: 1" <<<"$OUT" || die "generation not readied: $OUT"
[ "$($PSQL -c "select state from aps_search.embedding_profile where gen = 1")" = ready ] || die "not ready"
[ "$($PSQL -c "select count(*) from pg_indexes where tablename = 'chunk_vec_1' and indexdef ilike '%hnsw%'")" = 1 ] || die "no HNSW index"
ok "coverage reached the threshold: generation 1 is ready and has its HNSW index"
check "$(api vec_a '{"query":"cadena de frio","mode":"mixta"}')" "ready is not active: the semantic modes still fall back, with a notice" 'j["notice"]["code"] == "semantic_unavailable" and len(j["hits"]) >= 1'
EARLY=$($OCC buscador:profile semantic on 2>&1 || true)
grep -q "active profile" <<<"$EARLY" || die "semantic on before activation should be refused: $EARLY"
$OCC buscador:profile activate 1 >/dev/null
$OCC buscador:profile semantic on >/dev/null
$OCC buscador:profile show | grep -q "semantic modes: on" || die "show does not say on"
ok "activate and semantic on are explicit administrator acts"

echo "== 4. the three modes, filters, paging fields"
check "$(api vec_a '{"query":"cadena de frio vacunas","mode":"exacta"}')" "exacta finds the text file" 'any(h["file_id"] == '"$NOTE"' for h in j["hits"]) and j["notice"] is None'
check "$(api vec_a '{"query":"vacunas frio informe","mode":"significado"}')" "significado finds it through the vector leg" 'j["hits"][0]["file_id"] == '"$NOTE"' and j["notice"] is None'
check "$(api vec_a '{"query":"vacunas frio informe","mode":"mixta"}')" "mixta fuses both" 'j["hits"][0]["file_id"] == '"$NOTE"' and sorted(j["hits"][0].keys()) == ["file_id","heading","mtime","owner","page_no","path","snippet","title","uncertain"]'
check "$(api vec_a '{"query":"vacunas frio","mode":"mixta","filters":{"types":["pdf"]}}')" "the type filter excludes the text file" 'all(h["file_id"] != '"$NOTE"' for h in j["hits"])'
check "$(api vec_a '{"query":"vacunas frio","mode":"mixta","filters":{"types":["texto"],"modified_from":"2000-01-01","modified_to":"2999-12-31"}}')" "type and date filters keep it" 'any(h["file_id"] == '"$NOTE"' for h in j["hits"])'
check "$(api vec_a '{"query":"vacunas frio","mode":"mixta","filters":{"modified_to":"2000-01-01"}}')" "an old date range finds nothing" 'j["hits"] == []'
check "$(api vec_a '{"query":"vacunas frio","mode":"exacta","sort":"name_asc","page_size":25}')" "paging fields" 'j["page"]["total"] >= 1 and j["page"]["size"] == 25 and j["cursors"]["next"] is None'
if api vec_a '{"query":"x","mode":"nope"}' >/dev/null 2>&1; then die "an invalid mode was accepted"; fi
ok "an invalid mode is refused"

echo "== 5. permissions in the vector modes"
for m in exacta significado mixta; do
	check "$(api vec_b "{\"query\":\"vacunas frio informe\",\"mode\":\"$m\"}")" "vec_b sees nothing in $m" 'j["hits"] == []'
done

echo "== 6. the model is down: keyword results still come back"
$OCC config:app:set buscador_documental embed_url --value=http://127.0.0.1:9 >/dev/null
check "$(api vec_a '{"query":"cadena de frio vacunas","mode":"mixta"}')" "mixta falls back with a notice, never an empty list" 'j["notice"]["code"] == "semantic_failed" and any(h["file_id"] == '"$NOTE"' for h in j["hits"])'
$OCC config:app:set buscador_documental embed_url --value=http://embedstub:11434 >/dev/null
check "$(api vec_a '{"query":"vacunas frio informe","mode":"significado"}')" "and recovers when the endpoint is back" 'j["notice"] is None'

echo "== 7. an exclusion tag erases the vectors too"
$CTL tag vec_a nota.txt
$CTL erase "$NOTE" excluded
[ "$($PSQL -c "select count(*) from aps_search.chunk_vec_1 where file_id = $NOTE")" = 0 ] || die "vectors left after erase"
[ "$($PSQL -c "select count(*) from aps_search.chunk where file_id = $NOTE")" = 0 ] || die "chunks left after erase"
ok "erase removed the chunks and their vectors"
$CTL untag vec_a nota.txt

echo "== 8. log hygiene: query and chunk text sent to the endpoint appear in no log"
$C --profile worker exec -T embedstub sh -c 'echo CONTROL-STUB-STDOUT > /proc/1/fd/1'
$C exec -T nextcloud sh -c 'echo CONTROL-NC-LOGFILE >> /var/www/html/data/nextcloud.log'
$ENQ enqueue "$NOTE" >/dev/null 2>&1 || true
api vec_a "{\"query\":\"$S_QUERY\",\"mode\":\"mixta\"}" >/dev/null
$OCC config:app:set buscador_documental embed_url --value=http://127.0.0.1:9 >/dev/null
api vec_a "{\"query\":\"$S_QUERY\",\"mode\":\"mixta\"}" >/dev/null
$OCC config:app:set buscador_documental embed_url --value=http://embedstub:11434 >/dev/null
ALL=$(mktemp /tmp/bd-s1-vec-all.XXXXXX); trap 'rm -rf "$TMP" "$WORKLOG" "$ALL"; reset_vectors' EXIT
{ $C --profile worker logs --no-color 2>&1; $C exec -T nextcloud cat /var/www/html/data/nextcloud.log; cat "$WORKLOG"; } > "$ALL"
for c in CONTROL-STUB-STDOUT CONTROL-NC-LOGFILE; do grep -q "$c" "$ALL" || die "control $c not found: that sink is not read"; done
grep -q "embedded" "$WORKLOG" || die "worker output empty: nothing to check"
for s in "$S_DOC" "$S_QUERY"; do ! grep -q "$s" "$ALL" || die "sentinel $s leaked: $(grep -m2 "$s" "$ALL" | cut -c1-200)"; done
ok "controls found in the stub and Nextcloud logs; neither sentinel (chunk text, query) appears in any log"
STATS=$($C --profile worker exec -T embedstub python3 -c "import urllib.request;print(urllib.request.urlopen('http://127.0.0.1:11434/_stats').read().decode())")
grep -q embed_calls <<<"$STATS" && ! grep -q "$S_DOC" <<<"$STATS" || die "stub stats malformed or leaking"
ok "the endpoint saw the calls ($STATS) but keeps only counters"

echo "vectors OK ($PASS checks)"
