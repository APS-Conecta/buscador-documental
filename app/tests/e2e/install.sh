#!/usr/bin/env bash
# A-OPS-5: install the release tarball into a stock Nextcloud (no bind mount, own `bd-install` project), enable it, check the
# doctor, index one document with the worker and find it. NC_IMAGE overrides the Nextcloud image (the non-blocking NC 35 job).
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.install.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
SECRET=bd-install-toy-secret-0123456789abcdef
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }
cleanup() { $C --profile worker down -v --remove-orphans >/dev/null 2>&1 || true; rm -rf "${TMP:-/nonexistent}"; }
trap cleanup EXIT
TMP=$(mktemp -d /tmp/bd-install.XXXXXX)
TARBALL=$(ls app/dist/buscador_documental-*.tar.gz | head -1)
[ -f "$TARBALL.sha256" ] || die "no checksum next to the tarball"
(cd app/dist && sha256sum -c "$(basename "$TARBALL").sha256" >/dev/null) || die "checksum does not match"
ok "tarball present and its checksum verifies"

$C --profile worker down -v --remove-orphans >/dev/null 2>&1 || true
$C up -d --wait
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser >/dev/null
$C cp "$TARBALL" nextcloud:/tmp/app.tar.gz
$C exec -T nextcloud sh -c 'tar xzf /tmp/app.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps/buscador_documental'
$OCC app:enable buscador_documental ${NC_FORCE:+--force} >/dev/null || die "the app did not enable"
ok "the app enabled on a clean install ($($OCC app:list | grep -c buscador_documental) listing)"
[ "$($PSQL -c 'select schema_ver from aps_search.schema_meta')" = 9 ] || die "schema was not created at version 9"
ok "the index schema was created (version 9)"
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
# The suite adopts its generated worker secret from the environment (issue 43); a repeated run is a no-op.
$C exec -T --user www-data -e BD_WORKER_SECRET="$SECRET" nextcloud php occ buscador:adopt-secret >/dev/null || die "the worker secret was not adopted from the environment"
$C exec -T --user www-data -e BD_WORKER_SECRET="$SECRET" nextcloud php occ buscador:adopt-secret >/dev/null || die "a repeated adoption failed"
ok "the worker secret was adopted from BD_WORKER_SECRET (and adopting it again changes nothing)"
[ "$($PSQL -c "select count(*) from oc_systemtag where name = 'rag-excluded'")" = 1 ] || die "enabling the app did not create the rag-excluded tag (indexing would fail closed)"
ok "the exclusion tag exists after enabling"
$OCC buscador:doctor --json > "$TMP/doctor.json" || true
python3 - "$TMP/doctor.json" <<'PY' || die "doctor reports a failure on a fresh install"
import json, sys
checks = json.load(open(sys.argv[1]))
bad = [c for c in checks if c["status"] == "fail"]
print("   doctor:", {c["id"]: c["status"] for c in checks})
sys.exit(1 if bad else 0)
PY
ok "doctor has no failure on a fresh install"

R=$(head -c 3 /dev/urandom | od -An -tx1 | tr -d ' \n')
$C exec -T --user www-data -e OC_PASS=install-pass-123 nextcloud php occ user:add --password-from-env inst_a >/dev/null
printf 'Informe de instalacion %s con hemoglobina glicosilada\n' "$R" > "$TMP/informe.txt"
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/inst_a/files/Docs
$C cp "$TMP/informe.txt" nextcloud:/var/www/html/data/inst_a/files/Docs/informe.txt
$C exec -T nextcloud chown www-data:www-data /var/www/html/data/inst_a/files/Docs/informe.txt
$OCC files:scan inst_a >/dev/null
DOCS=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::inst_a' and f.path='files/Docs'")
$OCC buscador:scope:add --folder-id="$DOCS" >/dev/null
$PSQL -c "insert into aps_search.outbox (file_id, kind) select f.fileid, 'file' from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::inst_a' and f.path = 'files/Docs/informe.txt' on conflict do nothing"
JOB=$($OCC background-job:list --class='OCA\BuscadorDocumental\Job\ProcessOutbox' --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)[0]["id"])')
$OCC background-job:execute "$JOB" --force-execute >/dev/null
for _ in 1 2 3; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null 2>&1 || true; done
[ "$($PSQL -c "select count(*) from aps_search.file_state where text_state='indexed'")" = 1 ] || die "the document was not indexed by the installed app"
ok "a document was read by the worker from the installed app"
APP=/var/www/html/custom_apps/buscador_documental
HITS=$($C exec -T --user www-data nextcloud php -r '
require "/var/www/html/lib/base.php";
$s = \OC::$server->get(\OCA\BuscadorDocumental\Search\Searcher::class);
$r = $s->search("inst_a", \OCA\BuscadorDocumental\Search\SearchRequest::fromArray(["query" => "hemoglobina", "mode" => "exacta"]));
echo count($r["hits"]);
' 2>/dev/null | tail -1)
[ "$HITS" = 1 ] || die "the search did not find the document (hits: '$HITS')"
ok "the installed app found the document"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -u admin:admin http://127.0.0.1:8192/index.php/apps/buscador_documental/)
[ "$CODE" = 200 ] || die "the app page answered $CODE"
for f in js/buscador_documental-main.mjs css/buscador_documental-style.css; do
	[ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8192/custom_apps/buscador_documental/$f)" = 200 ] || die "$f is not served"
done
ok "the app page and its bundle are served"
echo "install OK ($PASS checks)"
