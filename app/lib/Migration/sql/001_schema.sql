-- aps_search: the index state (ADR-0002). Owner bd_owner; the Nextcloud DB user gets DML, the worker role gets nothing.
CREATE SCHEMA aps_search AUTHORIZATION bd_owner;
-- @@
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
CREATE TEXT SEARCH CONFIGURATION aps_search.spanish_unaccent (COPY = pg_catalog.spanish);
-- @@
ALTER TEXT SEARCH CONFIGURATION aps_search.spanish_unaccent
	ALTER MAPPING FOR hword, hword_part, word WITH public.unaccent, pg_catalog.spanish_stem;
-- @@
CREATE TABLE schema_meta (
	schema_ver int NOT NULL,
	ts_config_ver int NOT NULL
);
-- @@
INSERT INTO schema_meta VALUES (1, 1);
-- @@
CREATE TABLE file_state (
	file_id bigint PRIMARY KEY,
	etag text,
	content_hash text,
	text_state text NOT NULL DEFAULT 'pending'
		CHECK (text_state IN ('pending', 'extracting', 'ocr', 'chunking', 'indexed', 'skipped')),
	vec_state text NOT NULL DEFAULT 'pending',
	acl_dirty boolean NOT NULL DEFAULT false,   -- written, not read, in S1 (STUB S3)
	skip_reason text,
	ocr_ver text,
	chunker_ver text,
	updated_at timestamptz NOT NULL DEFAULT now()
);
-- @@
CREATE TABLE job (
	id bigserial PRIMARY KEY,
	lane text NOT NULL,
	file_id bigint NOT NULL,
	idem_key text NOT NULL UNIQUE,
	lease_until timestamptz,
	lease_epoch bigint NOT NULL DEFAULT 0,
	claimed_by text,
	claimed_etag text,
	claimed_hash text,
	attempts int NOT NULL DEFAULT 0,
	not_before timestamptz NOT NULL DEFAULT now()
);
-- @@
CREATE INDEX job_file_id_idx ON job (file_id);
-- @@
CREATE TABLE page (
	file_id bigint NOT NULL,
	page_no int NOT NULL,
	text text NOT NULL,
	ocr_conf real,
	ocr_ver text,
	source text,
	PRIMARY KEY (file_id, page_no)
);
-- @@
CREATE TABLE chunk (
	chunk_id bigserial PRIMARY KEY,
	file_id bigint NOT NULL,
	page_no int,
	ord int NOT NULL,
	heading text,
	text text NOT NULL,
	tsv tsvector GENERATED ALWAYS AS (to_tsvector('aps_search.spanish_unaccent'::regconfig, text)) STORED,
	chunker_ver text
);
-- @@
CREATE INDEX chunk_tsv_idx ON chunk USING gin (tsv);
-- @@
CREATE INDEX chunk_file_id_idx ON chunk (file_id);
-- @@
CREATE TABLE file_meta (
	file_id bigint PRIMARY KEY,
	name text NOT NULL,
	mime text,
	mtime bigint
);
-- @@
CREATE TABLE file_acl (
	file_id bigint PRIMARY KEY,
	principals text[] NOT NULL,
	acl_ver int NOT NULL DEFAULT 1
);
-- @@
CREATE INDEX file_acl_principals_idx ON file_acl USING gin (principals);
-- @@
RESET search_path;
-- @@
RESET ROLE;
