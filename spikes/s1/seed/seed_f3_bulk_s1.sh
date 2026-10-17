#!/usr/bin/env bash
# S1 perf fixture on the bd-s1 stack: the S0 seed (200 groups, 100 groupfolders, ACL, tag) reused unchanged, plus the
# bulk F3 content extended with `expired` and `multi` folders, the three shares, and results-s1/f3_hits.json (50 hits).
# 20 bulk + 3 multi + 10 shared + 2 expired readable-or-not by user, 5 restricted, 10 tagged.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -v ON_ERROR_STOP=1"
S0=spikes/s0-harness/seed
OUT=spikes/s1/results-s1
mkdir -p "$OUT"

$C up -d --build --wait
# custom_apps is created root-owned by the bind mount of the app; the app store needs it writable (non-recursive).
$C exec -T nextcloud chown www-data:www-data /var/www/html/custom_apps
$OCC app:install groupfolders >/dev/null 2>&1 || $OCC app:enable groupfolders >/dev/null
$C restart nextcloud >/dev/null && $C up -d --wait

$C cp "$S0/seed_nc.php" nextcloud:/tmp/seed_nc.php
$C exec -T --user www-data nextcloud php /tmp/seed_nc.php | tee "$OUT/seed_folders.json" >/dev/null
F3=$(python3 -c "import json;print(json.load(open('$OUT/seed_folders.json'))['folders']['gf-acl'])")

$C exec -T --user www-data nextcloud sh -c "
  d=/var/www/html/data/__groupfolders/$F3/files
  mkdir -p \$d/open \$d/restricted/inner/deep \$d/tagged/sub
  [ -e \$d/open/doc.txt ] || { echo abierto > \$d/open/doc.txt; echo restringido > \$d/restricted/inner/deep/secret.txt; echo etiquetado > \$d/tagged/sub/x.txt; }
  for a in 0 1 2 3 4; do for b in 0 1 2 3; do for c in 0 1; do
    mkdir -p \$d/bulk/a\$a/b\$b/c\$c
    for n in 1 2 3; do echo \"documento \$a \$b \$c \$n\" > \$d/bulk/a\$a/b\$b/c\$c/f\$n.txt; done
  done; done; done
  mkdir -p \$d/bulk/shared \$d/bulk/expired \$d/bulk/multi
  for n in \$(seq 1 20); do echo \"compartido \$n\" > \$d/bulk/shared/s\$n.txt; done
  for n in \$(seq 1 5); do echo \"vencido \$n\" > \$d/bulk/expired/e\$n.txt; echo \"multiple \$n\" > \$d/bulk/multi/m\$n.txt; done
  for n in \$(seq 1 10); do echo \"restringido \$n\" > \$d/restricted/inner/deep/r\$n.txt; done
  for n in \$(seq 1 20); do echo \"etiquetado \$n\" > \$d/tagged/sub/t\$n.txt; done"
$OCC groupfolders:scan "$F3" >/dev/null
$OCC groupfolders:permissions "$F3" --enable >/dev/null
$OCC groupfolders:permissions "$F3" restricted -u u_narrow -- -read >/dev/null

$PSQL -q <<SQL >/dev/null
INSERT INTO oc_systemtag (name, visibility, editable, etag, color)
  SELECT 'rag-excluded', 0, 0, md5('rag-excluded'), NULL
  WHERE NOT EXISTS (SELECT 1 FROM oc_systemtag WHERE name='rag-excluded');
INSERT INTO oc_systemtag_object_mapping (objectid, objecttype, systemtagid)
  SELECT fc.fileid::text, 'files', t.id
  FROM oc_filecache fc JOIN oc_storages st ON st.numeric_id = fc.storage
  CROSS JOIN (SELECT min(id) AS id FROM oc_systemtag WHERE name='rag-excluded') t
  WHERE fc.path='files/tagged' AND st.id = 'local::/var/www/html/data/__groupfolders/$F3/'
    AND NOT EXISTS (SELECT 1 FROM oc_systemtag_object_mapping m WHERE m.objectid=fc.fileid::text AND m.systemtagid=t.id);
SQL

$C cp spikes/s1/seed/seed_f3_bulk_s1.php nextcloud:/tmp/seed_f3_bulk_s1.php
$C exec -T --user www-data nextcloud php /tmp/seed_f3_bulk_s1.php

# 50 hits, deterministic: 20 bulk, 3 multi, 10 shared, 2 expired, 5 restricted, 10 tagged.
$PSQL -tA -F, <<SQL | python3 -c '
import sys, json
by = {}
for l in sys.stdin:
    if l.strip():
        kind, fid, path = l.strip().split(",")
        by.setdefault(kind, []).append((path, int(fid)))
pick = lambda k, n: [f for _, f in sorted(by[k])[:: max(1, len(by[k]) // n)][:n]]
spec = (("bulk", 20), ("multi", 3), ("shared", 10), ("expired", 2), ("restricted", 5), ("tagged", 10))
hits = [{"kind": k, "file_id": f} for k, n in spec for f in pick(k, n)]
assert len(hits) == 50, len(hits)
json.dump(hits, open("'"$OUT"'/f3_hits.json", "w"))
print("hits:", len(hits))'
SELECT CASE WHEN fc.path LIKE 'files/bulk/shared/%' THEN 'shared' WHEN fc.path LIKE 'files/bulk/expired/%' THEN 'expired'
            WHEN fc.path LIKE 'files/bulk/multi/%' THEN 'multi' WHEN fc.path LIKE 'files/bulk/%' THEN 'bulk'
            WHEN fc.path LIKE 'files/restricted/%' THEN 'restricted' ELSE 'tagged' END, fc.fileid, fc.path
FROM oc_filecache fc JOIN oc_storages st ON st.numeric_id = fc.storage
WHERE st.id = 'local::/var/www/html/data/__groupfolders/$F3/' AND fc.path ~ '\.txt$'
  AND (fc.path LIKE 'files/bulk/%' OR fc.path LIKE 'files/restricted/inner/deep/r%' OR fc.path LIKE 'files/tagged/sub/t%');
SQL
