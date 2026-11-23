# Notes storage and the NABRE import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tables for notes, their anchors and their citations (migration 064), and a script that turns NABRE's SCML into a private data half plus a placement report.

**Architecture:** A public Doctrine migration creates three empty tables. A public Python script (no text in it) reads the private SCML and a `\copy` dump of `"NABRE"`, places each marker in the stored row (code points into `text` or a `title*`), resolves each `<xref>` to a stored verse, and writes (a) the data half SQL, idempotent (`DELETE … WHERE edition = 'NABRE'` then `INSERT`s), to a path the user gives (the private `NABRE-source/migrations/`), and (b) a TSV report of every marker not placed exactly and every citation not resolved. CEI 2008's import is a later plan (same tables).

**Tech Stack:** PostgreSQL 18, Doctrine Migrations (`SqlFileMigration`), PHPUnit integration tests, Python 3 (stdlib only), `unittest`.

**Spec:** `docs/superpowers/specs/2026-11-23-footnotes-design.md` (Part 2).

## Global Constraints

- No copyrighted text in this repository: tests use synthetic SCML; the script's output goes outside the repo.
- Positions are 0-based **code points** into the stored field, formatting tags included, on a token boundary (never inside a word or a tag), like `title_position`.
- Anchors key verses by identity: `book, chapter, verse, verse_segment, text_tradition, printed_chapter` (migration 046's `<T>_identity`), never `sort_order`.
- NAB book ids skip 35: NAB 1–34 → books 1–34, NAB 36–74 → 35–73. NAB Esther additions are chapters 41–46 (resolve through the stored `verse_label`, as `nabre_headings_from_scml.py` does).
- An unplaced marker is stored with `position` NULL (whole verse) and listed in the report; an overrides file (`--overrides`, TSV `source_id<TAB>field<TAB>position`) can place it by hand. Nothing is dropped silently.
- Allowed tags in note bodies: `<i>`, `<b>`, `<sm>`; others are removed and reported.
- Run Python checks with `python3 -m unittest scripts/test_notes_nabre.py` and keep `composer lint:python` (xenon, complexity ≤ C) green.

## Review Focus

- A marker right after a speaker tag or at a verse's very start (`position` 0 vs. after `<speaker>…</speaker>`): must sit after the tag, never inside it. (Test `markerAfterSpeakerSitsAfterTheTag`.)
- Run-in headings (`<p><b>The Covenant with Abram.</b>`) and `<chsect>` carrying a marker: anchored to the heading's `title*` field of the next verse, not to the previous verse's text. (Test `markerOnARunInHeadingAnchorsToItsTitle`.)
- A footnote printed at two or three places (20 Psalm notes): one `note`, several `note_anchor` rows. (Test `oneFootnoteSeveralAnchors`.)
- Re-running the data half must not duplicate rows. (Integration test `dataHalfIsIdempotent`.)
- Characters the 2015 conversion changed (curly quotes, dashes, `&#8217;`): normalised the same way on both sides before matching. (Test `quotesAndDashesMatchAcrossConversions`.)

---

### Task 1: Migration 064, the notes tables

**Files:**
- Create: `migrations/064-notes.sql`, `src/Migrations/Version064Notes.php`, `tests/Integration/Database/NotesSchemaTest.php`
- Modify: `migrations/production-schema.sql` (append the three tables), `docs/CHANGELOG.md`

**Schema (exact):**
- `note(id serial PK, edition varchar(10) NOT NULL, kind varchar(8) NOT NULL CHECK (kind IN ('footnote','crossref')), marker varchar(8), label varchar(40), title text, body text NOT NULL, source_id varchar(40) NOT NULL, UNIQUE (edition, source_id))`
- `note_anchor(id serial PK, note_id int NOT NULL REFERENCES note ON DELETE CASCADE, book smallint NOT NULL, chapter smallint NOT NULL, verse smallint NOT NULL, verse_segment varchar(4), text_tradition varchar(8), printed_chapter smallint, field varchar(8) NOT NULL CHECK (field IN ('text','title1','title2','title3','chapter')), position int CHECK (position >= 0), last_book smallint, last_chapter smallint, last_verse smallint)` + index on `(book, chapter, verse)`.
- `note_ref(id serial PK, note_id int NOT NULL REFERENCES note ON DELETE CASCADE, ord smallint NOT NULL, book smallint NOT NULL, chapter smallint NOT NULL, verse smallint, verse_segment varchar(4), printed_chapter smallint, end_book smallint, end_chapter smallint, end_verse smallint, span_start int NOT NULL, span_end int NOT NULL, UNIQUE (note_id, ord))`.
- Column types of the identity parts follow the edition tables' (check `production-schema.sql`; adjust and ledger a ruling if they differ).

- [ ] **Step 1: Failing test** `NotesSchemaTest`: after running the file twice (idempotent: `CREATE TABLE IF NOT EXISTS`), inserting a note, two anchors and a ref works; deleting the note cascades; `kind = 'other'`, `field = 'body'` and a duplicate `(edition, source_id)` are rejected.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Database/NotesSchemaTest.php` — Expected: FAIL (file missing).
- [ ] **Step 3: Write** the SQL (header comment in the repository's style), the Version class, the schema dump lines, the CHANGELOG entry.
- [ ] **Step 4: Run** the test, then `composer lint && composer analyse` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(notes): migration 064, tables for notes, their anchors and citations`.

### Task 2: `scripts/notes_nabre.py`, notes and placement

**Files:**
- Create: `scripts/notes_nabre.py`, `scripts/test_notes_nabre.py`

**Interfaces:**
- CLI: `notes_nabre.py --scml DIR --dump nabre.copy [--overrides FILE] --out DATA.sql --report REPORT.tsv`. The dump is `\copy (SELECT sort_order, book, chapter, verse, coalesce(verse_segment,''), coalesce(text_tradition,''), coalesce(printed_chapter::text,''), coalesce(verse_label,''), text, coalesce(title1,''), coalesce(title2,''), coalesce(title3,'') FROM "NABRE" ORDER BY sort_order)` (documented in the docstring).
- `parse_book(scml: str) -> list[Marker]` where `Marker = (source_id, kind, marker, verse_id, field_hint, prefix)`; `parse_notes(scml: str) -> dict[source_id, NoteBody]` with `NoteBody = (label, body, xrefs: list[(target_id, span_start, span_end)])`.
- `place(marker, rows) -> Anchor` where `Anchor = (identity, field, position | None, reason)`; `reason` empty when exact.
- `to_sql(notes, anchors, refs) -> str` (data half) and `report(...) -> str` (TSV: `source_id, kind, verse, reason, context`).

Placement rule: the visible text that precedes the marker within its verse (or heading), normalised (HTML entities, NFC, curly quotes → straight, en/em dashes → `-`, whitespace removed), is matched against the start of the stored field's visible text normalised the same way; the position is the code-point offset in the **tagged** field just after the last matched visible character, moved forward past any closing tags so it never falls inside a tag. Empty prefix → position 0 but after a leading `<speaker>…</speaker>` and line-opening tags. No match → `position` NULL, reason `no-match`.

- [ ] **Step 1: Failing tests** (synthetic SCML only), at least: `placesAMarkerAfterItsWord` (position equals the offset after "desert," in `"<pof>Who is this coming up from the desert,</pof>"`), `markerAfterSpeakerSitsAfterTheTag`, `markerOnARunInHeadingAnchorsToItsTitle`, `oneFootnoteSeveralAnchors`, `quotesAndDashesMatchAcrossConversions`, `unmatchedMarkerIsWholeVerseAndReported`, `overrideSetsThePosition`, `xrefResolvesToTheStoredVerse` (incl. NAB book 36 → 35 and an Esther addition through `verse_label`), `bodyKeepsOnlyAllowedTags`, `dataHalfDeletesThenInserts`.
- [ ] **Step 2: Run** `python3 -m unittest scripts/test_notes_nabre.py` — Expected: FAIL (module missing).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the tests and `composer lint:python` — Expected: PASS, no block above C.
- [ ] **Step 5: Commit** — `feat(notes): NABRE notes from SCML, placed in the stored text, with a report`.

### Task 3: Real run, report review, data half

**Files:** none in this repository (outputs go to the private `NABRE-source`).

- [ ] **Step 1:** Dump NABRE from staging (command in the script's docstring) into the scratchpad; run the script on `NABRE-source/scml`.
- [ ] **Step 2:** Read the report; fix systematic misses in the script (with a synthetic test for each), re-run; what remains individual goes in `NABRE-source/notes-overrides.tsv` or stays whole-verse. Ledger the final counts (placed exactly / whole-verse / unresolved citations).
- [ ] **Step 3:** Write `NABRE-source/migrations/064-nabre-notes.sql` and the report; commit them **in the private repository** (staged by name).
- [ ] **Step 4:** Integration check on a local database: migration 064, then the data half twice (`dataHalfIsIdempotent`: same counts).
- [ ] **Step 5 (needs the user's go-ahead):** apply 064 and the data half to staging.
