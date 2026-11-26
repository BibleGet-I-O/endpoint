-- 070: Obadiah's three section headings in NABRE, missed by migration 029: its
-- script starts reading headings at the first chapter line, and a book of one
-- chapter has none. (Philemon, 2 John, 3 John and Jude print no headings.)
--
-- Set only where the verse has no heading. Titles are not part of text_plain:
-- search and embeddings are unaffected. Idempotent. Run by Doctrine: no
-- BEGIN/COMMIT.

DO $mig$
BEGIN
    IF to_regclass('public."NABRE"') IS NULL THEN
        RETURN;
    END IF;
    UPDATE "NABRE" v SET title1 = h.title
      FROM (VALUES (1, 'Edom’s Fall Decreed'), (8, 'Edom’s Betrayal of Judah'), (15, 'Edom’s Fall and Judah’s Restoration')) AS h (verse, title)
     WHERE v.book = 38 AND v.chapter = 1 AND v.verse = h.verse AND coalesce(v.title1, '') = '';
END
$mig$;
