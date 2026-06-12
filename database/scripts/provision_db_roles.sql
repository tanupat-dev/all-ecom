-- Provision the two-role split required by ADR 0016 (run once per environment
-- by a cluster admin, e.g. `sudo -u postgres psql -p 5434 -f provision_db_roles.sql`).
--
--   owner/migration role : all_ecom      (already exists in dev — owns the schema, runs migrate)
--   runtime app role     : all_ecom_app  (non-owner, DML only, never BYPASSRLS)
--
-- After running, point the app's runtime connection at the restricted role:
--   .env  DB_USERNAME=all_ecom_app  DB_PASSWORD=<chosen password>
--         DB_OWNER_USERNAME=all_ecom  DB_OWNER_PASSWORD=<owner password>
-- and run migrations as the owner: php artisan migrate --database=pgsql_owner

\set ON_ERROR_STOP on

-- ⚠️ The literal password below is for LOCAL DEV ONLY — in any shared or
-- production environment replace it before running.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'all_ecom_app') THEN
        CREATE ROLE all_ecom_app LOGIN PASSWORD 'all_ecom_app'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
    END IF;
END
$$;

\connect all_ecom

GRANT USAGE ON SCHEMA public TO all_ecom_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO all_ecom_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO all_ecom_app;

-- Tables the owner creates later (every migration) are granted automatically.
ALTER DEFAULT PRIVILEGES FOR ROLE all_ecom IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO all_ecom_app;
ALTER DEFAULT PRIVILEGES FOR ROLE all_ecom IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO all_ecom_app;
