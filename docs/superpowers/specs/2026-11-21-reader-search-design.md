# Reader search: a search lens, a results page, keyword autocomplete

**Repos:** `endpoint` (search cap, word list, `/search/suggest`) and `website` (lens, form, results page).
**Status:** design.

## Intent

Readers of the website want to find passages without leaving the reader: a word they remember ("light"), or an idea
they can only describe ("God forgives those who repent"). The `dev` endpoint already has keyword, semantic and hybrid
search; the reader has no way to use them. A magnifying-glass button in the reader bar opens a search over the edition
on screen, and the results link back into the reader.

Search must not become a way around the copyright cap the reader enforces: today every search route returns every
match in full, so a visitor could read most of NABRE or CEI 2008 through keyword searches. Search gets the reader's
rule: visitors see at most 30 verses of a copyrighted edition and are told to sign in for the rest.

## Goals

- A search button in the reader bar, searching the edition shown (the first edition when comparing).
- Three modes: **Keyword** (a one-line input with autocomplete), **Semantic** and **Hybrid** (a multi-line,
  chat-style text area for a question or description in plain language).
- A results page with shareable URLs; each result links to its verse in the reader.
- Keyword autocomplete: as the reader types, a dropdown of up to 8 words of that edition that start with what was
  typed, most frequent first.
- The 30-verse copyright cap on every search route, by the same rule as quotes, with the total number of matches
  reported so the site can say "30 of 252".

## Non-goals

- Searching several editions at once, or all editions.
- A conversation: the semantic field is a larger input for one question, not a chat with replies.
- "Similar passages" (`/search/similar`): it starts from a verse, so it belongs to a later verse action, not the
  search form. It still gets the cap (below).
- Boolean keyword syntax in the UI (the endpoint keeps `match=boolean` for API users).
- Highlighting matches inside semantic results.

## Anti-goals

- Any search route serving more than 30 verses of a copyrighted edition to a credential that may not read it whole.
- An API key reaching the browser (the site's `read` and `reader` keys stay server-only, as now).
- Suggestions that lead to a refused search (a stop word, a word shorter than the keyword minimum).

## Approaches considered

1. **Results page (chosen).** The lens opens a small form; submitting goes to
   `/{locale}/read/{edition}/search?mode=…&q=…`, rendered on the server with the visitor's or reader's key, like the
   chapter pages. Shareable and bookmarkable, works without JavaScript, keys stay on the server; autocomplete goes
   through one small site route.
2. **Panel over the chapter.** Results in a slide-over panel, fetched from the browser through a site route. The reader
   never leaves the chapter, but searches can't be shared, it needs more client state, and it's harder to make
   accessible.

The user chose 1.

## Endpoint

### 1. The copyright cap on search

The rule is the quote route's (`QuoteContext::fullTextAllowed` / `copyrightLimitedVersions`): an edition in
`versions_available` with `copyright = 1` is **capped** for a request unless the request's credential has scope
`reader` **and** the edition is flagged `reader_full_text`. The rule should live in one place both the quote pipeline
and the search handlers use (the builder decides the shape: a small policy class taking the credential and the
`versions_available` rows is the expected one).

- Applies to `/search` (legacy shape), `/search/keyword`, `/search/semantic`, `/search/hybrid`, `/search/similar`, for
  every edition in the request (`version` may list several).
- A capped edition contributes at most **30** results: for keyword search the first 30 in canonical (Bible) order, for
  semantic / hybrid / similar the first 30 in rank order (they already return at most `limit` ≤ 100).
- The response says when it capped: `info.CAPPED` is an object from sigla to the number of matches before capping,
  present only for editions that were capped, e.g. `"CAPPED": {"NABRE": 252}`. For semantic / hybrid / similar the
  number is the count the route would have returned without the cap (at most `limit`). Absent when nothing was capped.
  XML and HTML responses carry the same information in their own shape (the builder follows how `info` is already
  rendered there).
- Responses stay `private` when a credential is present (already the case), since the body depends on the scope.
- `openapi.json` documents the cap and `info.CAPPED` on each search route.

### 2. Paging keyword results

A common word matches thousands of verses (and a signed-in reader, or anyone on a public-domain edition, gets them
all), so `/search/keyword` learns to page:

- Optional `limit` (1–100) and `offset` (≥ 0). Without `limit` the route behaves as now (every match, canonical
  order). With it, it returns the window `offset … offset+limit-1` of the canonical order.
- With `limit`, `info.TOTAL` is the number of matches this caller may see (after the cap). For a capped edition the
  window is clipped to the first 30: an offset of 30 or more returns no results. `info.CAPPED` is unchanged (matches
  before the cap).
- `limit`/`offset` apply to a single `version`; with several, `limit` is refused, as the canonical merge across
  editions would make the window ambiguous. Parameter errors on the search routes answer 422
  (`ValidationException`), as they already do; this design's "400" means that.
- `openapi.json` documents both.

### 3. The word list and `/search/suggest`

Suggestions come from a precomputed list of each edition's words; computing them per keystroke would be too slow.

- **Migration 058** creates `search_vocabulary`: `sigla text`, `word text` (the word as printed, lowercased),
  `folded text` (lowercased and unaccented, the form a prefix is matched against), `freq integer` (verses containing
  it), primary key `(sigla, word)`, and an index on `(sigla, folded text_pattern_ops, freq DESC)` (or equivalent) for
  prefix lookups.
- It is filled from each edition's `text_plain` by a SQL function `refresh_search_vocabulary(sigla text)` the migration
  creates and calls for every edition in `versions_available`, **if** filling all editions fits a deploy: time it on
  the test database first (the deploy's migrate call has a 600 s timeout and runs in one transaction). If it doesn't
  fit comfortably (over ~60 s), the migration only creates the table and the function, and the filling becomes a
  separate script run once after the deploy. A future edition import calls it, and so does any later migration that
  changes an edition's `text_plain` (a text correction like 055); no trigger.
- Words are split on anything that is not a letter (apostrophes split: "l'uomo" → "l", "uomo"); words shorter than the
  keyword search's minimum length are left out, and so is any word the edition's own text search configuration
  (`bibleget_<ts_language>`) turns into nothing (a stop word), so every suggestion is a search that runs.
- **`GET /search/suggest?prefix=…&version=…&limit=…`**: `version` one sigla; `prefix` at least 2 letters, matched
  folded and case-insensitively against `folded`; `limit` default 8, maximum 20. Answers
  `{"suggestions": [{"word": "light", "count": 252}, …], "info": {...}}` ordered by `freq` descending, then
  alphabetically. A data route: it needs a credential like the other search routes, and gets cache headers. Unknown
  `version` → 422, as on the other search routes (`ValidationException`); bad `prefix` / `limit` → 422 too.
- Words are not verses: suggestions are not capped.
- `openapi.json` documents the route.

## Website

### The lens and the form

- A magnifying-glass button (`aria-label` "Search") in `ReaderBar`, opening a small popover form; Escape and a click
  outside close it, focus returns to the button.
- Mode tabs: **Keyword** (default), **Semantic**, **Hybrid**.
  - Keyword: a single-line input with autocomplete (an ARIA 1.2 combobox: arrow keys move through suggestions, Enter
    picks one or submits, Escape closes the list). Suggestions complete the word being typed (the last word of the
    input; picking one replaces only that word), fetched after 200 ms without typing, from 2 letters on, at most 8;
    they are the same with "Exact words" checked. An "Exact words" checkbox maps to `match=exact`; otherwise
    `match=fulltext`.
  - Semantic and Hybrid: a multi-line text area (Enter submits, Shift+Enter adds a line), at most 500 characters.
- The form is a plain GET form to the results page, so it works without JavaScript (without suggestions).
- The edition searched is the one on screen; when comparing, the first one. Its name is shown in the form.

### Autocomplete route

- `GET /api/search/suggest?edition=…&prefix=…` (a route handler: `/api` is outside the locale routing) calls the
  endpoint's `/search/suggest` server-side with the `read` key (suggestions don't depend on who is reading) and returns
  only `{"suggestions": [...]}`. It validates `edition` against the editions list and `prefix` (2–40 characters, the
  last word of the input) before calling, and sends `cache-control: public, max-age=3600`.

### Per-visitor limits

The site's `read` key is exempt from the API's rate limits, so the site protects the two costly calls itself, per
client IP, in memory (one Node process on the server; a restart resets the counts):

- `/api/search/suggest`: 60 requests a minute; beyond that 429 with `Retry-After`, and the combobox simply shows no
  suggestions.
- Semantic and hybrid searches on the results page: 10 a minute; beyond that the page says "Too many searches; try
  again in a minute." and offers the keyword search.
- Keyword searches are not limited, like chapters.

The client IP is the first `X-Forwarded-For` address set by the server's proxy (the builder confirms what Plesk /
Passenger sends); the limiter is a small module with its own tests.

### The results page

`/{locale}/read/{edition}/search?mode=keyword|semantic|hybrid&q=…[&match=exact][&page=N]`, server-rendered.

- Calls the matching search route with the visitor's `read` key or the signed-in reader's `reader` key (the existing
  access logic), with `limit` 30 for semantic and hybrid, and for keyword `limit=50&offset=(page-1)*50`. At
  `limit` 30 a semantic or hybrid search gives every reader the same 30 results, so it is never capped and shows no
  notice: only keyword search, which pages through every match, holds results back from visitors.
- Keyword results come in pages of 50 (`&page=N` in the URL), with Previous / Next and "51–100 of 1,234"; for a capped
  edition there is a single page of at most 30.
- Shows the question, the mode, the edition and the number of results; then the results in the order the API gives:
  the reference (book name in the edition's language, chapter:verse) as a link to the verse in the reader, and the
  verse text. For keyword search the matched words are emphasised (`<mark>`), compared case- and accent-insensitively
  on whole words.
- **Capped:** when `info.CAPPED` names the edition, a line "30 of 252 results" and the existing `CappedNotice`, with the
  kind from `noticeFor` (sign in / verify the email / not available) and `redirectTo` the results page itself, so
  signing in comes back to the full results.
- **Errors:**
  - Keyword refused (too short, or only common words; the API answers 422): "Try a longer or less common word."
  - Semantic or hybrid unavailable (the API answers 503): "Semantic search is not available right now; try a keyword
    search." with a link to the same text as a keyword search.
  - No results: "No results for …".
  - The API unreachable: the site's existing error handling.
- The page links back to the chapter the reader came from when it knows it (a `from` parameter set by the form), else to
  the edition's books page.
- The page sets `noindex` (search pages should not be indexed, staging or not).

### Verses as link targets

Each verse line in the reader gets an `id` (`v{chapter}.{verse}`, plus the letter for lettered verses, e.g. `v1.1a`: the
chapter is part of it because a page also prints a neighbour chapter's verse) so a result can link to
`/{locale}/read/{edition}/{book}/{chapter}#v3.16`; the targeted verse is briefly highlighted (`:target`), and the
page-turn wrapper does not interfere with the scroll.

### Strings

Every new English string goes into `messages/en.json`, then `npm run sync-messages`, then is translated by hand in
it/fr/de/es/pt.

## Constraints

- PHP 8.4+, PHPStan level 10, PHPCS, PHPUnit as in the endpoint; Doctrine migration (`migrations/058-….sql` plus
  `src/Migrations/Version058….php`, no BEGIN/COMMIT, idempotent).
- Website: Next.js 16, next-intl, Vitest; keys server-only (`import "server-only"`).
- The cap must hold for every credential type, not only the website's keys.

## Left to the builder

- The shape of the shared copyright policy, and how XML/HTML show `info.CAPPED`.
- The exact SQL of the word split and the vocabulary index.
- The popover's look, within the reader bar's existing style; the icon (an inline SVG).
- Whether the results page reuses `VerseLines` or a simpler list.

## Decisions

Decided by the user:
- Results page (not a panel); search the edition on screen, the first when comparing.
- Visitors get at most 30 verses of a copyrighted edition and a notice to sign in; signed-in readers get everything,
  as in the reader.
- Keyword results in pages of 50 (the endpoint gains `limit`/`offset`).
- Per-visitor limits on the site for suggestions and semantic/hybrid searches.

Calls made in the design (open to correction):
- `info.CAPPED` (sigla → matches before the cap) and `info.TOTAL` (matches visible) as the way the API reports it.
- An unknown edition on `/search/suggest` answers 422, like the other search routes.
- Suggestions complete the last word typed and ignore "Exact words".
- The word list is filled by the migration only if that fits a deploy; otherwise by a one-off script. No trigger:
  migrations that change an edition's text refresh its list.
- The specific limits (60 suggestions, 10 semantic/hybrid searches a minute per IP).
- `/search/similar` gets the cap but no place in the form.
