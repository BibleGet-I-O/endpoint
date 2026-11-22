-- 063: "Zec", the New American Bible's own abbreviation for Zechariah (book 45), in biblebooks_abbr ENGLISH.
--
-- The SeminaVerbi wikis cite NABRE as "Zec 9:9" (Ghent Altarpiece), which BibleGet rejected: the table held "Zech"
-- for English and "Zc" for Italian and Portuguese. "Zec" was checked against every token of both tables, in every
-- language, compared without spaces, case or accents: no book holds it.
-- tests/Integration/Pipeline/BibleBooksIntegrityTest guards that no stored token resolves to another book;
-- LocalizedBookAbbreviationsTest pins it.
--
-- Appended only when the cell does not hold it yet (compared without spaces, case-insensitively), so rerunning
-- changes nothing.
--
-- Run by Doctrine (src/Migrations/Version063ZechariahAbbreviation.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    r       RECORD;
    token   TEXT;
    cell    TEXT;
    have    TEXT[];
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('biblebooks_abbr', 'ENGLISH', 45, 'Zec')
        ) AS t(tbl, lang, book, tokens)
    LOOP
        EXECUTE format('SELECT %I FROM %I WHERE "BOOK" = $1', r.lang, r.tbl) INTO cell USING r.book;
        IF cell IS NULL THEN
            RAISE EXCEPTION '063: % has no % cell for book %', r.tbl, r.lang, r.book;
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
