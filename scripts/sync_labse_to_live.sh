#!/usr/bin/env bash
# =============================================================================
# Sync local embedding_labse → live PostgreSQL
# =============================================================================
#
# Purpose: Inverse of dump_versions_to_local.sh — pushes the locally-computed
#          LaBSE embeddings (in each version table's `embedding_labse` column)
#          up to the live database. Used in the production cutover for
#          issue #71 / discussion #107.
#
# Why a sync rather than a re-embed on live: LaBSE on CPU does ~30 rows/s,
# so re-embedding the full ~250k-row corpus on the live server takes hours.
# The local GPU host does it in ~25 minutes; this script transfers the
# resulting vectors.
#
# Prerequisites:
#   - Local Docker stack is up with all 8 version tables populated AND
#     embedding_labse filled (run dump_versions_to_local.sh + compute_embeddings.py
#     --column embedding_labse first).
#   - Live PG has migrations 012 + 013 applied (the embedding_labse columns
#     exist on every version table). If not, apply them on live first:
#         psql ... -f migrations/012-add-embedding-labse-columns.sql
#         psql ... -f migrations/013-add-embedding-labse-remaining-versions.sql
#   - SSH access to the live host, with credentials available in an .env-style
#     file at LIVE_ENV_PATH on the remote (DB_HOST, DB_PORT, DB_NAME, DB_USER,
#     DB_PASS — same shape as the deployed app's .env).
#
# Usage:
#   LIVE_SSH_HOST=ubuntu@catholicdigitalcommons.org \
#       ./scripts/sync_labse_to_live.sh
#
# Required env vars:
#   LIVE_SSH_HOST    - SSH endpoint (e.g. user@host) that can reach live PG
#
# Optional env vars:
#   LIVE_ENV_PATH    - .env file on the remote to source for DB credentials.
#                      Default: /var/www/vhosts/bibleget.io/httpdocs/query/dev/.env.staging
#   LOCAL_DB_HOST    - default: 127.0.0.1
#   LOCAL_DB_PORT    - default: 5432
#   LOCAL_DB_NAME    - default: bibleget
#   LOCAL_DB_USER    - default: bibleget
#   LOCAL_DB_PASS    - default: bibleget
#   VERSIONS         - space-separated sigla (default: all 8)
#
# Idempotent: each per-version transaction is independent. Re-running pushes
# the same vectors again; the UPDATE is a no-op write but the data is
# identical. Safe to retry after a network blip.
#
# =============================================================================

set -euo pipefail

VERSIONS="${VERSIONS:-NABRE CEI2008 NVBSE BLPD DIVCOM DRB LUZZI VGCL}"

if [ -z "${LIVE_SSH_HOST:-}" ]; then
    echo "Missing required env: LIVE_SSH_HOST" >&2
    echo "Example: LIVE_SSH_HOST=ubuntu@catholicdigitalcommons.org ./scripts/sync_labse_to_live.sh" >&2
    exit 1
fi

LIVE_ENV_PATH="${LIVE_ENV_PATH:-/var/www/vhosts/bibleget.io/httpdocs/query/dev/.env.staging}"

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-bibleget}"
LOCAL_DB_USER="${LOCAL_DB_USER:-bibleget}"
LOCAL_DB_PASS="${LOCAL_DB_PASS:-bibleget}"

log() {
    echo "$(date '+%H:%M:%S') [sync] $*"
}

# Runs psql against local PG and returns stdout. Used to dump (verseID, labse)
# tuples in COPY text format. Falls back to `docker compose exec` if psql is
# not on PATH (the common case when local PG is a Docker container).
if command -v psql >/dev/null 2>&1; then
    local_psql() {
        PGPASSWORD="$LOCAL_DB_PASS" psql \
            -h "$LOCAL_DB_HOST" -p "$LOCAL_DB_PORT" \
            -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" \
            -v ON_ERROR_STOP=1 "$@"
    }
else
    log "psql not on PATH — using docker compose exec for local queries"
    local_psql() {
        docker compose exec -T postgres psql \
            -U "$LOCAL_DB_USER" -d "$LOCAL_DB_NAME" \
            -v ON_ERROR_STOP=1 "$@"
    }
fi

# Streams an SQL bundle into psql on the live host over SSH. The live psql
# session sources LIVE_ENV_PATH for credentials so they never appear on the
# command line. The outer single quotes prevent local expansion of $DB_HOST
# etc.; the LIVE_ENV_PATH break-out interpolates the path locally so the
# remote shell sees a literal absolute path.
#
# SSH flags:
# - Compression=yes: vector text data compresses ~50-70%, large speedup.
# - ServerAliveInterval/CountMax: send keepalive probes during long-running
#   remote UPDATEs so a middle box doesn't drop the TCP session (previously
#   observed: connection dies between tables when an UPDATE takes minutes).
# - TCPKeepAlive=yes: redundant belt-and-suspenders at the kernel level.
remote_psql_stdin() {
    # POSIX `.` rather than bash-only `source` so the remote login shell can
    # be `/bin/sh` without breaking.
    ssh \
        -o Compression=yes \
        -o ServerAliveInterval=30 \
        -o ServerAliveCountMax=20 \
        -o TCPKeepAlive=yes \
        "$LIVE_SSH_HOST" '
        set -e
        set -a; . "'"$LIVE_ENV_PATH"'"; set +a
        PGPASSWORD="$DB_PASS" psql \
            -h "$DB_HOST" -p "$DB_PORT" \
            -U "$DB_USER" -d "$DB_NAME" \
            -v ON_ERROR_STOP=1
    '
}

log "Live target: $LIVE_SSH_HOST (creds from $LIVE_ENV_PATH)"
log "Versions:    $VERSIONS"
log ""

for V in $VERSIONS; do
    src_count=$(local_psql -tAc \
        "SELECT COUNT(*) FROM \"$V\" WHERE embedding_labse IS NOT NULL")
    src_count="${src_count//[[:space:]]/}"
    if [ "$src_count" = "0" ]; then
        log "$V: 0 LaBSE rows locally — skipping"
        continue
    fi
    log "$V: pushing $src_count LaBSE vectors → live"

    # Build the SQL bundle: BEGIN → temp table → COPY data → UPDATE → COMMIT.
    # The COPY data section sits between the COPY ... FROM STDIN line and a
    # `\.` terminator, exactly as in a pg_dump file.
    #
    # The UPDATE is wrapped in a DO block so we can compare ROW_COUNT against
    # the input count and abort the transaction on a mismatch. If verseIDs
    # exist locally but not on live (or vice versa), the UPDATE would silently
    # touch fewer rows than expected — the assertion turns that into a loud
    # rollback rather than a half-applied sync.
    {
        echo 'BEGIN;'
        echo "CREATE TEMP TABLE _labse_in (\"verseID\" INT PRIMARY KEY, embedding_labse vector(768));"
        echo 'COPY _labse_in FROM STDIN;'
        local_psql -tAc "COPY (SELECT \"verseID\", embedding_labse FROM \"$V\" WHERE embedding_labse IS NOT NULL) TO STDOUT"
        echo '\.'
        echo "DO \$sync\$"
        echo "DECLARE"
        echo "    expected_count INT;"
        echo "    updated_count INT;"
        echo "BEGIN"
        echo "    SELECT COUNT(*) INTO expected_count FROM _labse_in;"
        echo "    UPDATE \"$V\" SET embedding_labse = _labse_in.embedding_labse"
        echo "      FROM _labse_in WHERE \"$V\".\"verseID\" = _labse_in.\"verseID\";"
        echo "    GET DIAGNOSTICS updated_count = ROW_COUNT;"
        echo "    IF updated_count <> expected_count THEN"
        echo "        RAISE EXCEPTION 'sync mismatch for $V: expected %, updated %', expected_count, updated_count;"
        echo "    END IF;"
        echo "END"
        echo "\$sync\$;"
        echo 'COMMIT;'
    } | remote_psql_stdin | sed "s/^/    /"
done

log ""
log "Done. The cutover SQL (drop the legacy MiniLM column + rename"
log "embedding_labse → embedding) lives at docs/future-migrations/014-"
log "cutover-embedding-to-labse.sql — held back deliberately; promote it"
log "into migrations/ only after an explicit decision to retire MiniLM."
