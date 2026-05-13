-- Migration: Cutover from MiniLM (vector(384)) to LaBSE (vector(768))
-- Purpose: Promote `embedding_labse` to be THE embedding column, drop the
--          legacy MiniLM `embedding`. After this migration the API code
--          (SemanticSearchHandler / SimilarSearchHandler) continues to
--          query a column named `embedding` — no application change is
--          required for the rename.
--
-- Background: discussion #107 documents the A/B comparison that drove this
--             cutover. LaBSE +7 of 20 in Latin, +2.5 in Italian, ~tie in
--             English. Issue #71 (model commit pinning) is resolved by the
--             EMBEDDING_MODEL_REVISION env var in the embedding service and
--             scripts/compute_embeddings.py.
--
-- Precondition: `embedding_labse` MUST be fully populated on every version
--               table before this migration is applied. The DO block below
--               aborts the migration if any row's `embedding_labse` is
--               NULL — keeping it non-destructive on partial state. To
--               populate, run:
--                   EMBEDDING_MODEL=sentence-transformers/LaBSE \
--                       python scripts/compute_embeddings.py \
--                           --column embedding_labse
--               for each version table.
--
-- Idempotent: detects already-applied state via column dimensionality. If
--             `embedding` is already vector(768) and `embedding_labse` is
--             gone, the migration is a clean no-op.

DO $$
DECLARE
    v_sigla         TEXT;
    target_versions TEXT[] := ARRAY['BLPD', 'CEI2008', 'DIVCOM', 'DRB',
                                    'LUZZI', 'NABRE', 'NVBSE', 'VGCL'];
    embedding_typmod INT;
    labse_typmod     INT;
    null_count       BIGINT;
    new_idx_name     TEXT;
    labse_idx_name   TEXT;
    old_idx_name     TEXT;
BEGIN
    FOREACH v_sigla IN ARRAY target_versions LOOP
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = v_sigla
        ) THEN
            RAISE NOTICE 'Skipping %: table does not exist', v_sigla;
            CONTINUE;
        END IF;

        SELECT atttypmod INTO embedding_typmod
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relname = v_sigla AND a.attname = 'embedding';

        SELECT atttypmod INTO labse_typmod
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relname = v_sigla AND a.attname = 'embedding_labse';

        -- Idempotency: already cut over (vector(768) embedding, no embedding_labse).
        IF embedding_typmod = 768 AND labse_typmod IS NULL THEN
            RAISE NOTICE '%: already cut over (embedding is vector(768)), skipping', v_sigla;
            CONTINUE;
        END IF;

        IF labse_typmod IS NULL THEN
            RAISE EXCEPTION '%: embedding_labse column missing — run migration 012 + 013 first', v_sigla;
        END IF;

        -- Precondition: full coverage on embedding_labse.
        EXECUTE format('SELECT COUNT(*) FROM %I WHERE embedding_labse IS NULL', v_sigla)
            INTO null_count;
        IF null_count > 0 THEN
            RAISE EXCEPTION
                '%: embedding_labse has % unpopulated rows — run compute_embeddings.py --column embedding_labse first',
                v_sigla, null_count;
        END IF;

        -- Drop the legacy MiniLM index + column.
        old_idx_name := v_sigla || '_embedding_hnsw';
        IF EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'public' AND indexname = old_idx_name
        ) THEN
            EXECUTE format('DROP INDEX %I', old_idx_name);
        END IF;

        IF embedding_typmod IS NOT NULL THEN
            EXECUTE format('ALTER TABLE %I DROP COLUMN embedding', v_sigla);
        END IF;

        -- Promote embedding_labse → embedding (and its HNSW index).
        EXECUTE format('ALTER TABLE %I RENAME COLUMN embedding_labse TO embedding', v_sigla);

        labse_idx_name := v_sigla || '_embedding_labse_hnsw';
        new_idx_name   := v_sigla || '_embedding_hnsw';
        IF EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'public' AND indexname = labse_idx_name
        ) THEN
            EXECUTE format('ALTER INDEX %I RENAME TO %I', labse_idx_name, new_idx_name);
        ELSIF NOT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'public' AND indexname = new_idx_name
        ) THEN
            -- Defensive fallback: if for any reason migrations 012/013 didn't
            -- leave behind a labse-named HNSW index, build one from scratch
            -- on the now-renamed `embedding` column so semantic search keeps
            -- the ANN fast path.
            EXECUTE format(
                'CREATE INDEX %I ON %I USING hnsw (embedding vector_cosine_ops)',
                new_idx_name, v_sigla
            );
        END IF;

        RAISE NOTICE '%: cutover complete (embedding is now vector(768), LaBSE)', v_sigla;
    END LOOP;
END $$;

-- Reconcile embedding_metadata: the composite PK is (version_sigla, column_name).
-- After the rename, every metadata row whose column_name was 'embedding_labse'
-- now points at the column simply named 'embedding'. Roll those rows into the
-- canonical 'embedding' slot, replacing any stale MiniLM metadata.
DO $$
DECLARE
    labse_count INT;
BEGIN
    SELECT COUNT(*) INTO labse_count
    FROM embedding_metadata WHERE column_name = 'embedding_labse';
    IF labse_count = 0 THEN
        RAISE NOTICE 'embedding_metadata: no embedding_labse rows to promote';
        RETURN;
    END IF;

    DELETE FROM embedding_metadata
        WHERE column_name = 'embedding'
          AND version_sigla IN (
              SELECT version_sigla FROM embedding_metadata WHERE column_name = 'embedding_labse'
          );

    UPDATE embedding_metadata
       SET column_name = 'embedding'
     WHERE column_name = 'embedding_labse';

    RAISE NOTICE 'embedding_metadata: promoted % rows from embedding_labse to embedding', labse_count;
END $$;
