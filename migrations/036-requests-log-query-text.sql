-- 036: requests_log*."QUERY" becomes TEXT.
--
-- The request log stores the compiled SQL of each quote ("QUERY"), and the
-- rate limiter counts identical "QUERY" values. Since references select
-- verses by their printed label (PR #188, src/Util/PrintedLabel.php), the SQL
-- of even a single verse is longer than 255 characters, the width of the
-- yearly tables' column (requests_log itself, the legacy table, has 150).
-- The INSERT would fail, and with it every request that is not whitelisted.
--
-- Widens the column on every requests_log table and recreates
-- create_yearly_log_table() so future years get TEXT too (the same change
-- is in production-schema.sql). Idempotent. Apply before deploying #188.

BEGIN;

DO $$
DECLARE
    t TEXT;
BEGIN
    FOR t IN
        SELECT c.table_name FROM information_schema.columns c
        WHERE c.table_schema = current_schema()
          AND c.table_name LIKE 'requests\_log%'
          AND c.column_name = 'QUERY'
          AND c.data_type <> 'text'
    LOOP
        EXECUTE format('ALTER TABLE %I ALTER COLUMN "QUERY" TYPE TEXT', t);
        RAISE NOTICE '%."QUERY" is now TEXT', t;
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
                "PLUGINVERSION" VARCHAR(20) NOT NULL DEFAULT %L
            )', 'requests_log__' || table_year,
            '', '', '', '', '', '', '', '', '', '', '', '', ''
        );
        EXECUTE format(
            'CREATE INDEX IF NOT EXISTS %I ON %I ("WHO_IP", "WHO_WHEN" DESC)',
            'requests_log__' || table_year || '_ip_when',
            'requests_log__' || table_year
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
                "PLUGINVERSION" VARCHAR(20) NOT NULL DEFAULT %L
            )', 'requests_log__' || table_year,
            '', '', '', '', '', '', '', '', '', '', ''
        );
        EXECUTE format(
            'CREATE INDEX IF NOT EXISTS %I ON %I ("WHO_IP", "WHO_WHEN" DESC)',
            'requests_log__' || table_year || '_ip_when',
            'requests_log__' || table_year
        );
    END IF;
END;
$$ LANGUAGE plpgsql;

COMMIT;
