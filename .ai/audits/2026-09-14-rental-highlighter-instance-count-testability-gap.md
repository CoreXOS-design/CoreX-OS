# Rental application review screen: the highlighter's Alpine instance count doesn't match what the page's own comments describe — a real testability gap, cause not fully diagnosed

**Date:** 2026-09-14. **Found by:** cc5, while verifying the capture-chip positioning
fix (see `2026-09-14`'s rail+popup fix commit). **Status:** confirmed reproducible
symptom, root cause NOT fully diagnosed — write-up per the conductor's instruction
to put it on record now rather than let it be rediscovered a third time, not an
instruction to fix it today.

## Why this is being written down

This is the **second** time this exact class of thing has cost real verification
effort:
1. `scripts/rental-click-through.mjs` check #6 ("Capture chip — open existing mark
   and Save") is an already-documented, already-tracked KNOWN ISSUE — "mark overlay
   never rendered" — from an earlier session, never resolved, carried forward as a
   named exception so it doesn't silently fail the gate.
2. Tonight, verifying the capture-chip positioning fix, direct-method-injection
   testing (opening the chip via Alpine's own `openCaptureChipForCreate()` rather
   than a real draw gesture — the standard shortcut used elsewhere on this screen
   for exactly this reason) hit a related, but not identical, wall: **the chip's own
   state (`captureChip`) was set correctly, on the correct component instance
   (confirmed by DOM containment, not guessed), and the form was confirmed to be a
   genuine DOM descendant of that same instance — but the form still rendered
   `display: none`.**

Both are the same shape of problem: something about how this screen's highlighter
components are instantiated/scoped makes them resist inspection from outside a real,
full user gesture. That's exactly the shape of gap that quietly erodes what a gate
or a script can prove about this screen, and it's now shown up twice.

## What's actually confirmed (not guessed)

Tested against application 230's review screen (`/corex/rental-applications/230/review`,
read-only, 6 documents), in a real headless Chromium session via Puppeteer, viewport
900×500:

- `document.querySelectorAll('[x-data]')`, filtered to elements whose Alpine data
  exposes `openCaptureChipForCreate` (the highlighter component's own method): **19
  matches**, for an application with **6 documents**. Not 6, not 12 (a clean "two
  call sites × 6 docs" reading of the existing source comment) — 19.
- The first matched root is **not** a descendant of any `<section>` element — every
  per-document highlighter instance in the source is rendered inside
  `<section id="cv-doc-{id}">` (`review.blade.php`'s continuous-view `@foreach`,
  the only literal `x-data="rentalDocumentHighlighter(...)"` instantiation point in
  the current source — confirmed by grep, exactly one occurrence). A root with no
  enclosing `<section>` doesn't match that structure.
- Calling `openCaptureChipForCreate()` directly on that root **did** set
  `captureChip` correctly (confirmed: `captureChipTruthy: true`,
  `captureChipMode: 'create'`, read directly off the same component instance, not
  through a separate query). This rules out "the method call silently failed" as
  the explanation.
- The `[data-capture-chip-form]` element found via `root.querySelector(...)`
  (i.e., confirmed to be a real DOM descendant of the exact instance whose state
  was just set) **still computed `display: none` and `offsetHeight: 0`.**

So: state correctly set, on a confirmed-correct, confirmed-descendant DOM subtree,
and the `x-show="captureChip"` binding on that subtree's own form did not react.

## What this doesn't yet explain

- **Where 19 instances come from.** The source has exactly one instantiation site,
  looped once per document (6 documents → 6 instances, by the straightforward
  reading). 19 doesn't cleanly factor against 6 in a way that maps to "two call
  sites" (would be 12) or "one per page across however many total pages" without
  actually counting each document's page count, which wasn't done here.
- **Why a correctly-DOM-descendant form doesn't react to its own component's state
  change.** This is the more concerning half — if confirmed, it would mean
  `x-show="captureChip"` isn't binding to the reactive property the way normal
  Alpine scoping should guarantee for a plain descendant element with no
  intervening `x-data`. Whether an intervening scope exists somewhere in the actual
  DOM between the root and the form was not checked.
- **The pre-existing comment's own framing** ("the merged root copy or the
  continuous-view's own nested instance per document,"
  `document-highlighter-script.blade.php`, `captureChip`'s own docblock) describes
  a two-copy architecture that this investigation could not locate a second literal
  instantiation site for in the current source. Either that comment is describing
  something that's since been consolidated and is now stale, or the duplication
  happens somewhere this investigation didn't look (a different route/controller
  reusing the same Blade partials, a client-side templating mechanism, or something
  in the PDF page-rendering path that clones the component subtree per page).

## Why this wasn't chased further tonight

This surfaced as a side effect of verifying an unrelated positioning fix, under
real time pressure with Johan live-testing. Chasing 19-vs-6 and the display:none
mystery to a real root cause is a proper investigation in its own right — reading
through the full page-rendering/pagination path, not a five-minute add-on to a
different fix. Recording the precise, confirmed symptom now (rather than a vague
"testing is hard here" note, and rather than a confident but unverified theory)
is what lets whoever picks this up next start from real data instead of
re-discovering it from scratch a third time.

## Suggested next step (not started)

Trace every place `rentalDocumentHighlighter(` is instantiated or cloned —
including indirectly, via any JS that copies/re-renders a subtree containing an
`x-data` block (Alpine doesn't re-scan cloned DOM automatically; a naive `innerHTML`
clone would produce inert copies, not 19 reactive ones, so if these 19 are genuinely
reactive components, something is calling `Alpine.initTree()` or equivalent
per-clone) — and separately, confirm with a real draw gesture (not method
injection) whether the `display:none` symptom reproduces in a real user flow at
all, or whether it's specific to the injection approach. Until then, this screen's
capture-chip behavior is provable by a real human's real gesture (which is how
tonight's positioning fix is being finished — the conductor's own live walk) but
not reliably provable by an automated script reaching in from outside.
