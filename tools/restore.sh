#!/usr/bin/env bash
# Restore a backup made by tools/backup.sh into a database that already has what the schema needs (S5, ADR-0015, A-OPS-2).
#
#   BD_BACKUP_KEY_FILE=...  PGHOST=... PGUSER=<superuser> PGPASSWORD=...  tools/restore.sh IN.bak TARGET_DATABASE
#
# The backup must carry a valid keyed checksum (OUT.bak.hmac, made with the key file): it is verified before anything is decrypted,
# so a file altered by someone without the key is refused. Everything except creating the empty schema runs as the role bd_owner,
# which is not a superuser, cannot run programs on the server and owns nothing outside aps_search. A dump made by the key holder
# is trusted code for that schema (it can define functions there): restore only backups made with YOUR key.
#
# The target must exist and contain the roles bd_owner and bd_worker (cluster-wide), the Nextcloud database user named in the
# dump's grants, and the extensions unaccent (and vector when the backup has vector tables). It must not already hold aps_search.
# Nothing is changed unless every check passes; the restore itself runs in one transaction, and a failure removes the schema again.
set -euo pipefail
IN=${1:?usage: restore.sh IN.bak TARGET_DATABASE}
DB=${2:?usage: restore.sh IN.bak TARGET_DATABASE}
KEY=${BD_BACKUP_KEY_FILE:?set BD_BACKUP_KEY_FILE to the file holding the encryption key}
fail() { echo "restore: $*" >&2; exit 2; }
[ -f "$IN" ] || fail "the backup file does not exist"
[ -f "$KEY" ] && [ ! -L "$KEY" ] || fail "the key file does not exist (or is a symbolic link)"
case "$DB" in *[=' ']*) fail "TARGET_DATABASE must be a database name, not a connection string";; esac
[ -f "$IN.hmac" ] || fail "the keyed checksum $IN.hmac is missing: a backup without it cannot be trusted"
BD_BACKUP_MAC=$( { printf 'mac:'; cat "$KEY"; } | openssl dgst -sha256 -r | cut -d' ' -f1)
[ "$(openssl dgst -sha256 -hmac "$BD_BACKUP_MAC" -r < "$IN" | cut -d' ' -f1)" = "$(cat "$IN.hmac")" ] || fail "the keyed checksum does not match: the backup was altered, or the key is not the one that made it"
[ -f "$IN.sha256" ] || fail "the checksum file $IN.sha256 is missing: a backup without it cannot be checked for damage"
[ "$(sha256sum "$IN" | cut -d' ' -f1)" = "$(cat "$IN.sha256")" ] || fail "the checksum does not match: the backup is damaged"

# the decrypted dump lives in a private directory (memory when there is some), never in a shared temp file
if [ -d /dev/shm ] && [ -w /dev/shm ]; then WORK=$(mktemp -d -p /dev/shm); else WORK=$(mktemp -d); fi
chmod 700 "$WORK"
CREATED=0
cleanup() { if [ "$CREATED" = 1 ]; then psql -qAt -d "$DB" -c "drop schema if exists aps_search cascade" >/dev/null 2>&1 || true; fi; rm -rf "$WORK"; }
trap cleanup EXIT
TMP="$WORK/dump"
BD_BACKUP_PASS=$(openssl dgst -sha256 -r < "$KEY" | cut -d' ' -f1)
export BD_BACKUP_PASS
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:BD_BACKUP_PASS < "$IN" > "$TMP" 2>/dev/null || fail "cannot decrypt: wrong key or damaged backup"
TOC=$(pg_restore -l "$TMP" 2>/dev/null) || fail "the decrypted data is not a valid backup: wrong key or damaged backup"

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
if grep -q 'chunk_vec_' <<<"$TOC"; then
	[ -n "$(q "select 1 from pg_extension where extname = 'vector'")" ] || fail "the backup has vector tables and the target lacks the vector extension: CREATE EXTENSION vector"
fi

START=$(date +%s)
# The empty schema is the only thing created with superuser rights (bd_owner may not create schemas in the database).
q "create schema aps_search authorization bd_owner" >/dev/null
CREATED=1
grep -vE '^[0-9]+; [0-9]+ [0-9]+ SCHEMA - aps_search ' <<<"$TOC" > "$WORK/list"
pg_restore --dbname="$DB" --role=bd_owner --single-transaction --exit-on-error -L "$WORK/list" "$TMP"
CREATED=0
echo "restore: done in $(( $(date +%s) - START )) s"
psql -qAt -F' ' -d "$DB" -c "select 'schema_ver', schema_ver from aps_search.schema_meta
	union all select 'pages', count(*) from aps_search.page
	union all select 'chunks', count(*) from aps_search.chunk
	union all select 'files_indexed', count(*) from aps_search.file_state where text_state = 'indexed'
	union all select 'generations', count(*) from aps_search.embedding_profile
	order by 1"
