-- Test database schema for BibleGet endpoint integration tests (PostgreSQL)

-- Enable pgvector for embedding-based semantic search
CREATE EXTENSION IF NOT EXISTS vector;

-- Counter table for tracking good/bad queries
CREATE TABLE IF NOT EXISTS counter (
    id   SMALLINT NOT NULL PRIMARY KEY,
    good INT NOT NULL DEFAULT 0,
    bad  INT NOT NULL DEFAULT 0
);
INSERT INTO counter (id, good, bad) VALUES (1, 0, 0)
ON CONFLICT (id) DO NOTHING;

-- Available Bible versions
CREATE TABLE IF NOT EXISTS versions_available (
    sigla            VARCHAR(20) NOT NULL PRIMARY KEY,
    fullname         VARCHAR(255) NOT NULL DEFAULT '',
    year             VARCHAR(10) NOT NULL DEFAULT '',
    language         VARCHAR(50) NOT NULL DEFAULT '',
    imprimatur       VARCHAR(255) NOT NULL DEFAULT '',
    canon            VARCHAR(20) NOT NULL DEFAULT '',
    copyright_holder VARCHAR(255) NOT NULL DEFAULT '',
    notes            TEXT,
    copyright        SMALLINT NOT NULL DEFAULT 0,
    type             VARCHAR(20) NOT NULL DEFAULT 'BIBLE',
    ts_language      VARCHAR(30) NOT NULL DEFAULT 'simple'
);

-- Bible book full names (73 rows = 73 books, columns = languages)
-- Simplified: just two language columns for testing
CREATE TABLE IF NOT EXISTS biblebooks_fullname (
    "BOOK"    INT NOT NULL PRIMARY KEY,
    "ENGLISH" VARCHAR(255) NOT NULL DEFAULT '',
    "ITALIAN" VARCHAR(255) NOT NULL DEFAULT ''
);

-- Bible book abbreviations (same structure)
CREATE TABLE IF NOT EXISTS biblebooks_abbr (
    "BOOK"    INT NOT NULL PRIMARY KEY,
    "ENGLISH" VARCHAR(255) NOT NULL DEFAULT '',
    "ITALIAN" VARCHAR(255) NOT NULL DEFAULT ''
);

-- Request logging (yearly table, dynamically named for current year)
DO $$
DECLARE
    cur_year TEXT := to_char(CURRENT_DATE, 'YYYY');
    log_table TEXT;
BEGIN
    log_table := 'requests_log__' || cur_year;
    EXECUTE format(
        'CREATE TABLE IF NOT EXISTS %I (
            id SERIAL PRIMARY KEY,
            "WHO_IP" inet,
            "WHO_WHERE_JSON" TEXT,
            "HEADERS_JSON" TEXT,
            "ORIGIN" VARCHAR(255) NOT NULL DEFAULT %L,
            "QUERY" TEXT,
            "ORIGINALQUERY" TEXT,
            "REQUEST_METHOD" VARCHAR(10) NOT NULL DEFAULT %L,
            "HTTP_CLIENT_IP" VARCHAR(45) NOT NULL DEFAULT %L,
            "HTTP_X_FORWARDED_FOR" VARCHAR(255) NOT NULL DEFAULT %L,
            "HTTP_X_REAL_IP" VARCHAR(45) NOT NULL DEFAULT %L,
            "REMOTE_ADDR" VARCHAR(45) NOT NULL DEFAULT %L,
            "APP_ID" VARCHAR(100) NOT NULL DEFAULT %L,
            "DOMAIN" VARCHAR(255) NOT NULL DEFAULT %L,
            "PLUGINVERSION" VARCHAR(50) NOT NULL DEFAULT %L,
            "WHO_WHEN" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )', log_table, '', '', '', '', '', '', '', '', ''
    );
END $$;

-- Tracks which embedding model was used for each version's stored embeddings
CREATE TABLE IF NOT EXISTS embedding_metadata (
    version_sigla VARCHAR(20) NOT NULL PRIMARY KEY REFERENCES versions_available(sigla) ON DELETE CASCADE,
    model_name    VARCHAR(100) NOT NULL,
    model_version VARCHAR(50) NOT NULL DEFAULT '',
    dimensions    INT NOT NULL CHECK (dimensions > 0),
    computed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    content_xor   BYTEA
);

-- Trigger function to auto-maintain text_hash on verse tables
CREATE OR REPLACE FUNCTION update_text_hash()
RETURNS TRIGGER AS $$
BEGIN
    NEW.text_hash := decode(md5(NEW.text), 'hex');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- XOR aggregate for BYTEA (used for version-level content fingerprinting)
CREATE OR REPLACE FUNCTION bytea_xor(a BYTEA, b BYTEA) RETURNS BYTEA AS $$
BEGIN
    IF a IS NULL THEN RETURN b; END IF;
    IF b IS NULL THEN RETURN a; END IF;
    RETURN set_byte(set_byte(set_byte(set_byte(
           set_byte(set_byte(set_byte(set_byte(
           set_byte(set_byte(set_byte(set_byte(
           set_byte(set_byte(set_byte(set_byte(
               a,
               0, get_byte(a,0) # get_byte(b,0)),
               1, get_byte(a,1) # get_byte(b,1)),
               2, get_byte(a,2) # get_byte(b,2)),
               3, get_byte(a,3) # get_byte(b,3)),
               4, get_byte(a,4) # get_byte(b,4)),
               5, get_byte(a,5) # get_byte(b,5)),
               6, get_byte(a,6) # get_byte(b,6)),
               7, get_byte(a,7) # get_byte(b,7)),
               8, get_byte(a,8) # get_byte(b,8)),
               9, get_byte(a,9) # get_byte(b,9)),
              10, get_byte(a,10) # get_byte(b,10)),
              11, get_byte(a,11) # get_byte(b,11)),
              12, get_byte(a,12) # get_byte(b,12)),
              13, get_byte(a,13) # get_byte(b,13)),
              14, get_byte(a,14) # get_byte(b,14)),
              15, get_byte(a,15) # get_byte(b,15));
END;
$$ LANGUAGE plpgsql IMMUTABLE STRICT;

CREATE AGGREGATE bytea_xor_agg(BYTEA) (
    SFUNC = bytea_xor,
    STYPE = BYTEA
);

-- curl error log
CREATE TABLE IF NOT EXISTS curl_error (
    id    SERIAL PRIMARY KEY,
    "ERRNO" INT NOT NULL DEFAULT 0,
    "ERROR" TEXT
);
