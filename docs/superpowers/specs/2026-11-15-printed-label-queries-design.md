# Design: answer references by the verse number the edition prints

**Status:** approved approach (option B, approach 1), 2026-11-15. Step 1 of five; see "Where this fits".

## Intent

A Bible reference names a verse by its number. When an edition prints a verse (or part of one) at the end of the previous chapter under that verse's own label, the API must still answer the reference with it:

- NABRE prints Wis 12:1 after 11:26, labelled `12:1`; chapter 12 starts at verse 2.
- NABRE prints the first words of Job 10:1 ("I loathe my life.") after 9:35, labelled `10:1`; chapter 10 opens with the rest of verse 1, unnumbered.

Today `Wis12:1` returns nothing, `Job10:1` returns only the second part, `Job9:35` returns 9:35 plus "I loathe my life.", and `Wis11:26` fails with "there is no verse 26 … the last possible verse is 12". References come from outside the edition (lectionaries, footnotes, cross-references, other editions), so a reference must select the verse that carries that number. Where the edition prints it stays recorded and drives the order of the rows; reproducing the printed page is print mode's job (step 5).

## Goals

1. A reference selects rows by the verse number the edition prints, including a `chapter:verse` label printed in another chapter. The same rule applies on every endpoint that names or looks up a verse: `/quote`, `/search` (keyword), `/search/semantic`, `/search/similar`, `/search/hybrid`.
2. Rows come back in printed order (`verseID`), as today.
3. In every endpoint's results (JSON/XML/HTML), such a row reports the chapter and verse it belongs to; `verseequiv` keeps its printed label.
4. `NABRE_idx` (chapter bounds and counts) is computed by verse number, which fixes `Wis11:26`.
5. Tests: fixture-based HTTP tests in CI, plus a live suite run against staging.

## Non-goals

- No change to the stored rows (`chapter`, `verse`, `verseequiv` of the two NABRE rows stay as they are). Placement columns are step 2.
- No print mode, no client changes. The only response change besides the remapped numbers is `verseequiv` added to search results (see Search).
- John 7:53 (NABRE prints it at the start of chapter 8, stored in chapter 7): its row is untouched, but its index bound is fixed (see migration 035).
- The frozen v3 API on MariaDB is out of scope (porting the index fix there can be a follow-up).

## Anti-goals

- Returning the same text twice in one response (e.g. `Job9:35-10:1` must still give 4 rows: 9:35, 10:1a, 10:1, 10:2).
- Committing NABRE text to the public repo, in fixtures or in live-test expectations.

## Approaches considered

1. **Resolve labels at query time, storage unchanged (chosen).** The SQL also matches rows whose `verseequiv` is a `chapter:verse` label inside the requested range and excludes those rows from the chapter where they are stored; the response maps them to their number; `NABRE_idx` is recomputed. Step 2 later replaces the label parsing with the new columns.
2. Move the two rows to 12:1 and 10:1 now. Loses the record of where NABRE prints them until step 2, and pre-empts step 2's column design. Rejected.
3. Add step 2's placement columns first. Cleaner end state, but the bug fixes wait behind a schema change. Rejected for now.

## Design

### The label

A row is **labelled elsewhere** when `verseequiv ~ '^[0-9]+:[0-9]+[a-z]*$'`. Its **cited chapter** is the number before `:`; its **cited verse** is the leading digits after `:` (`10:1a` → chapter 10, verse 1). For every other row the cited chapter and verse are `chapter` and `verse`. Today only the two NABRE rows match (checked on staging across all eight editions). Esther's cross-references live in `versedescr`, not `verseequiv`, so they are unaffected; CEI2008's psalm cross-numbers in `verseequiv` look like `(9,22)` and don't match.

### Query (`src/Pipeline/Compiler/SqlCompiler.php`)

Each segment's condition is currently built from `chapter` and `verse`. Build it from a pair of column expressions instead, and emit:

```
book = B AND (
     ( <condition on chapter, verse> AND NOT <labelled elsewhere> )
  OR ( <labelled elsewhere> AND <condition on cited chapter, cited verse> )
) <preferorigin> ORDER BY "verseID" [LIMIT 30]
```

The whole condition is parenthesised so the appended `preferorigin` clause binds to all of it. The first branch keeps using the `(book, chapter, verse)` index; the second scans only the book's rows, of which at most a couple match. The SQL expressions for the cited numbers are built in one place (a small helper), so step 2 can swap them for columns.

Resulting behaviour (NABRE):

| Query | Rows |
|---|---|
| `Wis12:1` | the `12:1` row |
| `Wis12:1-5`, `Wis12` | `12:1`, then 2-5 / 2-27 |
| `Wis11`, `Wis11:20-26` | up to 26, without `12:1` |
| `Wis11:26` | 26 only (no error) |
| `Wis11:22-12:2` | 22-26, `12:1`, 2 (unchanged) |
| `Job9:35` | 9:35 only |
| `Job10:1` | `10:1a`, then 10:1 |
| `Job9:35-10:2` | 9:35, `10:1a`, 10:1, 10:2 (unchanged) |

### Response (`QueryExecutor::prepareResponse`)

For a labelled-elsewhere row, set `chapter` and `verse` to the cited numbers; leave `verseequiv` (and `versedescr`) as stored, so the printed label stays available. `canonical_order` still follows `verseID`. Clients that start a new heading when `chapter` changes and a new number when `verse` changes will show "I loathe my life." under Job 10:1, and Wis 12:1 under chapter 12.

### One helper

The label rule lives in one class (e.g. `src/Util/PrintedLabel.php`, name left to the builder) with two halves, used by every endpoint:

- **SQL:** the expressions for "labelled elsewhere", cited chapter and cited verse, for building WHERE clauses.
- **PHP:** given a fetched row, return its cited chapter and verse (remapping a labelled-elsewhere row, passing others through).

Step 2 replaces both halves with the new columns; nothing else should parse `verseequiv`.

### Search

- **Keyword, semantic, hybrid:** the row builders (`KeywordSearchService`, `SemanticSearchService`, `SimilarSearchHandler::mapResults`) report the cited chapter and verse through the helper, and add `verseequiv` to each result (my call: search results then carry the printed label, as quote results do; it's an additive field, documented in `openapi.json`). Filters and ranking are unchanged.
- **Similar (`/search/similar`):** the starting verse is looked up by cited chapter and verse. When that matches more than one row (NABRE Job 10:1: the `10:1a` row and the rest), the starting embedding is the average of their embeddings (pgvector `avg`), so "similar to Job 10:1" means the whole verse (my call). All the matched rows are excluded from the results, replacing today's `NOT (book, chapter, verse)` exclusion. A reference that matches no row still answers 404.

### Index (`NABRE_idx`) — migration `035`

Recompute `verses_last` and `verses_count` for NABRE by cited chapter and verse, using the same counting rule as `031` (count of distinct verse numbers per chapter, last = highest), for every NABRE book except Esther (book 19), whose index has its own layout and is left alone. A dry run on staging (2026-11-15) changes exactly these chapters, all expected:

| Book | Chapter | count old → new | last old → new | Why |
|---|---|---|---|---|
| Tob (17) | 6 | 17 → 18 | 18 | stale count |
| Job (22) | 9 | 36 → 35 | 35 | the `10:1a` row was counted |
| Job (22) | 10 | 21 → 22 | 22 | stale count |
| Wis (27) | 11 | 27 → 26 | `12:1` → 26 | the `12:1` row; fixes `Wis11:26` |
| Wis (27) | 12 | 26 → 27 | 27 | 12:1 now counted here |
| Sir (28) | 33, 41, 47 | 34 → 33, 26 → 22, 27 → 25 | unchanged | lettered rows were counted |
| John (50) | 7 | 52 → 53 | 52 → 53 | fixes `John7:53`, which today fails "last possible verse is 52" |
| John (50) | 8 | 60 → 59 | 59 | 7:53 was counted under chapter 8 |

The migration raises if, afterwards, any `verses_last` or `verses_count` token in any `*_idx` table is not an integer (a staging scan found `12:1` to be the only one). The code keeps reading the index with `intval`, but `QuoteContext` and `MetadataHandler` log an error for a non-integer token, so a label can't silently become a bound again.

Apply to staging, then run `scripts/versification_snapshot.py --check` (it must still match: stored rows don't change).

### Tests

**Fixture tests (CI).** Add to `tests/fixtures/seed.sql` a small test edition (`TEST3`, with `verseequiv`) whose structure mirrors the two NABRE cases with placeholder text: a chapter ending `…, 26, [12:1]` followed by a chapter starting at 2, and a chapter ending `…, 35, [10:1a]` followed by a chapter whose verse 1 continues it; plus its `_idx` row computed by verse number. HTTP tests in `tests/Server/QuoteHandlerHttpTest.php` (or a new `PrintedLabelHttpTest.php`) cover every row of the table above, asserting chapter, verse, verseequiv and order of the returned rows, plus `/metadata/versionindex` bounds. Search tests: a keyword hit on each labelled-elsewhere row reports its cited chapter/verse and `verseequiv`; `/search/similar` from the cited reference (`Wis 12:1` → found; `Job 10:1` → found, both rows excluded from results). The fixture rows need embeddings for the similar test; the existing search tests show how fixtures get vectors. Unit tests in `tests/Unit` cover the SQL compiler's output for single verse, same-chapter range, cross-chapter range and whole chapter.

**Live suite (against staging).** New `tests/Live/` directory and PHPUnit testsuite `Live`, excluded from `composer test` / `test:quick`; `composer test:live` runs it. Every test is skipped unless `BIBLEGET_LIVE_URL` is set (e.g. the staging base URL). It runs the NABRE queries of the table above, plus `/search/similar` for Wis 12:1 and Job 10:1 and a keyword search that hits the Job 10:1a row, and asserts structure (chapter, verse, verseequiv, row count, order, no errors) and, where text matters, `md5` of the returned text — never the text itself. The staging deploy workflow (`deploy.yaml`) gets a separate job, after the deploy job and only for the staging target, that installs dev dependencies on the runner and runs `composer test:live` against the staging URL. A failure turns the run red (the code is already live; the red run is the signal). Migrations are applied by hand, so a PR that needs a migration applies it to staging before its code is deployed; the PR checklist says so.

The API rate-limits identical queries from one IP (more than 10 in two days produces errors). The suite sends each query once per run from a fresh GitHub runner, so it stays under the limit without a whitelist entry; running it locally more than ten times in two days will trip it, and the suite reports that as a skip with the reason rather than a failure.

### Docs

CHANGELOG entry; wiki: Versification Differences (query behaviour for the two cases), Design Verse Rendering Modes (record that queries now select by number; backward-bridge rows report their cited chapter/verse in the data view), Known Data Issues (`Wis11:26` fixed). `openapi.json`: note on `verseequiv` that it can hold a `chapter:verse` printed label.

## Where this fits

1. **This step:** references by printed number, index fix, tests.
2. Placement columns (#184): printed chapter, `verse_label` (empty = printed unnumbered), `sort_order`.
3. Alignment of every edition to the Nova Vulgata as anchor, including alignment-only partial-verse segments.
4. Curator notes, separate from the edition's own notes (as in crmedr): generated "moved" notes come from the alignment; hand-written notes explain.
5. Print mode and the website reader (whole chapters, generated by a script from the database), showing a note only where editions on screen place a verse differently.

## Left to the builder

- Exact helper/method names and file split for the compiler change.
- Whether the fixture tests extend `QuoteHandlerHttpTest` or live in a new file.
