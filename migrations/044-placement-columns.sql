-- 044: placement columns (step 2 of the verse-rendering plan, #184 / #180).
--
-- Every row is stored at its identity (book, chapter, verse; a letter in
-- verseequiv only where the edition prints one). How the edition prints the
-- row is recorded apart, only where it differs from the default:
--   printed_chapter  the chapter under whose heading the row is printed, when
--                    not `chapter` (NULL: printed under `chapter`). NABRE prints
--                    Wis 12:1 at the end of chapter 11, John 7:53 under chapter 8.
--   verse_label      the label printed beside the row, when not simply the verse
--                    number (with verseequiv's letter). NULL: the number; '':
--                    printed without a number (the rest of NABRE Job 10:1).
--   omitted          the edition prints the verse number without text (the
--                    critical text leaves the verse out, #180). Such rows have
--                    empty text and are never embedded or searched.
-- Data changes (NABRE's moved rows, omitted verses) are in 045. Idempotent.

BEGIN;

DO $mig$
DECLARE
    rec RECORD;
BEGIN
    FOR rec IN SELECT sigla FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', rec.sigla)) IS NULL;
        EXECUTE format('ALTER TABLE public.%I '
                       'ADD COLUMN IF NOT EXISTS printed_chapter INT, '
                       'ADD COLUMN IF NOT EXISTS verse_label VARCHAR(16), '
                       'ADD COLUMN IF NOT EXISTS omitted BOOLEAN NOT NULL DEFAULT false', rec.sigla);
    END LOOP;
END
$mig$;

COMMIT;
