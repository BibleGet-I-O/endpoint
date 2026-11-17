-- 047: two import slips in NABRE's Greek Esther, shown by the versification
-- snapshot regenerated after 046 (NABRE's printed labels are in verse_label):
--
--   Addition E:21 (8:12v) was stored in chapter 9, between 8:12u and 8:12x;
--     CEI2008 and VGCL have it at 8:12v. A Greek quote of Est 8:12 missed it
--     and one of 9:12 returned it.
--   10:3e was labelled F:4, the label of 10:3d (F:1 ... F:11 run in order).
--
-- No text or reading order changes. Rows are found by key and label.
-- Idempotent.

BEGIN;

DO $mig$
DECLARE
    n INT;
BEGIN
    IF to_regclass('public."NABRE"') IS NULL THEN
        RETURN;
    END IF;

    UPDATE "NABRE" SET chapter = 8
     WHERE book = 19 AND chapter = 9 AND verse = 12 AND verse_segment = 'v'
       AND text_tradition = 'GREEK' AND verse_label = 'E:21';
    GET DIAGNOSTICS n = ROW_COUNT;
    RAISE NOTICE 'NABRE Esther E:21 moved to 8:12v: % row(s)', n;
    IF (SELECT count(*) FROM "NABRE" WHERE book = 19 AND chapter = 8 AND verse = 12 AND verse_segment = 'v'
          AND text_tradition = 'GREEK' AND verse_label = 'E:21') <> 1 THEN
        RAISE EXCEPTION 'NABRE Esther E:21 is not at 8:12v';
    END IF;

    UPDATE "NABRE" SET verse_label = 'F:5'
     WHERE book = 19 AND chapter = 10 AND verse = 3 AND verse_segment = 'e'
       AND text_tradition = 'GREEK' AND verse_label = 'F:4';
    GET DIAGNOSTICS n = ROW_COUNT;
    RAISE NOTICE 'NABRE Esther 10:3e relabelled F:5: % row(s)', n;
    IF (SELECT count(*) FROM "NABRE" WHERE book = 19 AND chapter = 10 AND verse = 3 AND verse_segment = 'e'
          AND text_tradition = 'GREEK' AND verse_label = 'F:5') <> 1 THEN
        RAISE EXCEPTION 'NABRE Esther 10:3e is not labelled F:5';
    END IF;
END
$mig$;

COMMIT;
