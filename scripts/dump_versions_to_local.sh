#!/usr/bin/env bash
# =============================================================================
# Dump version tables from live PostgreSQL → local Docker PostgreSQL
# =============================================================================
#
# Purpose: For the LaBSE A/B experiment (issue #71, discussion #107). Brings
#          real production verse data into the local Docker postgres so the
#          new embedding_labse column can be filled without touching live.
#
# Prerequisites:
#   - Local Docker stack is up:           docker compose up -d postgres
#   - Local schema is applied:            psql ... -f migrations/production-schema.sql
#   - Migration 012 has been applied:     psql ... -f migrations/012-add-embedding-labse-columns.sql
#   - pg_dump and psql on PATH, matching the live server's PG major version.
#
# Usage:
#   LIVE_DB_HOST=... LIVE_DB_USER=... LIVE_DB_PASS=... LIVE_DB_NAME=... \
#     ./scripts/dump_versions_to_local.sh
#
# Required env vars (live source):
#   LIVE_DB_HOST   - hostname / IP of live PG
#   LIVE_DB_NAME   - database name
#   LIVE_DB_USER   - username (read access to the version tables suffices)
#   LIVE_DB_PASS   - password
#
# Optional env vars:
#   LIVE_DB_PORT   - default: 5432
#   LOCAL_DB_HOST  - default: 127.0.0.1
#   LOCAL_DB_PORT  - default: 5432
#   LOCAL_DB_NAME  - default: bibleget
#   LOCAL_DB_USER  - default: bibleget
#   LOCAL_DB_PASS  - default: bibleget
#   VERSIONS       - space-separated sigla (default: NABRE CEI2008 NVBSE)
#
# Idempotent: truncates the target tables before restore, so it's safe to
# re-run as the experiment iterates.
#
# =============================================================================

set -euo pipefail

VERSIONS="${VERSIONS:-NABRE CEI2008 NVBSE}"

missing=()
for v in LIVE_DB_HOST LIVE_DB_NAME LIVE_DB_USER LIVE_DB_PASS; do
    if [ -z "${!v:-}" ]; then
        missing+=("$v")
    fi
done
if [ ${#missing[@]} -gt 0 ]; then
    echo "Missing required env vars: ${missing[*]}" >&2
    echo "Run with --help for usage." >&2
    exit 1
fi

LIVE_DB_PORT="${LIVE_DB_PORT:-5432}"
LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-bibleget}"
LOCAL_DB_USER="${LOCAL_DB_USER:-bibleget}"
LOCAL_DB_PASS="${LOCAL_DB_PASS:-bibleget}"

log() {
    echo "$(date '+%H:%M:%S') [dump] $*"
}

log "Live source:  ${LIVE_DB_USER}@${LIVE_DB_HOST}:${LIVE_DB_PORT}/${LIVE_DB_NAME}"
log "Local target: ${LOCAL_DB_USER}@${LOCAL_DB_HOST}:${LOCAL_DB_PORT}/${LOCAL_DB_NAME}"
log "Versions:     ${VERSIONS}"

# Build the pg_dump table args. Each version gets both the data table and the
# accompanying _idx table.
dump_args=()
for V in $VERSIONS; do
    dump_args+=(-t "${V}" -t "${V}_idx")
done

# Truncate destination tables so the restore is a clean replace, not an append
# that would fail on primary-key conflicts. CASCADE is required because the
# data tables FK into shared lookup rows in some installs.
log "Truncating local target tables..."
trunc_sql=""
for V in $VERSIONS; do
    trunc_sql+="TRUNCATE TABLE \"${V}\", \"${V}_idx\" RESTART IDENTITY CASCADE;"$'\n'
done
PGPASSWORD="$LOCAL_DB_PASS" psql \
    -h "$LOCAL_DB_HOST" -p "$LOCAL_DB_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" \
    -v ON_ERROR_STOP=1 \
    -c "$trunc_sql"

log "Dumping from live and piping into local..."
# --data-only assumes destination schema already exists (production-schema.sql
# + migration 012 must have been applied). --no-owner / --no-privileges keep
# the dump portable across PG users.
PGPASSWORD="$LIVE_DB_PASS" pg_dump \
    -h "$LIVE_DB_HOST" -p "$LIVE_DB_PORT" -U "$LIVE_DB_USER" -d "$LIVE_DB_NAME" \
    --data-only --no-owner --no-privileges \
    "${dump_args[@]}" \
  | PGPASSWORD="$LOCAL_DB_PASS" psql \
        -h "$LOCAL_DB_HOST" -p "$LOCAL_DB_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" \
        -v ON_ERROR_STOP=1

log "Row counts in local target:"
for V in $VERSIONS; do
    count=$(PGPASSWORD="$LOCAL_DB_PASS" psql \
        -h "$LOCAL_DB_HOST" -p "$LOCAL_DB_PORT" -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" \
        -t -A -c "SELECT COUNT(*) FROM \"${V}\"")
    log "  ${V}: ${count} rows"
done

log "Done. Next: rebuild the embedding service with LaBSE and run"
log "    python scripts/compute_embeddings.py --column embedding_labse"
log "to fill the new column."
