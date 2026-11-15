-- 037: NABRE Sir 33:20-21 and 41:14-15 in printed order (#185).
--
-- NABRE prints two passages of Sirach out of numeric order, and the rows were
-- stored in numeric order instead. Checked against the publisher's SCML
-- (nabre8.scml in the private NABRE-source repo), which prints:
--
--   Sir 33: ... 19, 20a, 21, 20b, 22 ...        stored 20a, 20b, 21
--   Sir 41: ... 13, 14b, 15, 14a, 16a, 16b ...  stored 14a, 14b, 15
--
-- Every row already carries the right text for its label (also checked
-- against the SCML); only the reading order ("verseID") is wrong. The heading
-- on 41:14b stays on that row, which now comes first, as in print.
--
-- Fix: within each passage, take the rows' own verseIDs, sorted, and hand them
-- out in printed order. The rows are found by label, not by verseID, so the
-- same SQL works on any copy of NABRE; re-running it changes nothing. Text,
-- embeddings and hashes don't change.
--
-- After applying: scripts/versification_snapshot.py --check shows the two
-- lines changing to the printed order; regenerate NABRE.txt.

BEGIN;

DO $mig$
DECLARE
    passage RECORD;
    ids     INT[];
    n       INT;
    i       INT;
BEGIN
    FOR passage IN
        SELECT * FROM (VALUES
            (33, ARRAY['20a', '21', '20b']),
            (41, ARRAY['14b', '15', '14a'])
        ) AS p(chapter, labels)
    LOOP
        -- A row's label: verseequiv when lettered (20a, 14b), else the verse number.
        SELECT array_agg("verseID" ORDER BY "verseID"), count(*) INTO ids, n
        FROM "NABRE"
        WHERE book = 28 AND chapter = passage.chapter
          AND coalesce(nullif(verseequiv, ''), verse::text) = ANY (passage.labels);
        IF n <> array_length(passage.labels, 1) THEN
            RAISE EXCEPTION 'Sir %: expected % rows labelled %, found %',
                passage.chapter, array_length(passage.labels, 1), passage.labels, n;
        END IF;

        -- Two steps, so the primary key never holds a duplicate mid-update.
        UPDATE "NABRE" SET "verseID" = -"verseID" WHERE "verseID" = ANY (ids);
        FOR i IN 1 .. n LOOP
            UPDATE "NABRE" SET "verseID" = ids[i]
            WHERE "verseID" < 0 AND book = 28 AND chapter = passage.chapter
              AND coalesce(nullif(verseequiv, ''), verse::text) = passage.labels[i];
        END LOOP;
        IF EXISTS (SELECT 1 FROM "NABRE" WHERE "verseID" < 0) THEN
            RAISE EXCEPTION 'Sir %: a row was left without its verseID', passage.chapter;
        END IF;
        RAISE NOTICE 'Sir %: % in printed order', passage.chapter, array_to_string(passage.labels, ', ');
    END LOOP;
END
$mig$;

COMMIT;
