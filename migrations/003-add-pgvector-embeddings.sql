-- Migration: Add pgvector extension and embedding columns for semantic search
-- Purpose: Enable vector similarity search for Phase 2-4 of semantic search plan.
-- Requires: PostgreSQL with pgvector extension installed (pgvector/pgvector Docker image
--           or manually installed via https://github.com/pgvector/pgvector)

-- Enable pgvector
CREATE EXTENSION IF NOT EXISTS vector;

-- Add embedding column and HNSW index to each version table.
-- The embedding column stores 384-dimensional vectors from the
-- paraphrase-multilingual-MiniLM-L12-v2 sentence-transformers model.
DO $$
DECLARE
    rec RECORD;
    idx_name TEXT;
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

        -- Add embedding column if not exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = rec.sigla AND column_name = 'embedding'
        ) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD COLUMN embedding vector(384)',
                rec.sigla
            );
        END IF;

        -- Create HNSW index if not exists
        idx_name := rec.sigla || '_embedding_hnsw';
        IF NOT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'public' AND indexname = idx_name
        ) THEN
            EXECUTE format(
                'CREATE INDEX %I ON %I USING hnsw (embedding vector_cosine_ops)',
                idx_name, rec.sigla
            );
        END IF;
    END LOOP;
END $$;
