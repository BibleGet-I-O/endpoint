-- 065: Sirach's prologue as chapter 0, verse 1 (#264).
--
-- No edition served the grandson's foreword: NABRE, CEI2008, NVBSE, BLPD and DRB
-- had no row for it, and VGCL had it glued onto 1:1 (one 1,708-character row
-- headed "Prologus"). Every source prints it as one block (CEI 2008 numbers it
-- 1, NABRE gives it one verse id, NVBSE and VGCL leave it unnumbered), so it is
-- one row, chapter 0, verse 1, with the edition's heading in title1. It fits
-- text/text_plain VARCHAR(2000) (migration 021) in every edition measured.
--
-- This migration
--   * adds prologue_verses (INT, default 0) to every <T>_idx table: the number of
--     verses of the book's prologue in that edition (0 = none). The API accepts
--     chapter 0 only where it is not 0 (data-driven, not a Sirach exception);
--   * defines bibleget_insert_prologue(tbl, book, text, title): the prologue as
--     <book> 0:1, just before the book's first row (the following sort orders
--     shift by one; the trigger derives text_plain and text_hash; embeddings are
--     computed afterwards), and prologue_verses = 1; run again, it replaces the
--     text and title;
--   * defines bibleget_split_prologue(tbl, book, title): a prologue glued onto 1:1
--     (title1 = title, the verse's own text from its first poetic line on) is
--     moved to 0:1 and 1:1 keeps verse 1; with nothing to split it does nothing;
--   * splits VGCL's (public domain).
-- The copyrighted editions' prologues come from private data halves that call
-- bibleget_insert_prologue (NABRE-source, CEI2008-bibbiaedu, NVBSE-vatican).
-- Afterwards: compute the new rows' embeddings, and refresh_search_vocabulary()
-- for each edition that got a prologue. The shifted sort orders mean: don't run
-- it while compute_embeddings.py runs.
-- Idempotent.
--
-- Run by Doctrine (src/Migrations/Version065SirachPrologue.php): no BEGIN/COMMIT.

CREATE OR REPLACE FUNCTION bibleget_insert_prologue(tbl TEXT, p_book INT, p_text TEXT, p_title TEXT) RETURNS VOID
LANGUAGE plpgsql AS $fn$
DECLARE
    existing INT;
    first_so INT;
    seq      TEXT;
BEGIN
    EXECUTE format('SELECT count(*) FROM %I WHERE book = $1 AND chapter = 0 AND verse = 1', tbl) INTO existing USING p_book;
    IF existing > 0 THEN
        EXECUTE format('UPDATE %I SET text = $2, title1 = $3 WHERE book = $1 AND chapter = 0 AND verse = 1', tbl)
            USING p_book, p_text, p_title;
    ELSE
        EXECUTE format('SELECT min(sort_order) FROM %I WHERE book = $1', tbl) INTO first_so USING p_book;
        IF first_so IS NULL THEN
            RAISE EXCEPTION 'bibleget_insert_prologue(%): no book %', tbl, p_book;
        END IF;
        -- Make room: every row from the book's first on moves one place on (through negative values, as sort_order is unique).
        EXECUTE format('UPDATE %I SET sort_order = -(sort_order + 1) WHERE sort_order >= $1', tbl) USING first_so;
        EXECUTE format('UPDATE %I SET sort_order = -sort_order WHERE sort_order < 0', tbl);
        EXECUTE format('INSERT INTO %I (sort_order, book, chapter, verse, text, title1, testament, section) '
                       'SELECT $1, $2, 0, 1, $3, $4, testament, section FROM %I WHERE sort_order = $1 + 1', tbl, tbl)
            USING first_so, p_book, p_text, p_title;
        seq := pg_get_serial_sequence(quote_ident(tbl), 'sort_order');
        IF seq IS NOT NULL THEN
            EXECUTE format('SELECT setval(%L, (SELECT max(sort_order) FROM %I))', seq, tbl);
        END IF;
    END IF;
    EXECUTE format('UPDATE %I SET prologue_verses = 1 WHERE book = $1', tbl || '_idx') USING p_book;
END
$fn$;

CREATE OR REPLACE FUNCTION bibleget_split_prologue(tbl TEXT, p_book INT, p_title TEXT) RETURNS VOID
LANGUAGE plpgsql AS $fn$
DECLARE
    so    INT;
    src   TEXT;
    title TEXT;
    cut   INT;
BEGIN
    EXECUTE format('SELECT sort_order, text, title1 FROM %I WHERE book = $1 AND chapter = 1 AND verse = 1 ORDER BY sort_order LIMIT 1', tbl)
        INTO so, src, title USING p_book;
    cut := position(E'\n<po' IN coalesce(src, ''));
    IF so IS NULL OR title IS DISTINCT FROM p_title OR cut = 0 THEN
        RETURN;
    END IF;
    EXECUTE format('UPDATE %I SET text = $2, title1 = NULL WHERE sort_order = $1', tbl) USING so, substr(src, cut + 1);
    PERFORM bibleget_insert_prologue(tbl, p_book, left(src, cut - 1), p_title);
END
$fn$;

DO $mig$
DECLARE
    rec RECORD;
BEGIN
    FOR rec IN SELECT sigla FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', rec.sigla || '_idx')) IS NULL;
        EXECUTE format('ALTER TABLE %I ADD COLUMN IF NOT EXISTS prologue_verses INT NOT NULL DEFAULT 0', rec.sigla || '_idx');
    END LOOP;
    -- (The test database's trimmed VGCL has no titles and no Sirach: nothing to split there.)
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'VGCL' AND column_name = 'title1') THEN
        PERFORM bibleget_split_prologue('VGCL', 28, 'Prologus');
    END IF;
END
$mig$;
