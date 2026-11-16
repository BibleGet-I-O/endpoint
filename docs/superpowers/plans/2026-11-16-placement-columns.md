# Placement Columns and Omitted Verses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store every verse row at its identity, record how the edition prints it in `printed_chapter` / `verse_label` / `omitted`, retire `PrintedLabel`'s parsing of `verseequiv`, and give omitted verses one representation with a `VERSE_OMITTED` notice.

**Architecture:** A schema migration (`044`) adds three nullable/defaulted columns to every edition table; a data migration (`045`, plus a private BLPD half) moves NABRE's four rows and normalizes omitted verses. Queries go back to plain `chapter`/`verse` conditions; responses carry the three new fields; the quote pipeline records, per compiled SQL, which segment it answers, so the executor can emit a notice when a segment's rows are all omitted.

**Tech Stack:** PHP 8.4 (PSR-7/15), PostgreSQL 18, PHPUnit 13, Python 3 scripts; v3 port in PHP/mysqli on MariaDB.

**Spec:** `docs/superpowers/specs/2026-11-16-placement-columns-design.md`

## Global Constraints

- Column definitions, verbatim: `printed_chapter INT`, `verse_label VARCHAR(16)`, `omitted BOOLEAN NOT NULL DEFAULT false`; NULL `printed_chapter` = printed under `chapter`; NULL `verse_label` = printed as the verse number (plus `verseequiv`'s letter); `''` = printed without a number.
- No response field is removed or renamed; `printed_chapter` (int|null), `verse_label` (string|null), `omitted` (bool) are added to every row of `/quote` and every `/search*` endpoint; `notices` (array, always present) is added to `/quote`.
- Notice shape: `{code: "VERSE_OMITTED", version, book (int), chapter (int), verse_from (int), verse_to (int), reference (string), message (string)}`; message `"<Book name> <chapter>:<verse> is not in the text of this edition: the critical text omits it; the number is printed without text."` (range: `<from>-<to>`).
- In HTML, notices only as `<input type="hidden" name="notices" value="<JSON>" class="BibleGetNotices">` inside the existing `div.info.bibleQuote`; no visible markup; the class must not contain `results` or `errors`.
- Copyrighted text never enters the public repo: fixtures are synthetic; public migrations select NABRE/CEI2008/LUZZI rows by key and a text guard and write only empty text; BLPD's changes live in the private BLPD-vatican archive.
- v3 (MariaDB): no schema change, no moved rows; only placeholder text cleanup.
- Commits end with the session's attribution lines; PRs follow the repo's existing format.

## Review Focus

1. A request mixing an omitted verse with a non-omitted one in the same segment (`Mk9:43-45`) must produce no notice; covered in Task 4.
2. `preferorigin`/Esther segments and the Psalm chapter swap must keep compiling (the per-SQL segment record must follow the remapped/swapped AST, not the raw one); covered by running the existing Esther and Psalm-swap integration tests in Task 2 and Task 4.
3. A whole-chapter request (`Mk9`) never gets a notice even if the chapter had only omitted verses; covered in Task 4.
4. Similar search with an omitted source verse must answer 404 "omitted in this edition", not average an empty embedding; covered in Task 3.
5. The 30-verse limit for copyrighted editions still applies after the SQL change (omitted rows count as rows); covered by the existing copyright tests run in Task 2.

---

### Task 1: Schema columns (migration 044) and fixtures

**Files:**
- Create: `migrations/044-placement-columns.sql`
- Modify: `tests/fixtures/seed.sql` (the five edition `CREATE TABLE`s: TEST1, TEST2, VGCL, DRB, TEST3), `tests/fixtures/schema.sql` (only if it creates edition tables; it currently does not define `verseorigin`, check), `migrations/production-schema.sql` (edition table template), `.github/workflows/ci.yaml`, `docker-compose.yml`
- Test: `tests/Integration/Database/PlacementColumnsTest.php`

**Interfaces:**
- Produces: on every edition table, columns `printed_chapter INT`, `verse_label VARCHAR(16)`, `omitted BOOLEAN NOT NULL DEFAULT false`.

- [ ] **Step 1: Write the failing test** `PlacementColumnsTest::testEditionTablesHavePlacementColumns` (DatabaseTestCase): for each of `TEST1, TEST2, TEST3, VGCL, DRB`, query `information_schema.columns` and assert the three columns exist with types `integer`, `character varying` (length 16), `boolean` (not null, default `false`).
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Database/PlacementColumnsTest.php` → FAIL (columns missing).
- [ ] **Step 3: Write `044-placement-columns.sql`**: one transaction; for each `versions_available.sigla` whose table exists (`to_regclass`), `ALTER TABLE … ADD COLUMN IF NOT EXISTS` the three columns; header comment explains the columns (copy the Global Constraints semantics). Add the same three columns to the five fixture tables and to production-schema.sql. Add `044` to CI's "Set up test database" after 043 and to docker-compose as `06-044-…`.
- [ ] **Step 4: Rebuild the local test DB** (schema, seed, biblebooks, 036, 043, 044) and run the test → PASS; run `044` a second time → no error (idempotent).
- [ ] **Step 5: Run the full suite** `composer test` → no new failures.
- [ ] **Step 6: Commit** `feat: placement columns printed_chapter, verse_label, omitted (044)`.

### Task 2: Step-2 storage in the fixtures; plain chapter/verse queries; response fields

**Files:**
- Modify: `tests/fixtures/seed.sql` (TEST3 rows and `TEST3_idx`), `src/Pipeline/Compiler/SqlCompiler.php` (remove `compileBothPlacements`, compile on `chapter`/`verse`), `src/Pipeline/QueryExecutor.php` (`prepareResponse`: no `PrintedLabel::cited`, normalize the three fields)
- Test: `tests/Server/PrintedLabelHttpTest.php`, `tests/Unit/Pipeline/Compiler/SqlCompilerTest.php`, `tests/Integration/Handlers/QuoteHandlerTest.php` (or the existing quote integration test that covers response shape)

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: quote result rows with `printed_chapter` (int|null), `verse_label` (string|null), `omitted` (bool); `SqlCompiler::compile()` signature unchanged.

TEST3 rows after this task (synthetic text, `verseID` in this order):

| book | chapter | verse | verseequiv | printed_chapter | verse_label | omitted |
|---|---|---|---|---|---|---|
| 22 | 9 | 34–35 | | | | |
| 22 | 10 | 1 ("first part labelmark") | NULL | 9 | `10:1` | |
| 22 | 10 | 1 (rest) | | | `''` | |
| 22 | 10 | 2 | | | | |
| 27 | 11 | 25–26 | | | | |
| 27 | 12 | 1 ("labelmark") | NULL | 11 | `12:1` | |
| 27 | 12 | 2–5 | (as today: `''`, `4a`) | | | |
| 48 | 9 | 43, 44 (text `''`, omitted), 45, 46 (`''`, omitted), 47 | | | | 44, 46 |
| 50 | 7 | 52; 53 (`printed_chapter` 8, `verse_label` `7:53`) | | | | |
| 50 | 8 | 1–2 | | | | |

`TEST3_idx` gains Mark (book 48, chapters 9, verses_last `…,47` at position 9) and John (book 50, chapters 8, `…,53,2` at positions 7–8), filling earlier positions with plausible counts.

- [ ] **Step 1: Update tests first.** `PrintedLabelHttpTest::references()` keeps every expected (chapter, verse) pair it has today (step 1's answers don't change); its `verseequiv` expectations for the two moved rows become `null`; add assertions that `Wis12:1` returns `printed_chapter` 11 / `verse_label` `"12:1"`, `Job10:1` returns two rows with `verse_label` `"10:1"` then `""`, `John7:53` returns `printed_chapter` 8 / `verse_label` `"7:53"`, `John7` includes 7:53 and `John8` does not, every other row has `printed_chapter` null, `verse_label` null, `omitted` false. `SqlCompilerTest`: the expected SQL contains `chapter`/`verse` conditions only and no `verseequiv` (replace step 1's two-branch expectations with the pre-#188 form).
- [ ] **Step 2: Update the TEST3 fixture** as in the table, rebuild the test DB, run the two tests → FAIL (old SQL can't find 12:1 rows where they now live; fields missing).
- [ ] **Step 3: Implement**: `SqlCompiler::compile()` appends `' AND ' . $this->compileSegment($segment, 'chapter', 'verse')`; delete `compileBothPlacements`. `QueryExecutor::prepareResponse()` drops the `PrintedLabel::cited` remap and sets `printed_chapter` (`is_numeric ? (int) : null`), `verse_label` (`is_string ? : null`), `omitted` (`(bool)`, PDO returns `true`/`false`).
- [ ] **Step 4: Run** the two tests, then the Esther, Psalm-swap and copyright integration tests (`--filter 'Esther|PsalmChapterSwap|Copyright'`) → PASS.
- [ ] **Step 5: XML and HTML**: in `QuoteHandler` XML results, write `omitted` as `"true"`/`"false"` (not `"1"`/`""`); in `buildHtmlResults`, the verse number span gets `data-printed-chapter`, `data-verse-label` (only when not null) and `data-omitted="true"` (only when omitted), and an omitted verse's text span is empty. Test first: assertions in the existing XML and HTML quote tests for `Wis12:1` (attributes present) and `Mk9:44` (empty text span, `data-omitted`), watch them fail, then implement.
- [ ] **Step 6: Run** `composer test` → PASS (the search endpoints still use `PrintedLabel` until Task 3; their TEST3 tests may now fail: if so, note them and fix in Task 3, which must leave the suite green).
- [ ] **Step 7: Commit** `feat: rows stored at their identity; quote responses carry placement fields`.

### Task 3: Search endpoints read identity; omitted rows excluded; PrintedLabel retired

**Files:**
- Modify: `src/Services/KeywordSearchService.php`, `src/Services/SemanticSearchService.php`, `src/Handlers/SimilarSearchHandler.php` (lookup, exclusion, mapping), the hybrid handler if it maps rows itself
- Delete: `src/Util/PrintedLabel.php`, `tests/Unit/Util/PrintedLabelTest.php`
- Test: `tests/Integration/Handlers/SimilarSearchPrintedLabelTest.php`, `tests/Integration/Handlers/HybridSearchPrintedLabelTest.php`, `tests/Server/KeywordSearchHttpTest.php`, `tests/Integration/Handlers/SemanticSearchHandlerTest.php`

**Interfaces:**
- Consumes: Task 2 fixture and field normalization (reuse one private static helper per class, or a small `Util\Placement::fromRow(array $row): array{printed_chapter: ?int, verse_label: ?string, omitted: bool}` used by all mappers and by `QueryExecutor` — pick one and use it everywhere).
- Produces: search result rows with the three fields; no code parses `verseequiv` for a `chapter:verse` label (`grep -rn "PrintedLabel\|split_part(verseequiv" src` is empty).

- [ ] **Step 1: Tests first.** Similar: `Wis12:1` source found and excluded from results (existing test, unchanged answers); new `testOmittedSourceVerseIs404`: `reference=Mk9:44&version=TEST3` → 404 whose detail contains `omitted in this edition`; new `testOmittedRowsNeverReturned`: give TEST3 Mk 9:44 an embedding identical to Mk 9:43's, search similar from 9:43, assert 9:44 absent. Semantic/hybrid: an omitted row with an embedding is never returned. Keyword: result rows carry the three fields.
- [ ] **Step 2: Run** them → FAIL.
- [ ] **Step 3: Implement**: replace `PrintedLabel::sql*()` with `chapter`/`verse`; add `AND NOT omitted` to the semantic, similar (candidate query and source lookup) and hybrid semantic leg; the source lookup throws `NotFoundException('Verse omitted in this edition: …')` when the matched rows are all omitted (check before averaging); mappers add the three fields; delete `PrintedLabel` and its unit test.
- [ ] **Step 4: Run** `composer test`, `composer lint`, `composer analyse` → PASS; `grep -rn "PrintedLabel" src tests` → no matches except comments you then remove.
- [ ] **Step 5: Commit** `refactor: search reads verse identity; omitted rows never searched; PrintedLabel retired`.

### Task 4: VERSE_OMITTED notices

**Files:**
- Create: `src/Pipeline/SegmentRef.php`
- Modify: `src/Pipeline/QuoteContext.php` (`public array $notices = []`, `public array $formulatedSegments = []`), `src/Pipeline/QueryFormulator.php`, `src/Pipeline/Compiler/SqlCompiler.php` (return per-SQL segment info), `src/Pipeline/QueryExecutor.php`, `src/Handlers/QuoteHandler.php` (JSON, XML, HTML)
- Test: `tests/Server/OmittedVerseNoticeHttpTest.php`

**Interfaces:**
- Produces: `final class SegmentRef { public function __construct(public readonly int $book, public readonly int $chapter, public readonly int $verseFrom, public readonly int $verseTo, public readonly string $reference) {} }`; `QuoteContext::$formulatedSegments` is `array<int, ?SegmentRef>` parallel to `$formulatedQueries` (null for whole-chapter and cross-chapter segments); `QuoteContext::$notices` is `list<array{code: string, version: string, book: int, chapter: int, verse_from: int, verse_to: int, reference: string, message: string}>`.
- `SqlCompiler` gets a sibling method `public function segmentRefs(BibleQuery $query, string $bookAsWritten, string $notation): array` returning `array<int, ?SegmentRef>` in the same order as `compile()`'s queries, built from the **remapped/swapped** AST the formulator compiles; `reference` = `"<bookAsWritten> <chapter><sep><from>[-<to>]"` with `<sep>` `:` (ENGLISH) or `,` (EUROPEAN).
- `bookAsWritten`: the leading book token of `$ctx->validatedQueries[$i]` (regex `^\s*(\d?\s*[^\d\s,:.;-][^\d,:.;]*?)\s*\d` → trimmed group 1).

- [ ] **Step 1: Tests first** (`OmittedVerseNoticeHttpTest`, TEST3):
  - `Mk9:44` → 200; `results` has one row, `omitted` true, `text` ""; `notices` == `[{code: VERSE_OMITTED, version: TEST3, book: 48, chapter: 9, verse_from: 44, verse_to: 44, reference: "Mk 9:44", message: "Mark 9:44 is not in the text of this edition: the critical text omits it; the number is printed without text."}]` (book name as the version index gives it).
  - `Mk9:44,46` → two notices, references `Mk 9:44` and `Mk 9:46`.
  - `Mk9,44` (European) → reference `Mk 9,44`.
  - `Mk9:43-45` → three rows, `notices` == `[]`.
  - `Mk9` → `notices` == `[]`.
  - `Mk9:44` with `version=TEST3,TEST1` (TEST1 has a non-omitted Mk 9:44 row, add one to TEST1's fixture if absent) → exactly one notice, version TEST3.
  - `return=xml` → `<notices><notice code="VERSE_OMITTED" …>message</notice></notices>`; `return=html` → contains `class="BibleGetNotices"` inside `div.info`, and no visible element with the message.
  - Every quote response without omitted verses has `notices: []`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**: formulator stores `formulatedSegments[$nn]` beside each SQL; executor, after fetching a query's rows, if `formulatedSegments[$i]` is set and all fetched rows have `omitted === true` (and at least one row), appends the notice (message from the version index's book name and the numbers); `QuoteHandler` emits `notices` in JSON (top level, next to `errors`), XML (`<notices>` element) and HTML (hidden input in the info div).
- [ ] **Step 4: Run** the new test, then the Esther and Psalm-swap integration tests, then `composer test`, lint, analyse → PASS.
- [ ] **Step 5: Commit** `feat: VERSE_OMITTED notice for references that ask only for omitted verses`.

### Task 5: Scripts — embeddings skip omitted rows; snapshots show printed layout

**Files:**
- Modify: `scripts/compute_embeddings.py` (loaders add `AND NOT omitted` when the column exists), `scripts/versification_snapshot.py`
- Test: `tests/Unit/Data/VersificationCasesTest.php` (unchanged code; its fixtures change in Task 6)

**Interfaces:**
- Produces: snapshot rows grouped by `(book, coalesce(printed_chapter, chapter), origin)`; label = `verse_label` when not NULL (`''` written as `·`), else today's label; an omitted verse keeps its number.

- [ ] **Step 1:** Add a small self-check to `versification_snapshot.py` (a `--self-test` flag with three asserted cases: `label(1, '', None)` → `1`, `label(1, '', '')` → `·`, `label(1, '', '10:1')` → `10:1`) and run it → FAIL; implement; run → PASS.
- [ ] **Step 2:** `compute_embeddings.py`: `has_column(conn, version, 'omitted')` → append `AND NOT omitted` to each loader query; `python3 -m py_compile`; `composer lint:python` → PASS.
- [ ] **Step 3: Commit** `feat(scripts): snapshots show printed placement; embeddings skip omitted rows`.

### Task 6: Staging data (045 + private BLPD half), snapshots, cases, live tests

**Files:**
- Create: `migrations/045-placement-data.sql`; in `~/development/BibleGet-I-O/BLPD-vatican`: `scripts/omitted_1kgs15.py` (or an extension of `split_inline.py`) and `migrations/045-blpd-omitted-verses.sql`
- Modify: `tests/fixtures/versification/{NABRE,CEI2008,LUZZI,BLPD}.txt` (regenerated), `tests/fixtures/versification/cases.json`, `tests/Live/NabrePrintedLabelLiveTest.php`, new `tests/Live/OmittedVerseLiveTest.php`

- [ ] **Step 1: Decide CEI 2008 Acts 8:37** from the private CEI2008-bibbiaedu archive: if the source has no text in the body for 8:37, it is omitted; record the finding in 045's header.
- [ ] **Step 2: Write `045-placement-data.sql`** (one transaction, refuses unexpected data):
  - NABRE: by `verseID` found via (book, chapter, verse, verseequiv) and `md5(text)` taken from staging: Wis row `27/11/26/'12:1'` → chapter 12, verse 1, verseequiv NULL, printed_chapter 11, verse_label `'12:1'`; Job row `22/9/35/'10:1a'` → chapter 10, verse 1, verseequiv NULL, printed_chapter 9, verse_label `'10:1'`; `22/10/1` (the other row) → verse_label `''`; John `50/7/53` → printed_chapter 8, verse_label `'7:53'`.
  - Omitted, guarded by `btrim(text_plain) ~ '^[][().]*$'` (or the empty string) **and** the exact (book, chapter, verse) list from the spec: CEI2008 (15 NT rows, + Acts 8:37 per Step 1), NABRE (16 NT rows + 1 Mac 9:34), LUZZI (16 NT rows): `text = ''`, `omitted = true`, embeddings NULL. Assert the expected counts, else RAISE.
  - BLPD: check the md5 the private half produces (as 042 does), then nothing else (its rows are set by the private half).
  - Recompute `NABRE_idx` from the rows as `035` did.
- [ ] **Step 3: BLPD private half** (BLPD-vatican): remove the trailing ` 6i` / ` 32ii` from 1 Kgs 15:5 / 15:31 in `text/11-1Re.tsv`, add rows 15:6 and 15:32 with empty text; generate `migrations/045-blpd-omitted-verses.sql` with `migration_inline.py`'s approach (one verseID shift pass; the new rows get `omitted = true`); commit and push the archive.
- [ ] **Step 4: Dry-run** both on staging (detached, `ROLLBACK`), then apply for real (detached, log file), then `045`'s public half.
- [ ] **Step 5: Regenerate snapshots** for NABRE, CEI2008, LUZZI, BLPD; update `cases.json` (`nabre-wis-12-1` → Wis 11 `1-26,12:1`, Wis 12 `2-27` unchanged; `nabre-job-10-1a` → Job 9 `1-35,10:1`, Job 10 `·,2-22`; add a John 7:53 case: NABRE John 7 `1-52`, John 8 `7:53,1-11`; `blpd-omitted-verses` keys for 1 Kgs 15 now `1-34`); run `VersificationCasesTest` → PASS.
- [ ] **Step 6: Live tests**: update `NabrePrintedLabelLiveTest` to assert the placement fields; new `OmittedVerseLiveTest`: CEI2008 `Mc9:43-48` returns 44 and 46 with `omitted` true, `text` "" and `notices` `[]`; CEI2008 `Mc9:44` returns one `VERSE_OMITTED` notice. Run against staging after deploying the branch's code is not possible before merge: run them locally with `BIBLEGET_LIVE_URL` only after the PR is merged and deployed; until then mark the plan step as pending in the PR.
- [ ] **Step 7: Commit** `fix: NABRE rows at their identity; omitted verses normalized (045)`.

### Task 7: v3 port, docs

**Files:**
- Create (scratchpad, not committed): `v3_045.php`
- Modify: `docs/CHANGELOG.md`, `openapi.json`, wiki pages Design-Verse-Rendering-Modes (step 2 → done, open question 1 answered), Known-Data-Issues (#180 resolved), Migrations (044, 045)

- [ ] **Step 1: v3 port script**: for CEI2008 and NABRE (and LUZZI if its v3 rows hold placeholders), set `text = ''` for exactly the staging-omitted (book, chapter, verse) keys whose v3 text matches the placeholder pattern; BLPD 1 Kgs 15:5/15:31: strip the trailing ` 6i` / ` 32ii`. Copy-table + checksum + `RENAME` with `_pre_045` backups, as in the 042 port. Run only after the PR is merged (the user asks for production ports explicitly).
- [ ] **Step 2: Docs**: CHANGELOG (Added: placement fields, notices; Changed: NABRE rows; Fixed: omitted placeholders, #180); openapi.json: the three row fields and `notices` with the notice schema; wiki pages as listed.
- [ ] **Step 3: Full verification**: `composer test`, `composer lint`, `composer analyse`, `composer lint:python`.
- [ ] **Step 4: Commit** `docs: placement fields, omitted verses, notices`.
