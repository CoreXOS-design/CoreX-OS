# Outcomes Dashboard date-column + badge-label fix — live cherry-pick, recorded

**Date:** 2026-08-20

## What happened

Johan spotted the Outcomes Dashboard (`/corex/presentations/outcomes`)
showing a sidebar badge of 5 next to a "Total Outcomes" tile of 4, with two
rows carrying decision dates outside the picked date filter. Investigation
found two independent causes (both against live/`nexus_os` — see the earlier
read-only investigation): the date-range filter was applied to
`presentation_outcomes.recorded_at` (when the outcome was logged into CoreX)
while every row displays `decision_at` (when the client actually decided);
and the sidebar badge counts a different thing entirely (presentations >30
days old with no outcome logged) rendered as a bare, easily-misread number.

Built and tested on qa1 (`qa1-outcomes-datefix`, commit `ca52b478e`, 5/5
tests green), taken through Staging (`.ai/audits/2026-08-20-outcomes-
dashboard-date-column-cherry-pick-divergence.md` — Staging had zero real
`presentation_outcomes` data for HFC, so only the badge fix could be verified
there with real data). Johan: "outcome fix - move to live for proper
testing" — his own reasoning: staging has no outcome data, live is the only
place the date-column fix can actually be demonstrated.

Files touched (exactly, in every application): `app/Http/Controllers/
Presentation/PresentationOutcomesDashboardController.php`,
`resources/views/layouts/corex-sidebar.blade.php`,
`tests/Feature/Presentation/PresentationOutcomesDashboardTest.php`.

## The four commit applications — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| qa1 (`qa1-outcomes-datefix`) | `ca52b478e825450b4f779359ff892533cfdacf59` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |
| `origin/Staging` | `476162fe7e5867495e4737524487f2bda7008199` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |
| `/corex-staging` local-only (`staging-rebase-prep`) | `702e56ecacb97bff5df6dc728fdbe6bb5de2f32e` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |
| Live (`main`) | `60ff10d6a705715e00c3fd4dc5807033c7e8db9b` | `36eff93c1af77c806f0ec266ee1bbbe1407dedbe` |

All four identical patch-ids — same 3-file tree change, different commit
hash at each application because each was cherry-picked onto a different
parent history. `origin/main` moved `a0a6219c1` → `60ff10d6a`, pushed
normally, clean cherry-pick, no conflict.

## Deploy detail

Live's checkout (`/corex`) carried Johan's own uncommitted WIP (`template-
67.blade.php` modified, `template-68.blade.php` new, under docuperfect CDS
templates) — untouched throughout; the cherry-picked commit shares no files
with it, and `git status` after the pick confirms the WIP is exactly as it
was before. `php8.3-fpm` reloaded (resolved dynamically from `/etc/nginx/
sites-enabled/corexos.co.za`, never hardcoded). `view:clear` run (Blade-only
change). Reflection confirmed the controller and the Composer root install
path both resolve from `/corex` — no cross-checkout contamination.
`migrate:status` confirmed zero pending migrations (this commit carries none
anyway).

## Verification on live — real data, real numbers

Rendered server-side as Retha Kelly (user 24, agency 1, branch Shelly
Beach), date filter 22 May – 20 Aug 2026, against `nexus_os`:

- **Total outcomes: 2** (was 4 before the fix) — 34 Marine Drive (decision
  12 Aug 2026) and 7 Dolfynsig (decision 10 Jul 2026). Won mandates: 2,
  100.0% of outcomes.
- **303 Juanita Flats (decision 20 Feb 2026) no longer appears** in the
  list, nor does 4 Barcelona (decision 17 May 2026, 5 days before the
  window) — both decided outside the picked window, exactly as the fix
  intends.
- **Badge**: `title="5 presentations older than 30 days with no outcome
  logged yet — not a count of outcomes">5 due` — labelled, not a bare
  number that could be misread as an outcomes count. (The underlying count
  is 5 here, not the 7 seen on staging — different real data, same fix.)
- **Avg days to outcome: 5d.** Internally consistent with the two rows
  shown: `DATEDIFF(decision_at, presentations.created_at)` — 34 Marine
  Drive: decision 12 Aug, presentation created 12 Aug → 0 days; 7
  Dolfynsig: decision 10 Jul, presentation created 1 Jul → 9 days.
  (0 + 9) / 2 = 4.5, rounds to 5 — matches the tile exactly.

## The one thing this note exists to prevent

When `Staging` is eventually merged into live/`main`, git will not
recognise `476162fe7` / `702e56eca` as "the same commit" as `60ff10d6a` by
hash — check patch-id first if either looks "missing" during that merge:

```
git show 60ff10d6a705715e00c3fd4dc5807033c7e8db9b | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep 36eff93c1af77c806f0ec266ee1bbbe1407dedbe
```

A match means the content is already present on both sides — not a lost
commit, not a conflict to fight.
