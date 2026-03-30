-- Migration: Add ts_language column to versions_available
-- Purpose: Store the PostgreSQL text search configuration name per Bible version
--          to enable language-aware full-text search with stemming.
--
-- PostgreSQL ships with dictionaries for: danish, dutch, english, finnish, french,
-- german, hungarian, italian, norwegian, portuguese, romanian, russian, spanish,
-- swedish, turkish. Use 'simple' for all other languages.

ALTER TABLE versions_available
    ADD COLUMN IF NOT EXISTS ts_language VARCHAR(30) NOT NULL DEFAULT 'simple';

-- Set ts_language for known languages.
-- This maps the human-readable `language` column to the PostgreSQL dictionary name.
-- Versions whose language has no PostgreSQL stemmer keep the default 'simple'.
UPDATE versions_available SET ts_language = 'english'    WHERE LOWER(language) = 'english';
UPDATE versions_available SET ts_language = 'french'     WHERE LOWER(language) IN ('french', 'français');
UPDATE versions_available SET ts_language = 'italian'    WHERE LOWER(language) IN ('italian', 'italiano');
UPDATE versions_available SET ts_language = 'german'     WHERE LOWER(language) IN ('german', 'deutsch');
UPDATE versions_available SET ts_language = 'spanish'    WHERE LOWER(language) IN ('spanish', 'español');
UPDATE versions_available SET ts_language = 'portuguese' WHERE LOWER(language) IN ('portuguese', 'português');
UPDATE versions_available SET ts_language = 'romanian'   WHERE LOWER(language) IN ('romanian', 'română');
UPDATE versions_available SET ts_language = 'hungarian'  WHERE LOWER(language) IN ('hungarian', 'magyar');
UPDATE versions_available SET ts_language = 'finnish'    WHERE LOWER(language) = 'finnish';
UPDATE versions_available SET ts_language = 'dutch'      WHERE LOWER(language) = 'dutch';
UPDATE versions_available SET ts_language = 'danish'     WHERE LOWER(language) = 'danish';
UPDATE versions_available SET ts_language = 'norwegian'  WHERE LOWER(language) = 'norwegian';
UPDATE versions_available SET ts_language = 'swedish'    WHERE LOWER(language) = 'swedish';
UPDATE versions_available SET ts_language = 'russian'    WHERE LOWER(language) = 'russian';
UPDATE versions_available SET ts_language = 'turkish'    WHERE LOWER(language) = 'turkish';

-- Rebuild GIN indexes on each version table to use the language-aware config.
-- This must be run per-version; the DO block iterates all versions.
DO $$
DECLARE
    rec RECORD;
    idx_name TEXT;
BEGIN
    FOR rec IN SELECT sigla, ts_language FROM versions_available LOOP
        -- Fail if the corresponding table doesn't exist (dangling sigla would break search)
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = rec.sigla
        ) THEN
            RAISE EXCEPTION 'Table "%" referenced by versions_available does not exist. Create the table or remove the entry before running this migration.', rec.sigla;
        END IF;

        idx_name := rec.sigla || '_text_fts';
        -- Drop the old simple-config index if it exists
        EXECUTE format('DROP INDEX IF EXISTS %I', idx_name);
        -- Create new index with the correct language config
        EXECUTE format(
            'CREATE INDEX %I ON %I USING gin(to_tsvector(%L, text))',
            idx_name, rec.sigla, rec.ts_language
        );
    END LOOP;
END $$;
