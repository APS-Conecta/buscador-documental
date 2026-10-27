-- schema_ver 8: whether a search snapshot holds an incomplete list, so cursor pages report what page 1 found (issue 50).
-- Idempotent: it runs after 010 on a fresh install and alone on a schema_ver 7 instance (SqlScript::upgradeToV8). bd_owner owns
-- every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
ALTER TABLE search_snapshot ADD COLUMN IF NOT EXISTS capped boolean NOT NULL DEFAULT false;
-- @@
UPDATE schema_meta SET schema_ver = 8 WHERE schema_ver < 8;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
