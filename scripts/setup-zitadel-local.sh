#!/usr/bin/env bash
#
# setup-zitadel-local.sh — provision the BibleGet Project in the LOCAL Zitadel
# (docker compose profile "zitadel") and point this checkout's .env.local at it.
#
#   docker compose --profile zitadel up -d zitadel-proxy
#   scripts/setup-zitadel-local.sh
#
# The provisioning itself is cdcf-infra's auth/setup-zitadel.sh, the same
# script that provisions the shared production instance, run with
# --target local --create-org BibleGet --provision-bibleget
# --provision-bibleget-website. So local and production get the same Project,
# role and app settings.
#
# Writes:
#   .env.local                         ZITADEL_ISSUER, ZITADEL_AUDIENCE (mode 600)
#   .stack-out/bibleget-website.env    the website's AUTH_ZITADEL_* values,
#                                      including the one-time client secret (600)
#
# cdcf-infra comes from CDCF_INFRA_DIR (a local checkout) if set, otherwise it
# is cloned into .stack-out/cdcf-infra at CDCF_INFRA_REF (default main). Until
# CatholicOS/cdcf-infra#55 is merged, main lacks --provision-bibleget: use
# CDCF_INFRA_REF=feat/provision-bibleget (the script checks and says so).
# Never prints the client secret. Idempotent: a re-run finds the existing
# Project and app, but Zitadel cannot return an existing app's secret, so the
# website file keeps the secret from the first run. If a run fails after the
# app was created, the captured output (.stack-out/zitadel-provision.out, 600)
# is kept and the next run takes the secret from it.
#
# ZITADEL_PORT (and MAILPIT_PORT) come from the shell, else from .env, as
# docker compose resolves them, else the defaults 8095 and 8026.
#
# The issuer is http://localhost:$ZITADEL_PORT, reachable from the host only:
# run the API on the host (php -S, composer test), not in the compose `app`
# container, whose localhost is itself (JWKS and userinfo would answer 503).

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# A port as docker compose resolves it: the shell, then .env, then the default.
compose_var() {
    local name="$1" default="$2" value="${!1:-}"
    if [[ -z "$value" && -f .env ]]; then
        value="$(sed -n "s/^${name}=//p" .env | tail -1 | tr -d "\"' \r")"
    fi
    printf '%s' "${value:-$default}"
}
ZITADEL_PORT="$(compose_var ZITADEL_PORT 8095)"
MAILPIT_PORT="$(compose_var MAILPIT_PORT 8026)"
ISSUER="http://localhost:${ZITADEL_PORT}"
PAT_FILE="$PWD/.zitadel-data/automation-user.pat"
ENV_FILE=".env.local"
WEBSITE_ENV=".stack-out/bibleget-website.env"
WORKDIR=".stack-out"
CURL_TIMEOUT=(--connect-timeout 5 --max-time 15)

for tool in curl jq git; do
    command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 1; }
done

echo "Waiting for Zitadel at $ISSUER ..."
for _ in $(seq 1 60); do
    curl -sf "${CURL_TIMEOUT[@]}" "$ISSUER/.well-known/openid-configuration" >/dev/null && break
    sleep 2
done
curl -sf "${CURL_TIMEOUT[@]}" "$ISSUER/.well-known/openid-configuration" >/dev/null || {
    echo "Zitadel is not answering at $ISSUER." >&2
    echo "  Start it: docker compose --profile zitadel up -d zitadel-proxy" >&2
    exit 1
}
[[ -s "$PAT_FILE" ]] || { echo "PAT not found at $PAT_FILE (Zitadel writes it on first start)" >&2; exit 1; }

mkdir -p "$WORKDIR"
if [[ -n "${CDCF_INFRA_DIR:-}" ]]; then
    INFRA_DIR="$(cd "$CDCF_INFRA_DIR" && pwd)"
else
    INFRA_DIR="$WORKDIR/cdcf-infra"
    ref="${CDCF_INFRA_REF:-main}"
    if [[ -d "$INFRA_DIR/.git" ]]; then
        git -C "$INFRA_DIR" fetch --quiet origin "$ref"
        git -C "$INFRA_DIR" checkout --quiet FETCH_HEAD
    else
        git clone --quiet --depth 1 --branch "$ref" https://github.com/CatholicOS/cdcf-infra.git "$INFRA_DIR"
    fi
fi
[[ -x "$INFRA_DIR/auth/setup-zitadel.sh" ]] || { echo "No auth/setup-zitadel.sh in $INFRA_DIR" >&2; exit 1; }
grep -q -- '--provision-bibleget-website' "$INFRA_DIR/auth/setup-zitadel.sh" || {
    echo "$INFRA_DIR/auth/setup-zitadel.sh has no --provision-bibleget (CatholicOS/cdcf-infra#55)." >&2
    echo "  Use CDCF_INFRA_REF=feat/provision-bibleget, or CDCF_INFRA_DIR=<a checkout of it>." >&2
    exit 1
}

# One env file per local property (cdcf-infra issue #34). The PAT's path,
# endpoint/.zitadel-data/, is what tells the provisioner which stack this is.
PROVISION_ENV="$PWD/$WORKDIR/.env.local.endpoint"
(umask 077; cat > "$PROVISION_ENV" <<EOF
ZITADEL_ISSUER=$ISSUER
ZITADEL_INTERNAL_URL=$ISSUER
ZITADEL_PAT_FILE=$PAT_FILE
EOF
)

# The provisioner's stdout carries the one-time client secret: capture it in an
# owner-only file, parse it, and delete it only once the secret is saved. Its
# stderr (progress) is shown. A failed earlier run's capture is kept as .prev,
# because a re-run cannot get the secret of the app that run created.
OUT="$WORKDIR/zitadel-provision.out"
[[ -s "$OUT" ]] && mv -f "$OUT" "$OUT.prev"
(umask 077; : > "$OUT")
trap 'echo "Failed: the provisioner output is kept in $OUT (owner-only); the next run reuses its secret." >&2' ERR
(
    cd "$INFRA_DIR/auth"
    ENV_FILE="$PROVISION_ENV" ./setup-zitadel.sh --target local \
        --create-org BibleGet --provision-bibleget --provision-bibleget-website
) > "$OUT"

val() { sed -n "s/^$1=\([^ ]*\).*/\1/p" "${2:-$OUT}" | head -1; }
AUDIENCE="$(val ZITADEL_AUDIENCE)"
ORG_ID="$(val ZITADEL_ORG_ID)"
CLIENT_ID="$(val AUTH_ZITADEL_ID)"
CLIENT_SECRET="$(val AUTH_ZITADEL_SECRET)"
if [[ -z "$CLIENT_SECRET" && -s "$OUT.prev" && "$(val AUTH_ZITADEL_ID "$OUT.prev")" == "$CLIENT_ID" ]]; then
    CLIENT_SECRET="$(val AUTH_ZITADEL_SECRET "$OUT.prev")"
fi
[[ -n "$AUDIENCE" ]]  || { echo "No ZITADEL_AUDIENCE in the provisioner output" >&2; exit 1; }
[[ -n "$CLIENT_ID" ]] || { echo "No website client id in the provisioner output" >&2; exit 1; }

# KEY=value into a file, replacing an existing KEY line or appending one. The
# value goes through the environment into awk, never into a regex or program
# text, so punctuation in secrets cannot corrupt the file.
set_env() {
    local file="$1" key="$2" value="$3" tmp
    [[ -f "$file" ]] || (umask 077; : > "$file")
    tmp="$(mktemp "$(dirname "$file")/.env.XXXXXX")"
    SET_ENV_KEY="$key" SET_ENV_VALUE="$value" awk '
        BEGIN { prefix = ENVIRON["SET_ENV_KEY"] "="; found = 0 }
        !found && substr($0, 1, length(prefix)) == prefix { print prefix ENVIRON["SET_ENV_VALUE"]; found = 1; next }
        { print }
        END { if (!found) print prefix ENVIRON["SET_ENV_VALUE"] }
    ' "$file" > "$tmp"
    chmod 600 "$tmp"
    mv "$tmp" "$file"
}

set_env "$ENV_FILE" ZITADEL_ISSUER "$ISSUER"
set_env "$ENV_FILE" ZITADEL_AUDIENCE "$AUDIENCE"

set_env "$WEBSITE_ENV" AUTH_ZITADEL_ISSUER "$ISSUER"
set_env "$WEBSITE_ENV" AUTH_ZITADEL_ID "$CLIENT_ID"
set_env "$WEBSITE_ENV" ZITADEL_PROJECT_ID "$AUDIENCE"
set_env "$WEBSITE_ENV" ZITADEL_ORG_ID "$ORG_ID"
if [[ -n "$CLIENT_SECRET" ]]; then
    set_env "$WEBSITE_ENV" AUTH_ZITADEL_SECRET "$CLIENT_SECRET"
    echo "✓ Website client secret saved to $WEBSITE_ENV (one-time emit)."
elif ! grep -qE '^AUTH_ZITADEL_SECRET=.+' "$WEBSITE_ENV"; then
    echo "⚠ The website app already existed and $WEBSITE_ENV has no secret:" >&2
    echo "  regenerate it in the console (BibleGet Org → Projects → BibleGet → Apps)." >&2
fi
rm -f "$OUT" "$OUT.prev"

echo
echo "✓ $ENV_FILE: ZITADEL_ISSUER=$ISSUER, ZITADEL_AUDIENCE=$AUDIENCE"
echo "  Console: $ISSUER/ui/console (root / RootPassword1!), mail: http://localhost:${MAILPIT_PORT}"
