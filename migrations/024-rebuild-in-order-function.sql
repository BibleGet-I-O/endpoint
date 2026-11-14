-- Migration: bibleget_rebuild_in_order(), rebuild an edition table in reading order
--
-- Rows that were appended or inserted after an edition's import (e.g. CEI2008's
-- Ps 119:82-176 and Esther, stored after Revelation) are out of order in
-- "verseID", and physically scattered on disk. Renumbering in place would leave
-- the heap fragmented; instead the table is rebuilt:
--
--   1. CREATE TABLE "<T>_reordered" (LIKE "<T>": columns, defaults, NOT NULL
--      and CHECK constraints, storage, comments);
--   2. copy the rows in the order given by `ord` (a table of verseID -> ord),
--      numbering "verseID" 1..n in that order, so the heap is written in
--      reading order and ordered lookups scan contiguous pages;
--   3. recreate the primary key, every index (including HNSW) and every
--      trigger from the old table's definitions; hand the verseID sequence
--      over (OWNED BY) and set it to n;
--   4. drop the old table, rename the new one (and its indexes) back.
--
-- Column values -- text_plain, text_hash, embedded_text_hash, embeddings -- are
-- copied as they are, so embeddings stay fresh. The function refuses to run
-- if `ord` does not cover every row exactly once, and works on any table
-- with an integer "verseID" primary key. Callers run it inside their own
-- transaction (CREATE/DROP TABLE are transactional in PostgreSQL).
--
-- Usage:
--     CREATE TEMP TABLE ord (vid INT PRIMARY KEY, ord NUMERIC NOT NULL) ON COMMIT DROP;
--     INSERT INTO ord ...;
--     SELECT bibleget_rebuild_in_order('CEI2008', 'ord');

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
    EXECUTE format('SELECT count(*) FROM %I', tbl) INTO n_old;
    EXECUTE format('SELECT count(*) FROM %I t JOIN %I o ON o.vid = t."verseID"', tbl, ord_table) INTO n_ord;
    IF n_ord <> n_old THEN
        RAISE EXCEPTION 'bibleget_rebuild_in_order(%): % of % rows have an order', tbl, n_ord, n_old;
    END IF;

    SELECT string_agg(format('%I', attname), ', ' ORDER BY attnum) INTO cols
    FROM pg_attribute
    WHERE attrelid = format('public.%I', tbl)::regclass AND attnum > 0 AND NOT attisdropped AND attname <> 'verseID';

    EXECUTE format('DROP TABLE IF EXISTS %I', new_tbl);
    EXECUTE format('CREATE TABLE %I (LIKE %I INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING STORAGE INCLUDING COMMENTS)',
                   new_tbl, tbl);
    EXECUTE format(
        'INSERT INTO %I ("verseID", %s) '
        || 'SELECT row_number() OVER (ORDER BY o.ord, t."verseID"), %s '
        || 'FROM %I t JOIN %I o ON o.vid = t."verseID" ORDER BY o.ord, t."verseID"',
        new_tbl, cols, (SELECT string_agg('t.' || c, ', ') FROM unnest(string_to_array(cols, ', ')) c), tbl, ord_table);
    EXECUTE format('SELECT count(*) FROM %I', new_tbl) INTO n_new;
    IF n_new <> n_old THEN
        RAISE EXCEPTION 'bibleget_rebuild_in_order(%): copied % of % rows', tbl, n_new, n_old;
    END IF;

    -- Primary key and indexes, built after the load (faster, and compact).
    SELECT conname INTO pk_name FROM pg_constraint
    WHERE conrelid = format('public.%I', tbl)::regclass AND contype = 'p';
    EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I PRIMARY KEY ("verseID")', new_tbl, pk_name || '_r');
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

    -- The verseID sequence belongs to the old column: hand it over first,
    -- or DROP TABLE would drop it.
    seq := pg_get_serial_sequence(format('public.%I', tbl), 'verseID');
    IF seq IS NOT NULL THEN
        EXECUTE format('ALTER SEQUENCE %s OWNED BY %I."verseID"', seq, new_tbl);
        EXECUTE format('SELECT setval(%L, %s)', seq, n_new);
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
    'Rebuild an edition table with "verseID" renumbered 1..n in the order of ord_table(vid, ord), '
    'physically in that order; keeps all columns, indexes, triggers and the sequence (migration 024).';
