# Mapping BibleGet's editions to CBTUDR units: drafts and review

Status: design, for approval (2026-11-19). Part 2 of the plan agreed in conversation:
1. CBTUDR seeded (done: CatholicOS/cbtudr).
2. **Map every BibleGet edition to the units, drafting what rules cannot decide and reviewing it** (this document).
3. The website's comparison view collects verses by unit.

## Why

The comparison view lines up editions by chapter and verse number. That fails:
- wherever editions divide the text differently: Tobit 5 in the Vulgate and in CEI 2008, Joel 3 and 4, the Vulgate's Psalms;
- wherever an edition puts a verse in another chapter (Gen 31:55 is Gen 32:1).

CBTUDR gives each piece of text one identifier. Once each edition's verses are mapped to those identifiers, the comparison view can line up anything. This part produces those mappings for the seven BibleGet editions (NVBSE, NABRE, CEI2008, VGCL, DRB, BLPD, LUZZI), with a person checking every link a rule cannot vouch for.

## How much needs drafting

Each edition's verse labels (structure only, no text) were compared, chapter by chapter, with the Nova Vulgata units:

| Edition | Chapters identical to the units | Chapters that differ | Verses in them |
|---|---|---|---|
| NABRE | 1241 | 87 (Sirach 49, Esther 7, Psalms 7, …) | 2452 |
| CEI2008 | 1245 | 83 (Sirach 46, Esther 7, Psalms 6, Tobit 4, …) | 2281 |
| BLPD | 1194 | 134 (Sirach 47, Psalms 14, …) | 3704 |
| VGCL | 1026 | 308 (Psalms 136, Judith 14, Esther 13, Tobit 12, …) | 7404 |
| DRB | 1021 | 313 (Psalms 135, …) | 7521 |
| LUZZI | 1030 | 159 (Psalms 66, …) | 3545 |

About 90% of chapters map by the plain same-reference rule. The rest are drafted, then reviewed.

## Goals

1. **Rule links.** Where a chapter's verses are exactly the units' verses, link verse `c:v` to `btu:<book>.c.v` with status `rule`. Rules are applied before drafting:
   - For VGCL and DRB, the Psalms chapter table the endpoint already uses (`PsalmNumbering`: Hebrew 10 = Vulgate 9:22-39, Hebrew 11-113 = Vulgate n−1, …).
   - Esther's two runs, as in the Nova Vulgata's own mapping.
2. **Drafted links** for every other verse, status `draft`.
   - Each stretch of verses between two rule-matched anchors is aligned with the Nova Vulgata units of the same stretch, in reading order, on LaBSE sentence embeddings. A stretch may cross a chapter boundary.
   - Allowed groupings: 1-1, 1-2, 2-1, 1-3, 3-1, 2-2, and a verse or unit with no counterpart.
   - The alignment chooses the groupings that maximise similarity, with a small cost for each extra verse in a group.
   - Each link gets a **confidence**: the similarity of its group, minus that of the best competing grouping.
   - Links between texts of different recensions (Tobit, Judith, the Vulgate's longer Esther and Sirach) are drafted as `corresponding`; everything else as `same`.
3. **Review on staging.**
   - Reviewers sign in with a new Zitadel role, `bibleget_reviewer`. Per-edition permissions (OpenFGA) are out of scope.
   - For each edition and chapter with drafts, the reviewer sees:
     - the edition's verses, with their text;
     - the Nova Vulgata units each is linked to, with the Nova Vulgata's text;
     - each link's confidence.

     The least confident chapters come first.
   - On each link the reviewer can:
     - **accept** it;
     - **relink** the verse to other units of the same stretch;
     - change `same` ↔ `corresponding`;
     - mark the verse as having **no counterpart**;
     - **flag a split needed**, with a note.
4. **Export.** A script writes the reviewed and rule links into CBTUDR's map files and opens a CBTUDR pull request. CBTUDR stays the source of truth; staging holds the work in progress.
5. **Cross-checks.** The drafts are checked against what BibleGet already knows:
   - the documented versification cases (`tests/fixtures/versification/cases.json`), such as Gen 31:55 = Gen 32:1 and Joel's chapters;
   - the endpoint's `alt_ref` values, where an edition records another numbering.

   A draft that contradicts them is marked low-confidence, so it is reviewed first.

## Non-goals

- Splitting units inside the review screen. A flagged split is done by hand in a CBTUDR pull request, which adds the parts and relinks the verses. Splits should be rare: most differences are groupings of whole verses.
- Per-edition reviewer permissions, and review of anything other than unit links.
- Named pericopes (deferred to the lectionary work).
- Changing the website's comparison view (part 3).

## Anti-goals

- **A draft presented as reviewed.** A link stays `draft` until a person acts on it. A bulk "accept all" exists only per chapter, after the reviewer has seen that chapter.
- **Bible text in CBTUDR,** or in any file committed to a public repository. The drafting tool reads vectors and verse identities; the review screen reads text from the API, as the reader does.
- **Load on the production or staging database from drafting.** Drafting runs locally, on an export of the vectors it needs.

## Approaches considered

1. **(Chosen) Rules first, then embedding-aligned drafts for each stretch between rule anchors, reviewed in a staging store, exported to CBTUDR.**
   - Rules cover about 90% of chapters with certainty.
   - Anchors keep every alignment short and local, so one bad match cannot shift a whole book.
   - Chapter-boundary cases come out of the same process, instead of a hand-written table.
2. **Hand-encode every documented difference as a rule, and draft only Tobit, Job, Sirach, Greek Esther and Exodus 40.** Rejected: it means hand-writing about 30 boundary cases plus the Clementine Psalms' verse-level differences, which the endpoint's table explicitly leaves unmapped. It would still miss the differences nobody has documented.
3. **Review in CBTUDR directly: reviewers edit draft files in pull requests.** Rejected for a first pass: reviewers would compare two texts with no tool to show them side by side, and copyrighted text cannot go into the repository to help them.

## Design

### Drafting tool (CBTUDR `scripts/draft_alignment.py`)

The tool is generic, so other applications can draft their own editions. It lives in CBTUDR.

**Input:**
- the edition's verse structure, as CSV;
- a vector per verse for the edition, and a vector per unit for the Nova Vulgata, as CSV. The vectors can come from any sentence-embedding model.

**Output:** the edition's map files, with links of status `rule` and `draft`. Each draft carries a `confidence` (0-1) in a parallel `drafts/` file, so the published maps stay clean.

BibleGet supplies:
- the LaBSE vectors (`embedding_labse`), exported from staging only for the verses of the differing chapters, plus the Nova Vulgata's verses of those chapters;
- the structure exports (`data-local/structure/`).

### Review store (endpoint, staging)

A Doctrine migration adds two tables. Their names are prefixed `btu_`, like the registry. They are loaded from a CBTUDR checkout and the drafts by `scripts/load_cbtudr.php`, which, like the key-minting scripts, runs locally through an SSH tunnel.

```
btu_unit (id text PK, book smallint, ord integer, parent text NULL, after text NULL, nv_omitted boolean)
btu_link (id bigserial PK, edition text, book smallint, ord integer, ref text, units text[], kind text,
          status text, confidence real NULL, note text NULL, reviewed_by text NULL, reviewed_at timestamptz NULL,
          UNIQUE (edition, book, ref))
```

`reviewed_by` holds the reviewer's Zitadel subject. It is kept only on staging, for the export; it is not exported, since the git history credits the pull request.

### Review API (endpoint)

Under `/review`, `OidcBearerMiddleware()->requireRole('bibleget_reviewer')`, the same pattern as `/admin`:

- `GET /review/editions`: each edition, with its counts of drafts and of reviewed links.
- `GET /review/editions/{edition}/chapters?status=draft`: chapters with drafts, least confident first.
- `GET /review/editions/{edition}/books/{book}/chapters/{chapter}`: that chapter's links, plus the units of its stretches with their Nova Vulgata references. No text: the website fetches the text from `/quote`.
- `PATCH /review/links/{id}`: one of
  - `{action: "accept"}`;
  - `{action: "relink", units: […]}`, where the units must lie in the same stretch;
  - `{kind}`;
  - `{action: "none"}`, for no counterpart;
  - `{action: "split", note}`.

  It records `reviewed_by` and `reviewed_at`.
- `POST /review/editions/{edition}/books/{book}/chapters/{chapter}/accept`: accepts the chapter's remaining drafts. The website offers it only after the chapter has been shown.

### Review screen (website, staging)

- `/[locale]/review` is visible only with `bibleget_reviewer`. A "Review" link in the header menu appears for those users, like the admin link.
- **The list:** editions, then chapters with drafts, least confident first.
- **The chapter view:** two columns.
  - The edition's verses, with their text.
  - Beside each verse, the Nova Vulgata text of the units it is linked to, with the link's confidence as a colour bar.
  - The buttons for the actions above.
  - Verse text comes from the API with the reviewer's access, so copyrighted editions show in full.
- Strings in the six languages, as the rest of the site.

### Export (CBTUDR `scripts/import_reviewed.py`, run by a maintainer)

1. It reads the reviewed and rule links from the staging API, under a reviewer's token.
2. It writes the map files.
3. It runs the validator.
4. The maintainer opens the pull request. Drafts still unreviewed stay `draft` in the files, marked as such.

## Decided with the user

- CBTUDR is the standard; BibleGet is a consumer.
- Drafts come from LaBSE; the user reviews the uncertain ones.
- Review happens on staging, behind a Zitadel role; per-edition OpenFGA permissions come later.
- Reviewer attribution lives in the git history.

## Outward actions this needs (each confirmed when reached)

- Creating the `bibleget_reviewer` role in the staging Zitadel project, and assigning it to the user.
- A read-only export of the LaBSE vectors for the differing chapters.
- Deploying the migration and the routes to staging.
- Loading the drafts into the staging tables.

## Left to the builder

- The exact confidence formula and the cost of each extra verse in a group. Tune them on Tobit 5, where the user's own mapping is known (VGCL 3-4 → CEI 4, 5-6 → 5, 7-8 → 6, 9-10 → 7).
- The layout details of the review screen.
- The batch size of the export pull requests: one per edition is suggested.
