# MIC — Promoted Property Should Always Be Excluded From the Canvass Pool (Proposal)

**Spec ID:** `mic-promoted-property-exclusion-proposal`
**Date:** 2026-08-19
**Author:** Claude (proposal only — not approved, not built)
**Status:** PROPOSAL. Not implemented. Requires Johan's go-ahead before any code changes, and not inside a deploy window.
**Related:** `.ai/specs/mic-complete-spec.md`; commits `497b6447a`, `9a692b188` (this session's claim-release guard, which this proposal complements but does not replace).

---

## 1. What goes wrong today, in plain terms

When a property gets promoted onto the agency's books — an agent worked a Market Intelligence lead through to a real property record — that new property is created in a "draft" state. Draft is the correct starting state; it's what lets an agent finish paperwork before the property is fully live in the system.

The problem: the *only* thing currently stopping that freshly-promoted property from showing back up in the canvassing list (the list agents search for new leads) is the claim record tied to it. This session's guard work (commits `497b6447a`, `9a692b188`) closed off every button and background job that could break that claim record. But the claim is still the single lock on the door. If that lock is ever bypassed by some *other* route we haven't guarded — a future feature, an edge case in matching, a different promotion path — the promoted property has no second line of defence. It would quietly reappear in the canvassing list, indistinguishable from a lead nobody has ever touched, and an agent could pitch a seller who is already a client.

In short: today, "already on the books" is remembered only by the claim. It should be remembered by the property itself.

## 2. Recommended fix

Make the canvassing-pool filter itself always exclude a property once it has been promoted — regardless of claim state.

Concretely: `OnMarketStockService::applyNotStock()` (`app/Services/Prospecting/OnMarketStockService.php:272`) is the shared filter that already decides "is this listing agency stock, on-market" for every screen that hides stock from the canvass pool. Extend it (or add a sibling check applied everywhere it's used) so that a listing whose linked `tracked_properties.promoted_to_property_id` is set is *always* excluded, the same way on-market stock is always excluded today — independent of the property's own `status` column (draft or otherwise) and independent of whether an active claim currently exists.

This makes "promoted" a property-level fact the filter checks directly, instead of an inference from "does a claim happen to still be alive and correctly linked."

## 3. What could break, and who sees a different list

`OnMarketStockService` is a shared building block. Every consumer that calls it (or the MIC canvass filter that wraps it) would change behaviour the moment a property is promoted, not just once its `status` later flips off-draft. Confirmed callers today:

- `app/Http/Controllers/CoreX/MarketIntelligenceController.php` — the MIC Work list itself (the primary target of this fix).
- `app/Services/MarketIntelligence/OpportunityPocketService.php`, `CompetitiveLandscapeService.php`, `DemandSupplyMatrixService.php`, `StrategicBriefService.php` — the MIC intelligence tiles (heatmaps, briefs, competitive views). These currently may still be counting a promoted-but-draft property as "market supply" rather than "our stock" — this fix would move it into the excluded set for them too, which is probably *correct* but is a behaviour change nobody has explicitly signed off on for those screens.
- `app/Services/Prospecting/PropertyIntelligencePanelService.php`, `SuggestedActionResolver.php` — per-property intelligence panel and suggested-action logic.
- `resources/views/prospecting/_buyer-matches-panel.blade.php`, `resources/views/corex/properties/show.blade.php`, `resources/views/layouts/corex-sidebar.blade.php` — buyer-match panel, the property page itself, and a sidebar count/badge.

Risk: none of these are destructive (nothing gets deleted, nothing hard-blocks), but several agency-facing counts and lists (sidebar badge counts, market-supply tiles) would shift lower the moment this ships, because promoted-draft properties currently double-count as both "our property" and "market inventory." That is arguably the correct fix everywhere, but every one of those five service files needs its own before/after check — this is not a one-file change even though the root cause is one filter.

## 4. How to prove it's right before shipping

1. Before the change: snapshot the current row counts for each of the five services above, for a handful of real agencies/suburbs, and note the raw list of listing IDs each one currently returns.
2. Apply the change on QA1 only.
3. Re-run the same snapshots. The only IDs that should disappear are ones whose `tracked_properties.promoted_to_property_id` is set. Any other ID that moves is a regression — stop and investigate before going further.
4. Specifically re-test the exact rows already known to be affected this session: opportunity 8056 (358 Sutherland Crescent) and claim 302's property (listing 4046) — confirm both are excluded from every one of the five services' output, not just the MIC Work list.
5. Spot-check one on-market listing that is *not* promoted, to confirm the existing stock exclusion still behaves exactly as before (no regression to the working case).
6. Only then does this get proposed for Staging, and only on Johan's explicit go — same as every other change in this codebase.

## 5. Does this need a data repair, or is the code fix enough going forward?

Code fix alone is enough **going forward** — once shipped, every future promotion is protected the moment `tracked_properties.promoted_to_property_id` is set, with no dependency on the claim surviving.

It does **not** retroactively fix anything already wrong in the data. Separately from this proposal, this session's data audit found 99 already-damaged claim rows on QA1 (`is_active=false` on an already-promoted property) — those rows are a data-repair question, already reported, explicitly *not* actioned pending Johan's decision, and unaffected by whether this code fix ships. This proposal and that repair are two separate decisions; shipping one does not require or imply the other.

## 6. Explicitly out of scope for this proposal

- No code changes are included with this document. This is a proposal only.
- No migration is required — this is filter logic, not a schema change.
- The listing-level write-back gap (`prospecting_listings.matched_property_id`/`pitched_at` not being set by every promotion path) is a separate, already-flagged issue owned by the compose lane — this proposal does not touch it and does not depend on it being fixed first.
