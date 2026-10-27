#!/usr/bin/env bash
# A-OPS-2: the backup holds the page text and the vectors, is unreadable without the key, and a restore into a fresh database
# reproduces the counts and answers a search. Measures backup, restore and re-reading times and writes them to
# spikes/s5/results-s5/restore_rehearsal.json. Runs against the bd-s1 stack only; the scripts run inside the database container, and some restores
# run in a side container from the db image that shares its network.
set -euo pipefail
cd "$(dirname "$0")/../../.."
C="docker compose -f compose.yaml"
OCC="$C exec -T --user www-data nextcloud php occ"
PSQL="$C exec -T db psql -U postgres -d nextcloud -tAq"
APP=/var/www/html/custom_apps/buscador_documental
ENQ="$C exec -T --user www-data nextcloud php $APP/tests/canary/enqueue.php"
SECRET=bd-s1-toy-secret-0123456789abcdef0123
PASS=0
ok() { PASS=$((PASS + 1)); echo "  ok - $*"; }
die() { echo "  FAIL - $*" >&2; exit 1; }
R=$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n'); SENT="SENTBAK${R}"
DBX() { $C exec -T -e PGUSER=postgres -e PGDATABASE=nextcloud -e BD_BACKUP_KEY_FILE=/tmp/bd.key db "$@"; }

echo "== a small indexed corpus with vectors"
$C up -d --build --wait
$OCC app:enable buscador_documental >/dev/null
$OCC config:system:set trusted_domains 2 --value=nextcloud >/dev/null
$OCC config:app:set buscador_documental worker_secret --value="$SECRET" --sensitive >/dev/null
$PSQL -c "TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl RESTART IDENTITY CASCADE" >/dev/null
$PSQL -c "DO \$\$ DECLARE t text; BEGIN FOR t IN SELECT relname FROM pg_class WHERE relnamespace = 'aps_search'::regnamespace AND relkind = 'r' AND relname ~ '^chunk_vec_[0-9]+\$' LOOP EXECUTE format('DROP TABLE aps_search.%I', t); END LOOP; END \$\$; DELETE FROM aps_search.embedding_profile; DELETE FROM aps_search.settings" >/dev/null
$C --profile worker build worker >/dev/null
$C --profile worker up -d --wait parser embedstub >/dev/null
trap '$PSQL -c "DO \$\$ DECLARE t text; BEGIN FOR t IN SELECT relname FROM pg_class WHERE relnamespace = '"'"'aps_search'"'"'::regnamespace AND relkind = '"'"'r'"'"' AND relname ~ '"'"'^chunk_vec_[0-9]+\$'"'"' LOOP EXECUTE format('"'"'DROP TABLE aps_search.%I'"'"', t); END LOOP; END \$\$; DELETE FROM aps_search.embedding_profile" >/dev/null 2>&1 || true; $C exec -T db dropdb -U postgres --if-exists bd_restore_test >/dev/null 2>&1 || true; $C exec -T db dropdb -U postgres --if-exists bd_restore_test2 >/dev/null 2>&1 || true; rm -rf "$TMP"' EXIT
TMP=$(mktemp -d /tmp/bd-s1-rs.XXXXXX)
$OCC buscador:profile set bge-m3 --url=http://embedstub:11434 >/dev/null
$OCC user:delete rs_a >/dev/null 2>&1 || true
$C exec -T --user www-data -e OC_PASS=rs-pass-12345 nextcloud php occ user:add --password-from-env rs_a >/dev/null
$C exec -T --user www-data nextcloud mkdir -p /var/www/html/data/rs_a/files
for i in 1 2 3; do printf 'Informe %s sobre hemoglobina glicosilada y control de pacientes %s\n' "$i" "$SENT" > "$TMP/n$i.txt"; $C cp "$TMP/n$i.txt" nextcloud:/var/www/html/data/rs_a/files/n$i.txt; $C exec -T nextcloud chown www-data:www-data /var/www/html/data/rs_a/files/n$i.txt; done
$OCC files:scan rs_a >/dev/null
$ENQ ensure-tag >/dev/null
READ_START=$(date +%s)
for f in n1 n2 n3; do
	FID=$($PSQL -c "select f.fileid from oc_filecache f join oc_storages s on s.numeric_id=f.storage where s.id='home::rs_a' and f.path='files/$f.txt'")
	$ENQ enqueue "$FID" | grep enqueued >/dev/null || die "enqueue refused"
done
for _ in 1 2 3 4; do $C --profile worker run --rm --no-deps -T worker python3 main.py --once >/dev/null; done
READ_SECONDS=$(( $(date +%s) - READ_START ))
N=$($PSQL -c "select count(*) from aps_search.chunk"); V=$($PSQL -c "select count(*) from aps_search.chunk_vec_1")
[ "$N" -ge 3 ] && [ "$N" = "$V" ] || die "chunks $N, vectors $V"
ok "3 files read and embedded in ${READ_SECONDS} s: $N chunks, $V vectors"

echo "== backup"
DBX sh -c 'head -c 48 /dev/urandom | base64 -w0 > /tmp/bd.key; chmod 600 /tmp/bd.key; rm -f /tmp/x.bak /tmp/x.bak.*'
$C cp tools/backup.sh db:/tmp/backup.sh >/dev/null; $C cp tools/restore.sh db:/tmp/restore.sh >/dev/null
B0=$(date +%s%N); DBX bash /tmp/backup.sh /tmp/x.bak | tail -1; BACKUP_MS=$(( ($(date +%s%N) - B0) / 1000000 ))
[ "$(DBX head -c 8 /tmp/x.bak)" = "Salted__" ] || die "the backup is not an encrypted file"
ok "the backup is an AES-256 file (Salted__ header), $BACKUP_MS ms"
[ "$(DBX sh -c "pg_dump --schema=aps_search --format=plain | grep -c $SENT")" -ge 1 ] || die "control: the plain dump should contain the sentinel"
[ "$(DBX sh -c "grep -c $SENT /tmp/x.bak || true")" = 0 ] || die "the sentinel is visible in the encrypted backup"
ok "the page text is in the dump (control: plain dump has it) and not visible in the encrypted file"
DBX cat /tmp/x.bak.meta | grep "^chunks $N$" >/dev/null || die "meta does not record the chunk count"
ok "the meta file records the counts: $(DBX cat /tmp/x.bak.meta | tr '\n' ' ')"
BAD=$(DBX bash /tmp/backup.sh /tmp/x.bak 2>&1 || true); grep -q "already exists" <<<"$BAD" || die "an existing backup was overwritten: $BAD"
DBX sh -c 'echo short > /tmp/short.key; chmod 600 /tmp/short.key'
BAD=$($C exec -T -e PGUSER=postgres -e PGDATABASE=nextcloud -e BD_BACKUP_KEY_FILE=/tmp/short.key db bash /tmp/backup.sh /tmp/y.bak 2>&1 || true); grep -q "at least 32 bytes" <<<"$BAD" || die "a short key was accepted: $BAD"
ok "an existing file is never overwritten and a short key is refused"

echo "== restore into a fresh database"
DBX dropdb --if-exists bd_restore_test; DBX createdb bd_restore_test
# 1. missing extension is named and nothing changes
BAD=$(DBX bash /tmp/restore.sh /tmp/x.bak bd_restore_test 2>&1 || true); grep -q "unaccent" <<<"$BAD" || die "a missing extension was not named: $BAD"
[ -z "$(DBX psql -qAt -d bd_restore_test -c "select 1 from pg_namespace where nspname='aps_search'")" ] || die "a refused restore left a schema behind"
ok "a target without the extensions is refused and left untouched"
DBX psql -q -d bd_restore_test -c "CREATE EXTENSION unaccent; CREATE EXTENSION vector"
# 2. wrong key
DBX sh -c 'head -c 48 /dev/urandom | base64 -w0 > /tmp/other.key; chmod 600 /tmp/other.key'
BAD=$($C exec -T -e PGUSER=postgres -e BD_BACKUP_KEY_FILE=/tmp/other.key db bash /tmp/restore.sh /tmp/x.bak bd_restore_test 2>&1 || true); grep -qE "wrong key|keyed checksum does not match" <<<"$BAD" || die "a wrong key was not refused: $BAD"
[ -z "$(DBX psql -qAt -d bd_restore_test -c "select 1 from pg_namespace where nspname='aps_search'")" ] || die "a refused restore left a schema behind"
ok "a wrong key is refused and nothing is restored"
# 3. the real restore
T0=$(date +%s%N); OUT=$(DBX bash /tmp/restore.sh /tmp/x.bak bd_restore_test 2>&1); RESTORE_MS=$(( ($(date +%s%N) - T0) / 1000000 ))
echo "$OUT" | grep "^chunks $N$" >/dev/null || die "restored chunk count differs: $OUT"
ok "restored in $RESTORE_MS ms; counts: $(echo "$OUT" | tail -5 | tr '\n' ' ')"
grep -q "staged in memory" <<<"$OUT" || die "a small backup was not staged in memory: $OUT"
ok "a small backup is staged in memory"
RESTORED=$(DBX psql -qAt -d bd_restore_test -c "select count(*) from aps_search.chunk where tsv @@ websearch_to_tsquery('aps_search.spanish_unaccent', 'hemoglobina glicosilada')")
[ "$RESTORED" = "$N" ] || die "the restored index answers $RESTORED of $N"
ok "a keyword search on the restored database finds all $N chunks"
[ "$(DBX psql -qAt -d bd_restore_test -c "select count(*) from aps_search.chunk_vec_1")" = "$V" ] || die "vectors differ after restore"
[ "$(DBX psql -qAt -d bd_restore_test -c "select count(*) from aps_search.chunk_vec_1 v join aps_search.chunk c using (chunk_id)")" = "$V" ] || die "vectors lost their chunks"
ok "the $V vectors came back, each with its chunk"
[ "$(DBX psql -qAt -d bd_restore_test -c "select has_function_privilege('bd_worker','aps_search.claim_job(text,int,text,int)','execute'), has_table_privilege('bd_worker','aps_search.chunk','select')")" = "t|f" ] || die "privileges changed in the restore"
ok "the worker role still has its functions and no table access"
[ "$(DBX psql -qAt -d bd_restore_test -c "select count(*) from pg_class where relnamespace = 'aps_search'::regnamespace and pg_get_userbyid(relowner) <> 'bd_owner'")" = 0 ] || die "something in aps_search is not owned by bd_owner after the restore"
ok "everything restored belongs to bd_owner (the restore ran as that role, not as the superuser)"
# the role the restore runs as cannot do what a tampered archive would want
[ -z "$(DBX psql -qAt -d bd_restore_test -c "select 1 from pg_roles where rolname = 'bd_owner' and (rolsuper or rolcreaterole or rolcreatedb)")" ] || die "bd_owner has powers it should not have"
if DBX psql -q -d bd_restore_test -c "set role bd_owner; copy (select 1) to program 'id > /tmp/pwned'" >/dev/null 2>&1; then die "bd_owner can run programs on the server"; fi
if DBX psql -q -d bd_restore_test -c "set role bd_owner; alter role nextcloud superuser" >/dev/null 2>&1; then die "bd_owner can change roles"; fi
ok "bd_owner cannot run server programs or change roles (what a dump made with your own key may do is limited to the search schema)"
BAD=$(DBX bash /tmp/restore.sh /tmp/x.bak bd_restore_test 2>&1 || true); grep -q "already has an aps_search schema" <<<"$BAD" || die "a second restore over the first was not refused: $BAD"
ok "restoring over an existing schema is refused"
# no key material in a process's arguments: every external command the scripts run is wrapped to log its argument list
ARGV_KEY=bd-argv-toy-key-0123456789abcdef0123456789abcdef
DBX sh -c "rm -rf /tmp/argv-bin /tmp/argv*.log /tmp/w.bak*; printf %s '$ARGV_KEY' > /tmp/argv.key; chmod 600 /tmp/argv.key"
MAC=$(DBX sh -c '{ printf mac:; cat /tmp/argv.key; } | openssl dgst -sha256 -r | cut -d" " -f1')
PASSPH=$(DBX sh -c 'openssl dgst -sha256 -r < /tmp/argv.key | cut -d" " -f1')
case "$MAC" in *6*) ;; *) die "control: the toy key's MAC key must contain a 6 (a NUL byte in the inner pad)";; esac
DBX sh -c 'mkdir /tmp/argv-bin && for c in openssl cat cut sha256sum wc stat psql pg_dump pg_restore mv rm rmdir chmod mktemp df awk sed sort grep date; do
	p=$(command -v "$c") || { echo "missing $c" >&2; exit 1; }
	printf "#!/bin/sh\nprintf \"%%s\\\\n\" \"\${0##*/} \$*\" >> /tmp/argv.log\nexec %s \"\$@\"\n" "$p" > "/tmp/argv-bin/$c" && chmod 755 "/tmp/argv-bin/$c" || exit 1
done' || die "could not wrap the commands the scripts run"
ARGV() { $C exec -T -e PGUSER=postgres -e PGDATABASE=nextcloud -e BD_BACKUP_KEY_FILE=/tmp/argv.key db sh -c 'PATH=/tmp/argv-bin:$PATH; export PATH; exec bash "$@"' sh "$@"; }
ARGV /tmp/backup.sh /tmp/w.bak >/dev/null || die "the backup with wrapped commands failed"
LOG=$(DBX cat /tmp/argv.log); grep -q '^openssl enc ' <<<"$LOG" && grep -q '^openssl dgst ' <<<"$LOG" || die "control: the wrapped backup logged no openssl call"
DBX mv /tmp/argv.log /tmp/argv.backup.log
BAD=$(ARGV /tmp/restore.sh /tmp/w.bak bd_restore_test 2>&1 || true); grep -q "already has an aps_search schema" <<<"$BAD" || die "the restore with wrapped commands did not reach the target check: $BAD"
LOG=$(DBX cat /tmp/argv.log); grep -q '^openssl enc -d ' <<<"$LOG" && grep -q '^openssl dgst ' <<<"$LOG" || die "control: the wrapped restore logged no openssl call"
DBX mv /tmp/argv.log /tmp/argv.restore.log
LOG=$(DBX cat /tmp/argv.backup.log /tmp/argv.restore.log)
if grep -qF -e "$MAC" -e "$PASSPH" -e "$ARGV_KEY" <<<"$LOG"; then die "a process's argument list holds key material"; fi
ok "the backup and restore scripts pass no key material in process arguments"
[ "$(DBX cat /tmp/w.bak.hmac)" = "$(DBX sh -c 'openssl dgst -sha256 -hmac "$1" -r < /tmp/w.bak | cut -d" " -f1' sh "$MAC")" ] || die "the keyed checksum is not HMAC-SHA256 under the derived key"
ok "the keyed checksum is HMAC-SHA256 under the derived key, in the same format as before"
DBX cp /tmp/x.bak.sha256 /tmp/x.sha.keep; DBX rm -f /tmp/x.bak.sha256
BAD=$(DBX bash /tmp/restore.sh /tmp/x.bak bd_restore_test 2>&1 || true); grep -q "checksum file" <<<"$BAD" || die "a backup without its checksum was accepted: $BAD"
DBX mv /tmp/x.sha.keep /tmp/x.bak.sha256
ok "a backup without its checksum file is refused"
# a backup of another schema version is refused (the live schema here is the version the script supports)
DBX sh -c 'rm -f /tmp/newer.bak*'
$PSQL -c "UPDATE aps_search.schema_meta SET schema_ver = 99" >/dev/null
DBX bash /tmp/backup.sh /tmp/newer.bak >/dev/null || { $PSQL -c "UPDATE aps_search.schema_meta SET schema_ver = 9" >/dev/null; die "could not back up the newer-schema copy"; }
$PSQL -c "UPDATE aps_search.schema_meta SET schema_ver = 9" >/dev/null     # never leave the stack on a schema the worker refuses
BAD=$(DBX bash /tmp/restore.sh /tmp/newer.bak bd_restore_test 2>&1 || true); grep -q "holds schema version 99" <<<"$BAD" || die "a backup of a newer schema was not refused: $BAD"
ok "a backup of a newer schema version is refused before anything is created"
# a file altered by someone WITHOUT the key is refused even if they recompute the plain checksum
DBX sh -c 'cp /tmp/x.bak /tmp/t.bak && printf "\x01" | dd of=/tmp/t.bak bs=1 seek=100 conv=notrunc 2>/dev/null && sha256sum /tmp/t.bak | cut -d" " -f1 > /tmp/t.bak.sha256 && cp /tmp/x.bak.hmac /tmp/t.bak.hmac'
BAD=$(DBX bash /tmp/restore.sh /tmp/t.bak bd_restore_test 2>&1 || true); grep -q "keyed checksum does not match" <<<"$BAD" || die "an altered backup with a recomputed plain checksum was accepted: $BAD"
DBX sh -c 'openssl dgst -sha256 -hmac attacker-guess -r < /tmp/t.bak | cut -d" " -f1 > /tmp/t.bak.hmac'
BAD=$(DBX bash /tmp/restore.sh /tmp/t.bak bd_restore_test 2>&1 || true); grep -q "keyed checksum does not match" <<<"$BAD" || die "an altered backup with a forged keyed checksum was accepted: $BAD"
DBX rm -f /tmp/t.bak.hmac
BAD=$(DBX bash /tmp/restore.sh /tmp/t.bak bd_restore_test 2>&1 || true); grep -q "keyed checksum .* is missing" <<<"$BAD" || die "a backup without its keyed checksum was accepted: $BAD"
BAD=$(DBX bash /tmp/restore.sh /tmp/x.bak "dbname=nextcloud host=evil" 2>&1 || true); grep -q "not a connection string" <<<"$BAD" || die "a connection string was accepted as the target: $BAD"
ok "an altered backup is refused whatever checksum is recomputed without the key; a missing keyed checksum and a connection-string target are refused too"
DBX sh -c 'head -c 3 /dev/urandom | od -An -tx1 > /dev/null; printf "abc\n%s\n" "$(head -c 60 /dev/urandom | base64 -w0)" > /tmp/twoline.key; chmod 600 /tmp/twoline.key'
BAD=$($C exec -T -e PGUSER=postgres -e BD_BACKUP_KEY_FILE=/tmp/twoline.key db bash /tmp/restore.sh /tmp/x.bak bd_restore_test 2>&1 || true); grep -qE "wrong key|already has|keyed checksum does not match" <<<"$BAD" || die "unexpected: $BAD"
DBX dropdb --if-exists bd_restore_test2; DBX createdb bd_restore_test2; DBX psql -q -d bd_restore_test2 -c "CREATE EXTENSION unaccent; CREATE EXTENSION vector"
BAD=$($C exec -T -e PGUSER=postgres -e BD_BACKUP_KEY_FILE=/tmp/twoline.key db bash /tmp/restore.sh /tmp/x.bak bd_restore_test2 2>&1 || true); grep -qE "wrong key|keyed checksum does not match" <<<"$BAD" || die "a key that merely shares the first line decrypted the backup: $BAD"
DBX dropdb --if-exists bd_restore_test2; DBX createdb bd_restore_test2; DBX psql -q -d bd_restore_test2 -c "CREATE EXTENSION unaccent; CREATE EXTENSION vector"
# a backup that does not fit in memory is staged on disk: forced here with BD_RESTORE_NO_SHM (the 15 GB backup of the S7 load test needed it)
OUT=$($C exec -T -e PGUSER=postgres -e BD_BACKUP_KEY_FILE=/tmp/bd.key -e BD_RESTORE_NO_SHM=1 db bash /tmp/restore.sh /tmp/x.bak bd_restore_test2 2>&1) || die "the restore staged on disk failed: $OUT"
grep -q "chunks 3" <<<"$OUT" || die "the disk-staged restore did not bring the chunks: $OUT"
grep -q "staged on disk" <<<"$OUT" || die "BD_RESTORE_NO_SHM did not stage the decrypted backup on disk: $OUT"
ok "BD_RESTORE_NO_SHM stages the decrypted backup on disk"
ok "a restore whose decrypted dump is staged on disk (not memory) gives the same result"
# where the decrypted backup is staged: restores in a side container from the db image (sharing its network), whose memory
# limit, /proc/meminfo or cgroup files are set per run
for f in x.bak x.bak.hmac x.bak.sha256 bd.key; do $C cp "db:/tmp/$f" "$TMP/$f" >/dev/null; done
IMG=$(docker inspect -f '{{.Config.Image}}' "$($C ps -q db)")
SIDE() { docker run --rm --shm-size=256m --network "container:$($C ps -q db)" -e PGHOST=127.0.0.1 -e PGUSER=postgres -e PGPASSWORD=dev -e BD_BACKUP_KEY_FILE=/work/bd.key -v "$TMP:/work:ro" -v "$PWD/tools/restore.sh:/restore.sh:ro" "$@"; }
DBX dropdb --if-exists bd_restore_test2; DBX createdb bd_restore_test2; DBX psql -q -d bd_restore_test2 -c "CREATE EXTENSION unaccent; CREATE EXTENSION vector"
OUT=$(SIDE --memory=64m --memory-swap=64m "$IMG" bash /restore.sh /work/x.bak bd_restore_test2 2>&1) || die "the restore in a 64 MiB container failed: $OUT"
grep -q "staged on disk" <<<"$OUT" || die "a 64 MiB container with a 256 MiB /dev/shm staged the decrypted backup in memory: $OUT"
grep -q "^chunks $N$" <<<"$OUT" || die "the restore in a 64 MiB container did not bring the chunks: $OUT"
ok "a container with less memory than its /dev/shm stages the decrypted backup on disk, and the restore succeeds"
BAD=$(SIDE "$IMG" bash /restore.sh /work/x.bak bd_restore_test 2>&1 || true)
grep -q "already has an aps_search schema" <<<"$BAD" && grep -q "staged in memory" <<<"$BAD" || die "control: the side container without a memory limit should stage in memory: $BAD"
ok "control: the side container without a memory limit stages the decrypted backup in memory"
printf 'MemTotal:       16384000 kB\nMemFree:         8192000 kB\n' > "$TMP/meminfo"
BAD=$(SIDE -v "$TMP/meminfo:/proc/meminfo:ro" "$IMG" bash /restore.sh /work/x.bak bd_restore_test 2>&1 || true)
grep -q "already has an aps_search schema" <<<"$BAD" && grep -q "staged on disk" <<<"$BAD" || die "an unreadable MemAvailable did not stage on disk: $BAD"
ok "an unreadable MemAvailable stages the decrypted backup on disk"
mkdir "$TMP/cg"; echo 1073741824 > "$TMP/cg/memory.max"
BAD=$(SIDE -v "$TMP/cg:/sys/fs/cgroup:ro" "$IMG" bash /restore.sh /work/x.bak bd_restore_test 2>&1 || true)
grep -q "already has an aps_search schema" <<<"$BAD" && grep -q "staged on disk" <<<"$BAD" || die "a cgroup memory limit with an unreadable usage did not stage on disk: $BAD"
ok "a cgroup memory limit whose usage cannot be read stages the decrypted backup on disk"
BAD=$(DBX bash /tmp/restore.sh /tmp/x.bak bd_restore_absent 2>&1 || true)
grep -q "cannot connect to the target database" <<<"$BAD" || die "a target database that does not exist was not refused: $BAD"
if grep -q "staged" <<<"$BAD"; then die "the backup was decrypted before the target connection was checked: $BAD"; fi
ok "a target that cannot be reached is refused before anything is decrypted"
DBX dropdb --if-exists bd_restore_test2
ok "the whole key file is the passphrase: a file that only shares the first line does not decrypt"

mkdir -p spikes/s5/results-s5
printf '{"files":3,"chunks":%s,"vectors":%s,"backup_ms":%s,"restore_ms":%s,"read_and_embed_seconds":%s,"note":"3 small text files on the test stack; the stub embedder; indicative of the procedure, not of volume"}\n' "$N" "$V" "$BACKUP_MS" "$RESTORE_MS" "$READ_SECONDS" > spikes/s5/results-s5/restore_rehearsal.json
echo "restore OK ($PASS checks)"
