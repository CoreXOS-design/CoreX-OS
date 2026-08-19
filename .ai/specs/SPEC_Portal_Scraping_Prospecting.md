# SPEC: Portal Scraping — Chrome Extension + Prospecting Module

## Overview

Agents browse P24 or Private Property, click a Chrome Extension button, and CoreX captures every listed property from the search results — all pages, one click. Data lands in a dedicated Prospecting / Market Intelligence module where agents can view, filter, and use the data for canvassing, presentations, and competitive analysis.

---

## 1. CHROME EXTENSION — Portal Capture V2

### How It Works

1. Agent browses to P24 or PP search results page
2. Clicks the CoreX Portal Capture extension icon
3. Extension detects which portal (P24 or PP) from the URL
4. Extension scrapes the current page of search results
5. Extension reads pagination — calculates total pages
6. Extension auto-fetches all remaining pages in the background
7. Extracts listing data from each page's DOM
8. Sends all listings to CoreX API in one batch
9. Shows progress: "Capturing page 2 of 5... Page 3 of 5... Done! 94 listings imported."

### Data Captured Per Listing

| Field | P24 Source | PP Source |
|-------|-----------|-----------|
| Property address | .p24_content .p24_address or listing title | Listing card address |
| Suburb | Extracted from address or search context | Extracted from address |
| Asking price | .p24_price or data attribute | Price element |
| Bedrooms | Icon/feature count | Feature count |
| Bathrooms | Icon/feature count | Feature count |
| Garages | Icon/feature count | Feature count |
| Property size (m²) | Feature if shown | Feature if shown |
| Erf size (m²) | Feature if shown | Feature if shown |
| Property type | Listing badge or search filter context | Listing type badge |
| Listing agent name | Agent name on card | Agent name |
| Listing agency name | Agency name on card | Agency name |
| Portal listing number | P24 ref from URL or data attribute | PP ref from URL or data attribute |
| Portal URL | href from listing card link | href from listing card link |
| Thumbnail photo | First image src from listing card | First image src from listing card |
| Portal source | "p24" or "pp" (auto-detected) | "p24" or "pp" |
| Scraped at | Timestamp of capture | Timestamp |

### Auto-Pagination Logic

P24 pagination:
- Total results shown in header: "48 results for Houses for sale in Shelly Beach"
- Pages use ?Page=2, ?Page=3 URL params
- 20 listings per page
- Extension fetches: total / 20 = number of pages, loops through

PP pagination:
- Similar pattern — investigate DOM during build
- Extension reads total count and page links

### Extension UI (Popup)

```
┌─────────────────────────────────┐
│  CoreX Portal Capture           │
│                                 │
│  ✓ Property24 detected          │
│  Search: Houses, Shelly Beach   │
│  Results: 48 listings (3 pages) │
│                                 │
│  [🔍 Capture All Listings]      │
│                                 │
│  Progress: ██████░░░░ 67%       │
│  Page 2 of 3...                 │
│                                 │
│  Status: Connected to CoreX     │
│  Token: ●●●●●●●● (valid)       │
│                                 │
│  [⚙ Settings]                   │
└─────────────────────────────────┘
```

After completion:
```
┌─────────────────────────────────┐
│  CoreX Portal Capture           │
│                                 │
│  ✅ 48 listings captured!       │
│  New: 42 | Updated: 6          │
│                                 │
│  [View in CoreX →]              │
│  [Capture Another Search]       │
└─────────────────────────────────┘
```

### Authentication

- Agent's API token stored in extension local storage
- Token set during first-time setup (paste from CoreX profile)
- Token validated on each API call
- Extension shows connection status

### Portal Detection

```javascript
const url = window.location.href;
if (url.includes('property24.com')) return 'p24';
if (url.includes('privateproperty.co.za')) return 'pp';
return null; // Not on a supported portal
```

---

## 2. API ENDPOINT — Receive Scraped Data

### Route
POST /api/prospecting/import

### Auth
Bearer token (agent's API token via Sanctum)

### Payload
```json
{
  "source": "p24",
  "search_context": {
    "url": "https://www.property24.com/for-sale/shelly-beach/...",
    "search_term": "Houses for sale in Shelly Beach",
    "total_results": 48,
    "pages_captured": 3,
    "captured_at": "2026-03-18T00:30:00Z"
  },
  "listings": [
    {
      "portal_ref": "P24-116950342",
      "portal_url": "https://www.property24.com/for-sale/...",
      "address": "14 Marine Drive, Shelly Beach",
      "suburb": "Shelly Beach",
      "price": 2970000,
      "bedrooms": 4,
      "bathrooms": 3,
      "garages": 2,
      "property_size_m2": null,
      "erf_size_m2": 850,
      "property_type": "House",
      "agent_name": "Jane Smith",
      "agency_name": "Seeff Shelly Beach",
      "thumbnail_url": "https://images.prop24.com/...",
      "source": "p24"
    }
  ]
}
```

### Controller Logic
- Validate token, identify agent + branch + agency
- For each listing:
  - Check if portal_ref already exists in prospecting_listings
  - If exists: update price, check for price change → log to price_history
  - If new: create record, download thumbnail to local storage
- Store the search capture metadata (search_captures table)
- Return: { imported: 42, updated: 6, total: 48 }

---

## 3. DATABASE

### Table: prospecting_listings

| Column | Type | Purpose |
|--------|------|---------|
| id | bigint PK | |
| agency_id | bigint FK | Which agency captured this |
| captured_by_user_id | bigint FK | Agent who captured it |
| portal_source | enum('p24','pp') | Which portal |
| portal_ref | string | P24-116950342 or PP ref |
| portal_url | string | Full listing URL |
| address | string | Full property address |
| suburb | string | Extracted suburb |
| district | string nullable | Extracted district/area |
| price | integer | Asking price in cents or rands |
| bedrooms | smallint nullable | |
| bathrooms | smallint nullable | |
| garages | smallint nullable | |
| property_size_m2 | decimal nullable | Floor area |
| erf_size_m2 | decimal nullable | Land size |
| property_type | string nullable | House, Apartment, etc. |
| agent_name | string nullable | Listed agent |
| agency_name | string nullable | Listed agency |
| thumbnail_path | string nullable | Local path to downloaded thumbnail |
| first_seen_at | datetime | When first captured |
| last_seen_at | datetime | Last time seen in a scrape |
| price_changed_at | datetime nullable | Last price change |
| is_active | boolean default true | Still appearing in searches |
| created_at | datetime | |
| updated_at | datetime | |
| deleted_at | datetime nullable | Soft delete |

**Unique constraint:** agency_id + portal_source + portal_ref

### Table: prospecting_price_history

| Column | Type | Purpose |
|--------|------|---------|
| id | bigint PK | |
| prospecting_listing_id | bigint FK | |
| old_price | integer | Previous price |
| new_price | integer | New price |
| changed_at | datetime | When detected |

### Table: prospecting_searches

| Column | Type | Purpose |
|--------|------|---------|
| id | bigint PK | |
| agency_id | bigint FK | |
| user_id | bigint FK | Agent who ran the search |
| portal_source | enum('p24','pp') | |
| search_url | text | Full search URL |
| search_description | string | "Houses for sale in Shelly Beach" |
| total_results | integer | Total listings found |
| pages_captured | integer | Pages scraped |
| listing_count | integer | Listings imported this capture |
| captured_at | datetime | |

---

## 4. PROSPECTING MODULE — UI

### Sidebar
Under a new top-level section: **Prospecting** (or under Agency Tracker)
- Market Intelligence (main view)
- Saved Searches (optional later)

### Main View: /prospecting

**Header:** "Market Intelligence" with subtitle "Portal listings captured by your team"

**Filters bar:**
- Portal: All / P24 / PP
- Suburb: dropdown from captured suburbs
- Property type: All / House / Apartment / Townhouse / etc.
- Price range: Min / Max inputs
- Bedrooms: Any / 1+ / 2+ / 3+ / 4+
- Agent/Agency: text search
- Status: Active / Removed / All
- Captured by: agent dropdown
- Date range: From / To

**Stats cards row:**
- Total Listings (number)
- Average Asking Price (formatted)
- New This Week (count)
- Price Reductions (count with badge)

**Table:**
| Photo | Address | Suburb | Price | Beds | Baths | Type | Agent | Agency | Portal | First Seen | Price History |
|-------|---------|--------|-------|------|-------|------|-------|--------|--------|------------|--------------|
| thumb | 14 Marine Dr | Shelly Beach | R2,970,000 | 4 | 3 | House | J Smith | Seeff | P24 | 15 Mar | R3.1M → R2.97M ↓ |

- Photo column: 60px thumbnail, click to enlarge
- Address: clickable → opens portal URL in new tab
- Price History: shows latest change if any, with arrow ↑↓
- Portal: P24 or PP badge
- Table: ds-table, sticky headers, sortable columns
- Pagination: 50 per page

### Price Change Alerts
When a listing's price changes between scrapes:
- Price history row logged
- Price change badge on the listing row (green ↓ or red ↑)
- Price Reductions stat card increments

### Listing Detail (click row or expand):
- Full address
- All property details
- Thumbnail enlarged
- Portal link
- Price history timeline
- First seen / last seen dates
- Agent + agency info
- "Add to Presentation" button (future — link to presentation CMA)

---

## 5. THUMBNAIL DOWNLOAD

When importing listings, download the thumbnail:
- Queue a job per listing (don't block the API response)
- Download from portal CDN URL
- Save to: storage/prospecting/thumbnails/{portal}_{ref}.jpg
- Resize to 300px width for consistency
- Store local path in thumbnail_path column
- Serve via authenticated route (same pattern as docuperfect page images)

---

## 6. DEACTIVATION DETECTION

When a listing that was previously captured does NOT appear in a new scrape of the same search:
- Don't delete it
- Set is_active = false
- Show as "Removed" in the UI with a different styling (greyed out)
- This tells agents: "This property was taken off the market — either sold or withdrawn"

Logic: After each scrape, compare portal_refs captured against existing records for that suburb + portal. Any existing record not in the new capture gets is_active = false.

---

## 7. BUILD PHASES

### Phase 1 — Database + API + Basic UI
- Migration for 3 tables
- API endpoint to receive listings
- ProspectingController with index view
- Basic table display with filters
- Sidebar navigation link

### Phase 2 — Chrome Extension V2
- Portal detection (P24 + PP)
- DOM scraping for both portals
- Auto-pagination
- Send to CoreX API
- Progress UI

### Phase 3 — Intelligence Features
- Price change detection + history
- Deactivation detection
- Stats cards
- Thumbnail download queue
- "Add to Presentation" integration

---

## 8. KEY FILES (to create)

| File | Purpose |
|------|---------|
| app/Http/Controllers/ProspectingController.php | Main module controller |
| app/Http/Controllers/Api/ProspectingApiController.php | API endpoint for extension |
| app/Models/ProspectingListing.php | Listing model |
| app/Models/ProspectingPriceHistory.php | Price history model |
| app/Models/ProspectingSearch.php | Search capture model |
| app/Jobs/DownloadListingThumbnail.php | Queue job for thumbnails |
| resources/views/prospecting/index.blade.php | Main prospecting view |
| database/migrations/create_prospecting_tables.php | All 3 tables |
| routes/api.php | Add prospecting import endpoint |
| routes/web.php | Add prospecting web routes |
| chrome-extension/portal-capture-v2/ | Extension source |

---

## 9. RULES

- Never hard delete prospecting listings — soft delete only
- Always deduplicate by agency_id + portal_source + portal_ref
- Price changes logged to history, never overwritten silently
- Thumbnails downloaded async, never block the import response
- Extension must work offline-gracefully (show error if CoreX unreachable)
- Search context always stored — agents can see what searches were run and when
- Agency-scoped — agents only see their agency's captured data

---

## 10. NEW SOURCE — CMA Info Deeds Capture (2026-08-12, SCAFFOLD — not final)

> Status: **DRAFT / SCAFFOLD.** Field extraction is wired to Johan's confirmed
> field labels; accordion-expand + owner's-ID reveal are best-effort pending a
> live-page tuning session with Johan; the POST payload contract is cc1's to
> define. This section documents the scaffold as built — treat exact selectors
> and payload keys as subject to change until both land.

### Why a new source, not a P24/PP-style module

`cmainfo.co.za/Mapping/PropSearch.aspx` is an ASP.NET WebForms **deeds**
lookup tool (Property/Sale/Municipal Valuation/Servitudes/Accommodation/
Renovations panels), not a listings portal. It has no JSON API — every field
is read from rendered HTML `<table>` rows (~112 tables on the page), and the
page never navigates on interaction (postback swaps DOM in place). This
drives three structural differences from the P24/PP sources:

- **Label-driven extraction, not hard selectors** — the label→value read
  (`findValueByLabel()`) matches on normalized label TEXT and reads the
  adjacent cell, rather than a fixed CSS selector. More robust against an
  ASP.NET generator's markup shifting across sections/deploys.
- **On-page button, not popup-driven** — P24/PP capture is triggered from
  the extension's popup (`popup.js` → `chrome.runtime.sendMessage` →
  `background.js`). CMA capture is a single-property action taken while
  looking at ONE loaded property, so the button lives ON the page itself
  (`content-cmainfo.js` injects it) and messages `background.js` directly.
  `background.js` reads `apiUrl`/`apiToken` from `chrome.storage.local`
  itself for this one flow, since there's no popup step to relay them.
- **No pagination / no durable queue** — one property, one capture, one POST.
  Mirrors `handlePullProperty()`'s shape, not the batched search-capture loop.

### Fields (confirmed by Johan, 2026-08-12)

**Property Information:** Deeds Office, Scheme no, Scheme name, Situated at,
Section number, Flat number, Address, Suburb, Municipality, Province, GPS,
Section extent, Type, Usage.

**Sale Information:** Owner, Owner's ID, Sale Price, Sale Date, Registered
Date, Title Deed, Bond Holder, Bond Amount, Sale Type.

Municipal Valuation / Servitudes / Accommodation / Renovations are
**deliberately out of scope for phase 1** (Johan's explicit call).

### Open items — do not finalize without these

1. **Exact accordion toggle + collapsed-state markup** — `ensureSectionExpanded()`
   in `content-cmainfo.js` is a generic best-effort (tries common ASP.NET
   accordion patterns: `aria-expanded`, `+`/`−` glyph, `collapsed`/`expanded`
   class names). Needs Johan's live-page confirmation.
2. **Exact "view owner's ID" reveal control** — `revealOwnerIdIfNeeded()` is
   a generic best-effort (looks for a clickable element in the Owner's ID
   row whose text matches `view|show|reveal`). Same caveat.
3. **POST /api/deeds-capture payload contract** — cc1 owns this. The
   extension currently sends a DRAFT shape (`buildDraftPayload()` in
   `content-cmainfo.js`) wrapping `property_information` / `sale_information`
   objects (keyed per the field list above) plus `is_sectional`,
   `captured_url`, `captured_at`. The endpoint itself does not exist yet —
   POSTs will fail until cc1 ships it. Do not build backend consumption
   against this draft shape; wait for the confirmed contract.
4. **Sectional-title multi-section capture** — one owner per section
   (Johan's call). Scaffold captures whatever section is CURRENTLY loaded at
   button-click time (`is_sectional` flags it, keyed on `section_number`) —
   no multi-section aggregation in one payload. The agent re-clicks the
   button per section, same mental model as any single-property capture.

### Files (scaffold)

- NEW `public/chrome-extension/portal-capture/content-cmainfo.js` — label-
  driven extraction, accordion-expand, owner's-ID reveal, on-page button,
  message handler (`getPageType`/`getDeedDetail` — mirrors the P24/PP
  message shape for popup parity even though this flow doesn't need a
  popup).
- EDIT `public/chrome-extension/portal-capture/background.js` — new
  `captureDeed` message handler + `handleCaptureDeed()` (auth/error handling
  mirrors `handlePullProperty()`; endpoint + payload are DRAFT).
- EDIT `public/chrome-extension/portal-capture/manifest.json` — added
  `cmainfo.co.za` host permission + a scoped content script entry
  (`https://www.cmainfo.co.za/Mapping/PropSearch.aspx*`); version bump
  3.1.8 → 3.2.0 for the new source.
- Not touched: `queue-idb.js` (no durable-queue need for a single-item
  capture), `popup.html`/`popup.js` (no popup step in this flow),
  `public/downloads/portal-capture-extension.zip` (the packaged download —
  regenerating/shipping it is a separate, manual step once this lands).
