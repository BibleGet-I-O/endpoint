# Changelog

All notable changes to the BibleGet I/O endpoint are recorded here.
Entries are grouped by the development branch they landed on. Format
loosely follows Keep-a-Changelog: each release lists Added / Changed /
Deprecated / Fixed / Removed / Security where applicable.

## Unreleased (`development`)

### Changed

- **Minimum PHP raised to 8.4.1; PHPUnit 11 → 13.** Production already
  runs Plesk PHP 8.4. PHPUnit 13 requires PHP ≥ 8.4.1, which is why
  Dependabot's bump (#170) failed on the 8.2/8.3 CI jobs. CI now runs
  PHPCS, PHPStan and coverage on 8.4 and tests on 8.4 and 8.5. Mocks
  without expectations in `SemanticSearchHandlerTest` became stubs
  (PHPUnit 13 notice).

- **LaBSE embedding infrastructure added alongside MiniLM** (cutover
  deferred). Driven by discussion #107: the existing
  `paraphrase-multilingual-MiniLM-L12-v2` model produced surface-token
  matches on Latin (NVBSE) — e.g. *"Ego sum panis vitae"* would return
  *"Respice in me et miserere mei"* because both share the token `me`.
  Across a 20-concept × 3-language probe set:
  - **Latin:** LaBSE +7 of 20 (10/20 → 17/20). Top-K is now thematically
    coherent (e.g. a Latin Beatitudes query returns *Beati X* verses, not
    a random Genesis verse with token overlap).
  - **Italian:** LaBSE +2.5 of 20.
  - **English:** roughly neutral (LaBSE recovers MiniLM's token-noise
    misses; loses a small number of highly-compressed metaphorical verses).

  The evaluation is convincing but not yet enacted as a cutover. This PR
  adds a parallel `embedding_labse vector(768)` column to every version
  table and ships the tooling to populate it (`compute_embeddings.py
  --column embedding_labse`, `sync_labse_to_live.sh`). The original
  `embedding vector(384)` MiniLM column stays untouched, fully populated,
  and queryable. The destructive cutover that would drop the MiniLM
  column lives as a *future* migration at
  `docs/future-migrations/014-cutover-embedding-to-labse.sql`; promoting
  it back into `migrations/` is an explicit decision deferred until the
  comparison UI ([BibleGet-I-O/bibleget-search-ui](https://github.com/BibleGet-I-O/bibleget-search-ui))
  validates LaBSE in the wild and the API gains a `model=` parameter.

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
  entrypoint convention. The CI `test` job's Postgres service container
  was bumped to `pgvector/pgvector:pg18` as well, so tests run against
  the same major version as live.

- **GPU passthrough on the embedding service.** `docker-compose.yml` now
  reserves an NVIDIA GPU for the embedding container (`deploy.resources.
  reservations.devices`). On a CPU-only host the embedding service won't
  start with the default compose; either remove the deploy block locally
  or bring up only `postgres` + `app`. LaBSE inference on a 4070-class
  GPU is ~10× faster than CPU.

### Added

- **`text_plain` and `title_position` on every edition table** (#123,
  `migrations/019-add-text-plain-and-title-position.sql`).
  - `text` stays the formatted, markup-bearing text for rendering.
    `text_plain` is derived from it by the new `bibleget_plain_text()`, a
    whitelist normalizer. It removes inline tags (`L<sm>ORD</sm>` → `LORD`),
    turns line/block tags and `<br/>` into spaces, collapses whitespace, and
    leaves unknown angle-bracket content (VGCL's `<Aleph>`) alone.
  - The `text_hash` trigger now sets `text_plain` and hashes it, so the two
    columns cannot drift.
  - Keyword search (all three modes) now matches against `text_plain`, with a
    new `<T>_text_plain_fts` index. Words split by markup are now found, and
    tag names are no longer searchable.
  - `compute_embeddings.py` embeds `text_plain`.
  - Keyword, semantic and similar search results gain a `text_plain` field.
    Quote results gain `text_plain` and `title_position`.
  - `title_position` places a verse's headings inside the verse: a 0-based
    character offset into `text` (tags count), or NULL for before the verse.
    A `<T>_title_position_check` constraint keeps it on a token boundary:
    never splitting a word, never inside a tag, never at either end.
  - Embedding freshness carries over where `text` and `text_plain` differ
    only in whitespace. MiniLM and LaBSE produce bit-identical vectors for
    those, as verified. Only rows whose markup changes the words go stale,
    in practice NABRE's tagged verses.
- **LUZZI formatted text** (`migrations/020-luzzi-formatted-text.sql`).
  `luzzi.txt`'s `<br />` poetic lines and `<i>` are restored into `text` in
  the NABRE tag set the clients already render: `<pof>`/`<po>`/`<pol>`
  lines joined by `\n`, and untagged lead-ins and superscriptions. The
  4 mid-verse headings get their `title_position`. Every UPDATE asserts that
  the formatted text normalizes back to exactly the current plain text, so
  LUZZI's `text_plain`, hashes and embeddings are unchanged.
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
  the column to BLPD, DIVCOM, DRB, LUZZI, VGCL so every version table
  has a LaBSE column available alongside MiniLM.
- `scripts/sync_labse_to_live.sh` — pushes locally-computed LaBSE
  vectors up to the live PG via SSH+psql. Per-table transaction with
  COPY into a temp table then UPDATE, SSH keepalives + compression so
  long-running remote UPDATEs don't get TCP-dropped, idempotent across
  retries.
- `docs/future-migrations/014-cutover-embedding-to-labse.sql` —
  drafted-but-deferred destructive cutover that drops the MiniLM
  column. Kept outside the active migration chain so applying
  migrations doesn't accidentally retire MiniLM. See
  `docs/future-migrations/README.md` for the promotion-back procedure.

### Fixed

- **LUZZI verses truncated by the original import** (2,494 rows). The
  importer of the original source `luzzi.txt` split each verse on its
  `<br />` poetic line breaks and kept only the first segment, and where a
  verse did not end in `<br />` it dropped the tags without a space. Result:
  2,453 Psalm verses truncated or joined (Ps 1:1 kept one line of three;
  Ps 23:1 read only *"Salmo di Davide."*; *"m'accompagnerannotutti"*), plus
  41 verses in other books: 37 joined (*"disse:«Balak"*) and 4 truncated
  (Num 24:20, Prov 30:1, Prov 31:1, Amos 1:2, which read only *"Egli
  disse:"*). `migrations/015-restore-luzzi-verses-from-source.sql`, generated
  by `scripts/repair_luzzi_from_source.py`, rebuilds those rows from
  `luzzi.txt` using the importer's own conversion: drop the `^` heading,
  strip `<i>`, `<br />` → space, decode entities. That conversion reproduces
  every undamaged row exactly. Each UPDATE is guarded on a known damaged
  text, so the migration is idempotent. Applied on 2026-11-14 to live
  PostgreSQL (`bibleget_dev`) and the legacy v3 MariaDB. All 31,094 LUZZI
  rows in both now match the source, and on live PG the MiniLM and LaBSE
  embeddings of the changed rows were recomputed. On other databases, only
  the default column `embedding_labse` tracks `text_hash`; the MiniLM
  `embedding` column is only filled where it is NULL. So, *before*
  re-embedding, NULL `embedding` where `text_hash != embedded_text_hash`
  (the LaBSE run resets the hash). Then run `compute_embeddings.py --version
  LUZZI` (LaBSE: the stale rows) and `compute_embeddings.py --version LUZZI
  --column embedding` (MiniLM: the NULLed rows).
  The same importer also discarded everything before the `^` heading
  marker. In 4 verses where the heading falls mid-verse (2 Kgs 25:21,
  Neh 7:73, John 12:36, Acts 9:19), that was the first half of the verse,
  so 015 now covers 2,498 rows.
- **LUZZI section headings stored in `title1`–`title3`.** The 1,651 headings
  in `luzzi.txt` (e.g. *"La creazione"*, *"LIBRO PRIMO (Salmi 1 a 41)"*)
  were discarded by the 2015 import. `migrations/018-luzzi-section-headings.sql`
  stores them by level: each `<br />`-separated part is a level, outermost
  first, with a parenthetical range or parallel joined to its heading.
  1,639 are single-level and land in `title1`. `migrations/017-widen-title-columns.sql`
  widens `title1`–`title3` from `VARCHAR(100)` to `VARCHAR(255)` on every
  edition table (the longest LUZZI heading is 203 characters), and
  `production-schema.sql` is updated to match. No client reads the title
  fields yet; they are returned on every verse row.
- **LUZZI provenance recorded.** `luzzi.txt` turned out to be a dump of
  LaParola.net's "Luzzi/Riveduta" text, which follows the Società Biblica
  Britannica e Forestiera printing (1925) as reproduced in the Libreria Sacre
  Scritture edition (Roma 1990, ISBN 88-237-1051-0).
  `migrations/016-luzzi-provenance-notes.sql` fills the previously empty
  `versions_available.notes` for LUZZI. It is applied to live PG and the v3
  MariaDB.

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

### Operator procedure: populate `embedding_labse` on live (side-by-side state)

The live server is CPU-only and LaBSE re-embedding ~250k verses on CPU
would take many hours. The supported flow precomputes embeddings on a
GPU-equipped local box and syncs them back. After this procedure runs,
live has both `embedding` (vector(384) MiniLM, untouched) and
`embedding_labse` (vector(768) LaBSE, freshly populated) on every
version table — comparison-ready, no cutover yet.

```sh
# ── LOCAL GPU BOX ────────────────────────────────────────────────────
# 1. Local schema mirrors live's pre-LaBSE state plus migrations 012+013.
docker compose up -d postgres
docker compose exec -T postgres psql ... -f migrations/production-schema.sql
docker compose exec -T postgres psql ... \
    -c "INSERT INTO versions_available (sigla) VALUES \
        ('BLPD'),('CEI2008'),('DIVCOM'),('DRB'), \
        ('LUZZI'),('NABRE'),('NVBSE'),('VGCL');"
for m in 003 004 005 012 013; do
    docker compose exec -T postgres psql ... -f migrations/${m}-*.sql
done

# 2. Pull verse text from live → local for all 8 versions.
LIVE_DB_HOST=... LIVE_DB_NAME=... LIVE_DB_USER=... LIVE_DB_PASS=... \
    VERSIONS="NABRE CEI2008 NVBSE BLPD DIVCOM DRB LUZZI VGCL" \
    ./scripts/dump_versions_to_local.sh

# 3. Build the LaBSE-pinned embedding image. The defaults in
#    docker-compose.yml + the Dockerfile already pin LaBSE @
#    836121a0533e5664b21c7aacc5d22951f2b8b25b, so a bare build works.
docker compose build embedding

# 4. Compute LaBSE for all 8 versions on the local GPU. Expect ~25 min
#    wall time on a 4070-class GPU at batch size 128.
docker run --rm --gpus all --network endpoint_default --user root \
    -e DB_HOST=postgres -e DB_NAME=bibleget \
    -e DB_USER=bibleget -e DB_PASS=bibleget \
    -e EMBEDDING_MODEL=sentence-transformers/LaBSE \
    -v "$(pwd)/scripts:/app/scripts:ro" \
    endpoint-embedding \
    bash -c "pip install --quiet --break-system-packages psycopg2-binary python-dotenv \
        && python /app/scripts/compute_embeddings.py --column embedding_labse --batch-size 128"

# ── LIVE SERVER ──────────────────────────────────────────────────────
# 5. Live PG: add the LaBSE column on all 8 version tables. Empty
#    column — purely additive, nothing destructive.
psql ... -f migrations/012-add-embedding-labse-columns.sql
psql ... -f migrations/013-add-embedding-labse-remaining-versions.sql

# ── BACK ON THE GPU BOX ──────────────────────────────────────────────
# 6. Push the locally-computed vectors up to live, table by table.
#    SSH keepalives + compression keep the long-running remote UPDATEs
#    from dropping. Per-table transaction; safe to retry.
LIVE_SSH_HOST=ubuntu@catholicdigitalcommons.org \
    ./scripts/sync_labse_to_live.sh
```

After step 6, live carries both embedding columns on every verse table
and `embedding_metadata` records both `(version_sigla, 'embedding')` and
`(version_sigla, 'embedding_labse')` rows. The API still queries the
`embedding` column (MiniLM); LaBSE waits for a follow-up that wires a
`model=` parameter into `/v3/search/semantic` and
`/v3/search/similar` plus a parallel embedding service on live.

The eventual cutover step — drop the MiniLM column, rename
`embedding_labse → embedding` — is drafted at
`docs/future-migrations/014-cutover-embedding-to-labse.sql`. Apply only
when there's an explicit decision to retire MiniLM (the comparison UI
results from search.bibleget.io are likely the deciding signal).
