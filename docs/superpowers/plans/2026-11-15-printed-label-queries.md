# Printed-Label Queries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every endpoint selects and reports a verse by the number the edition prints, including a `chapter:verse` label (`verseequiv`) printed in another chapter; NABRE's index is recomputed so `Wis11:26` and `John7:53` work; fixture and live tests cover it.

**Architecture:** One helper (`src/Util/PrintedLabel.php`) owns the label rule in two halves: SQL expressions (cited chapter/verse) for WHERE clauses, and a PHP function that maps a fetched row to its cited chapter/verse. `SqlCompiler`, `QueryExecutor::prepareResponse`, the three search row builders and `/search/similar`'s lookup use it. Migration `035` recomputes `NABRE_idx`. Storage is unchanged.

**Tech Stack:** PHP 8.4, PostgreSQL 18 (pgvector), PHPUnit 13, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-15-printed-label-queries-design.md`

## Global Constraints

- A row is labelled elsewhere when `verseequiv ~ '^[0-9]+:[0-9]+[a-z]*$'`; cited chapter = digits before `:`, cited verse = leading digits after `:` (`10:1a` → 10, 1). All other rows: cited = stored `chapter`, `verse`.
- Nothing outside `PrintedLabel` parses `verseequiv`.
- Stored rows are not modified. Migration 035 touches only `NABRE_idx`, every book except 19 (Esther).
- No NABRE text in the repo: fixtures use placeholder text; live tests assert structure and `md5` of text only.
- Rows are ordered by `"verseID"` as today; `verseequiv`/`versedescr` are returned as stored.
- PHPStan level 10, PHPCS (PSR-12, complexity ≤ 20, nesting ≤ 5) must stay clean: `composer analyse`, `composer lint`.
- Migrations are applied to staging by hand, before the code that needs them is deployed.

## Review Focus

1. **NULL `verseequiv`.** `NOT (NULL ~ '…')` is NULL, which would drop every ordinary row. The SQL must test `coalesce(verseequiv, '')`. Pinned in Task 1 (SQL string) and Task 2 (existing TEST1/TEST2 quote tests still pass after the column is added with NULLs).
2. **Appended clauses bind to the whole condition.** `preferorigin` (Psalms) is appended after the condition; without outer parentheses it would bind to the second branch only. Pinned in Task 2 (SQL unit test with a preferorigin suffix; Psalm HTTP tests unchanged).
3. **Other `verseequiv` shapes must not match**: lettered `18a`, Psalm cross-numbers `(9,22)`, `17aa`, empty string. Pinned in Task 1.
4. **Non-consecutive segments** (`Wis12,1.3`) compile each segment with the rule. Pinned in Task 2.
5. **`/search/similar` with a reference that matches no row** still answers 404; a reference matching two rows averages them and excludes both. Pinned in Task 3.

---

### Task 1: The label helper

**Files:**
- Create: `src/Util/PrintedLabel.php`
- Test: `tests/Unit/Util/PrintedLabelTest.php`

**Interfaces:**
- Produces:
  - `PrintedLabel::PATTERN = '^[0-9]+:[0-9]+[a-z]*$'` (POSIX regex for SQL; PHP uses the same with delimiters)
  - `PrintedLabel::sqlLabelled(): string` → `(coalesce(verseequiv, '') ~ '^[0-9]+:[0-9]+[a-z]*$')`
  - `PrintedLabel::sqlChapter(): string` → SQL int expression: cited chapter for labelled rows, else `chapter`
  - `PrintedLabel::sqlVerse(): string` → SQL int expression: cited verse for labelled rows, else `verse`
  - `PrintedLabel::cited(array<string, mixed> $row): array{chapter: int, verse: int}` → from `$row['chapter']`, `$row['verse']`, `$row['verseequiv'] ?? null`

- [ ] **Step 1: Write the failing tests** — `PrintedLabelTest` with a data provider for `cited()`:
  - `['chapter' => 11, 'verse' => 26, 'verseequiv' => '12:1']` → `['chapter' => 12, 'verse' => 1]`
  - `['chapter' => 9, 'verse' => 35, 'verseequiv' => '10:1a']` → `[10, 1]`
  - `verseequiv` `null`, `''`, `'18a'`, `'17aa'`, `'(9,22)'`, missing key → stored chapter/verse (ints, also when given as numeric strings)
  - `testSqlLabelledHandlesNull`: `sqlLabelled()` contains `coalesce(verseequiv, '')`.
  - `testSqlExpressionsAgreeWithCited` (Integration-free): `sqlChapter()`/`sqlVerse()` contain `split_part(verseequiv, ':', 1)` and `substring(split_part(verseequiv, ':', 2) from '^[0-9]+')` inside a `CASE WHEN <sqlLabelled> …`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Util/PrintedLabelTest.php` — expected FAIL (class not found).
- [ ] **Step 3: Implement** `final class PrintedLabel` in `src/Util/PrintedLabel.php` with the interface above (static methods, no state).
- [ ] **Step 4: Run** the test file — expected PASS. `composer analyse && composer lint` clean.
- [ ] **Step 5: Commit** `feat: PrintedLabel helper for chapter:verse labels printed in another chapter`

---

### Task 2: Quotes select and report by printed number

**Files:**
- Modify: `src/Pipeline/Compiler/SqlCompiler.php` (`compile`, `compileVerseRef`, `compileVerseRange`, `compileCrossChapterRange`)
- Modify: `src/Pipeline/QueryExecutor.php:376-403` (`prepareResponse`)
- Modify: `tests/fixtures/seed.sql` (add `verseequiv VARCHAR(10) DEFAULT NULL` to `TEST1`, `TEST2`, `VGCL`, `DRB` table definitions; add edition `TEST3`)
- Test: `tests/Unit/Pipeline/Compiler/SqlCompilerTest.php` (create or extend), `tests/Server/PrintedLabelHttpTest.php` (create)

**Interfaces:**
- Consumes: `PrintedLabel::sqlLabelled()`, `sqlChapter()`, `sqlVerse()`, `cited()` (Task 1).
- Produces: fixture edition `TEST3` (used by Tasks 3 and 4), and the SQL shape:
  `… WHERE book = B AND ( ( <cond(chapter, verse)> AND NOT <labelled> ) OR ( <labelled> AND <cond(sqlChapter, sqlVerse)> ) )<preferorigin> ORDER BY "verseID"[ LIMIT 30]`

**Fixture `TEST3`** (`versions_available`: `('TEST3', 'Test Bible Version 3', '2026', 'English', 'Yes', 'CATHOLIC', 'Test Publisher', 'Printed labels', 0, 'BIBLE', 'english')`). Table like `TEST1` plus `verseequiv`. Insert in this order (verseID order = print order), placeholder text naming the verse:

| book | chapter | verse | verseequiv | text |
|---|---|---|---|---|
| 22 | 9 | 34 | | `Job 9:34 placeholder` |
| 22 | 9 | 35 | | `Job 9:35 placeholder` |
| 22 | 9 | 35 | `10:1a` | `Job 10:1 first part labelmark placeholder` |
| 22 | 10 | 1 | | `Job 10:1 rest placeholder` |
| 22 | 10 | 2 | | `Job 10:2 placeholder` |
| 27 | 11 | 25 | | `Wis 11:25 placeholder` |
| 27 | 11 | 26 | | `Wis 11:26 placeholder` |
| 27 | 11 | 26 | `12:1` | `Wis 12:1 labelmark placeholder` |
| 27 | 12 | 2..5 | | `Wis 12:N placeholder` |

`TEST3_idx` (same columns as `TEST1_idx`): Job (22) 10 chapters, `verses_last` `'1,1,1,1,1,1,1,1,35,2'`; Wis (27) 12 chapters, `'1,1,1,1,1,1,1,1,1,1,26,5'`.

- [ ] **Step 1: Write the failing SQL unit tests** (`SqlCompilerTest`):
  - `testSingleVerseMatchesPrintedLabel`: compiling `Wis 12:1` for `TEST3` yields SQL containing `NOT ` + `PrintedLabel::sqlLabelled()` and `PrintedLabel::sqlChapter() . ' = 12'`.
  - `testPreferOriginBindsToWholeCondition`: with `$preferOrigin = " AND verseorigin = 'HEBREW'"`, the SQL matches `/book = 23 AND \(.*\) AND verseorigin = 'HEBREW' ORDER BY "verseID"$/s`.
  - `testCrossChapterRangeUsesBothBranches`, `testWholeChapterUsesBothBranches`: both branches present.
- [ ] **Step 2: Write the failing HTTP tests** (`PrintedLabelHttpTest extends ServerTestCase`), each asserting status 200, empty `errors`, and the list of `[chapter, verse, verseequiv]` of `results` in order:
  - `Wis12,1` → `[[12,1,'12:1']]`
  - `Wis12,1-5` → `[[12,1,'12:1'],[12,2,null],[12,3,null],[12,4,null],[12,5,null]]`
  - `Wis12` → same as above
  - `Wis11` → `[[11,25,null],[11,26,null]]`
  - `Wis11,26` → `[[11,26,null]]`
  - `Wis11,25-12,2` → `[[11,25,null],[11,26,null],[12,1,'12:1'],[12,2,null]]`
  - `Wis12,1.3` → `[[12,1,'12:1'],[12,3,null]]`
  - `Job9,35` → `[[9,35,null]]`
  - `Job10,1` → `[[10,1,'10:1a'],[10,1,null]]`
  - `Job9,35-10,2` → `[[9,35,null],[10,1,'10:1a'],[10,1,null],[10,2,null]]`
  - `testHtmlStartsChapterOnce`: `Job9,35-10,2&return=html` body contains exactly 2 `class="book bookChapter"`.
- [ ] **Step 3: Run** `vendor/bin/phpunit tests/Unit/Pipeline/Compiler/SqlCompilerTest.php` and `vendor/bin/phpunit --testsuite Server --filter PrintedLabel` (reload fixtures in the `bg-phpunit` container as `tests/Integration/DatabaseTestCase.php` / the Server bootstrap does) — expected FAIL.
- [ ] **Step 4: Implement.** Change the `compile*` helpers to take the chapter and verse column expressions as parameters (`string $chapterCol, string $verseCol`), and in `compile()` wrap each segment as in the SQL shape above. In `prepareResponse`, replace `chapter`/`verse` with `PrintedLabel::cited($row)`.
- [ ] **Step 5: Run** the two test files, then the full `Unit`, `Integration` and `Server` suites (Review Focus 1 and 2: existing TEST1/TEST2/VGCL/DRB/Psalm tests must still pass) — expected PASS. `composer analyse && composer lint` clean.
- [ ] **Step 6: Commit** `feat: quotes select and report verses by their printed chapter:verse label`

---

### Task 3: Search endpoints use the same rule

**Files:**
- Modify: `src/Services/KeywordSearchService.php` (row builder near line 270), `src/Services/SemanticSearchService.php` (near line 105), `src/Handlers/SimilarSearchHandler.php` (`mapResults`, `getVerseEmbedding`, `searchSimilar`)
- Modify: `openapi.json` (search result schema: add `verseequiv` string|null; `verseequiv` description: may hold a `chapter:verse` label printed in another chapter)
- Test: `tests/Integration/Handlers/SimilarSearchHandlerTest.php`, `tests/Server/KeywordSearchHttpTest.php`

**Interfaces:**
- Consumes: `PrintedLabel::cited()`, `sqlLabelled()`, `sqlChapter()`, `sqlVerse()` (Task 1); fixture `TEST3` (Task 2).
- Produces: every search result row has `chapter`/`verse` = cited numbers and a `verseequiv` key (string|null).

- [ ] **Step 1: Write the failing tests:**
  - `KeywordSearchHttpTest::testPrintedLabelRowReportsCitedNumbers`: keyword `labelmark` in `TEST3` → exactly 2 results; the Wis 12:1 row has `chapter` 12, `verse` 1, `verseequiv` `'12:1'`; the Job first-part row has 10, 1, `'10:1a'`.
  - `SimilarSearchHandlerTest` (set embeddings on TEST3 rows in `setUp`, as the existing test does for TEST1, using distinct vectors for the two Job 10:1 rows):
    - `testSimilarFromPrintedLabel`: `reference=Wis12:1, version=TEST3` → 200; no result is the `12:1` row.
    - `testSimilarFromSplitVerseAveragesAndExcludesBoth`: `reference=Job10:1` → no result has `chapter` 10 and `verse` 1; the top result is the row whose embedding is closest to the average of the two (set a third row's vector to exactly that average).
    - `testSimilarUnknownVerseIs404`: `reference=Wis12:9` → `NotFoundException`.
- [ ] **Step 2: Run** them — expected FAIL.
- [ ] **Step 3: Implement.**
  - The three row builders: `chapter`/`verse` from `PrintedLabel::cited($row)`; add `'verseequiv' => $row['verseequiv'] ?? null` (string or null).
  - `getVerseEmbedding`: `SELECT avg(<column>)::text AS embedding FROM … WHERE book = ? AND <sqlChapter> = ? AND <sqlVerse> = ? AND <column> IS NOT NULL`; a NULL result → `NotFoundException` (message unchanged).
  - `searchSimilar`: exclusion becomes `AND NOT (book = ? AND <sqlChapter> = ? AND <sqlVerse> = ?)`.
- [ ] **Step 4: Run** the new tests plus the full `Integration` and `Server` suites — expected PASS. `composer analyse && composer lint` clean; `npx @redocly/cli lint openapi.json` (or `composer lint:openapi`) clean.
- [ ] **Step 5: Commit** `feat: search endpoints report and look up verses by printed label`

---

### Task 4: NABRE index by verse number (migration 035)

**Files:**
- Create: `migrations/035-nabre-idx-by-verse-number.sql`
- Create: `src/Util/IndexList.php`
- Modify: `src/Pipeline/QuoteContext.php:365`, `src/Handlers/MetadataHandler.php:226` (use `IndexList::parse`)
- Test: `tests/Unit/Util/IndexListTest.php`, `tests/Server/PrintedLabelHttpTest.php` (add a metadata test)

**Interfaces:**
- Consumes: `PrintedLabel::sqlChapter()`/`sqlVerse()` expressions (copy them into the SQL file; a migration can't call PHP).
- Produces: `IndexList::parse(string $csv, LoggerInterface $logger, string $context): list<int>` — `intval` per token, and `$logger->error(...)` (with `$context`, e.g. `NABRE_idx book 27`) for any token not matching `/^\d+$/`.

- [ ] **Step 1: Write the failing tests:**
  - `IndexListTest`: `'16,24,19'` → `[16,24,19]`, no log; `'26,12:1'` → `[26,12]` and the logger (a PSR-3 test double) received one `error` containing `12:1` and the context string.
  - `PrintedLabelHttpTest::testVersionIndexReportsNumberedBounds`: `/v3/metadata/versionindex?versions=TEST3` → Wis (27) chapter 11 last verse 26, chapter 12 last verse 5.
- [ ] **Step 2: Run** — expected FAIL (class missing).
- [ ] **Step 3: Implement** `IndexList::parse` and use it at both call sites.
- [ ] **Step 4: Write `035`.** Header comment: purpose, the expected-changes table from the spec (Tobit 6, Job 9-10, Wis 11-12, Sir 33/41/47, John 7-8), and "Esther (19) excluded". Body, in one transaction:
  - A CTE over `"NABRE"` with `WHERE book <> 19` computing per book and cited chapter `count(DISTINCT cited_verse)` and `max(cited_verse)`, aggregated with `string_agg(... ORDER BY cited_chapter)` per book (assumes chapters 1..N all present; assert `count(*) = max(cited_chapter)` per book, else RAISE EXCEPTION).
  - `UPDATE "NABRE_idx"` for rows whose `verses_count` or `verses_last` differ; `RAISE NOTICE` each changed book (old → new).
  - A `DO` block looping over `versions_available.sigla` that raises if any token of `verses_last` or `verses_count` in `"<sigla>_idx"` is not `^[0-9]+$`.
- [ ] **Step 5: Apply to staging** (credentials from `.env.staging`, never printed) and check: the NOTICEs list exactly books 17, 22, 27, 28, 50; `https://query.bibleget.io/dev/quote?query=Wis11:26&version=NABRE` and `John7:53` return one row with no error; `python3 scripts/versification_snapshot.py --check` (run on the VPS as in earlier sessions) prints `matches` for all eight editions.
- [ ] **Step 6: Run** `Unit` and `Server` suites — PASS; analyse/lint clean.
- [ ] **Step 7: Commit** `fix: NABRE index counts and bounds by verse number (035)`

---

### Task 5: Live suite against staging

**Files:**
- Create: `tests/Live/LiveTestCase.php`, `tests/Live/NabrePrintedLabelLiveTest.php`
- Modify: `phpunit.xml.dist` (testsuite `Live` → `tests/Live`), `composer.json` (`test` and `test:quick` add `--exclude-testsuite Live`; new `test:live`: `phpunit --testsuite Live --testdox`), `.github/workflows/deploy.yaml` (new job)

**Interfaces:**
- Produces: `LiveTestCase::liveGet(string $path): array{status: int, body: array<string, mixed>}` — base URL from env `BIBLEGET_LIVE_URL` (e.g. `https://query.bibleget.io/dev`, paths like `/quote?...`, `/search/similar?...`, `/metadata/versionindex?...`), User-Agent `BibleGet-LiveTests/1.0`; `setUp` marks the test skipped when the env var is unset; a response whose `errors` contain `errNum` 10 (rate limit) marks the test skipped with that message.

- [ ] **Step 1: Write the tests** (`NabrePrintedLabelLiveTest`, `version=NABRE`), asserting `[chapter, verse, verseequiv]` lists like Task 2:
  - `Wis12:1` → `[[12,1,'12:1']]`; `Wis12:1-5` → 12:1 then 2-5; `Wis12` → 27 rows, first `[12,1,'12:1']`; `Wis11` → 26 rows, last `[11,26,null]`; `Wis11:26` → 1 row, no errors.
  - `Job9:35` → `[[9,35,null]]`; `Job10:1` → `[[10,1,'10:1a'],[10,1,null]]`; `Job9:35-10:2` → 4 rows.
  - `John7:53` → 1 row `[7,53,…]`, no errors.
  - `md5(text)` of the Wis 12:1 row and of the Job `10:1a` row equal constants taken from staging (compute once with the query and paste the hashes; never the text).
  - `/search/similar?reference=Wis12:1&version=NABRE&model=labse` → 200, no result `[12,1]`; `reference=Job10:1` → 200, no result `[10,1]`.
  - `/search/keyword?keyword=loathe&version=NABRE` → a result `[10,1,'10:1a']`.
  - `/metadata/versionindex?versions=NABRE` → Wis chapter 11 last 26, chapter 12 last 27; John chapter 7 last 53.
- [ ] **Step 2: Run** `composer test:live` without the env var → all skipped; with `BIBLEGET_LIVE_URL=https://query.bibleget.io/dev` → PASS only after Tasks 2-4 are deployed to staging (before that, expect the label tests to fail; that confirms they test the deployed code).
- [ ] **Step 3: Deploy job.** In `deploy.yaml` add job `live-tests`: `needs: deploy`, `if: github.event_name == 'workflow_dispatch' && inputs.target == 'staging'`, steps: checkout the same ref, `shivammathur/setup-php` 8.4, `composer install --no-interaction --prefer-dist` (dev deps), `composer test:live` with `BIBLEGET_LIVE_URL: ${{ vars.STAGING_BASE_URL || 'https://query.bibleget.io/dev' }}`.
- [ ] **Step 4: Run** `composer test` → the Live suite is not run; `composer lint`/`analyse` clean (include `tests/Live` in PHPStan paths if `tests/` is analysed).
- [ ] **Step 5: Commit** `test: live suite for printed-label queries against staging`

---

### Task 6: Docs and PR

**Files:**
- Modify: `docs/CHANGELOG.md` (Unreleased: Fixed — `Wis11:26`, `John7:53`, references to verses printed in another chapter, on every endpoint; Added — `verseequiv` in search results, live test suite, migration 035)
- Wiki (`../endpoint.wiki`, commit locally, push only when asked): `Versification-Differences.md` (the two cases: which rows each query returns), `Design-Verse-Rendering-Modes.md` (queries now select by printed number; backward-bridge rows report their cited chapter/verse in the data view), `Known-Data-Issues.md` (`Wis11:26`, `John7:53` fixed; #187 for other editions' counts), `Migrations` page (035)

- [ ] **Step 1:** Write the CHANGELOG entry and wiki updates.
- [ ] **Step 2:** Full local run: `composer test`, `composer analyse`, `composer lint`, `composer lint:python`.
- [ ] **Step 3:** Commit `docs: printed-label queries`, push the branch, open the PR against `development` (body: summary, the query table, migration 035 applied to staging, live suite runs after the staging deploy, test plan checklist).
