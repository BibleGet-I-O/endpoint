# Footnotes and cross-references: storage, import, API and reader

**Date:** 2026-11-23 · **Status:** draft for approval (builder check done 2026-11-23) · **Size:** a project in four parts (endpoint and website)

Supersedes the open points of the wiki's *Design: Footnotes* (RFC, never built), whose two-table model it adopts.

## Intent

The printed editions carry an apparatus the database has never stored: NABRE's footnotes and cross-references,
CEI 2008's commentary notes. Readers of the website (and API clients) should get them, placed as the printed page
places them, without disturbing the text, search, or any client that does not ask for them. A small companion
change explains the Song of Songs' speaker tags ("D?"), which readers find puzzling.

## Goals

1. **Speaker tags explained**: tapping or hovering a tag (`W`, `M`, `D`, `D?`; VGCL's `Sponsa`, `Sponsus`,
   `Chorus`…) shows who speaks, and that a `?` means the attribution is uncertain.
2. **Notes stored losslessly** for NABRE (8,257 footnotes, 13,517 cross-reference notes) and CEI 2008
   (5,708 notes), each with its anchor and, for the passages it cites, structured references.
3. **The API returns notes only when asked**; every current response stays byte-for-byte the same.
4. **The reader shows them**: footnotes at the bottom of the page on desktop, in a notes panel on mobile;
   cross-references in a popup of links.

## Non-goals

- Notes for BLPD, NVBSE, LUZZI, VGCL and DRB: their sources have none (vatican.va's BLPD and NVBSE pages carry
  none; NVBSE's Daniel editorial notes stay dropped on purpose, migration 027). The model leaves room for
  Challoner's DRB notes should a source turn up.
- Book introductions; translating notes; searching inside notes (later, if wanted).

## Anti-goals

- Changing the stored verse text, `text_plain`, embeddings or the search vocabulary.
- Notes reachable by anyone but the BibleGet website's signed-in readers: see *Who gets notes* (Part 3).
- Copyrighted source material (NABRE SCML, CEI pages, note bodies) in a public repository.

## Constraints

- NABRE text, introductions and notes © CCD/USCCB; the source repository `NABRE-source` is private. CEI 2008
  text and commentary © Fondazione di Religione Santi Francesco d'Assisi e Caterina da Siena; the source pages
  are in the private `CEI2008-bibbiaedu`. Their data goes in **private data halves** applied before the public
  migration that checks them, as for migrations 039 and 055.
- Positions inside a verse are counted like `title_position`: 0-based Unicode code points into the stored `text`,
  formatting tags included, always on a token boundary (never inside a word or a tag).
- Rows are matched by verse identity (`<T>_identity`, migration 046), never by `sort_order`, which is not
  stable.
- Visitors get at most 30 verses of a copyrighted edition per request; signed-in users with the `reader` key
  get whole chapters. The API decides, never the site.

## Approaches considered

- **A. Notes in their own tables; markers recorded as code-point positions in the verse text (chosen).** Text
  and every current client untouched; notes joined only on request; the same offsets as `title_position`.
  Cost: each marker's position in our stored text must be computed and audited (the 2015 NABRE conversion
  does not match the SCML word for word), as was done for the headings (migrations 028–029).
- **B. Markers as a new inline tag in `text`, bodies in a table.** Trivial placement, but rewrites about 21,000
  NABRE verses, changes the allowed-markup check, and makes every read strip the tag for clients that did not
  ask.
- **C. Like A, with positions counted in words of `text_plain`.** Sturdier against markup changes, but departs
  from the API's existing character offsets and pushes a conversion onto every client.

## Decided

### Part 1: speaker tags explained (website only)

- A speaker tag becomes a button-like chip; tap or hover shows a small popover: "`D?`: probably the Daughters
  of Jerusalem (the attribution is uncertain)", "`W`: the woman", "`M`: the man".
- The meanings are per edition and per tag, in the site's six languages (a small table in the website). NABRE:
  W = the woman, M = the man, D = the Daughters of Jerusalem; a trailing `?` adds "probably … (uncertain)".
  VGCL: Sponsa = the bride, Sponsus = the bridegroom, Chorus = the chorus, Chorus Fratrum = the chorus of the
  brothers, Chorus Adolescentularum = the chorus of the maidens. An unknown tag shows no popover.

### Part 2: storage and import (endpoint)

Tables (names and columns final in the migration; the wiki's model with these choices):

- **`note`**: one row per note. `id`, `edition`, `kind` (`footnote` | `crossref`), `marker` as printed (`*`,
  `a`, `aa`; NULL for CEI, which prints none in the line), `label` (the printed lemma or range, e.g. `3:6`,
  `8,1-4`), `title` (CEI's bold title, e.g. "Desiderio dell'unione"; NULL otherwise), `body` (the note's text
  with the existing inline tags: `<i>`, `<b>`, `<sm>`), and a stable key `source_id` (NABRE's `26003006-1`,
  CEI's `51014`), unique per edition.
- **`note_anchor`**: where its marker goes. `note_id`, the verse **identity**, `field` (`text` | `title1` |
  `title2` | `title3` | `chapter`), `position` (code points; NULL = the verse as a whole, shown on the verse
  number), and for a note covering a range, the last verse's identity. A note may have several anchors (20 NABRE
  Psalm footnotes are printed at two or three places).
- **Chapter headings** *(builder check, my call)*: NABRE prints notes on chapter headings, above all each psalm's
  introductory note on "PSALM n" (148 markers; 174 on `<ch>` in all). They anchor with `field = chapter` to the
  chapter's first verse, `position` NULL, and the reader shows the marker after the chapter's title. Notes on
  book divisions (`<bksect>`, `<bksubsect>`, about 90) anchor to the heading where the headings import put it
  (`title1..3` of the following verse); where that heading was not imported, they anchor as `chapter`.
- **`note_ref` targets** *(builder check, my call)*: the importer resolves each citation to the edition's own
  stored verse (its identity), so Esther's additions (NAB chapters 41–46), lettered verses (`32004009b`), Sirach's
  printed order and printed placements (Wis 12:1 in chapter 11) are settled once, at import. Besides the target
  identities, each ref keeps the citation as printed (the span in `body`). The API returns the target as the
  stored row's book, chapter, verse, segment and printed chapter, so a client builds the link without
  renumbering; citations that resolve to no stored verse are reported and stay plain text.
- **`note_ref`**: each passage a note cites, structured: `note_id`, order, start (book number, chapter, verse)
  and end (the same, or NULL for a single verse), and the span in `body` that prints it (code points), so a
  client can turn that span into a link.

Import, one edition at a time, each a script in the endpoint (public, it contains no text) that reads the private
source and writes the private data half:

- **NABRE** from `NABRE-source/scml/`: `<fnref>`/`<fn>` are footnotes, `<enref>`/`<en>` cross-references. A
  marker's position is found by locating, in our stored row, the text that precedes it in the SCML line
  (after the same normalisation the headings importer uses), on a token boundary. `<xref id="BBCCCVVV">` gives
  the structured references directly (NAB book ids skip 35: NAB 1–34 are books 1–34, 36–74 are 35–73). Markers
  on headings anchor to `title1..3`. Every marker that cannot be placed is reported, never guessed
  *(builder check, my call)*: an unplaced marker is stored as a whole-verse note (`position` NULL, shown on the
  verse number, so no note is lost), and an overrides file in the private source repository can give its exact
  position by hand. The import is accepted when the report has been reviewed; it need not reach zero.
- **CEI 2008** from `CEI2008-bibbiaedu/html/`: each `footer_notes` entry is a note (`kind = footnote`,
  `position` NULL), anchored to the first verse of its `note-verse-identificative` range, with the range's last
  verse recorded. The `<a href="/CEI2008/…/?sel=…">` links inside give its structured references.
- Bodies keep only the allowed inline tags; anything else is reported.

### Part 3: the API (endpoint)

- `/v3/quote` (and the reader's chapter route) take `footnotes=none|markers|full`, default `none`: responses
  without the parameter do not change.
- `markers`: each verse row gains `notes: [{id, kind, marker, field, position}]` (ordered by field, position,
  marker). `full`: the response also carries the bodies, once each: `notes: {id: {kind, marker, label, title,
  body, refs: [{start, end, span}]}}`.
- **Who gets notes** *(decided by the user, 2026-11-23)*: only requests made with a **`reader`-scope key**, that
  is the website's key for signed-in users. Project keys, installation tokens (the WordPress plugin, the
  MediaWiki extension, the Google Docs and Word add-ins) and the website's `read` key for visitors do not: with
  them, `footnotes` other than `none` answers **403** with a problem saying notes need a reader key.
- Among reader requests, notes follow the verses: only notes **anchored** to verses the response contains
  *(builder check, my call)*. A note is shown where it begins, as the printed page shows it: CEI's
  Gen 6:1–9:29 note comes with chapter 6, not with chapters 7–9 (83 CEI notes run across chapters).
- **JSON only, for now** *(builder check, my call)*: with `return=xml` or `return=html`, `footnotes` other than
  `none` answers 400 with a problem saying so; the WordPress and MediaWiki clients, which insert the HTML as it
  comes, are unaffected. Notes in those formats are a later change.
- `openapi.json` documents the parameter and shapes; the wiki's *Design: Footnotes* is updated to point here.

### Part 4: the reader (website)

Markers, distinguishable from the text:

- **NABRE**: the printed `*` and letters stay where printed, as small chips with a border and a tinted
  background; **footnotes and cross-references in two different tints**.
- **CEI 2008**: no marker in the line; the **verse number** gets the same treatment (as the CEI app does: a pale
  disc behind it).
- Speaker chips (Part 1) look different again (they are not notes).

Footnotes:

- **Desktop**: listed at the bottom of the chapter, under a heading, each with its marker or verse label and
  (CEI) its title. A marker links down to its note; the note's own marker links back up to the marker in the
  text (anchors, so browser Back works too).
- **Mobile**: a **notes panel** fixed at the bottom of the screen, about a quarter of the screen tall, open by
  default in chapters that have notes (hidden where there are none); a handle drags it down to a thin bar
  ("12 notes") or up to half the screen, and the choice is remembered. Tapping a marker scrolls **the panel**
  to the note and highlights it; the page does not scroll.
- References inside a note's body are links to the passage in the same edition (the search results' link
  builder, which already handles printed placements such as Wis 12:1 in chapter 11).

Cross-references:

- Tapping a letter opens a small **popup** listing the passages as links; a link navigates to that book,
  chapter and verse (`#v{chapter}.{verse}`), keeping the reader's comparison settings as `go()` does.

Who sees notes, and comparison:

- Only **signed-in** readers see notes: the site asks for `footnotes=full` only with the `reader` key. Visitors
  see the text as today, with no markers, no panel and no popups; where an edition has notes, a one-line hint
  under the chapter says that signed-in readers see the edition's notes *(my call: otherwise visitors would not
  know the notes exist)*.
- In a comparison, each edition's notes are shown with that edition's text; the panel and the bottom list group
  them by edition.

## Left to the builder

- Table and column names, indexes, the import scripts' structure, the report format.
- The normalisation used to place NABRE markers, within "never guess; report".
- Exact tints (within the site's slate/red palette, light and dark), chip and popup styling, the panel's drag
  behaviour and its remembered state.
- Whether `/v3/quote` and the reader's chapter route share one implementation of the note join.
- Test layout in both repositories.

## Order of work

1. Part 1 (website), shippable on its own.
2. Part 2 for NABRE (the harder placement), then CEI 2008.
3. Part 3.
4. Part 4, desktop then mobile.

Each part gets its own plan and PR(s); Parts 2–4 may be planned together once Part 2's placement report is in.
