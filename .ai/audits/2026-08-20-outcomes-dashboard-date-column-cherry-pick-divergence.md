# Outcomes Dashboard date-column + badge-label fix — Staging deploy note, recorded

**Date:** 2026-08-20

## What happened

Johan spotted the Outcomes Dashboard (`/corex/presentations/outcomes`) showing
a sidebar badge of 5 next to a tile reading 4, and two rows with decision
dates outside the picked date-range filter still appearing. Investigation
(read-only, against live/`nexus_os`) found two independent causes:

1. The window filter was applied to `presentation_outcomes.recorded_at`
   (when the outcome was logged into CoreX) while every row **displays**
   `decision_at` (when the client actually decided) — so a row decided
   outside the window could still pass the filter if it happened to be
   typed into the system inside it.
2. The sidebar badge counts presentations >30 days old with no outcome
   logged yet — a genuinely different metric from the dashboard's own
   outcome count, rendered as a bare number that read as if it should
   agree with the tile.

Johan authorised both fixes ("numbers should always be true"). Built and
tested on qa1 (`qa1-outcomes-datefix`, commit `ca52b478e`, 5/5 tests green),
then taken through the Staging hop only — live is off limits for this piece.

Files touched (exactly, in every application below): `app/Http/Controllers/
Presentation/PresentationOutcomesDashboardController.php`,
`resources/views/layouts/corex-sidebar.blade.php`,
`tests/Feature/Presentation/PresentationOutcomesDashboardTest.php`.

## Deploy detail specific to this hop — /corex-staging was mid another lane's work

`origin/Staging` cherry-pick went cleanly via a dedicated worktree
(`/corex-staging-wt-outcomesfix`, branch `staging-outcomes-datefix`) —
`origin/Staging` moved `044f263de` → `476162fe7`, pushed normally.

But the shared `/corex-staging` checkout — the one `staging.corexos.co.za`'s
nginx vhost actually serves (`root /corex-staging/public`) — was, at the time
of this deploy, checked out on a **different, unpushed local branch**
(`staging-rebase-prep`, one commit ahead of the pre-cherry-pick `origin/Staging`
tip, carrying another lane's in-progress buyers-report work, plus two
unrelated untracked CDS template files). Switching that checkout to `Staging`
or resetting it would have discarded or hidden that in-progress work, which
this session's rules forbid without explicit instruction.

Resolution: cherry-picked the SAME commit a second time, directly onto
`/corex-staging`'s current branch (`staging-rebase-prep`) as an additive,
non-destructive commit (`702e56eca`) — no branch switch, no reset, the other
lane's untracked files and unpushed commit untouched. This is what actually
made the fix visible on the live staging URL; it was **not pushed anywhere**
(the canonical, shareable version already lives on `origin/Staging` as
`476162fe7` via the dedicated worktree). When `staging-rebase-prep` is
eventually rebased/merged into `Staging` properly, this local application
will either already be redundant (both banks reconcile to the same tree) or
show as a routine no-op merge — see patch-id table below.

## The three commit applications — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| qa1 (`qa1-outcomes-datefix`) | `ca52b478e825450b4f779359ff892533cfdacf59` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |
| `origin/Staging` (pushed) | `476162fe7e5867495e4737524487f2bda7008199` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |
| `/corex-staging` local only (`staging-rebase-prep`) | `702e56ecacb97bff5df6dc728fdbe6bb5de2f32e` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |

All three identical patch-ids — same 3-file tree change, different commit
hash at each application because each was cherry-picked onto a different
parent history.

## Verification performed

**Checkout/DB verified against:** `/corex-staging` (the deployed checkout,
now on `staging-rebase-prep` + the local `702e56eca` cherry-pick), database
`hfc_staging`. FPM pool `php8.2-fpm` reloaded (resolved dynamically from
`/etc/nginx/sites-enabled/staging.corexos.co.za`, never hardcoded).
`view:clear` run (Blade-only change). Reflection confirmed the controller
and the Composer root install path both resolve from `/corex-staging` — no
cross-checkout contamination.

Rendered the page server-side as Retha Kelly (user 24, agency 1, branch
Shelly Beach) — same identity Johan used on live — via a direct controller
render (not a browser session; `route()` hrefs in the capture below show
`http://localhost/...` as an artifact of that harness, not a real bug — the
actual page at the URL below resolves normal `https://staging.corexos.co.za/...`
links).

**Data-availability note:** staging's `hfc_staging` database has **zero**
`presentation_outcomes` rows for agency 1 (HFC) at all — this feature has
real, live-only data; Retha's "2, not 4" scenario cannot be reproduced on
staging because the underlying rows don't exist there. This is a pre-existing
data gap, not a defect in the fix. What COULD be verified with staging's real
data: the sidebar badge, which reads off the `presentations` table (which
does have real staging data) —

```
<span>Outcomes</span>
<span ... title="7 presentations older than 30 days with no outcome logged yet — not a count of outcomes">7 due</span>
```

Total outcomes tile: `0` (correct — no outcome rows exist for Retha on
staging). Subtitle: "across 2 presentations in the selected window" (the
independent `totalPresentations` denominator, correctly non-zero since
presentations without outcomes still count there). No bare, unlabelled badge
number anywhere on the page.

## The one thing this note exists to prevent

When `staging-rebase-prep` is rebased/merged into `Staging` (or `origin/Staging`
is eventually merged into live/`main`), git will not recognise `702e56eca` and
`476162fe7` as "the same commit" by hash — check patch-id first if either
looks "missing":

```
git show 476162fe7e5867495e4737524487f2bda7008199 | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep 36eff93c1af77c806f0ec266ee1bbbe1407dedbe
```

A match means the content is already present — not a lost commit, not a
conflict to fight.
