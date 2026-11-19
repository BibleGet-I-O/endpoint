# Unit mapping drafts and review: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Map BibleGet's seven editions to CBTUDR units. Rules link what they can; LaBSE-aligned drafts cover the rest. A reviewer checks the drafts on staging, and the reviewed links go back to CBTUDR.

**Architecture:**
- A generic drafting tool in CBTUDR (Python): rule pass, anchored stretches, monotone alignment on vectors.
- A review store in the endpoint (PostgreSQL tables loaded by a local script), served by `/review` routes behind the Zitadel role `bibleget_reviewer`.
- A review screen in the website.
- An export script in CBTUDR that turns reviewed links into map files.

**Tech stack:** Python 3.12 (stdlib + numpy) in CBTUDR; PHP 8.4, PSR-15, Doctrine Migrations and PHPUnit 13 in the endpoint; Next.js 16, next-intl 4 and vitest in the website.

**Spec:** `docs/superpowers/specs/2026-11-19-unit-mapping-review-design.md` (endpoint repo).

## Global Constraints

- **No Bible text** in CBTUDR or in any committed file or fixture. Drafting reads verse identities, text lengths and vectors only; fixtures use synthetic vectors.
- **Statuses and kinds:** `status ∈ {rule, draft, reviewed}`, `kind ∈ {same, corresponding}`.
  - A link is `reviewed` only after a reviewer action.
  - "Accept chapter" accepts only that chapter's drafts.
- **The role** is exactly `bibleget_reviewer`, checked as `/admin` checks `bibleget_admin`.
- **Editions are named by** BibleGet sigla (`VGCL`) in the endpoint and website, and by CBTUDR slug (`vgcl` = lowercase sigla) in CBTUDR.
- **Drafting is local**, from `~/development/BibleGet-I-O/data-local/`, a git-ignored directory outside the repos. Exports from staging are read-only.
- **Outward actions need the user's confirmation when reached:**
  - creating the Zitadel role;
  - exporting the vectors;
  - deploying;
  - loading the tables on staging.
- **Each repo keeps its own conventions:**
  - endpoint: `composer test`, `composer lint`, `composer analyse` (PHPStan level 10), cyclomatic complexity ≤ 20;
  - website: `npm test`, `npx tsc --noEmit`, `npm run lint`, strings in 6 locales;
  - CBTUDR: `python3 -m unittest discover -s scripts`, and `python3 scripts/validate.py` reporting 0 problems.
- **Stage files by name** in every repo.

## Review Focus

These are the failure modes most likely to bite, each pinned by a named test:

1. **A stretch that crosses a chapter boundary.** VGCL Gen 31:55 must link to `btu:gn.32.1`, not to some unit of Gen 31 (Task 1: `test_a_stretch_crosses_the_chapter_boundary`).
2. **A Vulgate-numbered psalm.** VGCL Ps 9:22 must be compared with Hebrew Ps 10:1 before any rule decides (Task 1: `test_vulgate_psalms_are_compared_in_hebrew_numbering`).
3. **A relink outside the link's stretch.** It must be refused with 422, so a reviewer cannot link Tobit 5 to Tobit 9 by mistake (Task 5: `testRelinkOutsideTheStretchIs422`).
4. **A second load of the same drafts.** It must not overwrite reviewed links (Task 4: `testReloadKeepsReviewedLinks`).
5. **A reviewer whose session lacks the role.** They get a 404 page, not an empty review screen (Task 6: `reviewPagesAre404WithoutTheRole`).

---

### Task 1: Rule pass and anchored alignment (CBTUDR)

**Files:**
- Create: `scripts/align.py`
- Test: `scripts/test_align.py`

**Interfaces:**
- Produces:
  - `Verse = dict` with keys `book:int, chapter:int, label:str, tradition:str, vec:list[float]|None, length:int`;
  - `Unit = dict` with keys `id:str, chapter:int, label:str, vec:list[float]|None`;
  - `hebrew_psalm(chapter:int, verse:int, edition:str) -> tuple[int,int]`;
  - `rule_pass(verses:list[Verse], units:list[Unit], psalms:str) -> tuple[list[Link], list[Stretch]]`, where `Link = {ref, units, kind, status, confidence?, stretch?}` and `Stretch = {id:int, verses:list[int], units:list[str]}`;
  - `align_stretch(verses, units, extra=0.15, gap=0.30) -> list[tuple[list[int], list[int], float]]`, returning edition-verse indexes, unit indexes and similarity.

Steps:

- [ ] **Step 1. Write the failing tests** in `scripts/test_align.py`, with synthetic vectors: one-hot dimensions, so similarity is exactly 0 or 1.
  - `test_identical_chapters_are_rule_links`: an edition chapter with labels `1,2,3` against units `gn.5.1-3` gives three links `status:"rule"`, `kind:"same"`, and no stretch.
  - `test_a_differing_chapter_becomes_one_stretch_between_anchors`: chapters 4 and 6 identical and 5 different give one stretch holding chapter 5's verses and units.
  - `test_a_stretch_crosses_the_chapter_boundary`: VGCL Gn 31 has 55 verses and 32 has 32; the units have 31 = 1-54 and 32 = 1-33. Verse 31:55's vector equals unit `gn.32.1`'s, so `align_stretch` links `31:55` → `btu:gn.32.1`.
  - `test_two_verses_to_one_unit`: verses a and b, whose vectors sum to unit u's, are linked as one group `([0,1],[0])` to u.
  - `test_vulgate_psalms_are_compared_in_hebrew_numbering`: `hebrew_psalm(9, 22, "VGCL") == (10, 1)`, `hebrew_psalm(113, 9, "VGCL") == (115, 1)`, `hebrew_psalm(115, 1, "VGCL") == (116, 10)`, `hebrew_psalm(115, 10, "DRB") == (116, 10)`, `hebrew_psalm(147, 1, "VGCL") == (147, 12)` and `hebrew_psalm(50, 3, "VGCL") == (51, 3)`. A VGCL Ps 9 whose Hebrew-mapped labels equal units `ps.9.*` and `ps.10.*` gives rule links.
  - `test_unmatched_verse_has_no_units`: a verse whose vector matches nothing (similarity < `gap`) comes out with `units: []` and lowest confidence.
- [ ] **Step 2. Run** `cd ~/development/CatholicOS_org/cbtudr && python3 -m unittest scripts.test_align`. Expected: it fails with an ImportError.
- [ ] **Step 3. Implement `scripts/align.py`:**
  - `hebrew_psalm` inverts the table in the endpoint's `src/Pipeline/Transform/PsalmNumbering.php` docblock. Read `toHebrew()` there and port it exactly, including the VGCL/DRB difference in 115 and 147.
  - **The rule pass** compares, for each Nova Vulgata chapter, the edition's verses whose candidate reference falls in it with the unit labels. An exact equality makes rule links. The non-rule verses and units between consecutive rule chapters form a stretch.
  - **`align_stretch`** is a dynamic program over moves (1,1), (2,1), (1,2), (3,1), (1,3), (2,2), (1,0) and (0,1).
    - Score of a grouping: the cosine of the summed normalized vectors, minus `extra × (size − 2)`.
    - Score of a gap: minus `gap`.
    - It maximizes the total.
  - **Confidence** is the group's similarity, clamped to 0-1.
- [ ] **Step 4. Run the tests** again. Expected: OK.
- [ ] **Step 5. Commit** `scripts/align.py` and `scripts/test_align.py` on branch `draft-alignment`, with the message "Drafting: rule pass and anchored alignment".

### Task 2: Drafting CLI and drafts files (CBTUDR)

**Files:**
- Create: `scripts/draft_alignment.py`, `scripts/test_draft_alignment.py`
- Modify: `scripts/validate.py` (accept and check `drafts/<slug>/*.json`)
- Modify: `docs/schema-proposal.md` (§4: the `drafts/` files)

**Interfaces:**
- Consumes: Task 1's `rule_pass` and `align_stretch`.
- Produces:
  - `python3 scripts/draft_alignment.py --edition SLUG --structure S.csv --vectors V.csv --nv-vectors N.csv [--psalms vulgate|hebrew] [--kind-corresponding BOOK,…]`. It writes `data/maps/<slug>/NN-<book>.json` (links with no `confidence`) and `drafts/<slug>/NN-<book>.json`, which holds `{"edition","book","stretches":[{id, verses:[ref…], units:[id…]}], "confidence":{ref: float}}`.
  - Vectors CSV: `book,chapter,verse,verse_segment,text_tradition,labse` (the `labse` column is a JSON array).

Steps:

- [ ] **Step 1. Failing tests:**
  - `test_cli_writes_maps_and_drafts`: a tmp dir with a 2-book fixture writes both files, and `validate()` on the tmp dir returns `[]`.
  - `test_corresponding_books_are_drafted_as_corresponding`: with `--kind-corresponding tb`, drafts in Tobit have `kind:"corresponding"`.
  - `test_drafts_file_refs_match_map_refs`: every ref in a drafts file's `confidence` is a ref with `status:"draft"` in the map.
  - `test_validator_rejects_a_draft_ref_unknown_to_the_map`.
- [ ] **Step 2. Run.** Expected: fail.
- [ ] **Step 3. Implement** `scripts/draft_alignment.py`, and extend `validate.py`: it must check each drafts file against its map, and treat a missing `drafts/` directory as fine.
- [ ] **Step 4. Run** all CBTUDR tests and `validate.py`. Expected: OK, 0 problems.
- [ ] **Step 5. Commit** with the message "Drafting CLI; drafts files hold stretches and confidences".

### Task 3: Draft the six editions (data; outward actions)

**Files:**
- Create in CBTUDR: `data/maps/{nabre,cei2008,vgcl,drb,blpd,luzzi}/*.json`, `drafts/<slug>/*.json`, and the `data/editions.json` entries.
- Create in the endpoint: `scripts/export_draft_vectors.sh`, which stays out of the rsync deploy, as `scripts/` already does.

Steps:

- [ ] **Step 1.** Write `scripts/export_draft_vectors.sh EDITION BOOKS_CHAPTERS_FILE OUT.csv.gz`. Like the earlier exports, it runs `\copy` on the server inside `ssh`, sourcing `.env.staging` without printing it, and copies the result back. Its columns are those of Task 2's vectors CSV.
- [ ] **Step 2.** Compute the differing chapters per edition from `data-local/structure/` against the CBTUDR units: the chapter-level comparison of the recon, with the NV chapters of each.
- [ ] **Step 3 (confirm with the user).** Export the vectors of those chapters for each edition, plus NVBSE's vectors for the same Nova Vulgata chapters, into `data-local/labse/drafts/`.
- [ ] **Step 4.** Tune `extra` and `gap` on Tobit 5. The user's mapping VGCL → CEI is 3-4 → 4, 5-6 → 5, 7-8 → 6 and 9-10 → 7. Through the units, VGCL and CEI2008 Tb 5 should come out consistent with it.
  - Record the chosen values in `draft_alignment.py`'s defaults.
  - Add a fixture test, `test_tobit_5_shape`, built from the user's mapping with synthetic vectors.
- [ ] **Step 5.** Run the drafting for the six editions:
  - `--psalms vulgate` for VGCL and DRB;
  - `--kind-corresponding tb,idt,est,eccli` for VGCL and DRB;
  - `--kind-corresponding tb` for the others.

  `validate.py` must report 0 problems.
- [ ] **Step 6. Cross-check** against the endpoint's `tests/fixtures/versification/cases.json`.
  - Write `scripts/check_cases.py CASES.json`. For each case's chapter-boundary pairs, it reports any draft link that contradicts the documented shift (e.g. Gen 31:55 not linked to `btu:gn.32.1`).
  - Lower the confidence of the contradicting links to 0 in their drafts files.
  - Report the count.
- [ ] **Step 7.** Commit the data, plus `check_cases.py` with its test `test_contradicting_link_is_reported`. Open the CBTUDR PR "Draft maps of six BibleGet editions". It is merged only after review (Task 7 export).

### Task 4: Review store and loader (endpoint)

**Files:**
- Create:
  - `migrations/053-btu-review-store.sql` and `src/Migrations/Version053BtuReviewStore.php`;
  - `src/Repositories/BtuRepository.php`;
  - `scripts/load_cbtudr.php`;
  - `tests/Integration/Repositories/BtuRepositoryTest.php`.

**Interfaces:**
- Tables:
  - `btu_unit(id text PK, book smallint not null, ord integer not null, parent text null, after text null, nv_omitted boolean not null default false)`;
  - `btu_stretch(edition varchar(16), book smallint, id text, units text[] not null, PK(edition,book,id))` (text: Esther's stretches are per text tradition, `GREEK-1`; a touched link's frozen copy is `r<link id>`);
  - `btu_link(id bigserial PK, edition varchar(16) not null, book smallint not null, ord integer not null, ref text not null, units text[] not null, kind text not null check (kind in ('same','corresponding')), status text not null check (status in ('rule','draft','reviewed')), confidence real null, stretch text null, note text null, reviewed_by text null, reviewed_at timestamptz null, UNIQUE(edition,book,ref))`.
  - Indexes: `(edition, status)` and `(edition, book, ord)`.
- `BtuRepository` methods:
  - `upsertUnits(list $units): int`;
  - `loadEdition(string $sigla, int $book, list $links, list $stretches): array{inserted:int,updated:int,kept:int}`: a reviewed link is never overwritten;
  - `editions(): list<array{edition,draft,reviewed,rule}>`;
  - `chaptersWithDrafts(string $sigla): list<array{book,chapter,drafts,min_confidence}>`, ordered by `min_confidence` ascending, where a link's chapter is the chapter in its ref;
  - `chapter(string $sigla, int $book, int $chapter): array{links, units}`, where `units` are those of the stretches the chapter's links belong to;
  - `link(int $id): ?array`;
  - `review(int $id, array $change, string $sub): array`;
  - `acceptChapter(string $sigla, int $book, int $chapter, string $sub): int`.
- `php scripts/load_cbtudr.php CBTUDR_DIR [--edition SLUG]`. It reads `data/units`, `data/maps/<slug>` and `drafts/<slug>`, and prints `APP_ENV`/`DB_NAME` to stderr first, as the mint scripts do.

Steps:

- [ ] **Step 1. Failing integration tests** in `BtuRepositoryTest`:
  - `testLoadInsertsLinksAndStretches`;
  - `testReloadKeepsReviewedLinks`: load, review one link, load again with a different draft for that ref, and the reviewed link is unchanged with `kept === 1`;
  - `testChaptersWithDraftsAreOrderedByLowestConfidence`;
  - `testAcceptChapterAcceptsOnlyThatChaptersDrafts`;
  - `testChapterReturnsTheUnitsOfItsStretches`.
- [ ] **Step 2. Run** `composer test -- --filter BtuRepositoryTest`. Expected: fail.
- [ ] **Step 3. Implement:**
  - The migration file follows the `052` pattern, with no BEGIN/COMMIT.
  - The repository uses PDO prepared statements, with `text[]` passed as a PostgreSQL array literal built by a private helper.
  - The loader is thin: argument parsing, plus calls to the repository.
- [ ] **Step 4. Run** the tests, `composer lint` and `composer analyse`. Expected: green.
- [ ] **Step 5. Commit** on branch `feat/review-store`.

### Task 5: Review routes (endpoint)

**Files:**
- Create: `src/Handlers/Review/ReviewHandler.php` and `tests/Integration/Handlers/ReviewHandlerTest.php`.
- Modify: `src/Router.php` (route `review`, with `REVIEWER_ROLE = 'bibleget_reviewer'` and `oidcMiddleware` for `review`), `openapi.json`, and `CLAUDE.md` (routes table).

**Interfaces:**
- Consumes: Task 4's `BtuRepository`.
- Routes (JSON). A sigla matches `VersionAdminHandler::SIGLA_PATTERN`:
  - `GET /review/editions` → `{"editions":[{edition,draft,reviewed,rule}]}`
  - `GET /review/editions/{E}/chapters` → `{"chapters":[{book,chapter,drafts,min_confidence}]}`
  - `GET /review/editions/{E}/books/{B}/chapters/{C}` → `{"links":[{id,ref,units,kind,status,confidence,stretch,note}],"units":[{id,ref,stretch}]}`
  - `PATCH /review/links/{id}`: the body is exactly one of
    - `{"action":"accept"}`;
    - `{"action":"relink","units":[…]}`;
    - `{"action":"none"}`;
    - `{"action":"split","note":"…"}`;
    - `{"kind":"same"|"corresponding"}`.

    It answers the updated link.
  - `POST /review/editions/{E}/books/{B}/chapters/{C}/accept` → `{"accepted":n}`

Steps:

- [ ] **Step 1. Failing tests.** A request carries an `oidc_user` attribute, as the admin handler tests do.
  - `testEditionsListsCounts`;
  - `testChapterReturnsLinksAndUnits`;
  - `testAcceptMarksReviewed`;
  - `testRelinkOutsideTheStretchIs422`;
  - `testRelinkOfARuleLinkIs422`;
  - `testUnknownActionIs422`;
  - `testUnknownLinkIs404`;
  - `testAcceptChapterReturnsCount`.

  Plus a Router unit test, `testReviewRoutesRequireTheReviewerRole`: the pipeline for `review` uses `requireRole('bibleget_reviewer')`.
- [ ] **Step 2. Run.** Expected: fail.
- [ ] **Step 3. Implement** `ReviewHandler extends AbstractAdminHandler`, which reuses its auth, JSON and method plumbing. Keep `dispatch` under complexity 20 by splitting it into per-route private methods.
- [ ] **Step 4. Run** `composer test`, `composer lint` and `composer analyse`. Expected: green.
- [ ] **Step 5. Commit.** Push, open the endpoint PR "Review store and routes for CBTUDR unit links", and wait for CodeRabbit.

### Task 6: Review screen (website)

**Files:**
- Modify:
  - `lib/roles.ts`: `REVIEWER_ROLE`, `isReviewer`;
  - `auth.ts`: `reviewer` boolean on the token and session, alongside `admin`;
  - `components/AuthStatus.tsx`: a "Review" link when `session.reviewer`;
  - `messages/*.json`: a `Review` namespace, in 6 locales.
- Create:
  - `lib/review.ts`: types and `reviewUrl(path)`;
  - `app/[locale]/review/page.tsx`: editions, then chapters, least confident first;
  - `app/[locale]/review/[edition]/[book]/[chapter]/page.tsx`;
  - `app/[locale]/review/actions.ts`: Server Actions `reviewLink(id, change)` and `acceptChapter(edition, book, chapter)`, on the `setEditionFlag` pattern;
  - `components/ReviewLink.tsx` (client): one link row with its buttons;
  - tests: `lib/__tests__/roles.test.ts` (extended), `app/__tests__/review.test.tsx`, `components/__tests__/ReviewLink.test.tsx`.

**Interfaces:**
- Consumes: Task 5's routes.
- The chapter page renders:
  - the edition's verses: `getChapter` for the edition, with the reviewer's access;
  - for each link, the NVBSE text of its units: `getChapter` for NVBSE on the units' chapters, with units matched to rows by chapter, verse and segment;
  - the confidence, as a bar with `aria-label` "Confidence 45%".

Steps:

- [ ] **Step 1. Failing tests:**
  - `isReviewerReadsTheRolesClaim`;
  - `reviewPagesAre404WithoutTheRole`;
  - `theListPutsTheLeastConfidentChapterFirst`;
  - `theChapterShowsEachVerseBesideItsUnitsText`;
  - `acceptAllAppearsOnlyOnTheChapterPage`;
  - `relinkOffersOnlyTheStretchsUnits`;
  - `reviewLinkActionRequiresTheReviewerRole`;
  - the messages test stays green.
- [ ] **Step 2. Run** `npx vitest run`. Expected: fail.
- [ ] **Step 3. Implement:**
  - The pages are Server Components that fetch with `getAccessToken()`, as the admin editions page does.
  - `ReviewLink` calls the Server Actions and shows the new status.
  - Translate the strings into it, es, fr, de and pt.
- [ ] **Step 4. Run** `npm test`, `npx tsc --noEmit` and `npm run lint`. Expected: green. Take Playwright screenshots at 390 and 1280 px on a temporary preview page, removed before committing.
- [ ] **Step 5. Commit** on branch `feat/review-screen`, push, open the website PR, and wait for CodeRabbit.

### Task 7: Export of reviewed links (CBTUDR)

**Files:**
- Create: `scripts/import_reviewed.py` and `scripts/test_import_reviewed.py`.

**Interfaces:**
- `python3 scripts/import_reviewed.py --edition SLUG --api URL` reads a bearer token from the `BTU_REVIEW_TOKEN` env var (never an argument). It:
  1. pages `GET /review/editions/{SIGLA}/chapters` and each chapter;
  2. rewrites `data/maps/<slug>/*.json` for links with status `reviewed`;
  3. removes their refs from `drafts/`;
  4. runs `validate`.
- `apply_review(map_data: dict, drafts: dict, reviewed: list[dict]) -> tuple[dict, dict]` is pure, for tests.

Steps:

- [ ] **Step 1. Failing tests:**
  - `test_reviewed_links_replace_drafts`;
  - `test_none_becomes_absent`: a verse with no counterpart has `units: []`, so its former units go to `absent` if nothing else covers them;
  - `test_split_flags_are_reported_not_applied`;
  - `test_rule_links_are_untouched`.
- [ ] **Step 2. Run.** Expected: fail.
- [ ] **Step 3. Implement.**
- [ ] **Step 4. Run** all tests and `validate.py`. Expected: OK.
- [ ] **Step 5. Commit** to the `draft-alignment` branch.

### Task 8: Staging rollout (outward; confirm each)

- [ ] Merge the endpoint PR, then run the deploy workflow to staging. The migration 053 applies through `/_ops/migrate`.
- [ ] Create the role `bibleget_reviewer` in the staging Zitadel project, and grant it to the user. This needs the user's confirmation: their own action, or one through cdcf-infra.
- [ ] Load the drafts: `php scripts/load_cbtudr.php ~/development/CatholicOS_org/cbtudr`, through the SSH tunnel with the `DB_*` and `APP_ENV=staging` variables.
- [ ] Merge the website PR and deploy to staging. Then check at 390 and 1280 px that the reviewer sees the list and a chapter, and that a visitor gets 404 on `/en/review`.
