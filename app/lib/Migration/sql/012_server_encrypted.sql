-- schema_ver 9: the skip reason server_encrypted, for a file Nextcloud stores with server-side encryption (ADR-0007 skips it);
-- encrypted stays the reason for a document that needs its own password. Idempotent: it runs after 011 on a fresh install and
-- alone on a schema_ver 8 instance (SqlScript::upgradeToV9). bd_owner owns every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
-- NOT VALID, as in 004: rows already written keep whatever they hold; every new or updated row must use the vocabulary.
ALTER TABLE file_state DROP CONSTRAINT IF EXISTS file_state_skip_reason_chk;
-- @@
ALTER TABLE file_state ADD CONSTRAINT file_state_skip_reason_chk CHECK (skip_reason IS NULL OR skip_reason IN
	('encrypted', 'server_encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout', 'internal', 'unsafe',
	 'excluded', 'out_of_scope', 'trashed', 'user_deleted')) NOT VALID;
-- @@
-- fail_job: as in 010, with server_encrypted in the text-lane vocabulary; grants and owner are kept by REPLACE.
CREATE OR REPLACE FUNCTION aps_search.fail_job(p_job_id bigint, p_lease_epoch bigint, p_reason text, p_retryable boolean)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	j job%ROWTYPE;
BEGIN
	IF p_reason IS NULL OR p_reason NOT IN ('encrypted', 'server_encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout',
			'internal', 'unsafe', 'embed_failed', 'model_mismatch') THEN
		RAISE EXCEPTION 'invalid reason' USING ERRCODE = 'BD001';
	END IF;
	SELECT * INTO j FROM job WHERE id = p_job_id FOR UPDATE;
	IF NOT FOUND OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RAISE EXCEPTION 'stale_lease' USING ERRCODE = 'BD001';
	END IF;
	IF (j.lane = 'vec' AND p_reason NOT IN ('embed_failed', 'model_mismatch', 'internal', 'timeout'))
		OR (j.lane <> 'vec' AND p_reason IN ('embed_failed', 'model_mismatch')) THEN
		RAISE EXCEPTION 'invalid reason' USING ERRCODE = 'BD001';
	END IF;
	IF p_retryable AND j.attempts < 5 THEN
		UPDATE job SET lease_until = NULL, claimed_by = NULL,
			not_before = clock_timestamp() + make_interval(mins => power(2, j.attempts)::int)
			WHERE id = p_job_id;
		RETURN;
	END IF;
	-- Job row first, then file_state, as write_result takes them. The lock holds the etag until the bookkeeping below is done.
	PERFORM 1 FROM file_state WHERE file_id = j.file_id AND etag IS NOT DISTINCT FROM j.claimed_etag FOR UPDATE;
	IF FOUND THEN
		IF j.lane = 'vec' THEN
			INSERT INTO vec_failure (file_id, gen)
				SELECT j.file_id, j.gen WHERE EXISTS (SELECT 1 FROM embedding_profile e WHERE e.gen = j.gen AND e.state <> 'retired')
				ON CONFLICT DO NOTHING;
			UPDATE file_state SET vec_state = 'failed', updated_at = now() WHERE file_id = j.file_id
				AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
					OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
		ELSE
			UPDATE file_state SET text_state = 'skipped', skip_reason = p_reason, updated_at = now()
				WHERE file_id = j.file_id;
		END IF;
	END IF;
	DELETE FROM job WHERE id = p_job_id;
END
$$;
-- @@
UPDATE schema_meta SET schema_ver = 9 WHERE schema_ver < 9;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
