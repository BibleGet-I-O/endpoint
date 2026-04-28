# Code Style & Conventions — BibleGet endpoint

## Standards
- **PHP >= 8.2**
- **PSR-12** (with custom rules in `phpcs.xml`); enforced by `phpcs`, auto-fix via `phpcbf`
- **PHPStan level 10** (`phpstan.neon.dist`)
- **PSR-4** autoload: `BibleGet\Api\` → `src/`; tests `BibleGet\Tests\` → `tests/`
- Strict PSR-7/15/17 architecture (see project_structure memory)

## Required PHP extensions
`ext-pdo_pgsql`, `ext-json`, `ext-simplexml`, `ext-dom`

## Architectural conventions

### Adding a new handler
1. Create class in `src/Handlers/` extending `AbstractHandler`
2. Implement `handle(ServerRequestInterface $request): ResponseInterface`
3. Configure allowed methods, accept headers, content types via the constructor / parent
4. Add a route case in `src/Router.php`
5. Use built-in content negotiation; return PSR-7 `ResponseInterface`

### Errors
- Throw `ApiException` subclasses from `src/Http/Exception/`
- `ErrorHandlingMiddleware` will convert them to RFC 9457 `application/problem+json`
- Don't catch + transform errors at the handler level — let them propagate

### Logging
- Use `LoggerFactory::create(...)` in `src/Http/Logs/`
- PSR-3 Monolog with rotating file handler under `logs/`

### Database access
- Always go through `Database\Connection` (PDO singleton)
- `ERRMODE_EXCEPTION` and `FETCH_ASSOC` are default
- Use parameterized queries — never string-concatenate user input
- Per-version tables are quoted identifiers: `"{VERSION}"`, `"{VERSION}_idx"` (case-sensitive in PostgreSQL — keep the double-quotes)
- IPs go into `inet` columns (don't quote as plain strings if writing raw SQL)
- Full-text search uses `tsvector` columns + GIN indexes; queries via `tsquery`

### Quote pipeline (`src/Pipeline/`)
- All shared state lives in `QuoteContext` (NOT in handler properties)
- Order: `QueryValidator` → `QueryFormulator` → `QueryExecutor`
- `QueryExecutor` is responsible for rate limiting (throws `TooManyRequestsException`) and request logging — don't duplicate that elsewhere

### Bible reference notation
- ENGLISH (`John 3:16`) vs EUROPEAN (`Giovanni 3,16`) auto-detected
- A detection result of `MIXED` is invalid — the validator emits an error
- Return responses always include `info.detectedNotation` with `ENGLISH` | `EUROPEAN` (or `MIXED` on error)

### Verse ordering
- Use the `verseID` field for ordering — handles Greek subverses correctly
- Don't sort by `verse` numerically (verses can have letter suffixes)

### Copyright enforcement
- Versions in the `copyrightversions` set (e.g. `CEI2008`, `NABRE`, `BLPD`) cap a single request to 30 verses
- Enforcement is server-side — clients can't override

### Rate limits
- IP-based, 2-day rolling window, thresholds at 10 / 30 / 100 requests
- Past threshold → `TooManyRequestsException` → HTTP 429 problem+json

### CORS / bot protection (`AbstractHandler`)
- Dynamic `Access-Control-Allow-Origin` per request
- Block requests whose User-Agent matches `bot|crawl|slurp|spider`

### Content negotiation
- `return` query parameter takes priority for back-compat (json|xml|html)
- Otherwise, `Accept` header (`application/json`, `application/xml`, `text/html`)
- Default: JSON

## Response data shape (consistent across endpoints)
- `results` — array (verses or empty)
- `errors` — array of strings (always check this client-side)
- `info` — object with at minimum `ENDPOINT_VERSION` ("3.0"), plus endpoint-specific fields:
  - `/quote` adds `detectedNotation`, `bibleVersionsInfo`
  - `/metadata/bibleversions` adds `validversions`, `validversions_fullname`, `copyrightversions`
  - `/metadata/biblebooks` adds `languages`
  - `/metadata/versionindex` adds `indexes`
  - `/search` adds `keyword`, `version`

## Naming
- Namespaces / classes: PascalCase
- Methods, properties, vars: camelCase
- Class constants: UPPER_SNAKE_CASE
- Enum cases: per PHP enum conventions (typically PascalCase)

## Pre-commit (CaptainHook)
Configured in `captainhook.json`. Don't bypass with `--no-verify` unless explicitly authorized. Reinstall hooks if config changes:
```bash
vendor/bin/captainhook install -f
```
