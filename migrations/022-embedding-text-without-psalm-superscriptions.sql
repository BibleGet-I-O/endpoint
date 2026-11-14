-- 022: embed psalm verses without their superscription.
--
-- In Hebrew numbering a psalm's superscription is part of verse 1 (or 1-2),
-- and editions that mark up their poetry store it as an untagged line before
-- the first poetic line, e.g. NABRE Ps 23:1:
--
--     A psalm of David.
--     <pof>The L<sm>ORD</sm> is my shepherd;</pof>
--     <poi>there is nothing I lack.</poi>
--
-- Embedding the superscription together with the verse drags short queries
-- down: "the LORD is my shepherd" ranked Ps 23:1 11th (LaBSE) / 6th (MiniLM)
-- with it, 1st in both models without it.
--
-- bibleget_embedding_text(book, text) is what scripts/compute_embeddings.py
-- now embeds: bibleget_plain_text(text), except that for a Psalms verse
-- (universal book 23) that does not start with a poetic line but has one
-- later, everything before the first "\n<po..." line is left out. The
-- superscription stays in text / text_plain, so quotes and keyword search
-- are unchanged. Verse rows that are only a superscription (no poetry, e.g.
-- NABRE Ps 51:1-2) are embedded as they are.
--
-- Editions without poetic markup (CEI2008, BLPD, DRB, DIVCOM, NVBSE) cannot
-- be split reliably and are not affected.
--
-- The rows whose embedding text now differs from text_plain get
-- embedded_text_hash = NULL, which makes them stale; re-run
-- scripts/compute_embeddings.py (both columns) afterwards. As of 2026-11-14:
-- NABRE 60, LUZZI 114, VGCL (after 021) ~150 rows.

BEGIN;

CREATE OR REPLACE FUNCTION bibleget_embedding_text(p_book INT, src TEXT)
RETURNS TEXT LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT CASE
        WHEN p_book = 23
             AND src !~ '^<(po|pof|pol|poi|poil|poif|pos|po3|po3l)>'
             AND strpos(src, E'\n<po') > 0
        THEN bibleget_plain_text(substr(src, strpos(src, E'\n<po') + 1))
        ELSE bibleget_plain_text(src)
    END
$$;

COMMENT ON FUNCTION bibleget_embedding_text(INT, TEXT) IS
    'Text that compute_embeddings.py embeds: bibleget_plain_text(text) minus a '
    'leading psalm superscription line. If this rule changes, set '
    'embedded_text_hash = NULL on the rows whose result changes (see migration 022).';

DO $$
DECLARE
    t TEXT;
    n INT;
BEGIN
    FOR t IN SELECT sigla FROM versions_available ORDER BY sigla LOOP
        EXECUTE format(
            'UPDATE %I SET embedded_text_hash = NULL '
            || 'WHERE book = 23 AND embedded_text_hash IS NOT NULL '
            || 'AND bibleget_embedding_text(book, text) <> text_plain', t);
        GET DIAGNOSTICS n = ROW_COUNT;
        RAISE NOTICE '%: % psalm verses marked for re-embedding', t, n;
    END LOOP;
END $$;

COMMIT;
