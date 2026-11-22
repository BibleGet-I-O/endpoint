-- 061: book names the SeminaVerbi pilot found missing (2026-11-22).
--
--   pt  Atos, Atos dos Apóstolos  (Acts, Brazilian spelling; the table held only the European "Actos")
--   fr  Actes des Apôtres         (Acts in full; the table held only "Actes")
--   en  1Thes, 2Thes              (1 and 2 Thessalonians; the table held "1Th | 1Thess")
--
-- Book tokens are matched across every language, so each was checked against every token of both tables, in
-- every language, compared without spaces, case or accents: none is held by any book. Names with spaces work
-- as stored ("Hechos de los Apóstoles" already did): the query and the names are compared without spaces.
-- tests/Integration/Pipeline/BibleBooksIntegrityTest guards that no stored token resolves to another book;
-- LocalizedBookAbbreviationsTest pins these.
--
-- Each token is appended only when the cell does not hold it yet (compared without spaces, case-insensitively),
-- so rerunning changes nothing.
--
-- Run by Doctrine (src/Migrations/Version061MoreBookNames.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    r       RECORD;
    token   TEXT;
    cell    TEXT;
    have    TEXT[];
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('biblebooks_fullname', 'PORTUGUESE', 51, 'Atos | Atos dos Apóstolos'),
            ('biblebooks_fullname', 'FRENCH',     51, 'Actes des Apôtres'),
            ('biblebooks_abbr',     'ENGLISH',    59, '1Thes'),
            ('biblebooks_abbr',     'ENGLISH',    60, '2Thes')
        ) AS t(tbl, lang, book, tokens)
    LOOP
        EXECUTE format('SELECT %I FROM %I WHERE "BOOK" = $1', r.lang, r.tbl) INTO cell USING r.book;
        IF cell IS NULL THEN
            RAISE EXCEPTION '061: % has no % cell for book %', r.tbl, r.lang, r.book;
        END IF;
        FOREACH token IN ARRAY string_to_array(r.tokens, ' | ') LOOP
            SELECT array_agg(lower(replace(x, ' ', ''))) INTO have
              FROM unnest(string_to_array(cell, ' | ')) AS x;
            IF NOT lower(replace(token, ' ', '')) = ANY (coalesce(have, '{}')) THEN
                cell := CASE WHEN cell = '' THEN token ELSE cell || ' | ' || token END;
            END IF;
        END LOOP;
        EXECUTE format('UPDATE %I SET %I = $1 WHERE "BOOK" = $2', r.tbl, r.lang) USING cell, r.book;
    END LOOP;
END
$mig$;
