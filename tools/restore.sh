#!/usr/bin/env bash
# Restore a backup made by tools/backup.sh into a database that already has what the schema needs (S5, ADR-0015, A-OPS-2).
#
#   BD_BACKUP_KEY_FILE=...  PGHOST=... PGUSER=<superuser> PGPASSWORD=...  tools/restore.sh IN.bak TARGET_DATABASE
#
# The target must exist and contain the roles bd_owner and bd_worker (cluster-wide), the Nextcloud database user named in the
# dump's grants, and the extensions unaccent (and vector when the backup has vector tables). It must not already hold aps_search.
# Nothing is changed unless every check passes; the restore itself runs in one transaction.
set -euo pipefail
IN=${1:?usage: restore.sh IN.bak TARGET_DATABASE}
DB=${2:?usage: restore.sh IN.bak TARGET_DATABASE}
KEY=${BD_BACKUP_KEY_FILE:?set BD_BACKUP_KEY_FILE to the file holding the encryption key}
fail() { echo "restore: $*" >&2; exit 2; }
[ -f "$IN" ] || fail "the backup file does not exist"
[ -f "$KEY" ] || fail "the key file does not exist"
if [ -f "$IN.sha256" ]; then
	[ "$(sha256sum "$IN" | cut -d' ' -f1)" = "$(cat "$IN.sha256")" ] || fail "the checksum does not match: the backup is damaged"
fi

# decrypt once into a private temporary file so the checks and the restore read the same bytes
TMP=$(mktemp); trap 'rm -f "$TMP"' EXIT
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass "file:$KEY" < "$IN" > "$TMP" 2>/dev/null || fail "cannot decrypt: wrong key or damaged backup"
pg_restore -l "$TMP" > /dev/null 2>&1 || fail "the decrypted data is not a valid backup: wrong key or damaged backup"

q() { psql -qAt -d "$DB" -c "$1"; }
[ "$(q "select 1 from pg_database where datname = current_database()")" = 1 ] || fail "cannot connect to the target database"
[ -z "$(q "select 1 from pg_namespace where nspname = 'aps_search'")" ] || fail "the target already has an aps_search schema; drop it first (occ buscador_documental:schema:drop --force) if you mean to replace it"
for role in bd_owner bd_worker; do
	[ -n "$(q "select 1 from pg_roles where rolname = '$role'")" ] || fail "the role $role does not exist in the cluster: create it first (see the install runbook)"
done
for role in $(pg_restore -f - --schema-only "$TMP" | sed -nE 's/^ALTER .* OWNER TO ([a-z_][a-z0-9_]*);$/\1/p; s/^GRANT .* TO ([a-z_][a-z0-9_]*);$/\1/p' | sort -u | grep -viE '^public$' || true); do
	[ -n "$(q "select 1 from pg_roles where rolname = '$role'")" ] || fail "the backup grants privileges to the role $role, which does not exist in this cluster"
done
[ -n "$(q "select 1 from pg_extension where extname = 'unaccent'")" ] || fail "the target lacks the unaccent extension: CREATE EXTENSION unaccent"
if pg_restore -l "$TMP" | grep -q 'chunk_vec_'; then
	[ -n "$(q "select 1 from pg_extension where extname = 'vector'")" ] || fail "the backup has vector tables and the target lacks the vector extension: CREATE EXTENSION vector"
fi

START=$(date +%s)
pg_restore --dbname="$DB" --single-transaction --exit-on-error "$TMP"
echo "restore: done in $(( $(date +%s) - START )) s"
psql -qAt -F' ' -d "$DB" -c "select 'schema_ver', schema_ver from aps_search.schema_meta
	union all select 'pages', count(*) from aps_search.page
	union all select 'chunks', count(*) from aps_search.chunk
	union all select 'files_indexed', count(*) from aps_search.file_state where text_state = 'indexed'
	union all select 'generations', count(*) from aps_search.embedding_profile
	order by 1"
