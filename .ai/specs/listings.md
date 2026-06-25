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

## Pending Spec Items

The following require full spec before build:

- P24 image scraping into listing record
- Listing photo display in Presentations module
- Clickable P24 refs on price change log
- Listing-to-Flow integration (listing creation triggers mandate flow, etc.)

---

*Full spec to be completed during Phase 1 consolidation sprint.*
