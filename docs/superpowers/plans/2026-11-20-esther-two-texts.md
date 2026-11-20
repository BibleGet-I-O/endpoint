# Esther's Two Texts Side by Side — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the website reader, Esther in an edition with both texts shows the Septuagint and the Masoretic text side by side when read alone, and a visible *Septuagint | Masoretic* switch when comparing editions.

**Architecture:** The two texts reuse the comparison view's `Spread`: two sides of the same edition, rows paired by `buildRows` (chapter, verse, letter). `Spread` learns rubric headers and a way to leave empty cells empty. A server-rendered `TraditionSwitch` (two links) replaces the ⚙ menu's "Esther text" select.

**Tech Stack:** Next.js 16 (App Router, server components), next-intl, Tailwind, Vitest + Testing Library. Repo: `~/development/BibleGet-I-O/website`, branch `feat/esther-two-texts` from `main`.

**Spec:** `docs/superpowers/specs/2026-11-20-esther-two-texts-design.md` (endpoint repo)

## Global Constraints

- Applies only where `hasBothEstherTexts(book)` (`lib/esther.ts`): CEI 2008, NABRE, BLPD Esther. Every other book and edition renders exactly as today.
- Septuagint on the left, Masoretic on the right.
- Rubric: small red text (`text-red-700 dark:text-red-400`, `text-xs uppercase tracking-wide font-sans`), the full name from `sm:` up, the short one below `sm`.
- Labels (keys in the `Reader` namespace), verbatim:

  | locale | `septuagint` | `masoretic` | `septuagintShort` | `masoreticShort` |
  |---|---|---|---|---|
  | en | Septuagint | Masoretic text | LXX | MT |
  | it | Settanta | Testo masoretico | LXX | TM |
  | es | Septuaginta | Texto masorético | LXX | TM |
  | fr | Septante | Texte massorétique | LXX | TM |
  | de | Septuaginta | Masoretischer Text | LXX | MT |
  | pt | Septuaginta | Texto massorético | LXX | TM |

- `?tradition=HEBREW` keeps selecting the Masoretic text when comparing; Greek stays the default and is never written to the URL.
- Additions sit beside **empty** Masoretic space: no "Not in …" note in the two-texts spread (spec: "beside empty Masoretic space").
- The 30-verse visitor cap applies to each text separately.
- Gates: `npx vitest run`, `npx tsc --noEmit`, `npm run lint` all clean.

## Review Focus

1. A visitor on a copyrighted edition: the Greek chapter capped at 30 while the Hebrew one is shorter and complete — each column stops at its own end, with no gap notes after a cap, and one capped notice. → Task 4, `eachTextStopsAtItsOwnCap`.
2. The Masoretic request fails (API unavailable) while the Septuagint loads — the page shows the Septuagint alone with the `secondUnavailable` note naming the Masoretic text, not an error page. → Task 4, `aFailedMasoreticTextLeavesTheSeptuagintWithANote`.
3. Esther 10, where the Greek text runs on (addition F) past the Hebrew 10:3 — Greek-only rows with an empty right cell. → covered by Task 1's empty-cell test plus Task 4's pairing test.
4. Reading Esther alone in interlinear: chapter navigation and the book/chapter selects keep `view=interlinear`. → Task 3, `twoTextsKeepTheViewInTheQuery`.
5. Leaving Esther for another book drops the tradition and the two columns (existing behaviour; keep its test green).

---

### Task 1: `Spread` rubric headers and quiet gaps

**Files:**
- Modify: `components/Spread.tsx`
- Test: `components/__tests__/Spread.test.tsx`

**Interfaces:**
- Produces: `SpreadSide` gains `rubric?: { full: string; short: string }`; `Spread` gains prop `gapNotes?: boolean` (default `true`).

- [ ] **Step 1: Write the failing tests** in `Spread.test.tsx`:
  - `rubricSidesAreHeadedInRedWithTheShortNameOnPhones`: sides `[{ sigla: "NABRE", name: "NABRE", rubric: { full: "Septuagint", short: "LXX" } }, { sigla: "NABRE-MT", name: "NABRE", rubric: { full: "Masoretic text", short: "MT" } }]`; each `column-name` contains both texts, the full one in an element with class `hidden sm:inline`, the short one in `sm:hidden`; the header element has class `text-red-700`.
  - `withoutGapNotesAnEmptyCellStaysEmpty`: rows where side 1 has no verse, `gapNotes={false}` → `cell-b` has no "Not in" text; with the default, it still has it.
  - `interlinearTagsARubricSideWithItsShortName`: interlinear view → the `sigla` tag of side 1 reads `MT`.
- [ ] **Step 2: Run** `npx vitest run components/__tests__/Spread.test.tsx` — Expected: the 3 new tests FAIL.
- [ ] **Step 3: Implement** in `Spread.tsx`: a side with `rubric` gets the rubric header (Global Constraints styling) instead of the plain name; interlinear tags show `rubric.short` when present, else `sigla`; `cell()` returns `null` instead of `<GapNote>` when `gapNotes === false`. Update the docblock.
- [ ] **Step 4: Run** the same command — Expected: PASS, and the file's existing tests still pass.
- [ ] **Step 5: Commit** `feat(reader): rubric column headers and quiet gaps in the spread`.

### Task 2: Labels and `TraditionSwitch`

**Files:**
- Modify: `messages/{en,it,es,fr,de,pt}.json` (add the four keys; remove `greek` and `hebrew`; keep `estherText`)
- Create: `components/TraditionSwitch.tsx`
- Test: `components/__tests__/TraditionSwitch.test.tsx`

**Interfaces:**
- Produces: `TraditionSwitch({ tradition, hrefFor }: { tradition: Tradition; hrefFor: (t: Tradition) => string })` — a server component: a `nav` labelled `t("estherText")`, two links (Septuagint → `hrefFor("GREEK")`, Masoretic → `hrefFor("HEBREW")`) styled as rubric, the current one marked `aria-current="true"` (it stays a link).

- [ ] **Step 1: Write the failing tests:**
  - `offersBothTextsAndMarksTheCurrentOne`: `tradition="HEBREW"`, `hrefFor = (t) => t === "HEBREW" ? "/x?tradition=HEBREW" : "/x"` → link "Septuagint" has `href="/x"`, link "Masoretic text" has `aria-current="true"`; the nav's accessible name is "Esther text".
  - `everyLocaleHasTheFourLabels`: for each of the six message files, the four keys equal the Global Constraints table, and `greek`/`hebrew` are absent.
- [ ] **Step 2: Run** `npx vitest run components/__tests__/TraditionSwitch.test.tsx` — Expected: FAIL (module not found).
- [ ] **Step 3: Implement** the component and the message changes.
- [ ] **Step 4: Run** the same command — Expected: PASS.
- [ ] **Step 5: Commit** `feat(reader): Septuagint and Masoretic labels, and the switch between them`.

### Task 3: Settings — no select, a view toggle for the two texts

**Files:**
- Modify: `components/ReaderSettings.tsx`, `components/ReaderBar.tsx`, `lib/compare.ts`
- Test: `components/__tests__/ReaderBar.test.tsx`, `lib/__tests__/compare.test.ts`

**Interfaces:**
- Produces: `compareQuery(o: { with: string[]; view: View; tradition?: string; twoTexts?: boolean })` — writes `view=interlinear` when `with` is non-empty **or** `twoTexts`; writes `tradition=HEBREW` only when `with` is non-empty. `ReaderBar` prop `esther: boolean` keeps its meaning (edition has both texts).

- [ ] **Step 1: Write the failing tests:**
  - compare.test `twoTextsKeepTheViewInTheQuery`: `compareQuery({ with: [], view: "interlinear", twoTexts: true })` → `"?view=interlinear"`; `compareQuery({ with: [], view: "side-by-side", tradition: "HEBREW", twoTexts: true })` → `""`; `compareQuery({ with: ["DRB"], view: "side-by-side", tradition: "HEBREW" })` → `"?with=DRB&tradition=HEBREW"`.
  - ReaderBar.test: replace `estherTextLivesInTheMenuOnlyForEditionsWithBoth` with `theMenuHasNoEstherTextAndOffersInterlinearForTheTwoTexts`: with `esther` and no compared editions, opening the menu shows no "Esther text" control and shows the "Interlinear" checkbox; ticking it pushes `/en/read/nabre/esther/1?view=interlinear`. Without `esther`, no compared editions → no "Interlinear" checkbox (as today).
- [ ] **Step 2: Run** `npx vitest run lib/__tests__/compare.test.ts components/__tests__/ReaderBar.test.tsx` — Expected: the new tests FAIL.
- [ ] **Step 3: Implement:** remove the `estherText` select from `ReaderSettings`; show the interlinear checkbox when comparing or when `esther`; `ReaderBar` passes `twoTexts: p.esther && w.length === 0` to `compareQuery`. Fix any existing test whose expectation was the old URL rule (`tradition` written when not comparing): it now is not.
- [ ] **Step 4: Run** the same command — Expected: PASS.
- [ ] **Step 5: Commit** `feat(reader): Esther's text leaves the menu; the two texts can be read interlinear`.

### Task 4: The chapter page shows both texts

**Files:**
- Modify: `app/[locale]/read/[edition]/[book]/[chapter]/page.tsx`
- Test: `app/__tests__/pages.test.tsx`

**Interfaces:**
- Consumes: `SpreadSide.rubric`, `Spread` `gapNotes` (Task 1); `TraditionSwitch` (Task 2); `compareQuery({ …, twoTexts })` (Task 3).

- [ ] **Step 1: Write the failing tests** in `pages.test.tsx` (NABRE Esther index with `greekVersesPerChapter`, as in `estherWithBothTextsHasTenChaptersAndCrossesToFirstMaccabees`):
  - `readAloneEstherShowsBothTextsSideBySide`: `getChapter` returns Greek rows `1:1a` ("Greek one-a") and `1:1` ("Greek one") for `tradition: "GREEK"`, Hebrew row `1:1` ("Hebrew one") for `"HEBREW"` → both requested; one `spread`; column names "Septuagint" then "Masoretic text"; the row with "Greek one-a" has an empty `cell-b`; "Greek one" and "Hebrew one" share a row.
  - `comparingEstherShowsTheSwitchAndOneText`: `with: "DRB"`, `tradition: "HEBREW"` → `getChapter` called once for NABRE with `HEBREW`; a nav "Esther text" whose "Masoretic text" link has `aria-current`, and whose "Septuagint" link drops `tradition` but keeps `with=DRB`.
  - `eachTextStopsAtItsOwnCap`: Greek chapter `truncated: true`, Hebrew not → `Spread` gets `truncated=[true,false]` (assert no "Not in" text anywhere, one capped notice).
  - `aFailedMasoreticTextLeavesTheSeptuagintWithANote`: Hebrew `getChapter` rejects with `ApiUnavailable` → the page renders the Greek text and a note "Masoretic text could not be loaded. Try again later."
  - Update `passesTheEstherTraditionOnlyWhereTheEditionHasBoth` and `estherWithBothTextsHas…` to the new shape (no "Esther text" select; both traditions requested when alone; DRB still gets no tradition).
- [ ] **Step 2: Run** `npx vitest run app/__tests__/pages.test.tsx` — Expected: the new tests FAIL.
- [ ] **Step 3: Implement** in `page.tsx`:
  - `twoTexts = hasBothEstherTexts(book) && withSiglas.length === 0`.
  - Extract the existing "fetch the chapter plus its neighbour verses and `placeAsPrinted`" into a local `printed(tradition)` returning `{ rows, truncated }` (it already chooses `greekVersesPerChapter` for Greek).
  - Two texts: `printed("GREEK")` and `printed("HEBREW")` (the latter caught: `ApiUnavailable` → the note above, Greek shown with `BookPage`); render a heading and `Spread` with sides `[{ sigla: edition.sigla, name: edition.fullname, lang, rubric: { full: t("septuagint"), short: t("septuagintShort") } }, { sigla: \`${edition.sigla}-MT\`, …, rubric: { full: t("masoretic"), short: t("masoreticShort") } }]`, `rows={buildRows(greek.rows, [hebrew.rows], book.bookNum)}`, `truncated`, `view`, `gapNotes={false}`; page width `max-w-6xl` unless interlinear.
  - Comparing with both texts: render `TraditionSwitch` above the `Interlinear`, `hrefFor` built with `compareQuery({ with, view, tradition })` on the current path.
  - `qs` passes `twoTexts`.
- [ ] **Step 4: Run** `npx vitest run && npx tsc --noEmit && npm run lint` — Expected: all pass, clean.
- [ ] **Step 5: Commit** `feat(reader): Esther's Septuagint and Masoretic text side by side`.
- [ ] **Step 6: Check in a browser** (`npm run dev`, NABRE Esther 1 at 390px and 1280px wide, side by side and interlinear; then with `?with=DRB`): screenshots, rubric red in both themes.
