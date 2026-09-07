# Spec: Listings

**Status:** Live (basic) — spec to be written during consolidation sprint

---

## What Exists

- Listing creation with property details, pricing, bedroom/bathroom configs
- Multi-agent assignment per listing
- P24 email parser (suburb extraction fixed)
- Listing import from P24 email (`matchAllUsersFromAgentCell()`, `$listing->agents()->sync()`)
- Listing status management
- P24 market data integration via `P24MarketDataService` (used in Presentations)

---

## Consolidation Items (Phase 1)

- [ ] All listing status values from settings table (not hardcoded)
- [ ] All property type values from settings table
- [ ] Listing links bidirectionally to Property pillar
- [ ] Navigation: all listing actions reachable from sidebar

---

## Co-listing visibility (secondary agent) — Live

A property may carry a **secondary (co-listing) agent** alongside the primary.

- **Storage:** `properties.pp_second_agent_id` (nullable FK → `users`). Originally
  added for P24/PrivateProperty dual-agent syndication, now also the spine of
  co-listing visibility. Set via the "Second Agent" card on the property page.
- **Relationship:** `Property::secondAgent()`.
- **Pillars:** Property ↔ Agent (a listing links to two practitioners).

**Behaviour:**
- The secondary agent sees the co-listed property in their **own "My Listings"**
  — the index scope matches `agent_id = me OR pp_second_agent_id = me`.
- **Both agents are shown on the property card** (and the table's agent column):
  the primary agent on top, the secondary agent **underneath** — just the name and
  the dark-blue avatar, no "Primary"/"2nd" labels.
- **Counts once:** a co-listed property is a single `properties` row, so it
  counts **once** in the Total / On Market KPIs even when both agents are in the
  selected filter set — verified by `test_co_listed_property_counts_once_in_the_kpi_totals`.
- The secondary agent has **full view + edit** access to the listing (same as the
  primary — co-agents are equals on the listing).
- **Admin/BM agent picker:** filtering by an agent surfaces listings where that
  agent is **primary OR secondary**. The scope is `where/orWhere` on the single
  `properties` row (no JOIN), so a co-listed property renders **exactly once**
  even when both the primary and secondary are in the selected set.

**Acceptance:** secondary agent's "My Listings" shows the co-listing badged
"Secondary"; admin filter by either agent surfaces it without duplication.
Covered by `tests/Feature/Properties/SecondaryAgentVisibilityTest.php`.

**Files:** `app/Http/Controllers/CoreX/PropertyController.php` (index scope +
`viewer_is_secondary` flag), `resources/views/corex/properties/index.blade.php`
(badges).

---

## Deeds-capture suburb resolution never guesses across provinces — Live (2026-09-07, QA1)

Investigation of properties #21014 and #15774 (Johan, live-testing and QA1) found
that promoting a deeds capture to a property could file it in the wrong PROVINCE
even though the deeds capture screen was showing the correct one on screen. "Melville"
exists as both a Johannesburg, Gauteng suburb and a Port Shepstone, KwaZulu-Natal one
in `p24_suburbs`; the resolver matched on suburb NAME alone and took whichever row
had the lower id (Johannesburg) — ignoring the province and coordinates the deeds
capture had already captured correctly.

**Root cause & fix — `app/Models/P24Suburb.php::lookup()`:** now takes the caller's
known province name and coordinates as optional arguments and disambiguates in
order, never guessing: (1) a single name match needs no disambiguation; (2) multiple
matches are narrowed by province when one is given; (3) if still ambiguous,
coordinates break the tie **only** when the nearest candidate is plausibly the same
physical suburb (≤25km) — a `p24_suburbs` centroid can itself be wrong (historically
geocoded from mis-attributed listings via this same method), so coordinates are a
last-resort tie-breaker, never a primary signal; (4) still unresolved → `null`.

**Call site — `DeedsCaptureController::promote()` (line ~809):** now passes the
tracked property's own `province`/`latitude`/`longitude` — all already captured
correctly from the source — into `lookup()`. Also now sets `p24_province_id` on the
created property from the resolved chain (previously left null at creation, only
ever backfilled by a later manual edit).

**Silent wrong-filing, never.** When `lookup()` returns `null` (genuinely
unresolved), the existing `p24_suburb_mismatch` flag is set exactly as it already
was for a "no match at all" case — the property is created with province/suburb
text kept but no P24 link, not filed under a guess. This is the existing "flag, don't
guess" mechanism already built for the no-match case; it now also covers the
ambiguous-match case.

**Related, separate fix (different branch, not yet merged into QA1):** the EDIT-time
desync between `properties.town` and `city`/`suburb`/`province` — where correcting a
property's location after creation left `town` stale — is fixed on
`fix/property-town-sync-qa1` (`AppliesP24Location::applyP24Location()` +
`_market-snapshot.blade.php` city-first precedence). This fix (deeds-capture
creation-time province resolution) is independent and does not depend on it, but
both address the same underlying investigation (properties #21014/#15774).

**Other callers of `P24Suburb::lookup()`, found but NOT touched (backward
compatible — new params are optional, unused calls behave exactly as before; out of
scope for this defect, reported per CLAUDE.md #2):**
- `app/Services/Presentations/Analytics/AbsorptionInflowService.php:190`
- `app/Services/Presentations/Analytics/PropConInsightsService.php:251`

**Acceptance:** covered by
`tests/Feature/Prospecting/DeedsCapturePromoteSuburbProvinceTest.php` — a KZN
Melville deeds capture promotes to Port Shepstone/KwaZulu-Natal; a genuine
Johannesburg Melville deeds capture still promotes to Johannesburg/Gauteng
(confirms the fix does not simply flip everything to KZN).

**Files:** `app/Models/P24Suburb.php`, `app/Http/Controllers/CoreX/DeedsCaptureController.php`.

---

## Pending Spec Items

The following require full spec before build:

- P24 image scraping into listing record
- Listing photo display in Presentations module
- Clickable P24 refs on price change log
- Listing-to-Flow integration (listing creation triggers mandate flow, etc.)

---

*Full spec to be completed during Phase 1 consolidation sprint.*
