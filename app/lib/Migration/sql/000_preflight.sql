-- Fails before any object exists when the admin step was not done (S1 design section 7).
DO $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bd_owner') THEN
		RAISE EXCEPTION 'buscador_documental: role bd_owner is missing. An administrator must create it (NOLOGIN) and GRANT it to the Nextcloud database user; see the install documentation.';
	END IF;
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bd_worker') THEN
		RAISE EXCEPTION 'buscador_documental: role bd_worker is missing. An administrator must create it (LOGIN, no table privileges); see the install documentation.';
	END IF;
	IF NOT pg_has_role(current_user, 'bd_owner', 'SET') THEN
		RAISE EXCEPTION 'buscador_documental: the Nextcloud database user cannot SET ROLE bd_owner. An administrator must run GRANT bd_owner TO <that user> WITH SET TRUE.';
	END IF;
	IF NOT has_database_privilege(current_user, current_database(), 'CREATE') THEN
		RAISE EXCEPTION 'buscador_documental: the Nextcloud database user lacks CREATE on database %. An administrator must grant it.', current_database();
	END IF;
	IF NOT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'unaccent') THEN
		RAISE EXCEPTION 'buscador_documental: extension unaccent is not installed. An administrator must run CREATE EXTENSION unaccent in database %.', current_database();
	END IF;
END
$$;
