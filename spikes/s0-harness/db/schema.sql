-- Throwaway spike schema (bd_spike). Column names follow buscador/01-architecture.md §2 so results transfer.
-- Indexes are created AFTER the bulk load by bench/load.py (timed for m5).
CREATE SCHEMA IF NOT EXISTS bd_spike;
DROP TABLE IF EXISTS bd_spike.chunk, bd_spike.chunk_vec, bd_spike.file_acl;

CREATE TABLE bd_spike.chunk (
  chunk_id bigint NOT NULL,
  file_id  bigint NOT NULL,
  page     int    NOT NULL,
  text     text   NOT NULL,
  tsv      tsvector GENERATED ALWAYS AS (to_tsvector('public.spanish_unaccent', text)) STORED
);
CREATE TABLE bd_spike.chunk_vec (
  chunk_id bigint NOT NULL,
  file_id  bigint NOT NULL,
  vec      halfvec(384) NOT NULL
);
CREATE TABLE bd_spike.file_acl (
  file_id    bigint NOT NULL,
  principals text[] NOT NULL,
  acl_ver    int    NOT NULL DEFAULT 1
);
