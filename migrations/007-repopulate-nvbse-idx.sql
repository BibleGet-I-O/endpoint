-- Migration: Repopulate NVBSE_idx (issue #108)
-- Purpose: NVBSE_idx was empty in the source MariaDB and migrated faithfully
--          empty into PostgreSQL. The semantic / similar-search handlers
--          (`/v3/search/semantic`, `/v3/search/similar`) read book metadata
--          via `SELECT * FROM "<VERSION>_idx"` and surface zero results when
--          the index is empty, even though the underlying NVBSE table has all
--          35,855 verses correctly embedded.
--
-- This migration is a faithful PostgreSQL port of the canonical MariaDB
-- stored procedure `generate_bible_idx(source_table, idx_table, language)`.
-- For each book, it:
--
--   1. Excludes `verseequiv`-tagged rows (subverse markers like 18a, 18b)
--      from the verse counts — they are pointers, not new verses.
--
--   2. Detects which of three origin patterns the book follows:
--
--      Case A: book has zero rows with verseorigin set
--              → simple per-chapter aggregation
--      Case B: at least one chapter with origin info has only one origin
--              → same as Case A
--      Case C: every chapter with origin info has multiple origins
--              (NABRE-style Esther: GREEK and HEBREW everywhere)
--              → aggregate per (chapter, verseorigin) and emit ordered by
--                verseorigin DESC, chapter — so `chapters` becomes the count
--                of (chapter × origin) pairs and `verses_last` lists each
--                origin's chapters back-to-back
--
--   3. Looks up the Latin name and abbreviation, taking the first alias
--      before any " | " separator.
--
-- For NVBSE specifically, only book 19 (Esther) lands in Case C; the other
-- 72 books are Case A. The output for Esther matches NABRE_idx Esther.
--
-- Idempotent: skips entirely when "NVBSE_idx" already has rows or when NVBSE
-- itself is empty.

DO $$
DECLARE
    bk             INT;
    has_origin     INT;
    has_excl_orig  INT;
    n_chapters     INT;
    v_count        TEXT;
    v_last         TEXT;
    fname          TEXT;
    abbr           TEXT;
    n_inserted     INT := 0;
    expected_books INT;
    current_rows   INT;
BEGIN
    SELECT COUNT(*)               INTO expected_books FROM (SELECT DISTINCT book FROM "NVBSE") b;
    SELECT COUNT(*)               INTO current_rows   FROM "NVBSE_idx";

    IF expected_books = 0 THEN
        RAISE NOTICE 'NVBSE is empty — nothing to derive from. Skipping.';
        RETURN;
    END IF;

    IF current_rows = expected_books THEN
        RAISE NOTICE 'NVBSE_idx already fully populated (% rows) — skipping.', current_rows;
        RETURN;
    END IF;

    -- Partial / inconsistent state: clear before rebuilding so the loop's
    -- INSERTs don't collide with stale rows on the (book) PRIMARY KEY.
    IF current_rows > 0 THEN
        RAISE NOTICE 'NVBSE_idx has % of % rows — clearing for full rebuild.',
                     current_rows, expected_books;
        TRUNCATE TABLE "NVBSE_idx";
    END IF;

    FOR bk IN SELECT DISTINCT book FROM "NVBSE" ORDER BY book LOOP
        -- Does this book have any rows with verseorigin set?
        SELECT COUNT(*) INTO has_origin
        FROM (
            SELECT 1 FROM "NVBSE"
            WHERE book = bk AND verseorigin IS NOT NULL AND verseorigin <> ''
            GROUP BY chapter, verseorigin
        ) o;

        IF has_origin = 0 THEN
            -- Case A: force the simple-aggregation branch below
            has_excl_orig := 1;
        ELSE
            -- Case B vs C: any chapter with only one distinct origin → B
            SELECT COUNT(*) INTO has_excl_orig
            FROM (
                SELECT chapter
                FROM "NVBSE"
                WHERE book = bk AND verseorigin IS NOT NULL AND verseorigin <> ''
                GROUP BY chapter
                HAVING COUNT(DISTINCT verseorigin) = 1
            ) e;
        END IF;

        IF has_excl_orig > 0 THEN
            -- Case A or B: simple per-chapter aggregation, verseequiv filtered out
            SELECT COUNT(*),
                   string_agg(vc::text, ',' ORDER BY chapter),
                   string_agg(vl::text, ',' ORDER BY chapter)
              INTO n_chapters, v_count, v_last
            FROM (
                SELECT chapter,
                       COUNT(DISTINCT CASE WHEN verseequiv IS NULL OR verseequiv = '' THEN verse END) AS vc,
                       MAX(           CASE WHEN verseequiv IS NULL OR verseequiv = '' THEN verse END) AS vl
                FROM "NVBSE"
                WHERE book = bk
                GROUP BY chapter
            ) g;
        ELSE
            -- Case C: per (chapter, verseorigin), ordered verseorigin DESC, chapter
            SELECT COUNT(*),
                   string_agg(vc::text, ',' ORDER BY verseorigin DESC, chapter),
                   string_agg(vl::text, ',' ORDER BY verseorigin DESC, chapter)
              INTO n_chapters, v_count, v_last
            FROM (
                SELECT chapter, verseorigin,
                       COUNT(DISTINCT CASE WHEN verseequiv IS NULL OR verseequiv = '' THEN verse END) AS vc,
                       MAX(           CASE WHEN verseequiv IS NULL OR verseequiv = '' THEN verse END) AS vl
                FROM "NVBSE"
                WHERE book = bk AND verseorigin IS NOT NULL AND verseorigin <> ''
                GROUP BY chapter, verseorigin
            ) g;
        END IF;

        -- Latin name; mirror SUBSTRING_INDEX(@fn, ' | ', 1)
        SELECT COALESCE(SPLIT_PART(bf."LATIN", ' | ', 1), '')
          INTO fname
        FROM biblebooks_fullname bf WHERE bf."BOOK" = bk;

        SELECT COALESCE(SPLIT_PART(ba."LATIN", ' | ', 1), '')
          INTO abbr
        FROM biblebooks_abbr ba WHERE ba."BOOK" = bk;

        INSERT INTO "NVBSE_idx" (
            book, chapters, verses_count, verses_last, fullname, abbrev
        )
        VALUES (
            bk, n_chapters, v_count, v_last,
            LEFT(COALESCE(fname, ''), 30),
            LEFT(COALESCE(abbr,  ''), 10)
        );

        n_inserted := n_inserted + 1;
    END LOOP;

    RAISE NOTICE 'NVBSE_idx repopulated with % rows.', n_inserted;
END $$;
