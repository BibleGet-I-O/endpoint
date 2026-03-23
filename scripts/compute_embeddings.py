#!/usr/bin/env python3
"""Batch-compute verse embeddings for BibleGet semantic search.

Connects to PostgreSQL, loads verse texts per Bible version,
computes embeddings via sentence-transformers, and writes them
back to the embedding column.

Usage:
    python compute_embeddings.py                    # skip verses with existing embeddings
    python compute_embeddings.py --force            # recompute all embeddings
    python compute_embeddings.py --version NABRE    # only process one version
    python compute_embeddings.py --batch-size 256   # custom batch size

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
    """Load verses that need embeddings computed."""
    with conn.cursor() as cur:
        if force:
            cur.execute(
                sql.SQL('SELECT "verseID", text FROM {} ORDER BY "verseID"').format(
                    sql.Identifier(version)
                )
            )
        else:
            cur.execute(
                sql.SQL(
                    'SELECT "verseID", text FROM {} WHERE embedding IS NULL ORDER BY "verseID"'
                ).format(sql.Identifier(version))
            )
        return cur.fetchall()


def write_embeddings(conn, version, verse_ids, embeddings):
    """Write embeddings back to the database in a single transaction."""
    query = sql.SQL('UPDATE {} SET embedding = %s WHERE "verseID" = %s').format(
        sql.Identifier(version)
    )
    with conn.cursor() as cur:
        cur.executemany(
            query, [(embedding.tolist(), verse_id) for verse_id, embedding in zip(verse_ids, embeddings)]
        )
    conn.commit()


def process_version(conn, model, version, force=False, batch_size=DEFAULT_BATCH_SIZE):
    """Compute and store embeddings for all verses in a version."""
    verses = load_verses(conn, version, force=force)
    if not verses:
        print(f"  {version}: no verses to process (all have embeddings)")
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

    # Record which model was used for this version's embeddings
    update_metadata(conn, version, MODEL_NAME, EMBEDDING_DIM)

    print(f"  {version}: done ({total_written} embeddings)")
    return total_written


def update_metadata(conn, version, model_name, dimensions):
    """Insert or update the embedding_metadata record for a version."""
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO embedding_metadata (version_sigla, model_name, dimensions, computed_at)
            VALUES (%s, %s, %s, CURRENT_TIMESTAMP)
            ON CONFLICT (version_sigla) DO UPDATE
                SET model_name = EXCLUDED.model_name,
                    dimensions = EXCLUDED.dimensions,
                    computed_at = CURRENT_TIMESTAMP
            """,
            (version, model_name, dimensions),
        )
    conn.commit()


def main():
    parser = argparse.ArgumentParser(description="Compute verse embeddings for BibleGet")
    parser.add_argument("--force", action="store_true", help="Recompute all embeddings")
    parser.add_argument("--version", type=str, default=None, help="Process a single version")
    parser.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_SIZE, help="Batch size")
    args = parser.parse_args()

    if args.batch_size < 1:
        print("Error: --batch-size must be a positive integer.", file=sys.stderr)
        sys.exit(1)

    print(f"Loading model: {MODEL_NAME}")
    start = time.time()
    model = SentenceTransformer(MODEL_NAME)
    print(f"Model loaded in {time.time() - start:.1f}s")

    conn = get_connection()
    try:
        # Ensure pgvector and embedding columns exist
        with conn.cursor() as cur:
            cur.execute("CREATE EXTENSION IF NOT EXISTS vector")
        conn.commit()

        versions = get_versions(conn, args.version)
        if not versions:
            print("No versions found to process.")
            sys.exit(1)

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
