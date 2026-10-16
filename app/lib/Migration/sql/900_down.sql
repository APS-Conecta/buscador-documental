-- Teardown. Refused by Teardown::run when file_state has rows unless forced (D2). bd_owner owns the schema.
SET ROLE bd_owner;
-- @@
DROP SCHEMA IF EXISTS aps_search CASCADE;
-- @@
RESET ROLE;
