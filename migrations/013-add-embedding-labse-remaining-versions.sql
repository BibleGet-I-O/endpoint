-- Migration: Extend embedding_labse column to the remaining Bible versions
-- Purpose: Migration 012 added `embedding_labse vector(768)` to the three
--          versions selected for the discussion #107 A/B experiment
--          (NABRE, CEI2008, NVBSE). The cross-language evaluation confirmed
--          LaBSE beats MiniLM decisively for Latin and is at least neutral
--          elsewhere, so we extend the same column to the remaining live
--          versions in preparation for the cutover in migration 014.
--
-- Tables affected: BLPD, DIVCOM, DRB, LUZZI, VGCL.
--
-- Pure schema: pgvector `vector(768)` column + matching HNSW index per
--              table. No data is written here — `compute_embeddings.py`
--              fills the column afterwards.
--
-- Idempotent: each ADD COLUMN / CREATE INDEX is guarded by an EXISTS check
--             so a partially-applied run can be re-run cleanly.

DO $$
DECLARE
    v_sigla  TEXT;
    idx_name TEXT;
BEGIN
    FOREACH v_sigla IN ARRAY ARRAY['BLPD', 'DIVCOM', 'DRB', 'LUZZI', 'VGCL'] LOOP
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = v_sigla
        ) THEN
            RAISE NOTICE 'Skipping %: table does not exist', v_sigla;
            CONTINUE;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = v_sigla
              AND column_name = 'embedding_labse'
        ) THEN
            EXECUTE format('ALTER TABLE %I ADD COLUMN embedding_labse vector(768)', v_sigla);
        END IF;

        idx_name := v_sigla || '_embedding_labse_hnsw';
        IF NOT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'public' AND indexname = idx_name
        ) THEN
            EXECUTE format(
                'CREATE INDEX %I ON %I USING hnsw (embedding_labse vector_cosine_ops)',
                idx_name, v_sigla
            );
        END IF;
    END LOOP;
END $$;
