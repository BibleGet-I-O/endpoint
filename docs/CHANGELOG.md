# Changelog

All notable changes to the BibleGet I/O endpoint are recorded here.
Entries are grouped by the development branch they landed on. Format
loosely follows Keep-a-Changelog: each release lists Added / Changed /
Deprecated / Fixed / Removed / Security where applicable.

## Unreleased (`development`)

### Changed

- **Semantic search now uses Google's LaBSE embedding model** instead of
  `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`. The change
  is driven by discussion #107: the previous model produced surface-token
  matches on Latin (NVBSE) — e.g. *"Ego sum panis vitae"* would return
  *"Respice in me et miserere mei"* because both share the token `me`.
  Across a 20-concept × 3-language probe set:
  - **Latin:** LaBSE +7 of 20 (10/20 → 17/20). Top-K is now thematically
    coherent (e.g. a Latin Beatitudes query returns *Beati X* verses, not
    a random Genesis verse with token overlap).
  - **Italian:** LaBSE +2.5 of 20.
  - **English:** roughly neutral (LaBSE recovers MiniLM's token-noise
    misses; loses a small number of highly-compressed metaphorical verses).

  The pgvector column on each version table is now `vector(768)`; the old
  `vector(384)` MiniLM data is removed by migration 014. Existing API
  consumers see no surface-level change — the column is still named
  `embedding` and the request/response shape of `/v3/search/semantic` and
  `/v3/search/similar` is unchanged.

- **Score distribution under LaBSE is wider.** MiniLM concentrated top-1
  hits in `[0.85, 0.97]`; LaBSE uses `[0.45, 0.98]` with median ~0.72.
  Clients passing a `threshold=` value calibrated for MiniLM (e.g. 0.6+)
  will see fewer results under LaBSE and should re-tune. A useful quality
  filter under LaBSE is in the `0.3–0.5` range. The default `threshold=0.0`
  is unchanged — no behavior change for clients omitting the parameter.

- **Embedding model revision pinned** (closes #71). The Hugging Face Hub
  commit for `sentence-transformers/LaBSE` is pinned in both the embedding
  microservice and `scripts/compute_embeddings.py` via the
  `EMBEDDING_MODEL_REVISION` environment variable. A `docker compose build`
  now produces deterministic embeddings unless the pinned revision is
  changed explicitly. The current pin is
  `836121a0533e5664b21c7aacc5d22951f2b8b25b` (LaBSE main, March 2025).

- **Local Postgres tracks production PG version.** `docker-compose.yml`
  bumped from `pgvector/pgvector:pg16` to `pgvector/pgvector:pg18`. Live
  is on PG 18 and the version drift was breaking cross-version `pg_dump`
  streams. The mount changed accordingly from
  `/var/lib/postgresql/data` to `/var/lib/postgresql` for the PG 18
  entrypoint convention.

- **GPU passthrough on the embedding service.** `docker-compose.yml` now
  reserves an NVIDIA GPU for the embedding container (`deploy.resources.
  reservations.devices`). On a CPU-only host the embedding service won't
  start with the default compose; either remove the deploy block locally
  or bring up only `postgres` + `app`. LaBSE inference on a 4070-class
  GPU is ~10× faster than CPU.

### Added

- `scripts/compute_embeddings.py` is now column-aware via a `--column` flag
  (default `embedding`), so the same script can fill an A/B column without
  touching the primary one. Stale-row detection (text_hash watermarking)
  only applies to the primary column; non-primary columns recompute on
  NULL or `--force`.
- `scripts/dump_versions_to_local.sh` — pg_dump three version tables from
  a live PG into the local Docker container; truncates first so re-runs
  are clean.
- `scripts/evaluate_labse_vs_minilm.py` — loads two `SentenceTransformer`
  models in-process and prints side-by-side top-K rankings for each probe
  in a JSON probe file.
- `scripts/probe_queries.json` (20 concepts × 3 languages) and
  `scripts/probe_queries_liturgical_followup.json` (10 English-only
  Christological probes) — the seed probe sets that drove the LaBSE
  decision. Extend locally for further validation.
- `migrations/012-add-embedding-labse-columns.sql` — adds
  `embedding_labse vector(768)` to NABRE, CEI2008, NVBSE for the A/B
  evaluation; widens `embedding_metadata` PK to `(version_sigla, column_name)`.
- `migrations/013-add-embedding-labse-remaining-versions.sql` — extends
  the column to BLPD, DIVCOM, DRB, LUZZI, VGCL in preparation for cutover.
- `migrations/014-cutover-embedding-to-labse.sql` — drops the legacy
  MiniLM column, renames `embedding_labse` → `embedding` across all 8
  versions, rebuilds HNSW indexes, reconciles metadata. Asserts full
  coverage before mutating anything, so partial state aborts the
  migration rather than corrupting data.

### Known edge cases under LaBSE

These verses are known to rank correctly under MiniLM but lose the `#1`
slot under LaBSE in the evaluation. None of them disappear entirely —
each is still in the top-5, just not first. The pattern across them is
*highly compressed Pauline / Johannine theological metaphor*:

- **John 1:14** (`And the Word became flesh and dwelt among us` / `E il Verbo
  si fece carne`): LaBSE picks 3 John 1:2 (`propter veritatem quae permanet
  in nobis`) in English and Italian; in Latin John 1:14 is at `#2`.
- **Phil 2:8** (`He humbled himself, becoming obedient to death`): LaBSE
  picks Maccabean martyrdom passages instead.
- **John 1:29 English** (`Behold, the Lamb of God`): LaBSE picks Ps 82:8
  (`Arise, O God, judge the earth`). Latin LaBSE gets it right.
- **Matt 6:9 English** (`Our Father in heaven`): LaBSE picks 2 Sam 7:16
  (`Your house and your kingdom`). Italian and Latin LaBSE get it right.

These are documented for users hitting the regressions and for future
work; an investigation of 10 additional English Christological / liturgical
probes found *no* systematic class-level weakness — LaBSE actually
out-performs MiniLM on that probe set 4–1.

### Migration sequence for production cutover

```sh
# 1. Schema: add embedding_labse on every version table.
psql ... -f migrations/012-add-embedding-labse-columns.sql
psql ... -f migrations/013-add-embedding-labse-remaining-versions.sql

# 2. Rebuild the embedding service image with LaBSE pinned.
EMBEDDING_MODEL=sentence-transformers/LaBSE \
EMBEDDING_MODEL_REVISION=836121a0533e5664b21c7aacc5d22951f2b8b25b \
    docker compose build embedding

# 3. Populate embedding_labse for every version table. GPU recommended;
#    on CPU each version takes ~20 minutes, on a 4070-class GPU ~2 minutes.
EMBEDDING_MODEL=sentence-transformers/LaBSE \
    python scripts/compute_embeddings.py --column embedding_labse

# 4. Schema: swap. Fails with a clear error if any embedding_labse is NULL.
psql ... -f migrations/014-cutover-embedding-to-labse.sql

# 5. Restart the API; it queries `embedding` as before, now LaBSE-backed.
```
