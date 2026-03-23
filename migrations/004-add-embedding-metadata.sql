-- Migration: Add embedding_metadata table for model version tracking
-- Purpose: Track which embedding model was used to compute stored embeddings
--          per Bible version. Enables detection of model/embedding mismatches
--          when the model is upgraded and embeddings need recomputation.

CREATE TABLE IF NOT EXISTS embedding_metadata (
    version_sigla VARCHAR(20) NOT NULL PRIMARY KEY REFERENCES versions_available(sigla) ON DELETE CASCADE,
    model_name    VARCHAR(100) NOT NULL,
    model_version VARCHAR(50) NOT NULL DEFAULT '',
    dimensions    INT NOT NULL CHECK (dimensions > 0),
    computed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
