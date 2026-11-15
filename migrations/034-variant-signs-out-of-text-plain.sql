-- 034: CEI 2008's variant signs (⌈ ⌉ ⊥) are left out of text_plain.
--
-- CEI 2008 marks where the Nova Vulgata differs from the critical text it
-- translates (⌈...⌉: words changed or left out there; ⊥: where it adds
-- words). The signs are part of the printed text, so `text` keeps them (026
-- and 033 put them back) and quotes show them. text_plain is the searchable
-- form of the verse: keyword search, the embeddings (via
-- bibleget_embedding_text) and text_hash are built on it. There the signs
-- only get in the way:
--   * embeddings: for short verses the cosine between a verse with and
--     without its signs was 0.96-0.98 under LaBSE and 0.84-0.93 under
--     MiniLM (staging, 2026-11-15);
--   * keyword search: an exact phrase across a sign ("Susa, un uomo" vs
--     "Susa⌉, un uomo") did not match.
-- So bibleget_plain_text() now drops the three signs (before collapsing
-- whitespace, so "⌈ Tu conosci" becomes "Tu conosci"). The Python mirrors
-- (plain_text() in scripts/format_*/repair_* generators) do the same.
--
-- 207 CEI2008 rows have signs; their text_plain is recomputed (the text
-- trigger rederives it) and text_hash changes, so their LaBSE embeddings are
-- stale; `embedding` (MiniLM, only filled where NULL) is cleared too. Then
-- run scripts/compute_embeddings.py with the default column and with
-- --column embedding.
--
-- Staging briefly had an earlier 034 that stripped the signs only in
-- bibleget_embedding_text(); this restores that function to 022's
-- definition, since text_plain no longer has them.

BEGIN;

CREATE OR REPLACE FUNCTION bibleget_plain_text(src TEXT) RETURNS TEXT
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(regexp_replace(src,
        '[⌈⌉⊥]', '', 'g'),
        '</?(sm|i|sup)>', '', 'g'),
        '</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>', ' ', 'g'),
        '<br\s*/?>', ' ', 'gi'),
        '\s+', ' ', 'g'))
$$;

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
        -- SET text = text fires the trigger that rederives text_plain/text_hash.
        EXECUTE format(
            'UPDATE %I SET text = text, embedding = NULL '
            || 'WHERE text ~ ''[⌈⌉⊥]'' AND text_plain IS DISTINCT FROM bibleget_plain_text(text)', t);
        GET DIAGNOSTICS n = ROW_COUNT;
        IF n > 0 THEN
            RAISE NOTICE '%: text_plain recomputed without variant signs in % verses', t, n;
        END IF;
        EXECUTE format('SELECT count(*) FROM %I WHERE text_plain ~ ''[⌈⌉⊥]''', t) INTO n;
        IF n > 0 THEN
            RAISE EXCEPTION '%: % verses still have variant signs in text_plain', t, n;
        END IF;
    END LOOP;
END $$;

COMMIT;
