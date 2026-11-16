# Design: placement columns and omitted verses

**Status:** approach A chosen, omitted-verse rule agreed, 2026-11-16. Step 2 of the five steps in the wiki page Design-Verse-Rendering-Modes (step 1: `2026-11-15-printed-label-queries-design.md`, PR #188). Covers the placement part of #184 and settles #180.

## Intent

The goal of the plan is a website reader that shows whole chapters as each edition prints them, and compares editions side by side (steps 3–5). For that, every verse row needs two kinds of information, kept apart:

- **Identity**: which verse this is (book, chapter, verse, and a letter only where the edition prints one). References select by identity; that rule shipped in step 1 and does not change.
- **Printed presentation**: under which chapter heading the edition prints the row, what label it prints beside it, and where it comes in reading order (`verseID`, unchanged).

Today the two are mixed. A verse NABRE moves *back* is stored at the place it is printed, with its identity hidden in `verseequiv` as a `chapter:verse` string that step 1's `PrintedLabel` parses at query time:

| Row today | Stored at | `verseequiv` | NABRE prints |
|---|---|---|---|
| Wis 12:1 | 11:26 (second row) | `12:1` | at the end of chapter 11, labelled `12:1` |
| "I loathe my life." (Job 10:1, first part) | 9:35 (second row) | `10:1a` | at the end of chapter 9, labelled `10:1` |
| the rest of Job 10:1 | 10:1 | — | opening chapter 10, no number |
| John 7:53 | 7:53 | — | under CHAPTER 8, before 8:1, labelled `7:53` |

A verse moved *forward* (John 7:53) is stored at its identity with no trace of where it is printed. Checked against the SCML source in the private NABRE-source repo; on staging these are the only rows of any edition with a `chapter:verse` label.

Omitted verses (#180) are a second kind of printed presentation: the edition prints the number but no text (the critical text leaves the verse out), and today each edition stores that differently: `]`, `[]`, blank, empty, or no row.

## Goals

1. Every row is stored at its identity. Two new nullable columns record printed presentation where it differs from the default:
   - `printed_chapter`: the chapter under whose heading the edition prints the row, when it is not `chapter`.
   - `verse_label`: the label printed beside the row, when it is not simply the verse number (with its printed letter, where `verseequiv` holds one). `''` = printed without a number.
2. A third column, `omitted BOOLEAN NOT NULL DEFAULT false`, marks a verse the edition prints as a number without text.
3. The NABRE rows move to their identity: Wis 12:1 → chapter 12 verse 1 (`printed_chapter` 11, `verse_label` `12:1`); "I loathe my life." → chapter 10 verse 1 (`printed_chapter` 9, `verse_label` `10:1`); the rest of Job 10:1 gets `verse_label` `''`; John 7:53 stays at 7:53 with `printed_chapter` 8, `verse_label` `7:53`. Their `verseequiv` labels are cleared. Reading order (`verseID`) does not change.
4. `PrintedLabel`'s parsing of `verseequiv` goes away: references match `chapter`/`verse` directly (the rows are now there). Its responsibilities that remain (none in queries; possibly the label shown in responses) read the new columns.
5. API responses (JSON, XML, HTML; `/quote` and every `/search*` endpoint) gain `printed_chapter`, `verse_label` and `omitted` for each row. Existing fields keep their meaning; `verseequiv` keeps holding printed letters and CEI 2008's psalm cross-numbers.
6. Omitted verses, per the rule agreed on 2026-11-16: wherever the edition prints the verse number, a row with `omitted = true` and empty `text`; omitted rows are never embedded and never returned by semantic, similar or hybrid search, nor by keyword search (empty text). A reference that asks only for omitted verses also gets a standard `VERSE_OMITTED` entry in a new `notices` array (see Omitted verses).
7. Versification snapshots show the printed layout from the new columns; the documented cases keep passing (expectations updated where the layout's spelling changes).
8. Tests at every level touched: unit, integration (fixtures), HTTP server, live (staging).

## Non-goals

- #184's renames (`verseequiv` → `verse_segment`, `verseorigin` → `text_tradition`, `versedescr` → `alt_ref`, `verseID` → `sort_order`, `usfm_code`, the unique key, output transformers). Separate PR; the new column names are chosen to fit them.
- Print mode and the website reader (step 5), alignment (step 3), curator notes (step 4).
- The frozen v3 API (MariaDB) gets no schema change and its NABRE rows don't move (moving them would change what production prints). Only the omitted-verse text cleanup is ported, see Data.
- Footnotes holding an omitted verse's text (Design-Footnotes, later).
- NVBSE's "(21)" markers and NABRE's / BLPD's Sirach omissions: the edition prints no verse number as a verse, so no row is added (documented as-printed cases from #185 stay as they are).

## Anti-goals

- Storing a verse's text twice (no duplicate row for John 7:53 in chapter 7 and 8).
- Breaking the three clients: no field removed or renamed; new fields only.
- Copyrighted text in the public repo (fixtures stay synthetic; migrations change NABRE/CEI2008/BLPD rows by key and checksum, not by text, except to empty placeholder text).

## Approaches considered

- **A. Placement columns now, renames later (chosen).** Small, unblocks steps 3–5; renames in their own PR.
- **B. Placement columns plus all of #184's renames at once**, with old names aliased in responses. One schema change instead of two, but several times the work and risk before the reader moves on. Rejected for now.

For John 7:53 (wiki open question 1), the four storage options of the wiki (canonical-only, editorial-only, both rows, companion table) are superseded: identity columns plus `printed_chapter` record both the canonical number and NABRE's placement in one row, the same way for backward and forward moves.

## Design

### Columns (migration `044`)

On every edition table (each row of `versions_available` whose table exists):

```sql
ALTER TABLE "<T>" ADD COLUMN IF NOT EXISTS printed_chapter INT,
                  ADD COLUMN IF NOT EXISTS verse_label VARCHAR(16),
                  ADD COLUMN IF NOT EXISTS omitted BOOLEAN NOT NULL DEFAULT false;
```

- `printed_chapter` NULL means "printed under `chapter`". `verse_label` NULL means "printed as the verse number" (plus the row's printed letter from `verseequiv`, when it has one). Keeping the defaults NULL means only the four NABRE rows carry values.
- No CHECK beyond `omitted` being NOT NULL; `printed_chapter` positive when set.
- `production-schema.sql` and the test fixtures (`tests/fixtures/schema.sql` loop and `seed.sql` tables) get the same columns.

### The NABRE rows

Moved by key and checksum (md5 of `text`), not by text:

| Row (`verseID` unchanged) | chapter:verse | `verseequiv` | `printed_chapter` | `verse_label` |
|---|---|---|---|---|
| Wis 12:1 | 11:26 → **12:1** | `12:1` → NULL | 11 | `12:1` |
| Job "I loathe my life." | 9:35 → **10:1** | `10:1a` → NULL | 9 | `10:1` |
| Job 10:1, rest | 10:1 | — | NULL | `''` |
| John 7:53 | 7:53 | — | 8 | `7:53` |

Job 10:1 is then two rows with the same chapter, verse, letter and origin, told apart by `verseID`. That is how NABRE prints it (one verse number, two places); #184's future unique key will need a segment or the order column to allow it, noted there.

`NABRE_idx` is recomputed from the rows (verses_count, verses_last): chapter 11 ends at 26, chapter 12 has 1–27, chapter 9 ends at 35; John unchanged.

### Queries

- `SqlCompiler` drops the two-branch condition of step 1 and matches `chapter`/`verse` as before #188. `Wis12:1` now matches the row stored at 12:1; `Wis11` returns 1–26 (12:1 is not in chapter 11 by identity); `Job10:1` returns both rows, in `verseID` order; `Job9:35-10:1` returns 9:35, "I loathe my life.", the rest of 10:1 (unchanged results from step 1).
- The search services and `SimilarSearchHandler` likewise match `chapter`/`verse` directly; `PrintedLabel::sqlChapter/sqlVerse/sqlLabelled` and `cited()` are removed, and with them the parsing of `verseequiv`.
- Semantic, similar and hybrid search add `AND NOT omitted` (and similar search refuses an omitted source verse with 404 "omitted in this edition").
- Results stay in `verseID` order.

### Responses

Each row gains three fields, next to `verseequiv`:

- `printed_chapter` (int or null), `verse_label` (string or null), `omitted` (bool).
- XML: attributes/elements in the same place as `verseequiv`; HTML: data attributes on the verse element, and an omitted verse is rendered as its number with an empty text span (no placeholder characters).
- `chapter`/`verse` already report identity since step 1; nothing changes there.
- openapi.json documents the three fields.

### Omitted verses (#180)

Rule (agreed): a row exists, with `omitted = true` and `text = ''`, wherever the edition prints the verse number; text the edition prints in its body (in brackets or not) stays as text, `omitted = false`.

| Edition | Rows set omitted | Notes |
|---|---|---|
| CEI2008 | the rows whose `text_plain` is only `]`, `[]` or empty (15 NT rows) | Acts 8:37 has bracketed text in our table while #180 reports that bibbiaedu shows only `]`: check the private CEI2008-bibbiaedu archive; omitted if the source has no text in the body |
| NABRE | the 16 NT placeholder rows plus 1 Mac 9:34 (`]`) | Mt 12:47 keeps its bracketed text |
| LUZZI | the 16 empty NT rows | |
| BLPD | 1 Kgs 15:6 and 15:32: new rows (verseIDs shift as in 042), and the trailing " 6i" / " 32ii" removed from 15:5 and 15:31 | the bracketed/parenthesised NT verses stay as text; data half in the private BLPD-vatican archive, public half checks md5 |
| DRB, VGCL, NVBSE, DIVCOM | none | DRB/VGCL print the text; NVBSE prints "(n)" inside the previous verse |

The exact row sets are selected by key with a guard on the current text (placeholder pattern or md5), so the migration refuses to run on unexpected data. Embeddings of omitted rows are set to NULL and `compute_embeddings.py` skips `omitted` rows (its loaders add `AND NOT omitted`).

#### A reference that asks only for omitted verses (agreed 2026-11-16, option A)

When **every** row that a requested segment returns for an edition is omitted (`Mk9:44`, `Mk9:44,46`, `Mk9:44-44` in CEI2008), `/quote` returns those rows as usual (`omitted: true`, empty text) **and** adds an entry to a new top-level `notices` array:

```json
"notices": [
  {"code": "VERSE_OMITTED", "version": "CEI2008",
   "book": 48, "chapter": 9, "verse_from": 44, "verse_to": 44, "reference": "Mk 9:44",
   "message": "Mark 9:44 is not in the text of this edition: the critical text omits it; the number is printed without text."}
]
```

- One entry per requested segment and edition; a segment that also returns non-omitted rows (`Mk9:43-48`) gets none: the omitted rows are just part of the range.
- With several editions, only the editions that omit the verse get a notice (`Mk9:44` in VGCL,CEI2008: VGCL's text, CEI2008's omitted row and one notice).
- `notices` is always present (empty array when there are none), in JSON and XML next to `errors`. It is informational: it never changes the status code and is not an error, so the clients' error displays ("There are errors") are not triggered. Existing clients ignore the new field; each can decide later how to show it.
- **HTML (builder check; my call):** WordPress and MediaWiki insert the `return=html` response into readers' pages as is (WordPress strips only `div.errors.bibleQuote`, and its layout code matches classes containing `results` and `errors`). So in HTML the notices are **hidden**, like `buildHtmlInfo`'s `<input type="hidden" class="BibleGetInfo">`: one `<input type="hidden" class="BibleGetNotices" value="…JSON of the notices array…">`, whose class contains neither `results` nor `errors`. Nothing changes on readers' pages; no client change needed.
- The message is in English, like `errMessage`; the structured fields let clients write their own (localization follows the API's general approach later).
- **Fields (builder check; my call):** each notice carries `code`, `version`, `message`, the structured `book` (number), `chapter`, `verse_from`, `verse_to`, and `reference`, a display string. Clients build messages from the structured fields; `reference` is for display only and is not to be parsed. It is fully qualified, built from the parsed segment rather than the raw text: the book as the user wrote it (as resolved by the validator, e.g. `Mc` or `Mk`), the chapter filled in for segments that borrow it, and the user's notation (`Mc 9,44` for European input, `Mk 9:44` for English). `Mk9:44,46` is two segments and gives two notices, `Mk 9:44` and `Mk 9:46`.
- Rejected: an `errors` entry with a new `errNum` (clients would announce an error for a verse the edition simply doesn't print), and a 404 problem response with no row (breaks partial results when other references are requested, and hides that the number is printed).
- Search endpoints never return omitted rows, so they have no notices.

### Snapshots and cases

`versification_snapshot.py` groups rows by `coalesce(printed_chapter, chapter)` and writes the label as `verse_label` when set (`''` written as `·`, a printed position without a number), else as today; an omitted verse keeps its number (it is printed). So NABRE Wis 11 stays `1-26,12:1`, Job 9 becomes `1-35,10:1`, Job 10 `·,2-22`, John 7 `1-52` and John 8 `7:53,1-11`. Cases `nabre-wis-12-1`, `nabre-job-10-1a` and the John case are updated to the new spelling; `cases.json` text gains the step-2 explanation.

### Data: where it runs

- **Staging (PostgreSQL):** migration `044` (columns, NABRE moves, omitted rows for CEI2008/NABRE/LUZZI, NABRE_idx), with the BLPD part as a private data half (BLPD-vatican) plus the public md5 check, as in 039–042. Re-embed nothing new; omitted rows lose their embeddings.
- **Production v3 (MariaDB):** no schema change, no moved rows. Port only the placeholder cleanup: `text = ''` for the CEI2008/NABRE omitted rows (production prints `]` today), and BLPD 1 Kgs 15:5/15:31's " 6i"/" 32ii" removed (no new rows: v3 has no omitted flag). Same method as earlier ports (copy, checksum, RENAME, `_pre_044` backups).

### Tests

- Unit: `SqlCompiler` emits plain chapter/verse conditions again (step 1's two-branch tests replaced); response mappers include the three fields.
- Integration fixtures: `TEST3` rows are rewritten to the step-2 storage (Wis 12:1 at 12:1 with `printed_chapter` 11, Job split rows, John 7:53 with `printed_chapter` 8, an omitted row); the step-1 HTTP tests keep their expectations (same answers), plus checks of the new fields; similar/semantic/hybrid exclude the omitted row; similar from an omitted verse → 404.
- Notices: `Mk9:44` (fixture omitted row) → the row plus one `VERSE_OMITTED` notice with the structured fields; `Mk9:44,46` → two notices, the second with its chapter filled in; European notation → `reference` in European notation; `Mk9:43-45` → the rows, no notice; two versions, one omitting → one notice for that version; `notices` is `[]` otherwise; XML carries it; HTML carries it only in the hidden `BibleGetNotices` input (and adds no visible markup).
- Live (staging): NABRE responses carry `printed_chapter`/`verse_label` for the four rows; CEI2008 Mk 9:43-48 returns 44 and 46 with `omitted: true`, empty text and no notice; CEI2008 Mk 9:44 returns the row and a `VERSE_OMITTED` notice.
- Snapshot cases as above.

## Calls made in the design (to check)

- Column names `printed_chapter`, `verse_label`, `omitted`, chosen to fit #184's naming; defaults NULL/NULL/false so only affected rows carry values.
- Job 10:1 becomes two rows with the same identity, ordered by `verseID` (noted for #184's unique key).
- New response fields added, none renamed; v3 gets only the placeholder cleanup.
- Snapshot spelling: `·` for a printed position without a number.
- HTML notices hidden (see above); notice fields structured plus a display `reference`.
- CEI 2008 Acts 8:37 decided by the bibbiaedu source during implementation.

## Left to the builder

- Exact XML/HTML markup for the three fields, following the existing `verseequiv` conventions.
- How `PrintedLabel` is retired (delete vs. a slimmer helper for the response label), as long as nothing parses `verseequiv` for a `chapter:verse` label anymore.
- Splitting `044` into a public migration and the BLPD private data half, following 042's pattern.
