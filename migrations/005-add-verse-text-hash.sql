-- Migration: Add text_hash column and change-tracking for verse embeddings
-- Purpose: Detect when verse text changes so stale embeddings can be recomputed.
--
-- Adds:
--   1. text_hash (BYTEA) column on each version table — MD5 of verse text
--   2. Trigger to auto-maintain text_hash on INSERT/UPDATE
--   3. bit_xor aggregate for BYTEA — used to compute a version-level fingerprint
--   4. content_xor (BYTEA) column on embedding_metadata — XOR of all text hashes
--      at the time embeddings were last computed; a quick staleness check
--   5. embedded_text_hash (BYTEA) column on each version table — snapshot of
--      text_hash at the time the embedding was computed; enables per-verse
--      staleness detection

-- ── 1. Shared trigger function ──────────────────────────────────────────────
CREATE OR REPLACE FUNCTION update_text_hash()
RETURNS TRIGGER AS $$
BEGIN
    NEW.text_hash := decode(md5(NEW.text), 'hex');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ── 2. bit_xor aggregate for BYTEA ─────────────────────────────────────────
-- PostgreSQL has no built-in XOR aggregate for bytea.  We create one using
-- the bitwise XOR trick:  convert each 16-byte MD5 to two int8 halves, XOR
-- them independently, then reassemble.

CREATE OR REPLACE FUNCTION bytea_xor(a BYTEA, b BYTEA) RETURNS BYTEA AS $$
BEGIN
    IF a IS NULL THEN RETURN b; END IF;
    IF b IS NULL THEN RETURN a; END IF;
    -- XOR two 16-byte values via their int8 halves
    RETURN set_byte(set_byte(set_byte(set_byte(
           set_byte(set_byte(set_byte(set_byte(
           set_byte(set_byte(set_byte(set_byte(
           set_byte(set_byte(set_byte(set_byte(
               a,
               0, get_byte(a,0) # get_byte(b,0)),
               1, get_byte(a,1) # get_byte(b,1)),
               2, get_byte(a,2) # get_byte(b,2)),
               3, get_byte(a,3) # get_byte(b,3)),
               4, get_byte(a,4) # get_byte(b,4)),
               5, get_byte(a,5) # get_byte(b,5)),
               6, get_byte(a,6) # get_byte(b,6)),
               7, get_byte(a,7) # get_byte(b,7)),
               8, get_byte(a,8) # get_byte(b,8)),
               9, get_byte(a,9) # get_byte(b,9)),
              10, get_byte(a,10) # get_byte(b,10)),
              11, get_byte(a,11) # get_byte(b,11)),
              12, get_byte(a,12) # get_byte(b,12)),
              13, get_byte(a,13) # get_byte(b,13)),
              14, get_byte(a,14) # get_byte(b,14)),
              15, get_byte(a,15) # get_byte(b,15));
END;
$$ LANGUAGE plpgsql IMMUTABLE STRICT;

-- CREATE AGGREGATE has no IF NOT EXISTS clause in any released PostgreSQL.
-- Wrap in a DO block that looks up pg_aggregate by name + signature to make
-- the migration idempotent (safe to re-run on a database that already has it).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_aggregate a
        JOIN pg_proc p ON p.oid = a.aggfnoid
        WHERE p.proname = 'bytea_xor_agg'
          AND pg_get_function_identity_arguments(p.oid) = 'bytea'
    ) THEN
        CREATE AGGREGATE bytea_xor_agg(BYTEA) (
            SFUNC = bytea_xor,
            STYPE = BYTEA
        );
    END IF;
END;
$$;

-- ── 3. Add columns and triggers to each version table ───────────────────────
DO $$
DECLARE
    rec       RECORD;
    trig_name TEXT;
BEGIN
    FOR rec IN SELECT sigla FROM versions_available LOOP
        -- Skip if the corresponding table doesn't exist
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = rec.sigla
        ) THEN
            RAISE NOTICE 'Skipping %: table does not exist', rec.sigla;
            CONTINUE;
        END IF;

        -- Add text_hash column if not exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = rec.sigla AND column_name = 'text_hash'
        ) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD COLUMN text_hash BYTEA',
                rec.sigla
            );
        END IF;

        -- Add embedded_text_hash column if not exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = rec.sigla AND column_name = 'embedded_text_hash'
        ) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD COLUMN embedded_text_hash BYTEA',
                rec.sigla
            );
        END IF;

        -- Create trigger to auto-maintain text_hash
        trig_name := rec.sigla || '_text_hash_trigger';
        IF NOT EXISTS (
            SELECT 1 FROM pg_trigger
            WHERE tgname = trig_name
        ) THEN
            EXECUTE format(
                'CREATE TRIGGER %I BEFORE INSERT OR UPDATE OF text ON %I '
                || 'FOR EACH ROW EXECUTE FUNCTION update_text_hash()',
                trig_name, rec.sigla
            );
        END IF;

        -- Backfill text_hash for existing rows
        EXECUTE format(
            'UPDATE %I SET text_hash = decode(md5(text), ''hex'') WHERE text_hash IS NULL',
            rec.sigla
        );

        -- Snapshot current text_hash into embedded_text_hash for verses that
        -- already have embeddings (so they are not marked stale retroactively)
        EXECUTE format(
            'UPDATE %I SET embedded_text_hash = text_hash WHERE embedding IS NOT NULL AND embedded_text_hash IS NULL',
            rec.sigla
        );
    END LOOP;
END $$;

-- ── 4. Add content_xor column to embedding_metadata ────────────────────────
ALTER TABLE embedding_metadata
    ADD COLUMN IF NOT EXISTS content_xor BYTEA;
