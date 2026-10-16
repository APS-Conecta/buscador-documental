#!/usr/bin/env bash
# F3 bulk content for m9: ~150 files in a nested tree of the gf-acl groupfolder, a download-disabled user share,
# and results/f3_hits.json (50 deterministic "search hits": readable, shared, ACL-denied, tag-excluded).
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
F3=$(python3 -c 'import json;print(json.load(open("results/seed_folders.json"))["folders"]["gf-acl"])')

$C exec -T --user www-data nextcloud sh -c "
  d=/var/www/html/data/__groupfolders/$F3/files
  for a in 0 1 2 3 4; do for b in 0 1 2 3; do for c in 0 1; do
    mkdir -p \$d/bulk/a\$a/b\$b/c\$c
    for n in 1 2 3; do echo \"documento \$a \$b \$c \$n\" > \$d/bulk/a\$a/b\$b/c\$c/f\$n.txt; done
  done; done; done
  mkdir -p \$d/bulk/shared; for n in \$(seq 1 20); do echo \"compartido \$n\" > \$d/bulk/shared/s\$n.txt; done
  for n in \$(seq 1 10); do echo \"restringido \$n\" > \$d/restricted/inner/deep/r\$n.txt; done
  for n in \$(seq 1 20); do echo \"etiquetado \$n\" > \$d/tagged/sub/t\$n.txt; done"
$OCC groupfolders:scan "$F3" >/dev/null
$C cp seed/seed_f3_bulk.php nextcloud:/tmp/seed_f3_bulk.php
$C exec -T --user www-data nextcloud php /tmp/seed_f3_bulk.php

# 50 hits: 25 bulk (readable), 10 shared, 5 restricted (denied for u_narrow), 10 tagged (excluded for everyone).
$C exec -T db psql -U nextcloud -d nextcloud -tA -F, <<SQL | python3 -c '
import sys, json
rows = [l.strip().split(",") for l in sys.stdin if l.strip()]
by = {}
for kind, fid, path in rows:
    by.setdefault(kind, []).append((path, int(fid)))
pick = lambda k, n: [f for _, f in sorted(by[k])[:: max(1, len(by[k]) // n)][:n]]
hits = [{"kind": k, "file_id": f} for k, n in (("bulk", 25), ("shared", 10), ("restricted", 5), ("tagged", 10)) for f in pick(k, n)]
assert len(hits) == 50, len(hits)
json.dump(hits, open("results/f3_hits.json", "w"))
print("hits:", len(hits))'
SELECT CASE WHEN fc.path LIKE 'files/bulk/shared/%' THEN 'shared' WHEN fc.path LIKE 'files/bulk/%' THEN 'bulk'
            WHEN fc.path LIKE 'files/restricted/%' THEN 'restricted' ELSE 'tagged' END, fc.fileid, fc.path
FROM oc_filecache fc JOIN oc_storages st ON st.numeric_id = fc.storage
WHERE st.id = 'local::/var/www/html/data/__groupfolders/$F3/' AND fc.path ~ '\.txt$'
  AND (fc.path LIKE 'files/bulk/%' OR fc.path LIKE 'files/restricted/inner/deep/r%' OR fc.path LIKE 'files/tagged/sub/t%');
SQL
