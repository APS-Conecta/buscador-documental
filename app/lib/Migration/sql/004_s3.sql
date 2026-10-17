-- S3 (schema_ver 2): outbox, tombstone, audit_log, scope rules, reconcile cursor, settings, deleted-user bookkeeping,
-- batch erase primitives, the worker's schema check. Idempotent: it runs after 003 on a fresh install and alone on a
-- schema_ver 1 instance (SqlScript::upgradeToV2). Run as the Nextcloud DB user; bd_owner owns every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
CREATE TABLE IF NOT EXISTS outbox (
	id bigserial PRIMARY KEY,
	file_id bigint NOT NULL,
	kind text NOT NULL CHECK (kind IN ('file', 'subtree', 'acl', 'rule')),
	created_at timestamptz NOT NULL DEFAULT now(),
	processed_at timestamptz,
	attempts int NOT NULL DEFAULT 0
);
-- @@
-- A row being worked on (claimed_at) and a retry waiting out its backoff (not_before).
ALTER TABLE outbox ADD COLUMN IF NOT EXISTS claimed_at timestamptz;
-- @@
ALTER TABLE outbox ADD COLUMN IF NOT EXISTS not_before timestamptz NOT NULL DEFAULT now();
-- @@
-- Level-triggered (ADR-0013): one WAITING row per (file, kind) is enough, a row only means "re-evaluate this file".
-- A claimed row and a parked row (5 attempts) no longer hold the slot: an event that arrives while a row is in flight
-- becomes its own row (it would otherwise be lost when the in-flight one is settled), and a parked row can never block
-- the events that follow it.
DROP INDEX IF EXISTS outbox_pending_uq;
-- @@
CREATE UNIQUE INDEX IF NOT EXISTS outbox_pending_uq ON outbox (file_id, kind) WHERE processed_at IS NULL AND claimed_at IS NULL AND attempts < 5;
-- @@
CREATE INDEX IF NOT EXISTS outbox_processed_idx ON outbox (processed_at) WHERE processed_at IS NOT NULL;
-- @@
CREATE TABLE IF NOT EXISTS tombstone (
	file_id bigint PRIMARY KEY,
	reason text NOT NULL,
	created_at timestamptz NOT NULL DEFAULT now(),
	purge_after timestamptz
);
-- @@
-- actor and subject_token are pseudonyms; detail is a closed JSON schema (counts, reason codes, rule ids), never text.
CREATE TABLE IF NOT EXISTS audit_log (
	id bigserial PRIMARY KEY,
	at timestamptz NOT NULL DEFAULT now(),
	actor text NOT NULL,
	action text NOT NULL,
	subject_token text,
	detail jsonb NOT NULL DEFAULT '{}'
);
-- @@
CREATE INDEX IF NOT EXISTS audit_log_at_idx ON audit_log (at);
-- @@
CREATE TABLE IF NOT EXISTS scope_rule (
	id bigserial PRIMARY KEY,
	kind text NOT NULL CHECK (kind IN ('folder', 'all_groupfolders')),
	file_id bigint,
	created_by_token text,
	created_at timestamptz NOT NULL DEFAULT now(),
	CHECK ((kind = 'folder') = (file_id IS NOT NULL))
);
-- @@
CREATE TABLE IF NOT EXISTS reconcile_run (
	run_id bigserial PRIMARY KEY,
	started_at timestamptz NOT NULL DEFAULT now(),
	phase text NOT NULL DEFAULT 'eligible',
	cursor_file_id bigint NOT NULL DEFAULT 0,
	rules_fp text,
	counts jsonb NOT NULL DEFAULT '{}',
	finished_at timestamptz
);
-- @@
-- The files that should be indexed in one reconciliation run, materialised once so the passes can window over them.
-- UNLOGGED: it is rebuilt by a run that finds it empty and costs no WAL on a large instance.
CREATE UNLOGGED TABLE IF NOT EXISTS reconcile_node (
	run_id bigint NOT NULL,
	file_id bigint NOT NULL,
	PRIMARY KEY (run_id, file_id)
);
-- @@
CREATE TABLE IF NOT EXISTS settings (
	key text PRIMARY KEY,
	value text NOT NULL
);
-- @@
CREATE TABLE IF NOT EXISTS purge_pending (
	token text PRIMARY KEY,
	storage_numeric_id bigint NOT NULL,
	created_at timestamptz NOT NULL DEFAULT now()
);
-- @@
-- uid -> random token; the row is deleted when the user is erased, which makes the audit rows unlinkable.
CREATE TABLE IF NOT EXISTS pseudonym (
	uid text PRIMARY KEY,
	token text NOT NULL UNIQUE,
	created_at timestamptz NOT NULL DEFAULT now()
);
-- @@
ALTER TABLE file_state ADD COLUMN IF NOT EXISTS acl_dirty_since timestamptz;
-- @@
ALTER TABLE file_state ADD COLUMN IF NOT EXISTS reconciled_at timestamptz;
-- @@
CREATE INDEX IF NOT EXISTS file_state_acl_dirty_idx ON file_state (acl_dirty_since) WHERE acl_dirty;
-- @@
ALTER TABLE file_meta ADD COLUMN IF NOT EXISTS storage_id bigint;
-- @@
-- NOT VALID: rows written by S1/S2 keep whatever they hold; every new or updated row must use the vocabulary.
ALTER TABLE file_state DROP CONSTRAINT IF EXISTS file_state_skip_reason_chk;
-- @@
ALTER TABLE file_state ADD CONSTRAINT file_state_skip_reason_chk CHECK (skip_reason IS NULL OR skip_reason IN
	('encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout', 'internal', 'unsafe',
	 'excluded', 'out_of_scope', 'trashed', 'user_deleted')) NOT VALID;
-- @@
-- claim_job with the pause check: a copy of the 002 function so an instance upgraded from schema 1 gets it too
-- (a test keeps the two bodies identical).
CREATE OR REPLACE FUNCTION aps_search.claim_job(p_lane text, p_n int, p_worker text, p_lease_seconds int DEFAULT 900)
RETURNS TABLE (job_id bigint, lease_epoch bigint, file_id bigint, etag text)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
#variable_conflict use_column
DECLARE
	v_active int;
	v_total int;
	v_n int;
	r record;
BEGIN
	IF p_worker IS NULL OR length(p_worker) = 0 THEN
		RAISE EXCEPTION 'worker id required' USING ERRCODE = 'BD001';
	END IF;
	-- Paused by an administrator (ADR-0013): no new claims. Work in flight finishes or its lease expires.
	IF EXISTS (SELECT 1 FROM settings WHERE key = 'paused' AND value = '1') THEN
		RETURN;
	END IF;
	-- Serialise claimers so the caps below hold under READ COMMITTED.
	PERFORM pg_advisory_xact_lock(hashtext('aps_search.claim_job'));
	-- Concurrency cap derives from live claims in the table, never from a cache counter (D3).
	SELECT count(*) INTO v_active FROM job j
		WHERE j.claimed_by = p_worker AND j.lease_until > clock_timestamp();
	-- Global cap too (M1): the worker id is self-declared, so per-worker caps alone can be dodged by renaming.
	SELECT count(*) INTO v_total FROM job j WHERE j.lease_until > clock_timestamp();
	v_n := least(coalesce(p_n, 1), 4 - v_active, 16 - v_total);
	IF v_n <= 0 THEN
		RETURN;
	END IF;
	-- A job whose lease keeps expiring (worker killed by the file, or file gone) is given up after 5 claims.
	UPDATE file_state fs SET text_state = 'skipped', skip_reason = 'internal', updated_at = now()
		FROM job j WHERE j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp();
	DELETE FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp();
	-- Jobs for files that were excluded meanwhile are dropped instead of claimed.
	DELETE FROM job j USING file_state fs
		WHERE fs.file_id = j.file_id AND fs.text_state = 'skipped';
	FOR r IN
		SELECT j.id FROM job j
		WHERE j.lane = p_lane
		  AND j.not_before <= clock_timestamp()
		  AND (j.lease_until IS NULL OR j.lease_until <= clock_timestamp())
		ORDER BY j.id
		LIMIT v_n
		FOR UPDATE SKIP LOCKED
	LOOP
		RETURN QUERY
		UPDATE job j SET
			lease_epoch = j.lease_epoch + 1,
			lease_until = clock_timestamp() + make_interval(secs => least(greatest(p_lease_seconds, 1), 3600)),
			claimed_by = p_worker,
			claimed_etag = fs.etag,
			claimed_hash = NULL,   -- set only when a hash is known beforehand (S2 dedupe); S1 never pins one
			attempts = j.attempts + 1
		FROM file_state fs
		WHERE j.id = r.id AND fs.file_id = j.file_id
		RETURNING j.id, j.lease_epoch, j.file_id, j.claimed_etag;
	END LOOP;
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.schema_version()
RETURNS int
LANGUAGE sql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$ SELECT schema_ver FROM aps_search.schema_meta $$;
-- @@
-- Deletes every trace of the given files except a visible `skipped(reason)` state. Same lock order as the worker functions
-- (job rows first, in id order). Tables chunk_vec_<gen> (S4) carry file_id and are emptied for these files too.
CREATE OR REPLACE FUNCTION aps_search.erase_files(p_file_ids bigint[], p_reason text)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	t text;
BEGIN
	PERFORM 1 FROM job WHERE file_id = ANY(p_file_ids) ORDER BY id FOR UPDATE;
	INSERT INTO file_state (file_id, text_state, skip_reason, updated_at)
		SELECT DISTINCT unnest(p_file_ids), 'skipped', p_reason, now()
		ON CONFLICT (file_id) DO UPDATE SET text_state = 'skipped', skip_reason = EXCLUDED.skip_reason,
			acl_dirty = false, acl_dirty_since = NULL, updated_at = now();
	DELETE FROM job WHERE file_id = ANY(p_file_ids);
	FOR t IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
		WHERE n.nspname = 'aps_search' AND c.relkind = 'r' AND c.relname ~ '^chunk_vec_[0-9]+$' LOOP
		EXECUTE format('DELETE FROM aps_search.%I WHERE file_id = ANY($1)', t) USING p_file_ids;
	END LOOP;
	DELETE FROM page WHERE file_id = ANY(p_file_ids);
	DELETE FROM chunk WHERE file_id = ANY(p_file_ids);
	DELETE FROM file_acl WHERE file_id = ANY(p_file_ids);
	DELETE FROM file_meta WHERE file_id = ANY(p_file_ids);
END
$$;
-- @@
-- erase_files, then forget the files altogether (their state row too): the user-deletion and gone-from-Nextcloud case.
CREATE OR REPLACE FUNCTION aps_search.purge_files(p_file_ids bigint[])
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	PERFORM aps_search.erase_files(p_file_ids, 'user_deleted');
	DELETE FROM file_state WHERE file_id = ANY(p_file_ids);
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.erase_file(p_file_id bigint, p_reason text)
RETURNS void
LANGUAGE sql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$ SELECT aps_search.erase_files(ARRAY[p_file_id]::bigint[], p_reason) $$;
-- @@
REVOKE ALL ON FUNCTION aps_search.schema_version() FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.erase_files(bigint[], text) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.purge_files(bigint[]) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.erase_file(bigint, text) FROM PUBLIC;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.schema_version() TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.schema_version() TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.erase_files(bigint[], text) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.purge_files(bigint[]) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.erase_file(bigint, text) TO SESSION_USER;
-- @@
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
GRANT USAGE ON ALL SEQUENCES IN SCHEMA aps_search TO SESSION_USER;
-- @@
UPDATE schema_meta SET schema_ver = 2 WHERE schema_ver < 2;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
