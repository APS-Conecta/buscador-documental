-- Privileges. Run as the Nextcloud DB user; the SET ROLE makes bd_owner the grantor of its own objects.
SET ROLE bd_owner;
-- @@
REVOKE ALL ON SCHEMA aps_search FROM PUBLIC;
-- @@
GRANT USAGE ON SCHEMA aps_search TO bd_worker;
-- @@
REVOKE ALL ON FUNCTION aps_search.claim_job(text, int, text, int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.heartbeat(bigint, bigint, int) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.write_result(bigint, bigint, text, text, jsonb, jsonb, text, text) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.fail_job(bigint, bigint, text, boolean) FROM PUBLIC;
-- @@
REVOKE ALL ON FUNCTION aps_search.erase_file(bigint, text) FROM PUBLIC;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.claim_job(text, int, text, int) TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.heartbeat(bigint, bigint, int) TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.write_result(bigint, bigint, text, text, jsonb, jsonb, text, text) TO bd_worker;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.fail_job(bigint, bigint, text, boolean) TO bd_worker;
-- @@
-- The Nextcloud DB user (bd_app in the design) has DML on every table, and erase_file; nothing else is granted.
GRANT USAGE ON SCHEMA aps_search TO SESSION_USER;
-- @@
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA aps_search TO SESSION_USER;
-- @@
GRANT USAGE ON ALL SEQUENCES IN SCHEMA aps_search TO SESSION_USER;
-- @@
GRANT EXECUTE ON FUNCTION aps_search.erase_file(bigint, text) TO SESSION_USER;
-- @@
RESET ROLE;
