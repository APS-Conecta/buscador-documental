-- The vector index is built by `occ buscador:profile refresh` alone (CREATE INDEX CONCURRENTLY cannot run inside a function), so
-- the function the background job called is dropped. schema_ver stays 5: no table changes. Idempotent: it runs after 007 on a
-- fresh install and alone through Version000006.
SET ROLE bd_owner;
-- @@
DROP FUNCTION IF EXISTS aps_search.build_vec_index(int);
-- @@
RESET ROLE;
