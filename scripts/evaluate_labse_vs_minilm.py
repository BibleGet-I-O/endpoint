#!/usr/bin/env python3
"""Side-by-side eval: MiniLM (baseline) vs LaBSE (candidate) on a probe set.

For each probe query in each language, embed via both models and run a
pgvector cosine-similarity top-K against the matching version. Prints the
two top-K lists side by side so the user can eyeball whether LaBSE returns
better matches than MiniLM — especially for Latin, which discussion #107
identified as the weak point of the current MiniLM setup.

Usage:
    pip install -r scripts/requirements.txt
    python scripts/evaluate_labse_vs_minilm.py
    python scripts/evaluate_labse_vs_minilm.py --top-k 5
    python scripts/evaluate_labse_vs_minilm.py --probes scripts/probe_queries.json

Probe file format (JSON):
    [
      {
        "concept": "Genesis creation",
        "queries": {
          "NABRE":   "In the beginning, God created the heavens and the earth",
          "CEI2008": "In principio Dio creò il cielo e la terra",
          "NVBSE":   "In principio creavit Deus caelum et terram"
        }
      },
      ...
    ]

Each query is run against its own version. The probe file can be edited
locally without changing this script.

Environment variables:
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
    BASELINE_MODEL    - default: paraphrase-multilingual-MiniLM-L12-v2
    CANDIDATE_MODEL   - default: sentence-transformers/LaBSE
    BASELINE_COLUMN   - default: embedding
    CANDIDATE_COLUMN  - default: embedding_labse

A `.env` file at the project root is loaded automatically if python-dotenv
is installed.
"""

import argparse
import json
import os
import sys
from pathlib import Path

import psycopg2
from psycopg2 import sql
from sentence_transformers import SentenceTransformer

try:
    from dotenv import load_dotenv

    load_dotenv()
except ImportError:
    pass


BASELINE_MODEL = os.environ.get("BASELINE_MODEL", "paraphrase-multilingual-MiniLM-L12-v2")
CANDIDATE_MODEL = os.environ.get("CANDIDATE_MODEL", "sentence-transformers/LaBSE")
BASELINE_COLUMN = os.environ.get("BASELINE_COLUMN", "embedding")
CANDIDATE_COLUMN = os.environ.get("CANDIDATE_COLUMN", "embedding_labse")
DEFAULT_TOP_K = 5
DEFAULT_PROBES = Path(__file__).parent / "probe_queries.json"
TEXT_PREVIEW_CHARS = 90


def get_connection():
    return psycopg2.connect(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=os.environ.get("DB_PORT", "5432"),
        dbname=os.environ.get("DB_NAME", "bibleget"),
        user=os.environ.get("DB_USER", "bibleget"),
        password=os.environ.get("DB_PASS", "bibleget"),
    )


def top_k(conn, version, column, vector, k):
    """Return [(score, book, chapter, verse, text), ...] top-K rows.

    Ranks by cosine distance ascending (i.e. similarity descending) against
    `version`.`column`. Both `version` and `column` flow from the probe file
    / CLI / env — composed via psycopg2.sql.Identifier so identifier injection
    is impossible.
    """
    table_ident = sql.Identifier(version)
    col_ident = sql.Identifier(column)
    query = sql.SQL(
        'SELECT 1 - ({col} <=> %s::vector) AS score, '
        'book, chapter, verse, text '
        "FROM {table} "
        "WHERE {col} IS NOT NULL "
        "ORDER BY {col} <=> %s::vector "
        "LIMIT %s"
    ).format(table=table_ident, col=col_ident)
    vec_str = "[" + ",".join(str(x) for x in vector.tolist()) + "]"
    with conn.cursor() as cur:
        # nosemgrep
        cur.execute(query, (vec_str, vec_str, k))
        return cur.fetchall()


def format_row(score, book, chapter, verse, text):
    snippet = (text or "").replace("\n", " ").strip()
    if len(snippet) > TEXT_PREVIEW_CHARS:
        snippet = snippet[: TEXT_PREVIEW_CHARS - 1] + "…"
    return f"  {score:5.3f}  {book:>3}.{chapter}:{verse:<3}  {snippet}"


def render_side_by_side(label_a, rows_a, label_b, rows_b):
    print(f"  {label_a}")
    print("  " + "─" * (len(label_a)))
    if rows_a:
        for row in rows_a:
            print(format_row(*row))
    else:
        print("    (no results)")
    print()
    print(f"  {label_b}")
    print("  " + "─" * (len(label_b)))
    if rows_b:
        for row in rows_b:
            print(format_row(*row))
    else:
        print("    (no results)")


def main():
    parser = argparse.ArgumentParser(description="MiniLM vs LaBSE eval")
    parser.add_argument(
        "--probes",
        type=Path,
        default=DEFAULT_PROBES,
        help=f"Path to probe JSON file (default: {DEFAULT_PROBES})",
    )
    parser.add_argument(
        "--top-k", type=int, default=DEFAULT_TOP_K, help="Number of results per side"
    )
    parser.add_argument("--baseline-column", default=BASELINE_COLUMN)
    parser.add_argument("--candidate-column", default=CANDIDATE_COLUMN)
    args = parser.parse_args()

    if not args.probes.exists():
        print(f"Probe file not found: {args.probes}", file=sys.stderr)
        sys.exit(1)
    probes = json.loads(args.probes.read_text())

    print(f"Baseline:  {BASELINE_MODEL} → column {args.baseline_column!r}")
    print(f"Candidate: {CANDIDATE_MODEL} → column {args.candidate_column!r}")
    print(f"Probes:    {args.probes} ({len(probes)} concept(s))")
    print(f"Top-K:     {args.top_k}")
    print()

    print("Loading baseline model…")
    baseline = SentenceTransformer(BASELINE_MODEL)
    print("Loading candidate model…")
    candidate = SentenceTransformer(CANDIDATE_MODEL)

    conn = get_connection()
    try:
        for probe in probes:
            concept = probe["concept"]
            print(f"\n{'=' * 88}")
            print(f"Concept: {concept}")
            print("=" * 88)
            for version, query in probe["queries"].items():
                print(f"\n── {version} ─────────────────────────────────────────────────────────────")
                print(f'Query: "{query}"')
                print()

                base_vec = baseline.encode(query)
                cand_vec = candidate.encode(query)
                base_rows = top_k(conn, version, args.baseline_column, base_vec, args.top_k)
                cand_rows = top_k(conn, version, args.candidate_column, cand_vec, args.top_k)
                render_side_by_side(
                    f"MiniLM  ({args.baseline_column})", base_rows,
                    f"LaBSE   ({args.candidate_column})", cand_rows,
                )
        print()
    finally:
        conn.close()


if __name__ == "__main__":
    main()
