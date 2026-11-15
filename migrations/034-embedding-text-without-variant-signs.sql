-- 034: embed verses without CEI 2008's variant signs (⌈ ⌉ ⊥).
--
-- CEI 2008 marks where the Nova Vulgata differs from the critical text it
-- translates (⌈...⌉: words changed or left out there; ⊥: where it adds
-- words). The signs are part of the printed text, so text and text_plain
-- keep them (026 and 033 put them back), but they carry no meaning for the
-- embedding models and shift the vectors: for short verses the cosine
-- between a verse with and without its signs was 0.96-0.98 under LaBSE and
-- 0.84-0.93 under MiniLM (staging, 2026-11-15). 207 CEI2008 rows have them
-- (Esther, Tobit, Judith, Sirach).
--
-- bibleget_embedding_text() now also removes the three signs (and the
-- doubled spaces they leave). As 022 asks when the rule changes, the rows
-- whose embedding text changes get embedded_text_hash = NULL (stale for
-- embedding_labse) and embedding = NULL (MiniLM is only filled where NULL);
-- then run scripts/compute_embeddings.py with the default column and with
-- --column embedding.

BEGIN;

CREATE OR REPLACE FUNCTION bibleget_embedding_text(p_book INT, src TEXT)
RETURNS TEXT LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT btrim(regexp_replace(regexp_replace(
        CASE
            WHEN p_book = 23
                 AND src !~ '^<(po|pof|pol|poi|poil|poif|pos|po3|po3l)>'
                 AND strpos(src, E'\n<po') > 0
            THEN bibleget_plain_text(substr(src, strpos(src, E'\n<po') + 1))
            ELSE bibleget_plain_text(src)
        END,
        '[⌈⌉⊥]', '', 'g'), '\s+', ' ', 'g'))
$$;

COMMENT ON FUNCTION bibleget_embedding_text(INT, TEXT) IS
    'Text that compute_embeddings.py embeds: bibleget_plain_text(text) minus a '
    'leading psalm superscription line (022) and CEI 2008''s variant signs (034). '
    'If this rule changes, set embedded_text_hash = NULL on the rows whose result changes.';

DO $$
DECLARE
    t TEXT;
    n INT;
BEGIN
    FOR t IN SELECT sigla FROM versions_available ORDER BY sigla LOOP
        EXECUTE format(
            'UPDATE %I SET embedded_text_hash = NULL, embedding = NULL '
            || 'WHERE text_plain ~ ''[⌈⌉⊥]''', t);
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n > 0 THEN
            RAISE NOTICE '%: % verses with variant signs marked for re-embedding', t, n;
        END IF;
    END LOOP;
END $$;

COMMIT;
