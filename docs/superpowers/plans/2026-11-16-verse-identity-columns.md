# Verse-identity columns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename and sort the verse-identity columns (#184) so each holds one kind of value (`verse_segment`, `alt_ref`, `acrostic`, `text_tradition`, `sort_order`), add a unique key and a `biblebooks_codes` table, and move every caller to the new names.

**Architecture:** One public migration, `046-verse-identity-columns.sql`, converts every table in `versions_available` that still has `verseequiv`, sorting values by shape and refusing on anything unclaimed; it is applied to the old-shape test fixtures in CI and docker-compose (as 044 is), so the suite runs on migrated tables. PHP, scripts, tests and `openapi.json` switch to the new names; responses carry them directly (no aliases).

**Tech Stack:** PostgreSQL 18 (PL/pgSQL `DO` blocks, `NULLS NOT DISTINCT`), PHP 8.4 / PHPUnit 13, Python 3 scripts.

**Spec:** `docs/superpowers/specs/2026-11-16-verse-identity-columns-design.md`

## Global Constraints

- New columns: `verse_segment VARCHAR(4)`, `alt_ref VARCHAR(16)`, `acrostic VARCHAR(16)`, each NULL with `CHECK (<col> <> '')`; `text_tradition VARCHAR(10) CHECK (text_tradition IN ('GREEK','HEBREW'))`; `verseID` renamed `sort_order` (stays the primary key).
- `verse_label` semantics (044): NULL = plain number printed; `''` = no number printed; otherwise the printed label.
- Unique key: `UNIQUE NULLS NOT DISTINCT (book, chapter, verse, verse_segment, text_tradition, printed_chapter)`; it replaces the `<T>_bcv` index.
- No verse text, heading, `title_position` or reading order changes: a per-edition digest of `(sort_order, book, chapter, verse, text, title1, title2, title3, title_position)` is identical before and after, or the migration raises.
- Responses carry `verse_segment`, `alt_ref`, `acrostic`, `text_tradition`; `verseequiv`, `versedescr`, `verseorigin`, `verseID`/`sort_order` never appear in a response.
- The frozen v3 API (MariaDB), `NABRE_old`, and one-shot historical scripts (`scripts/format_*`, `scripts/repair_*`, `scripts/nabre_headings_from_scml.py`) are not touched.
- No copyrighted text in this public repo: tests use the fixtures' own text; staging checks use md5.

## Review Focus

1. A staging value no rule claims (e.g. a `verseequiv` like `3b-4`) → 046 raises naming edition, column and value, and changes nothing. Test: `testUnclaimedValueRaises` (Task 1).
2. 046 applied a second time → no-op, no error. Test: `testSecondRunIsNoOp` (Task 1).
3. A second unlettered row with the same (book, chapter, verse, tradition, printed_chapter) → rejected by the key, NULLs counting as equal. Test: `testUniqueKeyTreatsNullsAsEqual` (Task 1).
4. An empty string written to `verse_segment`/`alt_ref`/`acrostic` → rejected. Test: `testEmptyStringRejected` (Task 1).
5. A client still reading `verseequiv` gets nothing silently → the old names must be absent from JSON, XML and HTML responses (so a stale client fails visibly in testing). Test: `testResponsesCarryNoOldFieldNames` (Task 2).

---

### Task 1: Migration 046 and its test

**Files:**
- Create: `migrations/046-verse-identity-columns.sql`
- Create: `tests/Integration/Database/VerseIdentityColumnsTest.php`
- Modify: `.github/workflows/ci.yaml` (after the 044 line), `docker-compose.yml` (mount as `07-046-verse-identity-columns.sql`)

**Interfaces:**
- Produces: the schema above on every `versions_available` table; table `biblebooks_codes(book INTEGER PRIMARY KEY REFERENCES biblebooks_fullname("BOOK"), usfm_code CHAR(3) NOT NULL UNIQUE, osis_code VARCHAR(8) NOT NULL UNIQUE)`; `bibleget_rebuild_in_order(tbl, ord_table)` recreated using `sort_order` (same signature, `ord` table keeps columns `vid`, `ord`).

Migration rules (one `DO` block per table, inside one `BEGIN … COMMIT`; a table without `verseequiv` is skipped, so a second run is a no-op):

| Source | Shape | Target |
|---|---|---|
| `verseequiv` | `^[0-9]+[a-z]+$` | `verse_segment` = the letters |
| `verseequiv` | `^\([0-9]+,[0-9]+>?\)$` or `^[0-9]+$` | `alt_ref` = as stored |
| `verseequiv`, `versedescr` | `''` | NULL |
| `versedescr` | `^[0-9]+:[0-9]+$`, tables DRB, VGCL and any table not named below | `alt_ref` (if `alt_ref` already set: raise) |
| `versedescr` | NABRE `^[A-F]:[0-9]+$` | `verse_label` (must be NULL before) |
| `versedescr` | NABRE (27,12,1) `12:1`, (22,10,1) `10:1a`; CEI2008 (48,7,16) `[16]` | dropped (exactly these 3) |
| `versedescr` | a letter name, optionally `[…]` with trailing space | `acrostic`, brackets and spaces stripped |
| heading (`title1`–`title3`, whole trimmed value) | in the edition's letter list | `acrostic`; if the row already has one from `versedescr` they must be equal, unless the heading has a `title_position` (then the heading's letter wins) |
| `verseorigin` | | renamed `text_tradition`; LUZZI and DIVCOM (no column) get it, all NULL |

Letter lists for headings (from staging, 2026-11-16):
- LUZZI: `ALEF BET GHIMEL DALET HE VAU ZAIN CHET TETH IOD CAF LAMED MEM NUN SAMEC AIN PE TSADE KOF RESCH SCHIN TAU`
- NABRE: `Aleph Beth Gimel Daleth He Waw Zayin Heth Teth Yodh Kaph Lamedh Mem Nun Samekh Ayin Pe Sadhe Qoph Resh Shin Taw`
- VGCL: `Aleph Beth Ghimel Daleth He Vau Zain Heth Teth Jod Caph Lamed Mem Nun Samech Ain Phe Sade Coph Res Sin Tau Thau`
- CEI2008: `Alef Bet Ghimel Dalet He Vau Zain Het Tet Iod Caf Lamed Mem Nun Samec Ain Pe Sade Kof Res Sin Tau`
- BLPD: `Alef Bet Guímel Dálet He Vau Zain Jet Tet Iod Caf Lámed Mem Nun Sámec Ain Pe Sade Qof Res Sin Tau`

Per-chapter acrostic counts checked when the chapter has rows: LUZZI Ps 119 = 22; NABRE Ps 37 = 22, Ps 119 = 22; VGCL Ps 118 = 22, Lam 1 = 21, Lam 2 = 22, Lam 3 = 66, Lam 4 = 22; BLPD Ps 119 = 22. CEI2008 has no fixed count; its guard is the agreement check.

Every other non-NULL value in `verseequiv`/`versedescr` raises `'%: % value % claimed by no rule'` (table, column, value). Before dropping, the moved counts per rule are compared with the source counts per rule. Then: drop `verseequiv`, `versedescr`, the `_bcv` index and the `verseorigin` CHECK; rename `verseorigin`, `verseID`; add the CHECKs and the key; recreate `bibleget_rebuild_in_order`; create and fill `biblebooks_codes` (`IF NOT EXISTS`, `ON CONFLICT DO NOTHING`); compare digests.

`biblebooks_codes` rows, books 1–73 in order:
- usfm: `GEN EXO LEV NUM DEU JOS JDG RUT 1SA 2SA 1KI 2KI 1CH 2CH EZR NEH TOB JDT EST 1MA 2MA JOB PSA PRO ECC SNG WIS SIR ISA JER LAM BAR EZK DAN HOS JOL AMO OBA JON MIC NAM HAB ZEP HAG ZEC MAL MAT MRK LUK JHN ACT ROM 1CO 2CO GAL EPH PHP COL 1TH 2TH 1TI 2TI TIT PHM HEB JAS 1PE 2PE 1JN 2JN 3JN JUD REV`
- osis: `Gen Exod Lev Num Deut Josh Judg Ruth 1Sam 2Sam 1Kgs 2Kgs 1Chr 2Chr Ezra Neh Tob Jdt Esth 1Macc 2Macc Job Ps Prov Eccl Song Wis Sir Isa Jer Lam Bar Ezek Dan Hos Joel Amos Obad Jonah Mic Nah Hab Zeph Hag Zech Mal Matt Mark Luke John Acts Rom 1Cor 2Cor Gal Eph Phil Col 1Thess 2Thess 1Tim 2Tim Titus Phlm Heb Jas 1Pet 2Pet 1John 2John 3John Jude Rev`
- A header comment notes: Greek-tradition Esther rows are USFM `ESG` / OSIS `AddEsth`; USFM splits Baruch 6 as `LJE`. Both are left to the transformers.

- [ ] **Step 1: Write the failing tests** in `VerseIdentityColumnsTest extends DatabaseTestCase`. Each test opens a transaction, creates an old-shape table `"ZZ046"` (columns as `tests/fixtures/seed.sql`'s `TEST3`, plus `versedescr`, `title1..3`, `title_position`), registers it in `versions_available`, inserts rows, runs the migration file with its `BEGIN;`/`COMMIT;` lines stripped, asserts, and rolls back.
  - `testShapesLandInTheirColumns`: rows `verseequiv` `'4a'` → `verse_segment 'a'`; `'17aa'` → `'aa'`; `'(9,22)'` → `alt_ref '(9,22)'`; `'(113,20>)'` → `alt_ref '(113,20>)'`; `'33'` → `alt_ref '33'`; `''` → all NULL; `versedescr '11:2'` → `alt_ref '11:2'`; `versedescr '[Alef] '` → `acrostic 'Alef'`; `verseorigin 'GREEK'` → `text_tradition 'GREEK'`.
  - `testOldColumnsGoneAndSortOrderIsKey`: `information_schema.columns` for `ZZ046` has none of `verseequiv`, `versedescr`, `verseorigin`, `verseID`; `sort_order` is the primary key.
  - `testUnclaimedValueRaises`: a row with `verseequiv '3b-4'` → `PDOException` whose message contains `ZZ046`, `verseequiv` and `3b-4`.
  - `testSecondRunIsNoOp`: running the file twice raises nothing and the second run leaves the column values unchanged.
  - `testUniqueKeyTreatsNullsAsEqual`: after migration, inserting a copy of an unlettered row (same book, chapter, verse, NULL segment, NULL tradition, NULL printed_chapter) → unique violation (SQLSTATE 23505).
  - `testEmptyStringRejected`: `UPDATE "ZZ046" SET verse_segment = ''` → check violation (23514); same for `alt_ref`, `acrostic`.
  - `testFixtureTablesMigrated`: `TEST1`, `TEST2`, `TEST3`, `VGCL`, `DRB` have `sort_order` and `text_tradition`, no `verseequiv`.
  - `testBookCodes`: `biblebooks_codes` has 73 rows; book 1 = (`GEN`, `Gen`), 19 = (`EST`, `Esth`), 73 = (`REV`, `Rev`).

- [ ] **Step 2: Run them to verify they fail**
  Run: `vendor/bin/phpunit tests/Integration/Database/VerseIdentityColumnsTest.php`
  Expected: FAIL (migration file missing).

- [ ] **Step 3: Write `migrations/046-verse-identity-columns.sql`** to the rules above, and add it to CI and docker-compose.

- [ ] **Step 4: Rebuild the local test DB with 046 and run the new tests**
  Run: the scratchpad `rebuild_testdb.sh migrations/043-keyword-search-unaccent.sql migrations/044-placement-columns.sql migrations/046-verse-identity-columns.sql`, then `vendor/bin/phpunit tests/Integration/Database/VerseIdentityColumnsTest.php`
  Expected: PASS. The rest of the suite is expected to fail until Task 2 (the code still reads the old names); this is the one task boundary where the suite is red.

- [ ] **Step 5: Dry-run 046 on staging** (detached, with a log, inside `BEGIN … ROLLBACK`; save the PID; never `pkill -f`). Before the rollback, print per-edition move counts and acrostic counts per chapter.
  Expected: no exception; the counts match the spec's survey (CEI2008 alt_ref 52 from `verseequiv`; NVBSE and BLPD 10 each; NABRE Esther `verse_label` set on the A–F rows; acrostic counts as listed). Any raise is investigated and ruled on in the ledger before going on.

- [ ] **Step 6: Commit**
  `git add migrations/046-verse-identity-columns.sql tests/Integration/Database/VerseIdentityColumnsTest.php .github/workflows/ci.yaml docker-compose.yml && git commit -m "feat(046): verse-identity columns by what they hold; unique key; book codes (#184)"`

### Task 2: Code, fixtures, tests and API on the new names

**Files:**
- Modify: `src/Util/Placement.php`, `src/Pipeline/QueryFormulator.php:104`, `src/Pipeline/Transform/EstherRemapper.php:16,60,127`, `src/Pipeline/Compiler/SqlCompiler.php:19,64`, `src/Pipeline/QueryExecutor.php:396`, `src/Handlers/QuoteHandler.php:77`, `src/Handlers/SimilarSearchHandler.php:127,235-243,347`, `src/Handlers/SemanticSearchHandler.php:114`, `src/Handlers/HybridSearchHandler.php:162-271`, `src/Services/KeywordSearchService.php:153-295`, `src/Services/SemanticSearchService.php:122`, `src/Util/SearchUtils.php:191-223`
- Modify: the 18 test files that name the old columns (`grep -rlwE "verseequiv|versedescr|verseorigin|verseID" tests`), `tests/fixtures/seed.sql` only where a fixture value must change for the migrated shape
- Modify: `openapi.json` (replace the old fields; insert as text, don't re-serialize)
- Test: `tests/Server/QuoteHandlerHttpTest.php`

**Interfaces:**
- Consumes: Task 1's schema.
- Produces: `Placement::verseFields(array $row): array{chapter: int, verse: int, verse_segment: ?string, alt_ref: ?string, printed_chapter: ?int, verse_label: ?string, omitted: bool}`; search rows keyed by `sort_order` internally (`SearchUtils::assignCanonicalOrder` reads and unsets `sort_order`).

- [ ] **Step 1: Update the tests to the new names** (assert `verse_segment 'a'` where they asserted `verseequiv '1a'`; `alt_ref` where a cross-number was asserted; `text_tradition` for `verseorigin`; `sort_order` for `verseID`), and add `testResponsesCarryNoOldFieldNames` to `QuoteHandlerHttpTest`: one Esther Greek quote from the fixtures in JSON, XML and HTML; the bodies contain none of `verseequiv`, `versedescr`, `verseorigin`, `verseID`, `sort_order`, and the JSON rows have the keys `verse_segment`, `alt_ref`, `acrostic`, `text_tradition`.

- [ ] **Step 2: Run the suite to verify the failures**
  Run: `composer test`
  Expected: FAIL in the updated tests (code still uses old names).

- [ ] **Step 3: Switch the code** to the new names, as the Interfaces block says; `SimilarSearchHandler` keeps `IS NOT DISTINCT FROM` on `text_tradition`.

- [ ] **Step 4: Run the suite**
  Run: `composer test` (plus `composer lint` and `composer analyse`)
  Expected: all PASS, PHPStan level 10 clean.

- [ ] **Step 5: Commit**
  `git commit -am "feat: code, tests and API on the verse-identity column names (#184)"`

### Task 3: Book codes in `/metadata/biblebooks`

**Files:**
- Modify: `src/Handlers/MetadataHandler.php` (`getBibleBooks`), `openapi.json`
- Test: `tests/Server/MetadataHandlerHttpTest.php`

**Interfaces:**
- Consumes: `biblebooks_codes` (Task 1).
- Produces: the `biblebooks` response gains `codes: list<array{usfm: string, osis: string}>`, index-aligned with `results`.

- [ ] **Step 1: Write the failing test** `testBiblebooksCarriesCodes`: `count(codes) === count(results)`; `codes[0] === ['usfm' => 'GEN', 'osis' => 'Gen']`; `codes[18] === ['usfm' => 'EST', 'osis' => 'Esth']`; `codes[72] === ['usfm' => 'REV', 'osis' => 'Rev']`.
- [ ] **Step 2: Run it** — `vendor/bin/phpunit --filter testBiblebooksCarriesCodes` — Expected: FAIL (no `codes` key).
- [ ] **Step 3: Implement**: one `SELECT book, usfm_code, osis_code FROM biblebooks_codes ORDER BY book`, aligned to `results` by `"BOOK"`; a book with no codes row → `InternalServerErrorException('biblebooks_codes has no row for book N.')`. Document `codes` in `openapi.json`.
- [ ] **Step 4: Run** `composer test` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -am "feat(metadata): USFM and OSIS book codes in /metadata/biblebooks (#184)"`

### Task 4: Live scripts

**Files:**
- Modify: `scripts/compute_embeddings.py` (`"verseID"` → `sort_order`), `scripts/sync_labse_to_live.sh` (same), `scripts/versification_snapshot.py` (label from `verse` + `verse_segment`; tradition from `text_tradition`; docstrings)

- [ ] **Step 1: Update `versification_snapshot.py --self-test`** cases to the new column names; run `python3 scripts/versification_snapshot.py --self-test`. Expected: FAIL.
- [ ] **Step 2: Update the three scripts.**
- [ ] **Step 3: Run** the self-test, `composer lint:python`, and `bash -n scripts/sync_labse_to_live.sh`. Expected: PASS.
- [ ] **Step 4: Commit** `git commit -am "chore(scripts): verse-identity column names (#184)"`

### Task 5: Docs, PR, staging

**Files:**
- Modify: `CHANGELOG.md`, `tests/Live/LiveTestCase.php` (`labels()` reads `verse_segment`), the live tests asserting `verseequiv`, `docs/superpowers/specs/2026-11-16-verse-identity-columns-design.md` (only if a ruling changed it)
- Prepare (separate repo, not pushed before the user says so): the 10 `endpoint.wiki` pages naming the old columns

- [ ] **Step 1: Update the live tests and CHANGELOG**; `composer test` PASS (live tests skip locally).
- [ ] **Step 2: Snapshots before**: on staging, export `versification_snapshot.py` output for every edition (old schema, old script from `development`) to the VPS's /tmp.
- [ ] **Step 3: Push the branch and open the PR** (repo conventions; body lists the rulings and the staging dry-run counts).
- [ ] **Step 4: After the user's merge instruction**: apply 046 on staging (detached, logged), deploy staging, then regenerate the snapshots with the new script. Expected: byte-identical to Step 2's; live suite green.
- [ ] **Step 5: Wiki**: show the user the wiki diff; push only on their instruction.
