# buyers:autoland-pipeline schedule line — live/Staging divergence by design, recorded

**Date:** 2026-08-20

## What happened

Johan authorised scheduling `buyers:autoland-pipeline` (AT-72 — idempotent,
agency-scoped, dry-runnable, audited buyer-pipeline safety net; the command
already existed but was never scheduled). Same situation as CX-107's
lead-ticks fix (`.ai/audits/2026-08-20-cx107-lead-ticks-cherry-pick-divergence.md`):
`Staging` carried two other, unauthorised bodies of work at the time (cc3's
buyer-notes feature, cc6's buyers-report page), so a full `Staging` → live
merge was not an option. The one-line scheduler change was committed
directly onto live's `main` in isolation, then applied a second time to
`Staging` so the divergence has a real second commit to reconcile against
later, not a hypothetical one.

Before the schedule line landed anywhere: ran `buyers:autoland-pipeline
--dry-run` against LIVE. Result: 0 candidates. Every contact with a wishlist
on live already has a `buyer_state` — the AT-72 observer hook is currently
keeping `is_buyer` honest, so this schedule line has zero effect on today's
379-strong Buyers Pipeline. It is a forward-looking safety net only (a
future bulk import, raw UPDATE, or any path that bypasses the observer).

Also confirmed before relying on the schedule line at all: the system cron
invoking `schedule:run` is genuinely wired on live (`* * * * * cd /corex &&
php artisan schedule:run`, `cron.service` active) — a schedule entry with no
cron calling it would be a silent no-op, and was checked, not assumed.

## The two commits — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| Live (`main`) | `b70555f20490592e8800c1f5ecc386cddf2b92ab` | `dfe0906db6d51ddf705f4c8e1f753a5e33821a13` |
| Staging | `44c4f228f466cfde6a8b184bef2c6b97fb512713` | `dfe0906db6d51ddf705f4c8e1f753a5e33821a13` |

Confirmed identical patch-ids (`git show <sha> | git patch-id --stable`) —
same tree change (`routes/console.php`, +15 lines, the `buyers:autoland-pipeline`
schedule entry), different commit hash because the two commits have
different parents (live's `main` history vs. `Staging`'s history at the
point each was made).

## The one thing this note exists to prevent

**When `Staging` is eventually merged into live/`main`** — whenever cc3's
notes feature and cc6's buyers-report are actually authorised and that merge
happens — `44c4f228f`'s content will already be present on live under hash
`b70555f20`. Git recognises this by tree/patch content, not by hash. A plain
merge handles this fine (no conflict on `routes/console.php` — both sides
already have equivalent content at that hunk) — but if anyone manually
cherry-picks or resolves a conflict on that future merge and sees
`44c4f228f` "missing" from live's history by hash, **do not re-apply it and
do not panic at what looks like a conflict.** Check patch-id first:

```
git show 44c4f228f | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep dfe0906db6d51ddf705f4c8e1f753a5e33821a13
```

If it matches `b70555f20` (or whatever live's hash is by then), the content
is already live. The divergence is expected and accounted for here — it is
not a lost commit and not a merge conflict to fight.
