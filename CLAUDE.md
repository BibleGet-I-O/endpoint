# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BibleGet I/O Endpoint (v3.0) — a REST API service for retrieving Bible quotations across multiple versions and languages. Production URL: `https://query.bibleget.io/v3/`. Written in PHP 8.2+ with a PostgreSQL backend.

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

For local development with PHP's built-in server (requires external PostgreSQL):
```bash
php -S localhost:8000 -t public public/router.php
```

### Front Controller & Routing

All requests are routed through `public/index.php` → `src/Router.php` via `.htaccess` rewrite rules.

The Router parses the URL path and dispatches to the appropriate PSR-15 handler:

| Route                        | Handler                | Description |
|------------------------------|------------------------|-------------|
| `/v3/quote`                  | `QuoteHandler`         | Bible quote retrieval |
| `/v3/metadata/biblebooks`    | `MetadataHandler`      | Book names in 25+ languages |
| `/v3/metadata/bibleversions` | `MetadataHandler`      | Available Bible versions |
| `/v3/metadata/versionindex`  | `MetadataHandler`      | Chapter/verse indexes |
| `/v3/search`                 | `SearchHandler`        | Keyword search |

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
- Key tables: `versions_available`, `biblebooks_fullname`, `biblebooks_abbr`, `"{VERSION}_idx"`, `"{VERSION}"`, `requests_log__YYYY`
- IP addresses stored as PostgreSQL native `inet` type
- Full-text search uses `tsvector`/`tsquery` with GIN indexes

### Response Formats

JSON (default), XML, and HTML. Controlled by `return` query param or `Accept` header content negotiation.

## Development Notes

- **Testing** — PHPUnit 11 with unit, integration (PostgreSQL), and HTTP server test suites; run via `composer test` or `composer test:quick`
- **Code style** — PHPCS (PSR-12 base with custom rules) via `composer lint`; auto-fix with `composer lint:fix`
- **Static analysis** — PHPStan level 10 via `composer analyse`
- **CI/CD** — GitHub Actions (`.github/workflows/ci.yaml`) runs PHPCS + PHPStan + tests on PRs. (`readme.yaml` was meant to sync `openapi.json` to ReadMe.io on push to `master`, but the action is broken — see #100.)
- **Branches** — Two distinct codebases live in this repo:
  - `master` — the **legacy include-based codebase** that's currently serving production at `https://query.bibleget.io/v3/`. Tagged [v3.0.0](https://github.com/BibleGet-I-O/endpoint/releases/tag/v3.0.0) at commit `9cbf764` (PR #99 brought master into sync with that deployed snapshot — previously master had drifted ~16 months behind the deployed lineage). No `composer.json`, no `vendor/`, just `index.php` + `search.php` + `metadata.php` + `includes/`. Treat as frozen; bug fixes only.
  - `development` — the **modern PSR-based rewrite** described above. Active development happens here; will ship as `v4.x` when ready for production.
  - The deploy workflow's `Verify modern codebase shape` step (in `.github/workflows/deploy.yaml`) skips deploys for any tag that lacks `composer.json`, so future legacy hotfix tags won't accidentally trigger production deploys.
- **API spec** — `openapi.json` (OpenAPI 3.0.3) documents all endpoints
- **Logs** — written to `logs/` directory by Monolog (rotating file handler)

## Key Implementation Details

- Bot protection via User-Agent regex blocking (bot/crawl/slurp/spider)
- CORS enabled with dynamic origin headers (via AbstractHandler)
- Bible notation auto-detection converts between ENGLISH and EUROPEAN formats
- Copyright-restricted versions enforce a 30-verse-per-request limit
- Verse ordering uses `verseID` field to handle Greek subverses correctly
- Rate limiting: IP-based, 2-day windows, thresholds at 10/30/100 requests → `TooManyRequestsException`

## Live infrastructure (catholicdigitalcommons.org)

Bare-metal VPS, **not Docker** in production. `docker-compose.yml` is dev-only.

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
