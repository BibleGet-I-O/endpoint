-- Migration: validate the formatting markup of every edition's `text`
--
-- `text` carries the NABRE tag set (see the wiki's Database Schema page):
-- line tags <po> <pof> <pol> <poi> <poil> <poif> <pos> <po3> <po3l> <pr>
-- <bq> <bqf> <bql>, wrapping one line each, and inline tags <sm> <i> <sup>
-- <speaker>. Clients (WordPress, Google Docs, MediaWiki) and the HTML quote
-- output (FormattedText::toHtml) rely on that markup being well formed.
--
-- bibleget_markup_ok(text) checks each line ("\n"-separated) for:
--   * known tags only (no attributes, no self-closing tags);
--   * pairs that match and nest correctly within the line -- so a pair never
--     spans a line break;
--   * no line tag inside another line tag.
-- Every edition gets a CHECK (bibleget_markup_ok(text)) constraint, so a
-- malformed text is rejected on INSERT/UPDATE (bibleget_rebuild_in_order()
-- copies it, as it copies all CHECK constraints).
--
-- Before the constraint, the 10 NABRE rows that had a stray line break inside
-- a tag pair (from the 2015 SCML conversion) are fixed: Gen 3:18, 9:27, 48:16
-- ("<po>⏎Thorns ..."), Ps 71:18, 81:8, 86:2, 144:10, Sg 3:10, Lk 1:74,
-- 2 Tm 3:9 ("... Your power ⏎</po>"). Only whitespace moves, so text_plain,
-- text_hash and the embeddings are unchanged (asserted).
--
-- The constraint is added NOT VALID and validated in its own block, so no
-- table is scanned while ACCESS EXCLUSIVE locks are held (as in 019).
-- scripts/markup.py is the Python mirror, used by the generators.

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

COMMENT ON FUNCTION bibleget_markup_ok(TEXT) IS
    'Is a verse text''s markup well formed (known tags, matched and nested per line, no line tag inside a line tag)? Migration 030.';

-- Stray line breaks inside a tag pair -> removed (whitespace only).
DO $$
DECLARE
    tbl TEXT;
    n   INT;
    tags CONSTANT TEXT := 'po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|sm|i|sup|speaker';
BEGIN
    FOR tbl IN SELECT sigla FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', tbl)) IS NULL;
        EXECUTE format(
            'UPDATE %I SET text = regexp_replace(regexp_replace(text, %L, %L, %L), %L, %L, %L) '
            || 'WHERE (text ~ %L OR text ~ %L) '
            || 'AND bibleget_plain_text(regexp_replace(regexp_replace(text, %L, %L, %L), %L, %L, %L)) = text_plain',
            tbl,
            '(<(' || tags || ')>)[ \t]*\n[ \t]*', '\1', 'g', '[ \t]*\n[ \t]*(</(' || tags || ')>)', '\1', 'g',
            '<(' || tags || ')>[ \t]*\n', '\n[ \t]*</(' || tags || ')>',
            '(<(' || tags || ')>)[ \t]*\n[ \t]*', '\1', 'g', '[ \t]*\n[ \t]*(</(' || tags || ')>)', '\1', 'g');
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n > 0 THEN
            RAISE NOTICE '%: % rows had a line break inside a tag pair', tbl, n;
        END IF;
    END LOOP;
END
$$;

-- The constraint, added NOT VALID (no scan under ACCESS EXCLUSIVE) ...
DO $$
DECLARE
    tbl TEXT;
BEGIN
    FOR tbl IN SELECT sigla FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', tbl)) IS NULL;
        EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I', tbl, tbl || '_markup_check');
        EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (bibleget_markup_ok(text)) NOT VALID',
                       tbl, tbl || '_markup_check');
    END LOOP;
END
$$;

-- ... then validated table by table (SHARE UPDATE EXCLUSIVE: reads and writes continue).
DO $$
DECLARE
    tbl TEXT;
BEGIN
    FOR tbl IN SELECT sigla FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', tbl)) IS NULL;
        EXECUTE format('ALTER TABLE %I VALIDATE CONSTRAINT %I', tbl, tbl || '_markup_check');
    END LOOP;
END
$$;
