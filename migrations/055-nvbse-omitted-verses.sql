-- 055: NVBSE omitted verses as rows of their own (#230 follow-up).
--
-- The Nova Vulgata leaves out the verses its critical text omits and prints
-- their numbers in parentheses after the verse before: "(21)", "(10. 11)",
-- "(16 17)", "( 6 )". NVBSE kept that marker at the end of the verse before
-- ("... gaudium dabit.(19)") and had no row for the verse: 045 had decided so,
-- unlike the other editions. Now NVBSE follows the convention 045 set for them:
-- a row wherever the edition prints a verse number, with empty text and
-- omitted = true (no embedding; the client shows the number with "[]" or the
-- like), and the marker leaves the text of the verse before. 31 markers, 33
-- verses: 1 Reg 15:6; Sir 1:19, 9:10-11, 19:6, 22:23, 27:3, 29:16-17, 29:21,
-- 29:23, 32:23, 34:11, 35:3, 43:33; Ez 40:14, 40:30; Mt 17:21, 18:11, 23:14;
-- Mc 7:16, 9:44, 9:46, 11:26, 15:28; Lc 17:36, 23:17; Io 5:4; Act 8:37,
-- 15:34, 24:7, 28:29; Rom 16:24.
--
-- The rows are changed by the data half in the private archive (apply
-- NVBSE-vatican/migrations/055-nvbse-omitted-verses.sql FIRST, with psql);
-- this public half checks its result and recounts NVBSE_idx (the omitted
-- verses count, as in the other editions). Without an NVBSE table (the test
-- database) it does nothing. Re-embed NVBSE afterwards: the 31 verses that lost
-- their marker (the omitted rows have no embedding).
-- Idempotent: a check and a recount.
--
-- Run by Doctrine (src/Migrations/Version055NvbseOmittedVerses.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    digest TEXT;
BEGIN
    IF to_regclass('public."NVBSE"') IS NULL THEN
        RETURN;
    END IF;

    SELECT md5(string_agg(concat_ws('|', book, chapter, verse, coalesce(verse_segment, ''), coalesce(text_tradition, ''), text),
                          E'\n' ORDER BY sort_order)) INTO digest FROM "NVBSE";
    IF digest <> '5b4959755dae3b402b1a4d3fc0b46520' OR (SELECT count(*) FROM "NVBSE") <> 35927 THEN
        RAISE EXCEPTION 'NVBSE does not hold the NVBSE-vatican archive''s text (md5 %): apply NVBSE-vatican/migrations/055-nvbse-omitted-verses.sql first', digest;
    END IF;
    IF (SELECT count(*) FROM "NVBSE" WHERE omitted) <> 33 OR EXISTS (SELECT 1 FROM "NVBSE" WHERE omitted <> (text = '')) THEN
        RAISE EXCEPTION 'NVBSE: the omitted rows are not exactly the 33 empty ones';
    END IF;

    UPDATE "NVBSE_idx" i SET chapters = x.chapters, verses_count = x.cnt, verses_last = x.last
    FROM (
        SELECT book,
               CASE WHEN book = 19 THEN 2 * count(*) ELSE count(*) END AS chapters,
               CASE WHEN book = 19 THEN string_agg(d::text, ',' ORDER BY chapter) || ',' || string_agg(d::text, ',' ORDER BY chapter)
                    ELSE string_agg(d::text, ',' ORDER BY chapter) END AS cnt,
               CASE WHEN book = 19 THEN string_agg(m::text, ',' ORDER BY chapter) || ',' || string_agg(m::text, ',' ORDER BY chapter)
                    ELSE string_agg(m::text, ',' ORDER BY chapter) END AS last
        FROM (SELECT book, chapter, count(DISTINCT verse) AS d, max(verse) AS m FROM "NVBSE" GROUP BY book, chapter) c
        GROUP BY book
    ) x
    WHERE i.book = x.book
      AND (i.chapters, i.verses_count, i.verses_last) IS DISTINCT FROM (x.chapters, x.cnt, x.last);
END
$mig$;
