#!/usr/bin/env python3
"""Batch-compute verse embeddings for BibleGet semantic search.

Connects to PostgreSQL, loads verse texts per Bible version,
computes embeddings via sentence-transformers, and writes them
back to the embedding column.

Usage:
    python compute_embeddings.py                    # new + changed verses only
    python compute_embeddings.py --force            # recompute all embeddings
    python compute_embeddings.py --version NABRE    # only process one version
    python compute_embeddings.py --batch-size 256   # custom batch size
    python compute_embeddings.py --check            # report stale versions (no recomputation)

Modes:
    Default  — computes embeddings for verses that have no embedding OR whose
               text changed since the last embedding (text_hash != embedded_text_hash).
    --force  — recomputes all embeddings regardless of staleness.
    --check  — reports which versions have stale embeddings without recomputing.

Environment variables:
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
"""

import argparse
import os
import sys
import time

import psycopg2
from psycopg2 import sql
from sentence_transformers import SentenceTransformer

MODEL_NAME = "paraphrase-multilingual-MiniLM-L12-v2"
MODEL_VERSION = "1.0"
EMBEDDING_DIM = 384
DEFAULT_BATCH_SIZE = 128


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


def load_verses(conn, version, force=False):
    """Load verses that need embeddings computed.

    Default mode: verses with no embedding OR whose text changed since last embedding.
    Force mode: all verses.
    """
    use_hash = has_text_hash_column(conn, version)
    with conn.cursor() as cur:
        if force:
            cur.execute(
                sql.SQL('SELECT "verseID", text FROM {} ORDER BY "verseID"').format(
                    sql.Identifier(version)
                )
            )
        elif use_hash:
            # New verses (no embedding) OR changed verses (text_hash differs from embedded_text_hash)
            cur.execute(
                sql.SQL(
                    'SELECT "verseID", text FROM {} '
                    "WHERE embedding IS NULL "
                    "OR embedded_text_hash IS NULL "
                    "OR text_hash != embedded_text_hash "
                    'ORDER BY "verseID"'
                ).format(sql.Identifier(version))
            )
        else:
            # Fallback for pre-migration-005 databases
            cur.execute(
                sql.SQL(
                    'SELECT "verseID", text FROM {} WHERE embedding IS NULL ORDER BY "verseID"'
                ).format(sql.Identifier(version))
            )
        return cur.fetchall()


def write_embeddings(conn, version, verse_ids, embeddings):
    """Write embeddings back to the database in a single transaction.

    Also snapshots the current text_hash into embedded_text_hash so we can
    detect future text changes.
    """
    use_hash = has_text_hash_column(conn, version)
    if use_hash:
        query = sql.SQL(
            'UPDATE {} SET embedding = %s::vector, embedded_text_hash = text_hash WHERE "verseID" = %s'
        ).format(sql.Identifier(version))
    else:
        query = sql.SQL(
            'UPDATE {} SET embedding = %s::vector WHERE "verseID" = %s'
        ).format(sql.Identifier(version))

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
    """
    if not has_text_hash_column(conn, version):
        return None
    with conn.cursor() as cur:
        cur.execute(
            sql.SQL("SELECT bytea_xor_agg(text_hash) FROM {}").format(
                sql.Identifier(version)
            )
        )
        row = cur.fetchone()
        return row[0] if row else None


def check_staleness(conn, versions):
    """Report which versions have stale embeddings without recomputing."""
    stale = []
    for version in versions:
        if not has_text_hash_column(conn, version):
            print(f"  {version}: text_hash column not available (run migration 005)")
            continue

        with conn.cursor() as cur:
            # Count verses needing recomputation
            cur.execute(
                sql.SQL(
                    "SELECT "
                    "  COUNT(*) FILTER (WHERE embedding IS NULL) AS new_verses, "
                    "  COUNT(*) FILTER (WHERE embedding IS NOT NULL AND "
                    "    (embedded_text_hash IS NULL OR text_hash != embedded_text_hash)) AS changed_verses, "
                    "  COUNT(*) AS total "
                    "FROM {}"
                ).format(sql.Identifier(version))
            )
            row = cur.fetchone()
            new_count, changed_count, total = row

            # Check version-level XOR fingerprint
            current_xor = compute_content_xor(conn, version)
            cur.execute(
                "SELECT content_xor FROM embedding_metadata WHERE version_sigla = %s",
                (version,),
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
            else:
                print(f"  {version}: up to date ({total} verses)")

    return stale


def process_version(conn, model, version, force=False, batch_size=DEFAULT_BATCH_SIZE):
    """Compute and store embeddings for all verses in a version."""
    verses = load_verses(conn, version, force=force)
    if not verses:
        print(f"  {version}: no verses to process (all up to date)")
        return 0

    print(f"  {version}: computing embeddings for {len(verses)} verses...")
    verse_ids = [v[0] for v in verses]
    texts = [v[1] for v in verses]

    total_written = 0
    for i in range(0, len(texts), batch_size):
        batch_texts = texts[i : i + batch_size]
        batch_ids = verse_ids[i : i + batch_size]
        embeddings = model.encode(batch_texts, show_progress_bar=False)
        write_embeddings(conn, version, batch_ids, embeddings)
        total_written += len(batch_ids)
        if len(texts) > batch_size:
            print(f"    {total_written}/{len(texts)} written")

    # Record which model was used and the current content fingerprint
    content_xor = compute_content_xor(conn, version)
    update_metadata(conn, version, MODEL_NAME, EMBEDDING_DIM, content_xor, MODEL_VERSION)

    print(f"  {version}: done ({total_written} embeddings)")
    return total_written


def update_metadata(conn, version, model_name, dimensions, content_xor=None, model_version=""):
    """Insert or update the embedding_metadata record for a version."""
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO embedding_metadata (version_sigla, model_name, model_version, dimensions, computed_at, content_xor)
            VALUES (%s, %s, %s, %s, CURRENT_TIMESTAMP, %s)
            ON CONFLICT (version_sigla) DO UPDATE
                SET model_name = EXCLUDED.model_name,
                    model_version = EXCLUDED.model_version,
                    dimensions = EXCLUDED.dimensions,
                    computed_at = CURRENT_TIMESTAMP,
                    content_xor = EXCLUDED.content_xor
            """,
            (version, model_name, model_version, dimensions, content_xor),
        )
    conn.commit()


def main():
    parser = argparse.ArgumentParser(description="Compute verse embeddings for BibleGet")
    parser.add_argument("--force", action="store_true", help="Recompute all embeddings")
    parser.add_argument("--check", action="store_true", help="Report stale versions without recomputing")
    parser.add_argument("--version", type=str, default=None, help="Process a single version")
    parser.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_SIZE, help="Batch size")
    args = parser.parse_args()

    if args.batch_size < 1:
        print("Error: --batch-size must be a positive integer.", file=sys.stderr)
        sys.exit(1)

    conn = get_connection()
    try:
        # Verify pgvector extension is available (must be provisioned by DB migrations)
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
            print(f"Checking {len(versions)} version(s) for stale embeddings:")
            stale = check_staleness(conn, versions)
            if stale:
                print(f"\n{len(stale)} version(s) need recomputation: {', '.join(stale)}")
                print("Run without --check to recompute.")
            else:
                print("\nAll versions are up to date.")
            return

        print(f"Loading model: {MODEL_NAME}")
        start = time.time()
        model = SentenceTransformer(MODEL_NAME)
        print(f"Model loaded in {time.time() - start:.1f}s")

        print(f"Processing {len(versions)} version(s): {', '.join(versions)}")
        total = 0
        start = time.time()

        for version in versions:
            total += process_version(conn, model, version, force=args.force, batch_size=args.batch_size)

        elapsed = time.time() - start
        print(f"\nDone: {total} embeddings computed in {elapsed:.1f}s")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
