-- 043: keyword search ignores accents, diaereses and ligatures (#196).
--
-- Keyword search matched words exactly as each edition spells them: "Israel"
-- found nothing in VGCL ("Israël"), "caelum" nothing where an edition writes
-- "cælum", "perche" nothing in CEI 2008 ("perché").
--
-- For every built-in text search configuration <cfg>, this creates
-- public.bibleget_<cfg>: the same parser and dictionaries, with the unaccent
-- dictionary in front for every token type that can carry non-ASCII letters
-- (word, hword, hword_part, and the alphanumeric numword, numhword,
-- hword_numpart: "Israël2"). Each keeps the dictionaries <cfg> gives it, so
-- unaccent's folding (é→e, ë→e, æ→ae, œ→oe, ...) comes first and the
-- language's own dictionary still lowercases and stems. KeywordSearchService
-- queries 'bibleget_' || ts_language, so the text and the keyword are folded
-- alike; exact mode compares unaccent(text_plain) with the folded keyword.
-- The text returned is unchanged.
--
-- Each edition's full-text index ("<SIGLA>_text_plain_fts") is rebuilt on the
-- folded configuration, so it matches the query expression again. The new
-- indexes are built first under their temporary names (CREATE INDEX lets
-- reads through); the old ones are dropped and the new ones renamed only at
-- the end, so the exclusive locks that DROP INDEX takes are held for moments,
-- not for the whole rebuild. Deploy this migration before the code that uses
-- bibleget_<cfg>. Idempotent: rerunning resets the mappings and rebuilds.
-- The unaccent extension is trusted (PostgreSQL 13+): the database owner can
-- create it. Idempotent.

BEGIN;

CREATE EXTENSION IF NOT EXISTS unaccent;

DO $mig$
DECLARE
    cfg  RECORD;
    tt   RECORD;
    rec  RECORD;
BEGIN
    FOR cfg IN SELECT oid, cfgname, cfgparser FROM pg_ts_config WHERE cfgnamespace = 'pg_catalog'::regnamespace LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'bibleget_' || cfg.cfgname) THEN
            EXECUTE format('CREATE TEXT SEARCH CONFIGURATION public.%I (COPY = pg_catalog.%I)',
                           'bibleget_' || cfg.cfgname, cfg.cfgname);
        END IF;
        FOR tt IN
            SELECT t.alias,
                   string_agg(format('%I.%I', n.nspname, d.dictname), ', ' ORDER BY m.mapseqno) AS dicts
              FROM pg_ts_config_map m
              JOIN ts_token_type(cfg.cfgparser) t ON t.tokid = m.maptokentype
              JOIN pg_ts_dict d ON d.oid = m.mapdict
              JOIN pg_namespace n ON n.oid = d.dictnamespace
             WHERE m.mapcfg = cfg.oid
               AND t.alias IN ('word', 'hword', 'hword_part', 'numword', 'numhword', 'hword_numpart')
             GROUP BY t.alias
        LOOP
            EXECUTE format('ALTER TEXT SEARCH CONFIGURATION public.%I ALTER MAPPING FOR %I WITH public.unaccent, %s',
                           'bibleget_' || cfg.cfgname, tt.alias, tt.dicts);
        END LOOP;
    END LOOP;

    -- Build every new index first (reads continue), then swap them in at the end.
    FOR rec IN SELECT sigla, ts_language FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', rec.sigla)) IS NULL;
        EXECUTE format('DROP INDEX IF EXISTS public.%I', rec.sigla || '_text_plain_fts_043');
        EXECUTE format('CREATE INDEX %I ON public.%I USING gin (to_tsvector(%L::regconfig, text_plain))',
                       rec.sigla || '_text_plain_fts_043', rec.sigla, 'bibleget_' || rec.ts_language);
    END LOOP;
    FOR rec IN SELECT sigla FROM versions_available LOOP
        CONTINUE WHEN to_regclass(format('public.%I', rec.sigla)) IS NULL;
        EXECUTE format('DROP INDEX IF EXISTS public.%I', rec.sigla || '_text_plain_fts');
        EXECUTE format('ALTER INDEX public.%I RENAME TO %I', rec.sigla || '_text_plain_fts_043', rec.sigla || '_text_plain_fts');
    END LOOP;
END
$mig$;

COMMIT;
