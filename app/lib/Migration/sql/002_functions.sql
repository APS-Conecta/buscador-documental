-- The only way the worker role touches the index. All SECURITY DEFINER, owned by bd_owner, search_path pinned.
-- Lock order everywhere: job row, then file_state row (H2). Leases compare against clock_timestamp(), not now().
-- Errors carry SQLSTATE BD001 and one of: stale_lease, etag_mismatch, hash_mismatch, file_skipped, payload_too_large.
SET ROLE bd_owner;
-- @@
CREATE FUNCTION aps_search.claim_job(p_lane text, p_n int, p_worker text, p_lease_seconds int DEFAULT 900)
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
	-- Job rows first, in id order, then file_state: the same order write_result and fail_job take, so two claimers cannot deadlock.
	PERFORM 1 FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp() ORDER BY j.id FOR UPDATE;
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
CREATE FUNCTION aps_search.heartbeat(p_job_id bigint, p_lease_epoch bigint, p_lease_seconds int DEFAULT 900)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	j job%ROWTYPE;
BEGIN
	SELECT * INTO j FROM job WHERE id = p_job_id FOR UPDATE;
	IF NOT FOUND OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RETURN false;
	END IF;
	UPDATE job SET lease_until = clock_timestamp() + make_interval(secs => least(greatest(p_lease_seconds, 1), 3600))
		WHERE id = p_job_id;
	RETURN true;
END
$$;
-- @@
CREATE FUNCTION aps_search.write_result(
	p_job_id bigint, p_lease_epoch bigint, p_etag text, p_content_hash text,
	p_pages jsonb, p_chunks jsonb, p_ocr_ver text DEFAULT NULL, p_chunker_ver text DEFAULT NULL)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	j job%ROWTYPE;
	fs file_state%ROWTYPE;
BEGIN
	SELECT * INTO j FROM job WHERE id = p_job_id FOR UPDATE;
	IF NOT FOUND OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RAISE EXCEPTION 'stale_lease' USING ERRCODE = 'BD001';
	END IF;
	SELECT * INTO fs FROM file_state WHERE file_id = j.file_id FOR UPDATE;
	IF NOT FOUND OR fs.text_state = 'skipped' THEN
		RAISE EXCEPTION 'file_skipped' USING ERRCODE = 'BD001';
	END IF;
	IF p_etag IS DISTINCT FROM j.claimed_etag OR fs.etag IS DISTINCT FROM j.claimed_etag THEN
		RAISE EXCEPTION 'etag_mismatch' USING ERRCODE = 'BD001';
	END IF;
	IF j.claimed_hash IS NOT NULL AND p_content_hash IS DISTINCT FROM j.claimed_hash THEN
		RAISE EXCEPTION 'hash_mismatch' USING ERRCODE = 'BD001';
	END IF;
	IF p_pages IS NULL OR p_chunks IS NULL OR jsonb_typeof(p_pages) <> 'array' OR jsonb_typeof(p_chunks) <> 'array'
		OR jsonb_array_length(p_pages) > 10000 OR jsonb_array_length(p_chunks) > 2000
		OR octet_length(p_pages::text) > 64000000 OR octet_length(p_chunks::text) > 16000000
		OR EXISTS (SELECT 1 FROM jsonb_array_elements(p_chunks) c WHERE length(c->>'text') > 20000) THEN
		RAISE EXCEPTION 'payload_too_large' USING ERRCODE = 'BD001';
	END IF;
	-- file_id comes from the job row only; the payload has no file_id and any such key is ignored.
	DELETE FROM page WHERE file_id = j.file_id;
	DELETE FROM chunk WHERE file_id = j.file_id;
	INSERT INTO page (file_id, page_no, text, ocr_conf, ocr_ver, source)
		SELECT j.file_id, x.page_no, x.text, x.ocr_conf, p_ocr_ver, x.source
		FROM jsonb_to_recordset(p_pages) AS x(page_no int, text text, ocr_conf real, source text);
	INSERT INTO chunk (file_id, page_no, ord, heading, text, chunker_ver)
		SELECT j.file_id, x.page_no, x.ord, x.heading, x.text, p_chunker_ver
		FROM jsonb_to_recordset(p_chunks) AS x(page_no int, ord int, heading text, text text);
	UPDATE file_state SET text_state = 'indexed', content_hash = p_content_hash,
		ocr_ver = p_ocr_ver, chunker_ver = p_chunker_ver, skip_reason = NULL, updated_at = now()
		WHERE file_id = j.file_id;
	DELETE FROM job WHERE id = p_job_id;
END
$$;
-- @@
CREATE FUNCTION aps_search.fail_job(p_job_id bigint, p_lease_epoch bigint, p_reason text, p_retryable boolean)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	j job%ROWTYPE;
BEGIN
	IF p_reason IS NULL OR p_reason NOT IN ('encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout', 'internal', 'unsafe') THEN
		RAISE EXCEPTION 'invalid reason' USING ERRCODE = 'BD001';
	END IF;
	SELECT * INTO j FROM job WHERE id = p_job_id FOR UPDATE;
	IF NOT FOUND OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RAISE EXCEPTION 'stale_lease' USING ERRCODE = 'BD001';
	END IF;
	IF p_retryable AND j.attempts < 5 THEN
		UPDATE job SET lease_until = NULL, claimed_by = NULL,
			not_before = clock_timestamp() + make_interval(mins => power(2, j.attempts)::int)
			WHERE id = p_job_id;
	ELSE
		UPDATE file_state SET text_state = 'skipped', skip_reason = p_reason, updated_at = now()
			WHERE file_id = j.file_id;
		DELETE FROM job WHERE id = p_job_id;
	END IF;
END
$$;
-- @@
-- Called by Nextcloud (IndexEraser), never by the worker. One transaction, same lock order as the worker functions (H1, H2).
CREATE FUNCTION aps_search.erase_file(p_file_id bigint, p_reason text)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	PERFORM 1 FROM job WHERE file_id = p_file_id ORDER BY id FOR UPDATE;
	INSERT INTO file_state (file_id, text_state, skip_reason, updated_at)
		VALUES (p_file_id, 'skipped', p_reason, now())
		ON CONFLICT (file_id) DO UPDATE SET text_state = 'skipped', skip_reason = p_reason, updated_at = now();
	DELETE FROM job WHERE file_id = p_file_id;
	DELETE FROM page WHERE file_id = p_file_id;
	DELETE FROM chunk WHERE file_id = p_file_id;
	DELETE FROM file_acl WHERE file_id = p_file_id;
	DELETE FROM file_meta WHERE file_id = p_file_id;
END
$$;
-- @@
RESET ROLE;
