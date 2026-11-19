# BibleGet website, first release: the Bible reader

Date: 2026-11-19. Status: draft for approval.

## Intent

The v4 plan replaces the WordPress site at bibleget.io with a Next.js site
(see `2026-11-17-api-keys-installations-design.md`, "The new frontend"). Its
first release is the **Bible reader**, deployed to `staging.bibleget.io` after
testing against the local stack.

Why the reader first (decided 2026-11-19): it is what the site is for, and a
complete reader of every edition is what will be shown to the rights holders
(USCCB for NABRE, the CEI's foundation for CEI2008, the BLPD publishers, the
Holy See for the Nova Vulgata) to ask for permission to publish their text in
full. So the staging site shows every edition whole to signed-in users, and
the admin can withdraw any edition with one switch.

## Goals

1. Read any edition chapter by chapter, in each edition's own book names,
   chapter and verse numbering (the Psalms included).
2. Show a second edition alongside ("interlinear": edition by edition, verse by
   verse; there is no word-by-word original-language data).
3. Signed-in users read whole chapters of every edition whose full text the
   admin has switched on; visitors read at most 30 verses of a copyrighted
   edition's chapter.
4. The admin switches an edition's full text on or off from the site.
5. Runs locally against the endpoint's Docker stack and local Zitadel, and is
   deployed to `staging.bibleget.io` by a workflow.

## Non-goals (this release)

Developer pages (projects, keys), API docs, the blog (headless WordPress),
indices of persons/places/events, search, footnotes (the data has none),
replacing `www.bibleget.io`. Each is its own later design.

## Anti-goals

- Full copyrighted text reachable without signing in, or indexable by search
  engines.
- The site deciding copyright on its own: the API stays the one place that
  decides what text a request gets.
- An API key in the browser.
- A reader that renumbers editions: every edition is shown with its own
  numbering, as printed.

## Decisions (from the conversation)

### Reading view (default): the book page

One centered column of running text, as in a printed Bible: the chapter title,
the edition's section headings (`title1`–`title3`) where they fall, verse
numbers as small superscripts. A top bar with three dropdowns (edition, book,
chapter) and sign-in; previous / next chapter at the edges, crossing book
boundaries (Matthew 28 → Mark 1).

### Interlinear on: verse per line, side by side

A toggle and a second-edition dropdown in the top bar. When on, the layout
switches to one verse per line with its number in the margin, and the two
editions sit **side by side as facing pages**, rows aligned by verse, on
desktops and on phones in landscape; **stacked** (the second edition's verse
under each verse) on phones in portrait. This follows the Roman Martyrology
site (`CatholicOS/martyrology-frontend`: `components/Spread.tsx`,
`lib/parallel.ts`), whose facing-page spread and row alignment are the model.

Alignment is by the reference: the second edition is queried with the same
chapter reference, and the endpoint's Psalm mapping (`PsalmNumbering`) returns
the matching psalm in a Vulgate-numbered edition (NABRE Ps 23 beside VGCL
Ps 22). A verse one edition does not have (NABRE Ps 2:12, the NVBSE psalm
verses printed inside the verse before: see the wiki's *Psalms Numbering*,
from #217) gets a short note on the empty side, as the Martyrology site's gap
notes do, not a blank. Day one has only the "absent" kind of note (my call,
see below).

**Chapters the editions divide differently** (my call). Outside the Psalms,
some books have different chapter boundaries between the two numbering
families (Joel: 4 chapters in NABRE/CEI2008/NVBSE/BLPD, 3 in VGCL/DRB/LUZZI;
Malachi 3 vs 4; the full list is the `chapter-boundary` cases in
`tests/fixtures/versification/cases.json` and the wiki's *Versification
Differences*). Pairing those chapters by number would line up different
text under the same verse numbers. On day one such a pairing shows the
second edition's chapter whole, below, with a notice that the two editions
divide this book's chapters differently; verse-level alignment across
chapter boundaries comes with the wiki's *Verse Rendering Modes* steps 3–4.

**A Vulgate-numbered main edition** (my call). Reader URLs use the main
edition's own numbering (`/read/VGCL/psalms/22` is VGCL's Psalm 22). The API
reads a Psalm number as Hebrew today, so the endpoint gains a way to address
an edition by its own chapter and verse (endpoint change 5), and its psalm
results report the Hebrew reference of each row, which the site uses to fetch
the interlinear edition. The site keeps no psalm table of its own: the
mapping stays in `PsalmNumbering`. A Vulgate psalm that spans two Hebrew ones
(VGCL 9 = Hebrew 9–10, VGCL 113 = 114–115) pairs with both, in order.

**As printed** (my call, after the memory rule that printed placements are
not defects). A verse an edition prints under another chapter is shown where
it is printed, with its printed label: NABRE Wis 12:1 at the end of Wis 11,
labelled 12:1; the first words of Job 10:1 at the end of Job 9. The rows
already carry `printed_chapter` and `verse_label` (printed-label queries,
2026-11-15), so the site requests the chapter together with the first verse
of the next one and places each row by `printed_chapter`; no endpoint change.

**Esther** (my call). For editions that store both Esther texts (CEI2008,
NABRE, NVBSE, BLPD) the top bar shows a Greek / Hebrew choice, Greek by
default as the API's `text_tradition` default, kept in the URL
(`?tradition=HEBREW`). NABRE's additions A–F show their printed labels
(`verse_label`). VGCL and DRB show chapters 11–16 as their own chapters. In
interlinear, the second edition uses the same tradition where it has it.

### Who sees how much

- **Signed in:** whole chapters of every edition with `reader_full_text = true`.
- **Visitor:** the first 30 verses of a copyrighted edition's chapter, then a
  notice that the rest is available after signing in (with the sign-in
  button). No stepping verse by verse (decided: an account is easy to create,
  and it keeps bots off). Public-domain editions are shown whole to everyone.
- On staging, `reader_full_text` is set true for all seven editions (decided:
  something to show the rights holders), and the admin switch turns any of
  them off.

## Approaches considered

- **A (chosen): a new repository `BibleGet-I-O/website`, built like the
  Martyrology site.** Next.js 16 (App Router), next-auth v5 with the Zitadel
  provider, next-intl, Tailwind 4, Vitest; the reader components adapted from
  the Martyrology site (days → chapters). The site's server calls the endpoint;
  keys stay server-side.
- B: fork `martyrology-frontend` and strip it. Faster to a first page, but it
  brings the review tools, maps and data pipeline to remove.
- C: a static site calling the API from the browser. Cannot keep a key secret
  or sign users in.

## How the copyright rule stays in the API

The endpoint already decides: a `reader`-scope credential gets whole chapters
of editions flagged `reader_full_text`; every other credential gets at most 30
verses of a copyrighted edition per request (`QuoteContext`,
`copyrightLimitedVersions()`). The site holds **two keys** of the system
application "BibleGet website" (`scripts/mint-reader-key.php` upserts it):

| Request from | Key | API returns |
| --- | --- | --- |
| a signed-in user's page | `reader` scope, no rate limit | whole chapter where `reader_full_text` |
| a visitor's page | `read` scope, no rate limit (`rate_limit_per_hour` NULL) | ≤ 30 verses of a copyrighted edition |

The site picks the key from the session on its server, never from anything the
browser sends. The API truncates an over-long copyrighted request to its first
30 verses without a notice, so the site compares the last verse returned with
the chapter's verse count from `/metadata/versionindex` to decide whether to
show the "sign in for the rest" notice.

## Endpoint changes (in this repository)

1. **Admin switch:** `GET /admin/versions` (each edition with `copyright` and
   `reader_full_text`) and `PATCH /admin/versions/{sigla}`
   `{"reader_full_text": bool}`, behind the `bibleget_admin` role like the other
   `/admin/*` routes, with tests and `openapi.json`.
2. **`/quote` without vectors:** results stop carrying `embedding_labse` /
   `embedding` (several KB per verse today; nothing reads them).
3. **The visitor key:** `scripts/mint-reader-key.php` (or a sibling) can also
   mint the `read`-scope key with no rate limit on the same system application.
4. **Native numbering:** a request parameter that reads chapter and verse in
   the edition's own numbering (no Hebrew-first Psalm mapping), and, on psalm
   results, each row's Hebrew reference (from `PsalmNumbering`). Applies to
   `/quote`; documented in `openapi.json`.
5. On staging (operations, not code): set `reader_full_text = true` for all
   seven editions, mint both keys, put them in the site's server environment.

## Sign-in

Anyone may create an account (decided 2026-11-19: "it's easy enough to create
an account and login"), so on staging the full text of every switched-on
edition is open to any signed-in user; turning an edition off is the control.
Full chapters also need a verified email (my call: the same rule as the
developer pages, and a cheap barrier to throwaway sign-ups); an unverified
user sees what a visitor sees, with a prompt to verify.

next-auth v5 with Zitadel, as the Martyrology and CDCF sites. The `BibleGet
Website` app already exists on local (cdcf-infra `--provision-bibleget-website`,
client_secret_post, JWT access tokens, role assertion). For staging, cdcf-infra
gets the staging origin `https://staging.bibleget.io` in `BIBLEGET_WEBSITE_URLS`
under a **separate staging app name** (staging and production share the
instance, and a run replaces an app's redirect URIs). The session keeps the
access token server-side; the admin page calls `/admin/versions` with it; the
UI shows the admin link only when the token's roles include `bibleget_admin`
(the endpoint enforces it regardless).

## Site structure

- `/{locale}` — the editions, grouped by language, with year and rights holder
  (from `/metadata/bibleversions`).
- `/{locale}/read/{edition}` — the edition's books (its own names, from
  `/metadata/biblebooks` and `/metadata/versionindex`).
- `/{locale}/read/{edition}/{book}/{chapter}` — the chapter; the interlinear
  edition in a query parameter (`?with=VGCL`), so a pairing can be linked.
- `/{locale}/admin/editions` — the full-text switches (admins only).
- UI languages: en, it, es, fr, de, pt, as the sister sites (builder's call
  which are complete on day one; English first is fine). Text is always in the
  edition's own language.
- Pages are rendered on the server; chapter pages for visitors may be cached,
  signed-in pages are not. `robots`: staging is `noindex`.

## Hosting and deployment

As the Martyrology site: a Next.js standalone build served by the Plesk Node.js
extension on the VPS, under a `staging.bibleget.io` subdomain of the
bibleget.io subscription, deployed by a manual GitHub workflow over SSH. The
API base URL is the staging endpoint `https://query.bibleget.io/dev/`
(server-side). Locally: the endpoint's Docker stack and local Zitadel
(`scripts/setup-zitadel-local.sh` writes the website app's credentials to
`.stack-out/bibleget-website.env`), the site on `http://localhost:3000`.

## Relation to the wiki's *Verse Rendering Modes*

Its step 5 planned the reader as a script reading the database, because the
30-verse limit ruled out the API. The `reader` key (decided 2026-11-17)
removed that reason, so this design replaces step 5's method; the wiki is
updated to say so. Steps 3–4 (alignment to the Nova Vulgata, curator notes
such as "NABRE prints this at the end of Wis 11") stay later work; the
"moved / elsewhere" gap notes of the Martyrology site wait for them.

## Left to the builder

Component structure, styling within the sister sites' look, caching details,
how the edition/book/chapter dropdowns behave on phones, test layout, which
Martyrology code to copy versus rewrite, the repo's CI.
