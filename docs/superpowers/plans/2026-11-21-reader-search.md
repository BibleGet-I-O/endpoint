# Reader Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A search lens in the website reader (keyword with autocomplete, semantic, hybrid) over the edition shown, with
a results page, and the endpoint changes it needs: the 30-verse copyright cap on every search route, keyword paging,
and a word list behind `/search/suggest`.

**Architecture:** Endpoint first (Tasks 1–5, branch `feat/reader-search` in the endpoint worktree
`.worktrees/reader-search`), then the website (Tasks 6–11, branch `feat/reader-search` in a website worktree
`../website/.worktrees/reader-search` cut from `origin/main`). The website consumes the endpoint's `info.CAPPED`,
`info.TOTAL`, `limit`/`offset` and `/search/suggest`; until the endpoint is deployed, the website's tests mock them.

**Tech Stack:** PHP 8.4, PostgreSQL 18, PHPUnit 13, Doctrine Migrations; Next.js 16, React 19, next-intl 4, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-21-reader-search-design.md`

## Global Constraints

- Cap: an edition with `versions_available.copyright = 1` is capped unless the credential's scope is `reader` **and**
  the edition is `reader_full_text`; a capped edition yields at most **30** results per request. No credential → capped.
- `info.CAPPED`: object sigla → matches before the cap, only for editions actually capped (more than 30 matches);
  absent otherwise. `info.TOTAL`: matches this caller may see; only on `/search/keyword` with `limit`.
- Search parameter errors answer 422 (`ValidationException`), as today.
- `/search/suggest`: `prefix` ≥ 2 letters (max 40), `limit` default 8, max 20, one `version`; ordered by `freq` desc then
  word; unknown version → 422; needs a credential like the other search routes.
- Endpoint: PHPStan level 10, PHPCS (complexity ≤ 20, nesting ≤ 5), Doctrine migration with no BEGIN/COMMIT,
  idempotent; `openapi.json` updated for every contract change.
- Website: keys server-only (`import "server-only"`); new English strings in `messages/en.json`, then
  `npm run sync-messages`, then translated by hand in it/fr/de/es/pt.
- Website limits per client IP: suggestions 60/min, semantic+hybrid searches 10/min; keyword unlimited.
- Keyword results page size 50; semantic and hybrid `limit` 30; suggestions after 200 ms idle, ≥ 2 letters, max 8.
- Semantic/hybrid text area max 500 characters; Enter submits, Shift+Enter adds a line.
- Work only in the worktrees; stage files by name; never touch the main checkouts.

## Review Focus

1. A capped edition paged past verse 30 (`offset=30`) must return no rows, not rows 31–80 (Task 3
   `testCappedEditionWindowStopsAtThirty`).
2. A search listing several editions (`version=TEST1,TEST2`) caps only the copyrighted one (Task 2
   `testOnlyTheCopyrightedEditionIsCapped`).
3. A suggestion prefix with accents or uppercase ("Isr", "isr", "Ísr") matches the same words (Task 5
   `testPrefixIsFoldedAndCaseInsensitive`).
4. A results link for a verse printed in another chapter (placement) must land on the chapter the reader prints it in
   (Task 10 `linksAVersePrintedElsewhereToItsPrintedChapter`).
5. A visitor whose capped search is followed by signing in lands back on the same search (Task 10
   `cappedNoticeComesBackToThisSearch`).

---

## Endpoint (worktree `.worktrees/reader-search`)

### Task 1: One copyright rule for quotes and search

**Files:**
- Create: `src/Services/CopyrightPolicy.php`
- Modify: `src/Pipeline/QuoteContext.php` (`fullTextAllowed`, `copyrightLimitedVersions` delegate to the policy)
- Test: `tests/Unit/Services/CopyrightPolicyTest.php`

**Interfaces:**
- Produces: `final class CopyrightPolicy` with `public const VERSE_CAP = 30;`,
  `__construct(array $copyrighted, array $readerFullText)` (lists of sigla),
  `public static function fromDatabase(\PDO $pdo): self` (reads `versions_available` `sigla, copyright,
  reader_full_text`), `public function isCapped(string $sigla, ?Credential $credential): bool`.

- [ ] **Step 1: Write the failing tests** — `testPublicDomainIsNeverCapped` (TEST1-like sigla not in copyrighted →
  false for null, read, reader); `testCopyrightedIsCappedWithoutACredential`; `testReadScopeIsCapped`;
  `testReaderScopeReadsAFullTextEditionWhole` (copyrighted + readerFullText + scope `reader` → false);
  `testReaderScopeIsCappedWhereFullTextIsOff`. Credentials built as in `tests/Unit/Handlers/AbstractHandlerTest.php:317`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Services/CopyrightPolicyTest.php` — Expected: FAIL (class missing).
- [ ] **Step 3: Implement** the class; make `QuoteContext::fullTextAllowed($sigla)` return
  `!$policy->isCapped(...)` for copyrighted editions (keep its current meaning: true only for reader + full text) and
  build the policy from the lists `populateVersionsInfo` already fills.
- [ ] **Step 4: Run** the new test and `vendor/bin/phpunit tests/Integration/Handlers/QuoteHandlerTest.php
  tests/Server/QuoteHandlerHttpTest.php` — Expected: PASS (quotes unchanged).
- [ ] **Step 5: Commit** `refactor(copyright): one policy for the 30-verse cap (quotes now, search next)`.

### Task 2: The cap on every search route

**Files:**
- Modify: `src/Util/SearchUtils.php` (new `applyCopyrightCap`), `src/Handlers/KeywordSearchHandler.php`,
  `src/Handlers/SemanticSearchHandler.php`, `src/Handlers/HybridSearchHandler.php`,
  `src/Handlers/SimilarSearchHandler.php` (legacy `SearchHandler` delegates to keyword), `openapi.json`
- Test: `tests/Unit/Util/SearchUtilsCapTest.php`, `tests/Integration/Handlers/KeywordSearchHandlerTest.php`,
  `tests/Integration/Handlers/SemanticSearchHandlerTest.php`, `tests/Integration/Handlers/HybridSearchHandlerTest.php`,
  `tests/Integration/Handlers/SimilarSearchHandlerTest.php`

**Interfaces:**
- Consumes: `CopyrightPolicy` (Task 1); the credential is `$request->getAttribute('credential')` (a `Credential` or null).
- Produces: `SearchUtils::applyCopyrightCap(array $rows, CopyrightPolicy $policy, ?Credential $credential): array{rows: list<array<string,mixed>>, capped: array<string,int>}`
  — keeps, per `version`, the first `CopyrightPolicy::VERSE_CAP` rows in the given order for capped editions; `capped`
  maps each edition that lost rows to its row count before capping. Handlers put `capped` into `info.CAPPED` when
  non-empty; XML adds `<capped version="…" total="…"/>` children to `<info>`.

- [ ] **Step 1: Write the failing tests**
  - Unit: `testKeepsTheFirstThirtyOfACappedEdition` (35 rows TEST2 → 30 rows, `capped == ['TEST2' => 35]`, order kept);
    `testThirtyExactlyIsNotReportedAsCapped`; `testReaderFullTextIsNotCapped`.
  - Keyword integration: `testVisitorGetsThirtyOfACopyrightedEdition` (`keyword=test2`, `version=TEST2`, no credential
    → 30 results, `info.CAPPED.TEST2 > 30`); `testReaderCredentialOnAFullTextEditionGetsEverything` (set
    `reader_full_text = true` on TEST2 in the test, reset in tearDown; reader credential → all, no `CAPPED`);
    `testOnlyTheCopyrightedEditionIsCapped` (`version=TEST1,TEST2` → TEST1 rows all present); `testPublicDomainIsNeverCapped`.
  - Semantic, hybrid, similar: one test each that a capped edition returns ≤ 30 rows and `info.CAPPED` when the
    uncapped result would exceed 30 (use `limit=40` with the existing stub embedding client).
- [ ] **Step 2: Run** those test files — Expected: the new tests FAIL.
- [ ] **Step 3: Implement** `applyCopyrightCap` and call it in each handler after the rows are in their final order
  (keyword: after `assignCanonicalOrder`; semantic/hybrid/similar: after ranking), before the response is built. Build
  the policy once per request with `CopyrightPolicy::fromDatabase($pdo)`.
- [ ] **Step 4: Document** in `openapi.json`, on each search route: the cap (one sentence) and `info.CAPPED`
  (object, additionalProperties integer). Run `composer lint:openapi`.
- [ ] **Step 5: Run** `composer test:quick` — Expected: PASS.
- [ ] **Step 6: Commit** `feat(search): the 30-verse copyright cap on every search route, reported in info.CAPPED`.

### Task 3: Keyword paging

**Files:**
- Modify: `src/Services/KeywordSearchService.php`, `src/Handlers/KeywordSearchHandler.php`, `openapi.json`
- Test: `tests/Integration/Handlers/KeywordSearchHandlerTest.php`

**Interfaces:**
- Produces: `KeywordSearchService::countMatches(string $version, string $keyword, string $matchMode, string $tsLanguage): int`
  and `KeywordSearchService::executeWindow(string $version, string $keyword, string $matchMode, string $tsLanguage, int $limit, int $offset): \PDOStatement`
  (canonical `sort_order`, all three modes). The three modes' WHERE clause and bindings move into one private method
  both use, and `executeSearch` too. `/search/keyword?limit=&offset=` → `info.TOTAL`.

- [ ] **Step 1: Write the failing tests** — `testLimitAndOffsetReturnACanonicalWindow` (TEST1 `light` with `limit=1`,
  `offset=1` → the second row of the unpaged result; `info.TOTAL` = unpaged count); `testCappedEditionWindowStopsAtThirty`
  (TEST2 `test2`, `limit=50`, `offset=0` → 30 rows, `TOTAL` 30, `CAPPED.TEST2` > 30; `offset=30` → 0 rows, same
  `TOTAL` and `CAPPED`); `testLimitWithSeveralVersionsIsRefused` (422); `testNegativeOffsetIsRefused` (422);
  `testWithoutLimitNothingChanges` (no `TOTAL`).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement**: `limit` parsed with `SearchUtils::parseLimitParam($raw, 50, 100)` only when present;
  `offset` a non-negative integer (default 0). For a capped edition, visible = min(count, 30), window
  `[offset, min(offset+limit, visible))`; skip the window query when empty. `info.TOTAL` = visible count.
- [ ] **Step 4: Document** `limit`, `offset`, `info.TOTAL` in `openapi.json`; `composer lint:openapi`.
- [ ] **Step 5: Run** `composer test:quick` — Expected: PASS.
- [ ] **Step 6: Commit** `feat(search): page keyword results with limit and offset; info.TOTAL`.

### Task 4: The word list (migration 058)

**Files:**
- Create: `migrations/058-search-vocabulary.sql`, `src/Migrations/Version058SearchVocabulary.php`
- Modify: `tests/Integration/Handlers/Ops/MigrateHandlerTest.php` (expects 058; count 7)
- Test: `tests/Integration/Database/SearchVocabularyTest.php`

**Interfaces:**
- Produces: table `search_vocabulary(sigla text, word text, folded text, freq integer, PRIMARY KEY (sigla, word))`,
  index `search_vocabulary_prefix_idx ON (sigla, folded text_pattern_ops)`, and function
  `refresh_search_vocabulary(p_sigla text) RETURNS integer` (deletes the edition's rows, refills them, returns the
  count). Words: `regexp_split_to_table(lower(text_plain), '[^[:alpha:]]+')`, length ≥ 4
  (`KeywordSearchService::MIN_KEYWORD_LENGTH`), not a stop word
  (`to_tsvector(('bibleget_' || ts_language)::regconfig, word) <> ''::tsvector`), `folded = unaccent(word)`,
  `freq` = distinct verses containing it.

- [ ] **Step 1: Write the failing tests** — `testEveryEditionHasItsWords` (after migrating, TEST1 has `light` with
  freq = the number of TEST1 verses containing it); `testStopWordsAndShortWordsAreLeftOut` (`the`, `and` absent for an
  english edition); `testFoldedFormDropsAccents` (VGCL `israël` → folded `israel` if present in the fixture, else a
  word the fixture has with an accent); `testRefreshIsIdempotent` (two calls, same count).
- [ ] **Step 2: Run** — Expected: FAIL (no table).
- [ ] **Step 3: Implement** the migration and class (as `Version057MoreLocalizedBookAbbreviations`). The migration
  creates the table, index and function, then fills every edition in `versions_available` (`type = 'BIBLE'`).
- [ ] **Step 4: Time it** against a database the size of staging's: run `SELECT refresh_search_vocabulary(sigla)` for
  every edition on the local dev database (or a staging copy) with `\timing`. If the total is over 60 s, move the fill
  out of the migration into `scripts/fill_search_vocabulary.php` (run once after deploy, documented in the migration
  header) and ledger the ruling.
- [ ] **Step 5: Run** the test and `MigrateHandlerTest` — Expected: PASS.
- [ ] **Step 6: Commit** `feat(search): per-edition word list for suggestions (058)`.

### Task 5: `/search/suggest`

**Files:**
- Create: `src/Handlers/SuggestSearchHandler.php`
- Modify: `src/Router.php` (`createSearchHandler` and `searchRouteName` gain `suggest`), `openapi.json`
- Test: `tests/Integration/Handlers/SuggestSearchHandlerTest.php`, `tests/Server/SuggestSearchHttpTest.php`

**Interfaces:**
- Consumes: `search_vocabulary` (Task 4).
- Produces: `GET /search/suggest?prefix=&version=&limit=` → `{"suggestions":[{"word":string,"count":int}],"info":{"ENDPOINT_VERSION":…}}`.

- [ ] **Step 1: Write the failing tests** — `testSuggestsWordsStartingWithThePrefix` (TEST1 `lig` → includes `light`,
  ordered by count desc); `testPrefixIsFoldedAndCaseInsensitive` (`LIG`, and an accented prefix, give the same list);
  `testLimitDefaultsToEightAndCapsAtTwenty`; `testShortPrefixIsRefused` (`l` → 422); `testUnknownVersionIsRefused`
  (422); HTTP test `testSuggestNeedsACredentialWhenRequired` (as the keyword HTTP test does for its route).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement**: version validated with `KeywordSearchService::validateVersions([$v], '/search/suggest')`;
  prefix trimmed, 2–40 letters only (so no LIKE wildcard to escape); the prefix is folded first
  (`SELECT unaccent(lower(?))`) and bound as a finished pattern, `WHERE sigla = ? AND folded LIKE ? ORDER BY freq DESC,
  word LIMIT ?`, so the planner can search the (sigla, folded) index by range (unaccent() is only STABLE); cache
  headers via `withCacheHeaders`.
- [ ] **Step 4: Document** the route in `openapi.json` (tag SearchAPI, `ApiKey` security); `composer lint:openapi`.
- [ ] **Step 5: Run** `composer test:quick` and `composer analyse` and `composer lint` — Expected: all clean.
- [ ] **Step 6: Commit** `feat(search): /search/suggest, words of an edition that start with a prefix`.

---

## Website (worktree `../website/.worktrees/reader-search`, from `origin/main`)

### Task 6: Searching from the server

**Files:**
- Create: `lib/search.ts`
- Modify: `lib/api.ts` (export a status-returning request for search), `lib/types.ts` (search types)
- Test: `lib/__tests__/search.test.ts`

**Interfaces:**
- Produces:
  - `type SearchMode = "keyword" | "semantic" | "hybrid"`;
  - `type SearchHit = { bookNum: number; book: string; chapter: number; verse: number; segment: string | null; printedChapter: number | null; label: string | null; text: string }`;
  - `type SearchOutcome = { kind: "ok"; hits: SearchHit[]; total: number; capped: number | null } | { kind: "refused" } | { kind: "semanticUnavailable" }`;
  - `searchEdition(p: { sigla: string; mode: SearchMode; q: string; exact: boolean; page: number; access: Access }): Promise<SearchOutcome>`
    — keyword: `/search/keyword?keyword=&version=&match=fulltext|exact&limit=50&offset=(page-1)*50`, `total` from
    `info.TOTAL`; semantic/hybrid: `limit=30`, `total` = hits length; `capped` = `info.CAPPED[sigla] ?? null`;
    422 → refused; 503 → semanticUnavailable; other 5xx and 401/403/429 → `ApiUnavailable` as now.
  - `suggestWords(sigla: string, prefix: string): Promise<string[]>` (read key, `limit=8`).
- [ ] **Step 1: Write the failing tests** with `fetch` stubbed (as `lib/__tests__/api.test.ts`): the URLs for each
  mode and page; the reader key for `access: "reader"`; refused on 422; semanticUnavailable on 503; `capped` read from
  `info.CAPPED`; `suggestWords` returns the words only.
- [ ] **Step 2: Run** `npx vitest run lib/__tests__/search.test.ts` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(search): search an edition from the server`.

### Task 7: Per-visitor limits

**Files:**
- Create: `lib/rate-limit.ts`
- Test: `lib/__tests__/rate-limit.test.ts`

**Interfaces:**
- Produces: `createLimiter(o: { limit: number; windowMs: number }): { hit(key: string, now?: number): { ok: boolean; retryAfter: number } }`
  (fixed window per key, entries pruned when their window has passed); `clientIp(h: Headers): string` (first
  `x-forwarded-for` address, else `x-real-ip`, else `"unknown"`); `suggestLimiter` (60/min) and `semanticLimiter`
  (10/min) singletons.
- [ ] **Step 1: Write the failing tests** — `allowsUpToTheLimitInAWindow`; `refusesBeyondItWithRetryAfter`;
  `startsAgainInTheNextWindow`; `keysAreCountedSeparately`; `clientIpTakesTheFirstForwardedAddress`.
- [ ] **Step 2–4:** run (FAIL), implement, run (PASS).
- [ ] **Step 5: Commit** `feat(search): per-visitor limits for suggestions and semantic search`.

### Task 8: `/api/search/suggest`

**Files:**
- Create: `app/api/search/suggest/route.ts`
- Test: `app/__tests__/suggest-route.test.ts`

**Interfaces:**
- Consumes: `suggestWords` (Task 6), `suggestLimiter`, `clientIp` (Task 7), `getEditions` (`lib/api.ts`).
- Produces: `GET /api/search/suggest?edition=&prefix=` → `{"suggestions": string[]}`, `cache-control: public,
  max-age=3600`; 400 for an unknown edition or a prefix outside 2–40 characters; 429 with `Retry-After` past the limit;
  `{"suggestions": []}` with 200 if the endpoint is unavailable.
- [ ] **Step 1–5:** tests for each of those answers (endpoint and limiter mocked), FAIL, implement, PASS, commit
  `feat(search): the site's suggestion route`.

### Task 9: Verses as link targets

**Files:**
- Modify: `lib/placement.ts` (add `verseAnchor`), `components/BookPage.tsx`, `components/VerseLines.tsx`,
  `app/globals.css` (`:target` highlight, `scroll-margin-top`)
- Test: `lib/__tests__/placement.test.ts`, `components/__tests__/BookPage.test.tsx`

**Interfaces:**
- Produces: `verseAnchor(r: { chapter: number; verse: number; segment?: string | null }): string` →
  `` `v${r.chapter}.${r.verse}${r.segment ?? ""}` `` (chapter included: a page can show neighbour chapters' verses).
- [ ] **Step 1: Write the failing tests** — `verseAnchor({chapter: 3, verse: 16})` is `v3.16`, with segment `a` is
  `v1.1a`; BookPage renders an element with `id="v3.16"` around that verse; VerseLine's line has the id.
- [ ] **Step 2–4:** FAIL, implement (the id on the verse's `<span>` in BookPage and the `<p>` in VerseLine; the
  highlight: a short background fade on `:target`, none under `prefers-reduced-motion`), PASS.
- [ ] **Step 5: Commit** `feat(reader): each verse can be linked to (#v3.16)`.

### Task 10: The results page

**Files:**
- Create: `app/[locale]/read/[edition]/search/page.tsx`, `components/SearchResults.tsx`
- Modify: `messages/*.json`
- Test: `app/__tests__/search-page.test.tsx`

**Interfaces:**
- Consumes: `searchEdition` (Task 6), `semanticLimiter`, `clientIp` (Task 7), `verseAnchor` (Task 9), `getAccess`,
  `getIndex`, `slugFor`, `CappedNotice`/`noticeFor`.
- Produces: the page at `/{locale}/read/{edition}/search?mode=&q=&match=exact&page=&from=`.
- [ ] **Step 1: Write the failing tests** (as `app/__tests__/pages.test.tsx` does for the chapter page):
  `listsKeywordHitsWithLinksToTheirVerses` (link `/en/read/nabre/john/3#v3.16`); `marksTheMatchedWords`
  (`<mark>light</mark>`, accent- and case-insensitive whole words; none for semantic);
  `linksAVersePrintedElsewhereToItsPrintedChapter` (hit chapter 12, printedChapter 11 → `/…/11#v12.1`);
  `pagesKeywordResultsByFifty` (page 2 asks `offset=50`; "51–100 of 1,234"; Previous/Next links keep q, mode, match);
  `cappedNoticeComesBackToThisSearch` (`capped: 252` → "30 of 252" and `CappedNotice` with `redirectTo` this page's
  path and query); `refusedKeywordSaysTryAnotherWord`; `semanticUnavailableOffersKeyword`;
  `tooManySemanticSearchesSaysWait` (limiter refuses); `noResultsSaysSo`; `isNotIndexed` (metadata robots noindex);
  `unknownModeFallsBackToKeyword`; `emptyQueryShowsTheFormOnly`.
- [ ] **Step 2–4:** FAIL, implement, PASS. Strings in `messages/en.json` under a new `Search` namespace, then
  `npm run sync-messages`, then translate it/fr/de/es/pt by hand.
- [ ] **Step 5: Commit** `feat(search): the results page`.

### Task 11: The lens and the search form

**Files:**
- Create: `components/SearchForm.tsx`, `components/__tests__/SearchForm.test.tsx`
- Modify: `components/ReaderBar.tsx` (the lens button before the ⚙ menu), `messages/*.json`

**Interfaces:**
- Consumes: `/api/search/suggest` (Task 8); the results page URL (Task 10).
- Produces: `SearchForm({ locale, edition, editionName, from, initial? }: { locale: string; edition: string; editionName: string; from: string; initial?: { mode: SearchMode; q: string; exact: boolean } })`
  — a GET form to `/{locale}/read/{editionSlug(edition)}/search`; also used on the results page above the results.
- [ ] **Step 1: Write the failing tests** — `theLensOpensTheFormAndEscapeCloses` (focus back on the button);
  `keywordModeOffersSuggestionsForTheLastWord` (fetch stubbed; typing `the lig`, after 200 ms, asks `prefix=lig`;
  arrow down + Enter makes the input `the light`); `noSuggestionsBelowTwoLetters`; `exactWordsSetsMatchExact`;
  `semanticModeIsATextAreaEnterSubmitsShiftEnterNewLine`; `theFormNamesTheEditionSearched`;
  `comparingSearchesTheFirstEdition` (ReaderBar with `withSiglas` passes its own `edition`);
  `submitsAPlainGetWithoutJavaScript` (the form has `action` and `method="get"`, inputs named `mode`, `q`, `match`,
  `from`).
- [ ] **Step 2–4:** FAIL, implement (combobox per ARIA 1.2: `role="combobox"`, `aria-expanded`, `aria-controls`,
  `aria-activedescendant`; listbox options), PASS. Strings under `Search`, synced and translated.
- [ ] **Step 5: Run** `npm test && npm run typecheck && npm run lint && npm run build` — Expected: all pass.
- [ ] **Step 6: Commit** `feat(search): the lens and the search form in the reader bar`.
- [ ] **Step 7: Check in a browser** (built site on a free port with `BIBLEGET_API_URL` set to the local or staging
  API): the form, suggestions, a results page in light and dark at 390 px and 1280 px; close the browser after.
