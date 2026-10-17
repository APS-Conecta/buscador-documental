CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE SCHEMA IF NOT EXISTS bd_spike;
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'spanish_unaccent') THEN
    CREATE TEXT SEARCH CONFIGURATION public.spanish_unaccent (COPY = pg_catalog.spanish);
    ALTER TEXT SEARCH CONFIGURATION public.spanish_unaccent ALTER MAPPING FOR hword, hword_part, word WITH unaccent, spanish_stem;
  END IF;
END $$;
DROP TABLE IF EXISTS bd_spike.chunk, bd_spike.file_acl;
CREATE TABLE bd_spike.chunk (
  chunk_id bigint NOT NULL,
  file_id  bigint NOT NULL,
  page     int    NOT NULL,
  text     text   NOT NULL,
  tsv      tsvector GENERATED ALWAYS AS (to_tsvector('public.spanish_unaccent', text)) STORED
);
CREATE TABLE bd_spike.file_acl (
  file_id    bigint NOT NULL,
  principals text[] NOT NULL,
  acl_ver    int    NOT NULL DEFAULT 1
);
