# Esther's two texts side by side in the reader — design

**Status:** short design (a small change to the website reader). Approach A chosen by the user on 2026-11-20.

## Intent

CEI 2008, NABRE and BLPD print Esther twice: the Masoretic (Hebrew) text and the Septuagint (Greek) text with its
additions. Today the reader shows one at a time, chosen in the ⚙ menu ("Esther text", `?tradition=HEBREW`), where it
is easy to miss. The reader should show both at once, as the printed edition invites comparing them.

## Approaches

- **A. Two columns when reading one edition; one shared text when comparing (chosen).** Read alone, Esther shows
  the Septuagint and the Masoretic text side by side. Comparing editions, every edition shows the same text, chosen
  with a visible rubric switch above the columns instead of the ⚙ menu. Readable at any width; you cannot see both
  texts of every compared edition at once.
- **B. Every edition gets both columns.** Up to six columns with three editions: complete, but cramped even on a
  desktop. Not chosen.

## Design

- **When:** Esther in an edition whose index stores both texts (`hasBothEstherTexts`): CEI 2008, NABRE, BLPD.
  Every other book and edition is unchanged.
- **Read alone:** two columns, built with the comparison view's machinery (rows paired by chapter, verse and letter,
  as since website #13):
  - **Septuagint** on the left, **Masoretic** on the right;
  - each column is headed by a small red rubric: the full name on wide screens, `LXX` / `MT` (Italian `TM`)
    on phones in portrait;
  - the Greek additions (1:1a…) sit beside empty Masoretic space;
  - the reader's view setting applies as in comparisons: side by side by default, interlinear when chosen.
- **Comparing:** every edition shows the same text. A rubric switch, *Septuagint | Masoretic*, sits above the
  columns, replacing the ⚙ menu's "Esther text". `?tradition=HEBREW` keeps selecting the Masoretic text, so
  existing links still work.
- **Labels,** in the six reader languages: *Septuagint* / *Masoretic text* in English, *Settanta* /
  *Testo masoretico* in Italian; the rest follow the same pattern in each language.
- **The 30-verse cap** for visitors on copyrighted editions applies to each text, as to each compared edition.

## Non-goals

- **Pairing passages the Septuagint retells elsewhere,** e.g. CEI 1:1l–1r ≈ Masoretic 2:21–23. That needs the
  CBTUDR links (comparison by unit, a later part); pairing stays by number.
- **The review screen is unchanged:** it already reviews each text separately.

## Left to the builder

- Whether the two columns reuse `Interlinear` with a second "side" for the same edition, or get their own component.
- The exact rubric styling, within the reader's existing red-rubric conventions.
