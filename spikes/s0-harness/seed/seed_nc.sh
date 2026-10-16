#!/usr/bin/env bash
# Seed users/groups/groupfolders/ACL for the S0 spike. Run from the harness directory via `make seed`.
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
mkdir -p results
t0=$(date +%s)

$C cp seed/seed_nc.php nextcloud:/tmp/seed_nc.php
$C exec -T --user www-data nextcloud php /tmp/seed_nc.php | tee results/seed_folders.json >/dev/null

F3=$(python3 -c 'import json;print(json.load(open("results/seed_folders.json"))["folders"]["gf-acl"])')

# F3 content: nested tree written into the groupfolder storage, then scanned.
$C exec -T --user www-data nextcloud sh -c "
  d=/var/www/html/data/__groupfolders/$F3/files
  mkdir -p \$d/open \$d/restricted/inner/deep \$d/tagged/sub
  echo 'abierto' > \$d/open/doc.txt
  echo 'restringido' > \$d/restricted/inner/deep/secret.txt
  echo 'etiquetado' > \$d/tagged/sub/x.txt
"
$OCC groupfolders:scan "$F3" >/dev/null

# Advanced ACL: deny read on /restricted for u_narrow only; u_admin200 has no rule.
$OCC groupfolders:permissions "$F3" --enable >/dev/null
$OCC groupfolders:permissions "$F3" restricted -u u_narrow -- -read >/dev/null

# Tag `rag-excluded` on the ancestor /tagged (no occ for system tags: SQL).
$C exec -T db psql -U nextcloud -d nextcloud -v ON_ERROR_STOP=1 -q <<SQL >/dev/null
INSERT INTO oc_systemtag (name, visibility, editable, etag, color)
  SELECT 'rag-excluded', 1, 1, md5('rag-excluded'), NULL
  WHERE NOT EXISTS (SELECT 1 FROM oc_systemtag WHERE name='rag-excluded');
INSERT INTO oc_systemtag_object_mapping (objectid, objecttype, systemtagid)
  SELECT fc.fileid::text, 'files', t.id
  FROM oc_filecache fc
  JOIN oc_storages st ON st.numeric_id = fc.storage
  CROSS JOIN oc_systemtag t
  WHERE t.name='rag-excluded' AND fc.path='files/tagged'
    AND st.id = 'local::/var/www/html/data/__groupfolders/$F3/'
    AND NOT EXISTS (SELECT 1 FROM oc_systemtag_object_mapping m
                    WHERE m.objectid=fc.fileid::text AND m.systemtagid=t.id);
SQL

bash seed/seed_f3_bulk.sh

t1=$(date +%s)
printf '{"seconds": %d, "groups": 200, "folders": 100, "f3_folder": %s}\n' "$((t1 - t0))" "$F3" > results/seed_timing.json
cat results/seed_timing.json
