# API Keys and Plugin Installations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every v4 data request is attributed to an approved developer project (project key) or a registered plugin installation (installation token), with per-credential limits, revocation and IP-free telemetry.

**Architecture:** The endpoint owns four new tables (applications, api_keys, client_keys, installations) plus a daily usage counter. A credential middleware resolves `X-Api-Key` to a credential before the handler; a file-based sliding-window limiter follows. Developer and admin routes validate Zitadel bearer tokens (JWKS) issued to the Next.js frontend; the quote pipeline loses its IP limiter and IPinfo lookup and logs the credential instead.

**Tech Stack:** PHP 8.4, PostgreSQL 18, PSR-7/15 (nyholm, laminas), PHPUnit 13, `firebase/php-jwt` (new dependency, as LitCal).

**Spec:** `docs/superpowers/specs/2026-11-17-api-keys-installations-design.md`
**Reference implementation (read, adapt, don't import):** `../../LiturgicalCalendar/LiturgicalCalendarAPI` — `src/Repositories/ApiKeyRepository.php`, `src/Repositories/ApplicationRepository.php`, `src/Http/Middleware/ApiKeyMiddleware.php`, `src/Services/RateLimiter.php`, `src/Http/Middleware/ApiKeyRateLimitMiddleware.php`, `src/Handlers/ApplicationsHandler.php`, `src/Handlers/Admin/ApplicationAdminHandler.php`, `src/Http/Middleware/OidcAuthMiddleware.php`. Never open `*.pat` or `.env*` files there.

## Global Constraints

- Credential formats: `bibleget_{live|test}_<32 hex>` (project key), `bgclient_{live|test}_<32 hex>` (client key), `bginst_{live|test}_<32 hex>` (installation token); `live` iff `APP_ENV=production`; 32 hex = `bin2hex(random_bytes(16))`.
- Stored as SHA-256 hex of the full string plus a 20-character prefix; plaintext returned exactly once.
- Credentials travel only in the `X-Api-Key` header. No query-string form.
- Plugins: `wordpress|google-docs|libreoffice|openoffice|msword|webword`.
- Default limits: project keys 1000/hour, installation tokens 300/hour, client-key registrations 200/hour; system `reader` key exempt.
- No IP address, geolocation, `HTTP_*` or `REMOTE_ADDR` value is written by v4 code.
- `REQUIRE_CREDENTIALS`: when `APP_ENV=production` it is always true whatever the variable says; elsewhere it defaults to false.
- Errors are RFC 9457 problem+json through the existing `ApiException` hierarchy.
- Migrations are plain SQL in `migrations/`, idempotent, wrapped in `BEGIN/COMMIT`; destructive ones are drafted in `docs/future-migrations/`.
- Each new migration is added to `.github/workflows/ci.yaml` and the docker-compose init list next to `046`.
- `composer lint`, `composer analyse` (PHPStan level 10) and complexity ≤ 20 per function must pass.

## Review Focus

1. A key sent as `?api_key=…` or `Authorization: Bearer bibleget_…` (people will try both) — must be ignored, giving `401` when credentials are required, never a silent pass. → Task 3, `testKeyOutsideHeaderIsIgnored`.
2. Two concurrent first registrations with the same `install_id` — one installation row, the loser gets `200` with a valid token, never `500`. → Task 4, `testConcurrentFirstRegistrationYieldsOneRow`.
3. The first request of a new year — the new `requests_log__YYYY` table must have the credential columns. → Task 1, `testYearlyLogTableHasCredentialColumns`.
4. A project revoked by an admin while its key's `last_used_at` write is throttled — the next request must be `401`. → Task 3, `testRevokedApplicationKeyFailsImmediately`.
5. Rate-limit storage directory missing or unwritable — requests are served (fail open) and an error is logged, never a `500`. → Task 3, `testLimiterFailsOpenWhenStorageUnwritable`.

---

### Task 1: Migration 048 — schema

**Files:**
- Create: `migrations/048-api-keys-installations.sql`
- Modify: `tests/fixtures/schema.sql` (nothing, if CI applies 048 after the fixtures; otherwise mirror), `.github/workflows/ci.yaml`, `docker-compose.yml`
- Test: `tests/Integration/Database/ApiKeysSchemaTest.php`

**Interfaces:**
- Produces tables `applications`, `api_keys`, `client_keys`, `installations`, `usage_daily`; column `versions_available.reader_full_text`; columns `credential_type VARCHAR(16)`, `credential_id UUID` on every `requests_log__YYYY` and in `create_yearly_log_table()`.

- [ ] **Step 1: Write the failing tests**

```php
public function testTablesExist(): void            // the five tables exist with the spec's columns
public function testScopeReaderOnlyOnSystemApplications(): void
    // inserting an api_keys row with scope 'reader' for an application with is_system=false raises (trigger or CHECK via function)
public function testInstallationUniquePerClientKeyAndInstallId(): void
    // second INSERT with the same (client_key_id, install_id) raises unique_violation (23505)
public function testPluginCheck(): void              // client_keys.plugin = 'mediawiki' raises check_violation
public function testReaderFullTextDefaultsFalse(): void
public function testYearlyLogTableHasCredentialColumns(): void
    // SELECT create_yearly_log_table('2099'); then INSERT INTO requests_log__2099 (credential_type, credential_id, "QUERY") succeeds; DROP afterwards
public function testMigrationIsIdempotent(): void    // applying 048 twice leaves the same schema
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Database/ApiKeysSchemaTest.php` — Expected: FAIL (relations missing).

- [ ] **Step 3: Write `migrations/048-api-keys-installations.sql`.** Columns exactly as the spec's "Data model". `applications.status` CHECK `pending|approved|rejected|revoked`; `api_keys.scope` CHECK `read|reader`, reader-only-on-system enforced by a trigger; `installations.token_hash` UNIQUE; `usage_daily` PK (`day`, `credential_type`, `credential_id`, `route`) with `credential_id` NOT NULL and `'00000000-0000-0000-0000-000000000000'` for uncredentialed staging requests; `credential_type` CHECK `project|installation|none`. `CREATE OR REPLACE FUNCTION create_yearly_log_table` keeps 036's body and adds the two columns; `ALTER TABLE … ADD COLUMN IF NOT EXISTS` on every existing `requests_log__YYYY`.

- [ ] **Step 4: Run** the test file — Expected: PASS. Then `composer test:quick` — Expected: PASS.

- [ ] **Step 5: Commit** `feat(db): 048 applications, keys, client keys, installations, usage counters (#97)`

### Task 2: Credential primitives and repositories

**Files:**
- Create: `src/Auth/CredentialType.php` (enum `Project|Client|Installation` with `prefix(): string`), `src/Auth/CredentialFactory.php`, `src/Auth/Credential.php` (readonly value object), `src/Repositories/ApplicationRepository.php`, `src/Repositories/ApiKeyRepository.php`, `src/Repositories/ClientKeyRepository.php`, `src/Repositories/InstallationRepository.php`
- Test: `tests/Unit/Auth/CredentialFactoryTest.php`, `tests/Integration/Repositories/*RepositoryTest.php`

**Interfaces:**
- `CredentialFactory::generate(CredentialType $type, string $appEnv): array{plain: string, hash: string, prefix: string}`
- `CredentialFactory::typeOf(string $presented): ?CredentialType` (by prefix; null for anything else)
- `CredentialFactory::hash(string $presented): string`
- `final readonly class Credential { CredentialType $type; string $id; string $scope; ?int $ratePerHour; ?string $applicationId; ?string $installationId; ?string $plugin; bool $isSystem; }`
- `ApiKeyRepository::findActiveByHash(string $hash): ?Credential` (joins applications; null unless key active, unexpired and application `approved`)
- `InstallationRepository::findActiveByHash(string $hash): ?Credential`; `register(string $clientKeyId, string $installId, string $pluginVersion, ?string $siteUrl): array{token: string, installation_id: string, created: bool}`; `updateSelf(string $installationId, array $fields): void`; `touch(string $id): void`; `revoke(string $id): void`; `revokeRegisteredBetween(string $clientKeyId, DateTimeImmutable $from, DateTimeImmutable $to): int`
- `ClientKeyRepository::findActiveByHash(string $hash): ?array{id: string, plugin: string, max_registrations_per_hour: int}`; `mint(string $plugin, string $pluginVersion): array{plain: string, id: string}`; `revoke(string $id): void`
- `ApplicationRepository`: `create`, `findForOwner`, `listForOwner`, `update`, `delete`, `resubmit`, `approve`, `reject`, `revoke` (revoke also sets the project's keys inactive), `list(?string $status)`; `ApiKeyRepository`: `mint(string $applicationId, string $name, string $scope = 'read', ?int $ratePerHour = 1000): array{plain: string, id: string}`, `listForApplication`, `revoke`, `rotate`, `touch(string $id): void`

- [ ] **Step 1: Write failing tests**

```php
// CredentialFactoryTest
public function testFormatPerTypeAndEnv(): void
    // generate(Project,'production')['plain'] matches /^bibleget_live_[0-9a-f]{32}$/; Client,'staging' → /^bgclient_test_[0-9a-f]{32}$/; Installation → bginst_
public function testHashIsSha256OfPlainAndPrefixIs20Chars(): void
public function testTypeOfRecognisesOnlyKnownPrefixes(): void   // 'litcal_live_…', '', 'bibleget_' + 'x' → null
// InstallationRepositoryTest
public function testRegisterNewThenReRegisterRotatesTokenWithoutNewRow(): void
    // first: created=true; second with same install_id: created=false, new token valid, old token findActiveByHash → null, row count 1
public function testRevokeRegisteredBetweenOnlyHitsThatWindow(): void
// ApiKeyRepositoryTest
public function testKeyOfPendingOrRevokedApplicationIsNotFound(): void
public function testRevokingApplicationDeactivatesItsKeys(): void
public function testRotateRevokesOldAndReturnsNewWithSameScope(): void
```

- [ ] **Step 2: Run** them — Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** the signatures above, adapting LitCal's `ApiKeyRepository` and `ApplicationRepository` (UUID ids as strings throughout; LitCal's `is_int` id guard is a known bug, don't copy it). `register()` uses `INSERT … ON CONFLICT (client_key_id, install_id) DO UPDATE SET token_hash = EXCLUDED.token_hash, … RETURNING (xmax = 0) AS created`.
- [ ] **Step 4: Run** — Expected: PASS; `composer analyse` clean.
- [ ] **Step 5: Commit** `feat(auth): credential formats and repositories (#97)`

### Task 3: Credential, rate-limit and usage middleware on data routes

**Files:**
- Create: `src/Http/Exception/UnauthorizedException.php` (401; add `UNAUTHORIZED = 401` to `src/Http/Enum/StatusCode.php`), `src/Http/Middleware/CredentialMiddleware.php`, `src/Services/RateLimiter.php`, `src/Http/Middleware/RateLimitMiddleware.php`, `src/Http/Middleware/UsageMiddleware.php`
- Modify: `src/Router.php` (pipe the three middleware for `quote`, `metadata`, `search` only), `.env.example`, `tests/Server/ServerTestCase.php` (allow a subclass to pass extra environment to the spawned server; `Dotenv::createImmutable` keeps process env)
- Test: `tests/Unit/Http/Middleware/CredentialMiddlewareTest.php`, `tests/Unit/Services/RateLimiterTest.php`, `tests/Server/CredentialsHttpTest.php`

**Interfaces:**
- Consumes: Task 2's repositories and `CredentialFactory`.
- Produces: request attribute `'credential'` → `?Credential`; `RateLimiter::hit(string $identifier, int $limit): array{allowed: bool, remaining: int, reset: int}`; env `REQUIRE_CREDENTIALS`, `RATE_LIMIT_STORAGE_PATH` (default `logs/rate_limits`).

- [ ] **Step 1: Write failing tests**

```php
// CredentialsHttpTest (server started with REQUIRE_CREDENTIALS=true; fixtures insert an approved app + key, a client key, an installation)
public function testNoKeyIs401WithLinkToDeveloperPages(): void
    // status 401, content-type application/problem+json, body.detail mentions X-Api-Key
public function testProjectKeyAndInstallationTokenAreServed(): void   // 200 on /quote?query=Genesis1,1&version=TEST1
public function testClientKeyOnDataRouteIs403(): void
public function testKeyOutsideHeaderIsIgnored(): void
    // ?api_key=<valid> → 401; Authorization: Bearer <valid project key> → 401
public function testRevokedApplicationKeyFailsImmediately(): void
    // request OK, revoke application in DB, next request 401
public function testRateLimitHeadersAnd429(): void
    // key with rate_limit_per_hour=2: X-RateLimit-Limit: 2, third request 429 with Retry-After
public function testUsageDailyCountsEveryDataRoute(): void
    // one /quote, one /metadata/bibleversions → two usage_daily rows for the key, requests = 1 each
// RateLimiterTest
public function testSlidingWindow(): void
public function testLimiterFailsOpenWhenStorageUnwritable(): void   // path '/proc/nonexistent' → allowed=true, error logged
// CredentialMiddlewareTest
public function testProductionForcesRequireCredentials(): void        // APP_ENV=production, REQUIRE_CREDENTIALS=false → still 401 without key
public function testWithoutRequirementUnkeyedRequestPassesWithNullCredential(): void
```

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.** `CredentialMiddleware` routes by `CredentialFactory::typeOf`, looks up by hash, throws `UnauthorizedException` (missing/unknown/inactive) or `ForbiddenException` (client key on a data route); `touch()` at most once a minute per credential (in-process static map is not enough under PHP-FPM — use `UPDATE … WHERE last_seen_at < now() - interval '1 minute'`). `RateLimiter` mirrors LitCal's file-per-identifier sliding window with `flock`; identifiers `project:<id>` / `installation:<id>`; skipped for `isSystem` and for a null credential. `UsageMiddleware` upserts `usage_daily` after the handler returns (route = first path segment plus second for `search/*`), best-effort (errors logged, never thrown).
- [ ] **Step 4: Run** — Expected: PASS; full `composer test` still green (existing server tests run with the requirement off).
- [ ] **Step 5: Commit** `feat(auth): credentials, per-credential rate limits and usage counters on data routes (#97)`

### Task 4: Installation registration

**Files:**
- Create: `src/Handlers/InstallationsHandler.php`
- Modify: `src/Router.php` (`installations` route: no `CredentialMiddleware`; the handler validates the client key or installation token itself)
- Test: `tests/Server/InstallationsHttpTest.php`

**Interfaces:**
- Consumes: `ClientKeyRepository`, `InstallationRepository`, `RateLimiter` (identifier `registrations:<client_key_id>`, limit `max_registrations_per_hour`).
- Produces: `POST /installations` (body `install_id` UUID, `plugin_version` ≤ 50 chars, `site_url` optional URL ≤ 255, only when the key's plugin is `wordpress`) → `201|200 {token, installation_id}`; `PATCH /installations/self` (installation token) with any of `plugin_version`, `site_url`, `contact_email` (valid email or `""` to clear) → `204`.

- [ ] **Step 1: Write failing tests**

```php
public function testFirstRegistrationIs201AndTokenWorksOnQuote(): void
public function testReRegistrationIs200AndOldTokenStopsWorking(): void
public function testRevokedClientKeyIs401(): void
public function testExistingInstallationsSurviveClientKeyRevocation(): void
public function testRegistrationCapIs429WithRetryAfter(): void        // max_registrations_per_hour=1 on the fixture key
public function testInvalidInstallIdIs422(): void                     // 'abc', missing
public function testSiteUrlRejectedForNonWordpressPlugin(): void      // 422
public function testPatchSelfSetsAndClearsContactEmail(): void
public function testConcurrentFirstRegistrationYieldsOneRow(): void
    // two curl_multi requests with the same install_id → statuses {201,200} or {201,201}… must be no 500 and exactly one row
```

- [ ] **Step 2: Run** — Expected: FAIL. **Step 3: Implement.** **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(auth): plugin installation registration (#97)`

### Task 5: Quote pipeline without IPs; reader scope

**Files:**
- Modify: `src/Pipeline/QueryExecutor.php` (delete `getAndValidateIpAddress`, `validateIPAddress`, `isWhitelisted`, the four `check*` limiters, `enforceQueryLimitsAndLog`, `getGeoIPInfoFromLogsElseOnline`, `getGeoIPFromLogs`, `geoIPInfoIsEmptyOrIsError`, `buildLogUnion*`, `getLogTables` if unused; `logQuery()` writes `credential_type`, `credential_id`, `ORIGIN`, `QUERY`, `ORIGINALQUERY`, `REQUEST_METHOD` only, best-effort), `src/Pipeline/QuoteContext.php` (remove `forceversion`/`forcecopyright`, `appid`, `domain`, `pluginversion` from `DATA` handling; take `?Credential`; add the reader rule), `src/Handlers/QuoteHandler.php` (pass the request's credential into `QuoteContext`)
- Test: `tests/Unit/Pipeline/CopyrightLimitTest.php`, `tests/Server/QuoteHandlerHttpTest.php`, `tests/Integration/Pipeline/QueryExecutorLogTest.php`

**Interfaces:**
- Consumes: `Credential` (Task 2) from request attribute `'credential'`.
- Produces: `QuoteContext::fullTextAllowed(string $sigla): bool` — true iff credential scope is `reader` and `versions_available.reader_full_text` is true for that edition; those editions go into `REQUESTED_COPYRIGHTED_VERSIONS`.

- [ ] **Step 1: Write failing tests**

```php
// CopyrightLimitTest: decision table
//   null credential / read / reader × reader_full_text false|true → only (reader, true) lifts the limit
public function testOnlyReaderScopeOnFlaggedEditionLiftsLimit(): void
// QuoteHandlerHttpTest
public function testForceParametersHaveNoEffect(): void   // forcecopyright=true on a copyrighted TEST edition, 40-verse range → limited as without it
// QueryExecutorLogTest
public function testLogRowHasCredentialAndNoIp(): void
    // after a keyed /quote: latest requests_log__<year> row has credential_type='project', credential_id=<key id>, WHO_IP IS NULL, WHO_WHERE_JSON IS NULL
```

- [ ] **Step 2: Run** — Expected: FAIL. **Step 3: Implement.** **Step 4: Run** — Expected: PASS; `grep -rn "ipinfo\|WHO_IP\|REMOTE_ADDR" src/` returns nothing except comments.
- [ ] **Step 5: Commit** `feat(quote): log credentials, not IPs; reader scope replaces force parameters (#97)`

### Task 6: Bearer-token validation and developer routes

**Files:**
- Modify: `composer.json` (`firebase/php-jwt: ^7.0`)
- Create: `src/Http/Middleware/OidcBearerMiddleware.php`, `src/Services/JwksProvider.php`, `src/Handlers/ApplicationsHandler.php`
- Modify: `src/Router.php` (`applications` route behind `OidcBearerMiddleware`), `.env.example` (`ZITADEL_ISSUER`, `ZITADEL_AUDIENCE`, `ZITADEL_JWKS_FILE` — test-only override)
- Test: `tests/Unit/Http/Middleware/OidcBearerMiddlewareTest.php`, `tests/Server/ApplicationsHttpTest.php`, fixture `tests/fixtures/oidc/` (test RSA key pair + JWKS, generated by the test bootstrap, not committed private keys if avoidable)

**Interfaces:**
- Produces: request attribute `'oidc_user'` → `array{sub: string, email: string, email_verified: bool, roles: list<string>}` (roles from claim `urn:zitadel:iam:org:project:roles`, keys of that object); `OidcBearerMiddleware::requireRole(?string $role)`; JWKS fetched from `{ZITADEL_ISSUER}/oauth/v2/keys`, cached in `RATE_LIMIT_STORAGE_PATH/../jwks.json` for 1 hour, refetched once on unknown `kid`.
- Routes (LitCal shapes): `GET|POST /applications`, `GET|PATCH|DELETE /applications/{id}`, `POST /applications/{id}/resubmit`, `GET|POST /applications/{id}/keys`, `DELETE /applications/{id}/keys/{keyId}`, `POST /applications/{id}/keys/{keyId}/rotate`. Creating an application requires `email_verified`; keys only for `approved` applications; scope always `read`.

- [ ] **Step 1: Write failing tests**

```php
public function testMissingOrMalformedBearerIs401(): void
public function testWrongIssuerAudienceOrExpiredTokenIs401(): void
public function testUnverifiedEmailCannotCreateApplication(): void      // 403
public function testOwnerSeesOnlyOwnApplications(): void
public function testKeyMintingRequiresApproval(): void                 // pending → 409; approved → 201 with plain key once
public function testRotateAndRevokeOwnKey(): void
```

- [ ] **Step 2–4:** run (FAIL), implement, run (PASS).
- [ ] **Step 5: Commit** `feat(auth): Zitadel bearer validation and developer application routes (#97)`

### Task 7: Admin routes and statistics

**Files:**
- Create: `src/Handlers/Admin/ApplicationAdminHandler.php`, `src/Handlers/Admin/ClientKeyAdminHandler.php`, `src/Handlers/Admin/InstallationAdminHandler.php`, `src/Handlers/Admin/StatsHandler.php`
- Modify: `src/Router.php` (`admin/*` behind `OidcBearerMiddleware` with role `bibleget_admin`)
- Test: `tests/Server/AdminHttpTest.php`

**Interfaces:**
- Routes: `GET /admin/applications[?status=]`, `POST /admin/applications/{id}/approve|reject|revoke` (body `notes`; state transitions as LitCal: approve from pending|rejected, reject from pending, revoke from approved → else `409`); `GET|POST /admin/client-keys` (body `plugin`, `plugin_version` → plain key once), `POST /admin/client-keys/{id}/revoke`; `GET /admin/installations?plugin=&version=&active_since=&registered_from=&registered_to=`, `POST /admin/installations/{id}/revoke`, `POST /admin/installations/revoke-batch` (body `client_key_id`, `from`, `to` → `{revoked: n}`); `PATCH /admin/api-keys/{id}` and `PATCH /admin/installations/{id}` (`rate_limit_per_hour`); `GET /admin/stats?from=&to=` → `{installations: [{plugin, version, total, active_30d}], registrations_per_hour: [{client_key_id, hour, count}], requests: [{day, credential_type, credential_id, label, route, requests}]}`.

- [ ] **Step 1: Write failing tests**

```php
public function testNonAdminIs403(): void
public function testApproveRejectRevokeTransitions(): void       // incl. 409 on reject of approved
public function testMintClientKeyReturnsPlainOnce(): void        // GET list shows prefix only
public function testBatchRevokeHitsOnlyTheWindow(): void
public function testStatsCountsInstallationsAndRequests(): void
```

- [ ] **Step 2–4:** run (FAIL), implement, run (PASS).
- [ ] **Step 5: Commit** `feat(admin): approval, client keys, installations and usage statistics (#97)`

### Task 8: Scripts, documentation, CI and the deferred log cleanup

**Files:**
- Create: `scripts/mint-reader-key.php` (upserts the `is_system` application "BibleGet website", mints a `reader` key, prints it once; idempotent on the application), `scripts/mint-client-key.php` (CLI equivalent of `POST /admin/client-keys`, for bootstrapping before the frontend exists), `docs/future-migrations/049-strip-personal-data-from-request-logs.sql` (spec's list of columns set to NULL in every `requests_log*` table; drop `*_ip_when` indexes; idempotent; header says to run `VACUUM FULL` on those tables afterwards)
- Modify: `openapi.json` (security scheme `ApiKey` in header `X-Api-Key` on data routes; `401`/`403`/`429` responses; `/installations`, `/applications*`, `/admin/*` paths; remove `forceversion`/`forcecopyright`/`appid`/`domain`/`pluginversion`), `docs/CHANGELOG.md`, `tests/Live/LiveTestCase.php` (send `X-Api-Key` from `BIBLEGET_LIVE_API_KEY` when set), `.github/workflows/deploy.yaml` live-tests job (pass that secret), `CLAUDE.md` (Database / Key Implementation Details: credentials replace IP limits), `docs/plans/identity-rbac-and-fine-grained-authz.md` (status line: phases 1–2 superseded by the spec)
- Test: `tests/Integration/Database/StripRequestLogPersonalDataTest.php` (applies the future migration to a temp copy of a yearly table: personal columns NULL, other columns intact, second run no-op)

- [ ] **Step 1:** write the test — FAIL. **Step 2:** write the SQL — PASS.
- [ ] **Step 3:** scripts, docs, `openapi.json`; run `composer lint:openapi` — Expected: valid.
- [ ] **Step 4:** `composer test` — Expected: all suites green.
- [ ] **Step 5: Commit** `docs(auth): OpenAPI security, scripts, changelog; draft request-log cleanup (#97)`
