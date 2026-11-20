-- 054: NVBSE verses held in the verse before, behind an omitted verse's
-- bracketed number; Ez 40:42-43 in printed order (#230).
--
-- vatican.va prints the number of a verse the Nova Vulgata omits in
-- parentheses, then the next verse with its own number. In three places the
-- 2015 import kept that next verse in the row before; 042's scan for inline
-- numbers missed them because each sits behind a bracket. Found by comparing
-- every chapter with vatican.va while seeding CatholicOS/cbtudr:
--   * Sir 1:20, held in 1:18 after "(19)" (vatican.va prints "(20 Plenitudo",
--     a stray parenthesis that never closes: dropped);
--   * Ez 40:15, held in 40:13 after "(14)";
--   * Act 24:8, held in 24:6 after "(7)".
-- The bracketed number stays at the end of the verse before, as the Nova
-- Vulgata prints it and as NVBSE keeps its other omitted verses (Mt 17:20
-- "... (21)"): no row of its own (045, case nvbse-omitted-verses).
-- Ez 40:42-43: vatican.va prints 42a, 43a, 42b, 43b, the halves of the two
-- verses interleaved; NVBSE had them as 42a, 42b, 43a, 43b.
--
-- The rows are changed by the data half in the private archive (apply
-- NVBSE-vatican/migrations/054-nvbse-bracketed-verses.sql FIRST, with psql);
-- this public half checks its result and recounts NVBSE_idx (Sir 1, Ez 40 and
-- Act 24 gain a verse each). Without an NVBSE table (the test database) it does
-- nothing. Re-embed NVBSE afterwards (6 rows).
-- Idempotent: a check and a recount.
--
-- Run by Doctrine (src/Migrations/Version054NvbseBracketedVerses.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    digest TEXT;
BEGIN
    IF to_regclass('public."NVBSE"') IS NULL THEN
        RETURN;
    END IF;

    SELECT md5(string_agg(concat_ws('|', book, chapter, verse, coalesce(verse_segment, ''), coalesce(text_tradition, ''), text),
                          E'\n' ORDER BY sort_order)) INTO digest FROM "NVBSE";
    -- Or 055's result (5b4959…, 35927 rows): a database that gets both data halves
    -- before one deploy runs 054 after 055's data half.
    IF (digest, (SELECT count(*) FROM "NVBSE")) NOT IN (('3094d290c61f47e53216ff97c9e295a8', 35894),
                                                       ('5b4959755dae3b402b1a4d3fc0b46520', 35927)) THEN
        RAISE EXCEPTION 'NVBSE does not hold the NVBSE-vatican archive''s text (md5 %): apply NVBSE-vatican/migrations/054-nvbse-bracketed-verses.sql first', digest;
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
