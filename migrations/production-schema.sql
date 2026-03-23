-- =============================================================================
-- BibleGet Production PostgreSQL Schema
-- Migrated from MariaDB/MySQL
-- =============================================================================

-- ── Reference tables ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS counter (
    good INT NOT NULL DEFAULT 0,
    bad  INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS section (
    "IDX"     INT NOT NULL PRIMARY KEY,
    "NAME_EN" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_IT" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_ES" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_FR" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_DE" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_PT" VARCHAR(100) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS testament (
    "IDX"     INT NOT NULL PRIMARY KEY,
    "NAME_EN" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_IT" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_ES" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_FR" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_DE" VARCHAR(100) NOT NULL DEFAULT '',
    "NAME_PT" VARCHAR(100) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS versions_available (
    sigla            VARCHAR(10) NOT NULL PRIMARY KEY,
    fullname         VARCHAR(150) NOT NULL DEFAULT '',
    year             INT NOT NULL DEFAULT 0,
    language         VARCHAR(50) NOT NULL DEFAULT '',
    copyright        SMALLINT NOT NULL DEFAULT 0,
    copyright_holder VARCHAR(255) NOT NULL DEFAULT '',
    imprimatur       SMALLINT NOT NULL DEFAULT 0,
    canon            VARCHAR(20) DEFAULT NULL,
    notes            VARCHAR(1500) NOT NULL DEFAULT '',
    type             VARCHAR(20) NOT NULL DEFAULT 'BIBLE'
);

CREATE TABLE IF NOT EXISTS usage_counter (
    datetime      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP PRIMARY KEY,
    currentcount  INT NOT NULL DEFAULT 0,
    quarthourcount INT NOT NULL DEFAULT 0
);

-- ── Bible book names (25 languages) ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS biblebooks_fullname (
    "BOOK"        INT NOT NULL PRIMARY KEY,
    "ENGLISH"     VARCHAR(255) NOT NULL DEFAULT '',
    "AFRIKAANS"   VARCHAR(255) NOT NULL DEFAULT '',
    "ALBANIAN"    VARCHAR(255) NOT NULL DEFAULT '',
    "AMHARIC"     VARCHAR(255) NOT NULL DEFAULT '',
    "ARABIC"      VARCHAR(255) NOT NULL DEFAULT '',
    "CHINESE"     VARCHAR(255) NOT NULL DEFAULT '',
    "CROATIAN"    VARCHAR(255) NOT NULL DEFAULT '',
    "CZECH"       VARCHAR(150) NOT NULL DEFAULT '',
    "FILIPINO"    VARCHAR(255) NOT NULL DEFAULT '',
    "FRENCH"      VARCHAR(255) NOT NULL DEFAULT '',
    "GERMAN"      VARCHAR(255) NOT NULL DEFAULT '',
    "GREEK"       VARCHAR(255) NOT NULL DEFAULT '',
    "HUNGARIAN"   VARCHAR(255) NOT NULL DEFAULT '',
    "ITALIAN"     VARCHAR(255) NOT NULL DEFAULT '',
    "JAPANESE"    VARCHAR(255) NOT NULL DEFAULT '',
    "KOREAN"      VARCHAR(255) NOT NULL DEFAULT '',
    "LATIN"       VARCHAR(255) NOT NULL DEFAULT '',
    "POLISH"      VARCHAR(255) NOT NULL DEFAULT '',
    "PORTUGUESE"  VARCHAR(255) NOT NULL DEFAULT '',
    "ROMANIAN"    VARCHAR(255) NOT NULL DEFAULT '',
    "RUSSIAN"     VARCHAR(255) NOT NULL DEFAULT '',
    "SPANISH"     VARCHAR(255) NOT NULL DEFAULT '',
    "TAMIL"       VARCHAR(255) NOT NULL DEFAULT '',
    "THAI"        VARCHAR(255) NOT NULL DEFAULT '',
    "VIETNAMESE"  VARCHAR(255) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS biblebooks_abbr (
    "BOOK"        INT NOT NULL PRIMARY KEY,
    "AFRIKAANS"   VARCHAR(255) NOT NULL DEFAULT '',
    "ALBANIAN"    VARCHAR(255) NOT NULL DEFAULT '',
    "AMHARIC"     VARCHAR(255) NOT NULL DEFAULT '',
    "ARABIC"      VARCHAR(255) NOT NULL DEFAULT '',
    "CHINESE"     VARCHAR(255) NOT NULL DEFAULT '',
    "CROATIAN"    VARCHAR(255) NOT NULL DEFAULT '',
    "CZECH"       VARCHAR(255) NOT NULL DEFAULT '',
    "ENGLISH"     VARCHAR(255) NOT NULL DEFAULT '',
    "FILIPINO"    VARCHAR(255) NOT NULL DEFAULT '',
    "FRENCH"      VARCHAR(255) NOT NULL DEFAULT '',
    "GERMAN"      VARCHAR(255) NOT NULL DEFAULT '',
    "GREEK"       VARCHAR(255) NOT NULL DEFAULT '',
    "HUNGARIAN"   VARCHAR(255) NOT NULL DEFAULT '',
    "ITALIAN"     VARCHAR(255) NOT NULL DEFAULT '',
    "JAPANESE"    VARCHAR(255) NOT NULL DEFAULT '',
    "KOREAN"      VARCHAR(255) NOT NULL DEFAULT '',
    "LATIN"       VARCHAR(255) NOT NULL DEFAULT '',
    "POLISH"      VARCHAR(255) NOT NULL DEFAULT '',
    "PORTUGUESE"  VARCHAR(255) NOT NULL DEFAULT '',
    "ROMANIAN"    VARCHAR(255) NOT NULL DEFAULT '',
    "RUSSIAN"     VARCHAR(255) NOT NULL DEFAULT '',
    "SPANISH"     VARCHAR(255) NOT NULL DEFAULT '',
    "TAMIL"       VARCHAR(255) NOT NULL DEFAULT '',
    "THAI"        VARCHAR(255) NOT NULL DEFAULT '',
    "VIETNAMESE"  VARCHAR(255) NOT NULL DEFAULT ''
);

-- ── Bible version tables (common schema for most versions) ───────────────────
-- Each version has a text table and an index table.
-- Schemas vary slightly between versions, so we create them individually.

-- Template for full Bible text tables (CEI2008, BLPD, NVBSE, NABRE, NABRE_old, LUZZI):
--   testament, section, book, chapter, versedescr, verse, verseequiv, verseorigin,
--   text, title1, title2, title3, verseID

CREATE TABLE IF NOT EXISTS "CEI2008" (
    testament   SMALLINT NOT NULL,
    section     INT NOT NULL,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) DEFAULT NULL,
    title2      VARCHAR(100) DEFAULT NULL,
    title3      VARCHAR(100) DEFAULT NULL,
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "CEI2008_bcv" ON "CEI2008" (book, chapter, verse, verseequiv, verseorigin);
CREATE INDEX IF NOT EXISTS "CEI2008_text_fts" ON "CEI2008" USING gin(to_tsvector('simple', text));
CREATE INDEX IF NOT EXISTS "CEI2008_title1_fts" ON "CEI2008" USING gin(to_tsvector('simple', COALESCE(title1, '')));
CREATE INDEX IF NOT EXISTS "CEI2008_title2_fts" ON "CEI2008" USING gin(to_tsvector('simple', COALESCE(title2, '')));
CREATE INDEX IF NOT EXISTS "CEI2008_title3_fts" ON "CEI2008" USING gin(to_tsvector('simple', COALESCE(title3, '')));

CREATE TABLE IF NOT EXISTS "CEI2008_idx" (
    book        INT NOT NULL PRIMARY KEY,
    chapters    INT NOT NULL DEFAULT 0,
    verses_count TEXT DEFAULT NULL,
    verses_last TEXT DEFAULT NULL,
    fullname    VARCHAR(30) NOT NULL DEFAULT '',
    abbrev      VARCHAR(10) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS "BLPD" (
    testament   SMALLINT NOT NULL,
    section     INT NOT NULL,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) DEFAULT NULL,
    title2      VARCHAR(100) DEFAULT NULL,
    title3      VARCHAR(100) DEFAULT NULL,
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "BLPD_bcv" ON "BLPD" (book, chapter, verse, verseequiv, verseorigin);
CREATE INDEX IF NOT EXISTS "BLPD_text_fts" ON "BLPD" USING gin(to_tsvector('simple', text));
CREATE INDEX IF NOT EXISTS "BLPD_title1_fts" ON "BLPD" USING gin(to_tsvector('simple', COALESCE(title1, '')));
CREATE INDEX IF NOT EXISTS "BLPD_title2_fts" ON "BLPD" USING gin(to_tsvector('simple', COALESCE(title2, '')));
CREATE INDEX IF NOT EXISTS "BLPD_title3_fts" ON "BLPD" USING gin(to_tsvector('simple', COALESCE(title3, '')));

CREATE TABLE IF NOT EXISTS "BLPD_idx" (
    book        INT NOT NULL PRIMARY KEY,
    chapters    INT NOT NULL DEFAULT 0,
    verses_count TEXT DEFAULT NULL,
    verses_last TEXT DEFAULT NULL,
    fullname    VARCHAR(30) DEFAULT NULL,
    abbrev      VARCHAR(10) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS "NVBSE" (
    testament   SMALLINT NOT NULL,
    section     INT NOT NULL,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) NOT NULL DEFAULT '',
    title2      VARCHAR(100) NOT NULL DEFAULT '',
    title3      VARCHAR(100) NOT NULL DEFAULT '',
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "NVBSE_bcv" ON "NVBSE" (book, chapter, verse, verseequiv, verseorigin);
CREATE INDEX IF NOT EXISTS "NVBSE_text_fts" ON "NVBSE" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "NVBSE_idx" (
    book             INT NOT NULL,
    book_consecutive INT NOT NULL PRIMARY KEY,
    chapters         INT NOT NULL DEFAULT 0,
    verses_count     TEXT NOT NULL DEFAULT '',
    verses_last      TEXT NOT NULL DEFAULT '',
    fullname         VARCHAR(30) NOT NULL DEFAULT '',
    abbrev           VARCHAR(10) NOT NULL DEFAULT '',
    UNIQUE (book)
);

-- NABRE: verse is VARCHAR(5) not INT
CREATE TABLE IF NOT EXISTS "NABRE" (
    testament   SMALLINT NOT NULL,
    section     INT NOT NULL,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       VARCHAR(5) NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) NOT NULL DEFAULT '',
    title2      VARCHAR(100) NOT NULL DEFAULT '',
    title3      VARCHAR(100) NOT NULL DEFAULT '',
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "NABRE_bcv" ON "NABRE" (book, chapter, verse, verseequiv);
CREATE INDEX IF NOT EXISTS "NABRE_text_fts" ON "NABRE" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "NABRE_idx" (
    book        INT NOT NULL PRIMARY KEY,
    chapters    INT NOT NULL DEFAULT 0,
    verses_count TEXT NOT NULL DEFAULT '',
    verses_last TEXT NOT NULL DEFAULT '',
    fullname    VARCHAR(30) NOT NULL DEFAULT '',
    abbrev      VARCHAR(10) NOT NULL DEFAULT ''
);

-- NABRE_old: same as NABRE (archived version)
CREATE TABLE IF NOT EXISTS "NABRE_old" (
    testament   SMALLINT NOT NULL,
    section     INT NOT NULL,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       VARCHAR(5) NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) NOT NULL DEFAULT '',
    title2      VARCHAR(100) NOT NULL DEFAULT '',
    title3      VARCHAR(100) NOT NULL DEFAULT '',
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "NABRE_old_bcv" ON "NABRE_old" (book, chapter, verse, verseequiv);
CREATE INDEX IF NOT EXISTS "NABRE_old_text_fts" ON "NABRE_old" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "LUZZI" (
    testament   SMALLINT NOT NULL,
    section     INT NOT NULL,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) NOT NULL DEFAULT '',
    title2      VARCHAR(100) NOT NULL DEFAULT '',
    title3      VARCHAR(100) NOT NULL DEFAULT '',
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "LUZZI_bcv" ON "LUZZI" (book, chapter, verse);
CREATE INDEX IF NOT EXISTS "LUZZI_text_fts" ON "LUZZI" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "LUZZI_idx" (
    book             INT NOT NULL,
    book_consecutive SERIAL PRIMARY KEY,
    chapters         INT NOT NULL DEFAULT 0,
    verses_count     TEXT NOT NULL DEFAULT '',
    verses_last      TEXT NOT NULL DEFAULT '',
    fullname         VARCHAR(30) NOT NULL DEFAULT '',
    abbrev           VARCHAR(10) NOT NULL DEFAULT '',
    UNIQUE (book)
);

-- DIVCOM: no testament/section columns, no verseorigin
CREATE TABLE IF NOT EXISTS "DIVCOM" (
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) DEFAULT NULL,
    title2      VARCHAR(100) DEFAULT NULL,
    title3      VARCHAR(100) DEFAULT NULL,
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "DIVCOM_bcv" ON "DIVCOM" (book, chapter, verse);
CREATE INDEX IF NOT EXISTS "DIVCOM_text_fts" ON "DIVCOM" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "DIVCOM_idx" (
    book        INT NOT NULL PRIMARY KEY,
    fullname    VARCHAR(30) NOT NULL DEFAULT '',
    abbrev      VARCHAR(10) NOT NULL DEFAULT '',
    chapters    INT NOT NULL DEFAULT 0,
    verses_count TEXT DEFAULT NULL,
    verses_last TEXT DEFAULT NULL
);

-- VGCL and DRB: already exist from test fixtures but with fewer columns.
-- Production versions match the full schema pattern.
CREATE TABLE IF NOT EXISTS "VGCL" (
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) DEFAULT NULL,
    title2      VARCHAR(100) DEFAULT NULL,
    title3      VARCHAR(100) DEFAULT NULL,
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "VGCL_bcv" ON "VGCL" (book, chapter, verse, verseequiv, verseorigin);
CREATE INDEX IF NOT EXISTS "VGCL_text_fts" ON "VGCL" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "VGCL_idx" (
    book        INT NOT NULL PRIMARY KEY,
    chapters    INT NOT NULL DEFAULT 0,
    verses_count TEXT DEFAULT NULL,
    verses_last TEXT DEFAULT NULL,
    fullname    VARCHAR(30) DEFAULT NULL,
    abbrev      VARCHAR(10) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS "DRB" (
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    verse       INT NOT NULL,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    verseorigin VARCHAR(10) DEFAULT NULL,
    text        VARCHAR(900) NOT NULL DEFAULT '',
    title1      VARCHAR(100) DEFAULT NULL,
    title2      VARCHAR(100) DEFAULT NULL,
    title3      VARCHAR(100) DEFAULT NULL,
    "verseID"   SERIAL PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS "DRB_bcv" ON "DRB" (book, chapter, verse, verseequiv, verseorigin);
CREATE INDEX IF NOT EXISTS "DRB_text_fts" ON "DRB" USING gin(to_tsvector('simple', text));

CREATE TABLE IF NOT EXISTS "DRB_idx" (
    book        INT NOT NULL PRIMARY KEY,
    chapters    INT NOT NULL DEFAULT 0,
    verses_count TEXT DEFAULT NULL,
    verses_last TEXT DEFAULT NULL,
    fullname    VARCHAR(30) DEFAULT NULL,
    abbrev      VARCHAR(10) DEFAULT NULL
);

-- ── Literature tables ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS "CantiLiturgici" (
    "IDX"       SERIAL PRIMARY KEY,
    "Titolo"    VARCHAR(255) NOT NULL DEFAULT '',
    "Autore"    VARCHAR(50) DEFAULT NULL,
    "Categorie" TEXT DEFAULT NULL
);

-- ── curl error log ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS curl_error (
    "COUNTER"   SERIAL PRIMARY KEY,
    "ERRNO"     INT NOT NULL DEFAULT 0,
    "ERROR"     VARCHAR(100) NOT NULL DEFAULT '',
    "CURLWHEN"  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Request log tables ───────────────────────────────────────────────────────
-- Legacy table (pre-yearly) and old yearly tables (2014-2019): IP was int unsigned
-- Newer yearly tables (2020+): IP was varbinary(16)
-- In PostgreSQL, all use the native inet type.

-- Legacy requests_log (pre-yearly partitioning)
CREATE TABLE IF NOT EXISTS requests_log (
    "COUNTER"              SERIAL PRIMARY KEY,
    "WHO_WHEN"             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "WHO_IP"               inet,
    "WHO_WHERE_JSON"       VARCHAR(500) NOT NULL DEFAULT '',
    "HEADERS_JSON"         VARCHAR(800) NOT NULL DEFAULT '',
    "QUERY"                VARCHAR(150) NOT NULL DEFAULT '',
    "REQUEST_METHOD"       VARCHAR(50) NOT NULL DEFAULT '',
    "HTTP_CLIENT_IP"       VARCHAR(20) NOT NULL DEFAULT '',
    "HTTP_X_FORWARDED_FOR" VARCHAR(20) NOT NULL DEFAULT '',
    "HTTP_X_REAL_IP"       VARCHAR(20) NOT NULL DEFAULT '',
    "REMOTE_ADDR"          VARCHAR(20) NOT NULL DEFAULT '',
    "APP_ID"               VARCHAR(50) NOT NULL DEFAULT '',
    "DOMAIN"               VARCHAR(200) NOT NULL DEFAULT '',
    "PLUGINVERSION"        VARCHAR(20) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS "requests_log_ip_when" ON requests_log ("WHO_IP", "WHO_WHEN" DESC);

-- Function to create yearly log tables (used by migration script and at runtime)
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
                "QUERY" VARCHAR(255) NOT NULL DEFAULT %L,
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
                "QUERY" VARCHAR(255) NOT NULL DEFAULT %L,
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

-- Create yearly tables dynamically from 2014 through next year.
-- Tables before 2020 lack ORIGIN/ORIGINALQUERY columns.
-- The helper function is kept for runtime use (creating future yearly tables on demand).
SELECT create_yearly_log_table(y::TEXT, y >= 2020)
FROM generate_series(2014, EXTRACT(YEAR FROM NOW())::INT + 1) AS y;
