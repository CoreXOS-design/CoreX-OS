# Outcomes nav badge removal — live cherry-pick, recorded

**Date:** 2026-08-20

## What happened

Following the decision_at date-column fix (see `.ai/audits/2026-08-20-
outcomes-dashboard-date-column-live-cherry-pick-divergence.md`), Johan
rejected the earlier "N due" relabel outright:

> "This makes no sense. You are showing outcomes and on the screen its
> about outcomes. yet you are showing me 5 due? Is this the place where
> agents will capture presentation outcomes? If its not then why would we
> show it here? ... I would just remove it - I understand showing counts
> on menu items where the actual work is being done. like mic - it shows
> how many properties there are. makes sense. but showing due outcomes on
> outcomes?"

His rule: a count on a menu item counts what THAT screen is for. Answered
before removing: outcomes are captured via `PresentationOutcomeController::
record()` (`POST`/`PATCH presentations/{presentation}/outcome`), reached
from a presentation's own page, itself reached from the **"Presentations"**
nav item (`presentations.index`) — never from the Outcomes Dashboard, which
is read-only. So the "N due" count would follow Johan's own rule if it sat
on "Presentations" instead (told to Johan directly, not built — his call).
On "Outcomes" it had no home regardless of labelling. Removed outright.

Built and tested on qa1 (`qa1-outcomes-datefix`, commit `1e80a9b46`, 5/5
tests green — badge-presence test replaced with a badge-ABSENCE test),
approved directly by Johan for live, no Staging stop this time.

Files touched (exactly, in every application): `resources/views/layouts/
corex-sidebar.blade.php`, `tests/Feature/Presentation/
PresentationOutcomesDashboardTest.php`.

## Deploy detail — a genuine concurrent push, handled without touching Johan's WIP

The first `git push origin main` was rejected (non-fast-forward): another,
unrelated, already-authorised lane pushed `0ed127f4c` (the buyers-report
redesign promotion) to `origin/main` between this session's fetch and push.
Confirmed zero file overlap (`git diff --stat` — 23 buyers-report files,
none shared with this fix's 2 files) before proceeding.

Live's checkout (`/corex`) could not simply `pull`/rebase — it carried
Johan's own uncommitted WIP (`template-67.blade.php` modified, `template-
68.blade.php` new) that a `git reset --hard` or `git rebase` would have
discarded or blocked on. Resolution, in order:

1. Cherry-picked the same commit fresh onto the new `origin/main` tip in an
   **isolated temporary worktree** (`/tmp/corex-live-push-tmp`), never
   touching `/corex`'s working tree — pushed from there
   (`0ed127f4c` → `554ccc09f`).
2. Brought `/corex`'s own checkout up to that same tip via `git reset
   --soft` (moves HEAD only, never touches the working tree or discards
   anything) back to the last common ancestor, then `git merge --ff-only
   origin/main` twice (once to `0ed127f4c`, once more after a fresh fetch
   to `554ccc09f`) — a fast-forward only ever updates files that actually
   differ in the incoming commits, so Johan's untouched files were never
   part of that diff and were left exactly as they were throughout.
   Confirmed via `git status` before, mid-way, and after: only his 2 WIP
   files ever showed as dirty, at every step.
3. Removed the temporary worktree once its work had landed and was
   confirmed on `origin/main`.

No destructive git command (`reset --hard`, `stash`, `checkout --`) was
used at any point. Worktree removed the same session per disk hygiene.

## The commit applications — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| qa1 (`qa1-outcomes-datefix`) | `1e80a9b46dc33152f9e7191da2b608e3564917a8` | `bdb19b95ce69d938b1f823488af25b87b49bca87` |
| Live (`main`) | `554ccc09f3d72a2071dd5dc8e14b3dca6cdd7e93` | `bdb19b95ce69d938b1f823488af25b87b49bca87` |

Identical patch-ids — same 2-file tree change, different commit hash
because each was cherry-picked onto a different parent history.
`origin/main` moved `0ed127f4c` → `554ccc09f`, and `/corex`'s own checkout
was brought to the same tip as described above.

**Not yet propagated to Staging** — this badge-removal fix has not been
cherry-picked to `origin/Staging`. Staging still carries the earlier "N
due" labelled version (`.ai/audits/2026-08-20-outcomes-dashboard-date-
column-cherry-pick-divergence.md`). Only authorised for live this round;
flagging so Staging isn't assumed to match.

## Verification on live

`php8.3-fpm` reloaded (resolved dynamically from `/etc/nginx/sites-
enabled/corexos.co.za`). `view:clear` run. Reflection confirmed the
controller and Composer root install path both resolve from `/corex`.
`migrate:status` confirmed zero pending migrations. Johan's uncommitted
WIP confirmed untouched via `git status` (unchanged throughout).

Rendered server-side as Retha Kelly (user 24, agency 1), same window
(22 May – 20 Aug 2026):

- **Badge: gone.** Nav markup is exactly `<span>Outcomes</span></a>` — no
  sibling span, no count, no "due" text anywhere in the response.
- **Date fix intact:** Total outcomes still **2**, Won mandates 2 (100%),
  Avg days to outcome still **5d** — unchanged from before this commit,
  confirming the badge removal touched nothing else on the page.

## The one thing this note exists to prevent

When `Staging` is eventually merged into live/`main`, git will not
recognise a Staging-side application of `1e80a9b46` as "the same commit"
as `554ccc09f` by hash if one is cherry-picked there later — check patch-id
first if either looks "missing":

```
git show 554ccc09f3d72a2071dd5dc8e14b3dca6cdd7e93 | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep bdb19b95ce69d938b1f823488af25b87b49bca87
```

A match means the content is already present — not a lost commit, not a
conflict to fight.
