#!/usr/bin/env python3
"""Batch-compute verse embeddings for BibleGet semantic search.

Connects to PostgreSQL, loads verse texts per Bible version,
computes embeddings via sentence-transformers, and writes them
back to the chosen embedding column.

Usage:
    python compute_embeddings.py                                # new + changed verses only
    python compute_embeddings.py --force                        # recompute all embeddings
    python compute_embeddings.py --version NABRE                # only process one version
    python compute_embeddings.py --batch-size 256               # custom batch size
    python compute_embeddings.py --check                        # report stale versions
    python compute_embeddings.py --column embedding_labse       # write to the A/B column

Modes:
    Default  — computes embeddings for verses that have no embedding OR whose
               text changed since the last embedding (text_hash != embedded_text_hash,
               primary column only).
    --force  — recomputes all embeddings regardless of staleness.
    --check  — reports which versions have stale embeddings without recomputing.

Environment variables:
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
    EMBEDDING_MODEL          - sentence-transformers model name
                               (default: sentence-transformers/LaBSE)
    EMBEDDING_MODEL_REVISION - pinned Hugging Face commit for reproducibility
                               (default: see MODEL_REVISION constant — see #71)
    EMBEDDING_MODEL_VERSION  - free-form version tag stored in metadata
                               (default: 1.0)
    EMBEDDING_DIM            - vector dimensionality; if unset, derived from
                               the loaded model. Set this only to assert a
                               specific dimension at startup.

A `.env` file at the project root is loaded automatically if python-dotenv
is installed, so the same configuration drives both this script and the
PHP side.
"""

import argparse
import os
import sys
import time

import psycopg2
from psycopg2 import sql
from sentence_transformers import SentenceTransformer

try:
    from dotenv import load_dotenv

    load_dotenv()
except ImportError:
    pass

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "sentence-transformers/LaBSE")
MODEL_REVISION = os.environ.get(
    "EMBEDDING_MODEL_REVISION",
    "836121a0533e5664b21c7aacc5d22951f2b8b25b",
)
MODEL_VERSION = os.environ.get("EMBEDDING_MODEL_VERSION", "1.0")
_EMBEDDING_DIM_ENV = os.environ.get("EMBEDDING_DIM")
EMBEDDING_DIM_OVERRIDE = int(_EMBEDDING_DIM_ENV) if _EMBEDDING_DIM_ENV else None
DEFAULT_BATCH_SIZE = 128
# Aligned with the LaBSE default in MODEL_NAME above: the LaBSE-pinned vector
# lives in the `embedding_labse vector(768)` column. The legacy MiniLM column
# `embedding vector(384)` is targetable explicitly via --column embedding once
# the cutover migration in docs/future-migrations/ promotes embedding_labse
# back to `embedding`.
DEFAULT_COLUMN = "embedding_labse"


def get_connection():
    return psycopg2.connect(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=os.environ.get("DB_PORT", "5432"),
        dbname=os.environ.get("DB_NAME", "bibleget"),
        user=os.environ.get("DB_USER", "bibleget"),
        password=os.environ.get("DB_PASS", "bibleget"),
    )


def has_text_hash_column(conn, version):
    """Check if the version table has the text_hash column (migration 005)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT 1 FROM information_schema.columns "
            "WHERE table_schema = 'public' AND table_name = %s AND column_name = 'text_hash'",
            (version,),
        )
        return cur.fetchone() is not None


def has_column(conn, version, column):
    """Check if the version table has the given embedding column."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT 1 FROM information_schema.columns "
            "WHERE table_schema = 'public' AND table_name = %s AND column_name = %s",
            (version, column),
        )
        return cur.fetchone() is not None


def get_versions(conn, single_version=None):
    """Return list of version sigla to process."""
    with conn.cursor() as cur:
        if single_version:
            cur.execute(
                "SELECT sigla FROM versions_available WHERE sigla = %s",
                (single_version.upper(),),
            )
        else:
            cur.execute("SELECT sigla FROM versions_available ORDER BY sigla")
        return [row[0] for row in cur.fetchall()]


def load_verses(conn, version, column, force=False):
    """Load verses that need embeddings computed for the given column.

    Default mode (primary column only): verses with no embedding OR whose
    text changed since last embedding.
    For non-primary columns (e.g. embedding_labse): only NULL rows are
    selected — text-hash staleness is tracked only for the primary column.
    Force mode: all verses.
    """
    track_hash = column == DEFAULT_COLUMN and has_text_hash_column(conn, version)
    table_ident = sql.Identifier(version)
    col_ident = sql.Identifier(column)

    if force:
        query = sql.SQL('SELECT "verseID", text FROM {} ORDER BY "verseID"').format(table_ident)
    elif track_hash:
        query = sql.SQL(
            'SELECT "verseID", text FROM {table} '
            "WHERE {col} IS NULL "
            "OR embedded_text_hash IS NULL "
            "OR text_hash != embedded_text_hash "
            'ORDER BY "verseID"'
        ).format(table=table_ident, col=col_ident)
    else:
        query = sql.SQL(
            'SELECT "verseID", text FROM {table} WHERE {col} IS NULL ORDER BY "verseID"'
        ).format(table=table_ident, col=col_ident)

    with conn.cursor() as cur:
        cur.execute(query)
        return cur.fetchall()


def write_embeddings(conn, version, column, verse_ids, embeddings):
    """Write embeddings back to the database in a single transaction.

    For the primary column, also snapshots the current text_hash into
    embedded_text_hash so we can detect future text changes. Non-primary
    columns share the same source text and don't track their own hash.
    """
    track_hash = column == DEFAULT_COLUMN and has_text_hash_column(conn, version)
    table_ident = sql.Identifier(version)
    col_ident = sql.Identifier(column)
    if track_hash:
        query = sql.SQL(
            "UPDATE {table} SET {col} = %s::vector, "
            'embedded_text_hash = text_hash WHERE "verseID" = %s'
        ).format(table=table_ident, col=col_ident)
    else:
        query = sql.SQL(
            'UPDATE {table} SET {col} = %s::vector WHERE "verseID" = %s'
        ).format(table=table_ident, col=col_ident)

    with conn.cursor() as cur:
        cur.executemany(
            query,
            [
                ("[" + ",".join(str(x) for x in embedding.tolist()) + "]", verse_id)
                for verse_id, embedding in zip(verse_ids, embeddings, strict=True)
            ],
        )
    conn.commit()


def compute_content_xor(conn, version):
    """Compute the XOR of all text_hash values for a version.

    Returns the aggregate as bytes, or None if text_hash is not available.
    The fingerprint is over verse text, independent of which embedding
    column is being filled.
    """
    if not has_text_hash_column(conn, version):
        return None
    with conn.cursor() as cur:
        # nosemgrep
        cur.execute(
            sql.SQL("SELECT bytea_xor_agg(text_hash) FROM {}").format(
                sql.Identifier(version)
            )
        )
        row = cur.fetchone()
        return row[0] if row else None


def check_staleness(conn, versions, column):
    """Report which versions have stale embeddings for the given column."""
    stale = []
    for version in versions:
        if not has_column(conn, version, column):
            print(f"  {version}: column {column!r} does not exist on this version")
            continue

        track_hash = column == DEFAULT_COLUMN and has_text_hash_column(conn, version)
        if not track_hash:
            count_query = sql.SQL(
                "SELECT "
                "  COUNT(*) FILTER (WHERE {col} IS NULL) AS new_verses, "
                "  0 AS changed_verses, "
                "  COUNT(*) AS total "
                "FROM {table}"
            ).format(table=sql.Identifier(version), col=sql.Identifier(column))
        else:
            count_query = sql.SQL(
                "SELECT "
                "  COUNT(*) FILTER (WHERE {col} IS NULL) AS new_verses, "
                "  COUNT(*) FILTER (WHERE {col} IS NOT NULL AND "
                "    (embedded_text_hash IS NULL OR text_hash != embedded_text_hash)) AS changed_verses, "
                "  COUNT(*) AS total "
                "FROM {table}"
            ).format(table=sql.Identifier(version), col=sql.Identifier(column))

        with conn.cursor() as cur:
            cur.execute(count_query)
            row = cur.fetchone()
            new_count, changed_count, total = row

            current_xor = compute_content_xor(conn, version)
            cur.execute(
                "SELECT content_xor FROM embedding_metadata "
                "WHERE version_sigla = %s AND column_name = %s",
                (version, column),
            )
            meta = cur.fetchone()
            stored_xor = meta[0] if meta else None
            xor_match = current_xor == stored_xor if (current_xor and stored_xor) else None

            if new_count > 0 or changed_count > 0:
                stale.append(version)
                print(
                    f"  {version}: STALE — {new_count} new, {changed_count} changed "
                    f"(of {total} total)"
                )
            elif xor_match is False:
                stale.append(version)
                print(f"  {version}: STALE — content_xor mismatch (of {total} total)")
                # Known asymmetry: for non-primary columns (e.g. embedding_labse)
                # the loader at load_verses() only selects rows where the column
                # is NULL, because `embedded_text_hash` is the snapshot at the
                # time the PRIMARY column was last written — using it as the
                # staleness predicate for a secondary column would conflate
                # state. So an xor mismatch on a non-primary column is detected
                # here but the default mode won't remediate. Re-run with
                # --force to recompute the whole table for that column. Fixing
                # this cleanly needs a per-column hash (e.g.
                # embedded_text_hash_labse) — TODO when there's a third
                # embedding column or more.
            else:
                print(f"  {version}: up to date ({total} verses)")

    return stale


def process_version(conn, model, version, column, dim, force=False, batch_size=DEFAULT_BATCH_SIZE):
    """Compute and store embeddings for all verses in a version."""
    if not has_column(conn, version, column):
        print(f"  {version}: column {column!r} does not exist — skipping")
        return 0

    verses = load_verses(conn, version, column, force=force)
    if not verses:
        print(f"  {version}: no verses to process (all up to date)")
        return 0

    print(f"  {version}: computing embeddings for {len(verses)} verses → {column}")
    verse_ids = [v[0] for v in verses]
    texts = [v[1] for v in verses]

    total_written = 0
    for i in range(0, len(texts), batch_size):
        batch_texts = texts[i : i + batch_size]
        batch_ids = verse_ids[i : i + batch_size]
        embeddings = model.encode(batch_texts, show_progress_bar=False)
        write_embeddings(conn, version, column, batch_ids, embeddings)
        total_written += len(batch_ids)
        if len(texts) > batch_size:
            print(f"    {total_written}/{len(texts)} written")

    content_xor = compute_content_xor(conn, version)
    update_metadata(conn, version, column, MODEL_NAME, dim, content_xor, MODEL_VERSION)

    print(f"  {version}: done ({total_written} embeddings)")
    return total_written


def update_metadata(conn, version, column, model_name, dimensions, content_xor=None, model_version=""):
    """Insert or update the embedding_metadata record for (version, column)."""
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO embedding_metadata
                (version_sigla, column_name, model_name, model_version, dimensions, computed_at, content_xor)
            VALUES (%s, %s, %s, %s, %s, CURRENT_TIMESTAMP, %s)
            ON CONFLICT (version_sigla, column_name) DO UPDATE
                SET model_name = EXCLUDED.model_name,
                    model_version = EXCLUDED.model_version,
                    dimensions = EXCLUDED.dimensions,
                    computed_at = CURRENT_TIMESTAMP,
                    content_xor = EXCLUDED.content_xor
            """,
            (version, column, model_name, model_version, dimensions, content_xor),
        )
    conn.commit()


def main():
    parser = argparse.ArgumentParser(description="Compute verse embeddings for BibleGet")
    parser.add_argument("--force", action="store_true", help="Recompute all embeddings")
    parser.add_argument("--check", action="store_true", help="Report stale versions without recomputing")
    parser.add_argument("--version", type=str, default=None, help="Process a single version")
    parser.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_SIZE, help="Batch size")
    parser.add_argument(
        "--column",
        type=str,
        default=DEFAULT_COLUMN,
        help=f"Target embedding column (default: {DEFAULT_COLUMN}). "
        "Use embedding_labse for the LaBSE A/B experiment.",
    )
    args = parser.parse_args()

    if args.batch_size < 1:
        print("Error: --batch-size must be a positive integer.", file=sys.stderr)
        sys.exit(1)

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT 1 FROM pg_extension WHERE extname = 'vector'")
            if cur.fetchone() is None:
                print(
                    "Error: pgvector extension is not installed.\n"
                    "Run the database migrations (migrations/003-add-pgvector-embeddings.sql) first.",
                    file=sys.stderr,
                )
                sys.exit(1)

        versions = get_versions(conn, args.version)
        if not versions:
            print("No versions found to process.")
            sys.exit(1)

        if args.check:
            print(
                f"Checking {len(versions)} version(s) for stale embeddings "
                f"in column {args.column!r}:"
            )
            stale = check_staleness(conn, versions, args.column)
            if stale:
                print(f"\n{len(stale)} version(s) need recomputation: {', '.join(stale)}")
                print("Run without --check to recompute.")
            else:
                print("\nAll versions are up to date.")
            return

        print(f"Loading model: {MODEL_NAME} @ {MODEL_REVISION[:8]}")
        start = time.time()
        model = SentenceTransformer(MODEL_NAME, revision=MODEL_REVISION)
        load_secs = time.time() - start
        print(f"Model loaded in {load_secs:.1f}s")

        actual_dim = model.get_sentence_embedding_dimension()
        if EMBEDDING_DIM_OVERRIDE is not None and EMBEDDING_DIM_OVERRIDE != actual_dim:
            print(
                f"Error: EMBEDDING_DIM={EMBEDDING_DIM_OVERRIDE} does not match "
                f"the loaded model's dimension ({actual_dim}).",
                file=sys.stderr,
            )
            sys.exit(1)
        dim = actual_dim
        print(f"Embedding dimension: {dim}")
        print(f"Target column: {args.column}")

        print(f"Processing {len(versions)} version(s): {', '.join(versions)}")
        total = 0
        start = time.time()

        for version in versions:
            total += process_version(
                conn,
                model,
                version,
                args.column,
                dim,
                force=args.force,
                batch_size=args.batch_size,
            )

        elapsed = time.time() - start
        print(f"\nDone: {total} embeddings computed in {elapsed:.1f}s")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
