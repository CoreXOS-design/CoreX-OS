# Audit note — DeedsCaptureLinkService::availableDeeds() dropped the archived/duplicate exclusion

**Date:** 2026-08-19
**Found by:** cc6 (reconcile lane), during independent verification of the qa1-deal-external-guard → Staging merge (merge commit `27b9e227f`, folded to `origin/Staging` at `797dccd7c`).
**Status:** Follow-up, not a blocker. Recorded for tracking, not acted on.

## What changed

`DeedsCaptureLinkService::availableDeeds()` — the query behind the "Link a deed"
picker on the seller-outreach compose screen — was rewritten by qa1's CX-101 fix
(`aff2670e8` and the commits around it, 2026-08-19) to stop hiding a deed that was
already linked to a property or had no owner parsed, fixing a real bug Johan hit
on a real property (listing 2403 / TP #748).

Staging's **pre-merge** version of this method filtered candidates with:

```php
->where('status', TrackedProperty::STATUS_ACTIVE)
```

— which excluded any `TrackedProperty` with `status` of `archived` or `duplicate`
from the picker. The merged (qa1) version replaces this whole eligibility check
with narrower, more targeted exclusions (a deed already consumed by a *different*,
already-pitched listing) — deliberately, to fix the reported bug — but does not
carry forward any equivalent exclusion for `archived`/`duplicate` status. Grepped
the current file for `STATUS_ACTIVE`, `'archived'`, `'duplicate'` — zero matches
anywhere in `availableDeeds()` or its helpers.

## Impact assessment

Checked live's actual data (`nexus_os.tracked_properties`) directly:

```sql
SELECT status, capture_kind, COUNT(*) FROM tracked_properties
WHERE status IN ('archived','duplicate') GROUP BY status, capture_kind;
-- 0 rows
```

**Zero rows on live currently carry `archived` or `duplicate` status**, so this
has no observable effect today. The gap is real but currently inert.

## Why it's a follow-up, not a blocker

- No live data is affected right now (confirmed above, not assumed).
- The rewrite itself is well-reasoned and deliberately documented in its own
  commit message — this is a side-effect of a real bug fix, not carelessness.
- Fixing it properly means deciding whether `archived`/`duplicate` should be
  excluded outright (as before) or shown-with-a-note the same way CX-101 now
  handles "already linked" and "no owner" — that's a product decision, not a
  one-line patch, and shouldn't be made under deploy pressure.

## Recommended follow-up

Re-add an explicit `archived`/`duplicate` exclusion (or a stated-not-hidden
treatment, consistent with the rest of CX-101's philosophy) to
`DeedsCaptureLinkService::availableDeeds()`, in `app/Services/Prospecting/DeedsCaptureLinkService.php`.
Before shipping the fix, confirm intent with Johan: hide outright, or show with
a plain-language note like the other two states this method now handles.
