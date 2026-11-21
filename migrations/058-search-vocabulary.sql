-- 058: each edition's words, for keyword search suggestions (/search/suggest).
--
-- Suggesting words as a reader types needs, per edition, the words its text
-- uses and how often; splitting every verse on each keystroke would be far too
-- slow, so the list is kept here. One row per edition and word:
--   word    the word as printed, lowercased ("israël", "cælum")
--   folded  the word unaccented, what a typed prefix is matched against
--   freq    how many of the edition's verses contain it (suggestions come most
--           frequent first)
-- Words are what remains of text_plain split on anything that is not a letter,
-- of at least 4 letters (KeywordSearchService::MIN_KEYWORD_LENGTH) and not a
-- stop word of the edition's text search configuration (bibleget_<ts_language>,
-- migration 043): every word listed is one keyword search accepts.
--
-- refresh_search_vocabulary(sigla) rebuilds one edition's words and returns
-- how many it has. It is called here for every edition; an import of a new
-- edition, and any later migration that changes an edition's text_plain (a
-- text correction like 055), must call it too. There is no trigger.
--
-- Idempotent. Run by Doctrine (src/Migrations/Version058SearchVocabulary.php):
-- no BEGIN/COMMIT.

CREATE TABLE IF NOT EXISTS search_vocabulary (
    sigla  TEXT    NOT NULL,
    word   TEXT    NOT NULL,
    folded TEXT    NOT NULL,
    freq   INTEGER NOT NULL,
    PRIMARY KEY (sigla, word)
);

CREATE INDEX IF NOT EXISTS search_vocabulary_prefix_idx
    ON search_vocabulary (sigla, folded text_pattern_ops);

CREATE OR REPLACE FUNCTION refresh_search_vocabulary(p_sigla TEXT) RETURNS INTEGER
LANGUAGE plpgsql AS $fn$
DECLARE
    cfg   TEXT;
    added INTEGER;
BEGIN
    SELECT 'bibleget_' || coalesce(nullif(ts_language, ''), 'simple') INTO cfg
      FROM versions_available WHERE sigla = p_sigla;
    IF cfg IS NULL OR to_regclass(format('public.%I', p_sigla)) IS NULL THEN
        RAISE EXCEPTION '058: no edition table for %', p_sigla;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = cfg) THEN
        cfg := 'simple';
    END IF;

    DELETE FROM search_vocabulary WHERE sigla = p_sigla;
    EXECUTE format(
        'INSERT INTO search_vocabulary (sigla, word, folded, freq)
         SELECT %L, w.word, unaccent(w.word), count(DISTINCT t.sort_order)
           FROM %I t, regexp_split_to_table(lower(t.text_plain), %L) AS w(word)
          WHERE char_length(w.word) >= 4
            AND to_tsvector(%L::regconfig, w.word) <> %L::tsvector
          GROUP BY w.word',
        p_sigla, p_sigla, '[^[:alpha:]]+', cfg, '');
    GET DIAGNOSTICS added = ROW_COUNT;
    RETURN added;
END
$fn$;

DO $mig$
DECLARE
    v RECORD;
BEGIN
    FOR v IN
        SELECT sigla FROM versions_available
         WHERE type = 'BIBLE' AND to_regclass(format('public.%I', sigla)) IS NOT NULL
         ORDER BY sigla
    LOOP
        PERFORM refresh_search_vocabulary(v.sigla);
    END LOOP;
END
$mig$;
