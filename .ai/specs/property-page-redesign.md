# Spec: Property Page Redesign + Creation Flow Improvements

**Status:** Phase 1a in build (Andre, 2026-04-28) — Overview redesign live; Sticky Readiness Bar + remaining phases still pending review
**Date:** 2026-03-30 (orig), 2026-04-28 (Phase 1a build start)

---

## 2026-04-28 Addendum: Overview Redesign (Phase 1a)

The original A2 spec described a "Live Preview Card" replacing the stat boxes. After review with Andre on 2026-04-28, the Overview tab was found to be visually flat and chaotic — equally-weighted card grids, no hero, no pillar surfacing, cover image computed but never rendered, and inline forms styled identically to navigation tiles.

The Overview tab is now restructured as three zones:

1. **Hero band** (top) — cover image left (40%), details right (60%): title, suburb, price (XL right), status pill + listing-type badge over image, photo count chip, days-on-market, at-a-glance stats strip (single inline row, no boxes, dot separators), property/mandate/category chips, description preview (first 220 chars), action button row (Edit Details, Add Photos, Contacts, Call Agent).
2. **Activity column** (left 2/3) — Recent Activity timeline (existing `$activityTimeline`) + Key Dates as a 4-up label/value grid in a single panel.
3. **Pillar column** (right 1/3) — Listing Agent (Agent pillar), Owner/Seller/Landlord linked contact (Contact pillar, links to `corex.contacts.show`), Upcoming Showdays.

### Changes from original A2
- No collapse/expand toggle on hero — it IS the page now, not an extra widget.
- Quick Actions inline price/status forms removed (they reloaded the whole page; status change belongs in the sidebar header dropdown which already exists).
- Pillar surfacing made explicit — Owner contact and Listing Agent are now first-class on Overview, addressing CLAUDE.md non-negotiable #4 ("Pillars are the spine"). Empty-state for owner deep-links to Contacts tab.

### Files touched (Phase 1a)
- `resources/views/corex/properties/show.blade.php` — Overview tab block (lines ~832–984) replaced.
- `.ai/specs/property-page-redesign.md` — this addendum.

### Still pending (Phase 1c–4)
- A3 Info Tab Reorganisation
- A4 Smart Gallery
- B1–B5 Creation flow improvements

---

## 2026-04-28 Addendum: Sidebar Redesign (Phase 1b)

After the Overview redesign landed, the 280px left sidebar at [show.blade.php:44-177] became almost entirely redundant — cover image, status pill, LIVE chip, title, price, suburb, beds/baths/garages, floor/erf m², property type, category, mandate, agent, listed/expires were duplicated in the new hero.

The sidebar is restructured as a **command rail** focused on actions and readiness — content the rest of the page doesn't surface.

### New sidebar structure

1. **Identity strip (compact)** — 60-ish px row: thumb + status pill + LIVE chip (when published) + title. Just enough to anchor on long Info-tab scrolls.
2. **Action stack** — vertical button group:
   - Ad Builder (primary brand colour)
   - Market Property (Facebook blue, gated by `marketing_enabled`)
   - Live Preview (opens syndication modal at preview step)
   - Syndication (opens syndication modal at main step)
   - Duplicate (`POST corex.properties.duplicate`)
   - Archive (soft delete via `DELETE corex.properties.destroy` — confirms with user, recoverable per non-negotiable #1)
3. **Readiness panel** — A1's content reframed as a rail panel rather than a sticky top bar:
   - Completeness percentage (10 checks: title, price, status, suburb, description, beds, baths, listing agent, photos, listed_date)
   - Colour-coded bar (red <50%, amber 50–80%, green >80%)
   - Top 5 missing-fields checklist
   - Portal status: HFC Premium / Private Property / Property24 — Live / N fix / Off

### Architectural change: x-data hoisting

The right-pane "Syndication bar" originally owned its own `x-data="{ synOpen, synStep }"` scope. To trigger the same modal from the sidebar, that state was hoisted to the page-root x-data on [show.blade.php:6]:

```js
x-data="{ activeTab: '...', synOpen: false, synStep: 'main' }"
```

The visible bar (Live Preview + Syndication trigger buttons) was deleted — those triggers now live in the sidebar Action stack. The teleported modal (`<template x-teleport="body">`) stays where it was; it now inherits synOpen/synStep from the root scope.

### Files touched (Phase 1b)
- `resources/views/corex/properties/show.blade.php` — root x-data extended; aside (lines 44-177) replaced; syndication bar wrapper + duplicated trigger buttons removed; modal teleport retained.

### Pending (separate ticket)
- **Linked Deal pillar card** — sidebar should show the active linked Deal/mandate/offer when one exists. Property model has no `deals()` relation yet; deferred until [.ai/specs/deals.md](.ai/specs/deals.md) defines the Property↔Deal binding.

---

---

## Problem

The current property page has:
- A static info block that doesn't look like what buyers see
- Scattered fields across Overview and Info tabs with no visual hierarchy
- PP Feed-specific fields mixed into general sections
- No at-a-glance readiness indicator for portals
- Gallery is a flat dump of photos with no categorisation
- No quick-create, no clone, no completeness scoring
- No smart defaults — agents manually set province, branch, agent every time

---

## PART A: Property Show Page Redesign

### A1. STICKY READINESS BAR (locked at top)

A slim bar that sticks to the top of the property page (below the page header), always visible regardless of scroll position or active tab. Contains:

```
┌──────────────────────────────────────────────────────────────────────┐
│  ⬤ 75%  │ ✅ Basic  ✅ Address  ⚠️ Photos (3)  ✅ Agent  │ [Save]  │
│          │ 🌐 Website ✅  🟢 PP Active  🔵 P24 Active    │         │
└──────────────────────────────────────────────────────────────────────┘
```

- **Completeness score** — colour-coded circle (red <50%, amber 50-80%, green >80%)
- **Checklist items** — inline badges, click to jump to relevant tab/section:
  - Basic Info (title, price, type, status)
  - Address (suburb, street)
  - Description
  - Photos (count + recommended minimum 5)
  - Agent assigned
- **Portal status** — Website / PP / P24 with status and quick links
- **Save button** — always accessible, submits the Info tab form
- Collapses to a single-line on mobile (score + save button only)

### A2. LIVE PREVIEW CARD (collapsible, on Overview tab)

Replaces the current stat boxes with a card that looks like an actual P24/website listing. Has a **collapse/expand toggle** button (chevron) so agents can hide it once they've seen it.

```
┌─ Live Preview ──────────────────────────────── [▼ Collapse] ─┐
│  ┌─────────────┐                                              │
│  │             │  3 Bedroom House for Sale                     │
│  │   COVER     │  Uvongo Beach, Margate                        │
│  │   IMAGE     │                                              │
│  │             │  R 1,200,000          [FOR SALE] badge        │
│  └─────────────┘                                              │
│                                                                │
│  🛏 3  ·  🚿 2  ·  🚗 2  ·  📐 200 m²  ·  📏 1,028 m²        │
│                                                                │
│  Sole Mandate  ·  Residential  ·  Listed 20 Mar 2026           │
│                                                                │
│  ┌──────┐  Andre Roets                                        │
│  │ PHOTO│  Home Finders Coastal  ·  039 315 0315               │
│  └──────┘                                                      │
│                                                                │
│  "Beautiful family home with sea views, spacious living        │
│   areas and a sparkling pool..."  (first 150 chars)            │
└────────────────────────────────────────────────────────────────┘
```

- **Updates live** via Alpine.js as fields are edited on Info tab
- Cover image = first gallery photo (placeholder if none)
- Agent photo from Portal Agents section
- Status badge colour-coded
- Description preview (truncated)
- Collapse state saved in localStorage
- Read-only — editing happens on Info tab

### A3. INFO TAB REORGANISATION

**Section: Listing Details**
- Title / Headline
- Description (textarea)
- Price + Price on Application toggle
- Listing Type (Sale / Rental)
- When Rental: show inline sub-section with Rental Amount, Deposit, Lease Period, Rental Price Type (moved from Fees & Lease, relabelled from "Price Type (PP Feed)")

**Section: Classification**
- Property Type + Category (side by side)
- Property Status + Mandate Type (side by side)
- Listed Date + Expiry Date (side by side)

**Section: Address**
- Street Number + Street Name (side by side)
- Complex Name + Unit Number (side by side)
- Suburb + City/Town (side by side)
- Province (default KZN)
- P24 Suburb mapping indicator: "Mapped: Uvongo Beach (ID: 33106) ✅" or "⚠️ Not mapped — set in P24 Suburb Settings"
- Lat / Lng (optional)

**Section: Pricing & Costs**
- Rates & Taxes, Levy, Special Levy
- Commission %, Admin Fee, Marketing Fee

**Section: Assignment**
- Primary Agent, Second Agent, Branch
- Publish toggle

**Section: Features & Spaces** (unchanged — current editor works well)

### A4. SMART GALLERY WITH CATEGORY BUCKETS

Replace flat gallery with categorised photo manager:

**Upload area:** Drag-drop zone or file picker → photos land in "Unsorted"

**Category buckets** (drag-reorderable):
- Exterior
- Lounge / Living
- Kitchen
- Bedrooms
- Bathrooms
- Garden / Pool
- Views
- + Add Custom Category

**Behaviour:**
- Drag photos from Unsorted into categories
- Drag entire categories up/down — photos move with them
- Drag photos within a category to reorder
- First photo in first category = cover image (star badge)
- Empty categories collapse to single-line drop target
- Multi-select with shift+click
- Photo captions auto-generated from category name (sent to P24)
- Quick actions on hover: rotate, delete, set as cover, move to category
- "Unsorted" photos go at the end — never blocked from publishing
- Categories auto-populate from spaces (3 bedrooms → Bedrooms bucket appears)

**Storage:**
```json
{
  "categories": [
    {"name": "Exterior", "images": ["/storage/properties/16/img1.jpg"]},
    {"name": "Bedrooms", "images": ["/storage/properties/16/img2.jpg", "img3.jpg"]}
  ],
  "unsorted": ["/storage/properties/16/img4.jpg"]
}
```

`allImages()` flattens for backward compat. P24 mapper reads category name as `caption`.

### A5. PORTAL AGENTS (already built, keep as-is)

Shows primary + second agent with photos at bottom of Overview tab. No changes needed.

### A6. REMOVE / RELOCATE

- **Price Type (PP Feed)** → into Listing Details as "Rental Price Type", only visible for rentals
- **PP visibility toggles** → keep in sidebar syndication panel
- **PP exclusive days** → keep auto-calculated from dates

---

## PART B: Property Creation Flow Improvements

### B1. SMART DEFAULTS ON CREATE

When creating a new property:
- Auto-set province to "KwaZulu-Natal"
- Auto-set branch to the creating agent's branch
- Auto-assign creating agent as primary agent
- Default status to "For Sale"
- Default listing type to "Sale"
- When suburb is typed, show P24 suburb mapping status below the field

### B2. QUICK-CREATE (Minimal Fields)

A lightweight create mode that only requires: **title, suburb, price, property type, listing type**. Everything else optional, can be filled later.

- Accessible from Properties index: "Quick Create" button next to existing "Create"
- Uses the same form but with optional fields collapsed/hidden
- After save, redirects to the full property page where they can continue adding details
- Readiness bar immediately shows what's still missing

### B3. CLONE / DUPLICATE

On the property show page, a "Duplicate" action button (in the header area or sidebar):
- Creates a copy of the property with:
  - Same address, features, spaces, description, photos
  - Same agent, branch, category, type
  - Cleared: status (→ Draft), price (→ empty), unit number (→ empty), P24/PP syndication fields (→ reset)
  - New title: "[Original Title] (Copy)"
- Opens the new property in edit mode
- Perfect for developments with similar units

### B4. QUICK-CREATE FROM CONTACT

On a seller/landlord contact's profile page, add: "Create Listing" button
- Pre-links the contact to the new property (as seller/landlord role)
- Pre-fills address from contact record if available
- Redirects to property page with contact already linked

### B5. PROPERTY COMPLETENESS SCORE (Index View)

On the properties index/list page, each property card shows a completeness percentage:

```
┌─────────────────────────────────────────┐
│  3 Bed House · Uvongo Beach             │
│  R 1,200,000 · For Sale · Andre Roets   │
│  ████████░░ 82%                          │
└─────────────────────────────────────────┘
```

- Score calculated from: title, price, description, suburb, street, type, status, photos (count), agent
- Colour-coded: red (<50%), amber (50-80%), green (>80%)
- Agents see at a glance which listings need attention

---

## What Does NOT Change

- Sidebar layout (syndication panels, publish, preview)
- Tab structure (Overview, Info, Gallery, Contacts, Notes, Drive, Core Matches)
- Features & Spaces editor UI
- Contacts, Notes, Drive, Core Matches tabs
- All existing functionality — this is restyle/reorganise + new creation helpers

---

## Build Phases

### Phase 1: Sticky Readiness Bar + Live Preview
- Sticky bar with completeness score, checklist badges, portal status, save button
- Collapsible live preview card on Overview tab
- Remove/relocate PP-specific fields

### Phase 2: Info Tab Cleanup + Smart Defaults
- Reorganise Info tab sections
- Conditional rental fields
- Suburb mapping indicator
- Smart defaults on create
- Quick-create mode

### Phase 3: Smart Gallery
- Category buckets with drag-to-sort
- Auto-captions for P24
- Cover image management
- Auto-populate from spaces

### Phase 4: Clone + Quick-Create From Contact + Completeness Score
- Duplicate property action
- Contact → Create Listing flow
- Completeness % on properties index

---

## Acceptance Criteria

- [ ] Sticky readiness bar always visible with save button
- [ ] Live preview updates reactively, collapsible with state saved
- [ ] Readiness checklist shows accurate status for all portals
- [ ] Info tab sections logically grouped
- [ ] Rental fields only show when listing type = Rental
- [ ] Smart defaults applied on create (province, branch, agent)
- [ ] Quick-create works with minimal fields
- [ ] Clone creates accurate copy with reset syndication fields
- [ ] Gallery categories can be created, reordered, populated
- [ ] Photo captions feed through to P24 submission
- [ ] Completeness score shown on properties index
- [ ] No existing functionality broken
- [ ] Mobile-usable
