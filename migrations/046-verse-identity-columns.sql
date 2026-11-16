-- 046: verse-identity columns named for what they hold (#184).
--
-- verseequiv, versedescr and verseorigin each held two or three kinds of value
-- (survey of staging, 2026-11-16). Every edition table in versions_available
-- that still has verseequiv is converted; each value moves by its shape:
--
--   verseequiv '4a', '17aa'            -> verse_segment 'a', 'aa' (the printed letter only)
--   verseequiv '(9,22)', '(113,20>)'   -> alt_ref (CEI2008 Psalms: the Greek/Vulgate number)
--   verseequiv '33'                    -> alt_ref (Dan 3:91-100: the Hebrew number; NABRE Sir 41:15)
--   versedescr '11:2'                  -> alt_ref (DRB, VGCL Greek Esther: the Vulgate position)
--   versedescr 'A:1' (NABRE)           -> verse_label (NABRE prints Esther's additions as A:1 ... F:...)
--   versedescr 'Alef', '[Alef] '       -> acrostic (CEI2008, BLPD)
--   versedescr NABRE Wis 12:1 '12:1', Job 10:1 '10:1a', CEI2008 Mk 7:16 '[16]', BLPD Ps 119:32 '[He] ',
--   CEI2008 Ps 10:9 and 37:29 'Ain'    -> dropped (duplicates of verse_label; a leftover on an omitted
--                                         verse; a letter one verse before its stanza and heading, 119:33;
--                                         copies of the letter printed mid-verse in 10:8 and 37:28)
--   ''                                 -> NULL (an import leftover, not "no number printed")
--   a heading that is only a Hebrew letter name, in the edition's spelling
--                                      -> acrostic as well (LUZZI, NABRE, VGCL; CEI2008 and BLPD,
--                                         where it must agree with versedescr's letter unless the
--                                         heading falls mid-verse: the letter beginning there wins)
--   verseorigin                        -> text_tradition (added, NULL, where missing: LUZZI, DIVCOM)
--   "verseID"                          -> sort_order (reading order; still the primary key)
--
-- Any other value raises, naming table, column and value. Headings stay as
-- they are: the letter is data (acrostic), the heading is how it is printed.
-- '' keeps a meaning only in verse_label ('' = printed without a number).
--
-- Unique key: (book, chapter, verse, verse_segment, text_tradition, printed_chapter)
-- NULLS NOT DISTINCT, as a unique index (bibleget_rebuild_in_order recreates
-- indexes, not constraints). printed_chapter tells apart NABRE's two Job 10:1
-- rows (one printed at the end of chapter 9). It replaces the <T>_bcv index.
--
-- Nothing printed changes: a digest of (order, book, chapter, verse, text,
-- headings, title_position) per table is compared before and after.
--
-- Also: biblebooks_codes (USFM / OSIS book codes; Greek-tradition Esther rows
-- are USFM ESG / OSIS AddEsth, and USFM splits Baruch 6 as LJE: both left to
-- the output transformers), and bibleget_rebuild_in_order on sort_order.
-- Idempotent: a converted table is skipped.

BEGIN;

CREATE OR REPLACE FUNCTION pg_temp.m046_has(tbl TEXT, col TEXT) RETURNS BOOLEAN
LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = tbl AND column_name = col)
$$;

-- The digest of what is printed, over the columns the table has.
CREATE OR REPLACE FUNCTION pg_temp.m046_digest(tbl TEXT, ord_col TEXT) RETURNS TEXT
LANGUAGE plpgsql AS $$
DECLARE
    parts TEXT[] := ARRAY[format('%I', ord_col), 'book', 'chapter', 'verse', 'text'];
    c     TEXT;
    d     TEXT;
BEGIN
    FOREACH c IN ARRAY ARRAY['title1', 'title2', 'title3', 'title_position'] LOOP
        IF pg_temp.m046_has(tbl, c) THEN
            parts := parts || format('coalesce(%I::text, %L)', c, '');
        END IF;
    END LOOP;
    EXECUTE format('SELECT md5(coalesce(string_agg(concat_ws(%L, %s), E''\n'' ORDER BY %I), %L)) FROM %I',
                   '|', array_to_string(parts, ', '), ord_col, '', tbl) INTO d;
    RETURN d;
END
$$;

-- Hebrew letter names as each edition spells them (staging, 2026-11-16).
CREATE OR REPLACE FUNCTION pg_temp.m046_letters(tbl TEXT) RETURNS TEXT[]
LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE tbl
        WHEN 'LUZZI' THEN string_to_array('ALEF BET GHIMEL DALET HE VAU ZAIN CHET TETH IOD CAF LAMED MEM NUN SAMEC AIN PE TSADE KOF RESCH SCHIN TAU', ' ')
        WHEN 'NABRE' THEN string_to_array('Aleph Beth Gimel Daleth He Waw Zayin Heth Teth Yodh Kaph Lamedh Mem Nun Samekh Ayin Pe Sadhe Qoph Resh Shin Taw', ' ')
        WHEN 'VGCL' THEN string_to_array('Aleph Beth Ghimel Daleth He Vau Zain Heth Teth Jod Caph Lamed Mem Nun Samech Ain Phe Sade Coph Res Sin Tau Thau', ' ')
        WHEN 'CEI2008' THEN string_to_array('Alef Bet Ghimel Dalet He Vau Zain Het Tet Iod Caf Lamed Mem Nun Samec Ain Pe Sade Kof Res Sin Tau', ' ')
        WHEN 'BLPD' THEN string_to_array('Alef Bet Guímel Dálet He Vau Zain Jet Tet Iod Caf Lámed Mem Nun Sámec Ain Pe Sade Qof Res Sin Tau', ' ')
    END
$$;

-- Acrostic letters per chapter, checked when the chapter has rows: book, chapter, count
-- (Ps 9 and 10 share one alphabet, Alef-Caf and Lamed-Tau, with gaps in the Hebrew).
CREATE OR REPLACE FUNCTION pg_temp.m046_acrostic_counts(tbl TEXT) RETURNS INT[][]
LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE tbl
        WHEN 'LUZZI' THEN ARRAY[[23, 119, 22]]
        WHEN 'NABRE' THEN ARRAY[[23, 37, 22], [23, 119, 22]]
        WHEN 'VGCL'  THEN ARRAY[[23, 118, 22], [31, 1, 21], [31, 2, 22], [31, 3, 66], [31, 4, 22]]
        WHEN 'BLPD'  THEN ARRAY[[23, 119, 22]]
        WHEN 'CEI2008' THEN ARRAY[[23, 9, 10], [23, 10, 7], [23, 37, 22], [23, 119, 22], [24, 31, 22],
                                  [31, 1, 22], [31, 2, 22], [31, 3, 66], [31, 4, 22]]
    END
$$;

CREATE OR REPLACE FUNCTION pg_temp.m046_table(tbl TEXT) RETURNS TEXT
LANGUAGE plpgsql AS $fn$
DECLARE
    has_descr  BOOLEAN := pg_temp.m046_has(tbl, 'versedescr');
    letters    TEXT[]  := pg_temp.m046_letters(tbl);
    counts     INT[][] := pg_temp.m046_acrostic_counts(tbl);
    digest0    TEXT;
    digest1    TEXT;
    n          BIGINT;
    n_src      BIGINT;
    n_moved    BIGINT := 0;
    bad        TEXT;
    con        TEXT;
    c          TEXT;
    i          INT;
    -- versedescr values 046 drops: duplicates of verse_label, a leftover on an omitted
    -- verse, BLPD's "He" one verse early (the stanza and its heading begin at 119:33),
    -- and CEI2008's "Ain" on Ps 10:9 and 37:29, a copy of the letter printed mid-verse
    -- in 10:8 and 37:28 (where the heading is).
    dropped    CONSTANT TEXT := CASE tbl
        WHEN 'NABRE' THEN '(27,12,1,''12:1''),(22,10,1,''10:1a'')'
        WHEN 'CEI2008' THEN '(48,7,16,''[16]''),(23,10,9,''Ain''),(23,37,29,''Ain'')'
        WHEN 'BLPD' THEN '(23,119,32,''[He] '')'
        ELSE NULL END;
    seg_re     CONSTANT TEXT := '^[0-9]+[a-z]+$';
    alt_re     CONSTANT TEXT := '^(\([0-9]+,[0-9]+>?\)|[0-9]+)$';
    pos_re     CONSTANT TEXT := '^[0-9]+:[0-9]+$';
    nabre_re   CONSTANT TEXT := '^[A-F]:[0-9]+$';
    letter_re  CONSTANT TEXT := '^\[?[[:alpha:]]+\]? *$';
BEGIN
    IF NOT pg_temp.m046_has(tbl, 'verseequiv') THEN
        RETURN tbl || ': already converted';
    END IF;
    IF NOT has_descr THEN
        EXECUTE format('ALTER TABLE %I ADD COLUMN versedescr VARCHAR(10)', tbl);
    END IF;
    digest0 := pg_temp.m046_digest(tbl, 'verseID');

    EXECUTE format('ALTER TABLE %I ADD COLUMN verse_segment VARCHAR(4), ADD COLUMN alt_ref VARCHAR(16), '
                   'ADD COLUMN acrostic VARCHAR(16)', tbl);
    -- '' is no value (an import leftover). Rather than rewrite every such row
    -- (CEI2008: ~35,000, each touching the HNSW indexes) the rules skip it:
    -- the two columns are dropped below. The old index goes first, so the
    -- moves below don't maintain it.
    EXECUTE format('DROP INDEX IF EXISTS %I', tbl || '_bcv');

    -- Every value must be claimed by exactly one rule.
    EXECUTE format('SELECT min(verseequiv) FROM %I WHERE verseequiv <> %L AND verseequiv !~ %L AND verseequiv !~ %L',
                   tbl, '', seg_re, alt_re) INTO bad;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION '%: verseequiv value % claimed by no rule', tbl, bad;
    END IF;
    EXECUTE format('SELECT min(versedescr) FROM %I WHERE versedescr <> %L AND NOT ('
                   '(%L <> %L AND versedescr ~ %L) OR (%L = %L AND versedescr ~ %L) OR versedescr ~ %L%s)',
                   tbl, '', tbl, 'NABRE', pos_re, tbl, 'NABRE', nabre_re, letter_re,
                   CASE WHEN dropped IS NULL THEN ''
                        ELSE format(' OR (book, chapter, verse, versedescr) IN (%s)', dropped) END) INTO bad;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION '%: versedescr value % claimed by no rule', tbl, bad;
    END IF;

    -- verseequiv
    EXECUTE format('SELECT count(*) FROM %I WHERE verseequiv <> %L', tbl, '') INTO n_src;
    EXECUTE format('UPDATE %I SET verse_segment = regexp_replace(verseequiv, %L, %L) WHERE verseequiv ~ %L',
                   tbl, '^[0-9]+', '', seg_re);
    GET DIAGNOSTICS n = ROW_COUNT; n_moved := n;
    EXECUTE format('UPDATE %I SET alt_ref = verseequiv WHERE verseequiv ~ %L', tbl, alt_re);
    GET DIAGNOSTICS n = ROW_COUNT; n_moved := n_moved + n;
    IF n_moved <> n_src THEN
        RAISE EXCEPTION '%: verseequiv: % values, % moved', tbl, n_src, n_moved;
    END IF;

    -- versedescr
    EXECUTE format('SELECT count(*) FROM %I WHERE versedescr <> %L', tbl, '') INTO n_src;
    n_moved := 0;
    IF dropped IS NOT NULL THEN
        EXECUTE format('UPDATE %I SET versedescr = NULL WHERE (book, chapter, verse, versedescr) IN (%s)', tbl, dropped);
        GET DIAGNOSTICS n = ROW_COUNT; n_moved := n;
    END IF;
    IF tbl = 'NABRE' THEN
        EXECUTE format('SELECT count(*) FROM %I WHERE versedescr ~ %L AND verse_label IS NOT NULL', tbl, nabre_re) INTO n;
        IF n > 0 THEN
            RAISE EXCEPTION '%: % Esther rows already have a verse_label', tbl, n;
        END IF;
        EXECUTE format('UPDATE %I SET verse_label = versedescr WHERE versedescr ~ %L', tbl, nabre_re);
        GET DIAGNOSTICS n = ROW_COUNT; n_moved := n_moved + n;
    ELSE
        EXECUTE format('SELECT count(*) FROM %I WHERE versedescr ~ %L AND alt_ref IS NOT NULL', tbl, pos_re) INTO n;
        IF n > 0 THEN
            RAISE EXCEPTION '%: % rows have an alternate position in both verseequiv and versedescr', tbl, n;
        END IF;
        EXECUTE format('UPDATE %I SET alt_ref = versedescr WHERE versedescr ~ %L', tbl, pos_re);
        GET DIAGNOSTICS n = ROW_COUNT; n_moved := n_moved + n;
    END IF;
    EXECUTE format('UPDATE %I SET acrostic = btrim(versedescr, %L) WHERE versedescr ~ %L', tbl, '[] ', letter_re);
    GET DIAGNOSTICS n = ROW_COUNT; n_moved := n_moved + n;
    IF n_moved <> n_src THEN
        RAISE EXCEPTION '%: versedescr: % values, % moved', tbl, n_src, n_moved;
    END IF;
    IF letters IS NOT NULL THEN
        EXECUTE format('SELECT min(acrostic) FROM %I WHERE acrostic IS NOT NULL AND NOT acrostic = ANY(%L)', tbl, letters) INTO bad;
        IF bad IS NOT NULL THEN
            RAISE EXCEPTION '%: acrostic letter % is not in the edition''s list', tbl, bad;
        END IF;
    END IF;

    -- Acrostic letters printed as headings.
    IF letters IS NOT NULL AND pg_temp.m046_has(tbl, 'title1') THEN
        FOREACH c IN ARRAY ARRAY['title1', 'title2', 'title3'] LOOP
            -- A heading letter that differs from versedescr's, at the start of the verse: a conflict.
            EXECUTE format('SELECT min(book || '' '' || chapter || '':'' || verse) FROM %I '
                           'WHERE btrim(%I) = ANY(%L) AND acrostic IS NOT NULL AND acrostic <> btrim(%I) AND title_position IS NULL',
                           tbl, c, letters, c) INTO bad;
            IF bad IS NOT NULL THEN
                RAISE EXCEPTION '%: % heading letter and versedescr letter differ at %', tbl, c, bad;
            END IF;
            EXECUTE format('UPDATE %I SET acrostic = btrim(%I) WHERE btrim(%I) = ANY(%L) AND acrostic IS DISTINCT FROM btrim(%I)',
                           tbl, c, c, letters, c);
        END LOOP;
    END IF;
    IF counts IS NOT NULL THEN
        FOR i IN 1 .. array_length(counts, 1) LOOP
            EXECUTE format('SELECT count(*) FROM %I WHERE book = %s AND chapter = %s', tbl, counts[i][1], counts[i][2]) INTO n;
            CONTINUE WHEN n = 0;
            EXECUTE format('SELECT count(*) FROM %I WHERE book = %s AND chapter = %s AND acrostic IS NOT NULL',
                           tbl, counts[i][1], counts[i][2]) INTO n;
            IF n <> counts[i][3] THEN
                RAISE EXCEPTION '%: % %: % acrostic letters, expected %', tbl, counts[i][1], counts[i][2], n, counts[i][3];
            END IF;
        END LOOP;
    END IF;

    -- Schema: drop the old columns, rename, constrain, key.
    EXECUTE format('ALTER TABLE %I DROP COLUMN verseequiv, DROP COLUMN versedescr', tbl);
    IF pg_temp.m046_has(tbl, 'verseorigin') THEN
        FOR con IN SELECT conname FROM pg_constraint
                   WHERE conrelid = format('public.%I', tbl)::regclass AND contype = 'c'
                     AND pg_get_constraintdef(oid) ~ 'verseorigin' LOOP
            EXECUTE format('ALTER TABLE %I DROP CONSTRAINT %I', tbl, con);
        END LOOP;
        EXECUTE format('ALTER TABLE %I RENAME COLUMN verseorigin TO text_tradition', tbl);
    ELSE
        EXECUTE format('ALTER TABLE %I ADD COLUMN text_tradition VARCHAR(10)', tbl);
    END IF;
    EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO sort_order', tbl, 'verseID');
    EXECUTE format('ALTER TABLE %I '
                   'ADD CONSTRAINT %I CHECK (text_tradition IN (%L, %L)), '
                   'ADD CONSTRAINT %I CHECK (verse_segment <> %L), '
                   'ADD CONSTRAINT %I CHECK (alt_ref <> %L), '
                   'ADD CONSTRAINT %I CHECK (acrostic <> %L)',
                   tbl, tbl || '_text_tradition_check', 'GREEK', 'HEBREW',
                   tbl || '_verse_segment_check', '', tbl || '_alt_ref_check', '', tbl || '_acrostic_check', '');
    EXECUTE format('CREATE UNIQUE INDEX %I ON %I (book, chapter, verse, verse_segment, text_tradition, printed_chapter) NULLS NOT DISTINCT',
                   tbl || '_identity', tbl);

    digest1 := pg_temp.m046_digest(tbl, 'sort_order');
    IF digest1 <> digest0 THEN
        RAISE EXCEPTION '%: printed content changed (digest % -> %)', tbl, digest0, digest1;
    END IF;
    EXECUTE format('SELECT count(*) FROM %I WHERE verse_segment IS NOT NULL', tbl) INTO n;
    bad := format('%s: verse_segment %s', tbl, n);
    EXECUTE format('SELECT count(*) FROM %I WHERE alt_ref IS NOT NULL', tbl) INTO n;
    bad := bad || format(', alt_ref %s', n);
    EXECUTE format('SELECT count(*) FROM %I WHERE acrostic IS NOT NULL', tbl) INTO n;
    bad := bad || format(', acrostic %s', n);
    RETURN bad;
END
$fn$;

DO $mig$
DECLARE
    rec RECORD;
BEGIN
    FOR rec IN SELECT sigla FROM versions_available ORDER BY sigla LOOP
        CONTINUE WHEN to_regclass(format('public.%I', rec.sigla)) IS NULL;
        RAISE NOTICE '%', pg_temp.m046_table(rec.sigla);
    END LOOP;
END
$mig$;

-- USFM 3 and OSIS 2.1.1 book codes, by BibleGet's book number.
CREATE TABLE IF NOT EXISTS biblebooks_codes (
    book      INTEGER PRIMARY KEY REFERENCES biblebooks_fullname ("BOOK"),
    usfm_code CHAR(3) NOT NULL UNIQUE,
    osis_code VARCHAR(8) NOT NULL UNIQUE
);
COMMENT ON TABLE biblebooks_codes IS
    'USFM and OSIS book codes (migration 046). Greek-tradition Esther rows are USFM ESG / OSIS AddEsth; '
    'USFM keeps Baruch 6 apart as LJE.';
INSERT INTO biblebooks_codes (book, usfm_code, osis_code)
SELECT n, u, o
FROM unnest(
    string_to_array('GEN EXO LEV NUM DEU JOS JDG RUT 1SA 2SA 1KI 2KI 1CH 2CH EZR NEH TOB JDT EST 1MA 2MA JOB PSA PRO ECC SNG WIS SIR ISA JER LAM BAR EZK DAN HOS JOL AMO OBA JON MIC NAM HAB ZEP HAG ZEC MAL MAT MRK LUK JHN ACT ROM 1CO 2CO GAL EPH PHP COL 1TH 2TH 1TI 2TI TIT PHM HEB JAS 1PE 2PE 1JN 2JN 3JN JUD REV', ' '),
    string_to_array('Gen Exod Lev Num Deut Josh Judg Ruth 1Sam 2Sam 1Kgs 2Kgs 1Chr 2Chr Ezra Neh Tob Jdt Esth 1Macc 2Macc Job Ps Prov Eccl Song Wis Sir Isa Jer Lam Bar Ezek Dan Hos Joel Amos Obad Jonah Mic Nah Hab Zeph Hag Zech Mal Matt Mark Luke John Acts Rom 1Cor 2Cor Gal Eph Phil Col 1Thess 2Thess 1Tim 2Tim Titus Phlm Heb Jas 1Pet 2Pet 1John 2John 3John Jude Rev', ' ')
) WITH ORDINALITY AS c(u, o, n)
ON CONFLICT (book) DO NOTHING;
DO $$
BEGIN
    IF (SELECT count(*) FROM biblebooks_codes) <> 73 THEN
        RAISE EXCEPTION 'biblebooks_codes: % rows, expected 73', (SELECT count(*) FROM biblebooks_codes);
    END IF;
END
$$;

-- bibleget_rebuild_in_order (024), on sort_order.
CREATE OR REPLACE FUNCTION bibleget_rebuild_in_order(tbl TEXT, ord_table TEXT) RETURNS INT
LANGUAGE plpgsql AS $fn$
DECLARE
    new_tbl  TEXT := tbl || '_reordered';
    cols     TEXT;
    n_old    BIGINT;
    n_new    BIGINT;
    n_ord    BIGINT;
    seq      TEXT;
    pk_name  TEXT;
    rec      RECORD;
    renames  TEXT[] := '{}';
BEGIN
    -- SHARE: reads continue, but no write can commit between the copy and
    -- the swap (it would be lost with the old table). Held to the end of
    -- the transaction; writers waiting on it must be rerun afterwards.
    EXECUTE format('LOCK TABLE %I IN SHARE MODE', tbl);
    EXECUTE format('SELECT count(*) FROM %I', tbl) INTO n_old;
    EXECUTE format('SELECT count(*) FROM %I t JOIN %I o ON o.vid = t.sort_order', tbl, ord_table) INTO n_ord;
    IF n_ord <> n_old THEN
        RAISE EXCEPTION 'bibleget_rebuild_in_order(%): % of % rows have an order', tbl, n_ord, n_old;
    END IF;

    SELECT string_agg(format('%I', attname), ', ' ORDER BY attnum) INTO cols
    FROM pg_attribute
    WHERE attrelid = format('public.%I', tbl)::regclass AND attnum > 0 AND NOT attisdropped AND attname <> 'sort_order';

    EXECUTE format('DROP TABLE IF EXISTS %I', new_tbl);
    EXECUTE format('CREATE TABLE %I (LIKE %I INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING STORAGE INCLUDING COMMENTS)',
                   new_tbl, tbl);
    EXECUTE format(
        'INSERT INTO %I (sort_order, %s) '
        || 'SELECT row_number() OVER (ORDER BY o.ord, t.sort_order), %s '
        || 'FROM %I t JOIN %I o ON o.vid = t.sort_order ORDER BY o.ord, t.sort_order',
        new_tbl, cols, (SELECT string_agg('t.' || c, ', ') FROM unnest(string_to_array(cols, ', ')) c), tbl, ord_table);
    EXECUTE format('SELECT count(*) FROM %I', new_tbl) INTO n_new;
    IF n_new <> n_old THEN
        RAISE EXCEPTION 'bibleget_rebuild_in_order(%): copied % of % rows', tbl, n_new, n_old;
    END IF;

    -- Primary key and indexes, built after the load (faster, and compact).
    SELECT conname INTO pk_name FROM pg_constraint
    WHERE conrelid = format('public.%I', tbl)::regclass AND contype = 'p';
    EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I PRIMARY KEY (sort_order)', new_tbl, pk_name || '_r');
    renames := renames || ARRAY[pk_name];
    FOR rec IN
        SELECT i.indexname, i.indexdef FROM pg_indexes i
        WHERE i.schemaname = 'public' AND i.tablename = tbl AND i.indexname <> pk_name
    LOOP
        EXECUTE replace(
            replace(rec.indexdef, format('INDEX %I ON', rec.indexname), format('INDEX %I ON', rec.indexname || '_r')),
            format('ON public.%I ', tbl), format('ON public.%I ', new_tbl));
        renames := renames || ARRAY[rec.indexname];
    END LOOP;

    -- Triggers (e.g. <T>_text_hash_trigger), created after the load so the
    -- copied text_plain/text_hash are kept as they are.
    FOR rec IN SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger
               WHERE tgrelid = format('public.%I', tbl)::regclass AND NOT tgisinternal
    LOOP
        EXECUTE replace(rec.def, format('ON public.%I ', tbl), format('ON public.%I ', new_tbl));
    END LOOP;

    -- The sort_order sequence belongs to the old column: hand it over first,
    -- or DROP TABLE would drop it.
    seq := pg_get_serial_sequence(format('public.%I', tbl), 'sort_order');
    IF seq IS NOT NULL THEN
        EXECUTE format('ALTER SEQUENCE %s OWNED BY %I.sort_order', seq, new_tbl);
        -- ALTER SEQUENCE is transactional (setval is not): a rollback
        -- restores the sequence along with the old table.
        EXECUTE format('ALTER SEQUENCE %s RESTART WITH %s', seq, n_new + 1);
    END IF;

    EXECUTE format('DROP TABLE %I', tbl);
    EXECUTE format('ALTER TABLE %I RENAME TO %I', new_tbl, tbl);
    EXECUTE format('ALTER TABLE %I RENAME CONSTRAINT %I TO %I', tbl, pk_name || '_r', pk_name);
    FOR i IN 2 .. coalesce(array_length(renames, 1), 1) LOOP
        EXECUTE format('ALTER INDEX %I RENAME TO %I', renames[i] || '_r', renames[i]);
    END LOOP;
    EXECUTE format('ANALYZE %I', tbl);
    RETURN n_new;
END
$fn$;

COMMENT ON FUNCTION bibleget_rebuild_in_order(TEXT, TEXT) IS
    'Rebuild an edition table with sort_order renumbered 1..n in the order of ord_table(vid, ord), '
    'physically in that order; keeps all columns, indexes, triggers and the sequence (migration 024; sort_order since 046).';

COMMIT;
