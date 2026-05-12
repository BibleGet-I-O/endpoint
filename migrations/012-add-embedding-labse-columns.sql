-- Migration: Add embedding_labse columns for the LaBSE A/B experiment
-- Purpose: Issue #71 / discussion #107 — paraphrase-multilingual-MiniLM-L12-v2
--          gave poor semantic-search results for Latin (NVBSE). LaBSE
--          (sentence-transformers/LaBSE) is the next candidate. Run the two
--          side by side on a representative subset of versions before any
--          live cutover.
--
-- Scope:   NABRE (English), CEI2008 (Italian), NVBSE (Latin) — one version
--          per language family so the comparison spans the cases that
--          motivated the experiment. Other versions stay on MiniLM until
--          LaBSE is validated.
--
-- Shape:   Adds `embedding_labse vector(768)` alongside the existing
--          `embedding vector(384)`. Keeping both columns lets the same
--          rows be queried under either model without recomputing the
--          baseline.
--
-- Metadata: `embedding_metadata` is widened so per-(version, column)
--           tracking is possible. Existing rows keep column_name =
--           'embedding' via the DEFAULT, so model upgrades on the
--           primary column behave exactly as before.

-- ── 1. Add embedding_labse column + HNSW index to the three target versions ──
DO $$
DECLARE
    v_sigla  TEXT;
    idx_name TEXT;
BEGIN
    FOREACH v_sigla IN ARRAY ARRAY['NABRE', 'CEI2008', 'NVBSE'] LOOP
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

-- ── 2. Widen embedding_metadata to (version_sigla, column_name) ──────────────
-- column_name defaults to 'embedding' so all pre-existing rows automatically
-- describe the primary embedding column, matching prior behavior.
ALTER TABLE embedding_metadata
    ADD COLUMN IF NOT EXISTS column_name VARCHAR(50) NOT NULL DEFAULT 'embedding';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        WHERE t.relname = 'embedding_metadata'
          AND c.contype = 'p'
          AND c.conname = 'embedding_metadata_pkey'
    ) AND (
        SELECT array_length(conkey, 1)
        FROM pg_constraint
        WHERE conname = 'embedding_metadata_pkey'
    ) = 1 THEN
        ALTER TABLE embedding_metadata DROP CONSTRAINT embedding_metadata_pkey;
        ALTER TABLE embedding_metadata ADD PRIMARY KEY (version_sigla, column_name);
    END IF;
END $$;
