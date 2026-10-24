-- Admin provisioning for the bd-s1 stack (runs once, as the image superuser). On a real install this is the
-- documented admin step (S1 design C2/D2): a stock Nextcloud DB user can create the schema but not roles.
-- Passwords are toys; the stack binds to 127.0.0.1 only.
CREATE ROLE nextcloud LOGIN PASSWORD 'dev' CREATEDB;
ALTER DATABASE nextcloud OWNER TO nextcloud;
CREATE ROLE bd_owner NOLOGIN;                       -- owns aps_search and its functions; nothing outside it
CREATE ROLE bd_worker LOGIN PASSWORD 'dev-worker' CONNECTION LIMIT 20;  -- no table privileges; EXECUTE on the worker functions only
-- The timeouts and temp file limit bound accidents, not the worker itself (instalador #43, from the review of the
-- suite's roles script): only CONNECTION LIMIT and temp_file_limit bind a compromised worker, because a role
-- cannot lift them. statement_timeout and idle_in_transaction_session_timeout are per-role defaults — the worker
-- session can raise them with SET. The lease caps and the password rotation are the real bounds on holding the
-- database (S7 STRIDE review H3).
ALTER ROLE bd_worker SET statement_timeout = '10min';
ALTER ROLE bd_worker SET temp_file_limit = '2GB';
ALTER ROLE bd_worker SET idle_in_transaction_session_timeout = '5min';
-- Nextcloud's installer revokes PUBLIC privileges on its database, so the worker needs an explicit CONNECT.
GRANT CONNECT ON DATABASE nextcloud TO bd_worker;
GRANT bd_owner TO nextcloud WITH INHERIT FALSE, SET TRUE;  -- lets the migration create the schema AUTHORIZATION bd_owner
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS vector;              -- S4; created here because it is not a trusted extension
