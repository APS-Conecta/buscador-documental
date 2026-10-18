-- S5 (schema_ver 4): worker liveness, the night window and the concurrency caps in claim_job (ADR-0015). Idempotent: it runs after
-- 005 on a fresh install and alone on a schema_ver 3 instance (SqlScript::upgradeToV4). bd_owner owns every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
-- Completion times for the ETA: stamped when the state turns indexed / embedded, not by every touch of the row.
ALTER TABLE file_state ADD COLUMN IF NOT EXISTS indexed_at timestamptz;
-- @@
ALTER TABLE file_state ADD COLUMN IF NOT EXISTS embedded_at timestamptz;
-- @@
UPDATE file_state SET indexed_at = CASE WHEN text_state = 'indexed' AND indexed_at IS NULL THEN updated_at ELSE indexed_at END,
	embedded_at = CASE WHEN vec_state = 'embedded' AND embedded_at IS NULL THEN updated_at ELSE embedded_at END
	WHERE (text_state = 'indexed' AND indexed_at IS NULL) OR (vec_state = 'embedded' AND embedded_at IS NULL);
-- @@
CREATE INDEX IF NOT EXISTS file_state_indexed_at_idx ON file_state (indexed_at) WHERE indexed_at IS NOT NULL;
-- @@
CREATE INDEX IF NOT EXISTS file_state_embedded_at_idx ON file_state (embedded_at) WHERE embedded_at IS NOT NULL;
-- @@
CREATE OR REPLACE FUNCTION aps_search.file_state_done() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	IF NEW.text_state = 'indexed' AND OLD.text_state IS DISTINCT FROM 'indexed' THEN
		NEW.indexed_at := now();
	END IF;
	IF NEW.vec_state = 'embedded' AND OLD.vec_state IS DISTINCT FROM 'embedded' THEN
		NEW.embedded_at := now();
	END IF;
	RETURN NEW;
END
$$;
-- @@
REVOKE ALL ON FUNCTION aps_search.file_state_done() FROM PUBLIC;
-- @@
DROP TRIGGER IF EXISTS file_state_done ON file_state;
-- @@
CREATE TRIGGER file_state_done BEFORE UPDATE ON file_state FOR EACH ROW EXECUTE FUNCTION aps_search.file_state_done();
-- @@
CREATE TABLE IF NOT EXISTS worker_seen (
	worker text NOT NULL,
	lane text NOT NULL,
	at timestamptz NOT NULL DEFAULT now(),
	PRIMARY KEY (worker, lane)
);
-- @@
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
	UPDATE file_state fs SET text_state = 'skipped', skip_reason = 'internal', updated_at = now()
		FROM job j WHERE j.lane = 'text' AND j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp();
	UPDATE file_state fs SET vec_state = 'failed', updated_at = now()
		FROM job j WHERE j.lane = 'vec' AND j.file_id = fs.file_id AND j.attempts >= 5
		  AND j.lease_until IS NOT NULL AND j.lease_until <= clock_timestamp()
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
CREATE OR REPLACE FUNCTION aps_search.heartbeat(p_job_id bigint, p_lease_epoch bigint, p_lease_seconds int DEFAULT 900)
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
	-- A long job is still a live worker (the worker polls only between jobs).
	UPDATE worker_seen SET at = now() WHERE worker = j.claimed_by AND lane = j.lane;
	RETURN true;
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.write_vectors(
	p_job_id bigint, p_lease_epoch bigint, p_etag text, p_chunk_ids bigint[], p_vectors text[])
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	j job%ROWTYPE;
	fs file_state%ROWTYPE;
	vs text;
	tbl text;
	d int;
	complete boolean;
BEGIN
	SELECT * INTO j FROM job WHERE id = p_job_id FOR UPDATE;
	IF NOT FOUND OR j.lane <> 'vec' OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RAISE EXCEPTION 'stale_lease' USING ERRCODE = 'BD001';
	END IF;
	SELECT * INTO fs FROM file_state WHERE file_id = j.file_id FOR UPDATE;
	IF NOT FOUND OR fs.text_state = 'skipped' THEN
		RAISE EXCEPTION 'file_skipped' USING ERRCODE = 'BD001';
	END IF;
	IF p_etag IS DISTINCT FROM j.claimed_etag OR fs.etag IS DISTINCT FROM j.claimed_etag THEN
		RAISE EXCEPTION 'etag_mismatch' USING ERRCODE = 'BD001';
	END IF;
	SELECT e.dim INTO d FROM embedding_profile e WHERE e.gen = j.gen AND e.state <> 'retired';
	IF NOT FOUND THEN
		RAISE EXCEPTION 'retired' USING ERRCODE = 'BD001';
	END IF;
	IF p_chunk_ids IS NULL OR p_vectors IS NULL OR cardinality(p_chunk_ids) <> cardinality(p_vectors)
		OR cardinality(p_chunk_ids) > 2000 THEN
		RAISE EXCEPTION 'bad_vector' USING ERRCODE = 'BD001';
	END IF;
	IF (SELECT count(DISTINCT i) FROM unnest(p_chunk_ids) i) <> cardinality(p_chunk_ids) THEN
		RAISE EXCEPTION 'bad_vector' USING ERRCODE = 'BD001';        -- repeated ids (and NULLs, which count as none)
	END IF;
	IF EXISTS (SELECT 1 FROM unnest(p_chunk_ids) i
			WHERE i IS NULL OR NOT EXISTS (SELECT 1 FROM chunk c WHERE c.chunk_id = i AND c.file_id = j.file_id)) THEN
		RAISE EXCEPTION 'bad_vector' USING ERRCODE = 'BD001';
	END IF;
	vs := aps_search.vec_schema();
	tbl := 'chunk_vec_' || j.gen;
	BEGIN
		EXECUTE format('INSERT INTO aps_search.%I (chunk_id, file_id, vec)
			SELECT x.id, $1, x.v::%I.halfvec(%s) FROM unnest($2, $3) AS x(id, v)
			ON CONFLICT (chunk_id) DO UPDATE SET vec = EXCLUDED.vec', tbl, vs, d) USING j.file_id, p_chunk_ids, p_vectors;
	EXCEPTION WHEN SQLSTATE '22000' OR SQLSTATE '22003' OR SQLSTATE '22P02' OR SQLSTATE '22023' THEN
		RAISE EXCEPTION 'bad_vector' USING ERRCODE = 'BD001';
	END;
	EXECUTE format('SELECT NOT EXISTS (SELECT 1 FROM aps_search.chunk c WHERE c.file_id = $1
		AND NOT EXISTS (SELECT 1 FROM aps_search.%I v WHERE v.chunk_id = c.chunk_id))', tbl) INTO complete USING j.file_id;
	IF complete THEN
		DELETE FROM job WHERE id = p_job_id;
		-- Every finished generation counts for the rate shown by occ buscador:status, not only the one searches use.
		UPDATE file_state SET embedded_at = now() WHERE file_id = j.file_id;
		-- vec_state describes the generation searches use (or the first one, while none is active yet).
		UPDATE file_state SET vec_state = 'embedded', updated_at = now() WHERE file_id = j.file_id
			AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
				OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
	END IF;
	RETURN complete;
END
$$;
-- @@
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
UPDATE schema_meta SET schema_ver = 4 WHERE schema_ver < 4;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
