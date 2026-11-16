-- 045: rows at their identity, placement recorded; omitted verses (#180, step 2
-- of the verse-rendering plan; columns from 044).
--
-- NABRE. Four rows (checked against the SCML source in the private NABRE-source
-- repo) move to their identity, with where NABRE prints them recorded:
--   Wis 12:1, stored as a second 11:26 with verseequiv '12:1' -> 12:1,
--     printed_chapter 11, verse_label '12:1' (printed at the end of chapter 11);
--   "I loathe my life.", stored as a second 9:35 with verseequiv '10:1a' -> 10:1,
--     printed_chapter 9, verse_label '10:1' (printed at the end of chapter 9);
--   the rest of Job 10:1 -> verse_label '' (opens chapter 10 without a number);
--   John 7:53 -> printed_chapter 8, verse_label '7:53' (printed under chapter 8).
-- verseID (reading order) is unchanged. Rows are found by key and md5(text).
--
-- Omitted verses: the edition prints the number without text. Their rows get
-- empty text and omitted = true, and lose their embeddings:
--   CEI2008, NABRE, LUZZI: the 16 New Testament verses the critical text omits
--     (Mt 17:21, 18:11, 23:14; Mc 7:16, 9:44, 9:46, 11:26, 15:28; Lc 17:36,
--     23:17; Io 5:4; Act 8:37, 15:34, 24:7, 28:29; Rom 16:24), whose text was a
--     leftover "]", "[]", blank or empty;
--   NABRE 1 Mac 9:34 ("]");
--   CEI2008 Act 8:37 held bracketed text, but CEI 2008 itself (bibbiaedu.it, the
--     private CEI2008-bibbiaedu archive) prints only the number: omitted too.
--   BLPD 1 Kgs 15:6 and 15:32 (printed as a bare number with a footnote): rows
--     added by the private half BLPD-vatican/migrations/045-blpd-omitted-verses.sql,
--     which must be applied FIRST; this half checks the result (md5).
-- NABRE Rom 16:24's placeholder held the heading "Doxology" and 16:25's opening
-- bracket: both move to 16:25.
-- Bracketed text an edition prints in its body (BLPD's NT verses, NABRE Mt 12:47)
-- stays text. DRB and VGCL print these verses; NVBSE marks them "(n)" inside the
-- verse before, with no number of their own, so they get no row.
--
-- NABRE_idx is recomputed from the rows (unchanged in effect: 035 already
-- counted these verses by number). Idempotent.

BEGIN;

DO $mig$
DECLARE
    n      INT;
    digest TEXT;
    nt     CONSTANT TEXT := '(47,17,21),(47,18,11),(47,23,14),(48,7,16),(48,9,44),(48,9,46),(48,11,26),(48,15,28),'
                         || '(49,17,36),(49,23,17),(50,5,4),(51,8,37),(51,15,34),(51,24,7),(51,28,29),(52,16,24)';
    placeholder CONSTANT TEXT := '^[][().[:space:]]*$';
    t      TEXT;
    want   INT;
BEGIN
    -- BLPD: the private half must have run.
    SELECT md5(string_agg(concat_ws('|', book, chapter, verse, coalesce(verseequiv, ''), coalesce(verseorigin, ''), text),
                          E'\n' ORDER BY "verseID")) INTO digest FROM "BLPD";
    IF digest <> '3ac846e8a474e7f3513a5140337bc241' OR (SELECT count(*) FROM "BLPD") <> 35739 THEN
        RAISE EXCEPTION 'BLPD does not hold the BLPD-vatican archive''s text (md5 %): apply BLPD-vatican/migrations/045-blpd-omitted-verses.sql first', digest;
    END IF;

    -- NABRE: Job 10:1 first (its remaining row is the one without a label).
    IF EXISTS (SELECT 1 FROM "NABRE" WHERE book = 22 AND chapter = 9 AND verse = 35 AND verseequiv = '10:1a') THEN
        UPDATE "NABRE" SET verse_label = ''
         WHERE book = 22 AND chapter = 10 AND verse = 1 AND md5(text) = '58f52734e0e5f9cf6defb92da431afc9';
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n <> 1 THEN RAISE EXCEPTION 'NABRE Job 10:1 (rest): % rows, expected 1', n; END IF;
        UPDATE "NABRE" SET chapter = 10, verse = 1, verseequiv = NULL, printed_chapter = 9, verse_label = '10:1'
         WHERE book = 22 AND chapter = 9 AND verse = 35 AND verseequiv = '10:1a' AND md5(text) = 'c92a2696f8c8c3717482e39dc3c6ca7f';
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n <> 1 THEN RAISE EXCEPTION 'NABRE Job 10:1 (first part): % rows, expected 1', n; END IF;
    END IF;
    -- Moved already, or never there: either way the rows must now be where 045 puts them.
    IF (SELECT count(*) FROM "NABRE" WHERE book = 22 AND chapter = 10 AND verse = 1 AND printed_chapter = 9
          AND verse_label = '10:1' AND verseequiv IS NULL AND md5(text) = 'c92a2696f8c8c3717482e39dc3c6ca7f') <> 1
       OR (SELECT count(*) FROM "NABRE" WHERE book = 22 AND chapter = 10 AND verse = 1 AND printed_chapter IS NULL
          AND verse_label = '' AND md5(text) = '58f52734e0e5f9cf6defb92da431afc9') <> 1 THEN
        RAISE EXCEPTION 'NABRE Job 10:1: the two rows are not stored as expected (10:1 printed in 9 as "10:1", 10:1 unnumbered)';
    END IF;
    IF EXISTS (SELECT 1 FROM "NABRE" WHERE book = 27 AND chapter = 11 AND verse = 26 AND verseequiv = '12:1') THEN
        UPDATE "NABRE" SET chapter = 12, verse = 1, verseequiv = NULL, printed_chapter = 11, verse_label = '12:1'
         WHERE book = 27 AND chapter = 11 AND verse = 26 AND verseequiv = '12:1' AND md5(text) = '84cc13c50de0013aaaa974a3c21ffb72';
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n <> 1 THEN RAISE EXCEPTION 'NABRE Wis 12:1: % rows, expected 1', n; END IF;
    END IF;
    IF (SELECT count(*) FROM "NABRE" WHERE book = 27 AND chapter = 12 AND verse = 1 AND printed_chapter = 11
          AND verse_label = '12:1' AND verseequiv IS NULL AND md5(text) = '84cc13c50de0013aaaa974a3c21ffb72') <> 1 THEN
        RAISE EXCEPTION 'NABRE Wis 12:1: not stored as expected (12:1 printed in 11 as "12:1")';
    END IF;
    UPDATE "NABRE" SET printed_chapter = 8, verse_label = '7:53'
     WHERE book = 50 AND chapter = 7 AND verse = 53 AND md5(text) = 'b2ee6e036acc990fd149352e0edec2fa';
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n <> 1 THEN RAISE EXCEPTION 'NABRE John 7:53: % rows, expected 1', n; END IF;

    -- NABRE Rom 16:24 held "]\n [" with the heading "Doxology" between: NABRE prints
    -- "[24]" (omitted), the heading, then "[25 ... 27 Amen.]". The heading and the
    -- opening bracket belong to 16:25 (16:27 holds the closing one).
    IF NOT EXISTS (SELECT 1 FROM "NABRE" WHERE book = 52 AND chapter = 16 AND verse = 25 AND title1 = 'Doxology') THEN
        UPDATE "NABRE" SET text = '[' || text, title1 = 'Doxology', title_position = NULL,
                           embedding = NULL, embedding_labse = NULL, embedded_text_hash = NULL
         WHERE book = 52 AND chapter = 16 AND verse = 25 AND md5(text) = '43831b25f040e46756d93285d538738b'
           AND coalesce(title1, '') = '';
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n <> 1 THEN RAISE EXCEPTION 'NABRE Rom 16:25: % rows, expected 1', n; END IF;
        UPDATE "NABRE" SET title1 = '', title_position = NULL
         WHERE book = 52 AND chapter = 16 AND verse = 24 AND md5(text) = '371153b633a031b525f6b6b54cff71b6';
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n <> 1 THEN RAISE EXCEPTION 'NABRE Rom 16:24: % rows, expected 1', n; END IF;
    END IF;
    IF (SELECT count(*) FROM "NABRE" WHERE book = 52 AND chapter = 16 AND verse = 25 AND title1 = 'Doxology'
          AND title_position IS NULL AND left(text, 1) = '[') <> 1
       OR EXISTS (SELECT 1 FROM "NABRE" WHERE book = 52 AND chapter = 16 AND verse = 24 AND coalesce(title1, '') <> '') THEN
        RAISE EXCEPTION 'NABRE Rom 16:24-25: the Doxology heading and bracket are not on 16:25';
    END IF;

    -- Omitted verses: by key, and only where the text is a placeholder (or already done).
    -- CEI2008 Act 8:37 held bracketed text the edition does not print (see above).
    UPDATE "CEI2008" SET text = '', omitted = true, embedding = NULL, embedding_labse = NULL, embedded_text_hash = NULL
     WHERE book = 51 AND chapter = 8 AND verse = 37 AND NOT omitted AND md5(text) = 'ea1b49524b917c2ab26cad04cc222f4a';
    FOREACH t IN ARRAY ARRAY['CEI2008', 'NABRE', 'LUZZI'] LOOP
        EXECUTE format('UPDATE %I SET text = %L, omitted = true, embedding = NULL, embedding_labse = NULL, embedded_text_hash = NULL '
                       'WHERE (book, chapter, verse) IN (%s) AND NOT omitted AND text_plain ~ %L',
                       t, '', nt, placeholder);
        EXECUTE format('SELECT count(*) FROM %I WHERE (book, chapter, verse) IN (%s) AND omitted', t, nt) INTO n;
        want := 16;
        IF n <> want THEN RAISE EXCEPTION '%: % omitted NT rows, expected %', t, n, want; END IF;
    END LOOP;
    UPDATE "NABRE" SET text = '', omitted = true, embedding = NULL, embedding_labse = NULL, embedded_text_hash = NULL
     WHERE book = 20 AND chapter = 9 AND verse = 34 AND NOT omitted AND text_plain ~ placeholder;
    IF (SELECT count(*) FROM "CEI2008" WHERE omitted) <> 16 OR (SELECT count(*) FROM "NABRE" WHERE omitted) <> 17
       OR (SELECT count(*) FROM "LUZZI" WHERE omitted) <> 16 OR (SELECT count(*) FROM "BLPD" WHERE omitted) <> 2 THEN
        RAISE EXCEPTION 'omitted rows: CEI2008 %, NABRE %, LUZZI %, BLPD % (expected 16, 17, 16, 2)',
            (SELECT count(*) FROM "CEI2008" WHERE omitted), (SELECT count(*) FROM "NABRE" WHERE omitted),
            (SELECT count(*) FROM "LUZZI" WHERE omitted), (SELECT count(*) FROM "BLPD" WHERE omitted);
    END IF;

    -- NABRE_idx from the rows, by number (as 035), outside Esther.
    UPDATE "NABRE_idx" i SET verses_count = x.cnt, verses_last = x.last
    FROM (
        SELECT book, string_agg(d::text, ',' ORDER BY chapter) AS cnt, string_agg(m::text, ',' ORDER BY chapter) AS last
        FROM (SELECT book, chapter, count(DISTINCT verse) AS d, max(verse) AS m FROM "NABRE" WHERE book <> 19 GROUP BY book, chapter) c
        GROUP BY book
    ) x
    WHERE i.book = x.book AND (i.verses_count, i.verses_last) IS DISTINCT FROM (x.cnt, x.last);
    GET DIAGNOSTICS n = ROW_COUNT;
    RAISE NOTICE 'NABRE_idx: % books changed (expected 0)', n;
END
$mig$;

COMMIT;
