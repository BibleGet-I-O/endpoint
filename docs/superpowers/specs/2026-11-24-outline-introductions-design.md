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

## Introductions

- **`book_introduction`** (migration 071): `edition, id, ord, title, books INT[], general, blocks JSONB`, key
  `(edition, id)`. One row per introduction as printed: a book's own (`id` its universal number, `general` false), or a
  general one (`general` true, `id` a slug). `books` lists what it introduces: NABRE prints one introduction for
  1-2 Samuel ("THE BOOKS OF SAMUEL") and one for 1-2 Kings, so those rows have two books.
- **`blocks`**, in order: `{type: "heading", level, text, refs}`, `{type: "paragraph", text, refs}`,
  `{type: "item", level, text, refs}` (numbered outlines: level 1 `I.`, 2 `A.`) and `{type: "row", cells: [{text, refs}]}`.
  `text` keeps `<i>/<b>/<sm>`; `refs` are citations shaped as the notes' (`{start, end, span}`, span in code points
  into `text`, tags included). No notes: neither edition prints any in a book's introduction (the NABRE import's
  "206 introduction notes" were misread verse notes).
- **General introductions** (decided by the user 2026-11-26: linked at the end of each book's introduction they cover).
  NABRE's, as printed, with the books they introduce (universal numbers): The Pentateuch 1-5, The Historical Books 6-21,
  Biblical Novellas 17-19, The Wisdom Books 22-28, The Prophetic Books 29-46, Preface to the New American Bible (its
  New Testament) 47-73, The Gospels 47-50, New Testament Letters 52-72, The Catholic Letters 66-72.
- **API** (`reader` key only, else 403; JSON only, else 400; unknown version or book 400):
  - `GET /metadata/introduction?version=NABRE&book=10` →
    `{version, book, introduction: {id, title, books, blocks} | null, general: [{id, title, books}]}`, the general ones
    covering the book in print order;
  - `GET /metadata/introduction?version=NABRE&id=pentateuch` → `{version, introduction: {id, title, books, blocks}}`; an
    unknown id 404.
  - `/metadata/outline` with the `reader` key adds `introduction: {id, title} | null`, so the chapter page knows whether
    to show the book icon and the index's first entry without loading the text.
- **Website:** page `/read/{edition}/{book}/introduction` (signed-in readers only), its citations linked as the notes'
  are; the general introductions are listed at its end, each opening `/read/{edition}/{book}/introduction?part={id}`.
  The book icon beside the tree icon, and "Introduction" first in the title index, when the book has one.
- **Misprints** (decided by the user 2026-11-26, as the Martyrology project records them): the text stays as printed; a
  verified misprint is recorded in the private archive (`NABRE-source/misprints.json`: introduction, printed, intended,
  cite, verified) and its block carries `sic: [{span, intended, ref}]`. The website shows "[*sic!* expected: …]" after
  the printed words, in rubric red, the intended passage linked. A link whose id is wrong while its text is right is
  corrected instead (`introductions-citations.tsv`), as the notes' are; a cited verse the edition does not print is
  left unlinked there ("-").
- **Data:** `scripts/introductions.py nabre` writes a private data half (`NABRE-source/migrations/071-…`). A book's
  introduction runs from its title (`<bk>`, `<bks>`) to the first `<bksect>`, `<ch>`, `<chsect>`, `<cs>`, verse, or a
  second `<bk>` (where a joint introduction gives way to its first book). A citation that does not resolve to a stored
  verse is kept as plain text and reported. CEI 2008's 73 follow in the next slice.

## Anti-goals

Changing the stored text or `title1`-`title3`; showing commentary titles or introductions to visitors.

## Order

NABRE index, CEI index (done, #272), NABRE introductions, CEI introductions.
