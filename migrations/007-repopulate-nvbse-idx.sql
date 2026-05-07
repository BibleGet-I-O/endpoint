-- Migration: Repopulate NVBSE_idx (issue #108)
-- Purpose: NVBSE_idx was empty in the source MariaDB and migrated faithfully
--          empty into PostgreSQL. The semantic / similar-search handlers
--          (`/v3/search/semantic`, `/v3/search/similar`) read book metadata
--          via `SELECT * FROM "<VERSION>_idx"` and surface zero results when
--          the index is empty, even though the underlying NVBSE table has all
--          35,855 verses correctly embedded.
--
-- Strategy: Reconstruct the index by aggregating the NVBSE verse table:
--   - book              ← distinct book numbers in NVBSE
--   - book_consecutive  ← 1..N over books in canonical order
--   - chapters          ← MAX(chapter)
--   - verses_count      ← per-chapter COUNT(DISTINCT verse), comma-separated
--   - verses_last       ← per-chapter MAX(verse), comma-separated
--   - fullname/abbrev   ← biblebooks_fullname.LATIN / biblebooks_abbr.LATIN,
--                         taking the first alias before any " | " separator
--                         (matches the canonical short form used in other
--                         Latin-language _idx tables)
--
-- Idempotent: skips entirely when "NVBSE_idx" already has rows.

DO $$
DECLARE
    inserted_rows INT;
BEGIN
    IF EXISTS (SELECT 1 FROM "NVBSE_idx" LIMIT 1) THEN
        RAISE NOTICE 'NVBSE_idx already populated — skipping.';
        RETURN;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM "NVBSE" LIMIT 1) THEN
        RAISE NOTICE 'NVBSE is empty — nothing to derive from. Skipping.';
        RETURN;
    END IF;

    WITH per_chapter AS (
        SELECT
            book,
            chapter,
            COUNT(DISTINCT verse) AS verse_count_in_chap,
            MAX(verse)            AS max_verse_in_chap
        FROM "NVBSE"
        GROUP BY book, chapter
    ),
    per_book AS (
        SELECT
            book,
            MAX(chapter) AS chapters,
            string_agg(verse_count_in_chap::text, ',' ORDER BY chapter) AS verses_count,
            string_agg(max_verse_in_chap::text,   ',' ORDER BY chapter) AS verses_last
        FROM per_chapter
        GROUP BY book
    ),
    numbered AS (
        SELECT
            book,
            ROW_NUMBER() OVER (ORDER BY book)::int AS book_consecutive,
            chapters,
            verses_count,
            verses_last
        FROM per_book
    )
    INSERT INTO "NVBSE_idx" (
        book, book_consecutive, chapters, verses_count, verses_last, fullname, abbrev
    )
    SELECT
        n.book,
        n.book_consecutive,
        n.chapters,
        n.verses_count,
        n.verses_last,
        LEFT(TRIM(SPLIT_PART(COALESCE(NULLIF(bf."LATIN", ''), bf."ENGLISH"), '|', 1)), 30),
        LEFT(TRIM(SPLIT_PART(COALESCE(NULLIF(ba."LATIN", ''), ba."ENGLISH"), '|', 1)), 10)
    FROM numbered n
    JOIN biblebooks_fullname bf ON bf."BOOK" = n.book
    JOIN biblebooks_abbr     ba ON ba."BOOK" = n.book
    ORDER BY n.book;

    GET DIAGNOSTICS inserted_rows = ROW_COUNT;
    RAISE NOTICE 'NVBSE_idx repopulated with % rows.', inserted_rows;
END $$;
