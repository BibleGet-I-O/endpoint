# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BibleGet I/O Endpoint (v3.0) — a REST API service for retrieving Bible quotations across multiple versions and languages. Production URL: `https://query.bibleget.io/v3/`. Written in PHP 8.4+ with a PostgreSQL backend.

## Architecture

The codebase uses a **PSR-based architecture** modeled after the Liturgical Calendar API:

- **PSR-4** autoloading via Composer (`BibleGet\Api\` → `src/`)
- **PSR-7** HTTP messages via `nyholm/psr7`
- **PSR-15** request handlers and middleware via `psr/http-server-handler` and `psr/http-server-middleware`
- **PSR-17** HTTP factories via `nyholm/psr7` (`Psr17Factory`)
- **PSR-3** logging via `monolog/monolog`
- **RFC 9457** `application/problem+json` error responses
- Response emission via `laminas/laminas-httphandlerrunner` (`SapiEmitter`)

### Commands

```bash
composer install          # Install dependencies
composer dump-autoload    # Regenerate autoloader
```

For local development with Docker Compose (PostgreSQL + PHP/Apache):
```bash
docker compose up -d          # Start PostgreSQL + app
docker compose down            # Stop services
```

Local Zitadel (developer sign-in), opt-in profile, one origin at `http://localhost:8095`:
```bash
docker compose --profile zitadel up -d zitadel-proxy
scripts/setup-zitadel-local.sh   # BibleGet Org/Project/bibleget_admin + website app → .env.local
```
It runs cdcf-infra's `auth/setup-zitadel.sh --target local` (cloned from `main`; set `CDCF_INFRA_DIR` to use a local checkout, which must include CatholicOS/cdcf-infra#55). The issuer `http://localhost:8095` is reachable from the host only, so run the API on the host (`php -S`, `composer test`), not in the compose `app` container. Console `/ui/console` (root / RootPassword1!), mail in Mailpit at `:8026`; the website app's one-time client secret goes to `.stack-out/bibleget-website.env`. `.env*` files never override the process environment (`src/Util/EnvLoader.php`). Zitadel access tokens carry no email claims: `POST /applications` gets `email_verified` from `/oidc/v1/userinfo` (`src/Services/UserInfoClient.php`).

For local development with PHP's built-in server (requires external PostgreSQL):
```bash
php -S localhost:8000 -t public public/router.php
```

### Front Controller & Routing

All requests are routed through `public/index.php` → `src/Router.php` via `.htaccess` rewrite rules.

The Router parses the URL path and dispatches to the appropriate PSR-15 handler:

| Route                        | Handler                | Description |
|------------------------------|------------------------|-------------|
| `/v3/quote`                  | `QuoteHandler`         | Bible quote retrieval. `mentions=1` (JSON only) adds each NVBSE row's CBPPEDR `mentions` (code points of the formatted `text`, dropped when the row's `text_plain` or `text` no longer hashes as at load) and the top-level `entities` cards (`src/Services/QuoteMentions.php`, table `bpe_mention` etc., migration 073, loaded by `scripts/load_cbppedr.php`) |
| `/v3/metadata/biblebooks`    | `MetadataHandler`      | Book names in 25+ languages |
| `/v3/metadata/bibleversions` | `MetadataHandler`      | Available Bible versions |
| `/v3/metadata/versionindex`  | `MetadataHandler`      | Chapter/verse indexes |
| `/v3/search`                 | `SearchHandler`        | Keyword search |
| `/v3/metadata/entities`     | `MetadataHandler`      | An edition's index of the persons, places or events of CBPPEDR (`?version=&type=person\|place\|event`): each entry's attested CBTUDR units resolved to the edition's verses through `btu_link` (rule and reviewed links only). Tables `bpe_*`, migration 072, loaded by `scripts/load_cbppedr.php` |
| `/v3/review/...`             | `ReviewHandler`        | Review of the links from editions' verses to CBTUDR units (role `bibleget_reviewer`; tables `btu_*`, migration 053, loaded by `scripts/load_cbtudr.php`) |

### Middleware Pipeline

For each request, the Router builds a `MiddlewarePipeline` (PSR-15):

1. **ErrorHandlingMiddleware** — outermost; catches all exceptions → RFC 9457 `problem+json`
2. **LoggingMiddleware** — logs request/response via Monolog PSR-3 logger
3. **Handler** — the actual endpoint handler

### Directory Structure

```text
public/index.php                     # Front controller
src/
├── Router.php                       # URL dispatch
├── Handlers/
│   ├── AbstractHandler.php          # Base PSR-15 handler with CORS, validation, content negotiation
│   ├── QuoteHandler.php             # /quote endpoint
│   ├── MetadataHandler.php          # /metadata endpoint
│   └── SearchHandler.php            # /search endpoint
├── Pipeline/
│   ├── QuoteContext.php             # Shared data context for the quote pipeline
│   ├── QueryValidator.php           # Validates Bible reference syntax
│   ├── QueryFormulator.php          # Translates references → SQL
│   └── QueryExecutor.php            # Executes SQL, rate limiting, logging
├── Http/
│   ├── Enum/                        # StatusCode, RequestMethod, RequestContentType, AcceptHeader
│   ├── Exception/                   # ApiException hierarchy (RFC 9457)
│   ├── Middleware/                   # ErrorHandlingMiddleware, LoggingMiddleware
│   ├── Server/MiddlewarePipeline.php
│   └── Logs/LoggerFactory.php       # Monolog factory
└── Database/Connection.php          # PDO/PostgreSQL connection singleton
```

### Quote Pipeline

`QuoteHandler` orchestrates: `QuoteContext` → `QueryValidator` → `QueryFormulator` → `QueryExecutor`

- **QuoteContext** (`src/Pipeline/QuoteContext.php`) — holds all shared state (DB connection, metadata, validated queries, results, errors). Replaces the old `BIBLEGET_QUOTE` public properties.
- **QueryValidator** — validates notation, book names, chapter/verse bounds
- **QueryFormulator** — builds SQL WHERE clauses from Bible references
- **QueryExecutor** — runs SQL, enforces rate limits (throws `TooManyRequestsException`), logs requests

### Error Handling

All errors throw `ApiException` subclasses (`src/Http/Exception/`), caught by `ErrorHandlingMiddleware` and returned as RFC 9457 `application/problem+json`:

```json
{"type": "https://datatracker.ietf.org/doc/html/rfc9110#name-...", "title": "...", "status": 4xx, "detail": "..."}
```

### Database

- **PostgreSQL** via PDO (`ext-pdo_pgsql`)
- Credentials loaded from environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) via `vlucas/phpdotenv`
- Managed by `src/Database/Connection.php` (PDO singleton with `ERRMODE_EXCEPTION` and `FETCH_ASSOC`)
- Key tables: `versions_available`, `biblebooks_fullname`, `biblebooks_abbr`, `"{VERSION}_idx"`, `"{VERSION}"`, `requests_log__YYYY`, plus the credential tables `applications`, `api_keys`, `client_keys`, `installations`, `usage_daily` (migration 048)
- **Migrations** — from `052` on, Doctrine Migrations (`doctrine-migrations.php`, classes in `src/Migrations/`, table `doctrine_migration_versions`). Each class extends `src/Database/SqlFileMigration.php` and names its SQL file in `migrations/`; the file has no `BEGIN`/`COMMIT`: Doctrine runs the pending migrations in one transaction (`all_or_nothing`), and a file that controls a transaction is refused. A file may hold several statements (it runs through `executeStatement`, not `addSql`). A run holds a PostgreSQL advisory lock, so a second one answers 409. New migration: `migrations/NNN-name.sql` + `src/Migrations/VersionNNNName.php`. The deploy workflow applies them through `POST /_ops/migrate` (`src/Handlers/Ops/MigrateHandler.php`, gated by `X-Deploy-Token` = `DEPLOY_TOKEN` in the server's `.env.*` and the GitHub environment's secret; `GET /_ops/migrate/status` lists them). Locally: `composer db:migrate`, `composer db:migrations:status` (`bin/doctrine-migrations`, not deployed). `001`–`051` predate Doctrine and were applied by hand with `psql`; so are a private archive's data halves, which must be applied before the public migration that checks them. Ported from the Liturgical Calendar API.
- v4 stores no IP addresses or geolocation: `requests_log__YYYY` records `credential_type`/`credential_id`, and the legacy personal columns stay NULL. Stripping the imported v3 history is drafted in `docs/future-migrations/049-…` (destructive, apply on staging and the production copy before go-live)
- Full-text search uses `tsvector`/`tsquery` with GIN indexes
- `bpe_*` (migrations 072, 073) hold CBPPEDR (CatholicOS/cbppedr): its entries, their attested units, and for a labelled unit only the editions that name the entry there; migration 073 adds `mentioned` on `bpe_attested`, `bpe_mention` (where the text names an entry or, under review, its candidates: positions in code points of the formatted text, valid against `plain_hash`), `bpe_detail` (image, Wikipedia, coordinates) and `bpe_verse_count` (filled after the load). `scripts/load_cbppedr.php <checkout>` (not deployed, run through a tunnel like `load_cbtudr.php`, after it) replaces every row and records the commit in `bpe_release`
- `search_vocabulary` (migration 058) holds each edition's words for `/search/suggest`: importing an edition, or any migration that changes an edition's `text_plain`, must also run `SELECT refresh_search_vocabulary('<SIGLA>')`, or that edition's suggestions go stale (there is no trigger)

### Response Formats

JSON (default), XML, and HTML. Controlled by `return` query param or `Accept` header content negotiation.

## Development Notes

- **Testing** — PHPUnit 13 with unit, integration (PostgreSQL), and HTTP server test suites; run via `composer test` or `composer test:quick`
- **Code style** — PHPCS (PSR-12 base with custom rules) via `composer lint`; auto-fix with `composer lint:fix`
- **Static analysis** — PHPStan level 10 via `composer analyse`
- **Complexity** — PHPCS fails any PHP function with cyclomatic complexity above 20 or nesting deeper than 5 (`phpcs.xml`, so `composer lint`, pre-commit and CI enforce it). Python (`scripts/`, `services/embedding/`) is gated by xenon at the same limit: `composer lint:python` (needs `uv`), run by the pre-push hook when `uvx` is available and by the `Python complexity (xenon)` CI job. CodeFactor additionally flags "Complex Method" on PRs with its own (different) metric.
- **CI/CD** — GitHub Actions (`.github/workflows/ci.yaml`) runs PHPCS + PHPStan + tests on PRs. (`readme.yaml` was meant to sync `openapi.json` to ReadMe.io on push to `master`, but the action is broken — see #100.)
- **Branches** — Two distinct codebases live in this repo:
  - `master` — the **legacy include-based codebase** that's currently serving production at `https://query.bibleget.io/v3/`. Tagged [v3.0.0](https://github.com/BibleGet-I-O/endpoint/releases/tag/v3.0.0) at commit `9cbf764` (PR #99 brought master into sync with that deployed snapshot — previously master had drifted ~16 months behind the deployed lineage), then [v3.0.1](https://github.com/BibleGet-I-O/endpoint/releases/tag/v3.0.1) (PR #152, Esther rate-limiter hotfix). No `composer.json`, no `vendor/`, just `index.php` + `search.php` + `metadata.php` + `includes/`. Treat as frozen; bug fixes only.
  - `development` — the **modern PSR-based rewrite** described above. Active development happens here; will ship as `v4.x` when ready for production.
  - The deploy workflow's `Verify modern codebase shape` step (in `.github/workflows/deploy.yaml`) skips deploys for any tag that lacks `composer.json`, so future legacy hotfix tags won't accidentally trigger production deploys.
  - **Legacy hotfixes** — branch from `origin/master`, PR back to `master`, tag `v3.0.x`. The shared captainhook git hooks need `vendor/bin/captainhook`, which doesn't exist on the legacy tree, so commit/push with `git -c core.hooksPath=/dev/null …` and run `php -l` by hand. Legacy files are CRLF — keep them that way or the diff becomes whole-file. Deploy is manual: copy the changed file(s) into `/var/www/vhosts/bibleget.io/httpdocs/query/v3/` on the VPS (see *Live infrastructure* below).
  - **Cutover to v4** — the release model is: merge `development` into `master`, then tag the release from `master` (a tag with `composer.json` passes the deploy guard, so that's the moment the modern codebase ships). Never merge `master` into `development` — it would resurrect the legacy files. A dry run (`git merge-tree --write-tree origin/master origin/development`) shows exactly two expected conflicts, both resolved in favour of `development`:
    - `includes/QueryExecutor.php` — modify/delete (v3.0.1 modified it, the rewrite deleted it). `git rm` it; the fix is already covered by the PDO prepared statements in `src/Pipeline/QueryExecutor.php`.
    - `openapi.json` — content conflict on `info.version` (PR #99 set it to `v3.0.0` on master). `git checkout development -- openapi.json`.
    Any further `v3.0.x` hotfix will add its file(s) to the modify/delete list; resolve the same way.
- **API spec** — `openapi.json` (OpenAPI 3.0.3) documents all endpoints
- **Logs** — written to `logs/` directory by Monolog (rotating file handler)

## Key Implementation Details

- Bot protection via User-Agent regex blocking (bot/crawl/slurp/spider)
- CORS enabled with dynamic origin headers (via AbstractHandler)
- Bible notation auto-detection converts between ENGLISH and EUROPEAN formats
- Copyright-restricted versions enforce a 30-verse-per-request limit
- Service notices (`service_notices`, migration 060; `src/Services/ServiceNotices.php`): the admin publishes them (`/admin/service-notices`); every data-route answer carries the ones its credential is targeted by (`service_notices` in JSON, quote XML and the quote HTML's hidden `BibleGetServiceNotices` input) with RFC 9745 `Deprecation` / RFC 8594 `Sunset` / `Link` headers. A data handler opts in with `protected bool $carriesServiceNotices = true;`. Its answers are cached an hour at most (`SERVICE_NOTICE_MAX_AGE`), and a withdrawn notice keeps its row (`withdrawn_at`) so its id is never reused. The only channel to registered plugin installations, which have no developer account
- Verse ordering uses `verseID` field to handle Greek subverses correctly
- Credentials, not IPs: data routes need `X-Api-Key` (project key `bibleget_…` or installation token `bginst_…`; client keys `bgclient_…` only register installations via `POST /installations`). Required when `APP_ENV=production` or `REQUIRE_CREDENTIALS=true`. Per-credential sliding-hour rate limit (project 1000, installation 300; a key's `rate_limit_per_hour` overrides it, and NULL means that default, not unlimited; keys of an `is_system` application, such as the website's `reader` and `read` keys, are exempt) → `TooManyRequestsException` (429); `/applications` and `/admin/*` take a Zitadel Bearer token (role `bibleget_admin` for admin). Design: `docs/superpowers/specs/2026-11-17-api-keys-installations-design.md`

## Live infrastructure (catholicdigitalcommons.org)

Bare-metal VPS, **not Docker** in production. `docker-compose.yml` is dev-only.

- **PHP runtime is Plesk-managed**, not provisioned by the deploy workflow.
  The app needs `pdo_pgsql`, `json`, `simplexml`, `dom`, `intl` (see
  `composer.json`); Plesk PHP 8.3/8.4/8.5 (`/opt/plesk/php/*/bin/php`) all
  ship them. Check with `php -m` before switching the vhost's PHP version.
- **SSH:** `ssh ubuntu@catholicdigitalcommons.org`. The `ubuntu` user is in
  the `psacln` group, which grants group-read on the Plesk vhost trees
  below — most credential files can be sourced without sudo.
- **PostgreSQL 18 + pgvector 0.8.2** — native apt install
  (`postgresql-18`, `postgresql-18-pgvector`), single cluster `main`,
  listens on `127.0.0.1:5432` only. Database: `bibleget_dev`. Credentials
  live in `/var/www/vhosts/bibleget.io/httpdocs/query/dev/.env.staging`
  (DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS — `set -a; source .env.staging;
  set +a` to use them).
- **MariaDB** — also runs on `127.0.0.1:3306`, serves the legacy v3 API.
  Credentials in the legacy codebase's `dbcredentials.php`.

### Codebases on the server

| Path | Purpose |
|---|---|
| `/var/www/vhosts/bibleget.io/httpdocs/query/` | **Legacy** include-based PHP, MariaDB-backed. Mapped to `https://query.bibleget.io/v3/`. Frozen. |
| `/var/www/vhosts/bibleget.io/httpdocs/query/dev/` | **Modern** PSR-based PHP (this `development` branch), PostgreSQL-backed. Active dev/staging. |
| `/home/bibleget-embed/embedding/` | **Embedding microservice**, see below. |

Reference artifacts at the legacy root (`bibleget-io.chm`, `NABRE.sql`)
are intentional — see auto-memory `feedback_dont_delete_reference_artifacts`.

### Embedding microservice

Runs as user-level systemd under a dedicated unprivileged user — **no Docker**.

- **User:** `bibleget-embed` (uid 10004, primary group `bibleget-embed`).
  Home `/home/bibleget-embed`. Linger is enabled
  (`sudo loginctl enable-linger bibleget-embed`) so user-systemd persists
  without an active login.
- **Code:** `~bibleget-embed/embedding/` — `main.py`, `requirements.txt`,
  both `bibleget-embedding{,-labse}.service` files, plus `venv/` (excluded
  from rsync deploy). Directory is mode 700, not readable by `ubuntu`
  without sudo.
- **Venv:** `~bibleget-embed/embedding/venv/`. Refreshed by the deploy
  workflow via `pip install --upgrade --upgrade-strategy only-if-needed`.
  Shared by both services — requirements.txt is identical; only
  `EMBEDDING_MODEL` differs between the two unit files.
- **Model cache:** `~bibleget-embed/.cache/huggingface/` (`HF_HOME`),
  shared by both services. Files persist across deploys; first-startup
  download is ~470 MB for MiniLM, ~1.8 GB for LaBSE.
- **Two parallel services** (one venv, one model cache, distinct ports):
  - `bibleget-embedding.service` — MiniLM
    (`paraphrase-multilingual-MiniLM-L12-v2`) on `127.0.0.1:8000`. PHP
    API reads via `EMBEDDING_SERVICE_URL_MINILM` (legacy
    `EMBEDDING_SERVICE_URL` is honoured as a fallback for MiniLM only).
    Backs the `embedding` column.
  - `bibleget-embedding-labse.service` — LaBSE
    (`sentence-transformers/LaBSE` @ pinned revision in `main.py`) on
    `127.0.0.1:8002`. PHP API reads via `EMBEDDING_SERVICE_URL_LABSE`.
    Backs the `embedding_labse` column. **Default model** for
    `/v3/search/{semantic,similar}` requests that omit `model=`.
- **Unit files:**
  `~bibleget-embed/.config/systemd/user/bibleget-embedding{,-labse}.service`
  (sourced from the matching files under `services/embedding/` in this
  repo).

**Manage the services from `ubuntu`:**

```bash
# Status / logs / restart — user-systemd via sudo
# (substitute bibleget-embedding-labse for the LaBSE service)
sudo -u bibleget-embed XDG_RUNTIME_DIR=/run/user/10004 \
    systemctl --user status bibleget-embedding bibleget-embedding-labse
sudo -u bibleget-embed XDG_RUNTIME_DIR=/run/user/10004 \
    journalctl --user -u bibleget-embedding -n 50
sudo -u bibleget-embed XDG_RUNTIME_DIR=/run/user/10004 \
    systemctl --user restart bibleget-embedding bibleget-embedding-labse
```

### Minting keys (scripts are not deployed)

`scripts/mint-reader-key.php` (the website's keys, both on the system application "BibleGet website": `reader` by default, for signed-in users; `--scope=read` for a `read` key for visitors' pages, to which the API still applies the 30-verse cap on copyrighted editions; both are unlimited because the system application is exempt from rate limiting, not because of their NULL `rate_limit_per_hour`) and
`scripts/mint-client-key.php` (a plugin release's client key) and `scripts/mint-live-test-key.php` (the live test
suite's key: an ordinary rate-limited project key on the application "BibleGet live tests"; pipe it into
`gh secret set BIBLEGET_LIVE_API_KEY --env staging` without showing it) are excluded
from the rsync deploy on purpose: anything outside `public/` might be
web-reachable. Run them from a local checkout through an SSH tunnel to the
target's PostgreSQL:

```bash
ssh -N -L 15432:127.0.0.1:5432 ubuntu@catholicdigitalcommons.org &
export APP_ENV=staging            # or production: decides live vs test keys
export DB_HOST=127.0.0.1 DB_PORT=15432 DB_NAME=... DB_USER=... DB_PASS=...
php scripts/mint-client-key.php wordpress 9.0.0
```

Process variables win over the local `.env*` files, so set every `DB_*` and
`APP_ENV` (values from the server's `.env.staging` / `.env.production`; never
print them). Each script prints `APP_ENV` and `DB_NAME` to stderr before
minting, then the key once on stdout. Revoke an old reader key with
`POST /admin/api-keys/{id}/revoke` (ids from `GET /admin/applications/{id}`).

### Deploy workflows (manual, `workflow_dispatch`)

- **`.github/workflows/deploy.yaml`** — deploys the PHP API. Skips any tag
  lacking `composer.json` so legacy hotfix tags don't accidentally ship.
- **`.github/workflows/deploy-embedding.yaml`** — deploys **both** embedding
  microservices (MiniLM + LaBSE) in a single run, sharing one venv:
  1. Checks out `development`, verifies
     `services/embedding/{main.py,requirements.txt,bibleget-embedding.service,bibleget-embedding-labse.service}` exist.
  2. `rsync --delete services/embedding/ → bibleget-embed@VPS:embedding/`,
     excluding `Dockerfile`, `README.md`, `venv/`, `.cache/`, `__pycache__`.
  3. For each of the two unit files: if it changed, copy to
     `~/.config/systemd/user/`. Then `daemon-reload` and `enable` both.
  4. `pip install --upgrade-strategy only-if-needed -r requirements.txt`
     inside the shared venv.
  5. `systemctl --user restart` both services, then health-poll:
     10×3s on `:8000/health` (MiniLM), 30×3s on `:8002/health` (LaBSE —
     longer because cold-loading LaBSE takes longer than MiniLM).
