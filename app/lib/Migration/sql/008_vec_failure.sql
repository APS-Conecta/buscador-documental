-- Schema 6: a permanent vector failure is remembered per (file, generation), since vec_state holds one value per file (the
-- generation searches use). Idempotent: it runs after 007 on a fresh install and alone on a schema_ver 5 instance
-- (SqlScript::upgradeToV6). bd_owner owns every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
-- A generation gave up on this file's vectors. No foreign keys: erase_files and drop_generation delete the rows themselves, and
-- a key on gen would make fail_job lock the generation's row while it holds the job's, the reverse of drop_generation's order.
CREATE TABLE IF NOT EXISTS vec_failure (
	file_id bigint NOT NULL,
	gen int NOT NULL,
	PRIMARY KEY (file_id, gen)
);
-- @@
-- Failures recorded before this step: vec_state described the active generation, or every live one while none was active.
INSERT INTO vec_failure (file_id, gen)
	SELECT fs.file_id, e.gen FROM file_state fs
	JOIN embedding_profile e ON e.state = 'active'
		OR (e.state IN ('building', 'ready') AND NOT EXISTS (SELECT 1 FROM embedding_profile a WHERE a.state = 'active'))
	WHERE fs.vec_state = 'failed'
	ON CONFLICT DO NOTHING;
-- @@
-- fail_job: as in 005, plus the failure record for the job's generation, kept only for the file version the job was for.
CREATE OR REPLACE FUNCTION aps_search.fail_job(p_job_id bigint, p_lease_epoch bigint, p_reason text, p_retryable boolean)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	j job%ROWTYPE;
BEGIN
	IF p_reason IS NULL OR p_reason NOT IN ('encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout', 'internal', 'unsafe',
			'embed_failed', 'model_mismatch') THEN
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
	ELSIF j.lane = 'vec' THEN
		-- A give-up for an older version of the file records nothing: the new version gets its own job.
		IF EXISTS (SELECT 1 FROM file_state WHERE file_id = j.file_id AND etag IS NOT DISTINCT FROM j.claimed_etag) THEN
			INSERT INTO vec_failure (file_id, gen)
				SELECT j.file_id, j.gen WHERE EXISTS (SELECT 1 FROM embedding_profile e WHERE e.gen = j.gen AND e.state <> 'retired')
				ON CONFLICT DO NOTHING;
			UPDATE file_state SET vec_state = 'failed', updated_at = now() WHERE file_id = j.file_id
				AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
					OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
		END IF;
		DELETE FROM job WHERE id = p_job_id;
	ELSE
		UPDATE file_state SET text_state = 'skipped', skip_reason = p_reason, updated_at = now()
			WHERE file_id = j.file_id;
		DELETE FROM job WHERE id = p_job_id;
	END IF;
END
$$;
-- @@
-- Queues vec jobs for indexed files that still lack vectors in the generation, have no job for it and did not fail in it. Idempotent.
CREATE OR REPLACE FUNCTION aps_search.enqueue_vec_backfill(p_gen int, p_limit int)
RETURNS int
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	n int;
BEGIN
	IF NOT EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen AND state <> 'retired') THEN
		RETURN 0;
	END IF;
	EXECUTE format($f$
		WITH c AS (
			SELECT fs.file_id, fs.etag FROM aps_search.file_state fs
			WHERE fs.text_state = 'indexed'
			  AND NOT EXISTS (SELECT 1 FROM aps_search.vec_failure f WHERE f.file_id = fs.file_id AND f.gen = $1)
			  AND EXISTS (SELECT 1 FROM aps_search.chunk ch WHERE ch.file_id = fs.file_id
					AND NOT EXISTS (SELECT 1 FROM aps_search.%I v WHERE v.chunk_id = ch.chunk_id))
			  AND NOT EXISTS (SELECT 1 FROM aps_search.job j WHERE j.lane = 'vec' AND j.file_id = fs.file_id AND j.gen = $1)
			ORDER BY fs.file_id LIMIT $2),
		i AS (
			INSERT INTO aps_search.job (lane, file_id, idem_key, gen)
			SELECT 'vec', c.file_id, c.file_id::text || ':' || coalesce(c.etag, '') || ':' || $1::text, $1 FROM c
			ON CONFLICT (idem_key) DO NOTHING RETURNING 1)
		SELECT count(*) FROM i$f$, 'chunk_vec_' || p_gen) INTO n USING p_gen, greatest(p_limit, 0);
	RETURN n;
END
$$;
-- @@
-- claim_job: as in 006, plus the failure record when the attempts cap gives a vec job up.
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
	v_per int;
	v_window text;
	v_tz text;
	v_now time;
	v_from time;
	v_to time;
	v_inside boolean;
	v_cap text;
	r record;
BEGIN
	IF p_worker IS NULL OR length(p_worker) = 0 THEN
		RAISE EXCEPTION 'worker id required' USING ERRCODE = 'BD001';
	END IF;
	IF p_worker !~ '^[A-Za-z0-9_-]{1,64}$' THEN
		RAISE EXCEPTION 'bad worker id' USING ERRCODE = 'BD001';
	END IF;
	IF p_lane NOT IN ('text', 'vec') THEN
		RETURN;                                           -- there is no other lane; nothing is recorded for a made-up one
	END IF;
	-- Every call proves the worker is alive, paused or not (occ buscador:doctor reads this). A known worker is refreshed; a new
	-- one is added only while the table is small, so inventing names cannot grow it (a worker holds the DSN and picks its name).
	IF EXISTS (SELECT 1 FROM worker_seen WHERE worker = p_worker AND lane = p_lane) THEN
		UPDATE worker_seen SET at = now() WHERE worker = p_worker AND lane = p_lane;
	ELSIF (SELECT count(*) FROM worker_seen) < 200 THEN
		INSERT INTO worker_seen (worker, lane, at) VALUES (p_worker, p_lane, now()) ON CONFLICT (worker, lane) DO UPDATE SET at = EXCLUDED.at;
	END IF;
	-- Paused by an administrator (ADR-0013): no new claims. Work in flight finishes or its lease expires.
	IF EXISTS (SELECT 1 FROM settings WHERE key = 'paused' AND value = '1') THEN
		RETURN;
	END IF;
	-- Night window (ADR-0015): outside it the covered lanes (vec, or all) get nothing. An unusable setting never blocks the index.
	SELECT value INTO v_window FROM settings WHERE key = 'work_window';
	IF v_window ~ '^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]$'
		AND (p_lane = 'vec' OR coalesce((SELECT value FROM settings WHERE key = 'work_window_lanes'), 'vec') = 'all') THEN
		v_from := substr(v_window, 1, 5)::time;
		v_to := substr(v_window, 7, 5)::time;
		SELECT coalesce(nullif(value, ''), 'UTC') INTO v_tz FROM settings WHERE key = 'work_tz';
		BEGIN
			v_now := (now() AT TIME ZONE coalesce(v_tz, 'UTC'))::time;
		EXCEPTION WHEN OTHERS THEN
			v_now := (now() AT TIME ZONE 'UTC')::time;        -- an unknown zone is ignored, not obeyed
			v_from := NULL;
		END;
		IF v_from IS NOT NULL AND v_from <> v_to THEN
			v_inside := CASE WHEN v_from < v_to THEN v_now >= v_from AND v_now < v_to ELSE v_now >= v_from OR v_now < v_to END;
			IF NOT v_inside THEN
				RETURN;
			END IF;
		END IF;
	END IF;
	-- Serialise claimers so the caps below hold under READ COMMITTED.
	PERFORM pg_advisory_xact_lock(hashtext('aps_search.claim_job'));
	-- Concurrency caps derive from live claims in the table, never from a cache counter (D3). The worker id is self-declared,
	-- so a per-lane global cap backs up the per-worker one (M1).
	SELECT count(*) INTO v_active FROM job j
		WHERE j.lane = p_lane AND j.claimed_by = p_worker AND j.lease_until > clock_timestamp();
	SELECT count(*) INTO v_total FROM job j WHERE j.lane = p_lane AND j.lease_until > clock_timestamp();
	-- Per-worker cap from settings (cap_text, cap_vec: 1 to 16, anything else means the default); the lane-wide cap stays a multiple of it.
	SELECT value INTO v_cap FROM settings WHERE key = CASE p_lane WHEN 'vec' THEN 'cap_vec' ELSE 'cap_text' END;
	v_per := CASE WHEN v_cap ~ '^[0-9]{1,2}$' AND v_cap::int BETWEEN 1 AND 16 THEN v_cap::int ELSE CASE p_lane WHEN 'vec' THEN 2 ELSE 4 END END;
	v_n := least(coalesce(p_n, 1), v_per - v_active, v_per * (CASE p_lane WHEN 'vec' THEN 2 ELSE 4 END) - v_total);
	IF v_n <= 0 THEN
		RETURN;
	END IF;
	-- A job whose lease keeps expiring (worker killed by the file, or file gone) is given up after 5 claims.
	-- Job rows first, in id order, then file_state: the same order write_result and fail_job take, so two claimers cannot deadlock.
	PERFORM 1 FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp() ORDER BY j.id FOR UPDATE;
	-- A vec give-up is recorded for the job's generation, and only for the file version the job was for (see fail_job).
	INSERT INTO vec_failure (file_id, gen)
		SELECT j.file_id, j.gen FROM job j
		JOIN file_state fs ON fs.file_id = j.file_id AND fs.etag IS NOT DISTINCT FROM j.claimed_etag
		JOIN embedding_profile e ON e.gen = j.gen AND e.state <> 'retired'
		WHERE j.lane = 'vec' AND j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp()
		ON CONFLICT DO NOTHING;
	UPDATE file_state fs SET text_state = 'skipped', skip_reason = 'internal', updated_at = now()
		FROM job j WHERE j.lane = 'text' AND j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp();
	UPDATE file_state fs SET vec_state = 'failed', updated_at = now()
		FROM job j WHERE j.lane = 'vec' AND j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp() AND fs.etag IS NOT DISTINCT FROM j.claimed_etag
		  AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
			OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
	DELETE FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp();
	-- Jobs for files that were excluded meanwhile are dropped instead of claimed.
	DELETE FROM job j USING file_state fs
		WHERE fs.file_id = j.file_id AND fs.text_state = 'skipped';
	-- A vec job for an older etag, or for a retired generation, that nobody holds is obsolete.
	DELETE FROM job j USING file_state fs
		WHERE j.lane = 'vec' AND fs.file_id = j.file_id
		  AND (j.lease_until IS NULL OR j.lease_until <= clock_timestamp())
		  AND (j.idem_key <> j.file_id::text || ':' || coalesce(fs.etag, '') || ':' || coalesce(j.gen::text, '')
			OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.gen = j.gen AND e.state <> 'retired'));
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
-- write_result: as in 007, plus new text clears the file's failure records.
CREATE OR REPLACE FUNCTION aps_search.write_result(
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
		OR EXISTS (SELECT 1 FROM jsonb_array_elements(p_chunks) c WHERE length(c->>'text') > 20000)
		-- Every page and chunk row copies these three, so their size multiplies: bound them (S7 STRIDE review).
		OR length(p_ocr_ver) > 300 OR length(p_chunker_ver) > 100 OR length(p_content_hash) > 128
		OR EXISTS (SELECT 1 FROM jsonb_array_elements(p_pages) p WHERE length(p->>'text') > 2000000) THEN
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
		ocr_ver = p_ocr_ver, chunker_ver = p_chunker_ver, skip_reason = NULL, updated_at = now(),
		vec_state = CASE WHEN EXISTS (SELECT 1 FROM embedding_profile WHERE state IN ('active', 'building', 'ready'))
			THEN 'pending' ELSE vec_state END
		WHERE file_id = j.file_id;
	-- New text for the file: every generation tries its vectors again.
	DELETE FROM vec_failure WHERE file_id = j.file_id;
	INSERT INTO job (lane, file_id, idem_key, gen)
		SELECT 'vec', j.file_id, j.file_id::text || ':' || coalesce(fs.etag, '') || ':' || e.gen::text, e.gen
		FROM embedding_profile e WHERE e.state IN ('active', 'building', 'ready')
		ON CONFLICT (idem_key) DO NOTHING;
	DELETE FROM job WHERE id = p_job_id;
END
$$;
-- @@
-- erase_files: as in 004, plus the files' failure records (every trace goes, ADR-0013); purge_files and erase_file call it.
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
	DELETE FROM vec_failure WHERE file_id = ANY(p_file_ids);
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
-- activate_generation: as in 005, plus vec_state brought in line with the failures of the generation it activates.
CREATE OR REPLACE FUNCTION aps_search.activate_generation(p_gen int)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	PERFORM 1 FROM embedding_profile ORDER BY gen FOR UPDATE;       -- serialises with drop_generation and other activations
	IF NOT EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen AND state IN ('ready', 'active')) THEN
		RAISE EXCEPTION 'generation % is not ready', p_gen;
	END IF;
	UPDATE embedding_profile SET state = 'retired' WHERE state = 'active' AND gen <> p_gen;
	UPDATE embedding_profile SET state = 'active' WHERE gen = p_gen;
	-- vec_state follows the generation searches now use: its failures count, and the old generation's no longer do.
	UPDATE file_state fs SET vec_state = 'failed', updated_at = now()
		WHERE fs.vec_state <> 'failed' AND EXISTS (SELECT 1 FROM vec_failure f WHERE f.file_id = fs.file_id AND f.gen = p_gen);
	EXECUTE format($f$
		UPDATE aps_search.file_state fs SET vec_state = CASE WHEN NOT EXISTS (SELECT 1 FROM aps_search.chunk c WHERE c.file_id = fs.file_id
				AND NOT EXISTS (SELECT 1 FROM aps_search.%I v WHERE v.chunk_id = c.chunk_id)) THEN 'embedded' ELSE 'pending' END,
			updated_at = now()
		WHERE fs.vec_state = 'failed' AND fs.text_state = 'indexed'
		  AND NOT EXISTS (SELECT 1 FROM aps_search.vec_failure f WHERE f.file_id = fs.file_id AND f.gen = $1)$f$,
		'chunk_vec_' || p_gen) USING p_gen;
END
$$;
-- @@
-- drop_generation: as in 005, plus its failure records (a later generation may get the same number).
CREATE OR REPLACE FUNCTION aps_search.drop_generation(p_gen int)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	PERFORM 1 FROM embedding_profile ORDER BY gen FOR UPDATE;       -- serialises with activate_generation
	IF EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen AND state = 'active') THEN
		RAISE EXCEPTION 'generation % is the active generation', p_gen;
	END IF;
	IF NOT EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen) THEN
		RAISE EXCEPTION 'unknown generation %', p_gen;
	END IF;
	PERFORM 1 FROM job WHERE lane = 'vec' AND gen = p_gen ORDER BY id FOR UPDATE;
	DELETE FROM job WHERE lane = 'vec' AND gen = p_gen;
	DELETE FROM vec_failure WHERE gen = p_gen;
	EXECUTE format('DROP TABLE IF EXISTS aps_search.%I', 'chunk_vec_' || p_gen);
	DELETE FROM embedding_profile WHERE gen = p_gen;
END
$$;
-- @@
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
UPDATE schema_meta SET schema_ver = 6 WHERE schema_ver < 6;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
