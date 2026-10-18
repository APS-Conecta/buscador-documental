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
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
UPDATE schema_meta SET schema_ver = 5 WHERE schema_ver < 5;
-- @@
RESET search_path;      -- Nextcloud keeps using this connection: leave the session as it was found
-- @@
RESET ROLE;
