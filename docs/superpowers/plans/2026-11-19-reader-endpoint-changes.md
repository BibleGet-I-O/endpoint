# Reader: endpoint changes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the website reader what it needs from the API: an admin switch for each edition's full text, `/quote` without search vectors, reading an edition in its own Psalm numbering with each psalm row's Hebrew reference, and an unlimited `read` key for visitors.

**Architecture:** Four independent changes in the existing PSR-15 pipeline. A new admin handler under `/admin/versions`, a query parameter `numbering` threaded from `QuoteContext` into `AstValidator` and `QueryFormulator`, a row field added in `QueryExecutor::prepareResponse()`, and a `--scope` option on the mint script.

**Tech Stack:** PHP 8.4, PostgreSQL, PHPUnit 13 (unit, integration, server suites), PHPCS, PHPStan level 10.

**Spec:** `docs/superpowers/specs/2026-11-19-website-reader-design.md` ("Endpoint changes", items 1–5). The website itself is a separate plan: `docs/superpowers/plans/2026-11-19-website-reader.md`.

## Global Constraints

- `/admin/*` routes need the `bibleget_admin` role (already enforced by `Router` for the `admin` prefix).
- The 30-verse rule is unchanged: whole chapters only for a `reader`-scope credential on an edition with `reader_full_text = true` (`QuoteContext::copyrightLimitedVersions()`).
- `PsalmNumbering` stays the only place that maps Hebrew ↔ Vulgate psalm numbers; nothing duplicates its table.
- Default behaviour of `/quote` is unchanged for existing clients: `numbering` defaults to `hebrew`.
- Every change documented in `openapi.json`; `composer lint`, `composer analyse`, `composer test` green.
- Stage files by name; never commit `.env*`, `.zitadel-data/`, `.stack-out/`.

## Review Focus

1. `numbering=edition` on a Hebrew-numbered edition must behave exactly like the default (only VGCL/DRB differ). Test in Task 3.
2. A Vulgate psalm that spans two Hebrew psalms (VGCL 9, 113) and the halves of Hebrew 116/147 (VGCL 114/115, 146/147) must report the right `psalm_hebrew` for every verse, including DRB's kept verse numbers. Test in Task 3.
3. `PATCH /admin/versions/{sigla}` with an unknown sigla, a non-boolean, or a lowercase sigla: 404, 422, and 404 respectively; never a 500. Test in Task 2.
4. A whole-chapter request of a copyrighted edition with `numbering=edition` must still be capped at 30 verses for a non-reader key. Test in Task 3.
5. The `read` key minted for visitors must not be mintable with scope `reader` on a non-system application, and re-running the script must add a key, not fail. Test in Task 4.

---

### Task 1: `/quote` results without search vectors

**Files:**
- Modify: `src/Pipeline/QueryExecutor.php:114`
- Test: `tests/Integration/Handlers/QuoteHandlerTest.php`

**Interfaces:** Produces: quote result rows never contain the keys `embedding`, `embedding_labse`, `text_hash`, `embedded_text_hash`.

- [ ] **Step 1: Write the failing test** `testQuoteRowsCarryNoEmbeddingColumns` in `QuoteHandlerTest`: request `Mt5,1-3` in an edition whose fixture rows have `embedding_labse` set; for every row assert `assertArrayNotHasKey('embedding_labse', $row)` and the same for `embedding`, `text_hash`, `embedded_text_hash`.
- [ ] **Step 2: Run:** `vendor/bin/phpunit --filter testQuoteRowsCarryNoEmbeddingColumns` → FAIL (`embedding_labse` present).
- [ ] **Step 3: Implement:** add `$row['embedding_labse']` to the `unset(...)` on line 114. Check the hybrid/semantic search handlers still rank (they read the vector before this point, per the comment above line 114).
- [ ] **Step 4: Run** the test and `vendor/bin/phpunit tests/Integration/Handlers` → PASS.
- [ ] **Step 5: Commit** `fix(quote): drop embedding_labse from /quote results`.

### Task 2: Admin switch for an edition's full text

**Files:**
- Create: `src/Handlers/Admin/VersionAdminHandler.php`, `src/Repositories/VersionRepository.php`
- Modify: `src/Router.php` (the `/admin/{resource}` match: add `'versions' => new VersionAdminHandler($this->psr17Factory, $pathParts)`), `openapi.json`
- Test: `tests/Integration/Repositories/VersionRepositoryTest.php`, `tests/Server/AdminHttpTest.php`

**Interfaces:**
- Produces: `GET /admin/versions` → `200 {"versions": [{"sigla": "NABRE", "fullname": "New American Bible - Revised Edition", "year": 2011, "language": "en", "copyright": true, "reader_full_text": false}, ...], "total": 7}` ordered by sigla; `PATCH /admin/versions/{sigla}` with `{"reader_full_text": bool}` → `200` with that one version object.
- `VersionRepository::list(): array<int, array{sigla:string, fullname:string, year:int, language:string, copyright:bool, reader_full_text:bool}>`, `find(string $sigla): ?array`, `setReaderFullText(string $sigla, bool $on): bool` (false when no row).
- `copyright` is `versions_available.copyright = 1`, the column `QuoteContext` reads into `COPYRIGHT_VERSIONS` (same source, so the admin page and the 30-verse rule cannot disagree).

- [ ] **Step 1: Repository tests** (`VersionRepositoryTest`, on the integration DB): `testListReturnsEveryBibleVersionWithFlags` (7 rows, `reader_full_text` all false after migration 048), `testSetReaderFullTextFlipsOneEditionOnly` (set NABRE true → `find('NABRE')['reader_full_text'] === true`, `find('CEI2008')` still false), `testSetReaderFullTextOnUnknownSiglaReturnsFalse`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Repositories/VersionRepositoryTest.php` → FAIL (class missing).
- [ ] **Step 3: Implement** `VersionRepository` with prepared statements on `versions_available WHERE type = 'BIBLE'`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: HTTP tests** in `AdminHttpTest`: `testVersionsListShowsFullTextFlags` (GET as admin → 200, `total` 7, each item has the six keys); `testPatchVersionTurnsFullTextOnAndOff` (PATCH NABRE true → 200 and `reader_full_text` true; then a `/quote` of `Mt5` with the system reader key returns 48 rows; PATCH false → the same quote returns 30); `testPatchVersionRejectsBadInput` (`{"reader_full_text":"yes"}` → 422; `/admin/versions/XYZ` → 404; `/admin/versions/nabre` → 404); `testVersionsNeedAdmin` (plain user → 403, no token → 401); `GET`/`PATCH` are the only methods (POST → 405).
- [ ] **Step 6: Run** `vendor/bin/phpunit --filter Version tests/Server/AdminHttpTest.php` → FAIL (404 route).
- [ ] **Step 7: Implement** `VersionAdminHandler extends AbstractAdminHandler` following `ClientKeyAdminHandler`: `dispatch()` — `$path === []` → GET list; one segment → GET/PATCH `[A-Z0-9]{2,10}` sigla; `reader_full_text` must be `is_bool` (else `ValidationException`). Wire it in `Router`.
- [ ] **Step 8: Run** the HTTP tests → PASS; add both operations to `openapi.json` under the admin tag (copy the client-keys security block); `composer lint:openapi`.
- [ ] **Step 9: Commit** `feat(admin): switch an edition's reader full text (/admin/versions)`.

### Task 3: `numbering=edition` and each psalm row's Hebrew reference

**Files:**
- Modify: `src/Pipeline/QuoteContext.php` (`$defaultParameters` += `'numbering' => ''`; normalise to `hebrew|edition`, default `hebrew`, as `text_tradition` is normalised at line ~121), `src/Pipeline/QueryValidator.php` (pass the mode to `AstValidator`), `src/Pipeline/Validator/AstValidator.php` (lines 125–128 and the Hebrew verse-limit check at ~246), `src/Pipeline/QueryFormulator.php:61`, `src/Pipeline/Transform/PsalmNumbering.php`, `src/Pipeline/QueryExecutor.php` (`prepareResponse`), `openapi.json`
- Test: `tests/Unit/Pipeline/Transform/PsalmNumberingTest.php`, `tests/Server/QuoteHandlerHttpTest.php`

**Interfaces:**
- `QuoteContext::NUMBERINGS = ['hebrew', 'edition']`; `$ctx->DATA['numbering']` is one of them.
- `AstValidator::__construct(array $bibleBooks, array $indexes, array $requestedVersions, bool $editionNumbering = false)`.
- `PsalmNumbering::toHebrew(int $chapter, int $verse, string $version): array{int, int}` — the inverse of `resolve()` for one verse: identity for Hebrew-numbered editions; for VGCL/DRB it inverts `SPANS`/`DRB_SPANS` and the `chapter − 1` zones. Built from the same constants (no second table).
- Every Psalms row (book 23) of `/quote`, `/search*` results gains `"psalm_hebrew": "23:4"` (string `chapter:verse`), for every edition.

- [ ] **Step 1: Unit tests** `PsalmNumberingTest`: `testToHebrewIsIdentityForHebrewEditions` (`toHebrew(23, 4, 'NABRE') === [23, 4]`); data provider `vulgateToHebrew` with VGCL `[9,1]→[9,1]`, `[9,22]→[10,1]`, `[9,39]→[10,18]`, `[22,4]→[23,4]`, `[113,9]→[115,1]`, `[114,9]→[116,9]`, `[115,1]→[116,10]`, `[146,11]→[147,11]`, `[147,1]→[147,12]`, `[150,6]→[150,6]`; DRB `[115,10]→[116,10]`, `[147,12]→[147,12]`; `testToHebrewRoundTripsResolve` (for every Hebrew `chapter:verse` within `hebrewVerseLimit`, `toHebrew(resolve(x, 'VGCL'))` returns x).
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Pipeline/Transform/PsalmNumberingTest.php` → FAIL (method missing).
- [ ] **Step 3: Implement `toHebrew()`**.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: HTTP tests** in `QuoteHandlerHttpTest`: `testEditionNumberingReadsVulgatePsalmsByTheirOwnNumber` (`Ps22&version=VGCL&numbering=edition` → rows with `chapter` 22, first `psalm_hebrew` `"23:1"`; same query without `numbering` → chapter 21); `testEditionNumberingIsTheDefaultForHebrewEditions` (`Ps23&version=NABRE&numbering=edition` returns the same rows as without it); `testVulgatePsalmSpanningTwoHebrewPsalmsReportsBoth` (`Ps9&version=VGCL&numbering=edition` → 39 rows, row 22's `psalm_hebrew` `"10:1"`); `testEditionNumberingBoundsUseTheEditionIndex` (`Ps9,39&version=VGCL&numbering=edition` → 1 row, no error; `Ps9,39&version=VGCL` → the Hebrew out-of-bounds error as today); `testEditionNumberingKeepsTheThirtyVerseCap` (a copyrighted edition's 40+-verse chapter with `numbering=edition` and a `read` key → 30 rows); `testUnknownNumberingFallsBackToHebrew` (`numbering=foo` behaves as default).
- [ ] **Step 6: Run** → FAIL.
- [ ] **Step 7: Implement:** with `edition`, `QueryFormulator` skips `psalmNumbering->resolve()` and `AstValidator` skips both the Hebrew verse-limit check and the resolve before `validateBounds`; `QueryExecutor::prepareResponse()` sets `psalm_hebrew` on book-23 rows from `PsalmNumbering::toHebrew((int) $row['chapter'], (int) $row['verse'], $currentVariant)`. Document `numbering` (enum `hebrew|edition`, default `hebrew`) and the `psalm_hebrew` field in `openapi.json`.
- [ ] **Step 8: Run** `composer test` → all green; `composer analyse`, `composer lint`.
- [ ] **Step 9: Commit** `feat(quote): numbering=edition and psalm_hebrew on psalm rows`.

### Task 4: An unlimited `read` key for the website's visitors

**Files:**
- Modify: `scripts/mint-reader-key.php`, `CLAUDE.md` ("Minting keys")
- Test: `tests/Integration/Scripts/MintScriptsTest.php` (extend)

**Interfaces:** `php scripts/mint-reader-key.php [--scope=reader|read] [key name]` — default scope `reader` (unchanged behaviour); `read` mints a `read`-scope key with `rate_limit_per_hour` NULL on the same system application "BibleGet website"; default name for `read`: `Website visitors`.

- [ ] **Step 1: Tests:** `testDefaultStillMintsAReaderKey`; `testReadScopeMintsAnUnlimitedReadKeyOnTheSystemApplication` (scope `read`, `rate_limit_per_hour` null, same `application_id` as the reader key); `testRerunAddsAnotherKey` (two runs → two active keys); `testUnknownScopeExitsNonZero` (`--scope=admin` → exit ≠ 0, nothing written).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the option parsing before the positional name; reuse the existing upsert of the system application.
- [ ] **Step 4: Run** → PASS; document the two keys in `CLAUDE.md` under "Minting keys".
- [ ] **Step 5: Commit** `feat(scripts): mint the website's unlimited read key`.

### Task 5: Docs and the staging rollout

**Files:**
- Modify: `../endpoint.wiki/Design-Verse-Rendering-Modes.md` (item 4 "[Answered for the reader]": the reader reads through the API with the `reader` key, per this spec; step 5 keeps print mode only), `../endpoint.wiki/Psalms-Numbering.md` (a short "Reading an edition in its own numbering" section: `numbering=edition`, `psalm_hebrew`).

- [ ] **Step 1:** Edit both wiki pages; commit and push the wiki.
- [ ] **Step 2:** Branch, push, open the PR against `development` with the five commits; wait for CI and CodeRabbit; address findings.
- [ ] **Step 3 (after merge, with the user's go-ahead for each live action):** deploy `/dev` (`gh workflow run deploy.yaml --ref development -f target=staging`); turn full text on for the seven editions with `PATCH /admin/versions/{sigla}` (admin token) or, until the site's admin page exists, `UPDATE versions_available SET reader_full_text = true WHERE type = 'BIBLE'` on `bibleget_dev`; mint both keys through the SSH tunnel (`APP_ENV=staging`, never print the keys into logs; they go straight into the site's server environment).

Expected end state: `curl '/dev/quote?query=Ps22&version=VGCL&numbering=edition'` returns VGCL Psalm 22 with `psalm_hebrew` on each row and no `embedding_labse`.
