# Plan: Semantic Search, Stemming, and Similar Passages

**Status**: Proposed
**Date**: 2026-03-23

## Motivation

The current `/v3/search` endpoint uses PostgreSQL's `tsvector`/`tsquery` with the `simple` dictionary, which performs exact token matching with no stemming or semantic understanding. This means:

- Searching "forgive" won't match "forgiveness", "forgiven", or "forgiving"
- Users cannot search by concept (e.g., "passages about redemption through suffering")
- There is no way to find thematically related passages given a known verse

Concordance-style stemming, semantic (embedding-based) search, and a "find similar passages" feature would significantly improve the search experience.

## Phase 1 — Language-Aware Stemming

**Goal**: Replace the `simple` text search dictionary with language-specific PostgreSQL dictionaries to enable concordance-style stemming.

**No new dependencies** — this uses built-in PostgreSQL functionality.

### Changes

1. **Add `ts_language` column to `versions_available`**
   - Stores the PostgreSQL text search configuration name (e.g., `english`, `french`, `italian`, `simple`)
   - Default to `simple` for languages PostgreSQL doesn't have a stemmer for
   - PostgreSQL ships with dictionaries for: danish, dutch, english, finnish, french, german, hungarian, italian, norwegian, portuguese, romanian, russian, spanish, swedish, turkish

2. **Update `SearchHandler::executeSearch()`**
   - Look up the `ts_language` for the requested version
   - Use it dynamically in queries:
     ```sql
     to_tsvector($lang, text) @@ websearch_to_tsquery($lang, ?)
     ```

3. **Rebuild GIN indexes per version**
   - The GIN index must match the text search config used in queries
   - Migration script to drop and recreate indexes with the correct language config:
     ```sql
     CREATE INDEX ON "{VERSION}" USING GIN (to_tsvector('{lang}', text));
     ```

4. **Fallback behavior**
   - If `ts_language` is NULL or `simple`, behavior is unchanged from current
   - Exact match mode (`exactmatch=true`) is unaffected — it uses regex, not tsvector

### Testing

- Verify stemming works: searching "forgive" returns rows containing "forgiveness"
- Verify `simple` fallback for unsupported languages
- Verify exact match mode is unaffected
- Performance benchmarks on GIN index rebuild

## Phase 2 — Embeddings Infrastructure

**Goal**: Set up `pgvector`, a Python embedding service, and batch-compute verse embeddings.

### Dependencies

- PostgreSQL extension: `pgvector`
- Python packages: `sentence-transformers`, `psycopg2` (or `asyncpg`), `fastapi`, `uvicorn`
- Model: `paraphrase-multilingual-MiniLM-L12-v2` (384 dimensions, 50+ languages)
  - Using the multilingual model universally simplifies the architecture vs. maintaining separate models for English and non-English versions

### Changes

1. **Enable `pgvector` in PostgreSQL**
   ```sql
   CREATE EXTENSION IF NOT EXISTS vector;
   ```

2. **Add `embedding` column to each version table**
   ```sql
   ALTER TABLE "{VERSION}" ADD COLUMN embedding vector(384);
   ```
   - Alternatively, a separate `verse_embeddings` table with a foreign key could decouple embeddings from verse data

3. **Python batch job** (`scripts/compute_embeddings.py` or similar)
   - Connects to PostgreSQL
   - For each version table, loads all verse texts
   - Computes embeddings via `SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')`
   - Writes embeddings back to the `embedding` column
   - Idempotent: skips verses that already have embeddings, or re-computes all with a `--force` flag

4. **Python query-embedding microservice**
   - Lightweight FastAPI app exposing `POST /embed` → accepts text, returns 384-dim vector
   - Runs as a sidecar service (Docker Compose) or systemd unit
   - PHP calls this at query time to embed the user's search query

5. **Create HNSW index for approximate nearest neighbor search**
   ```sql
   CREATE INDEX ON "{VERSION}" USING hnsw (embedding vector_cosine_ops);
   ```

### Architecture

```
[Python batch job]        → pre-computes embeddings → writes to pgvector
[Python FastAPI service]  → embeds user queries at request time
[PHP SearchHandler]       → calls Python service for query vector
                          → runs pgvector similarity search in PostgreSQL
```

## Phase 3 — Semantic Search Endpoint

**Goal**: Expose embedding-based search via the existing `/v3/search` endpoint.

### Changes

1. **New `mode` query parameter** on `/v3/search`
   - `mode=keyword` (default) — current tsvector behavior (with Phase 1 stemming)
   - `mode=semantic` — embedding-based similarity search

2. **Semantic search flow in `SearchHandler`**
   - Call Python microservice to embed the user's keyword/phrase
   - Execute pgvector similarity query:
     ```sql
     SELECT *, embedding <=> $query_vector AS distance
     FROM "{VERSION}"
     ORDER BY embedding <=> $query_vector
     LIMIT 20;
     ```
   - Optionally expose a `threshold` parameter to filter by minimum similarity

3. **Response format**
   - Same structure as keyword search results
   - Add a `similarity` field (0.0–1.0) to each result

## Phase 4 — Similar Passages

**Goal**: Given a verse reference, find thematically related passages.

### Changes

1. **New endpoint**: `/v3/similar`
   - Parameters: `reference` (e.g., `Gen1:1`), `version` (e.g., `CEI2008`), `limit` (default 10)
   - Optionally `crossversion=true` to search across all versions

2. **Implementation**
   - Look up the embedding for the given verse
   - Run nearest-neighbor search — no Python service call needed at query time:
     ```sql
     SELECT *, embedding <=> (
       SELECT embedding FROM "{VERSION}" WHERE book=$book AND chapter=$chapter AND verse=$verse
     ) AS distance
     FROM "{VERSION}"
     ORDER BY distance
     LIMIT $limit;
     ```

3. **Cross-version similar passages**
   - When `crossversion=true`, query across multiple version tables
   - Return results grouped by version

4. **New PSR-15 handler**: `SimilarHandler`
   - Registered in `Router.php` at `/v3/similar`
   - Follows the same pattern as `SearchHandler`

## Open Questions

- **Embedding storage**: separate `verse_embeddings` table vs. column on each version table? A separate table allows a single HNSW index but requires joins; per-table columns are simpler but multiply the number of indexes.
- **Model updates**: when the embedding model is updated, all embeddings need recomputation. A `model_version` column or metadata table could track this.
- **Query result limit**: what's a sensible default and maximum for semantic search results?
- **Hybrid search**: should Phase 3 support a combined mode that merges keyword and semantic results (reciprocal rank fusion)?
