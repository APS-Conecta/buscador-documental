#!/usr/bin/env bash
# Encrypted backup of the Buscador Documental search schema (aps_search), page text included (S5, ADR-0015, A-OPS-2).
#
#   BD_BACKUP_KEY_FILE=/safe/place/backup.key  PGHOST=... PGUSER=... PGDATABASE=... PGPASSWORD=...  tools/backup.sh OUT.bak
#
# Writes OUT.bak (pg_dump custom format, AES-256-CBC with PBKDF2), OUT.bak.sha256 (detects corruption) and OUT.bak.meta
# (counts and the schema version, no names or text). Needs pg_dump, psql and openssl. The passphrase is derived from the WHOLE key
# file, which must be at least 32 bytes and readable by you only; keep it apart from the backup (anyone with both reads every
# indexed word). The encryption does not authenticate the file: restore only backups you made and kept in storage others cannot write.
set -euo pipefail
OUT=${1:?usage: backup.sh OUT.bak}
KEY=${BD_BACKUP_KEY_FILE:?set BD_BACKUP_KEY_FILE to a file holding the encryption key}
[ -f "$KEY" ] && [ ! -L "$KEY" ] || { echo "backup: the key file does not exist (or is a symbolic link)" >&2; exit 2; }
[ "$(wc -c < "$KEY")" -ge 32 ] || { echo "backup: the key file must hold at least 32 bytes" >&2; exit 2; }
mode=$(stat -c %a "$KEY")
if [ "$mode" != "600" ] && [ "$mode" != "400" ]; then
	echo "backup: the key file must be readable by its owner only (chmod 600)" >&2; exit 2
fi
if [ -e "$OUT" ] || [ -L "$OUT" ]; then echo "backup: $OUT already exists" >&2; exit 2; fi
have=$(psql -qAt -c "select 1 from pg_namespace where nspname = 'aps_search'") || { echo "backup: cannot query the database (check the connection settings)" >&2; exit 2; }
[ "$have" = 1 ] || { echo "backup: there is no aps_search schema in this database" >&2; exit 2; }

BD_BACKUP_PASS=$(openssl dgst -sha256 -r < "$KEY" | cut -d' ' -f1)
export BD_BACKUP_PASS
START=$(date +%s)
umask 077
trap 'rm -f "$OUT.part"' ERR EXIT
pg_dump -Fc --schema=aps_search | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -pass env:BD_BACKUP_PASS > "$OUT.part"
mv "$OUT.part" "$OUT"
sha256sum "$OUT" | cut -d' ' -f1 > "$OUT.sha256"
psql -qAt -F' ' -c "select 'schema_ver', schema_ver from aps_search.schema_meta
	union all select 'pages', count(*) from aps_search.page
	union all select 'chunks', count(*) from aps_search.chunk
	union all select 'files_indexed', count(*) from aps_search.file_state where text_state = 'indexed'
	union all select 'generations', count(*) from aps_search.embedding_profile
	order by 1" > "$OUT.meta"
echo "backup: $OUT ($(wc -c < "$OUT") bytes) in $(( $(date +%s) - START )) s"
