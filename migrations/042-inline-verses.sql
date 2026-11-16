-- 042: verses printed inline, merged into the verse before (#185).
--
-- Reviewing #185's remaining "needs review" items against each edition's
-- source turned up verses stored inside the previous verse's row. vatican.va
-- prints some verse numbers inline, before a lowercase word ("... ad bella
-- procedere, 46 sescenta tria milia ..."): the 2015 import kept both verses in
-- one row, and the archive comparisons of 039/041 parsed the page the same way,
-- so they saw no difference. A scan of every edition for the next verse's number
-- inside a verse found them all:
--   * NVBSE: Num 1:46, 2 Reg 9:31, Esd 1:11, Esd 2:5, Neh 12:29, 2 Macc 14:36,
--     Ps 130:6, Ps 140:10, Is 30:16, Ez 13:16, Act 4:30, Act 15:18, Rom 14:8;
--     and 1 Par 11:40, stored as verse 0 (vatican.va misprints "0 Hira").
--   * BLPD: Esd 1:11, Act 28:2.
--   * DRB (checked against drbo.org, chapter by chapter wherever DRB's numbering
--     differs from VGCL's): Mal 3:12 was held in 3:11 ("... of hosts.12 And all
--     nations ..."), Prov 28:25 in the row numbered 26 (no number left in the
--     text), and Wis 18:25 was numbered 26.
-- NVBSE and BLPD are split by the data halves in their private archives (apply
-- NVBSE-vatican/migrations/042-nvbse-inline-verses.sql and
-- BLPD-vatican/migrations/042-blpd-inline-verses.sql FIRST); this public half
-- checks their result, fixes DRB (public domain) in place, and rebuilds the
-- indexes. Only the affected rows change: headings and the other rows'
-- embeddings stay. Re-embed the three editions afterwards (about 40 rows).
-- Rerunning it is harmless: the DRB fixes check for themselves, the rest is
-- a check and an index rebuild.

BEGIN;

DO $mig$
DECLARE
    digest TEXT;
    vid INT;
BEGIN
    SELECT md5(string_agg(concat_ws('|', book, chapter, verse, coalesce(verseequiv, ''), coalesce(verseorigin, ''), text),
                          E'\n' ORDER BY "verseID")) INTO digest FROM "NVBSE";
    IF digest <> '0bb9bbd401a3e64be4a5ae38f188981d' OR (SELECT count(*) FROM "NVBSE") <> 35891 THEN
        RAISE EXCEPTION 'NVBSE does not hold the NVBSE-vatican archive''s text (md5 %): apply NVBSE-vatican/migrations/042-nvbse-inline-verses.sql first', digest;
    END IF;

    SELECT md5(string_agg(concat_ws('|', book, chapter, verse, coalesce(verseequiv, ''), coalesce(verseorigin, ''), text),
                          E'\n' ORDER BY "verseID")) INTO digest FROM "BLPD";
    IF digest <> '96d61746b02e63c683a0c7117c4e5fcb' OR (SELECT count(*) FROM "BLPD") <> 35737 THEN
        RAISE EXCEPTION 'BLPD does not hold the BLPD-vatican archive''s text (md5 %): apply BLPD-vatican/migrations/042-blpd-inline-verses.sql first', digest;
    END IF;

    -- DRB Prov 28:25-26: the row numbered 26 holds both verses.
    IF NOT EXISTS (SELECT 1 FROM "DRB" WHERE book = 24 AND chapter = 28 AND verse = 25) THEN
        SELECT "verseID" INTO vid FROM "DRB"
         WHERE book = 24 AND chapter = 28 AND verse = 26 AND md5(text) = '2a2803572308765e33d4f3cd039b3c65';
        IF vid IS NULL THEN RAISE EXCEPTION 'DRB Prov 28:26 is not the row expected'; END IF;
        UPDATE "DRB" SET "verseID" = -"verseID" - 1 WHERE "verseID" > vid;
        UPDATE "DRB" SET "verseID" = -"verseID" WHERE "verseID" < 0;
        UPDATE "DRB" SET verse = 25,
               text = 'He that boasteth, and puffeth up himself, stirreth up quarrels: but he that trusteth in the Lord, shall be healed.',
               embedding = NULL, embedding_labse = NULL, embedded_text_hash = NULL
         WHERE "verseID" = vid;
        INSERT INTO "DRB" ("verseID", testament, section, book, chapter, verse, text)
        SELECT vid + 1, testament, section, book, chapter, 26,
               'He that trusteth in his own heart, is a fool: but he that walketh wisely, he shall be saved.'
          FROM "DRB" WHERE "verseID" = vid;
        PERFORM setval(pg_get_serial_sequence('"DRB"', 'verseID'), (SELECT max("verseID") FROM "DRB"));
    END IF;
    -- DRB Mal 3:11-12: the row of 3:11 holds 3:12 too.
    IF NOT EXISTS (SELECT 1 FROM "DRB" WHERE book = 46 AND chapter = 3 AND verse = 12) THEN
        SELECT "verseID" INTO vid FROM "DRB"
         WHERE book = 46 AND chapter = 3 AND verse = 11 AND md5(text) = 'a3dd3e269b267adb5f8d65a919c10e9d';
        IF vid IS NULL THEN RAISE EXCEPTION 'DRB Mal 3:11 is not the row expected'; END IF;
        UPDATE "DRB" SET "verseID" = -"verseID" - 1 WHERE "verseID" > vid;
        UPDATE "DRB" SET "verseID" = -"verseID" WHERE "verseID" < 0;
        UPDATE "DRB" SET
               text = 'And I will rebuke for your sakes the devourer, and he shall not spoil the fruit of your land: neither shall the vine in the field be barren, saith the Lord of hosts.',
               embedding = NULL, embedding_labse = NULL, embedded_text_hash = NULL
         WHERE "verseID" = vid;
        INSERT INTO "DRB" ("verseID", testament, section, book, chapter, verse, text)
        SELECT vid + 1, testament, section, book, chapter, 12,
               'And all nations shall call you blessed: for you shall be a delightful land, saith the Lord of hosts.'
          FROM "DRB" WHERE "verseID" = vid;
        PERFORM setval(pg_get_serial_sequence('"DRB"', 'verseID'), (SELECT max("verseID") FROM "DRB"));
    END IF;
    -- DRB Wis 18: the last verse is 25, stored as 26.
    UPDATE "DRB" SET verse = 25
     WHERE book = 27 AND chapter = 18 AND verse = 26 AND md5(text) = 'd1309e79d715e6ea082c0f1764359207'
       AND NOT EXISTS (SELECT 1 FROM "DRB" WHERE book = 27 AND chapter = 18 AND verse = 25);

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

    UPDATE "BLPD_idx" i SET chapters = x.chapters, verses_count = x.cnt, verses_last = x.last
    FROM (
        SELECT book,
               CASE WHEN book = 19 THEN 2 * count(*) ELSE count(*) END AS chapters,
               CASE WHEN book = 19 THEN string_agg(d::text, ',' ORDER BY chapter) || ',' || string_agg(d::text, ',' ORDER BY chapter)
                    ELSE string_agg(d::text, ',' ORDER BY chapter) END AS cnt,
               CASE WHEN book = 19 THEN string_agg(m::text, ',' ORDER BY chapter) || ',' || string_agg(m::text, ',' ORDER BY chapter)
                    ELSE string_agg(m::text, ',' ORDER BY chapter) END AS last
        FROM (SELECT book, chapter, count(DISTINCT verse) AS d, max(verse) AS m FROM "BLPD" GROUP BY book, chapter) c
        GROUP BY book
    ) x
    WHERE i.book = x.book
      AND (i.chapters, i.verses_count, i.verses_last) IS DISTINCT FROM (x.chapters, x.cnt, x.last);

    -- DRB_idx: only the three chapters (the full rebuild formula doesn't fit DRB's
    -- Esther); position 28 of Proverbs, 18 of Wisdom, 3 of Malachi.
    UPDATE "DRB_idx" SET verses_count = regexp_replace(verses_count, '^((?:[^,]*,){27})27,', '\128,')
     WHERE book = 24 AND split_part(verses_count, ',', 28) = '27';
    UPDATE "DRB_idx" SET verses_last = regexp_replace(verses_last, '^((?:[^,]*,){17})26,', '\125,')
     WHERE book = 27 AND split_part(verses_last, ',', 18) = '26';
    UPDATE "DRB_idx" SET verses_count = regexp_replace(verses_count, '^((?:[^,]*,){2})17,', '\118,')
     WHERE book = 46 AND split_part(verses_count, ',', 3) = '17';
    IF (SELECT split_part(verses_count, ',', 3) || '/' || split_part(verses_last, ',', 3) FROM "DRB_idx" WHERE book = 46) <> '18/18' THEN
        RAISE EXCEPTION 'DRB_idx: unexpected Mal 3 counts';
    END IF;
    IF (SELECT split_part(verses_count, ',', 28) || '/' || split_part(verses_last, ',', 28) FROM "DRB_idx" WHERE book = 24) <> '28/28'
       OR (SELECT split_part(verses_count, ',', 18) || '/' || split_part(verses_last, ',', 18) FROM "DRB_idx" WHERE book = 27) <> '25/25' THEN
        RAISE EXCEPTION 'DRB_idx: unexpected Prov 28 / Wis 18 counts';
    END IF;
END
$mig$;

COMMIT;
