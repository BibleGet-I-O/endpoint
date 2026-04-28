# BibleGet I/O Endpoint — Project Overview

**REST API service** for retrieving Bible quotations across multiple versions and languages.

**Production URL**: `https://query.bibleget.io/v3/`
**Repo**: `BibleGet-I-O/endpoint` on GitHub
**Note**: the directory and Serena project are named `endpoint` — the actual project is BibleGet I/O.

## Purpose
Serves the BibleGet WordPress / Google Docs / Microsoft Word / OpenOffice / LibreOffice plugins (and any third-party apps) with:
- Bible quote retrieval (multiple versions, JSON/XML/HTML output)
- Metadata (book names in 25+ languages, available versions, chapter/verse indexes)
- Keyword search

## Versioning
- v3 is the current/active major version (this repo's main code)
- v2 still served at `https://query.bibleget.io/v2/` (legacy)
- Bare `https://query.bibleget.io` URL is being retired so version is always explicit

## Tech Stack
- **PHP >= 8.2**
- **PostgreSQL** (via PDO `ext-pdo_pgsql`) — IPs stored as native `inet`, full-text search uses `tsvector`/`tsquery` + GIN
- **PSR-7** HTTP messages — `nyholm/psr7`
- **PSR-15** request handlers + middleware — `psr/http-server-handler`, `psr/http-server-middleware`
- **PSR-17** factories — `nyholm/psr7` (`Psr17Factory`)
- **PSR-3** logging — `monolog/monolog` (rotating file handler in `logs/`)
- **PSR-4** autoload — `BibleGet\Api\` → `src/`; tests `BibleGet\Tests\` → `tests/`
- **RFC 9457** error responses (`application/problem+json`)
- Response emission — `laminas/laminas-httphandlerrunner` (`SapiEmitter`)
- Env: `vlucas/phpdotenv`
- Quality: PHPUnit 11, PHPStan **level 10**, PHP_CodeSniffer (PSR-12 + custom rules), CaptainHook for git hooks

## Architecture mirrors LiturgicalCalendarAPI
The v3 codebase explicitly follows the same PSR-based architecture as `LiturgicalCalendarAPI` (Router → MiddlewarePipeline → AbstractHandler → response via Negotiator). Familiarity with that codebase carries over here.

## Repo Location
`/home/johnrdorazio/development/BibleGet-I-O/endpoint`

Sibling repos under `BibleGet-I-O/`:
- `bibleget-wordpress` — WP plugin
- `mediawiki-extensions-BibleGet` — MediaWiki extension

## Branches
- `master` — stable / production
- `development` — active development
PRs target `development`.

## CI/CD
- GitHub Actions:
  - `.github/workflows/ci.yaml` — runs phpcs + phpstan + tests on PRs
  - `readme.yaml` — syncs `openapi.json` to ReadMe.io on push to `master`

## Operational notes
- **Bot protection**: User-Agent regex blocks `bot|crawl|slurp|spider`
- **CORS**: enabled with dynamic origin headers via `AbstractHandler`
- **Rate limiting**: IP-based, 2-day windows, thresholds 10/30/100 → `TooManyRequestsException`
- **Copyright versions**: enforce 30-verses-per-request limit (e.g. `CEI2008`, `NABRE`, `BLPD`)
- **Notation**: auto-detect ENGLISH (`John 3:16`) vs EUROPEAN (`Giovanni 3,16`); MIXED is an error
- **Verse ordering**: uses `verseID` field to handle Greek subverses correctly
