-- Migration: rebuild the other editions in reading order (bibleget_rebuild_in_order, 024)
--
-- A scan of every edition (2026-11-14) found blocks of rows that the imports
-- had appended after Revelation, so they sort after the end of the Bible in
-- "verseID" and sit at the end of the heap:
--
--   NABRE  verseID 35434-35703  Esther, 270 Greek sub-verse rows -> after the rest of Esther (13456)
--   VGCL   verseID 35810-36070  Esther, 261 Greek rows            -> after the rest of Esther (13614)
--   NVBSE  verseID 35690        Ezekiel 40:43b                    -> after 40:43a (25970)
--          verseID 35691-35857  Esther, 167 Hebrew rows           -> after the rest of Esther (13562)
--   DRB    verseID 35649-38303  Isaiah + Jeremiah, 2,655 rows     -> after Sirach (20622), their
--                               place in the Douay order (DRB keeps 1-2 Maccabees after Malachi,
--                               which is that edition's own order, not an artifact)
--          verseID 38304-38477  Esther, 174 Greek rows            -> after the rest of Esther (13709)
--
-- Each moved block keeps its internal order (Esther's Hebrew/Greek
-- interleaving is a separate question). LUZZI and BLPD are already in order
-- but have gaps in "verseID" (LUZZI's run to 117697 for 31,094 rows); they are
-- rebuilt as they are, numbered 1..n. CEI2008 was rebuilt by 025; DIVCOM is
-- already contiguous.
--
-- Every move is checked first: the block must hold exactly the expected book
-- and row count, otherwise the edition is skipped with a NOTICE (so the
-- migration is idempotent: after the rebuild the blocks are gone).
-- Embeddings, hashes and all other columns are copied unchanged.

BEGIN;

CREATE OR REPLACE FUNCTION pg_temp.rebuild_with_moves(tbl TEXT, moves INT[][]) RETURNS VOID
LANGUAGE plpgsql AS $fn$
DECLARE
    i     INT;
    n     BIGINT;
    books INT[];
BEGIN
    -- moves: rows of {from_id, to_id, after_id, expected_book, expected_count};
    -- expected_book 0 = DRB's Isaiah + Jeremiah block (books 29 and 30).
    FOR i IN 1 .. coalesce(array_length(moves, 1), 0) LOOP
        EXECUTE format('SELECT count(*), array_agg(DISTINCT book) FROM %I WHERE "verseID" BETWEEN $1 AND $2', tbl)
            INTO n, books USING moves[i][1], moves[i][2];
        IF n <> moves[i][5] OR books <> (CASE WHEN moves[i][4] = 0 THEN ARRAY[29, 30] ELSE ARRAY[moves[i][4]] END) THEN
            RAISE NOTICE '%: block %-% is not % rows of book % (found % rows, books %): skipping',
                tbl, moves[i][1], moves[i][2], moves[i][5], moves[i][4], n, books;
            RETURN;
        END IF;
    END LOOP;
    IF array_length(moves, 1) IS NULL THEN
        EXECUTE format('SELECT count(*) FROM %I WHERE "verseID" > (SELECT count(*) FROM %I)', tbl, tbl) INTO n;
        IF n = 0 THEN
            RAISE NOTICE '%: already numbered 1..n: skipping', tbl;
            RETURN;
        END IF;
    END IF;

    DROP TABLE IF EXISTS pg_temp.edition_ord;
    CREATE TEMP TABLE edition_ord (vid INT PRIMARY KEY, ord NUMERIC NOT NULL) ON COMMIT DROP;
    EXECUTE format('INSERT INTO edition_ord SELECT "verseID", "verseID" FROM %I', tbl);
    FOR i IN 1 .. coalesce(array_length(moves, 1), 0) LOOP
        UPDATE edition_ord
        SET ord = moves[i][3] + (vid - moves[i][1] + 1)::NUMERIC / (moves[i][2] - moves[i][1] + 2)
        WHERE vid BETWEEN moves[i][1] AND moves[i][2];
    END LOOP;
    PERFORM bibleget_rebuild_in_order(tbl, 'edition_ord');
    RAISE NOTICE '%: rebuilt in reading order', tbl;
END
$fn$;

SELECT pg_temp.rebuild_with_moves('NABRE', ARRAY[[35434, 35703, 13456, 19, 270]]);
SELECT pg_temp.rebuild_with_moves('VGCL',  ARRAY[[35810, 36070, 13614, 19, 261]]);
SELECT pg_temp.rebuild_with_moves('NVBSE', ARRAY[[35690, 35690, 25970, 33, 1], [35691, 35857, 13562, 19, 167]]);
-- DRB: Isaiah (35649-36940) and Jeremiah (36941-38303) move as one block (book 0 = Isaiah + Jeremiah).
SELECT pg_temp.rebuild_with_moves('DRB',   ARRAY[[35649, 38303, 20622, 0, 2655], [38304, 38477, 13709, 19, 174]]);
SELECT pg_temp.rebuild_with_moves('LUZZI', ARRAY[]::INT[][]);
SELECT pg_temp.rebuild_with_moves('BLPD',  ARRAY[]::INT[][]);

COMMIT;
