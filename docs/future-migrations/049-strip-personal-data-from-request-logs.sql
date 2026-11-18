-- 049: strip personal data from the imported v3 request history (#97).
--
-- DESTRUCTIVE and irreversible. Decided 2026-11-17: v4 holds no IP addresses,
-- geolocation or client headers. Sets WHO_IP, WHO_WHERE_JSON, HEADERS_JSON,
-- HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR, HTTP_X_REAL_IP and REMOTE_ADDR to NULL
-- in requests_log and every requests_log__YYYY, keeping time, query, origin,
-- app and domain; drops the *_ip_when indexes; and updates
-- create_yearly_log_table() so new yearly tables no longer get that index
-- (the columns stay, for compatibility).
--
-- Most of these columns are NOT NULL DEFAULT '' on the imported tables
-- (WHO_IP is nullable), so NOT NULL is dropped first, where present.
--
-- Not in migrations/ on purpose: apply it by hand on staging, and on the
-- production copy of the database before go-live (move it into migrations/
-- when applied). Afterwards run VACUUM FULL on requests_log and every
-- requests_log__YYYY to give the space back (it cannot run inside the
-- transaction below). v3's MariaDB log is not touched. Idempotent.

BEGIN;

DO $$
DECLARE
    t   TEXT;
    col TEXT;
BEGIN
    FOR t IN
        SELECT c.relname FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relkind = 'r'
          AND c.relname ~ '^requests_log(__[0-9]{4})?$'
    LOOP
        FOREACH col IN ARRAY ARRAY[
            'WHO_IP', 'WHO_WHERE_JSON', 'HEADERS_JSON', 'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'
        ]
        LOOP
            IF EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = t AND column_name = col
            ) THEN
                EXECUTE format('ALTER TABLE %I ALTER COLUMN %I DROP NOT NULL', t, col);
                EXECUTE format('UPDATE %I SET %I = NULL WHERE %I IS NOT NULL', t, col, col);
            END IF;
        END LOOP;
        EXECUTE format('DROP INDEX IF EXISTS %I.%I', current_schema(), t || '_ip_when');
    END LOOP;
END $$;

CREATE OR REPLACE FUNCTION create_yearly_log_table(table_year TEXT, has_origin BOOLEAN DEFAULT TRUE)
RETURNS VOID AS $$
BEGIN
    IF has_origin THEN
        EXECUTE format(
            'CREATE TABLE IF NOT EXISTS %I (
                "COUNTER" SERIAL PRIMARY KEY,
                "WHO_WHEN" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "WHO_IP" inet,
                "WHO_WHERE_JSON" VARCHAR(500) NOT NULL DEFAULT %L,
                "HEADERS_JSON" VARCHAR(1500) NOT NULL DEFAULT %L,
                "ORIGIN" VARCHAR(255) NOT NULL DEFAULT %L,
                "QUERY" TEXT NOT NULL DEFAULT %L,
                "ORIGINALQUERY" VARCHAR(100) NOT NULL DEFAULT %L,
                "REQUEST_METHOD" VARCHAR(50) NOT NULL DEFAULT %L,
                "HTTP_CLIENT_IP" VARCHAR(46) NOT NULL DEFAULT %L,
                "HTTP_X_FORWARDED_FOR" VARCHAR(46) NOT NULL DEFAULT %L,
                "HTTP_X_REAL_IP" VARCHAR(46) NOT NULL DEFAULT %L,
                "REMOTE_ADDR" VARCHAR(46) NOT NULL DEFAULT %L,
                "APP_ID" VARCHAR(50) NOT NULL DEFAULT %L,
                "DOMAIN" VARCHAR(200) NOT NULL DEFAULT %L,
                "PLUGINVERSION" VARCHAR(20) NOT NULL DEFAULT %L,
                credential_type VARCHAR(16),
                credential_id UUID
            )', 'requests_log__' || table_year,
            '', '', '', '', '', '', '', '', '', '', '', '', ''
        );
    ELSE
        EXECUTE format(
            'CREATE TABLE IF NOT EXISTS %I (
                "COUNTER" SERIAL PRIMARY KEY,
                "WHO_WHEN" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "WHO_IP" inet,
                "WHO_WHERE_JSON" VARCHAR(500) NOT NULL DEFAULT %L,
                "HEADERS_JSON" VARCHAR(1500) NOT NULL DEFAULT %L,
                "QUERY" TEXT NOT NULL DEFAULT %L,
                "REQUEST_METHOD" VARCHAR(50) NOT NULL DEFAULT %L,
                "HTTP_CLIENT_IP" VARCHAR(46) NOT NULL DEFAULT %L,
                "HTTP_X_FORWARDED_FOR" VARCHAR(46) NOT NULL DEFAULT %L,
                "HTTP_X_REAL_IP" VARCHAR(46) NOT NULL DEFAULT %L,
                "REMOTE_ADDR" VARCHAR(46) NOT NULL DEFAULT %L,
                "APP_ID" VARCHAR(50) NOT NULL DEFAULT %L,
                "DOMAIN" VARCHAR(200) NOT NULL DEFAULT %L,
                "PLUGINVERSION" VARCHAR(20) NOT NULL DEFAULT %L,
                credential_type VARCHAR(16),
                credential_id UUID
            )', 'requests_log__' || table_year,
            '', '', '', '', '', '', '', '', '', '', ''
        );
    END IF;
END;
$$ LANGUAGE plpgsql;

COMMIT;
