# Future migrations

SQL migrations that are **drafted but deliberately not in the active
migration sequence** under `migrations/`. They are kept here so the
intent and exact SQL are preserved for a future operator action, without
polluting the migration chain that gets applied to every environment.

When the time comes to actually run one of these, move it back into
`migrations/` (renaming if needed to maintain the numeric ordering), and
apply it via the normal `psql -f migrations/NNN-….sql` flow.

## Index

| File | Status | Notes |
|---|---|---|
| `014-cutover-embedding-to-labse.sql` | drafted, **not applied** | Destructive cutover: drops the legacy `embedding vector(384)` (MiniLM) column from every version table and renames `embedding_labse vector(768)` → `embedding`. Held back so MiniLM stays queryable alongside LaBSE while the comparison UI (BibleGet-I-O/bibleget-search-ui) and the API `model=` param mature. Apply only after a decision to retire MiniLM. Validated on a local pre-cutover schema during the experiment (idempotent, asserts full LaBSE coverage before mutating). |
