-- schema_ver 6: whether a search snapshot holds an incomplete list, so cursor pages report what page 1 found (issue 50).
-- Idempotent: it runs after 008 on a fresh install and alone on a schema_ver 5 instance (SqlScript::upgradeToV6). bd_owner owns
-- every object.
SET ROLE bd_owner;
-- @@
SET search_path = aps_search, pg_catalog;
-- @@
ALTER TABLE search_snapshot ADD COLUMN IF NOT EXISTS capped boolean NOT NULL DEFAULT false;
-- @@
UPDATE schema_meta SET schema_ver = 6 WHERE schema_ver < 6;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
