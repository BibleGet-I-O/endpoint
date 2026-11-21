# Reader: swipe to turn the chapter, with a page curl

**Status:** approved approach, design for review
**Repo of the work:** `website/` (the Next.js reader). This document lives in the endpoint repo with the
reader's other designs.

## Intent

On a phone, a reader moves through a book by turning pages: swipe left for the next chapter, swipe right for
the previous one. Today the only way is the ← Previous / Next → links at the bottom of a chapter, which on a
long chapter means scrolling to the end first. The Martyrology reader (`CatholicOS/martyrology-frontend`,
`components/Reader.tsx`) already turns its days with a swipe; the Bible reader should feel like it, and turn
the page more like a book: the sheet curls from its top corner and follows the finger, as in StPageFlip.

Success: on a phone, a finger dragged from the right side of a chapter lifts the page's top-right corner and
the sheet curls after it; let go far enough and the page turns and the next chapter is there; let go early
and it falls back. It feels like one gesture, not a swipe followed by a page load.

## Goals

- Swipe left → next chapter, swipe right → previous chapter, crossing books exactly as `ChapterNav` does
  (Matthew 28 → Mark 1), with the same query kept or dropped (`query` / `leavingQuery`).
- A page curl that follows the finger, StPageFlip-style (geometry below), from the top-right corner for
  *next*, the top-left corner for *previous*.
- Release past the threshold or a quick flick completes the turn and navigates; otherwise the page springs
  back.
- The Previous / Next links play the same turn (animated, no drag) before they navigate.
- Every layout on the chapter page turns: a single text, Esther's two columns, the interlinear comparison.

## Non-goals

- Showing the next chapter under the lifting page. Under the curl is a **blank sheet** of the page's colour;
  the next chapter appears when the turn ends. (Chosen to see how it looks; drawing the next chapter
  underneath is a possible follow-up.)
- Mouse dragging on desktop. A mouse drag on a page of text is text selection; desktop gets the turn on the
  links only.
- Keyboard ← / → shortcuts (can come later).
- A page-flip library. StPageFlip and its kind keep every page in their own container; our chapters are
  separate server-rendered routes.

## Anti-goals (a failure even if it works)

- Breaking vertical scrolling, or a scroll that turns the page by accident.
- Stealing the iOS/Android edge gestures (browser back/forward) or text selection.
- A turn that ends on a blank page for long: the neighbours are prefetched so the navigation is quick.
- Motion for people who asked for none.
- A turn that leaves the page clipped or shifted when it is cancelled, or after Back.

## Constraints

- `ChapterNav` is a server component; its `neighbours()` and `href()` give the two targets. The swipe and
  the curl live in a client component that receives those two hrefs (or none at the ends of the Bible), so
  the swipe goes exactly where the links go.
- Same gesture thresholds as the Martyrology where they apply: a swipe moves at least **50px** sideways and
  its sideways move is more than **1.5×** its vertical one. In addition: a touch that starts within **24px**
  of the screen's left or right edge is left to the browser; one touch only; no turn while text is selected;
  no turn from inside an element that scrolls sideways.
- The decision whether a gesture is a turn is taken early (first ~10px of movement): once it is horizontal
  the page curls and vertical scroll is blocked for that gesture; once it is vertical it is a scroll and the
  curl never starts.
- `prefers-reduced-motion: reduce`: no curl; a completed swipe (decided on release with the thresholds above)
  fades the page out briefly and navigates; the links navigate at once.
- Light and dark themes: the sheet, the flap and the shadows take the page's own background, so the curl
  looks right in both.
- The chapter page can be much taller than the screen and scrolled anywhere; the curl acts on the part of
  the page that is on screen (the viewport), with the corner at the viewport's top corner.

## Approaches considered

1. **The Martyrology's turn reused** — the incoming page swings in ~18° and settles in 0.4s. Cheap and
   consistent across the two sites, but a settle, not a curl: the outgoing page never moves.
2. **Our own StPageFlip-style curl in CSS/TS** — the corner follows the finger, fold geometry per frame,
   clip-path plus a flap and gradients. **Chosen**: it is the effect asked for, fits the server-rendered
   routes, needs no dependency.
3. **A page-flip library (StPageFlip)** — realistic, but it wants all pages in its own container; dropped.

## The design

### Geometry (per frame)

The page rectangle is the visible part of the chapter (viewport-sized, at the current scroll). For a *next*
turn the corner at rest is the top-right **C**; the finger, offset by where it first touched, places the
moving corner **P** (clamped so the sheet never tears from its spine: |P − top-left| ≤ page width, as in
StPageFlip).

- The **fold line** is the perpendicular bisector of C–P. It cuts the page into the part that stays (spine
  side) and the part that has lifted.
- The page is **clipped** to the staying part (`clip-path: polygon(...)` from the fold line's intersections
  with the page edges).
- The **flap** is the lifted part reflected across the fold line: an element of the page's colour shaped
  with the same polygon mirrored, positioned with a transform (rotation by twice the fold angle about the
  fold line). It is the back of the sheet: blank paper, no text.
- **Shading**: a linear gradient on the flap, lighter towards the fold (the shine), and a shadow cast on the
  blank sheet along the fold, deeper as the curl progresses.
- *Previous* mirrors all of it from the top-left corner.

### Gesture → turn

- Touch down: record the point; nothing moves.
- First ~10px: decide horizontal (curl starts, scroll is held for this gesture) or vertical (scroll, no curl),
  with the 1.5× rule. The direction picks the corner; a direction without a target (Genesis 1 → previous)
  does nothing.
- Move: update P each animation frame (`requestAnimationFrame`, one write per frame).
- Release: complete if the corner has crossed **a third of the page width**, or the release velocity is a
  flick (above ~0.5 px/ms horizontally in the turn's direction); else spring back. Completion animates P to
  the far side (~250ms, ease-out), then navigates with the router; the spring-back animates P to C.
- Cancel (a second finger, `touchcancel`, the page hidden): spring back.

### Links

The Previous / Next links keep their `href` (no-JS and middle-click work as today). A plain click is turned
into the same turn played on its own (P travels from C along a fixed path, ~450ms), then navigates.

### Loading the neighbour

When the chapter opens, the two neighbours are prefetched (`router.prefetch`), so the navigation after the
curl swaps to an already-loaded chapter. The new chapter arrives unclipped and at the top of the page, like
following a link today.

### Accessibility

The links stay the accessible way to turn; the curl is decoration (`aria-hidden` flap and shadow). With
reduced motion, see Constraints.

## Calls made after the builder check (the designer's, open to correction)

1. **What curls.** The client component wraps everything on the chapter page below the `ReaderBar` (the
   bar is not sticky, so it simply scrolls with the page and is not clipped). During a turn that wrapper
   gets the `clip-path`; its polygon is computed in viewport coordinates and translated into the wrapper's
   own box (subtract its `getBoundingClientRect()` top/left), so a page scrolled anywhere curls from the top
   corner of the screen. The flap and the fold shadow are a `position: fixed`, viewport-sized,
   `pointer-events: none` overlay.
2. **What is under it.** What shows through the clipped part is the page's own background (the body's,
   light or dark): that is the blank sheet. The shadow along the fold is drawn on the overlay. Outside
   `<main>` on wide screens nothing changes; on a phone `<main>` is the whole width.
3. **Handing over to the next chapter.** On completion the wrapper stays fully clipped (a blank sheet) and
   the router navigates; the wrapper is keyed by the chapter's path, so the new chapter mounts fresh,
   unclipped. If the new chapter has not arrived after **400ms**, a small, quiet loading mark shows on the
   blank sheet. The chapter route has no `loading.tsx`; the prefetch must fetch the whole route (a full
   prefetch, not only up to a loading boundary); the builder confirms Next 16's API for it. The state is also
   reset on `pageshow` (bfcache) and on `popstate`, so Back never shows a clipped page.
4. **Where a gesture may start.** Anywhere in the wrapper, except: on an interactive control (`a`,
   `button`, `select`, `input`, `textarea`, `[role=menu]`, `[contenteditable]`, or inside one); inside an
   element that scrolls sideways (an ancestor with `scrollWidth > clientWidth` and `overflow-x` `auto` or
   `scroll`); within 24px of the screen's left or right edge.
5. **The corner is not grabbed.** The finger need not start at the corner: the moving corner P is the rest
   corner C plus the finger's displacement since it touched down. So the 24px edge rule never gets in the
   way of the curl.
6. **Esther's two columns and the interlinear turn as one page**, never per column.
7. **Links.** Keep `next-intl`'s `Link` (its locale handling) and add an `onClick`: a plain left click with
   no modifier plays the turn and navigates; Ctrl/Cmd/Shift/Alt and middle clicks fall through to the
   browser. Clicks and new gestures during a turn are ignored.
8. **Tests.** The geometry (C, P, page size → polygons, flap transform, shadow) and the gesture decision
   (points and times → none / scroll / turn next / turn prev, complete or spring back) are pure functions
   with unit tests; the component gets jsdom tests with touch events for both directions, the ends of the
   Bible, a vertical drag, an edge start, a start on a link, reduced motion and modified clicks. The look is
   checked by hand on a phone (and Playwright's touch emulation on staging).

## Left to the builder

- Exact easing curves, shadow strengths and the flap's tint in each theme (tune on a phone).
- Where the client component sits: wrapping the `<main>` contents below the `ReaderBar`, or just the article;
  the reader bar should not curl.
- How the geometry is factored (a pure function from C, P and the page size to the two polygons, the flap
  transform and the shadow parameters is the natural unit to test).
- How to block the scroll for a horizontal gesture (`touch-action: pan-y` on the area plus a non-passive
  `touchmove` listener once the curl has started).
