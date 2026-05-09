-- Migration: Drop book_consecutive from NVBSE_idx and LUZZI_idx
-- Purpose: `book_consecutive` is a legacy artifact of the original LUZZI
--          MariaDB schema (AUTO_INCREMENT PRIMARY KEY, with `book` UNIQUE)
--          and was carried into PostgreSQL — and accidentally added to
--          NVBSE_idx in PG too even though MariaDB's NVBSE_idx never had
--          it. Nothing in the API or any client repo reads the column;
--          it is dead weight that complicates the schema and forced the
--          migration script to special-case these two idx tables.
--
-- This migration aligns both PG idx tables with the standard idx schema
-- used by every other edition (CEI2008_idx, BLPD_idx, etc.):
-- `book INT PRIMARY KEY`. The companion MariaDB cleanup is a one-shot
-- ALTER on the live MariaDB and is not scripted here.
--
-- Idempotent: each table is checked for the column before altering, and
-- the matching SERIAL sequence is dropped if it exists.

DO $$
DECLARE
    tbl TEXT;
BEGIN
    FOREACH tbl IN ARRAY ARRAY['NVBSE_idx', 'LUZZI_idx'] LOOP
        IF to_regclass(format('%I', tbl)) IS NULL THEN
            RAISE NOTICE '% does not exist — skipping.', tbl;
            CONTINUE;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name   = tbl
               AND column_name  = 'book_consecutive'
        ) THEN
            RAISE NOTICE '%.book_consecutive already absent — skipping.', tbl;
            CONTINUE;
        END IF;

        -- 1. Drop the existing PRIMARY KEY (which is on book_consecutive).
        EXECUTE format(
            'ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I',
            tbl, tbl || '_pkey'
        );

        -- 2. Drop the now-redundant UNIQUE(book) constraint if present;
        --    its name is auto-generated (e.g. NVBSE_idx_book_key).
        EXECUTE format(
            'ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I',
            tbl, tbl || '_book_key'
        );

        -- 3. Drop the column. Postgres also drops the owning sequence
        --    automatically when the SERIAL column is dropped.
        EXECUTE format(
            'ALTER TABLE %I DROP COLUMN book_consecutive',
            tbl
        );

        -- 4. Promote book to PRIMARY KEY.
        EXECUTE format(
            'ALTER TABLE %I ADD PRIMARY KEY (book)',
            tbl
        );

        RAISE NOTICE 'Dropped %.book_consecutive and promoted book to PRIMARY KEY.', tbl;
    END LOOP;
END $$;
