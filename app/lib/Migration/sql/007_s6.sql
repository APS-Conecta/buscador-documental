-- S6 (schema_ver 5): the owner of a file, for the Propiedad filter (mine / shared with me). Idempotent: it runs after 006 on a fresh
-- install and alone on a schema_ver 4 instance (SqlScript::upgradeToV5). bd_owner owns every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
ALTER TABLE file_meta ADD COLUMN IF NOT EXISTS owner text;
-- @@
CREATE INDEX IF NOT EXISTS file_meta_owner_idx ON file_meta (owner);
-- @@
-- Files indexed before this step have no owner recorded: queue each once so the outbox processor fills it in (team folders stay NULL).
INSERT INTO outbox (file_id, kind) SELECT file_id, 'file' FROM file_meta WHERE owner IS NULL ON CONFLICT DO NOTHING;
-- @@
-- write_result with bounded version strings, hash and page text (S7 STRIDE review); grants and owner are kept by REPLACE.
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
	INSERT INTO job (lane, file_id, idem_key, gen)
		SELECT 'vec', j.file_id, j.file_id::text || ':' || coalesce(fs.etag, '') || ':' || e.gen::text, e.gen
		FROM embedding_profile e WHERE e.state IN ('active', 'building', 'ready')
		ON CONFLICT (idem_key) DO NOTHING;
	DELETE FROM job WHERE id = p_job_id;
END
$$;
-- @@
-- More detail in the planner's statistics on the search column: the most common terms are read from them (CandidateQuery).
ALTER TABLE chunk ALTER COLUMN tsv SET STATISTICS 500;
-- @@
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
UPDATE schema_meta SET schema_ver = 5 WHERE schema_ver < 5;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
