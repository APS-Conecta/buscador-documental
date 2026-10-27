-- Schema 6: a job given up for an old version of a file leaves the current version's state alone. Idempotent: it runs after 008
-- on a fresh install and alone on a schema_ver 5 instance (SqlScript::upgradeToV6). bd_owner owns every object; CREATE OR REPLACE
-- keeps the owner and the EXECUTE grants.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
-- fail_job: as in 005, but a give-up changes file_state only while it still holds the version the job claimed (the rule write_result
-- and write_vectors apply). A job for an overwritten version is only dropped: the current version's state and jobs stay as they are.
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
		RETURN;
	END IF;
	-- Job row first, then file_state, as write_result takes them. The lock holds the etag until the bookkeeping below is done.
	PERFORM 1 FROM file_state WHERE file_id = j.file_id AND etag IS NOT DISTINCT FROM j.claimed_etag FOR UPDATE;
	IF FOUND THEN
		IF j.lane = 'vec' THEN
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
-- claim_job: as in 006, but its attempts cap takes the etag guard of fail_job.
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
	v_cap_at timestamptz;
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
	-- A job whose lease keeps expiring (worker killed by the file, or file gone) is given up after 5 claims, judged at one instant.
	-- Job rows first, in id order, then file_state, in file order: the same order write_result and fail_job take, so two claimers
	-- cannot deadlock. As in fail_job, a job for an overwritten version is only dropped and the current version's state stays.
	v_cap_at := clock_timestamp();
	PERFORM 1 FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= v_cap_at ORDER BY j.id FOR UPDATE;
	PERFORM 1 FROM file_state fs
		WHERE fs.file_id IN (SELECT j.file_id FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= v_cap_at)
		ORDER BY fs.file_id FOR UPDATE;
	DELETE FROM job j USING file_state fs
		WHERE fs.file_id = j.file_id AND j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= v_cap_at
		  AND fs.etag IS DISTINCT FROM j.claimed_etag;
	UPDATE file_state fs SET text_state = 'skipped', skip_reason = 'internal', updated_at = now()
		FROM job j WHERE j.lane = 'text' AND j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= v_cap_at;
	UPDATE file_state fs SET vec_state = 'failed', updated_at = now()
		FROM job j WHERE j.lane = 'vec' AND j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= v_cap_at
		  AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
			OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
	DELETE FROM job j WHERE j.attempts >= 5 AND j.lease_until IS NOT NULL AND j.lease_until <= v_cap_at;
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
UPDATE schema_meta SET schema_ver = 6 WHERE schema_ver < 6;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
