-- 043: keyword search ignores accents, diaereses and ligatures (#196).
--
-- Keyword search matched words exactly as each edition spells them: "Israel"
-- found nothing in VGCL ("Israël"), "caelum" nothing where an edition writes
-- "cælum", "perche" nothing in CEI 2008 ("perché").
--
-- For every built-in text search configuration <cfg>, this creates
-- public.bibleget_<cfg>: the same parser and dictionaries, with the unaccent
-- dictionary in front for the token types that can carry non-ASCII letters
-- (word, hword, hword_part). unaccent's rules fold é→e, ë→e, æ→ae, œ→oe, ...;
-- the dictionary after it (simple, or the language's stemmer) lowercases and
-- stems as before. KeywordSearchService queries 'bibleget_' || ts_language, so
-- both the text and the keyword are folded; exact mode compares
-- unaccent(text_plain) with unaccent(keyword). The text returned is unchanged.
--
-- Each edition's full-text index ("<SIGLA>_text_plain_fts") is rebuilt on the
-- folded configuration, so it matches the query expression again. Deploy this
-- migration before the code that uses bibleget_<cfg>.
-- The unaccent extension is trusted (PostgreSQL 13+): the database owner can
-- create it. Idempotent.

BEGIN;

CREATE EXTENSION IF NOT EXISTS unaccent;

DO $mig$
DECLARE
    cfg  RECORD;
    dict TEXT;
    rec  RECORD;
BEGIN
    FOR cfg IN SELECT cfgname FROM pg_ts_config WHERE cfgnamespace = 'pg_catalog'::regnamespace LOOP
        dict := CASE WHEN cfg.cfgname = 'simple' THEN 'simple' ELSE cfg.cfgname || '_stem' END;
        CONTINUE WHEN NOT EXISTS (SELECT 1 FROM pg_ts_dict WHERE dictname = dict);
        CONTINUE WHEN EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'bibleget_' || cfg.cfgname);
        EXECUTE format('CREATE TEXT SEARCH CONFIGURATION public.%I (COPY = pg_catalog.%I)',
                       'bibleget_' || cfg.cfgname, cfg.cfgname);
        EXECUTE format('ALTER TEXT SEARCH CONFIGURATION public.%I ALTER MAPPING FOR word, hword, hword_part '
                       'WITH public.unaccent, pg_catalog.%I', 'bibleget_' || cfg.cfgname, dict);
    END LOOP;

    FOR rec IN SELECT sigla, ts_language FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', rec.sigla)) IS NULL;
        EXECUTE format('DROP INDEX IF EXISTS public.%I', rec.sigla || '_text_plain_fts');
        EXECUTE format('CREATE INDEX %I ON public.%I USING gin (to_tsvector(%L::regconfig, text_plain))',
                       rec.sigla || '_text_plain_fts', rec.sigla, 'bibleget_' || rec.ts_language);
    END LOOP;
END
$mig$;

COMMIT;
