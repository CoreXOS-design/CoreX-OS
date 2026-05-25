# MIC Prospect-Collision Investigation Report
**Date:** 2026-05-25  
**Scope:** Identify all Market Intelligence (MIC) surfaces with prospect/pitch entry points and determine which apply collision detection vs which have gaps.

---

## Summary

- **Total MIC pitch entry points found:** 6 primary surfaces
- **Entry points already applying collision rules:** 0 (NONE detected)
- **Entry points missing collision rules:** 6 (ALL gaps identified)

**Critical Finding:** The `MapProspectStatusService` collision logic (6 statuses: `available`, `held`, `own_draft`, `other_draft`, `previously_sold`, `previously_held`) is **only called by the Map module**. **NO MIC pitch entry points currently invoke this service or equivalent collision detection.** An agent can create duplicate prospects across all MIC surfaces without hitting the safeguards.

---

## Task 1 — Pitch Now Entry Points in MIC

### Entry Point 1.1: MIC Work Tab — "PITCH NOW" / "PITCH NOW · HIGH" Chips

**File:** `resources/views/corex/market-intelligence/_suggested-action-chip.blade.php` (R5/R6 rules)  
**Lines:** 54–63 (anchor), 237–241 (SuggestedActionResolver)  
**Button text (user-visible):** "PITCH NOW · HIGH" or "PITCH NOW"  
**Route:** `seller-outreach.entry.from-prospecting`  
**Controller:** `\App\Http\Controllers\SellerOutreach\EntryPointController::fromProspecting()`  
**Page/Module:** Market Intelligence Work tab  
**Context carried:** `prospectingListingId` (URL param), listing object

**Current behavior:**
1. Agent clicks "PITCH NOW" on a prospecting listing
2. Routed to `fromProspecting($prospectingListingId)` (EntryPointController lines 62–95)
3. **No collision check** — only creates temp-lock for concurrency
4. Shows contact-capture form; when submitted, promotes listing to Property WITHOUT checking if HFC owns address
5. Agent proceeds to compose and send pitch

**Collision detection applied:** **NO**

---

### Entry Point 1.2: MIC Work Tab — "RE-PITCH STOCK" Chip (R7 Rule)

**File:** `app/Services/Prospecting/SuggestedActionResolver.php` (R7 rule)  
**Lines:** 264–283 (buildR7)  
**Button text:** "RE-PITCH STOCK"  
**Route:** `seller-outreach.entry.from-property`  
**Controller:** `EntryPointController::fromProperty()`  
**Page/Module:** Market Intelligence Work tab  

**Current behavior:**
1. R7 rule fires when: listing is in agency stock + no active claim + new strong-tier buyers arrived
2. Agent clicks "RE-PITCH STOCK" → routes to `fromProperty($property)`
3. **No collision check needed** — property already exists in HFC system (it triggered R7)

**Collision detection applied:** **YES, implicitly** — R7 only fires for in-stock properties.

---

### Entry Point 1.3: MIC Market Pulse Tab

**File:** `resources/views/corex/market-intelligence/market-pulse.blade.php`  
**Finding:** Read-only dashboard showing P24 email imports and price changes. **No pitch buttons found.**

**Collision detection:** **N/A** (no prospect action)

---

### Entry Point 1.4: MIC Opportunities Tab

**File:** `resources/views/corex/market-intelligence/partials/opportunities-list.blade.php` + `opportunity-detail.blade.php`  
**Finding:** Shows TrackedProperty rows and detail, but **no "Pitch" or "Prospect" CTA is rendered.**  
Detail page has "Edit address", "Promote to Stock", "Merge duplicate" but no pitch button.

**Collision detection:** **N/A** (no pitch entry point)

---

### Entry Point 1.5: MIC Slideover Property Detail

**File:** `resources/views/corex/market-intelligence/_slideover-header.blade.php` line 106  
**Button text:** "Pitch this property" (approximate)  
**Route:** `seller-outreach.entry.from-property`  
**Page/Module:** MIC Work tab slide-over detail pane  

**Current behavior:** When agent opens slide-over for a Property (in-stock), they can pitch it.

**Collision detection:** **Implicit YES** — only appears for matched (in-stock) properties.

---

### Entry Point 1.6: Contacts Pillar — "Compose Pitch" Button

**File:** `resources/views/corex/contacts/show.blade.php` lines 92–108  
**Button text:** "Compose pitch"  
**Route:** `seller-outreach.composer.show` (direct, no entry-point controller)  
**Page/Module:** Contact detail page  

**Current behavior:**
1. Agent clicks "Compose pitch" on a contact (buyer) page
2. Routed directly to composer
3. **No collision logic** — agent can select any property and compose

**Collision detection:** **NO** — Contact-initiated pitches bypass collision detection entirely.

---

## Task 2 — Current Flow per Entry Point

| Entry Point | Pre-Check Flow | Collision Logic | Result |
|---|---|---|---|
| **1.1: PITCH NOW (Work)** | Click chip → temp-lock → contact-form → submit → promote listing | None | Can prospect HFC-owned property |
| **1.2: RE-PITCH STOCK (Work)** | Click chip (R7 fires for in-stock) → fromProperty() | Implicit gate | Safe (already in stock) |
| **1.3: Market Pulse** | Read-only display | N/A | No action |
| **1.4: Opportunities** | TP list/detail, no pitch CTA | N/A | No action |
| **1.5: Slideover** | Property detail → pitch button | Implicit (matched) | Safe if in stock |
| **1.6: Contact Composer** | Click "Compose" → pick property → compose | None | Can pitch HFC-owned property |

---

## Task 3 — Dashboard Tiles

**MIC "This Week" Hero Block** (`ThisWeekTileBuilder`)  
**File:** `app/Services/MarketIntelligence/ThisWeekTileBuilder.php`

**Tiles rendered in MarketIntelligenceController lines 496–509:**
- AI-narrated tiles showing agency-wide statistics
- **No tile renders a "Pitch this property" button**
- Tiles reflect canvass-pool data (where `matched_property_id IS NULL` by default)
- Stats-strip respects `include_in_stock` filter

**Collision detection in dashboard tiles:** **N/A** (no pitch action)

---

## Task 4 — Opportunities Tab Deep Dive

**File:** `app\Http\Controllers\CoreX\MarketIntelligenceController::opportunities()` lines 612–740

**Query construction:**
```php
TrackedProperty::withoutGlobalScopes()
  ->where('agency_id', $agencyId)
  ->whereNull('deleted_at')
  ->filter based on chip selection
  ->paginate(50)
```

**Filters:**
- `filter='company_stock'`: Shows TPs where status='promoted' OR promoted_to_property_id IS NOT NULL
- Default filter is 'all' (shows every TP, in-stock or not)

**Status column:** Each TP row shows "IN STOCK" badge if promoted (lines 20–21, 42–48)

**Clicking through:** Routes to `market-intelligence.opportunities.show` → `opportunityShow()` controller  
Detail page shows "Promoted to agency stock" status but **no pitch CTA is rendered**  
Agent would need to navigate back to Work tab to find and pitch the listing

**Pre-check on clicking:** **None** — opportunity detail is read-only metadata display

---

## Task 5 — Other Prospect Surfaces

### 5.1: Buyer-Match Panel

**File:** `resources/views/corex/market-intelligence/_listing-row.blade.php` lines 323–335  
**Button:** User icon ("View matched buyers")  
**Action:** Alpine call `openBuyerPanel()` opens slide-over with strong/mid-tier buyers  
**From panel:** Agent would need to select a contact and navigate to composer

**Collision detection:** **NO** — when agent eventually composes a pitch, no property collision check

---

### 5.2: Legacy /prospecting Index

**File:** `resources/views/prospecting/index_legacy_body.blade.php` + `/prospecting` route  
**Finding:** Legacy controller redirects to new `/corex/market-intelligence` URL. Pitch buttons on legacy rows route to same endpoints as Work tab.

**Collision detection:** **Same as Work tab — NO check applied**

---

### 5.3: Email Inbox / P24 / PP Alerts

**Finding:** Emails ingested → P24Listing rows created → shown in Market Pulse (read-only)  
**No pitch CTA in email surfaces** — agents navigate to Work tab to act

**Collision detection:** **N/A** (no pitch action)

---

## Gap Analysis Table

| Entry Point | Has Collision Check? | What it does today | What it should do |
|---|---|---|---|
| **PITCH NOW (Work R5/R6)** | NO | Routes to contact-capture; promotes listing without checking HFC ownership | Call `MapProspectStatusService::resolve()` before entry; if status != 'available', redirect with "Already have mandate" |
| **RE-PITCH STOCK (Work R7)** | YES (implicit) | R7 rule gates to in-stock only | Already safe; no change needed |
| **Contact Composer** | NO | Agent selects any property; composes pitch | Show collision status of each property; warn on non-available properties |
| **Slideover Property Detail** | YES (implicit) | Property already matched to prospecting listing | Already safe |
| **Opportunities Tab** | YES (implicit) | No pitch CTA rendered; read-only | Already safe |
| **Market Pulse** | N/A | Read-only display | No change needed |
| **Buyer Panel** | Partial | Opens buyer list; agent navigates afterward | Show collision status when agent selects buyer + property for compose |
| **Legacy /prospecting** | NO | Same as Work tab | Apply same collision check as Work tab |

---

## Recommended Fix Scope

### Priority 1 (CRITICAL) — Work Tab R5/R6 Entry Points

**File:** `app/Http/Controllers/SellerOutreach/EntryPointController.php` lines 62–95

**Action:**
1. Before rendering contact-capture form, resolve prospect status via `MapProspectStatusService::resolve()`
2. If status != 'available', redirect to Work tab with error message
3. Link to responsible Property record or show draft/colleague indicator
4. If available, proceed as normal

**Risk:** HIGH (most common MIC pitch entry; agents bypass collision detection daily)

---

### Priority 2 (HIGH) — Contact Composer Entry Point

**File:** Composer views (not shown in audit scope)

**Action:**
1. When agent selects property in composer, load collision status for candidate properties
2. Render collision badge/warning (e.g., "HELD" label, "Own Draft" indicator)
3. Allow selection but warn before submit

**Risk:** HIGH (contact-initiated pitches have no safeguard)

---

### Priority 3 (MEDIUM) — Buyer Panel + Buyer-Driven Compose

**Action:** If buyer-match surface leads to compose, apply collision check before pitch submit

**Risk:** MEDIUM (fewer agents use buyer→property routing)

---

### Priority 4 (LOW) — Legacy /prospecting Deprecation

**File:** `routes/web.php` line 2860–2909

**Action:** Once Phase I1 migration window closes, retire `/prospecting` prefix

**Risk:** LOW (migration already planned)

---

## Notes

1. **Reference:** `MapProspectStatusService` (lines 38–183) defines the 6-status scheme and GPS/address matching. All new entry points should invoke this.

2. **Held Statuses:**
   - `held` — property status in ['active', 'available', 'for_sale', 'to_let']
   - `own_draft` — draft assigned to current agent
   - `other_draft` — draft assigned to another agent
   - `previously_sold` — status = 'sold'
   - `previously_held` — defined but not emitted

3. **No Listing-Level Filter:** Default `whereNull('matched_property_id')` shows canvass pool but doesn't prevent duplicate entry at prospect-click time.

4. **EntryPointController Reuse Logic:** The `promoteListingToProperty()` check (lines 319–330) looks for existing Property with same address but doesn't invoke collision detection or warn the agent.

5. **SuggestedActionResolver:** R5/R6 rules correctly suppress action for in-stock listings (`$notInStock = $listing->matched_property_id === null`), but only *after* listing is matched. Pre-pitch moment has no guard.

---

## Conclusion

**Zero MIC pitch entry points apply `MapProspectStatusService` collision logic or equivalent safeguards.**  
Three surfaces present highest risk:
1. **Work tab "PITCH NOW" (R5/R6)** — most used, no pre-check
2. **Contact Composer property picker** — no property status visibility
3. **Buyer-driven pitch flows** — unknown collision coverage

A unified collision-check wrapper should be called at the moment an agent initiates a prospect action, **before** rendering contact-capture or property-picker forms.

