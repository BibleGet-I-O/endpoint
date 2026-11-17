-- 048: applications, API keys, client keys, plugin installations and usage
-- counters for the v4 credential system (#97).
--
--   applications   a developer project (or the system "reader" application)
--   api_keys       keys of an application; scope 'reader' only on is_system
--   client_keys    public per-plugin-release keys that can only register installations
--   installations  one row per plugin install, with its own token
--   usage_daily    per-day request counters per credential and route
--
-- Also: versions_available.reader_full_text (editions the reader scope may
-- serve whole) and credential_type / credential_id on every requests_log__YYYY
-- and in create_yearly_log_table(). Idempotent.

BEGIN;

CREATE TABLE IF NOT EXISTS applications (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id  TEXT NOT NULL,
    name             TEXT NOT NULL,
    description      TEXT,
    website          TEXT,
    status           TEXT NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'approved', 'rejected', 'revoked')),
    review_notes     TEXT,
    reviewed_by      TEXT,
    reviewed_at      TIMESTAMPTZ,
    is_system        BOOLEAN NOT NULL DEFAULT FALSE,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS applications_zitadel_user_id_idx ON applications (zitadel_user_id);
CREATE UNIQUE INDEX IF NOT EXISTS applications_system_name_uniq ON applications (name) WHERE is_system;

CREATE TABLE IF NOT EXISTS api_keys (
    id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id       UUID NOT NULL REFERENCES applications (id) ON DELETE CASCADE,
    key_hash             CHAR(64) NOT NULL UNIQUE,
    key_prefix           VARCHAR(20) NOT NULL,
    name                 TEXT NOT NULL,
    scope                TEXT NOT NULL DEFAULT 'read' CHECK (scope IN ('read', 'reader')),
    rate_limit_per_hour  INTEGER DEFAULT 1000,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    last_used_at         TIMESTAMPTZ,
    expires_at           TIMESTAMPTZ,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS api_keys_application_id_idx ON api_keys (application_id);

CREATE OR REPLACE FUNCTION api_keys_reader_scope_check() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.scope = 'reader' AND NOT EXISTS (
        SELECT 1 FROM applications WHERE id = NEW.application_id AND is_system
    ) THEN
        RAISE EXCEPTION 'scope reader is allowed only on system applications'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS api_keys_reader_scope ON api_keys;
CREATE TRIGGER api_keys_reader_scope
    BEFORE INSERT OR UPDATE OF scope, application_id ON api_keys
    FOR EACH ROW EXECUTE FUNCTION api_keys_reader_scope_check();

CREATE TABLE IF NOT EXISTS client_keys (
    id                          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plugin                      TEXT NOT NULL
                                CHECK (plugin IN ('wordpress', 'google-docs', 'libreoffice', 'openoffice', 'msword', 'webword')),
    plugin_version              TEXT,
    key_hash                    CHAR(64) NOT NULL UNIQUE,
    key_prefix                  VARCHAR(20) NOT NULL,
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at                  TIMESTAMPTZ,
    max_registrations_per_hour  INTEGER NOT NULL DEFAULT 200
);

CREATE TABLE IF NOT EXISTS installations (
    id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    client_key_id        UUID NOT NULL REFERENCES client_keys (id),
    install_id           UUID NOT NULL,
    token_hash           CHAR(64) NOT NULL UNIQUE,
    token_prefix         VARCHAR(20) NOT NULL,
    plugin_version       TEXT,
    site_url             TEXT,
    contact_email        TEXT,
    rate_limit_per_hour  INTEGER DEFAULT 300,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    first_seen_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    request_count        BIGINT NOT NULL DEFAULT 0,
    UNIQUE (client_key_id, install_id)
);

-- credential_id is the nil UUID, with credential_type 'none', for requests
-- served without a credential (staging, REQUIRE_CREDENTIALS=false).
CREATE TABLE IF NOT EXISTS usage_daily (
    day              DATE NOT NULL,
    credential_type  VARCHAR(16) NOT NULL CHECK (credential_type IN ('project', 'installation', 'none')),
    credential_id    UUID NOT NULL,
    route            TEXT NOT NULL,
    requests         BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (day, credential_type, credential_id, route)
);

ALTER TABLE versions_available
    ADD COLUMN IF NOT EXISTS reader_full_text BOOLEAN NOT NULL DEFAULT FALSE;

DO $$
DECLARE
    t TEXT;
BEGIN
    FOR t IN
        SELECT c.relname FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relkind = 'r'
          AND c.relname ~ '^requests_log__[0-9]{4}$'
    LOOP
        EXECUTE format(
            'ALTER TABLE %I ADD COLUMN IF NOT EXISTS credential_type VARCHAR(16),
                            ADD COLUMN IF NOT EXISTS credential_id UUID', t);
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
                "PLUGINVERSION" VARCHAR(20) NOT NULL DEFAULT %L,
                credential_type VARCHAR(16),
                credential_id UUID
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
