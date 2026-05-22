# Presentations V2 — One-Button Auto-Presentation with Engagement Loop

**Status:** Spec — replaces `.ai/specs/presentations.md` (58-line stub, stale)
**Date:** 2026-05-23
**Author:** Claude + Johan
**Audit reference:** `.ai/audits/presentations-audit-2026-05-23.md`

---

## 1. Goal

One click on a Property → fully-assembled presentation in 30-60 seconds. Zero agent inputs beyond the click (or one optional asking-price confirmation for properties without a listing price). Built-in engagement tracking, teaser vs full distinction, static snapshots, refresh-request flow, and outcome enforcement via Event Classes.

---

## 2. Core Principles

1. **Agent stays in the loop.** Every escalation requires agent action. No competitor abuse path.
2. **Static snapshots are the legal record.** What was sent on date X is what was sent. Full presentations are immutable once dispatched.
3. **Data flywheel.** Every CMA imported makes future presentations stronger across the agency.
4. **Settings-driven thresholds.** No hard-coded numbers. Agencies tune their own coverage requirements.
5. **Outcome enforced via existing Event Class infrastructure.** Don't build parallel forcing functions.
6. **Engine layer is reusable.** Existing MA, SP, HoldingCost, Narrative, Compiler services stay — we orchestrate them.

---

## 3. The Agent Flow

### 3.1 Generate Presentation

Two entry points:
- "Generate Presentation" button on Property show page
- "Send Presentation" button on Contact record (selects property first)

Click triggers `PresentationGeneratorService::generate(propertyId, agentUserId, agencyId, options)`:

1. Resolves Property (or TrackedProperty via Universal Match-or-Create)
2. Hydrates: address, suburb, type, beds, baths, erf, floor area, current listing price
3. Looks up cached MA + SP runs for `(suburb, type, period_months=12)`. If stale or missing, runs fresh.
4. Pulls active competitive stock from `imported_listings` filtered by suburb + type
5. Pulls sold comps from agency-wide sold data (whatever tables hold sold comp records — to be confirmed in Phase 1 investigation)
6. Calls `HoldingCostService` with property defaults (rates/levies from property pillar, bond=0 default)
7. Generates AI summary via `PresentationAiSummaryService` (Anthropic call, grounded facts only)
8. Persists `Presentation` + `PresentationSnapshot` (both MA + SP run IDs linked) + `PresentationVersion` in one transaction
9. Returns version_id + a default shareable URL + an optional teaser URL

### 3.2 Asking Price Handling

- **Property has listed_price set** → use it, no modal, true one-click
- **Property has no listed_price** → small modal: "What price do you want to test?" pre-filled with MA-suggested median based on (suburb, type, beds) comps. Agent confirms or overrides. Then generate.

### 3.3 AI Summary Review

After generation, agent sees a "Review before compile" panel:
- AI-generated summary (200-word seller-facing narrative, hard-hitting but inviting tone)
- Agent can edit any line
- Agent can click "Regenerate" to get a different variant
- Save → presentation is finalized

The variant ID and any agent edits are stored against the version for later conversion analysis.

### 3.4 Send Presentation

After generation, agent sees a "Send" panel:

- **Recipients:** checkboxes for each linked contact on the property. Send-to-all checked by default. Agent can uncheck individuals.
- **Type:** radio: Teaser or Full. Default = whatever agent picked last time (sticky).
- **Channel:** WhatsApp or Email (or both). Agent's last choice sticky.

Click Send → system:
1. Creates a `PresentationDispatch` record per recipient
2. Generates a unique signed URL token per dispatch (recipient-specific link)
3. If WhatsApp: opens WhatsApp deep link with pre-filled message + URL
4. If Email: sends via existing notification infrastructure with pre-filled subject/body + URL

### 3.5 Re-generation

Clicking "Generate Presentation" on a property that already has one → **upsert**. The existing `Presentation` row is reused. A new `PresentationVersion` is created. Engagement history preserved.

---

## 4. The Recipient Flow

### 4.1 Teaser Presentation

URL: `/share/teaser/{token}`

Recipient opens link → sees:

**Header:** "Market Insights for [Suburb]" (not their property address)

**Section 1 — Suburb Snapshot**
- "X properties sold in [Suburb] in the last 12 months"
- "Average price: R[N]"
- "Average days on market: [N]"

**Section 2 — Market Temperature**
- One-line: Buyer's market / Balanced market / Seller's market for this suburb
- Colour-coded (red / amber / green)

**Section 3 — Price Band for Property Type**
- "[Property type]s in [Suburb] typically sell between R[low] and R[high]"
- No specific value for their property

**Section 4 — Seller's Estimate Input**
- "What price do you think your property is worth?"
- Single input field
- On submit → shows enhanced teaser:
  - "Properties in this suburb sold for [low] to [high] in the last 6 months. Where your property lands in that range depends on its condition, position, and current buyer demand."
  - The magic line: drives engagement without revealing analysis

**Section 5 — Tease the Full Presentation**
- "Want to know what *your* property is worth, what the right launch price is, and how long it'll take to sell? Your local [Agency] agent has built a full personalised analysis."
- Agent card: name, photo, WhatsApp number

**Section 6 — CTA**
- Big button: "Send me my full presentation"
- Click → `RequestFullPresentation` event fires
- Agent notified in CoreX

**No personalisation beyond suburb + type** in the teaser. Different recipients in different suburbs see different teasers. Different recipients in the same suburb with the same property type see the same teaser.

### 4.2 Full Presentation

URL: `/share/full/{token}`

Recipient opens link → sees the **static snapshot** of the full presentation:
- Property-specific analysis
- Comparable sales
- Recommended price band
- AI summary (the hard-hitting personalised one)
- Holding cost analysis
- Agent recommendation
- Agent contact card

**The full presentation is rendered from the saved `PresentationVersion` snapshot** — it does NOT regenerate on each view. The data shown is the data at time of send.

### 4.3 Refresh Request

When a recipient opens a full presentation that's older than the agency's configured refresh expiry (default 21 days):

A banner appears at the top:
> *"This presentation was prepared on [date]. The market has moved since then. Want a refreshed analysis from your agent?*
> [Request Updated Presentation] *button*"

Click the button → fires `RefreshRequested` event. Agent notified.

The original presentation link still works — recipient can revisit unlimited times. The banner just appears on every visit until the agent sends a new version.

When agent sends a new version, a new dispatch is created, new token generated, sent to recipient. Old token still works (legal record of what was originally presented) but the banner is now suppressed because it's been superseded.

### 4.4 Defence: Device Fingerprint Flagging

Each presentation link tracks:
- First-opened IP + user-agent + device fingerprint
- Subsequent opens compared against the first

If opens come from genuinely different fingerprints (not just browser updates or device changes), the system flags it:
- Agent's engagement dashboard shows: "Presentation for [property] was opened from a different device on [date]. May be the seller on another device, or someone they shared the link with."

Not blocked. Just visible. Combined with static-snapshots and per-recipient tokens, this raises the bar for competitor abuse significantly.

---

## 5. Data Model

### 5.1 Changes to existing `presentations` table

Add columns:
- `property_id` BIGINT NULL — explicit FK to Property pillar (existing `listing_id` is sparse)
- `tracked_property_id` BIGINT NULL — for prospecting presentations on unlisted properties
- `seller_contact_id` BIGINT NULL — explicit FK to Contact pillar
- `deal_id` BIGINT NULL — for when presentation lives inside a deal
- Index on all four

These FKs replace the current `listing_id` (1/21 use rate per audit). Existing presentations remain valid but the new flow uses these.

### 5.2 New table: `presentation_dispatches`

Each "send" event creates a row. Stores recipient + type + channel + token + tracking.

```
id BIGINT PK
presentation_version_id BIGINT FK → presentation_versions
recipient_contact_id BIGINT FK → contacts
recipient_email VARCHAR(255) NULL
recipient_phone VARCHAR(20) NULL
type ENUM('teaser', 'full') NOT NULL
channel ENUM('whatsapp', 'email', 'both') NOT NULL
token VARCHAR(64) UNIQUE NOT NULL
sent_by_user_id BIGINT FK → users
sent_at TIMESTAMP NOT NULL
first_opened_at TIMESTAMP NULL
last_opened_at TIMESTAMP NULL
open_count INT DEFAULT 0
first_device_fingerprint VARCHAR(64) NULL
first_ip VARCHAR(45) NULL
first_user_agent TEXT NULL
flagged_for_review BOOLEAN DEFAULT FALSE
flagged_reason TEXT NULL
superseded_by_dispatch_id BIGINT NULL FK → self
refresh_requested_at TIMESTAMP NULL
full_requested_at TIMESTAMP NULL  -- when teaser viewer clicked "send me full"
agency_id BIGINT FK
created_at, updated_at, deleted_at
```

### 5.3 New table: `presentation_engagement_events`

Granular event log. Every open, scroll, click, request.

```
id BIGINT PK
dispatch_id BIGINT FK → presentation_dispatches
event_type ENUM('open', 'estimate_submitted', 'request_full', 'request_refresh', 'agent_contact_clicked', 'whatsapp_clicked', 'phone_clicked')
event_data JSON NULL  -- payload (e.g. estimate amount)
ip_address VARCHAR(45)
user_agent TEXT
device_fingerprint VARCHAR(64)
occurred_at TIMESTAMP
agency_id BIGINT FK
```

### 5.4 New table: `presentation_ai_variants`

For variant tracking + conversion analysis.

```
id BIGINT PK
variant_key VARCHAR(50) UNIQUE  -- e.g. 'tone_direct_v1', 'tone_warm_v2'
prompt_template TEXT
description VARCHAR(255)
active BOOLEAN DEFAULT TRUE
created_at, updated_at
```

And on `presentation_versions`:
- Add `ai_variant_id` column FK → presentation_ai_variants
- Add `ai_summary_text` TEXT  -- the actual generated copy (saved verbatim for legal record)
- Add `ai_summary_edited_by_agent` BOOLEAN DEFAULT FALSE
- Add `ai_summary_agent_edits` JSON NULL  -- before/after if edited

### 5.5 New table: `presentation_outcomes`

Closes the outcome forcing loop.

```
id BIGINT PK
presentation_id BIGINT FK → presentations
dispatch_id BIGINT NULL FK  -- which specific send led to the outcome (optional)
outcome ENUM('won', 'lost', 'pending', 'still_negotiating', 'no_response')
outcome_recorded_at TIMESTAMP
outcome_recorded_by_user_id BIGINT FK → users
notes TEXT NULL
deal_id BIGINT NULL FK  -- if outcome=won, link to the resulting deal
agency_id BIGINT FK
```

### 5.6 Agency Settings (new section)

In existing agency settings infrastructure, add a "Presentations" panel:

- **CMA Coverage Thresholds**
  - Rich (one-button, no upload prompt): default 6+ comps
  - Moderate (one-button + soft upload suggestion): default 3-5 comps
  - Thin (generate-anyway + prominent upload prompt): default 1-2 comps
  - None (upload-first, generate disabled): 0 comps

- **Refresh Expiry**
  - Days after dispatch before refresh banner appears: default 21 days

- **Default Teaser Tone**
  - Direct / Warm / Confident / Custom

- **Send Preferences**
  - Default channel: WhatsApp / Email / Both
  - Default type: Teaser / Full

### 5.7 Event Class: `presentation_outcome_pending`

New entry in `event_classes` table:
- key: `presentation_outcome_pending`
- label: "Presentation Outcome Pending"
- Defaults: green at 7 days, amber at 14 days, red at 21 days
- Agency-configurable per existing Event Class pattern

When a presentation dispatch is sent, an event of this class is created with `due_at = sent_at + agency.config.outcome_chase_days`. Standard event flow handles the rest.

---

## 6. Build Phases

Each phase ships independently and is tested before moving to the next.

### Phase 1 — Foundation (Friday morning, ~3h)

**Goal:** PresentationGeneratorService works end-to-end. Click a property → presentation generated.

Deliverables:
- `PresentationGeneratorService` class
- Property → Presentation hydration helper
- Migration: add `property_id`, `tracked_property_id`, `seller_contact_id`, `deal_id` to `presentations`
- Wire existing fallback adapters (`PresentationActiveListingsAdapter`, `PresentationSoldCompsAdapter`) to consume agency-wide data instead of per-presentation uploads
- Atomic compile path: `analysis/run + compute + compile` in one transaction
- "Generate Presentation" button on Property show page (basic, no asking-price modal yet)
- Test: generate for 3 real HFC properties in 3 different suburbs

Acceptance: a property in `properties` table can be turned into a `PresentationVersion` row in one click, with MA + SP runs linked.

### Phase 2 — Coverage Scorer + Agency Settings (Friday afternoon, ~2.5h)

**Goal:** Coverage state computed and displayed. Agency can tune thresholds.

Deliverables:
- `CmaCoverageService::scoreForProperty(property)` → returns `{state: rich|moderate|thin|none, comp_count, suburb_count}`
- Coverage indicator on Property show page above the Generate button
- New "Presentations" panel in Agency Settings with threshold inputs
- Migration: `agency_settings.presentations_*` columns or JSON config
- Asking-price modal for properties without listed_price, pre-filled with MA suggestion
- Test: change agency thresholds, verify coverage state changes correctly

Acceptance: agency can set thresholds; coverage state computed live on property pages.

### Phase 3 — AI Summary + Variant Tracking (Friday evening, ~2h)

**Goal:** Each presentation includes an AI-generated summary in the hard-hitting-but-inviting tone.

Deliverables:
- `PresentationAiSummaryService` class
- Anthropic call with grounded-facts-only prompt (forbid hallucination)
- Migration: `presentation_ai_variants` table
- Seed table with 3 initial variants: `direct_v1`, `warm_v1`, `confident_v1`
- Migration: `ai_variant_id`, `ai_summary_text`, `ai_summary_edited_by_agent`, `ai_summary_agent_edits` on `presentation_versions`
- "Review before compile" UI: shows generated summary, agent can edit, click "Regenerate" for variant
- Save → finalized
- Test: generate 3 presentations, each with different variant, verify variant_id stored

Acceptance: every generated presentation has an AI summary saved with variant attribution; agent can edit before saving.

**Saturday morning starts here.**

### Phase 4 — Static Snapshot Links + Engagement Tracking (Saturday morning, ~2.5h)

**Goal:** Generated presentations have shareable static-snapshot URLs with view tracking.

Deliverables:
- Migration: `presentation_dispatches` table
- Migration: `presentation_engagement_events` table
- `PresentationLinkService` for token generation
- `/share/full/{token}` route serving the static-snapshot view
- View tracking: every open creates an engagement event
- Device fingerprint computation + first-fingerprint capture
- Flagging logic: subsequent opens from different fingerprints flag the dispatch
- Engagement dashboard panel on agent's Presentation show page: opens, last viewed, flagged
- Test: generate, get link, open in two browsers (simulating different device), verify flagging

Acceptance: every full presentation has a static URL; opens tracked; fingerprint flagging works.

### Phase 5 — Teaser Presentation Route (Saturday midday, ~3h)

**Goal:** Teaser link works as a separate recipient surface.

Deliverables:
- `/share/teaser/{token}` route
- New view: teaser-presentation template (suburb-level data only, no property specifics)
- Seller-estimate input → enhanced teaser
- "Request full presentation" button → fires `request_full` event
- Agent notification on full request
- Agent's dashboard: panel showing teaser engagement events
- Test: send teaser, recipient flow through, request full, verify agent notified

Acceptance: teaser flow works end-to-end from agent send to agent notification.

### Phase 6 — Send-to-Recipient Flow (Saturday afternoon, ~2.5h)

**Goal:** Agent can send presentations from Property or Contact.

Deliverables:
- "Send Presentation" button on Contact record + Property show page
- Send modal: recipient checkboxes, teaser/full radio, channel selector
- Sticky defaults per agent (last choice)
- WhatsApp deep link generation
- Email send via existing notification infrastructure
- Dispatch row per recipient per send
- Test: send from Contact + from Property, multiple recipients, both channels

Acceptance: agent can send to multiple recipients in one action; each gets their own token.

### Phase 7 — Refresh Request Flow (Sunday morning, ~2h)

**Goal:** Old presentations prompt for refresh, agent gets notified.

Deliverables:
- Refresh expiry computation (now - dispatch.sent_at vs agency setting)
- Banner on full presentation view when expired
- "Request Updated Presentation" button → fires `request_refresh` event
- Agent notification
- When agent regenerates + sends: new dispatch supersedes old (`superseded_by_dispatch_id`)
- Old token still works, banner suppressed
- Test: backdate a dispatch, view, verify banner, request refresh, verify agent notified

Acceptance: refresh banner appears appropriately; old link works without banner once superseded.

### Phase 8 — Outcomes + Event Class (Sunday afternoon, ~2h)

**Goal:** Outcomes captured, enforcement via Event Class.

Deliverables:
- Migration: `presentation_outcomes` table
- Event Class seed: `presentation_outcome_pending` with default thresholds
- Auto-create event on dispatch
- Outcome capture UI on Presentation show page: Won / Lost / Pending / Still Negotiating / No Response
- One-tap recording
- Outcome resolves the Event Class entry
- If outcome=won and a deal exists: link them
- Test: send, leave unresolved, verify amber/red transitions; record outcome, verify event resolved

Acceptance: outcomes capture cleanly; Event Class drives chasing.

### Phase 9 — Testing + Polish + Staging Deploy (Sunday evening, ~2h)

**Goal:** End-to-end tested on real properties, deployed to staging, ready for Monday live.

Deliverables:
- E2E test: 3 real HFC properties, 3 different suburbs, full flow
- Hotfix anything broken
- Brief sanity check on existing presentation data (the 21 March presentations) — confirm they still display correctly
- Deploy to staging
- Smoke test on staging
- Document the new flow for HFC agents (1-page reference)

Acceptance: Monday morning, push to live happens with no surprises.

---

## 7. Out of Scope (Phase 2 Features for Later)

- Variant A/B analysis dashboard (record now, analyse later)
- Bulk send to multiple properties at once
- Custom presentation templates per agency
- Translation / multi-language
- Embedded video in presentations
- Interactive simulators in recipient view
- PDF download of teaser (full only)
- Presentation favouriting / pinning
- Mobile-app-specific recipient view (responsive web only)

---

## 8. Files to Modify / Create

### Backend

- `app/Services/Presentations/PresentationGeneratorService.php` — NEW
- `app/Services/Presentations/PresentationAiSummaryService.php` — NEW
- `app/Services/Presentations/PresentationLinkService.php` — NEW
- `app/Services/Presentations/CmaCoverageService.php` — NEW
- `app/Services/Presentations/PresentationDispatchService.php` — NEW
- `app/Services/Presentations/RefreshExpiryService.php` — NEW
- `app/Http/Controllers/Presentation/PresentationGeneratorController.php` — NEW
- `app/Http/Controllers/Presentation/PresentationShareController.php` — NEW (handles `/share/full` + `/share/teaser`)
- `app/Http/Controllers/Presentation/PresentationDispatchController.php` — NEW
- `app/Models/PresentationDispatch.php` — NEW
- `app/Models/PresentationEngagementEvent.php` — NEW
- `app/Models/PresentationAiVariant.php` — NEW
- `app/Models/PresentationOutcome.php` — NEW
- `app/Models/Presentation.php` — modify (add relationships)
- `app/Models/Property.php` — modify (add `presentations()` relation properly)
- `app/Models/Contact.php` — modify (add `presentation_dispatches()` relation)

### Database

- 8 migrations (see §5)

### Routes

- `routes/web.php` — add share routes (public, signed-URL-authenticated)
- `routes/api.php` — add internal API endpoints

### Views

- `resources/views/presentations/show.blade.php` — extend with engagement panel + outcome capture
- `resources/views/presentations/generator-modal.blade.php` — NEW (asking-price modal)
- `resources/views/presentations/send-modal.blade.php` — NEW
- `resources/views/presentations/ai-review.blade.php` — NEW (review-before-compile)
- `resources/views/share/full.blade.php` — NEW (static-snapshot recipient view)
- `resources/views/share/teaser.blade.php` — NEW (teaser recipient view)
- `resources/views/properties/show.blade.php` — modify (add Generate button + coverage indicator)
- `resources/views/contacts/show.blade.php` — modify (add Send Presentation button)
- `resources/views/agency/settings/presentations.blade.php` — NEW

### Settings

- `event_classes` table seed: `presentation_outcome_pending`
- Agency settings schema additions

---

## 9. Acceptance for Monday Live

By Sunday evening, all of:

1. Click Generate on any property → presentation generates without error
2. Coverage scorer shows correct state for the property
3. AI summary generates in the right tone, can be edited by agent
4. Send to a contact via WhatsApp → recipient gets working link
5. Recipient opens teaser → sees suburb data only, no property specifics
6. Recipient submits estimate → enhanced teaser shows
7. Recipient requests full → agent notified
8. Agent sends full → recipient gets working full link
9. Static snapshot view renders correctly
10. Backdated dispatch shows refresh banner
11. Recipient requests refresh → agent notified
12. Agent records outcome → Event Class resolves
13. Two browsers opening same link → flagging works

All 13 must pass on staging before deploying to live.

---

## 10. Open Questions Resolved

- One presentation per property: **upsert**
- Coverage thresholds: **settings-driven**
- Asking price source: **listed_price if set, modal if not**
- Outcome enforcement: **Event Class** (`presentation_outcome_pending`)
- Recipient surface: **static link + refresh request flow**
- AI tone: **hard-hitting but inviting, variant-tracked**
- Teaser personalisation: **suburb + type only**
- Lead capture: **WhatsApp default, agent settings determine**
- Static vs live: **static, with refresh-request escalation**
- Teaser vs full choice: **agent's call at send time, sticky default**
- Multi-recipient: **checkboxes per linked contact, send-to-all default**

---

*Ready to fire Phase 1.*
