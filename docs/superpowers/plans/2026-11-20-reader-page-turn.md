# Reader Page Turn Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the chapter page, a horizontal swipe turns to the next or previous chapter with a StPageFlip-style
page curl that follows the finger, and the Previous / Next links play the same turn.

**Architecture:** Two pure modules (fold geometry; gesture decisions) carry the logic and the unit tests. A client
component `PageTurn` wraps the chapter page below the reader bar, listens to touches, clips itself to the part of the
page that stays and draws the flap and the fold shadow in a fixed overlay, then navigates with the locale-keeping
router. `ChapterNav` keeps computing the two targets; its links become `TurnLink`s that ask `PageTurn` to play the
turn.

**Tech Stack:** Next.js 16 App Router, React 19, TypeScript, next-intl 4, Tailwind 4 + a CSS module, Vitest 5 +
Testing Library (jsdom).

**Spec:** `endpoint/docs/superpowers/specs/2026-11-20-reader-page-turn-design.md` (read it; the work is in the
`website/` repo, branch `feat/reader-page-turn` from `main`).

## Global Constraints

- Swipe left → next chapter, swipe right → previous; targets exactly the hrefs `ChapterNav` builds (same `query` /
  `leavingQuery` rule).
- A swipe: at least **50px** sideways and sideways > **1.5×** vertical. Axis decided once the finger has moved
  **10px**. A touch starting within **24px** of the viewport's left or right edge is ignored. One touch only.
- Complete the turn when the corner has moved at least **a third of the page width**, or on a flick of at least
  **0.5 px/ms** horizontally in the turn's direction (with the 50px minimum); otherwise spring back.
- Completion animation **~250ms** ease-out; a link-played turn **~450ms**; loading mark after **400ms** of blank
  sheet.
- No turn: with text selected; starting on `a, button, select, input, textarea, [role=menu], [contenteditable]` or
  inside one; starting inside an ancestor with `scrollWidth > clientWidth` and `overflow-x` `auto`/`scroll`.
- `prefers-reduced-motion: reduce`: no curl; a completed swipe fades the page out (~150ms) and navigates; a link
  navigates at once.
- The curl acts on the page: the chapter wrapper's box clipped to the viewport (never the header, the footer or, on
  wide screens, the margins outside `<main>`). The rest corner is that page's top-right (next) / top-left (prev); the
  moving corner P is the rest corner plus the finger's displacement; P is clamped to within one page width of the
  spine's top corner. The flap and the fold shadow are fixed overlays, but their clip polygons are the page's, so
  nothing is drawn outside it.
- The flap and shadow are `aria-hidden`, `pointer-events: none`; the links stay the accessible way to turn and keep
  working without JavaScript and for modified/middle clicks.
- Every new English string is added to `messages/en.json`, then `npm run sync-messages`, then translated by hand in
  it/fr/de/es/pt.
- Colours come from the page background (light and dark); no hard-coded paper colour.
- Commit/stage files by name; never `git add -A`.

## Review Focus

1. A vertical scroll that drifts a little sideways must stay a scroll and never curl (Task 2 test
   `aMostlyVerticalDragIsAScroll`, Task 3 test `aVerticalDragNeverCurls`).
2. A page scrolled far down must curl from the top corner of the screen, not of the article (Task 3 test
   `theClipIsTranslatedIntoTheWrappersBox`).
3. Back after a turn (page restored from the bfcache) must show the chapter unclipped (Task 3 test
   `aRestoredPageIsNotLeftClipped`).
4. A two-finger pinch must never curl, and a second finger added mid-turn cancels it (Task 2 test
   `twoFingersAreNeverATurn`, Task 3 test `aSecondFingerCancelsTheTurn`); pinched in, one finger pans the page
   sideways (`touch-action: auto` on `[data-zoomed]`, Task 3 test `aZoomedInPageCanBePannedSideways`).
5. A tap or drag starting on a control inside the page (the Esther tradition switch, a capped-notice link) is not a
   swipe (Task 2 test `aTouchOnAControlIsNotASwipe`).

---

### Task 1: Fold geometry

**Files:**
- Create: `lib/page-curl.ts`
- Test: `lib/__tests__/page-curl.test.ts`

**Interfaces:**
- Produces:
  ```ts
  export type Point = { x: number; y: number };
  export type Side = "next" | "prev";
  /** The rest corner: (w, 0) for next, (0, 0) for prev. */
  export function restCorner(side: Side, w: number): Point;
  /** P clamped to within w of the spine's top corner ((0,0) for next, (w,0) for prev). */
  export function clampCorner(side: Side, p: Point, w: number): Point;
  export type Curl = {
    stay: Point[];        // polygon of the page that stays (viewport coords), [] when fully turned
    flap: Point[];        // the lifted part reflected across the fold (viewport coords), [] at rest
    foldAngle: number;    // degrees, direction of the fold line, for the gradients
    progress: number;     // 0 at rest … 1 fully turned: |C.x − P.x| / (2w), clamped
  };
  export function curl(side: Side, p: Point, w: number, h: number): Curl;
  ```

The lifted part is the set of page points nearer to C than to P (the fold line is the perpendicular bisector of
C–P); `stay` is the rest of the page rectangle; `flap` is the lifted polygon reflected across the fold line. Clip the
rectangle against the half-plane (Sutherland–Hodgman on four edges). `curl` clamps P first. A polygon of zero area
is returned as `[]`. The flap is the back of the sheet, blank paper with no text, so it is drawn directly as this
reflected polygon (a `clip-path` on a viewport-sized layer) rather than as a transformed copy of the page; this is
the plan's reading of the spec's "positioned with a transform", with the same result on screen.

- [ ] **Step 1: Write the failing tests** (compare polygons as sets of points rounded to 0.01, any start/order)

```ts
it("aStraightPullFoldsAVerticalLine", () => {
  const c = curl("next", { x: 200, y: 0 }, 400, 800);
  expect(norm(c.stay)).toEqual(norm([{ x: 0, y: 0 }, { x: 300, y: 0 }, { x: 300, y: 800 }, { x: 0, y: 800 }]));
  expect(norm(c.flap)).toEqual(norm([{ x: 200, y: 0 }, { x: 300, y: 0 }, { x: 300, y: 800 }, { x: 200, y: 800 }]));
  expect(c.progress).toBeCloseTo(0.25);
});
it("aCornerPullFoldsATriangle", () => {
  const c = curl("next", { x: 300, y: 100 }, 400, 800);
  expect(norm(c.flap)).toEqual(norm([{ x: 300, y: 0 }, { x: 300, y: 100 }, { x: 400, y: 100 }]));
  expect(norm(c.stay)).toEqual(norm([{ x: 0, y: 0 }, { x: 300, y: 0 }, { x: 400, y: 100 }, { x: 400, y: 800 }, { x: 0, y: 800 }]));
});
it("atRestNothingLifts", () => {
  const c = curl("next", restCorner("next", 400), 400, 800);
  expect(c.flap).toEqual([]); expect(c.progress).toBe(0);
});
it("theCornerCannotTearFromTheSpine", () => {
  expect(clampCorner("next", { x: -600, y: 0 }, 400)).toEqual({ x: -400, y: 0 });
  expect(clampCorner("prev", { x: 1000, y: 0 }, 400)).toEqual({ x: 800, y: 0 });
});
it("aFullTurnLeavesNothingStanding", () => {
  expect(curl("next", { x: -400, y: 0 }, 400, 800).stay).toEqual([]);
  expect(curl("next", { x: -400, y: 0 }, 400, 800).progress).toBe(1);
});
it("previousMirrorsNext", () => {
  const c = curl("prev", { x: 100, y: 100 }, 400, 800);
  expect(norm(c.flap)).toEqual(norm([{ x: 100, y: 0 }, { x: 100, y: 100 }, { x: 0, y: 100 }]));
});
```

- [ ] **Step 2: Run** `npx vitest run lib/__tests__/page-curl.test.ts` — Expected: FAIL (module not found).
- [ ] **Step 3: Implement** the interface above in `lib/page-curl.ts`.
- [ ] **Step 4: Run** the same command — Expected: 6 passed.
- [ ] **Step 5: Commit** `git add lib/page-curl.ts lib/__tests__/page-curl.test.ts && git commit -m "feat(reader): fold geometry for the page curl"`

### Task 2: Gesture decisions

**Files:**
- Create: `lib/swipe.ts`
- Test: `lib/__tests__/swipe.test.ts`

**Interfaces:**
- Produces:
  ```ts
  export const SWIPE_PX = 50, AXIS_PX = 10, EDGE_PX = 24, FLICK_PX_PER_MS = 0.5;
  /** Whether a touch at clientX on target may start a turn (edge, controls, sideways scrollers, selection). */
  export function canStartAt(target: Element | null, clientX: number, viewportWidth: number, selection?: string): boolean;
  export function decideAxis(dx: number, dy: number): "pending" | "scroll" | "turn";
  /** dx < 0 → "next", dx > 0 → "prev". */
  export function sideOf(dx: number): Side;
  export function releaseOutcome(o: { dx: number; dy: number; w: number; vx: number; reducedMotion: boolean; side: Side }): "complete" | "back";
  /** True only for exactly one touch. */
  export function singleTouch(touches: { length: number }): boolean;
  ```
  `Side` is imported from `lib/page-curl.ts`. `vx` is px/ms over the last ~100ms of movement. With `reducedMotion`
  the outcome is "complete" iff |dx| ≥ 50 and |dx| > 1.5·|dy|; otherwise iff |dx| ≥ 50 and (the corner the reader sees,
  `clampCorner(side, cornerFor(side, dx, dy, w), w)`, has travelled ≥ w/3 horizontally from the rest corner, or a
  flick in dx's direction). `cornerFor` (rest corner plus the displacement, only away from its edge, never above the
  top) lives in `lib/swipe.ts`; test `theCornerTheReaderSeesDecidesNotTheFingersSidewaysPull`: on a 400px page
  `{ dx: -60, dy: 400, side: "next" }` → "complete", `{ dx: -60, dy: 100, side: "next" }` → "back". Either way a displacement against `side` (the turn the drag locked, so dx = 0 or sideOf(dx) ≠
  side) is "back". `vx` is 0 when the last movement is older than ~100ms at release: a finger that stopped is not
  flicking. In normal motion the 1.5× ratio decides only the axis at the start: once the page curls, pulling the
  corner down is part of the curl, and the corner the reader sees decides.

- [ ] **Step 1: Write the failing tests**

```ts
it("aMostlyVerticalDragIsAScroll", () => {
  expect(decideAxis(3, 4)).toBe("pending");
  expect(decideAxis(12, 10)).toBe("scroll");
  expect(decideAxis(-16, 10)).toBe("turn");
});
it("twoFingersAreNeverATurn", () => { expect(singleTouch({ length: 2 })).toBe(false); expect(singleTouch({ length: 1 })).toBe(true); });
it("theScreenEdgesBelongToTheBrowser", () => {
  const p = document.createElement("p"); document.body.append(p);
  expect(canStartAt(p, 20, 400)).toBe(false);
  expect(canStartAt(p, 380, 400)).toBe(false);
  expect(canStartAt(p, 200, 400)).toBe(true);
});
it("aTouchOnAControlIsNotASwipe", () => {
  document.body.innerHTML = `<a href="/x"><span id="in">x</span></a><select id="s"></select><div role="menu"><i id="m"></i></div>`;
  for (const id of ["in", "s", "m"]) expect(canStartAt(document.getElementById(id), 200, 400)).toBe(false);
});
it("aSidewaysScrollerKeepsItsOwnSwipes", () => {
  document.body.innerHTML = `<div id="w" style="overflow-x:auto"><span id="t">x</span></div>`;
  const w = document.getElementById("w")!;
  Object.defineProperty(w, "scrollWidth", { value: 900 }); Object.defineProperty(w, "clientWidth", { value: 300 });
  expect(canStartAt(document.getElementById("t"), 200, 400)).toBe(false);
});
it("selectedTextIsNotTurned", () => { expect(canStartAt(document.body, 200, 400, "In principio")).toBe(false); });
it("aThirdOfThePageCompletes", () => {
  expect(releaseOutcome({ dx: -140, dy: 0, w: 400, vx: 0, reducedMotion: false, side: "next" })).toBe("complete");
  expect(releaseOutcome({ dx: -120, dy: 0, w: 400, vx: 0, reducedMotion: false, side: "next" })).toBe("back");
});
it("aFlickCompletesButNotBelowTheMinimum", () => {
  expect(releaseOutcome({ dx: -60, dy: 0, w: 400, vx: -0.6, reducedMotion: false, side: "next" })).toBe("complete");
  expect(releaseOutcome({ dx: -40, dy: 0, w: 400, vx: -2, reducedMotion: false, side: "next" })).toBe("back");
  expect(releaseOutcome({ dx: -60, dy: 0, w: 400, vx: 0.6, reducedMotion: false, side: "next" })).toBe("back");
});
it("withReducedMotionASwipeIsEnough", () => {
  expect(releaseOutcome({ dx: 60, dy: 20, w: 400, vx: 0, reducedMotion: true, side: "prev" })).toBe("complete");
  expect(releaseOutcome({ dx: 60, dy: 50, w: 400, vx: 0, reducedMotion: true, side: "prev" })).toBe("back");
});
it("leftIsNextRightIsPrevious", () => { expect(sideOf(-1)).toBe("next"); expect(sideOf(1)).toBe("prev"); });
```

- [ ] **Step 2: Run** `npx vitest run lib/__tests__/swipe.test.ts` — Expected: FAIL (module not found).
- [ ] **Step 3: Implement** `lib/swipe.ts` (`canStartAt` walks `closest()` for the controls and ancestors with
  `getComputedStyle(el).overflowX` for the scrollers; `selection` defaults to `window.getSelection()?.toString()`).
- [ ] **Step 4: Run** the same command — Expected: 10 passed.
- [ ] **Step 5: Commit** `git add lib/swipe.ts lib/__tests__/swipe.test.ts && git commit -m "feat(reader): decide when a touch is a page turn"`

### Task 3: The PageTurn component and TurnLink

**Files:**
- Create: `components/PageTurn.tsx` (`"use client"`), `components/page-turn.module.css`
- Modify: `messages/en.json` (`Reader.turning`), then `npm run sync-messages` and translate in the five others
- Test: `components/__tests__/PageTurn.test.tsx`

**Interfaces:**
- Consumes: `curl`, `restCorner`, `clampCorner`, `Side` (Task 1); everything in `lib/swipe.ts` (Task 2);
  `useRouter`, `Link` from `@/i18n/navigation`.
- Produces:
  ```tsx
  export function PageTurn(props: { prev?: string; next?: string; children: React.ReactNode }): JSX.Element;
  /** A next-intl Link that, on a plain left click, plays the turn instead of navigating at once. */
  export function TurnLink(props: { side: Side; href: string; rel: "prev" | "next"; className?: string; children: React.ReactNode }): JSX.Element;
  ```
  hrefs are locale-less (as `ChapterNav` builds them). A React context carries `play(side)` from `PageTurn` to
  `TurnLink`; outside a `PageTurn`, `TurnLink` is a plain `Link`.

Behaviour (spec, "The design" and "Calls made" 1–8):
- On mount: `router.prefetch(href, { kind: "full" } as never)` for each defined target (Next's `PrefetchKind.FULL`
  is `"full"`; the enum is not exported publicly).
- The wrapper is a `<div data-page-turn data-state="idle|dragging|turning|blank|fading">` with `touch-action: pan-y`;
  touch listeners are attached with `addEventListener` (`touchmove` non-passive, calling `preventDefault()` only once
  the axis is "turn").
- While curling: the wrapper's `clip-path: polygon(...)` is `curl(...).stay` translated by minus the wrapper's
  `getBoundingClientRect()` left/top; a fixed overlay (`aria-hidden`, `data-testid="page-turn-flap"`) has the flap's
  `clip-path` and a `linear-gradient` at `foldAngle` for the shine, and a second fixed layer the fold shadow,
  stronger with `progress`. One DOM write per `requestAnimationFrame`.
- A direction with no target (swiping right on Genesis 1) does nothing.
- Completion animates P to the far side (`clampCorner` limit), sets `data-state="blank"` (wrapper fully clipped),
  then `router.push(href)`. After 400ms still blank: render `<p role="status">{t("turning")}</p>` on the sheet.
- Spring back animates P to the rest corner, then clears the clip and the overlay.
- `pageshow` (persisted) and `popstate` reset to idle and clear the clip.
- While `turning`/`blank`/`fading`, new touches and `play()` calls are ignored.
- Reduced motion (`matchMedia("(prefers-reduced-motion: reduce)")`): no curl while dragging; a completed swipe sets
  `data-state="fading"` (CSS opacity transition ~150ms) then pushes; `play()` pushes at once.
- `TurnLink` onClick: return (let the browser act) if `button !== 0` or any of ctrl/meta/shift/alt; else
  `preventDefault()` and `play(side)` (~450ms: P travels from the rest corner to the far side along a slight
  downward arc), then hand over as above.

Message: `"turning": "Turning the page…"` (it: "Si volta pagina…", fr: "On tourne la page…", de: "Die Seite wird
umgeblättert…", es: "Pasando la página…", pt: "Virando a página…").

- [ ] **Step 1: Write the failing tests.** Mock `next/navigation`'s `useRouter` to `{ push, prefetch, replace,
  refresh, back, forward }` as `ReaderBar.test.tsx` does (the pushed path is locale-prefixed, `/en/...`); wrap in
  `NextIntlClientProvider locale="en"`; stub `matchMedia`; give the wrapper a `getBoundingClientRect` of
  `{ left: 0, top: -1200, width: 400, height: 3000 }` and `window.innerWidth = 400`, `innerHeight = 800`. Helper
  `swipe(el, from, to, ms)` fires `touchStart`/`touchMove` (several steps)/`touchEnd` with `clientX/clientY` and
  advances `performance.now()` via fake timers. Tests:
  - `swipingLeftTurnsToTheNextChapter` — swipe 300→100 at y=300 → `await waitFor` `push("/en/read/drb/mark/1")`.
  - `swipingRightTurnsToThePreviousChapter` — 100→300 → `push("/en/read/drb/matthew/27")`.
  - `nothingBeforeGenesis` — no `prev`, swipe right → `push` not called, no flap rendered.
  - `aShortDragSpringsBack` — 300→240 slowly → no push; after the animation the wrapper has no `clip-path` and
    `data-state="idle"`.
  - `aVerticalDragNeverCurls` — 200,300 → 215,420 → no push, no `clip-path` ever set.
  - `aTouchStartingAtTheEdgeIsLeftToTheBrowser` — start x=390 → no push.
  - `twoFingerTouchesDoNotCurl` — touchStart with two touches → no clip.
  - `theClipIsTranslatedIntoTheWrappersBox` — mid-drag (finger at 250 from 350, y 300) the wrapper's `clip-path`
    contains `1200px` offsets (the stay polygon's top edge at y = 0 + 1200).
  - `aRestoredPageIsNotLeftClipped` — complete a swipe (push called), then dispatch `pageshow` with
    `persisted: true` → `data-state="idle"`, no `clip-path`.
  - `aSlowNeighbourShowsTheLoadingMark` — after a completed swipe, advance 400ms → `getByRole("status")` has
    "Turning the page…".
  - `theNeighboursArePrefetched` — on mount `prefetch` called with both locale-prefixed hrefs and `{ kind: "full" }`.
  - `aPlainClickPlaysTheTurn` / `aModifiedClickIsALink` — `TurnLink` inside `PageTurn`: plain click → push after the
    animation; `ctrlKey` click → `defaultPrevented` false, push not called.
  - `withReducedMotionTheLinkGoesAtOnce` — `matchMedia` matches → click → push synchronously, no flap.
  - `aTurnIgnoresNewGestures` — during the completion animation a second swipe right → `push` called once, with next.
- [ ] **Step 2: Run** `npx vitest run components/__tests__/PageTurn.test.tsx` — Expected: FAIL (module not found).
- [ ] **Step 3: Implement** `components/PageTurn.tsx`, `components/page-turn.module.css` and the message.
- [ ] **Step 4: Run** the same command — Expected: 15 passed. Then `npm test`, `npm run typecheck`, `npm run lint`
  — Expected: all pass, no warnings.
- [ ] **Step 5: Commit** `git add components/PageTurn.tsx components/page-turn.module.css components/__tests__/PageTurn.test.tsx messages/*.json && git commit -m "feat(reader): turn the page with a curl that follows the finger"`

### Task 4: Wire it into the chapter page

**Files:**
- Modify: `components/ChapterNav.tsx` (export `chapterTargets`; links become `TurnLink`)
- Modify: `app/[locale]/read/[edition]/[book]/[chapter]/page.tsx` (wrap everything after `ReaderBar` in `PageTurn`)
- Test: `components/__tests__/ChapterNav.test.tsx`, `app/__tests__/pages.test.tsx`

**Interfaces:**
- Consumes: `PageTurn`, `TurnLink` (Task 3).
- Produces:
  ```ts
  export function chapterTargets(o: { index: EditionIndex; bookNum: number; chapter: number; base: string; query?: string; leavingQuery?: string }): { prev?: string; next?: string };
  ```
  `ChapterNav` uses it for its hrefs (its existing tests must still pass unchanged); the page passes the same result
  to `PageTurn`, keyed by `` `${base}/${slug}/${chapter}${qs}` `` so each chapter mounts fresh.

- [ ] **Step 1: Write the failing tests**
  - `ChapterNav.test.tsx` › `targetsAreTheLinksHrefs` — `chapterTargets({ index, bookNum: 47, chapter: 28, base:
    "/read/drb", query: "?view=interlinear", leavingQuery: "" })` equals `{ prev:
    "/read/drb/matthew/27?view=interlinear", next: "/read/drb/mark/1" }`; for Genesis 1 `prev` is undefined.
  - `pages.test.tsx` › `theChapterTurnsAsOnePage` — the rendered chapter page has one `[data-page-turn]` element
    containing the `article` and the chapter `nav`, and the reader toolbar outside it. Also for Esther with both
    texts (the two-column spread inside the same single wrapper).
- [ ] **Step 2: Run** `npx vitest run components/__tests__/ChapterNav.test.tsx app/__tests__/pages.test.tsx` —
  Expected: the two new tests FAIL.
- [ ] **Step 3: Implement** both modifications.
- [ ] **Step 4: Run** `npm test && npm run typecheck && npm run lint` — Expected: all pass.
- [ ] **Step 5: Check it in the browser.** `npm run dev`; with Playwright at 390×844 with touch emulation open
  `/en/read/drb/matthew/28`, drag from (300,400) to (60,420): the corner curls during the drag and Mark 1 opens;
  a short drag springs back; scroll the page down and drag: the curl starts at the screen's top corner; dark theme;
  reduced motion (emulated) fades. Screenshot mid-drag into the scratchpad.
- [ ] **Step 6: Commit** `git add components/ChapterNav.tsx "app/[locale]/read/[edition]/[book]/[chapter]/page.tsx" components/__tests__/ChapterNav.test.tsx app/__tests__/pages.test.tsx && git commit -m "feat(reader): swipe the chapter page to turn it"`
