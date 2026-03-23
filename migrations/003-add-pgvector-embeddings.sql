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
        -- Add embedding column if not exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = rec.sigla AND column_name = 'embedding'
        ) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD COLUMN embedding vector(384)',
                rec.sigla
            );
        END IF;

        -- Create HNSW index for approximate nearest neighbor search
        idx_name := rec.sigla || '_embedding_hnsw';
        EXECUTE format('DROP INDEX IF EXISTS %I', idx_name);
        EXECUTE format(
            'CREATE INDEX %I ON %I USING hnsw (embedding vector_cosine_ops)',
            idx_name, rec.sigla
        );
    END LOOP;
END $$;
