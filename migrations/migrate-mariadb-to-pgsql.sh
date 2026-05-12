#!/usr/bin/env bash
# =============================================================================
# Migrate BibleGet data from MariaDB to PostgreSQL
# =============================================================================
#
# Two modes:
#
#   docker (default)  - both DBs are Docker containers on this host. Used by
#                       the local docker-compose dev stack.
#   --native          - both DBs are reachable as native services via TCP.
#                       Used on the production VPS where MariaDB and Postgres
#                       run as system services on 127.0.0.1.
#
# Usage (docker mode, default):
#   MARIA_PASS=secret PG_PASS=secret \
#     ./migrations/migrate-mariadb-to-pgsql.sh
#
# Usage (native mode):
#   MARIA_PASS=secret PG_PASS=secret \
#     ./migrations/migrate-mariadb-to-pgsql.sh --native
#
# Required env vars (both modes):
#   MARIA_PASS        - MariaDB password
#   PG_PASS           - PostgreSQL password
#
# Optional env vars (both modes):
#   MARIA_USER (default: bibleget)
#   MARIA_DB   (default: bibleget)
#   PG_USER    (default: bibleget)
#   PG_DB      (default: bibleget)
#   KEEP_TMPDIR=1     - preserve temp files after migration
#
# Optional env vars (docker mode):
#   MARIA_CONTAINER (default: mariadb-virx-mariadb-1)
#   PG_CONTAINER    (default: endpoint-postgres-1)
#
# Optional env vars (native mode):
#   MARIA_HOST (default: 127.0.0.1)
#   MARIA_PORT (default: 3306)
#   PG_HOST    (default: 127.0.0.1)
#   PG_PORT    (default: 5432)
#
# Native mode requires the `mariadb` and `psql` CLI clients in PATH.
#
# =============================================================================
set -euo pipefail

# ── Argument parsing ─────────────────────────────────────────────────────────

NATIVE_MODE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --native) NATIVE_MODE=1; shift ;;
        --docker) NATIVE_MODE=0; shift ;;
        -h|--help)
            # Print only the commented header at the top of this file.
            # Stops at the first non-comment, non-blank line (e.g. `set -euo
            # pipefail`) so the help output stays in sync with the docstring
            # regardless of how the script grows below.
            awk 'NR==1 {next}
                 /^#/ {sub(/^# ?/, ""); print; next}
                 /^[[:space:]]*$/ {print; next}
                 {exit}' "$0"
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            echo "Usage: $0 [--native|--docker]  (run with --help for details)" >&2
            exit 2
            ;;
    esac
done

# ── Configuration ─────────────────────────────────────────────────────────────

MARIA_USER="${MARIA_USER:-bibleget}"
MARIA_PASS="${MARIA_PASS:?Set MARIA_PASS in the environment}"
MARIA_DB="${MARIA_DB:-bibleget}"

PG_USER="${PG_USER:-bibleget}"
PG_PASS="${PG_PASS:?Set PG_PASS in the environment}"
PG_DB="${PG_DB:-bibleget}"

# Mode-specific defaults (all are read regardless of mode; only the relevant
# ones are actually used in the helper invocations below).
MARIA_CONTAINER="${MARIA_CONTAINER:-mariadb-virx-mariadb-1}"
PG_CONTAINER="${PG_CONTAINER:-endpoint-postgres-1}"
MARIA_HOST="${MARIA_HOST:-127.0.0.1}"
MARIA_PORT="${MARIA_PORT:-3306}"
PG_HOST="${PG_HOST:-127.0.0.1}"
PG_PORT="${PG_PORT:-5432}"

KEEP_TMPDIR="${KEEP_TMPDIR:-0}"
umask 077
MIGRATION_TMPDIR="$(mktemp -d "${TMPDIR:-/tmp}/bibleget-migration.XXXXXX")"
trap '[[ "$KEEP_TMPDIR" == "1" ]] || rm -rf "$MIGRATION_TMPDIR"' EXIT

# ── Preflight ────────────────────────────────────────────────────────────────

if [ "$NATIVE_MODE" = "1" ]; then
    command -v mariadb >/dev/null || { echo "Native mode requires 'mariadb' in PATH" >&2; exit 1; }
    command -v psql    >/dev/null || { echo "Native mode requires 'psql' in PATH"    >&2; exit 1; }
    MODE_LABEL="native (mariadb on ${MARIA_HOST}:${MARIA_PORT}, postgres on ${PG_HOST}:${PG_PORT})"
else
    command -v docker >/dev/null || { echo "Docker mode requires 'docker' in PATH (or use --native)" >&2; exit 1; }
    MODE_LABEL="docker (containers ${MARIA_CONTAINER}, ${PG_CONTAINER})"
fi

# ── Backend invocation helpers ───────────────────────────────────────────────
# These thin wrappers are the only place that knows about docker vs native.
# Existing call sites (maria_sql, maria_dump_csv, pg_sql, ...) compose them.

maria_exec() {
    if [ "$NATIVE_MODE" = "1" ]; then
        mariadb -h "$MARIA_HOST" -P "$MARIA_PORT" \
            -u "$MARIA_USER" -p"$MARIA_PASS" "$MARIA_DB" "$@"
    else
        docker exec "$MARIA_CONTAINER" \
            mariadb -u"$MARIA_USER" -p"$MARIA_PASS" "$MARIA_DB" "$@"
    fi
}

# Plain psql (no stdin redirection from the caller).
pg_exec() {
    if [ "$NATIVE_MODE" = "1" ]; then
        PGPASSWORD="$PG_PASS" psql -h "$PG_HOST" -p "$PG_PORT" \
            -v ON_ERROR_STOP=1 -X -U "$PG_USER" -d "$PG_DB" "$@"
    else
        docker exec -e PGPASSWORD="$PG_PASS" "$PG_CONTAINER" \
            psql -v ON_ERROR_STOP=1 -X -U "$PG_USER" -d "$PG_DB" "$@"
    fi
}

# psql for COPY commands that read from the script's stdin.
# In docker mode we need `-i` so docker forwards stdin to the container.
pg_exec_stdin() {
    if [ "$NATIVE_MODE" = "1" ]; then
        PGPASSWORD="$PG_PASS" psql -h "$PG_HOST" -p "$PG_PORT" \
            -v ON_ERROR_STOP=1 -X -U "$PG_USER" -d "$PG_DB" "$@"
    else
        docker exec -i -e PGPASSWORD="$PG_PASS" "$PG_CONTAINER" \
            psql -v ON_ERROR_STOP=1 -X -U "$PG_USER" -d "$PG_DB" "$@"
    fi
}

# ── High-level helpers ───────────────────────────────────────────────────────

maria_sql() {
    maria_exec -N -B -e "$1" 2>/dev/null
}

maria_dump_csv() {
    local table="$1"
    local query="$2"
    maria_exec -N -B -e "$query" 2>/dev/null \
        | tr -d '\r' > "$MIGRATION_TMPDIR/${table}.tsv"
}

pg_sql() {
    pg_exec -c "$1"
}

pg_sql_file() {
    pg_exec -f "$1"
}

pg_copy_from_stdin() {
    local table="$1"
    local columns="$2"
    # NULL marker is the literal four-letter string "NULL" because MariaDB's
    # batch mode (-B -N, with or without --raw) emits NULL values as exactly
    # that string. The default Postgres COPY-text NULL marker (\N) does NOT
    # match what MariaDB actually outputs, so any nullable column from MariaDB
    # was previously round-tripping as the literal text "NULL" — silent data
    # corruption for nullable text columns, and a loud `invalid input syntax
    # for type inet: "NULL"` for the requests_log* WHO_IP column.
    # Trade-off: a legitimate text value of literally "NULL" would now be
    # imported as SQL NULL. Acceptable for this dataset (Bible texts and
    # request logs don't contain that string).
    pg_exec_stdin -c "\\copy $table ($columns) FROM STDIN WITH (FORMAT text, NULL 'NULL')"
}

log() {
    echo "$(date '+%H:%M:%S') [migrate] $*"
}

log "Mode: ${MODE_LABEL}"

# ── Step 1: Apply production schema ──────────────────────────────────────────

log "Applying production schema..."
SCHEMA_FILE="$(dirname "$0")/production-schema.sql"
if [ "$NATIVE_MODE" = "1" ]; then
    pg_sql_file "$SCHEMA_FILE"
else
    docker cp "$SCHEMA_FILE" "$PG_CONTAINER":/tmp/production-schema.sql
    pg_sql_file /tmp/production-schema.sql
fi
log "Schema applied."

# ── Step 2: Migrate simple reference tables ──────────────────────────────────

log "Migrating counter..."
COUNTER_DATA=$(maria_sql "SELECT good, bad FROM counter LIMIT 1")
GOOD=$(echo "$COUNTER_DATA" | cut -f1)
BAD=$(echo "$COUNTER_DATA" | cut -f2)
EXISTING=$(pg_exec -t -A -c "SELECT COUNT(*) FROM counter;")
if [ "$EXISTING" = "0" ]; then
    pg_sql "INSERT INTO counter (good, bad) VALUES ($GOOD, $BAD);"
else
    log "  counter row already exists, skipping insert."
fi

log "Migrating section..."
maria_dump_csv section "SELECT * FROM section ORDER BY IDX"
pg_copy_from_stdin section '"IDX","NAME_EN","NAME_IT","NAME_ES","NAME_FR","NAME_DE","NAME_PT"' < "$MIGRATION_TMPDIR/section.tsv"

log "Migrating testament..."
maria_dump_csv testament "SELECT * FROM testament ORDER BY IDX"
pg_copy_from_stdin testament '"IDX","NAME_EN","NAME_IT","NAME_ES","NAME_FR","NAME_DE","NAME_PT"' < "$MIGRATION_TMPDIR/testament.tsv"

log "Migrating versions_available..."
# notes field may contain tabs/newlines, so use CSV format for this table.
# --raw disables MariaDB's batch-mode escaping of \n / \t / \\, so real
# newlines in `notes` reach the file as actual newlines (inside quoted CSV
# fields, which Postgres \copy FORMAT csv handles correctly). Without --raw
# they would arrive as the two-character literal "\n", which Postgres CSV
# format does NOT interpret, corrupting the imported text.
# Preserve NULLs and original whitespace; only escape double-quotes for CSV.
maria_exec -N -B --raw -e "SELECT CONCAT_WS(',',
        CONCAT('\"', REPLACE(sigla,'\"','\"\"'), '\"'),
        CONCAT('\"', REPLACE(fullname,'\"','\"\"'), '\"'),
        year,
        CONCAT('\"', REPLACE(language,'\"','\"\"'), '\"'),
        copyright,
        CONCAT('\"', REPLACE(copyright_holder,'\"','\"\"'), '\"'),
        imprimatur,
        CASE WHEN canon IS NULL THEN '\\N' ELSE CONCAT('\"', canon, '\"') END,
        CONCAT('\"', REPLACE(notes,'\"','\"\"'), '\"'),
        CONCAT('\"', REPLACE(type,'\"','\"\"'), '\"')
    ) FROM versions_available ORDER BY sigla" \
    2>/dev/null | tr -d '\r' > "$MIGRATION_TMPDIR/versions_available.csv"
pg_exec_stdin -c "\copy versions_available (sigla,fullname,year,language,copyright,copyright_holder,imprimatur,canon,notes,type) FROM STDIN WITH (FORMAT csv, NULL '\\N')" \
    < "$MIGRATION_TMPDIR/versions_available.csv"

log "Migrating usage_counter..."
maria_dump_csv usage_counter "SELECT datetime, currentcount, quarthourcount FROM usage_counter"
pg_copy_from_stdin usage_counter 'datetime,currentcount,quarthourcount' < "$MIGRATION_TMPDIR/usage_counter.tsv"

# ── Step 3: Migrate biblebooks tables ────────────────────────────────────────

log "Migrating biblebooks_fullname..."
maria_dump_csv biblebooks_fullname 'SELECT * FROM biblebooks_fullname ORDER BY BOOK'
pg_copy_from_stdin biblebooks_fullname \
    '"BOOK","ENGLISH","AFRIKAANS","ALBANIAN","AMHARIC","ARABIC","CHINESE","CROATIAN","CZECH","FILIPINO","FRENCH","GERMAN","GREEK","HUNGARIAN","ITALIAN","JAPANESE","KOREAN","LATIN","POLISH","PORTUGUESE","ROMANIAN","RUSSIAN","SPANISH","TAMIL","THAI","VIETNAMESE"' \
    < "$MIGRATION_TMPDIR/biblebooks_fullname.tsv"

log "Migrating biblebooks_abbr..."
maria_dump_csv biblebooks_abbr 'SELECT * FROM biblebooks_abbr ORDER BY BOOK'
pg_copy_from_stdin biblebooks_abbr \
    '"BOOK","AFRIKAANS","ALBANIAN","AMHARIC","ARABIC","CHINESE","CROATIAN","CZECH","ENGLISH","FILIPINO","FRENCH","GERMAN","GREEK","HUNGARIAN","ITALIAN","JAPANESE","KOREAN","LATIN","POLISH","PORTUGUESE","ROMANIAN","RUSSIAN","SPANISH","TAMIL","THAI","VIETNAMESE"' \
    < "$MIGRATION_TMPDIR/biblebooks_abbr.tsv"

# ── Step 4: Migrate Bible version tables ─────────────────────────────────────

migrate_bible_version() {
    local version="$1"
    local maria_select="$2"
    local pg_columns="$3"
    local maria_idx_select="$4"
    local pg_idx_columns="$5"

    log "Migrating ${version}..."
    maria_dump_csv "$version" "SELECT $maria_select FROM \`$version\` ORDER BY verseID"
    pg_copy_from_stdin "\"$version\"" "$pg_columns" < "$MIGRATION_TMPDIR/${version}.tsv"

    log "Migrating ${version}_idx..."
    maria_dump_csv "${version}_idx" "SELECT $maria_idx_select FROM \`${version}_idx\` ORDER BY book"
    pg_copy_from_stdin "\"${version}_idx\"" "$pg_idx_columns" < "$MIGRATION_TMPDIR/${version}_idx.tsv"

    # Reset the serial sequence to max verseID
    pg_sql "SELECT setval(pg_get_serial_sequence('\"$version\"', 'verseID'), COALESCE((SELECT MAX(\"verseID\") FROM \"$version\"), 1));" > /dev/null
}

# MariaDB -B -N mode outputs the literal string "NULL" for NULL values.
# Our pg_copy_from_stdin sets the COPY NULL marker to match (see comment
# in that helper). So passing NULLs through unchanged is correct.
FULL_MARIA_SELECT="testament,section,book,chapter,versedescr,verse,verseequiv,verseorigin,text,title1,title2,title3,verseID"
FULL_PG_COLS='testament,section,book,chapter,versedescr,verse,verseequiv,verseorigin,text,title1,title2,title3,"verseID"'
STD_IDX_MARIA="book,chapters,verses_count,verses_last,fullname,abbrev"
STD_IDX_PG='book,chapters,verses_count,verses_last,fullname,abbrev'

# CEI2008, BLPD: full columns with verseorigin
for V in CEI2008 BLPD; do
    migrate_bible_version "$V" \
        "$FULL_MARIA_SELECT" "$FULL_PG_COLS" \
        "$STD_IDX_MARIA" "$STD_IDX_PG"
done

# NVBSE: same shape as CEI2008/BLPD
log "Migrating NVBSE..."
maria_dump_csv NVBSE "SELECT testament,section,book,chapter,versedescr,verse,verseequiv,verseorigin,text,title1,title2,title3,verseID FROM NVBSE ORDER BY verseID"
pg_copy_from_stdin '"NVBSE"' 'testament,section,book,chapter,versedescr,verse,verseequiv,verseorigin,text,title1,title2,title3,"verseID"' < "$MIGRATION_TMPDIR/NVBSE.tsv"
log "Migrating NVBSE_idx..."
maria_dump_csv NVBSE_idx "SELECT book,chapters,verses_count,verses_last,fullname,abbrev FROM NVBSE_idx ORDER BY book"
pg_copy_from_stdin '"NVBSE_idx"' 'book,chapters,verses_count,verses_last,fullname,abbrev' < "$MIGRATION_TMPDIR/NVBSE_idx.tsv"
pg_sql "SELECT setval(pg_get_serial_sequence('\"NVBSE\"', 'verseID'), COALESCE((SELECT MAX(\"verseID\") FROM \"NVBSE\"), 1));" > /dev/null

# NABRE, NABRE_old: no verseorigin in the unique key. NABRE.verse is INT
# in the target schema (and in MariaDB after the one-time NABRE anomaly
# normalization — 7 sub-verse rows split into verse + verseequiv, 1 bridge
# row relocated). NABRE_old keeps verse VARCHAR as a frozen legacy backup.
for V in NABRE NABRE_old; do
    log "Migrating ${V}..."
    maria_dump_csv "$V" "SELECT testament,section,book,chapter,versedescr,verse,verseequiv,verseorigin,text,title1,title2,title3,verseID FROM \`$V\` ORDER BY verseID"
    pg_copy_from_stdin "\"$V\"" 'testament,section,book,chapter,versedescr,verse,verseequiv,verseorigin,text,title1,title2,title3,"verseID"' < "$MIGRATION_TMPDIR/${V}.tsv"
    pg_sql "SELECT setval(pg_get_serial_sequence('\"$V\"', 'verseID'), COALESCE((SELECT MAX(\"verseID\") FROM \"$V\"), 1));" > /dev/null
done
log "Migrating NABRE_idx..."
maria_dump_csv NABRE_idx "SELECT book,chapters,verses_count,verses_last,fullname,abbrev FROM NABRE_idx ORDER BY book"
pg_copy_from_stdin '"NABRE_idx"' 'book,chapters,verses_count,verses_last,fullname,abbrev' < "$MIGRATION_TMPDIR/NABRE_idx.tsv"

# LUZZI: no verseorigin column
log "Migrating LUZZI..."
maria_dump_csv LUZZI "SELECT testament,section,book,chapter,versedescr,verse,verseequiv,text,title1,title2,title3,verseID FROM LUZZI ORDER BY verseID"
pg_copy_from_stdin '"LUZZI"' 'testament,section,book,chapter,versedescr,verse,verseequiv,text,title1,title2,title3,"verseID"' < "$MIGRATION_TMPDIR/LUZZI.tsv"
log "Migrating LUZZI_idx..."
maria_dump_csv LUZZI_idx "SELECT book,chapters,verses_count,verses_last,fullname,abbrev FROM LUZZI_idx ORDER BY book"
pg_copy_from_stdin '"LUZZI_idx"' 'book,chapters,verses_count,verses_last,fullname,abbrev' < "$MIGRATION_TMPDIR/LUZZI_idx.tsv"
pg_sql "SELECT setval(pg_get_serial_sequence('\"LUZZI\"', 'verseID'), COALESCE((SELECT MAX(\"verseID\") FROM \"LUZZI\"), 1));" > /dev/null

# DIVCOM: no testament/section/verseorigin
log "Migrating DIVCOM..."
maria_dump_csv DIVCOM "SELECT book,chapter,versedescr,verse,verseequiv,text,title1,title2,title3,verseID FROM DIVCOM ORDER BY verseID"
pg_copy_from_stdin '"DIVCOM"' 'book,chapter,versedescr,verse,verseequiv,text,title1,title2,title3,"verseID"' < "$MIGRATION_TMPDIR/DIVCOM.tsv"
log "Migrating DIVCOM_idx..."
maria_dump_csv DIVCOM_idx "SELECT book,fullname,abbrev,chapters,verses_count,verses_last FROM DIVCOM_idx ORDER BY book"
pg_copy_from_stdin '"DIVCOM_idx"' 'book,fullname,abbrev,chapters,verses_count,verses_last' < "$MIGRATION_TMPDIR/DIVCOM_idx.tsv"
pg_sql "SELECT setval(pg_get_serial_sequence('\"DIVCOM\"', 'verseID'), COALESCE((SELECT MAX(\"verseID\") FROM \"DIVCOM\"), 1));" > /dev/null

# VGCL, DRB: full data schema (same as CEI2008/BLPD), but the idx tables
# have a simpler column set — they lack `verses_count`. Earlier versions
# of this script used a "simple" SELECT for the data tables that dropped
# `versedescr`, `verseequiv` and the title columns; that silently lost the
# 94 sub-verse identifiers (`1a`, `1b`, …) in DRB and VGCL Esther 1:1, so
# rows became indistinguishable by primary key. Migrate the full schema.
SIMPLE_IDX_MARIA="book,chapters,verses_last,fullname,abbrev"
SIMPLE_IDX_PG='book,chapters,verses_last,fullname,abbrev'

for V in VGCL DRB; do
    migrate_bible_version "$V" \
        "$FULL_MARIA_SELECT" "$FULL_PG_COLS" \
        "$SIMPLE_IDX_MARIA" "$SIMPLE_IDX_PG"
done

# ── Step 5: Migrate CantiLiturgici ───────────────────────────────────────────

log "Migrating CantiLiturgici..."
maria_dump_csv CantiLiturgici "SELECT IDX,Titolo,Autore,Categorie FROM CantiLiturgici ORDER BY IDX"
pg_copy_from_stdin '"CantiLiturgici"' '"IDX","Titolo","Autore","Categorie"' < "$MIGRATION_TMPDIR/CantiLiturgici.tsv"
pg_sql "SELECT setval(pg_get_serial_sequence('\"CantiLiturgici\"', 'IDX'), COALESCE((SELECT MAX(\"IDX\") FROM \"CantiLiturgici\"), 1));" > /dev/null

# ── Step 6: Migrate curl_error ───────────────────────────────────────────────

log "Migrating curl_error..."
maria_dump_csv curl_error "SELECT COUNTER,ERRNO,ERROR,CURLWHEN FROM curl_error ORDER BY COUNTER"
pg_copy_from_stdin 'curl_error' '"COUNTER","ERRNO","ERROR","CURLWHEN"' < "$MIGRATION_TMPDIR/curl_error.tsv"
pg_sql "SELECT setval(pg_get_serial_sequence('curl_error', 'COUNTER'), COALESCE((SELECT MAX(\"COUNTER\") FROM curl_error), 1));" > /dev/null

# ── Step 7: Migrate request log tables ───────────────────────────────────────
# This is the largest migration (~1.3M rows, ~800MB).
# - Legacy `requests_log`: int unsigned IP -> inet via INET_NTOA()
# - 2014-2019: int unsigned IP -> inet via INET_NTOA()
# - 2020+: varbinary(16) IP -> inet via INET6_NTOA()
# NULL IPs are preserved as NULL (not replaced with 0.0.0.0).

log "Migrating requests_log (legacy, 570K rows)..."
maria_dump_csv requests_log \
    "SELECT COUNTER, WHO_WHEN, INET_NTOA(WHO_IP), WHO_WHERE_JSON, HEADERS_JSON, \`QUERY\`, REQUEST_METHOD, HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR, HTTP_X_REAL_IP, REMOTE_ADDR, APP_ID, DOMAIN, PLUGINVERSION FROM requests_log ORDER BY COUNTER"
pg_copy_from_stdin 'requests_log' \
    '"COUNTER","WHO_WHEN","WHO_IP","WHO_WHERE_JSON","HEADERS_JSON","QUERY","REQUEST_METHOD","HTTP_CLIENT_IP","HTTP_X_FORWARDED_FOR","HTTP_X_REAL_IP","REMOTE_ADDR","APP_ID","DOMAIN","PLUGINVERSION"' \
    < "$MIGRATION_TMPDIR/requests_log.tsv"
pg_sql "SELECT setval(pg_get_serial_sequence('requests_log', 'COUNTER'), COALESCE((SELECT MAX(\"COUNTER\") FROM requests_log), 1));" > /dev/null

# Discover yearly log tables from MariaDB and migrate each one.
# Schema evolved: pre-2020 tables lack ORIGIN/ORIGINALQUERY and use int unsigned for IP,
# 2020+ tables have ORIGIN/ORIGINALQUERY and use varbinary(16) for IP.
OLD_LOG_COLS='"COUNTER","WHO_WHEN","WHO_IP","WHO_WHERE_JSON","HEADERS_JSON","QUERY","REQUEST_METHOD","HTTP_CLIENT_IP","HTTP_X_FORWARDED_FOR","HTTP_X_REAL_IP","REMOTE_ADDR","APP_ID","DOMAIN","PLUGINVERSION"'
NEW_LOG_COLS='"COUNTER","WHO_WHEN","WHO_IP","WHO_WHERE_JSON","HEADERS_JSON","ORIGIN","QUERY","ORIGINALQUERY","REQUEST_METHOD","HTTP_CLIENT_IP","HTTP_X_FORWARDED_FOR","HTTP_X_REAL_IP","REMOTE_ADDR","APP_ID","DOMAIN","PLUGINVERSION"'

YEARLY_TABLES=$(maria_sql "SELECT table_name FROM information_schema.tables WHERE table_schema = '$MARIA_DB' AND table_name LIKE 'requests\_log\_\_%' ORDER BY table_name")
for TABLE in $YEARLY_TABLES; do
    COUNT=$(maria_sql "SELECT COUNT(*) FROM \`$TABLE\`")
    if [ "$COUNT" -eq 0 ]; then
        log "Skipping $TABLE (empty)"
        continue
    fi

    # Determine schema variant by checking if ORIGIN column exists
    HAS_ORIGIN=$(maria_sql "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$MARIA_DB' AND table_name = '$TABLE' AND column_name = 'ORIGIN'")
    # Determine IP column type to choose INET_NTOA vs INET6_NTOA
    IP_TYPE=$(maria_sql "SELECT column_type FROM information_schema.columns WHERE table_schema = '$MARIA_DB' AND table_name = '$TABLE' AND column_name = 'WHO_IP'")

    if [[ "$IP_TYPE" == *"varbinary"* ]]; then
        IP_EXPR="INET6_NTOA(WHO_IP)"
    else
        IP_EXPR="INET_NTOA(WHO_IP)"
    fi

    log "Migrating $TABLE ($COUNT rows)..."
    if [ "$HAS_ORIGIN" -gt 0 ]; then
        maria_dump_csv "$TABLE" \
            "SELECT COUNTER, WHO_WHEN, $IP_EXPR, WHO_WHERE_JSON, HEADERS_JSON, ORIGIN, \`QUERY\`, ORIGINALQUERY, REQUEST_METHOD, HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR, HTTP_X_REAL_IP, REMOTE_ADDR, APP_ID, DOMAIN, PLUGINVERSION FROM \`$TABLE\` ORDER BY COUNTER"
        pg_copy_from_stdin "$TABLE" "$NEW_LOG_COLS" < "$MIGRATION_TMPDIR/${TABLE}.tsv"
    else
        maria_dump_csv "$TABLE" \
            "SELECT COUNTER, WHO_WHEN, $IP_EXPR, WHO_WHERE_JSON, HEADERS_JSON, \`QUERY\`, REQUEST_METHOD, HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR, HTTP_X_REAL_IP, REMOTE_ADDR, APP_ID, DOMAIN, PLUGINVERSION FROM \`$TABLE\` ORDER BY COUNTER"
        pg_copy_from_stdin "$TABLE" "$OLD_LOG_COLS" < "$MIGRATION_TMPDIR/${TABLE}.tsv"
    fi
    pg_sql "SELECT setval(pg_get_serial_sequence('$TABLE', 'COUNTER'), COALESCE((SELECT MAX(\"COUNTER\") FROM $TABLE), 1));" > /dev/null
done

# ── Step 8: Verify ───────────────────────────────────────────────────────────

log ""
log "=== Migration complete. Verifying row counts ==="
log ""

# Compare row counts
TABLES="counter section testament versions_available biblebooks_fullname biblebooks_abbr CEI2008 CEI2008_idx BLPD BLPD_idx NVBSE NVBSE_idx NABRE NABRE_idx NABRE_old LUZZI LUZZI_idx DIVCOM DIVCOM_idx VGCL VGCL_idx DRB DRB_idx CantiLiturgici curl_error usage_counter requests_log"

printf "%-30s %10s %10s %s\n" "TABLE" "MARIADB" "POSTGRES" "STATUS"
printf "%-30s %10s %10s %s\n" "-----" "-------" "--------" "------"

for T in $TABLES; do
    M_COUNT=$(maria_sql "SELECT COUNT(*) FROM \`$T\`" 2>/dev/null || echo "N/A")
    P_COUNT=$(pg_exec -t -A -c "SELECT COUNT(*) FROM \"$T\"" 2>/dev/null || echo "N/A")
    if [ "$M_COUNT" = "$P_COUNT" ]; then
        STATUS="✓"
    else
        STATUS="✗ MISMATCH"
    fi
    printf "%-30s %10s %10s %s\n" "$T" "$M_COUNT" "$P_COUNT" "$STATUS"
done

# Yearly log tables (discovered dynamically)
for T in $YEARLY_TABLES; do
    M_COUNT=$(maria_sql "SELECT COUNT(*) FROM \`$T\`" 2>/dev/null || echo "N/A")
    P_COUNT=$(pg_exec -t -A -c "SELECT COUNT(*) FROM \"$T\"" 2>/dev/null || echo "N/A")
    if [ "$M_COUNT" = "$P_COUNT" ]; then
        STATUS="✓"
    else
        STATUS="✗ MISMATCH"
    fi
    printf "%-30s %10s %10s %s\n" "$T" "$M_COUNT" "$P_COUNT" "$STATUS"
done

log ""
log "Done!"
