# Codebase Structure — BibleGet endpoint

## Top-level layout
```
endpoint/
├── public/                # Web entry point (front controller + .htaccess routing)
├── src/                   # PHP source (PSR-4: BibleGet\Api\)
├── tests/                 # PHPUnit (PSR-4: BibleGet\Tests\)
│                          # Suites: Unit, Integration (PostgreSQL), Server (HTTP)
├── services/              # External / runtime services
├── migrations/            # DB migrations
├── scripts/               # Helper scripts
├── docs/                  # Project docs
├── .htaccess              # Apache rewrite to public/index.php
├── docker-compose.yml     # Local dev: PostgreSQL + PHP/Apache
├── Dockerfile             # Production image
├── docker-entrypoint.sh
├── start-server.sh / stop-server.sh / restart-server.sh
├── composer.json / composer.lock
├── phpunit.xml.dist
├── phpstan.neon.dist      # static analysis (level 10)
├── phpcs.xml              # PSR-12 + custom
├── captainhook.json       # git hooks
├── openapi.json           # OpenAPI 3.0.3 spec (synced to ReadMe.io on master push)
├── redocly.yaml           # OpenAPI lint config
├── .env.example, .env.test
└── CLAUDE.md, README.md, LICENSE
```

## Front controller / routing
1. `.htaccess` rewrites all requests → `public/index.php`
2. `public/index.php` instantiates `Router`
3. `src/Router.php` parses URL path and dispatches to a PSR-15 handler

## Routes (v3)
| Route                          | Handler           | Description                          |
|--------------------------------|-------------------|--------------------------------------|
| `/v3/quote`                    | `QuoteHandler`    | Bible quote retrieval (GET/POST)     |
| `/v3/metadata/biblebooks`      | `MetadataHandler` | Book names in 25+ languages          |
| `/v3/metadata/bibleversions`   | `MetadataHandler` | Available Bible versions             |
| `/v3/metadata/versionindex`    | `MetadataHandler` | Chapter/verse indexes for versions   |
| `/v3/search`                   | `SearchHandler`   | Keyword search                       |

## Middleware pipeline (for every request)
1. `ErrorHandlingMiddleware` — outermost; converts exceptions → RFC 9457 `problem+json`
2. `LoggingMiddleware` — Monolog request/response logging
3. Endpoint-specific PSR-15 handler

## src/ layout
```
src/
├── Router.php
├── Handlers/
│   ├── AbstractHandler.php           # base PSR-15 handler: CORS, validation, content negotiation
│   ├── QuoteHandler.php              # /v3/quote
│   ├── MetadataHandler.php           # /v3/metadata/{biblebooks|bibleversions|versionindex}
│   └── SearchHandler.php             # /v3/search
├── Pipeline/
│   ├── QuoteContext.php              # Shared state for quote pipeline
│   ├── QueryValidator.php            # Validates Bible reference syntax
│   ├── QueryFormulator.php           # References → SQL
│   └── QueryExecutor.php             # Runs SQL, rate limit, logging
├── Http/
│   ├── Enum/                         # StatusCode, RequestMethod, RequestContentType, AcceptHeader
│   ├── Exception/                    # ApiException hierarchy (RFC 9457)
│   ├── Middleware/                   # ErrorHandlingMiddleware, LoggingMiddleware
│   ├── Server/MiddlewarePipeline.php
│   └── Logs/LoggerFactory.php        # Monolog factory
└── Database/Connection.php           # PDO/PostgreSQL singleton (ERRMODE_EXCEPTION, FETCH_ASSOC)
```

## Quote pipeline flow
`QuoteHandler` orchestrates:
`QuoteContext` → `QueryValidator` → `QueryFormulator` → `QueryExecutor`

- **QuoteContext** holds DB connection, metadata, validated queries, results, errors. Replaced the legacy `BIBLEGET_QUOTE` public properties.
- **QueryValidator** checks notation (ENGLISH vs EUROPEAN), book names, chapter/verse bounds.
- **QueryFormulator** assembles SQL WHERE clauses.
- **QueryExecutor** runs SQL, enforces rate limits (`TooManyRequestsException`), logs to `requests_log__YYYY`.

## Database (PostgreSQL)
- Credentials via env: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (via `vlucas/phpdotenv`)
- Singleton in `src/Database/Connection.php`
- Key tables:
  - `versions_available` — list of supported versions
  - `biblebooks_fullname`, `biblebooks_abbr` — book name metadata
  - `"{VERSION}_idx"` — per-version index (chapter/verse limits)
  - `"{VERSION}"` — per-version verse text (one table per version)
  - `requests_log__YYYY` — annual rolling request log
- IPs stored as native `inet` type
- Search: `tsvector` + `tsquery` with GIN indexes

## Error handling (RFC 9457)
All `ApiException` subclasses (in `src/Http/Exception/`) → caught by `ErrorHandlingMiddleware` → returned as:
```json
{
  "type":   "https://datatracker.ietf.org/doc/html/rfc9110#name-...",
  "title":  "...",
  "status": 4xx,
  "detail": "..."
}
```

## Response formats
- JSON (default), XML, HTML
- Selected via `return` query param OR `Accept` header content negotiation
- Accept header values: `application/json`, `application/xml`, `text/html`

## Standard request parameters (key ones)
- `query` *(required, /quote)* — Bible reference (English `John3:16` or European `Giovanni3,16`)
- `version` — Bible version acronym(s), comma-separated; default `CEI2008`
- `appid` *(required, /quote)* — application identifier (no real registration; informal tracking)
- `pluginversion` — calling plugin's version
- `domain` — calling website's domain
- `preferorigin` — `GREEK` | `HEBREW` (for OT books with multiple textual traditions, e.g. Esther)
- `keyword` *(required, /search)*; `exactmatch` (bool); `versions` *(required, /metadata/versionindex)*
- `return` — `json` | `xml` | `html` (Accept header preferred)
