-- Migration: NABRE Numbers 25:19 / 26:19
--
-- Found while lining up NABRE with the publisher's SCML sources
-- (nab-04-nm.scml, see scripts/nabre_headings_from_scml.py):
--
--   * The row between 25:18 and 26:1 holds "After the plague" -- NAB's 25:19,
--     which the NABRE prints at the head of chapter 26 -- but was stored as
--     chapter 26, verse 19.
--   * The real 26:19 ("The sons of Judah were Er and Onan. Er and Onan died in
--     the land of Canaan.") was missing: chapter 26 went from 18 to 20.
--
-- Fix: relabel the row as 25:19, insert 26:19 after 26:18, rebuild NABRE in
-- reading order (bibleget_rebuild_in_order, 024) so the new row gets its place
-- in "verseID", and recompute NABRE_idx for Numbers. Guarded and idempotent;
-- the new row has no embedding until compute_embeddings.py runs.

BEGIN;

CREATE TEMP TABLE nabre_ord (vid INT PRIMARY KEY, ord NUMERIC NOT NULL) ON COMMIT DROP;

DO $mig$
DECLARE
    n       INT;
    v2618   INT;
    new_vid INT;
BEGIN
    IF EXISTS (SELECT 1 FROM "NABRE" WHERE book = 4 AND chapter = 25 AND verse = 19) THEN
        RAISE NOTICE '028 already applied';
        RETURN;
    END IF;

    UPDATE "NABRE" SET chapter = 25, verse = 19
    WHERE book = 4 AND chapter = 26 AND verse = 19 AND text_plain = 'After the plague';
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n <> 1 THEN RAISE EXCEPTION 'guard failed: NABRE Num 26:19 "After the plague" (% rows)', n; END IF;

    SELECT "verseID" INTO v2618 FROM "NABRE" WHERE book = 4 AND chapter = 26 AND verse = 18;
    INSERT INTO "NABRE" (testament, section, book, chapter, verse, text, title1, title2, title3)
    SELECT testament, section, 4, 26, 19,
           'The sons of Judah were Er and Onan. Er and Onan died in the land of Canaan.', '', '', ''
    FROM "NABRE" WHERE "verseID" = v2618
    RETURNING "verseID" INTO new_vid;

    INSERT INTO nabre_ord SELECT "verseID", "verseID" FROM "NABRE";
    UPDATE nabre_ord SET ord = v2618 + 0.5 WHERE vid = new_vid;
    PERFORM bibleget_rebuild_in_order('NABRE', 'nabre_ord');

    UPDATE "NABRE_idx" i SET verses_count = x.cnt, verses_last = x.last
    FROM (
        SELECT string_agg(d::text, ',' ORDER BY chapter) AS cnt, string_agg(m::text, ',' ORDER BY chapter) AS last
        FROM (SELECT chapter, count(DISTINCT verse) AS d, max(verse) AS m FROM "NABRE" WHERE book = 4 GROUP BY chapter) c
    ) x
    WHERE i.book = 4;
END
$mig$;

COMMIT;
