-- S4 (schema_ver 3): embedding generations, the vector lane, search snapshots, filter data. Idempotent: it runs after 004 on a
-- fresh install and alone on a schema_ver 2 instance (SqlScript::upgradeToV3). Run as the Nextcloud DB user; bd_owner owns
-- every object. pgvector is NOT needed here: a keyword-only install stays valid, create_generation names the missing extension.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
ALTER TABLE file_state DROP CONSTRAINT IF EXISTS file_state_vec_state_chk;
-- @@
ALTER TABLE file_state ADD CONSTRAINT file_state_vec_state_chk
	CHECK (vec_state IN ('pending', 'embedding', 'embedded', 'skipped', 'failed')) NOT VALID;
-- @@
-- Ids of the parent folders up to the storage root (ids only, no names): the folder filter's data.
ALTER TABLE file_meta ADD COLUMN IF NOT EXISTS ancestors bigint[];
-- @@
CREATE INDEX IF NOT EXISTS file_meta_ancestors_idx ON file_meta USING gin (ancestors);
-- @@
-- Files indexed before this step have no folder chain: queue each once so the outbox processor fills it in (idempotent).
INSERT INTO outbox (file_id, kind) SELECT file_id, 'file' FROM file_meta WHERE ancestors IS NULL ON CONFLICT DO NOTHING;
-- @@
-- The embedding generation a vec job is for (NULL for text jobs).
ALTER TABLE job ADD COLUMN IF NOT EXISTS gen int;
-- @@
CREATE TABLE IF NOT EXISTS embedding_profile (
	gen int PRIMARY KEY CHECK (gen >= 1),
	profile_id text NOT NULL,
	model text NOT NULL,
	digest text NOT NULL,
	dim int NOT NULL CHECK (dim BETWEEN 1 AND 4000),
	quant text,
	query_prefix text NOT NULL DEFAULT '',
	passage_prefix text NOT NULL DEFAULT '',
	max_tokens int NOT NULL CHECK (max_tokens > 0),
	state text NOT NULL DEFAULT 'building' CHECK (state IN ('building', 'ready', 'active', 'retired')),
	created_at timestamptz NOT NULL DEFAULT now()
);
-- @@
-- At most one active generation; a ready one never takes over by itself (ADR-0004).
CREATE UNIQUE INDEX IF NOT EXISTS embedding_profile_one_active ON embedding_profile ((true)) WHERE state = 'active';
-- @@
-- Ranked candidate lists for paging: ids only, never text; pruned by housekeeping after ten minutes.
CREATE UNLOGGED TABLE IF NOT EXISTS search_snapshot (
	qid uuid PRIMARY KEY,
	uid_hash text NOT NULL,
	created_at timestamptz NOT NULL DEFAULT now(),
	file_ids bigint[] NOT NULL,
	chunk_ids bigint[] NOT NULL,
	ver int NOT NULL DEFAULT 1
);
-- @@
CREATE INDEX IF NOT EXISTS search_snapshot_created_idx ON search_snapshot (created_at);
-- @@
-- Schema that holds pgvector (its types are not on the pinned search_path).
CREATE OR REPLACE FUNCTION aps_search.vec_schema()
RETURNS text
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	s text;
BEGIN
	SELECT n.nspname INTO s FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'vector';
	IF s IS NULL THEN
		RAISE EXCEPTION 'the vector extension (pgvector 0.8 or later) is not installed in database %. An administrator must run CREATE EXTENSION vector.', current_database();
	END IF;
	RETURN s;
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.create_generation(
	p_gen int, p_profile_id text, p_model text, p_digest text, p_dim int, p_quant text,
	p_query_prefix text, p_passage_prefix text, p_max_tokens int)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	vs text;
	t text;
BEGIN
	IF p_gen IS NULL OR p_gen < 1 THEN
		RAISE EXCEPTION 'invalid generation';
	END IF;
	IF p_dim IS NULL OR p_dim < 1 OR p_dim > 4000 THEN
		RAISE EXCEPTION 'invalid dimension % (1 to 4000)', p_dim;
	END IF;
	vs := aps_search.vec_schema();
	IF EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen) THEN
		RAISE EXCEPTION 'generation % already exists', p_gen;
	END IF;
	INSERT INTO embedding_profile (gen, profile_id, model, digest, dim, quant, query_prefix, passage_prefix, max_tokens)
		VALUES (p_gen, p_profile_id, p_model, p_digest, p_dim, p_quant, coalesce(p_query_prefix, ''), coalesce(p_passage_prefix, ''), p_max_tokens);
	t := 'chunk_vec_' || p_gen;
	-- A plain table keyed by the chunk: a reindex that replaces chunks removes their vectors in the same statement.
	EXECUTE format('CREATE TABLE aps_search.%I (chunk_id bigint PRIMARY KEY REFERENCES aps_search.chunk (chunk_id) ON DELETE CASCADE, file_id bigint NOT NULL, vec %I.halfvec(%s) NOT NULL)', t, vs, p_dim);
	EXECUTE format('CREATE INDEX %I ON aps_search.%I (file_id)', t || '_file_idx', t);
	EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON aps_search.%I TO %I', t, session_user);
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.build_vec_index(p_gen int)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
DECLARE
	vs text := aps_search.vec_schema();
BEGIN
	IF NOT EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen) THEN
		RAISE EXCEPTION 'unknown generation %', p_gen;
	END IF;
	EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON aps_search.%I USING hnsw (vec %I.halfvec_cosine_ops) WITH (m = 16, ef_construction = 64)',
		'chunk_vec_' || p_gen || '_hnsw', 'chunk_vec_' || p_gen, vs);
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.mark_generation_ready(p_gen int)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	UPDATE embedding_profile SET state = 'ready' WHERE gen = p_gen AND state IN ('building', 'ready');
	IF NOT FOUND THEN
		RAISE EXCEPTION 'generation % is not building', p_gen;
	END IF;
END
$$;
-- @@
-- The only way a generation becomes the one searches use, and only an administrator's command calls it.
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
END
$$;
-- @@
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
	EXECUTE format('DROP TABLE IF EXISTS aps_search.%I', 'chunk_vec_' || p_gen);
	DELETE FROM embedding_profile WHERE gen = p_gen;
END
$$;
-- @@
-- Queues vec jobs for indexed files that still lack vectors in the generation and have no job for it. Idempotent.
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
			WHERE fs.text_state = 'indexed' AND fs.vec_state <> 'failed'
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
CREATE OR REPLACE FUNCTION aps_search.vec_coverage(p_gen int)
RETURNS TABLE (files bigint, embedded bigint)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM embedding_profile WHERE gen = p_gen) THEN
		RAISE EXCEPTION 'unknown generation %', p_gen;
	END IF;
	RETURN QUERY EXECUTE format($f$
		SELECT count(*)::bigint,
			count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM aps_search.chunk ch WHERE ch.file_id = fs.file_id
				AND NOT EXISTS (SELECT 1 FROM aps_search.%I v WHERE v.chunk_id = ch.chunk_id)))::bigint
		FROM aps_search.file_state fs
		WHERE fs.text_state = 'indexed' AND EXISTS (SELECT 1 FROM aps_search.chunk ch WHERE ch.file_id = fs.file_id)$f$,
		'chunk_vec_' || p_gen);
END
$$;
-- @@
-- Worker side. Each checks the lease exactly as write_result does; the worker never reads a table.
CREATE OR REPLACE FUNCTION aps_search.vec_profile(p_job_id bigint, p_lease_epoch bigint)
RETURNS TABLE (gen int, model text, digest text, dim int, query_prefix text, passage_prefix text, max_tokens int)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
#variable_conflict use_column
DECLARE
	j job%ROWTYPE;
BEGIN
	SELECT * INTO j FROM job WHERE id = p_job_id;
	IF NOT FOUND OR j.lane <> 'vec' OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RAISE EXCEPTION 'stale_lease' USING ERRCODE = 'BD001';
	END IF;
	RETURN QUERY SELECT e.gen, e.model, e.digest, e.dim, e.query_prefix, e.passage_prefix, e.max_tokens
		FROM embedding_profile e WHERE e.gen = j.gen AND e.state <> 'retired';
	IF NOT FOUND THEN
		RAISE EXCEPTION 'retired' USING ERRCODE = 'BD001';
	END IF;
END
$$;
-- @@
CREATE OR REPLACE FUNCTION aps_search.vec_chunks(p_job_id bigint, p_lease_epoch bigint, p_etag text)
RETURNS TABLE (chunk_id bigint, heading text, text text)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = aps_search, pg_catalog, pg_temp
AS $$
#variable_conflict use_column
DECLARE
	j job%ROWTYPE;
	fs file_state%ROWTYPE;
BEGIN
	SELECT * INTO j FROM job WHERE id = p_job_id;
	IF NOT FOUND OR j.lane <> 'vec' OR j.lease_epoch <> p_lease_epoch OR j.lease_until IS NULL OR j.lease_until <= clock_timestamp() THEN
		RAISE EXCEPTION 'stale_lease' USING ERRCODE = 'BD001';
	END IF;
	SELECT * INTO fs FROM file_state WHERE file_id = j.file_id;
	IF NOT FOUND OR fs.text_state = 'skipped' THEN
		RAISE EXCEPTION 'file_skipped' USING ERRCODE = 'BD001';
	END IF;
	IF p_etag IS DISTINCT FROM j.claimed_etag OR fs.etag IS DISTINCT FROM j.claimed_etag THEN
		RAISE EXCEPTION 'etag_mismatch' USING ERRCODE = 'BD001';
	END IF;
	IF NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.gen = j.gen AND e.state <> 'retired') THEN
		RAISE EXCEPTION 'retired' USING ERRCODE = 'BD001';
	END IF;
	-- Only the chunks that still lack a vector, so a retried job resumes where it stopped.
	RETURN QUERY EXECUTE format('SELECT c.chunk_id, c.heading, c.text FROM aps_search.chunk c
		WHERE c.file_id = $1 AND NOT EXISTS (SELECT 1 FROM aps_search.%I v WHERE v.chunk_id = c.chunk_id)
		ORDER BY c.page_no, c.ord, c.chunk_id', 'chunk_vec_' || j.gen) USING j.file_id;
END
$$;
-- @@
-- Compare-and-set write of some or all of the file's vectors. Returns true when the file is complete (job done).
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
		-- vec_state describes the generation searches use (or the first one, while none is active yet).
		UPDATE file_state SET vec_state = 'embedded', updated_at = now() WHERE file_id = j.file_id
			AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
				OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
	END IF;
	RETURN complete;
END
$$;
-- @@
-- claim_job: per-lane caps (text 4 per worker / 16 in all as before; vec 2 / 4) so embedding cannot starve OCR, a give-up
-- that touches the state of the lane that failed, and obsolete unclaimed vec jobs dropped. Text branch unchanged from 004.
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
	-- Concurrency caps derive from live claims in the table, never from a cache counter (D3). The worker id is self-declared,
	-- so a per-lane global cap backs up the per-worker one (M1).
	SELECT count(*) INTO v_active FROM job j
		WHERE j.lane = p_lane AND j.claimed_by = p_worker AND j.lease_until > clock_timestamp();
	SELECT count(*) INTO v_total FROM job j WHERE j.lane = p_lane AND j.lease_until > clock_timestamp();
	v_n := least(coalesce(p_n, 1),
		(CASE p_lane WHEN 'vec' THEN 2 ELSE 4 END) - v_active,
		(CASE p_lane WHEN 'vec' THEN 4 ELSE 16 END) - v_total);
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
		UPDATE file_state SET vec_state = 'failed', updated_at = now() WHERE file_id = j.file_id
			AND (j.gen = (SELECT e.gen FROM embedding_profile e WHERE e.state = 'active')
				OR NOT EXISTS (SELECT 1 FROM embedding_profile e WHERE e.state = 'active'));
		DELETE FROM job WHERE id = p_job_id;
	ELSE
		UPDATE file_state SET text_state = 'skipped', skip_reason = p_reason, updated_at = now()
			WHERE file_id = j.file_id;
		DELETE FROM job WHERE id = p_job_id;
	END IF;
END
$$;
-- @@
-- write_result: as in 002, plus the vector lane. The reindex replaces the chunk rows (their vectors cascade), so every live
-- generation (the one searches use and any being built) gets a fresh job for the new etag.
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
		ocr_ver = p_ocr_ver, chunker_ver = p_chunker_ver, skip_reason = NULL, updated_at = now(),
		vec_state = CASE WHEN EXISTS (SELECT 1 FROM embedding_profile WHERE state IN ('active', 'building', 'ready'))
			THEN 'pending' ELSE vec_state END
		WHERE file_id = j.file_id;
	INSERT INTO job (lane, file_id, idem_key, gen)
		SELECT 'vec', j.file_id, j.file_id::text || ':' || coalesce(fs.etag, '') || ':' || e.gen::text, e.gen
		FROM embedding_profile e WHERE e.state IN ('active', 'building', 'ready')
		ON CONFLICT (idem_key) DO NOTHING;
	DELETE FROM job WHERE id = p_job_id;
END
$$;
-- @@
REVOKE ALL ON FUNCTION aps_search.vec_schema() FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.create_generation(int, text, text, text, int, text, text, text, int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.build_vec_index(int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.mark_generation_ready(int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.activate_generation(int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.drop_generation(int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.enqueue_vec_backfill(int, int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.vec_coverage(int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.vec_profile(bigint, bigint) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.vec_chunks(bigint, bigint, text) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.write_vectors(bigint, bigint, text, bigint[], text[]) FROM PUBLIC;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.vec_profile(bigint, bigint) TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.vec_chunks(bigint, bigint, text) TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.write_vectors(bigint, bigint, text, bigint[], text[]) TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.create_generation(int, text, text, text, int, text, text, text, int) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.build_vec_index(int) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.mark_generation_ready(int) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.activate_generation(int) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.drop_generation(int) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.enqueue_vec_backfill(int, int) TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.vec_coverage(int) TO SESSION_USER;
-- @@
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
UPDATE schema_meta SET schema_ver = 3 WHERE schema_ver < 3;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
