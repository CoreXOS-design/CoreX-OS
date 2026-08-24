# CX-107 lead-ticks fix — live/Staging divergence by design, recorded

**Date:** 2026-08-20

## What happened

The lead-ticks predicate fix (Johan, CX-107 — "leads" now tick-eligible on
the property-viewing calendar screen, not just "buyers") was built and
tested on `qa1-viewing-ticks-leads`, ported to `Staging` as commit
`bee727cae`, then needed to reach live urgently while `Staging` also
carried two other, unrelated, NOT-yet-authorised bodies of work (cc3's
buyer-notes feature, cc6's buyers-report page — 17 commits, 31 files,
~2988 lines between live's prior HEAD and `Staging`'s tip). A full
`Staging` → live merge would have shipped both of those unauthorised.

**Decision (Johan):** cherry-pick the one authorised commit directly onto
live rather than hold it behind the other two features, or attempt to
isolate them out of `Staging` under time pressure.

## The two commits — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| Staging | `bee727cae210d8bac1cc4b7a8efaf9aa51484173` | `067a012bd93220102130d0a099d2220fd453725c` |
| Live (`main`) | `7c50df022521f8367d765c5424a20083fc6543d6` | `067a012bd93220102130d0a099d2220fd453725c` |

Confirmed identical patch-ids (`git show <sha> \| git patch-id --stable`) —
same tree change, same 4 files (`CalendarController.php`,
`CalendarEventLink.php`, `CalendarEventService.php`, the new
`PropertyOwnersBuyerLeadClassificationTest.php`), different commit hash
because they have different parents (`Staging`'s history vs. live's
`main` history at the point each was made).

## The one thing this note exists to prevent

**When `Staging` is eventually merged into live/`main`** — whenever cc3's
notes feature and cc6's buyers-report are actually authorised and that
merge happens — **`bee727cae`'s content will already be present on live**
under hash `7c50df022`. Git will not recognise this as "already applied"
by hash; it recognises it by tree/patch content. A plain `merge` handles
this fine on its own (the merge will simply produce no conflict on these
4 files, since both sides already have equivalent content) — but if
anyone reaches for a manual cherry-pick or a conflict-resolution pass on
that future merge and sees `bee727cae` "missing" from live's history by
hash, **do not re-apply it and do not panic at what looks like a
conflict.** Check patch-id first:

```
git show bee727cae | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep <the patch-id above>
```

If it matches `7c50df022` (or whatever live's cherry-picked hash is by
then), the content is already live. The divergence is expected and
accounted for here — it is not a lost commit and not a merge conflict to
fight.
