# When a Coding Task Is Complete — BibleGet endpoint

Run before declaring done / committing:

1. **Lint**
   ```bash
   composer lint:fix    # phpcbf (auto-fix)
   composer lint        # phpcs verification
   ```

2. **Static analysis (PHPStan level 10)**
   ```bash
   composer analyse
   ```

3. **Tests (PHPUnit 11)**
   ```bash
   composer test            # full: Unit + Integration (PostgreSQL) + Server (HTTP)
   composer test:quick      # exclude @group slow for fast iteration
   ```

   Integration suite needs a running PostgreSQL — easiest is `docker compose up -d` first.

4. **OpenAPI lint** (if `openapi.json` changed)
   ```bash
   composer lint:openapi
   ```

5. **Manual smoke test** — start the server and exercise affected route(s):
   ```bash
   composer start
   curl 'http://localhost:8000/v3/quote?query=Mt1,1-5&version=NABRE&appid=local-test'
   curl http://localhost:8000/v3/metadata/bibleversions
   composer stop
   ```

6. **Verify error path** — for changes that touch validation/exceptions, hit a known-bad input and confirm:
   - Status code is correct
   - Response is `application/problem+json`
   - Body has `type`, `title`, `status`, `detail`

7. **For DB schema changes**
   - Add migration in `migrations/`
   - Apply locally and run integration tests against the new schema
   - Document the change in `CLAUDE.md` if it affects the "Key tables" list

## Pre-commit (CaptainHook)
Will run automatically. Don't `--no-verify`. If a hook fails, fix the issue and create a NEW commit.

## Branch / PR rules
- Branch off `development`
- PRs target `development` (NEVER `master` directly)
- `master` updates trigger ReadMe.io sync of `openapi.json` — be deliberate

## CI
`.github/workflows/ci.yaml` runs phpcs + phpstan + tests on PRs. Match local results to that pipeline.
