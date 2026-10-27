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
# The schema version this script (and the app that ships it) writes and reads. A backup of any other version is refused: restore
# it with the app version that made it, then upgrade the app (a newer schema would be read by code that does not know it).
SUPPORTED_SCHEMA=8
fail() { echo "restore: $*" >&2; exit 2; }
# HMAC-SHA256 (RFC 2104) of stdin as lowercase hex, built from plain digests so the key stays off every command line.
# $1 is the 64-character hex MAC key: exactly one SHA-256 block, used as is (no pre-hashing).
hmac_sha256() {
	local i c ipad='' opad=''
	for (( i = 0; i < 64; i++ )); do
		printf -v c '%d' "'${1:i:1}"
		printf -v ipad '%s\\x%02x' "$ipad" $(( c ^ 0x36 ))
		printf -v opad '%s\\x%02x' "$opad" $(( c ^ 0x5c ))
	done
	{ printf '%b' "$opad"; { printf '%b' "$ipad"; cat; } | openssl dgst -sha256 -binary; } | openssl dgst -sha256 -r | cut -d' ' -f1
}
[ -f "$IN" ] || fail "the backup file does not exist"
[ -f "$KEY" ] && [ ! -L "$KEY" ] || fail "the key file does not exist (or is a symbolic link)"
case "$DB" in *[=' ']*) fail "TARGET_DATABASE must be a database name, not a connection string";; esac
[ -f "$IN.hmac" ] || fail "the keyed checksum $IN.hmac is missing: a backup without it cannot be trusted"
BD_BACKUP_MAC=$( { printf 'mac:'; cat "$KEY"; } | openssl dgst -sha256 -r | cut -d' ' -f1)
[ "$(hmac_sha256 "$BD_BACKUP_MAC" < "$IN")" = "$(cat "$IN.hmac")" ] || fail "the keyed checksum does not match: the backup was altered, or the key is not the one that made it"
[ -f "$IN.sha256" ] || fail "the checksum file $IN.sha256 is missing: a backup without it cannot be checked for damage"
[ "$(sha256sum "$IN" | cut -d' ' -f1)" = "$(cat "$IN.sha256")" ] || fail "the checksum does not match: the backup is damaged"
q() { psql -qAt -d "$DB" -c "$1"; }
[ "$(q "select 1 from pg_database where datname = current_database()")" = 1 ] || fail "cannot connect to the target database"

# The decrypted dump lives in a private directory, never in a shared temp file. It goes to memory (/dev/shm) only when the dump
# plus the target's maintenance_work_mem plus HEADROOM fits in each of: /dev/shm's free space, the kernel's MemAvailable and,
# under a cgroup v2 memory limit, memory.max minus the working set (memory.current minus inactive_file), because inside a
# container MemAvailable reports the host. maintenance_work_mem counts because the final index build takes that much (in
# /dev/shm when it runs in parallel, as backend memory otherwise) while the dump is still staged; HEADROOM (64 MiB) covers
# openssl, pg_restore and psql. A figure that cannot be read means disk, and BD_RESTORE_NO_SHM forces disk. On disk the dump
# goes to a private directory in BD_RESTORE_TMPDIR or the default temp dir; the decrypted text is then on that disk until the
# restore ends, so put it on an encrypted volume.
NEED=$(( $(wc -c < "$IN") + 1048576 ))
HEADROOM=67108864
digits() { case "$1" in ''|*[!0-9]*) return 1;; esac; }
# errexit is off inside a function called from `if`: every read returns explicitly, and figures are checked before arithmetic
fits_in_memory() {
	local mwm need free avail cg dir max cur inactive
	[ -z "${BD_RESTORE_NO_SHM:-}" ] && [ -d /dev/shm ] && [ -w /dev/shm ] || return 1
	mwm=$(q "select pg_size_bytes(current_setting('maintenance_work_mem'))") && digits "$mwm" || return 1
	need=$(( NEED + mwm + HEADROOM ))
	free=$(df -PB1 /dev/shm | awk 'NR==2{print $4}') && digits "$free" && [ "$free" -gt "$need" ] || return 1
	avail=$(awk '$1=="MemAvailable:"{print $2}' /proc/meminfo) && digits "$avail" && [ $(( avail * 1024 )) -gt "$need" ] || return 1
	cg=$(sed -n 's/^0:://p' /proc/self/cgroup) || return 1
	dir=/sys/fs/cgroup${cg%/}
	[ -n "$cg" ] && [ -f "$dir/memory.max" ] || return 0
	max=$(cat "$dir/memory.max") || return 1
	[ "$max" != max ] || return 0
	cur=$(cat "$dir/memory.current") || return 1
	inactive=$(awk '$1=="inactive_file"{print $2}' "$dir/memory.stat") || return 1
	digits "$max" && digits "$cur" && digits "$inactive" && [ $(( max - cur + inactive )) -gt "$need" ]
}
if fits_in_memory; then AT='in memory'; WORK=$(mktemp -d -p /dev/shm); else AT='on disk'; WORK=$(mktemp -d ${BD_RESTORE_TMPDIR:+-p "$BD_RESTORE_TMPDIR"}); fi
FREE=$(df -PB1 "$WORK" | awk 'NR==2{print $4}')
[ "$FREE" -gt "$NEED" ] || { rmdir "$WORK" 2>/dev/null; fail "not enough free space to hold the decrypted backup ($(( NEED / 1048576 )) MB needed): set BD_RESTORE_TMPDIR to a directory with room"; }
echo "restore: the decrypted backup is staged $AT ($WORK)" >&2
chmod 700 "$WORK"
CREATED=0
cleanup() { if [ "$CREATED" = 1 ]; then psql -qAt -d "$DB" -c "drop schema if exists aps_search cascade" >/dev/null 2>&1 || true; fi; rm -rf "$WORK"; }
trap cleanup EXIT
TMP="$WORK/dump"
BD_BACKUP_PASS=$(openssl dgst -sha256 -r < "$KEY" | cut -d' ' -f1)
export BD_BACKUP_PASS
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:BD_BACKUP_PASS < "$IN" > "$TMP" 2>/dev/null || fail "cannot decrypt: wrong key or damaged backup"
TOC=$(pg_restore -l "$TMP" 2>/dev/null) || fail "the decrypted data is not a valid backup: wrong key or damaged backup"

DUMP_VER=$(pg_restore -a -n aps_search -t schema_meta -f - "$TMP" 2>/dev/null | awk '/^COPY /{f=1; next} f{print $1; exit}')
[ "$DUMP_VER" = "$SUPPORTED_SCHEMA" ] || fail "the backup holds schema version ${DUMP_VER:-unknown} and this restore script supports version $SUPPORTED_SCHEMA: restore it with the app version that made it, then upgrade"

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
