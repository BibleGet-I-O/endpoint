# Suggested Commands — BibleGet endpoint

## First-time setup
```bash
composer install                    # install deps
composer dump-autoload              # regenerate autoloader if needed
cp .env.example .env                # then edit (DB credentials, etc.)
vendor/bin/captainhook install -f   # (re)install git hooks
```

## Local dev with Docker (PostgreSQL + PHP/Apache)
```bash
docker compose up -d
docker compose down
docker compose logs -f app          # tail app logs
```

## Local dev with PHP built-in server (requires external PostgreSQL)
```bash
php -S localhost:8000 -t public public/router.php
# Or via shell scripts:
composer start         # ./start-server.sh
composer stop          # ./stop-server.sh
composer restart       # ./restart-server.sh
```

## Tests (PHPUnit 11)
```bash
composer test                     # all suites: Unit + Integration (PostgreSQL) + Server (HTTP)
composer test:quick               # excludes @group slow
composer test:coverage            # text + HTML report → coverage/
```

## Static analysis
```bash
composer analyse                  # phpstan analyse — level 10
```

## Linting
```bash
composer lint                     # phpcs (PSR-12 + custom)
composer lint:fix                 # phpcbf
composer lint:openapi             # npx @redocly/cli lint openapi.json
```

## Hitting the API
```bash
# Quote
curl 'http://localhost:8000/v3/quote?query=Mt1,1-5&version=NABRE&appid=local-test'

# Metadata
curl http://localhost:8000/v3/metadata/biblebooks
curl http://localhost:8000/v3/metadata/bibleversions
curl 'http://localhost:8000/v3/metadata/versionindex?versions=NABRE'

# Search
curl 'http://localhost:8000/v3/search?keyword=light&version=NABRE'

# Set Accept header instead of return param:
curl -H 'Accept: application/xml' 'http://localhost:8000/v3/quote?query=Mt1,1&appid=local-test'
```

## Git workflow
```bash
git checkout development
git pull origin development
git checkout -b feature/your-feature
# PRs target `development`; `master` is stable/production
```

## OpenAPI sync
- Editing `openapi.json` triggers ReadMe.io sync on push to `master` (via `.github/workflows/readme.yaml`).
- Lint locally first: `composer lint:openapi`.

## System utilities (Linux/WSL2)
GNU coreutils. Prefer Serena's `find_file`, `search_for_pattern`, `find_symbol` over shell `find`/`grep` inside the repo.
