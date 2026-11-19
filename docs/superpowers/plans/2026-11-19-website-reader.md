# BibleGet website: the reader — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Next.js site in a new repository `BibleGet-I-O/website` that reads every edition chapter by chapter, shows a second edition side by side, gives signed-in users whole chapters and visitors 30 verses, lets the admin switch each edition's full text, and deploys to `staging.bibleget.io`.

**Architecture:** Server-rendered Next.js App Router pages call the BibleGet endpoint from the server with one of two keys (visitor `read` key, signed-in `reader` key), chosen from the session; API keys stay in server code; the access token lives only in the next-auth JWT session cookie (encrypted, HttpOnly) and is read only by server code through `getAccessToken()`, never in the Session object, a client component or any response body. Sign-in is next-auth v5 with Zitadel. The reader's facing-page spread and row alignment are adapted from `CatholicOS/martyrology-frontend` (`components/Spread.tsx`, `components/Reader.tsx`, `components/ReaderBar.tsx`, `lib/parallel.ts`, `auth.ts`, `lib/roles.ts`, `lib/session-token.ts`, `.github/workflows/deploy.yml`).

**Tech Stack:** Next.js 16, React 19, TypeScript, next-auth 5 (beta, as the Martyrology site), next-intl 4, Tailwind 4, Vitest + Testing Library, ESLint; Node as the Martyrology site's `.nvmrc`/engines.

**Spec:** `docs/superpowers/specs/2026-11-19-website-reader-design.md` (in the endpoint repo; copy it into the new repo's `docs/` in Task 1). Depends on the endpoint plan `docs/superpowers/plans/2026-11-19-reader-endpoint-changes.md` (Tasks 1–4 merged and deployed to `/dev` before this plan's Task 9).

## Global Constraints

- Never an API key or access token in the browser. API keys only in server code (`import "server-only"`). The access token is stored only in the next-auth JWT session cookie (encrypted, HttpOnly) and read only by server code (`getAccessToken()`); it never appears in the Session object, in a client component or in any response body.
- The site never decides copyright: visitor pages use the `read` key (API caps copyrighted editions at 30 verses), pages of a signed-in user **with a verified email** use the `reader` key.
- Every edition shown in its own numbering: chapter requests use `numbering=edition`.
- Visitors: the first 30 verses of a capped chapter, then the notice and a sign-in button; no stepping verse by verse.
- Interlinear: side by side on wide screens and in landscape; stacked in portrait below the `md` breakpoint.
- UI locales `en, it, es, fr, de, pt`; English complete, others may start as copies of English (`npm run sync-messages`).
- Staging is `noindex` (robots + `X-Robots-Tag`).
- Env (server only): `BIBLEGET_API_URL` (e.g. `https://query.bibleget.io/dev`), `BIBLEGET_READ_KEY`, `BIBLEGET_READER_KEY`, `AUTH_ZITADEL_ISSUER`, `AUTH_ZITADEL_ID`, `AUTH_ZITADEL_SECRET`, `AUTH_SECRET`, `AUTH_URL`, `ZITADEL_PROJECT_ID`, `SITE_NOINDEX=1` on staging.

## Review Focus

1. A signed-in user whose email is **not** verified must get visitor pages (30 verses) and a prompt to verify. Test in Task 3 and Task 5.
2. A chapter with exactly 30 verses in a capped edition must **not** show the "sign in for the rest" notice; 31 must. Test in Task 2.
3. Psalms with a Vulgate-numbered main edition (`/read/VGCL/psalms/9?with=NABRE`) must pair VGCL 9:22–39 with NABRE Psalm 10, not leave them empty. Test in Task 6.
4. A book/chapter that does not exist in the edition (`/read/NABRE/matthew/29`, an unknown edition, a book the edition lacks such as Tobit in LUZZI) must give a 404 page, never a 500. Test in Task 4.
5. The endpoint unreachable or answering 5xx must show an error page with retry, not a blank chapter, and must not cache the failure. Test in Task 2.

---

### Task 1: Repository and skeleton

**Files:** Create the repository `BibleGet-I-O/website` (public, default branch `main`; ask the user before creating it) at `~/development/BibleGet-I-O/website`: `package.json`, `next.config.ts` (`output: "standalone"`), `tsconfig.json`, `eslint.config.mjs`, `postcss.config.mjs`, `vitest.config.ts`, `vitest.setup.ts`, `i18n/{routing,request,navigation}.ts`, `proxy.ts` (next-intl middleware), `messages/{en,it,es,fr,de,pt}.json`, `app/[locale]/layout.tsx`, `app/[locale]/page.tsx` (placeholder), `app/robots.ts`, `.github/workflows/ci.yml` (lint, typecheck, test, build), `CLAUDE.md`, `README.md`, `.env.example`, `docs/specs/2026-11-19-website-reader-design.md`.

**Interfaces:** Produces the `@/` path alias, `Link`/`redirect` from `@/i18n/navigation`, `routing.locales`.

- [ ] **Step 1:** Copy the Martyrology site's config files listed above, removing its unused dependencies (leaflet, MDX, scalar); `npm install`.
- [ ] **Step 2: Test** `app/__tests__/robots.test.ts`: with `SITE_NOINDEX=1`, `robots()` returns `{ rules: { userAgent: "*", disallow: "/" } }`; without it, `allow: "/"`. Run `npm test` → FAIL, implement `app/robots.ts` and the `X-Robots-Tag: noindex` header in `next.config.ts` when `SITE_NOINDEX=1`, → PASS.
- [ ] **Step 3:** `npm run lint && npx tsc --noEmit && npm run build` → success; `npm run dev` serves `/en`.
- [ ] **Step 4: Commit** `chore: Next.js skeleton modelled on martyrology-frontend`; push; CI green.

### Task 2: The endpoint client

**Files:** Create `lib/api.ts`, `lib/types.ts`; Test `lib/__tests__/api.test.ts` (mocked `fetch`).

**Interfaces (produces):**
- `type Access = "visitor" | "reader"`.
- `getEditions(): Promise<Edition[]>` from `/metadata/bibleversions` — `Edition = { sigla, fullname, year, language, copyrightHolder }`.
- `getIndex(sigla): Promise<EditionIndex>` from `/metadata/versionindex?versions=SIGLA` — books in order with `{ bookNum, name, abbrev, chapters: number, versesPerChapter: number[] }`; `slugFor(book)` / `bookBySlug(index, slug)` (slug = lowercased, diacritics stripped, spaces → `-`, from the edition's own book name).
- `getChapter({ sigla, bookAbbrev, chapter, access, tradition?, numbering? }): Promise<Chapter>` — `numbering?: "edition" | "hebrew"`, default `"edition"`; calls `/quote?query={abbrev}{chapter}&version={sigla}&numbering=edition&return=json[&text_tradition=…]`, and with `numbering: "hebrew"` the same request without `numbering=edition` (the chapter is then a Hebrew psalm number, which the endpoint maps to the edition's own); with header `X-Api-Key` = `BIBLEGET_READ_KEY` or `BIBLEGET_READER_KEY`; `Chapter = { rows: VerseRow[], truncated: boolean, expectedVerses: number }`; `VerseRow = { chapter, verse, label, printedChapter, text, titles: string[], psalmHebrew?: string }` (`label` from `verse_label`/`verseequiv`, `printedChapter` from `printed_chapter`).
- `truncated` = the last returned verse is lower than the chapter's verse count in the edition's index (`versesPerChapter`); never inferred from receiving 30 rows.
- Errors: `ApiUnavailable` (network/5xx) and `NotFound` classes. `NotFound` covers both ways the endpoint rejects a chapter: a 4xx `application/problem+json` (e.g. an unknown edition: `version=XXX` → 422 "No valid Bible versions were requested."), and a validation failure, which the endpoint reports as **HTTP 200** with an empty `results` array and a non-empty `errors` array (e.g. `query=Mt29&version=DRB` → 200, `"results":[]`, `"errors":[{"errNum":13,"errMessage":"A chapter in the query is out of bounds: there is no chapter <29> in the book in the requested version DRB, the last possible chapter is <28>"}]`); for a chapter page a 200 with errors and no results is a `NotFound`, never an empty chapter; `fetch` with `cache: "no-store"` for `reader`, `next: { revalidate: 3600 }` for `visitor`.

- [ ] **Step 1: Tests:** `usesTheReadKeyForVisitorsAndTheReaderKeyForReaders` (header check); `requestsEditionNumbering` (URL contains `numbering=edition`); `marksA48VerseChapterCappedAt30AsTruncated`; `doesNotMarkAnExactly30VerseChapterAsTruncated`; `mapsPrintedChapterAndLabel`; `throwsApiUnavailableOn503AndNetworkError`; `throwsNotFoundOnA4xxProblemResponse`; `throwsNotFoundOnA200WithErrorsAndNoResults` (the out-of-bounds chapter body above); `requestsHebrewNumberingWithoutTheEditionParameter` (`numbering: "hebrew"` → URL lacks `numbering=`); `slugForStripsDiacritics` (`"Génesis"` → `"genesis"`).
- [ ] **Step 2:** `npm test lib/__tests__/api.test.ts` → FAIL. **Step 3:** implement. **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat: server-side client for the BibleGet endpoint`.

### Task 3: Sign-in

**Files:** Create `auth.ts`, `lib/roles.ts`, `lib/session-token.ts` (from the Martyrology site), `app/api/auth/[...nextauth]/route.ts`, `lib/access.ts`, `components/SignInButton.tsx`; Test `lib/__tests__/auth-callbacks.test.ts`, `lib/__tests__/access.test.ts`.

**Interfaces (produces):**
- Zitadel provider with scopes `openid email profile offline_access`.
- The next-auth JWT, stored only in its encrypted HttpOnly session cookie and read only by server code, holds `access_token`, `refresh_token`, `expires_at`, `emailVerified: boolean` (from the ID-token `profile.email_verified` at sign-in), `admin: boolean` (`bibleget_admin` in `urn:zitadel:iam:org:project:roles`).
- Session exposes only `{ user, emailVerified: boolean, admin: boolean, error? }`.
- `getAccess(): Promise<{ access: Access; signedIn: boolean; emailVerified: boolean }>` — `reader` only when signed in **and** `emailVerified`.
- `getAccessToken(): Promise<string | null>` — the only reader of the access token, called from server code (server actions, route handlers, server components) only; refresh as the Martyrology site's `lib/session-token.ts`. Its value is never returned to a client component or written into a response body.

- [ ] **Step 1: Tests:** `sessionNeverCarriesTheAccessToken` (port the Martyrology test); `jwtStoresEmailVerifiedAndAdminFromTheProfile`; `unverifiedUserGetsVisitorAccess`; `verifiedUserGetsReaderAccess`; `noSessionGetsVisitorAccess`.
- [ ] **Step 2:** → FAIL. **Step 3:** implement. **Step 4:** → PASS.
- [ ] **Step 5: Manual check** against the local stack: endpoint `scripts/setup-zitadel-local.sh`, copy `.stack-out/bibleget-website.env` values into `.env.local`; sign in as `dev@bibleget.localhost` on `http://localhost:3000`; the header shows the user.
- [ ] **Step 6: Commit** `feat: Zitadel sign-in; reader access needs a verified email`.

### Task 4: Editions and books

**Files:** Create `app/[locale]/page.tsx` (editions grouped by language: name, year, rights holder), `app/[locale]/read/[edition]/page.tsx` (books grouped OT/NT with chapter links), `app/[locale]/not-found.tsx`, `components/EditionCard.tsx`, messages keys `Home.*`, `Books.*`; Test `app/__tests__/pages.test.tsx`.

- [ ] **Step 1: Tests:** `homeListsTheSevenEditionsByLanguage` (mock `getEditions`); `booksPageUsesTheEditionsOwnNames` (the names rendered are the mocked index's names, e.g. a DRB index's "Apocalypse"); `unknownEditionIs404`; `bookTheEditionLacksIs404` (Tobit in a mocked LUZZI index).
- [ ] **Step 2–4:** FAIL → implement (`notFound()` from `next/navigation`) → PASS.
- [ ] **Step 5: Commit** `feat: editions and books pages`.

### Task 5: The chapter page (book view)

**Files:** Create `app/[locale]/read/[edition]/[book]/[chapter]/page.tsx`, `components/ReaderBar.tsx` (edition/book/chapter dropdowns, interlinear toggle and second-edition dropdown, Greek/Hebrew choice for Esther), `components/BookPage.tsx`, `components/ChapterNav.tsx`, `components/CappedNotice.tsx`, `lib/placement.ts`, `app/[locale]/read/[edition]/[book]/[chapter]/error.tsx`; Test `components/__tests__/BookPage.test.tsx`, `lib/__tests__/placement.test.ts`, `components/__tests__/ChapterNav.test.tsx`.

**Interfaces:**
- `placeAsPrinted(current: VerseRow[], nextFirst: VerseRow[], chapter: number): VerseRow[]` — rows whose `printedChapter === chapter` from both requests, in printed order; rows of `current` printed in another chapter are dropped (they appear in that chapter's page).
- `neighbours(index, bookNum, chapter): { prev?: {slug, chapter}, next?: {slug, chapter} }` — crosses books.
- Query parameters: `with` (second edition sigla), `tradition` (`GREEK|HEBREW`, Esther only).

- [ ] **Step 1: Tests:** `rendersHeadingsWhereTheyFallAndSuperscriptVerseNumbers` (titles before the verse that carries them; `<sup>` with the printed `label`); `showsThePrintedLabelOfAVersePrintedInThePreviousChapter` (`placeAsPrinted` puts NABRE Wis 12:1 at the end of 11, labelled `12:1`, and removes it from 12); `cappedChapterShowsTheNoticeAndSignInButton` (visitor on a truncated chapter → sign-in notice and button; not truncated → none); `unverifiedUserSeesTheVerifyPrompt` (signed in, email not verified, truncated → the verify-email notice instead of the sign-in button); `fullTextOffForTheEditionShowsTheUnavailableNoticeWithoutSignIn` (signed-in verified reader, truncated because the admin switched the edition's full text off → a separate "full text not available for this edition" notice, no sign-in button); `neighboursCrossBooks` (Matthew 28 → Mark 1; Genesis 1 has no prev); `estherOffersGreekAndHebrewOnlyForEditionsWithBoth`; `errorBoundaryOffersRetry` (on `ApiUnavailable`).
- [ ] **Step 2–4:** FAIL → implement (page fetches chapter N and, when N is not the book's last, verse 1 of N+1, then `placeAsPrinted`) → PASS.
- [ ] **Step 5: Manual check** on localhost: NABRE Wis 11 ends with 12:1; a 48-verse NABRE chapter as visitor shows 30 + notice, as `dev@bibleget.localhost` (verified, with `reader_full_text` on locally) shows 48.
- [ ] **Step 6: Commit** `feat: chapter page in book view`.

### Task 6: Interlinear

**Files:** Create `lib/parallel.ts` (adapted), `components/Spread.tsx` (adapted), `components/VerseLines.tsx`, `components/GapNote.tsx`; Modify the chapter page; Test `lib/__tests__/parallel.test.ts`, `components/__tests__/Spread.test.tsx`.

**Interfaces:**
- `alignKey(row: VerseRow, book: number): string` — Psalms (book 23): `row.psalmHebrew`; otherwise `` `${row.chapter}:${row.verse}` ``.
- `buildRows(a: VerseRow[], b: VerseRow[], book: number): Array<{ key: string; a?: VerseRow; b?: VerseRow }>` — order of `a`, rows of `b` matched by `alignKey`; unmatched `b` rows inserted after their preceding matched key.
- `pairingMode(aIndex, bIndex, book, chapter): "aligned" | "whole-chapter"` — `"whole-chapter"` when the book is not Psalms and the two editions' verse counts for this chapter differ (covers every `chapter-boundary`/`chapter-count` case in the endpoint's `tests/fixtures/versification/cases.json` without copying it); Psalms are always `"aligned"`.
- For a Vulgate-numbered main edition, the second edition is fetched for the Hebrew psalm(s) its rows report (distinct chapters of `psalmHebrew`, in order: VGCL 9 → Hebrew 9 and 10); for a Hebrew-numbered main edition with a Vulgate-numbered second edition (VGCL, DRB), the second is fetched with `getChapter({ …, chapter: <Hebrew number>, numbering: "hebrew" })`, so the request omits `numbering=edition` and the endpoint maps the Hebrew psalm to the edition's own numbering.

- [ ] **Step 1: Tests:** `alignsPsalmsByHebrewReference` (VGCL 9:22 row pairs with NABRE 10:1); `vulgateMainEditionFetchesBothHebrewPsalms` (VGCL 9 → requests for Ps 9 and Ps 10); `hebrewMainEditionFetchesTheVulgateSecondWithHebrewNumbering` (NABRE Ps 10 with VGCL → VGCL requested as Ps 10 with `numbering: "hebrew"`, no `numbering=edition` in the URL); `missingVerseLeavesAGapNote` (NABRE Ps 2 vs CEI2008 Ps 2 → row 12 has only `b`, rendered with the "absent" note); `differentVerseCountsOutsidePsalmsUseWholeChapter` (Joel 3 NABRE vs VGCL); `equalCountsAlign` (Mt 5 DRB vs VGCL); `layoutIsSideBySideFromMdOrInLandscapeAndStackedInPortrait` (class names `grid grid-cols-1 md:grid-cols-2 landscape:grid-cols-2` on the spread).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Manual check:** `/en/read/drb/matthew/5?with=VGCL`, `/en/read/vgcl/psalms/9?with=NABRE`, `/en/read/nabre/joel/3?with=VGCL` on a desktop width, a phone-portrait and a phone-landscape viewport.
- [ ] **Step 6: Commit** `feat: interlinear spread with aligned verses`.

### Task 7: The admin page

**Files:** Create `app/[locale]/admin/editions/page.tsx`, `app/[locale]/admin/editions/actions.ts` (server action), `components/FullTextSwitch.tsx`; Test `app/__tests__/admin.test.tsx`.

**Interfaces:** Consumes `GET /admin/versions`, `PATCH /admin/versions/{sigla}` (endpoint Task 2) with `Authorization: Bearer <getAccessToken()>`. `setFullText(sigla: string, on: boolean): Promise<{ ok: true } | { ok: false; status: number }>`.

- [ ] **Step 1: Tests:** `nonAdminGets404` (session `admin: false` → `notFound()`); `listsEditionsWithCopyrightAndSwitchState`; `switchCallsPatchWithTheAccessTokenAndRevalidates`; `forbiddenFromTheEndpointShowsAnError` (endpoint 403 → message, switch reverts).
- [ ] **Step 2–4:** FAIL → implement (the header shows an "Admin" link only when `session.admin`) → PASS.
- [ ] **Step 5: Manual check** with root (granted `bibleget_admin` locally): toggle NABRE off → visitor and signed-in both see 30 verses, the visitor with the sign-in notice, the signed-in verified reader with the "full text not available for this edition" notice and no sign-in button; back on → signed-in sees 48 and no notice.
- [ ] **Step 6: Commit** `feat: admin switches for each edition's full text`.

### Task 8: Staging sign-in app (cdcf-infra)

**Files (repo `CatholicOS/cdcf-infra`):** Modify `auth/setup-zitadel.sh` (`BIBLEGET_WEBSITE_URLS` for staging = `https://staging.bibleget.io`; on staging the app name `BibleGet Website (Staging)`; devMode false), `auth/setup-zitadel.selftest.sh`, `auth/handoffs/bibleget.md`.

- [ ] **Step 1: Selftest cases:** staging no longer skips `--provision-bibleget-website`; it registers `https://staging.bibleget.io/api/auth/callback/zitadel`, looks the app up by the staging name, sends `devMode: false`; production still skips.
- [ ] **Step 2:** → FAIL; implement; → PASS (`auth/setup-zitadel.selftest.sh`).
- [ ] **Step 3:** PR, CI, merge.
- [ ] **Step 4 (user's go-ahead):** on the VPS, `sudo ./setup-zitadel.sh --target staging --provision-bibleget-website`; the client id and one-time secret go straight into the site's server environment (never printed into the conversation).

### Task 9: Deploy to staging.bibleget.io

**Files:** Create `.github/workflows/deploy.yml` (from the Martyrology site's: standalone build, tarball over SSH, `tmp/restart.txt`), `docs/deploy.md`.

- [ ] **Step 1 (user's go-ahead, Plesk):** create the `staging.bibleget.io` subdomain in the bibleget.io subscription, Let's Encrypt certificate, Node.js app (same Node version as the workflow), application root as the workflow expects; set the env listed in Global Constraints in the Plesk Node panel (`BIBLEGET_API_URL=https://query.bibleget.io/dev`, `SITE_NOINDEX=1`, the two keys from endpoint Task 5, the Zitadel values from Task 8).
- [ ] **Step 2:** repo secrets/variables as the Martyrology workflow (`VPS_SSH_KEY`, host, path); run the workflow.
- [ ] **Step 3: Verify:** `curl -sI https://staging.bibleget.io/en` → 200 with `X-Robots-Tag: noindex`; as a visitor NABRE Mt 5 shows 30 verses and the notice; signed in (verified) shows 48; `/en/read/vgcl/psalms/22?with=NABRE` aligns; the admin switch works for the admin account.
- [ ] **Step 4: Commit** the workflow and docs; update the endpoint `CLAUDE.md` "Live infrastructure" with the site's path.
