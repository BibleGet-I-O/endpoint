# Title index and book introductions: design

**Date:** 2026-11-24 · **Status:** approved by the user 2026-11-24 (in conversation) · **Size:** a project in slices

## Intent

From the line that names the book and chapter, a reader opens (a) the book's titles as a hierarchy, each a link to its
verse, and (b) the book's introduction, with its notes and linked citations.

## Decided by the user

- A **book icon** on the book/chapter title line opens the introduction; a **tree icon** beside it opens the index.
- The index is per book and per edition; its **first entry is the introduction**, then the titles, indented.
- Introductions live in a table of their own (they have no chapter or verse).
- CEI books without printed headings use the titles of CEI's notes, **for signed-in readers only**.
- CEI's introductions may be archived (done: private `CEI2008-bibbiaedu/introductions/`).

## Title index

- **`book_outline`** (migration 069): `edition, book, ord, level, title`, the verse identity
  (`chapter, verse, verse_segment, text_tradition, printed_chapter`) and `reader_only`. Unique `(edition, book, ord)`.
  Titles repeat the printed headings because `title1`-`title3` hold no level (a `title1` may be a division or a section).
- **Levels** (1 = highest), as the source marks them:
  - NABRE: `bksect` 1, `bksubsect` 2, `chsect`/`cs`/`pc`/run-in 3, `chsubsect` 4.
  - CEI 2008: printed headings `s` 1, `s2` 2; commentary titles in capitals 1, others 2.
  Clients indent by the distinct levels a book has, not by the number.
- **Left out:** stanza numerals and acrostic letters (a title of a Roman numeral, one letter, or a Hebrew letter's name).
- **Data:** NABRE headings are public already (029) and CEI's printed ones too (023), but the rows go in private data
  halves for both, as the notes do, so one mechanism serves all.
- **API:** `GET /metadata/outline?version=NABRE&book=1` (universal number), JSON only:
  `{version, book, titles: [{level, title, chapter, verse, segment, printed_chapter}]}`. Rows with `reader_only` are given
  only to the `reader` key; others get the rest, without an error. Unknown version or book: 400.
- **Website:** the tree icon shows when the book has titles (or an introduction); the panel is a dialog like the go-to
  panel; a title links to `/{printed_chapter ?? chapter}#v{chapter}.{verse}{segment}`, keeping the comparison. The title
  nearest above the chapter on screen is marked. In a comparison, the icons are the main edition's.

## Introductions (next slice)

- **`book_introduction`**: `edition, book, title`, and `blocks` (JSON): headings, paragraphs, list items and table rows in
  order, each with its text (`<i>/<b>/<sm>`) and its citations (span and verse). No notes: neither edition prints any in
  a book's introduction (the NABRE import's "206 introduction notes" were misread verse notes).
- `GET /metadata/introduction?version=&book=`: `reader` key only (403), JSON only.
- Page `/read/{edition}/{book}/introduction`, signed-in readers only, its citations linked as the notes' are.

## Anti-goals

Changing the stored text or `title1`-`title3`; showing commentary titles or introductions to visitors.

## Order

NABRE index, CEI index, NABRE introductions, CEI introductions.
