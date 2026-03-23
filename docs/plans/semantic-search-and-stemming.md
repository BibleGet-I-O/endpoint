# Plan: Semantic Search, Stemming, and Similar Passages

**Status**: Proposed
**Date**: 2026-03-23
**Related Issue**: https://github.com/BibleGet-I-O/endpoint/issues/62

## Motivation

The current `/v3/search` endpoint uses PostgreSQL's `tsvector`/`tsquery` with the `simple` dictionary, which performs exact token matching with no stemming or semantic understanding. This means:

- Searching "forgive" won't match "forgiveness", "forgiven", or "forgiving"
- Users cannot search by concept (e.g., "passages about redemption through suffering")
- There is no way to find thematically related passages given a known verse

Concordance-style stemming, semantic (embedding-based) search, and a "find similar passages" feature would significantly improve the search experience.

## Endpoint Design

The current single `/v3/search` endpoint is replaced by three dedicated sub-endpoints, each serving a fundamentally different input/output shape. The existing `/v3/search` is retained as a backward-compatible alias.

### Route Table

| Route | Handler | Description |
|---|---|---|
| `/v3/search/keyword` | `KeywordSearchHandler` | Text-based search: full-text, exact match, boolean |
| `/v3/search/semantic` | `SemanticSearchHandler` | Conceptual search via embeddings |
| `/v3/search/similar` | `SimilarSearchHandler` | Find thematically related passages by verse reference |
| `/v3/search` | *(alias)* | Backward-compatible alias for `/v3/search/keyword` |

### `/v3/search/keyword` — Text-Based Search

Consolidates all keyword-based search modes under one endpoint with a `match` parameter:

| Parameter | Type | Default | Description |
|---|---|---|---|
| `keyword` | string | *(required)* | Search term(s) |
| `version` | string | *(required)* | Bible version sigla |
| `match` | string | `fulltext` | Matching strategy: `fulltext`, `exact`, `boolean` |

**Match modes:**

- **`fulltext`** (default) — Language-aware FTS with stemming. "forgive" matches "forgiveness", "forgiven", "forgiving". Falls back to `simple` dictionary for languages without a PostgreSQL stemmer.
- **`exact`** — Regex word-boundary match (current `exactmatch=true` behavior). No minimum keyword length.
- **`boolean`** — FTS with explicit boolean operators. Supports `AND`, `OR`, `NOT`, and quoted phrases for exact phrase matching. Uses PostgreSQL's `to_tsquery()` with language-aware stemming.

Stemming is not a separate mode — it is the default behavior of `fulltext` once language-aware dictionaries are configured (Phase 1).

**Backward compatibility:** The existing `/v3/search` endpoint is retained as an alias. The legacy `exactmatch=true` parameter maps to `match=exact`.

### `/v3/search/semantic` — Conceptual Search

Embedding-based similarity search for finding passages by concept rather than keywords.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `query` | string | *(required)* | Natural language query (e.g., "passages about forgiveness after betrayal") |
| `version` | string | *(required)* | Bible version sigla |
| `limit` | int | `20` | Maximum results to return |
| `threshold` | float | `0.0` | Minimum similarity score (0.0–1.0) to include in results |

The PHP endpoint calls the Python embedding microservice to vectorize the query, then runs a pgvector cosine similarity search. Clients do not need to compute vectors — embedding is handled server-side.

Response includes a `similarity` field (0.0–1.0) per result alongside the standard verse fields.

### `/v3/search/similar` — Find Related Passages

Given a verse reference, returns thematically related passages using pre-computed embeddings. No Python call needed at query time.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `reference` | string | *(required)* | Verse reference (e.g., `Gen1:1`) |
| `version` | string | *(required)* | Bible version sigla |
| `limit` | int | `10` | Maximum results to return |
| `crossversion` | bool | `false` | Search across all available versions |

When `crossversion=true`, results are grouped by version.

## Phase 1 — Language-Aware Stemming

**Goal**: Replace the `simple` text search dictionary with language-specific PostgreSQL dictionaries to enable concordance-style stemming in `/v3/search/keyword`.

**No new dependencies** — this uses built-in PostgreSQL functionality.

### Changes

1. **Add `ts_language` column to `versions_available`**
   - Stores the PostgreSQL text search configuration name (e.g., `english`, `french`, `italian`, `simple`)
   - Default to `simple` for languages PostgreSQL doesn't have a stemmer for
   - PostgreSQL ships with dictionaries for: danish, dutch, english, finnish, french, german, hungarian, italian, norwegian, portuguese, romanian, russian, spanish, swedish, turkish

2. **Refactor search into `KeywordSearchHandler`**
   - New handler at `/v3/search/keyword` with `match` parameter (`fulltext`, `exact`, `boolean`)
   - Look up the `ts_language` for the requested version
   - Use it dynamically in `fulltext` and `boolean` mode queries:
     ```sql
     to_tsvector($lang, text) @@ websearch_to_tsquery($lang, ?)
     ```
   - `exact` mode uses regex word-boundary match (unchanged from current behavior)
   - Retain `/v3/search` as a backward-compatible alias mapping `exactmatch` → `match`

3. **Rebuild GIN indexes per version**
   - The GIN index must match the text search config used in queries
   - Migration script to drop and recreate indexes with the correct language config:
     ```sql
     CREATE INDEX ON "{VERSION}" USING GIN (to_tsvector('{lang}', text));
     ```

4. **Fallback behavior**
   - If `ts_language` is NULL or `simple`, behavior is unchanged from current
   - `exact` mode is unaffected — it uses regex, not tsvector

### Testing

- Verify stemming works: searching "forgive" returns rows containing "forgiveness"
- Verify `simple` fallback for unsupported languages
- Verify `exact` mode is unaffected
- Verify `boolean` mode supports AND/OR/NOT and quoted phrases
- Verify backward compatibility of `/v3/search` alias
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

**Goal**: Implement `/v3/search/semantic` using the embeddings infrastructure from Phase 2.

### Changes

1. **New `SemanticSearchHandler`**
   - Registered in `Router.php` at `/v3/search/semantic`
   - Accepts `query`, `version`, `limit`, `threshold` parameters
   - Calls Python microservice to embed the user's query
   - Executes pgvector similarity query:
     ```sql
     SELECT *, 1 - (embedding <=> $query_vector) AS similarity
     FROM "{VERSION}"
     WHERE 1 - (embedding <=> $query_vector) >= $threshold
     ORDER BY embedding <=> $query_vector
     LIMIT $limit;
     ```

2. **Response format**
   - Same structure as keyword search results
   - Adds a `similarity` field (0.0–1.0) to each result

## Phase 4 — Similar Passages

**Goal**: Implement `/v3/search/similar` for finding thematically related passages by verse reference.

### Changes

1. **New `SimilarSearchHandler`**
   - Registered in `Router.php` at `/v3/search/similar`
   - Accepts `reference`, `version`, `limit`, `crossversion` parameters

2. **Implementation**
   - Parse the verse reference using existing `QueryValidator` notation parsing
   - Look up the embedding for the given verse
   - Run nearest-neighbor search — no Python service call needed at query time:
     ```sql
     SELECT *, 1 - (embedding <=> (
       SELECT embedding FROM "{VERSION}" WHERE book=$book AND chapter=$chapter AND verse=$verse
     )) AS similarity
     FROM "{VERSION}"
     ORDER BY embedding <=> (
       SELECT embedding FROM "{VERSION}" WHERE book=$book AND chapter=$chapter AND verse=$verse
     )
     LIMIT $limit;
     ```

3. **Cross-version similar passages**
   - When `crossversion=true`, query across multiple version tables
   - Return results grouped by version

## Open Questions

- **Embedding storage**: separate `verse_embeddings` table vs. column on each version table? A separate table allows a single HNSW index but requires joins; per-table columns are simpler but multiply the number of indexes.
- **Model updates**: when the embedding model is updated, all embeddings need recomputation. A `model_version` column or metadata table could track this.
- **Query result limit**: what's a sensible default and maximum for semantic search results?
- **Hybrid search**: should a future phase support a combined mode that merges keyword and semantic results (reciprocal rank fusion)?
