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
- **CI/CD** — GitHub Actions (`.github/workflows/ci.yaml`) runs PHPCS + PHPStan + tests on PRs; `readme.yaml` syncs `openapi.json` to ReadMe.io on push to `master`
- **Branches** — `master` is stable/production, `development` is active dev
- **API spec** — `openapi.json` (OpenAPI 3.0.3) documents all endpoints
- **Logs** — written to `logs/` directory by Monolog (rotating file handler)

## Key Implementation Details

- Bot protection via User-Agent regex blocking (bot/crawl/slurp/spider)
- CORS enabled with dynamic origin headers (via AbstractHandler)
- Bible notation auto-detection converts between ENGLISH and EUROPEAN formats
- Copyright-restricted versions enforce a 30-verse-per-request limit
- Verse ordering uses `verseID` field to handle Greek subverses correctly
- Rate limiting: IP-based, 2-day windows, thresholds at 10/30/100 requests → `TooManyRequestsException`
