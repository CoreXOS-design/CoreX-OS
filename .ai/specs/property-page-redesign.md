# Spec: Property Page Redesign + Creation Flow Improvements

**Status:** Draft — Awaiting Johan's review
**Date:** 2026-03-30

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
