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

-- Bible book full names (73 rows = 73 books, one column per language).
-- Mirrors the live table; data lives in biblebooks.sql.
CREATE TABLE IF NOT EXISTS biblebooks_fullname (
    "BOOK" integer NOT NULL PRIMARY KEY,
    "ENGLISH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "AFRIKAANS" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ALBANIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "AMHARIC" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ARABIC" character varying(255) DEFAULT ''::character varying NOT NULL,
    "CHINESE" character varying(255) DEFAULT ''::character varying NOT NULL,
    "CROATIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "CZECH" character varying(150) DEFAULT ''::character varying NOT NULL,
    "FILIPINO" character varying(255) DEFAULT ''::character varying NOT NULL,
    "FRENCH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "GERMAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "GREEK" character varying(255) DEFAULT ''::character varying NOT NULL,
    "HUNGARIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ITALIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "JAPANESE" character varying(255) DEFAULT ''::character varying NOT NULL,
    "KOREAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "LATIN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "POLISH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "PORTUGUESE" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ROMANIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "RUSSIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "SPANISH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "TAMIL" character varying(255) DEFAULT ''::character varying NOT NULL,
    "THAI" character varying(255) DEFAULT ''::character varying NOT NULL,
    "VIETNAMESE" character varying(255) DEFAULT ''::character varying NOT NULL
);

-- Bible book abbreviations (same shape; note ENGLISH is not the first language column here)
CREATE TABLE IF NOT EXISTS biblebooks_abbr (
    "BOOK" integer NOT NULL PRIMARY KEY,
    "AFRIKAANS" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ALBANIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "AMHARIC" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ARABIC" character varying(255) DEFAULT ''::character varying NOT NULL,
    "CHINESE" character varying(255) DEFAULT ''::character varying NOT NULL,
    "CROATIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "CZECH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ENGLISH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "FILIPINO" character varying(255) DEFAULT ''::character varying NOT NULL,
    "FRENCH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "GERMAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "GREEK" character varying(255) DEFAULT ''::character varying NOT NULL,
    "HUNGARIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ITALIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "JAPANESE" character varying(255) DEFAULT ''::character varying NOT NULL,
    "KOREAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "LATIN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "POLISH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "PORTUGUESE" character varying(255) DEFAULT ''::character varying NOT NULL,
    "ROMANIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "RUSSIAN" character varying(255) DEFAULT ''::character varying NOT NULL,
    "SPANISH" character varying(255) DEFAULT ''::character varying NOT NULL,
    "TAMIL" character varying(255) DEFAULT ''::character varying NOT NULL,
    "THAI" character varying(255) DEFAULT ''::character varying NOT NULL,
    "VIETNAMESE" character varying(255) DEFAULT ''::character varying NOT NULL
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
-- Per-(version, column) row: migration 012 widened the PK so a single
-- version can carry metadata for both the legacy `embedding` (MiniLM) and
-- the newer `embedding_labse` (LaBSE) columns. The default on column_name
-- preserves source-compat with pre-migration callers that INSERT without
-- naming it.
CREATE TABLE IF NOT EXISTS embedding_metadata (
    version_sigla VARCHAR(20) NOT NULL REFERENCES versions_available(sigla) ON DELETE CASCADE,
    column_name   VARCHAR(50) NOT NULL DEFAULT 'embedding',
    model_name    VARCHAR(100) NOT NULL,
    model_version VARCHAR(50) NOT NULL DEFAULT '',
    dimensions    INT NOT NULL CHECK (dimensions > 0),
    computed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    content_xor   BYTEA,
    PRIMARY KEY (version_sigla, column_name)
);

-- Plain-text normalizer (mirror of migrations/019-add-text-plain-and-title-position.sql)
CREATE OR REPLACE FUNCTION bibleget_plain_text(src TEXT) RETURNS TEXT
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(src,
        '</?(sm|i|sup)>', '', 'g'),
        '</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>', ' ', 'g'),
        '<br\s*/?>', ' ', 'gi'),
        '\s+', ' ', 'g'))
$$;

-- Title-position guard (mirror of migrations/019). A heading may only be inserted between tokens: never splitting a word
-- (judged on the *visible* characters either side, so an offset inside
-- L<sm>|ORD</sm> is rejected even though the raw neighbour is '>'), never
-- inside a tag, never at either end of the text. Inline tags (sm, i, sup)
-- vanish in the visible text; any other tag counts as a space, as in
-- bibleget_plain_text().
CREATE OR REPLACE FUNCTION bibleget_title_position_ok(src TEXT, pos INT) RETURNS BOOLEAN
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
    SELECT pos IS NULL OR (
        pos > 0 AND pos < char_length(src)
        AND left(src, pos) !~ '<[^>]*$'
        AND NOT (
            right(regexp_replace(regexp_replace(left(src, pos), '</?(sm|i|sup)>', '', 'g'), '<[^>]*>', ' ', 'g'), 1) ~ '[[:alnum:]]'
            AND left(regexp_replace(regexp_replace(substr(src, pos + 1), '</?(sm|i|sup)>', '', 'g'), '<[^>]*>', ' ', 'g'), 1) ~ '[[:alnum:]]'
        )
    )
$$;

-- Markup validator (mirror of migrations/030-markup-validator.sql)
CREATE OR REPLACE FUNCTION bibleget_markup_ok(src TEXT) RETURNS BOOLEAN
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
DECLARE
    line_tags   CONSTANT TEXT[] := ARRAY['po', 'pof', 'pol', 'poi', 'poil', 'poif', 'pos', 'po3', 'po3l',
                                         'pr', 'bq', 'bqf', 'bql'];
    inline_tags CONSTANT TEXT[] := ARRAY['sm', 'i', 'sup', 'speaker'];
    line        TEXT;
    m           TEXT[];
    stack       TEXT[];
    tag         TEXT;
BEGIN
    IF src IS NULL THEN
        RETURN TRUE;
    END IF;
    FOREACH line IN ARRAY string_to_array(src, E'\n') LOOP
        stack := '{}';
        FOR m IN SELECT regexp_matches(line, '<(/?)([A-Za-z][A-Za-z0-9]*)([^>]*)>', 'g') LOOP
            tag := m[2];
            IF m[3] <> '' OR NOT (tag = ANY (line_tags) OR tag = ANY (inline_tags)) THEN
                RETURN FALSE;  -- unknown tag, attributes or self-closing
            END IF;
            IF m[1] = '' THEN
                IF tag = ANY (line_tags) AND stack && line_tags THEN
                    RETURN FALSE;  -- line tag inside a line tag
                END IF;
                stack := stack || tag;
            ELSIF cardinality(stack) = 0 OR stack[cardinality(stack)] <> tag THEN
                RETURN FALSE;  -- unmatched or mis-nested closing tag
            ELSE
                stack := stack[1:cardinality(stack) - 1];
            END IF;
        END LOOP;
        IF cardinality(stack) > 0 THEN
            RETURN FALSE;  -- tag left open at the end of the line
        END IF;
    END LOOP;
    RETURN TRUE;
END
$$;

-- Trigger function to auto-maintain text_plain and text_hash on verse tables
CREATE OR REPLACE FUNCTION update_text_hash()
RETURNS TRIGGER AS $$
BEGIN
    NEW.text_plain := bibleget_plain_text(NEW.text);
    NEW.text_hash  := decode(md5(NEW.text_plain), 'hex');
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
