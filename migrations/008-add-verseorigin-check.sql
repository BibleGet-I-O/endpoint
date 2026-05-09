-- Migration: Add CHECK constraint on verseorigin
-- Purpose: In MariaDB, `verseorigin` is `ENUM('GREEK','HEBREW')` and invalid
--          values are rejected at the storage layer. The mariadb→pgsql
--          migration flattened it to `varchar(10)` and lost that guarantee
--          (see Known Data Issues page in the wiki, "PostgreSQL migration
--          regressions" section).
--
-- This migration restores the constraint as a CHECK on each edition table
-- that carries `verseorigin`: BLPD, CEI2008, DRB, NABRE, NVBSE, VGCL.
-- DIVCOM and LUZZI have no `verseorigin` column. NABRE_old is a legacy
-- backup not used by the live endpoint and is intentionally skipped here.
--
-- CHECK is preferred over `CREATE TYPE … AS ENUM` because:
--   - it's a non-destructive ADD CONSTRAINT (no ALTER COLUMN TYPE on
--     millions of rows)
--   - NULL is allowed by default, preserving existing semantics (the vast
--     majority of rows have no verseorigin)
--   - it is easier to modify if the allowed value set ever changes
--
-- Idempotent: skips any table that already has a constraint named
-- "<TABLE>_verseorigin_check".

DO $$
DECLARE
    tbl TEXT;
BEGIN
    FOREACH tbl IN ARRAY ARRAY['BLPD', 'CEI2008', 'DRB', 'NABRE', 'NVBSE', 'VGCL'] LOOP
        IF NOT EXISTS (
            SELECT 1 FROM pg_constraint
             WHERE conname  = tbl || '_verseorigin_check'
               AND conrelid = format('%I', tbl)::regclass
        ) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD CONSTRAINT %I CHECK (verseorigin IN (''GREEK'', ''HEBREW''))',
                tbl,
                tbl || '_verseorigin_check'
            );
            RAISE NOTICE 'Added % CHECK constraint.', tbl || '_verseorigin_check';
        ELSE
            RAISE NOTICE '% already exists — skipping.', tbl || '_verseorigin_check';
        END IF;
    END LOOP;
END $$;
