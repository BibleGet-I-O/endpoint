# Verse-identity columns: names that say what they hold (#184)

## Why

The verse-identity columns were named in 2020 (#6), and their names don't say what they hold. Worse, each holds two or three different kinds of value. A staging survey (2026-11-16) of the eight editions in `versions_available` found:

| Column | Holds | Where |
|---|---|---|
| `verseequiv` | the printed letter with the number (`1a`, `17aa`) | all editions with Greek Esther, NABRE/CEI2008/BLPD/NVBSE splits |
| | a cross-number in another numbering: `(9,22)`, `(113,20>)` | CEI2008 Psalms (52 rows) |
| | a bare number: the Hebrew numbering of Dan 3:91–100 (`24`…`33`); NABRE Sir 41:15 (`15`) | NVBSE, BLPD (10 each); NABRE (1) |
| | `''` | CEI2008 (about 35,000 rows: import leftover) |
| `versedescr` | the Vulgate position of a Greek Esther row (`11:2`) | DRB, VGCL |
| | NABRE's printed Esther labels (`A:1` … `F:…`) | NABRE |
| | acrostic letter names (`Alef`, `[Alef] `) | CEI2008 (~300), BLPD (Ps 119, 22) |
| | duplicates of step 2's `verse_label` (`12:1`, `10:1a`) | NABRE Wis 12:1, Job 10:1 |
| | `[16]` | CEI2008 Mk 7:16 (omitted since 045) |
| | `''` | CEI2008 (import leftover) |
| `verseorigin` | HEBREW / GREEK (Esther's two traditions) | all but LUZZI and DIVCOM, which lack the column |
| `verseID` | reading order (since 027); internal: stripped from responses, which carry `canonical_order` | all |

No client reads `/dev` (all use `/v3`, or the root that returns 410), and a v4 release deploys to `/v4`. So output fields can be renamed outright; no aliases.

## Goals

1. Each column holds one kind of value, named after it, mapping onto USFM/USX and OSIS as #184 proposes.
2. Values move by their shape, each move counted and checked (as 045 does), so the migration refuses on anything unexpected.
3. Every edition records acrostic letters the same way.
4. Book metadata gains `usfm_code` and `osis_code`.
5. A unique key on verse identity.

## Non-goals

- Output transformers (USX, USFM, OSIS): #184's follow-up.
- Queries for a lettered verse (`Est 1:1a`): not supported today, not added here.
- The frozen v3 API (MariaDB): unchanged.
- Leftover tables outside `versions_available` (`NABRE_old`).
- One-shot historical scripts (`scripts/format_*`, `scripts/repair_*`, `nabre_headings_from_scml.py`): they belong to the migrations they produced and stay as written. Live scripts (`compute_embeddings.py`, `versification_snapshot.py`, `sync_labse_to_live.sh`) are updated.

## Anti-goals

- Changing any verse text, heading or reading order (the two Esther corrections below change a verse number and a label only). The digest of (book, chapter, verse, text, titles, order) per edition must be identical before and after.
- Losing a value: every non-empty old value lands in a new column or is listed as dropped (the two duplicate and leftover cases above), by count.

## Approaches

1. **Rename only** (`verseequiv` → `verse_segment`, …) and leave the contents. Cheapest, but the names would describe only some of the values (`verse_segment = '(9,22)'`). Rejected: it is the problem #184 exists to fix.
2. **Rename and sort the values into columns by shape** (chosen). One migration; the new columns hold one kind of value each.
3. **Sort the values, keep the old names**: fixes the data but not the naming; rejected by #184's own decision list.

## Design

### Columns (every edition table)

| New column | Type | Holds | From |
|---|---|---|---|
| `verse_segment` | `VARCHAR(4)`, NULL | the printed letter(s) only: `a`, `aa` | `verseequiv` matching `^[0-9]+[a-z]+$`, digits stripped |
| `alt_ref` | `VARCHAR(16)`, NULL | the position in another numbering scheme, as stored | `verseequiv` cross-numbers and bare numbers; `versedescr` `N:N` in DRB and VGCL |
| `acrostic` | `VARCHAR(16)`, NULL | the Hebrew letter the verse falls under, spelled as the edition spells it | see Acrostics |
| `text_tradition` | `VARCHAR(10)`, NULL, CHECK IN ('GREEK','HEBREW') | as `verseorigin` | `verseorigin`; added (all NULL) to LUZZI and DIVCOM |
| `sort_order` | `INTEGER` (stays the primary key) | reading order | `verseID`, renamed |
| `verse_label` | (from 044) | NULL = the plain number is printed; `''` = no number printed (NABRE Job 10:1, rest); otherwise the printed label | gains NABRE's Esther `A:1`… from `versedescr` |

- `''` keeps its meaning only in `verse_label`. `verse_segment`, `alt_ref` and `acrostic` get `CHECK (col <> '')`.
- `verseequiv` and `versedescr` are dropped once every value is placed.
- NABRE's Esther `A:1` labels go into `verse_label` only where it is NULL (no Esther row has one today).

### Acrostics

The letter is data (`acrostic`); the heading is how the edition prints it, with its position (`title_position`), and stays unchanged.

- CEI2008, BLPD: from `versedescr` (BLPD's brackets and trailing space stripped).
- LUZZI (Ps 119), NABRE (Ps 37, Ps 119), VGCL (Ps 118, Lam 1–4): from the heading slot (`title1`–`title3`) whose whole value is a letter name in that edition's spelling. The migration lists the spellings per edition (e.g. LUZZI `ALEF`…`RESCH`, `SCHIN`, `TSADE`; NABRE `Aleph`…`Lamedh`, `Sadhe`; VGCL `Aleph`…`Phe`, `Coph`, `Thau`) and checks the count per chapter (22; 66 for Lam 3; VGCL Lam 1 has 21, since 1:1 carries "Prologus" and no letter).
- CEI2008 and BLPD also print the letters as headings. Where a row has both, they must agree (the migration checks); where only the heading has one (e.g. CEI2008 Lam 1:1, whose `versedescr` is empty), the heading supplies it.
- A letter whose heading falls mid-verse (`title_position` set: CEI2008 Ps 10:8 and 37:28, NABRE Ps 37:28) belongs to that row. CEI2008 also marked those two letters in `versedescr` on the next verse (10:9, 37:29); those copies are dropped, as is BLPD's `[He] ` on Ps 119:32 (its stanza and heading begin at 119:33).
- A `versedescr` letter must be in the edition's list; an edition without a list has none. A heading letter that disagrees with the row's `versedescr` letter raises, mid-verse or not.

### Corrections found by the migration's guards

A lettered `verseequiv` must carry the row's own verse number. Seven Greek Esther rows did not, both import slips, corrected first (user decision, 2026-11-17):

- VGCL stored A:13–17 (`1n`–`1r`, Vulgate 12:2–6) at verses 1:2–1:6; every other edition has them at 1:1, and 1:2–1:6 are the Greek text's own verses. Moved to 1:1.
- DRB labelled its 4:8 additions (Vulgate 15:2–3) `13a`/`13b`; VGCL has `8a`/`8b` for the same rows. Relabelled.

### Unique key

`UNIQUE NULLS NOT DISTINCT (book, chapter, verse, verse_segment, text_tradition, printed_chapter)`.

#184 proposed the key without `printed_chapter`, but step 2 stores NABRE Job 10:1 as two rows with the same number: one printed at the end of chapter 9 (`printed_chapter` 9) and the rest in chapter 10. `printed_chapter` tells them apart, and the survey found no other duplicate. The old `<T>_bcv` indexes are replaced by this key's index.

### Book codes

A new table `biblebooks_codes (book INTEGER PRIMARY KEY REFERENCES biblebooks_fullname("BOOK"), usfm_code CHAR(3) NOT NULL UNIQUE, osis_code VARCHAR(8) NOT NULL UNIQUE)`, 73 rows.

- A separate table, not columns on `biblebooks_fullname`: `MetadataHandler` and `QuoteContext` read that table with `SELECT *` and treat every column as a language.
- Esther is `EST` / `Esth`. Rows with `text_tradition = 'GREEK'` correspond to USFM's `ESG` (OSIS `AddEsth`); that mapping is the transformers' job and is documented, not stored.
- `/metadata/biblebooks` returns the codes alongside the names.

### Database objects

- `bibleget_rebuild_in_order()` is recreated with the new names.
- The primary key stays on the renamed `sort_order`; the CHECK on `verseorigin` moves to `text_tradition`.
- Test fixtures (`tests/fixtures/schema.sql`, `seed.sql`), CI and docker-compose use the new schema.

### Code and API

- All PHP, live scripts, tests and `openapi.json` use the new names. Responses carry `verse_segment`, `alt_ref`, `acrostic`, `text_tradition` instead of `verseequiv`, `versedescr`, `verseorigin` (`sort_order`, like `verseID` today, never leaves the API).
- The HTML and XML outputs carry the new fields where they carry the old ones today.

### Migration

`046-verse-identity-columns.sql`, one transaction, idempotent (does nothing once the old columns are gone):

1. Per edition: the content digest (anti-goal above) and the counts of every source shape.
2. Add the columns, move each shape, check each count, raise on any value no rule claims.
3. Acrostics from headings, counted per chapter.
4. Drop `verseequiv`, `versedescr`; rename `verseorigin`, `verseID`; constraints, key, function.
5. `biblebooks_codes`.
6. The digest again: identical, or raise.

Embeddings are unaffected (no text changes).

## Left to the builder

- The exact per-edition letter-name lists, taken from the data.
- Index and constraint names.
- Whether the key replaces the `_bcv` index directly or both live for one migration.
