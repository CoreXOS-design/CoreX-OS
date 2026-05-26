# Seller Outreach — Module Spec

> Status: Draft pending Johan approval — 2026-05-13 (late evening)
> Owner: Johan / Andre
> Pillars: Contact (record-keeping spine) + Property (the pitch's subject)
> Depends on (Phase 1 already shipped):
> - `.ai/specs/unified-buyer-wishlist-spec.md` — ContactMatch data the pitch counts come from
> - `.ai/specs/prospecting-setup-spec.md` — segment definitions (town, property type, bedrooms, price band)
> - `.ai/specs/prospecting-intelligence-spec.md` — aggregation engine producing live buyer-count claims
> - `.ai/specs/corex-domain-events-spec.md` — event/listener pattern
> Source decisions (Johan, 2026-05-13 evening):
> - Contact-first workflow. Property linkage mandatory.
> - WhatsApp first via wa.me, email as secondary.
> - Pre-written templates with merge fields.
> - Mandatory snapshot of facts at send time (PPRA defensibility).
> - Link tracking via unique short URL per send. No WhatsApp API integration in v1.
> - Public landing page agency-branded. No expiry. Page content updates live.
> - When the underlying property is archived, landing page reverts to generic agent business card + live area demand.
> - POPIA: legitimate interest. Opt-out clause in message.

---

## Section 1 — Purpose & Context

**What this does.** Lets a real estate agent send a defensible, data-backed pitch to a property seller via WhatsApp (primary) or email (secondary), with every claim ("we have 14 active buyers in Margate") sourced live from the prospecting intelligence layer, and every send recorded against the contact for compliance and future re-engagement.

**Why it matters.** Every other CRM lets agents send templated messages. CoreX's pitch is different: the buyer-count claims are real, factually defensible against PPRA scrutiny, and dynamically computed from the agency's actual data. The pitch isn't "we have a great network" — it's "we have 14 buyers, of which 7 are looking for 3-bed properties like yours, today."

**The honest pitch principle (carried from prospecting intelligence).** Every claim in the message holds up in a dispute. No pre-approval counts. No "qualified" labels. A buyer is a buyer — a person in the system actively looking. The numbers are agency-scoped and segment-driven from configuration the agency itself owns.

**The contact-first principle.** Communications live on the contact record. Every pitch records on the contact's communication timeline. Cooldowns, re-engagement, opt-outs — all visible to any agent opening the contact. No orphaned messages with no record-keeping.

**Downstream features this unblocks:**
- Agency-wide pitch performance dashboard (open rates, response rates per template).
- "Re-pitch expired mandates" workflows.
- A/B testing of message templates.
- Compliance audit trail per send (PPRA + POPIA evidence).

**Explicit out of scope:**
- WhatsApp Cloud API integration (requires Meta Business approval, agent-number registration, billing — too much for v1; wa.me click-to-chat from the agent's own phone is the v1 channel).
- Inbound message handling. Sellers reply on WhatsApp to the agent's personal number; the system doesn't see those replies.
- Bulk send / mail-merge style mass communication. v1 is one-pitch-at-a-time, agent-reviewed before sending.
- Address enrichment on prospecting listings — handled by the prospecting module.
- Cross-reference of seller's property against agency stock — handled by the prospecting module's property-pillar work (the "on our books" badges).

---

## Section 2 — Source Material

| File / Spec | Read |
|---|---|
| `CLAUDE.md` | Yes |
| `.ai/STANDARDS.md` | Yes |
| `.ai/specs/unified-buyer-wishlist-spec.md` | Yes (ContactMatch data) |
| `.ai/specs/prospecting-setup-spec.md` | Yes (segment definitions) |
| `.ai/specs/prospecting-intelligence-spec.md` | Yes (aggregation engine — pitch counts come from `ProspectingIntelligenceService::snapshot()`) |
| `.ai/specs/corex-domain-events-spec.md` | Yes (4 new event classes) |
| Existing Contact / Property models | Re-read in pre-flight |
| Existing communication-timeline UI on Contact (if any) | Re-read in pre-flight |

---

## Section 3 — Decisions Locked

### S1. Contact-first workflow

The outreach record always lives on a contact. Three entry points, all converging on the same composer:

1. **From the contact record** (canonical) — agent opens a seller's contact, clicks "Compose pitch" — composer opens.
2. **From a property record** — agent clicks "Pitch this seller" → composer redirects to the property's linked seller contact (if multiple sellers, agent picks which). If no contact linked yet, composer prompts agent to create or link one first.
3. **From the prospecting tab** — agent clicks a portal-discovered listing's "Pitch this seller" → composer first asks for the seller's contact info (creates the contact, dedupes against existing) and links the property, then opens.

**The composer screen never opens without (a) a contact and (b) a property selected.** Both are mandatory for defensibility — the pitch references a specific property and the send is logged on a specific contact.

### S2. Mandatory property linkage

The pitch is about a property. The composer requires one selected before facts can be computed. Property can be:
- Already linked to the contact via the existing `document_contacts` / similar linkage tables.
- Picked from properties not yet linked to this contact (system shows possibilities from agency stock).
- Created on the fly (minimal record: address + suburb + property type + bedrooms + price + listing type — the same fields the prospecting intelligence layer segments by).

When a property is created on the fly, it gets linked to the contact via the standard property-contact pivot. Future agents see the link.

### S3. Dedupe on every contact creation/link path

When the prospecting-tab entry creates a contact, OR when the on-the-fly property is captured against a contact, dedupe rules:

- **Exact phone match** (normalised — strip spaces, dashes, brackets, leading-zero / +27 equivalents) → confirmed duplicate.
- **Exact email match** (lowercased, trimmed) → confirmed duplicate.
- **Fuzzy name match** with phone OR email near-match → soft duplicate, agent confirms.

When a duplicate is found, composer redirects to use the existing contact, not create a new one. Agent sees: "John Smith already exists in your contacts (last activity 3 months ago) — using existing record."

### S4. Pre-written templates with merge fields

Templates live under Settings → Templates (new section), agency-managed, agency-scoped:

```
Template:
  name: string                     (e.g. "Initial outreach — sale")
  channel: enum(whatsapp, email)
  subject: string                  (email only; ignored for whatsapp)
  body: text with merge fields
  description: string              (internal hint to agents)
  is_active: boolean
  default_for_channel: boolean     (one default per channel per agency)
```

**Merge fields** (system-managed, validated at template-save time):
- `{seller_name}` — first name of the contact.
- `{property_address}` — full address of the linked property.
- `{property_suburb}` — suburb only.
- `{property_town}` — resolved town via configuration service.
- `{property_type}` — e.g. "House", "Apartment".
- `{property_beds}` — number, e.g. "3".
- `{agent_name}` — full name of the sending agent.
- `{agent_phone}` — agent's phone in display format.
- `{agency_name}` — agency's display name.
- `{buyer_count}` — live count: distinct active buyers (new/warm + active wishlist) in the property's town.
- `{matching_buyer_count}` — live count: subset who match by property type, beds, price band.
- `{tracking_link}` — the unique short URL per send. **MANDATORY in every template** — system rejects template save if missing.

HFC seeded with 3 default templates:
1. "Initial outreach — sale" — full pitch with buyer counts + tracking link.
2. "Follow-up after 7 days" — softer reminder, references previous message.
3. "Expired mandate re-pitch" — references the property's mandate history.

### S5. Snapshot at send time (PPRA defensibility)

Every send creates an immutable record on `seller_outreach_sends` with:
- `template_id` (the template used; nullable if free-edit override).
- `subject_snapshot` — the rendered subject at send time (email).
- `body_snapshot` — the rendered body at send time, with all merge fields filled in.
- `facts_snapshot` — JSON snapshot of every claim the message made:
  - buyer_count, matching_buyer_count, town, suburb, property attributes, segment IDs.
- `tracking_short_code` — unique 6-character alphanumeric, indexed.
- `landing_page_url` — full URL stored for reference.
- All standard pillar fields: contact_id, property_id, agent_id, agency_id, channel, sent_at.

The body_snapshot is the ground truth in any dispute — it's what the agent sent at that moment. Even if the template changes later, the snapshot stays unchanged.

### S6. wa.me click-to-chat (no Cloud API)

Sending via WhatsApp:
1. Agent reviews the composed message in the composer.
2. Clicks "Open WhatsApp".
3. System creates the `seller_outreach_sends` record (`sent_at = NOW()`).
4. Browser opens `https://wa.me/{phone}?text={URL-encoded body with the tracking link}`.
5. Agent's WhatsApp opens with the message pre-filled, addressed to the seller's number.
6. Agent reviews one final time, sends from their own number.

This means:
- The agent's WhatsApp is the actual sender.
- The seller replies to the agent's personal number.
- The system has no visibility into actual delivery / read receipts.
- The tracking link is the only feedback signal (see S7).

For email channel: the composer opens a mailto: link with subject and body pre-filled, OR — preferred when CoreX has agency SMTP configured — sends via the agency's SMTP gateway. v1 ships mailto: only; SMTP is a v2 upgrade.

### S7. Link tracking via unique short URL per send

Every send gets a unique 6-character shortcode (e.g. `xK9p2A`). The short URL is built as `{agency-subdomain}/m/{shortcode}` or whatever the agency's configured short-URL pattern is.

When the link is clicked:
1. `seller_outreach_clicks` row created: `send_id, clicked_at, ip, user_agent, geo_country` (geo via IP lookup — best effort, no PII storage).
2. The first click flips the parent send record's `first_clicked_at`.
3. The page renders (see S8 for content).

This is the only delivery feedback the system has. Sends without clicks are silent — could mean the seller didn't open, didn't engage, or chose to reply directly via WhatsApp instead.

### S8. Public landing page (no expiry, live content)

The landing page is **always alive**. Never expires. Content adapts to the current state of the underlying data:

**Three render modes** (system picks based on state at the moment of click):

**Mode 1 — Active mode** (property and send are both current):
- Agent's business card: photo, name, phone (click-to-call), WhatsApp button, email, agency logo, agency name.
- Property reference: "Your property at {address}".
- **Live demand summary** — recomputed at render time, not snapshot:
  - "We have {live_buyer_count} buyers actively looking in {town}."
  - "Of those, {live_matching_count} are searching for properties like yours ({property_type}, {beds} beds, {price_band})."
- Call to action: "Reply on WhatsApp" (deep link to the agent's WhatsApp) + "Request a callback" (small form that creates a task on the agent's dashboard).
- Small "what we do" section: 2-3 lines of agency boilerplate from settings.

**Mode 2 — Generic mode** (property has been archived / withdrawn / sold by other agency):
- Same agent business card as Mode 1.
- No property-specific reference.
- Live area demand only: "We're still active in {town} — {live_buyer_count} active buyers looking."
- CTA: "Get in touch" (WhatsApp + email + callback form).
- Same agency boilerplate.

**Mode 3 — Agent unavailable** (agent left agency, deactivated):
- Falls back to **branch manager** as the contact card (or whoever the agency configures as the "default contact" for orphaned outreach).
- Generic mode otherwise (no property reference).

Mode is determined per-click at render time. The same link could render different modes a month apart as state changes.

**No expiry. No "this link has expired" page.** Sellers may revisit months or years later — the page always works, always shows useful info, always represents the agency well.

### S9. POPIA compliance

Legal basis: **legitimate interest** under POPIA — a property publicly advertised on P24/PP or in the public domain is implicit consent to be contacted by interested real-estate professionals. This is industry norm.

The message body must include an opt-out clause. Every message (every template, validated at template-save) ends with:

> Reply STOP to opt out of further messages.

The contact record gets a `messaging_opt_out_at` field. When an agent marks a contact as opted-out (manually after the seller replies STOP), the composer blocks future sends to that contact with a clear notice: "This contact has opted out — cannot send."

Bypassing the opt-out requires re-consent — a flag on the contact set only after the seller proactively re-engages.

### S10. Cooldown is informational, not blocking

The composer shows the contact's communication timeline at the top — every previous send and click against this contact. Agent makes the judgment call. No hard cooldown gate.

If the seller's been contacted in the last 7 days, the composer shows a soft amber warning: "{Agent X} contacted this seller on {date} — make sure your message adds new value." Agent overrides by typing or selecting a reason.

The composer ALWAYS blocks send if `messaging_opt_out_at` is set (per S9). Cooldown is soft, opt-out is hard.

### S11. Events emitted

Per spec E2 in `corex-domain-events-spec.md`:

- `SellerOutreach\PitchSent(send, channel, template, actorUserId, agencyId)`
- `SellerOutreach\PitchClicked(click, send, actorUserId=null, agencyId)` — actorUserId is null because the click is by the seller, not a system user.
- `SellerOutreach\OptOutRecorded(contact, send=null, reason, actorUserId, agencyId)`
- `SellerOutreach\TemplateConfigured(template, action, actorUserId, agencyId)`

The wildcard audit listener writes each to `domain_event_log` automatically.

Listeners (Phase 1):
- On `PitchSent`: append entry to the contact's communication timeline (existing pattern if one exists; else a simple write to a `contact_communications` table).
- On `PitchClicked`: same — append to timeline.
- On `OptOutRecorded`: set `messaging_opt_out_at` on the contact + append timeline entry.

### S12. Multi-tenancy

Every table introduced here has `agency_id NOT NULL` and is scoped via `BelongsToAgency`. Including the `seller_outreach_sends` and `seller_outreach_clicks` tables. Including the `seller_outreach_templates` table.

The public landing-page route is the one exception — it must be unauthenticated (sellers don't have CoreX accounts). The shortcode lookup query MUST be agency-scoped through the `seller_outreach_sends.agency_id` value. No cross-agency leakage even on public URLs.

---

## Section 4 — Schema Changes

### 4.1 New table — `seller_outreach_templates`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | FK → agencies, BelongsToAgency |
| `name` | varchar(150) | NO | |
| `channel` | enum('whatsapp','email') | NO | |
| `subject` | varchar(255) | YES | required for email, ignored for whatsapp |
| `body` | text | NO | merge-field-encoded |
| `description` | text | YES | internal hint for agents |
| `is_active` | boolean | NO | default true |
| `is_default_for_channel` | boolean | NO | default false; only one true per (agency, channel) |
| `deleted_at` | timestamp | YES | SoftDeletes |
| timestamps | | | |

Indexes: `(agency_id, channel, is_active)`, `(agency_id, is_default_for_channel)`, soft-delete index.

### 4.2 New table — `seller_outreach_sends`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | FK → agencies |
| `contact_id` | bigint unsigned | NO | FK → contacts |
| `property_id` | bigint unsigned | NO | FK → properties |
| `agent_id` | bigint unsigned | NO | FK → users — the sending agent |
| `template_id` | bigint unsigned | YES | FK → seller_outreach_templates; nullable if free-edited |
| `channel` | enum('whatsapp','email') | NO | |
| `subject_snapshot` | varchar(255) | YES | rendered at send time |
| `body_snapshot` | text | NO | rendered at send time |
| `facts_snapshot` | json | NO | every claim made — see S5 |
| `tracking_short_code` | char(6) | NO | UNIQUE within agency |
| `recipient_phone_snapshot` | varchar(30) | YES | normalised number used in wa.me |
| `recipient_email_snapshot` | varchar(255) | YES | for email channel |
| `sent_at` | timestamp | NO | when the system recorded the send |
| `first_clicked_at` | timestamp | YES | denormalised for fast "has been opened" queries |
| `outcome` | enum('sent','clicked','replied','booked','no_response','not_interested','bounced') | NO | default 'sent'; agent-updatable except 'clicked' (auto) |
| `outcome_note` | text | YES | agent's note on outcome |
| `outcome_set_by_user_id` | bigint unsigned | YES | FK → users |
| `outcome_set_at` | timestamp | YES | |
| `deleted_at` | timestamp | YES | SoftDeletes |
| timestamps | | | |

Indexes:
- UNIQUE `(agency_id, tracking_short_code)` — `outreach_agency_code_unique`.
- `(agency_id, contact_id, sent_at)` — fast "recent comms for this contact" lookup.
- `(agency_id, property_id, sent_at)` — fast "pitches about this property" lookup.
- `(agency_id, agent_id, sent_at)` — per-agent reporting.
- `(agency_id, outcome)` — outcome reporting.
- `(tracking_short_code)` — public landing-page lookup is by code only (then the row's agency_id is the scoping value).

### 4.3 New table — `seller_outreach_clicks`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | FK → agencies; copied from parent send |
| `send_id` | bigint unsigned | NO | FK → seller_outreach_sends |
| `clicked_at` | timestamp | NO | precise to seconds |
| `ip_address` | varchar(45) | YES | nullable — only stored for fraud / spike detection |
| `user_agent` | varchar(500) | YES | |
| `geo_country` | char(2) | YES | ISO country code, best-effort |
| timestamps | | | |

Indexes: `(agency_id, send_id, clicked_at)`, `(send_id, clicked_at)`.

### 4.4 New columns on existing `contacts` table

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `messaging_opt_out_at` | timestamp | YES | set when seller replies STOP |
| `messaging_opt_out_reason` | varchar(255) | YES | |
| `messaging_opt_out_recorded_by_user_id` | bigint unsigned | YES | |

These are minimal additive columns. The contact's existing communication timeline (or a new `contact_communications` table if one doesn't exist — pre-flight identifies) carries the per-send entries.

### 4.5 No changes to existing data layer

`prospecting_listings`, `contact_matches`, `properties`, `prospecting_buyer_matches` etc. — all unchanged. The outreach build is purely additive.

---

## Section 5 — Service Layer

### 5.1 `SellerOutreachComposerService`

Constructs the pitch context from a contact + property pair. Single public method:

```php
public function composeContext(int $agencyId, Contact $contact, Property $property, ?int $templateId = null): OutreachContext
```

`OutreachContext` (DTO) carries:
- The contact, property, agent (current auth user).
- The resolved template (or default for channel).
- The full merge-field value map.
- The live facts (buyer_count, matching_buyer_count, etc. — pulled from `ProspectingIntelligenceService`).
- The rendered subject + body (template applied to fields).
- Validation status (e.g. "contact has no phone — cannot send WhatsApp").
- Cooldown signal (last contact date from this agency, if any).
- Opt-out status (blocks send).

### 5.2 `SellerOutreachSenderService`

Handles the actual send action:

```php
public function send(OutreachContext $context, string $channel): SellerOutreachSend
```

- Generates the unique `tracking_short_code` (retries on collision; agency-scoped uniqueness so the 36^6 space is per-agency).
- Builds the tracking URL.
- Renders final body with the tracking link substituted.
- Creates the `SellerOutreachSend` record with all snapshots.
- Fires `SellerOutreach\PitchSent` event.
- Returns the send record for the controller to use in building the wa.me / mailto URL.

### 5.3 `SellerOutreachLandingService`

Resolves the public landing page state:

```php
public function resolveLanding(string $shortCode): LandingPageData
```

- Looks up the send by `tracking_short_code` (no agency scoping on the query — the code is the entry point).
- Determines render mode (Active / Generic / Agent-Unavailable) based on:
  - Property's current state (not archived, not flagged sold elsewhere).
  - Agent's current state (active, not deactivated).
- Computes live demand stats by calling `ProspectingIntelligenceService::snapshot()` scoped to the send's agency.
- Records the click via `SellerOutreachClickRecorder`.
- Returns `LandingPageData` to the controller.

### 5.4 `SellerOutreachTemplateValidator`

A small service that ensures every template body contains `{tracking_link}` before save. Used by the templates CRUD controller.

---

## Section 6 — UI Surfaces

### 6.1 Composer screen — `/corex/contacts/{contact}/outreach/compose`

Layout:

**Left column (60% width):**
- Top: contact header (name, phone, email, opt-out badge if applicable).
- Property selector dropdown — pre-filled if entered with `?property_id=N` query param.
- Channel toggle: WhatsApp / Email (Email disabled if contact has no email; WhatsApp disabled if no phone).
- Template dropdown — defaults to the agency's default-for-channel.
- Subject field (email only).
- Body editor (textarea with monospace font). Live preview of rendered body below.
- Send button: "Open WhatsApp" or "Send Email" depending on channel.

**Right column (40% width):**
- Communication timeline for this contact (cooldown signal, every prior send + click).
- Facts panel — preview of the live values that will be injected into the merge fields:
  - "Buyer count in {town}: 14"
  - "Matching for this property: 7"
  - "Tracking link: {auto-generated short URL}"

On send:
1. Composer creates the send record.
2. Server returns the wa.me or mailto URL.
3. Browser opens that URL in a new tab.
4. The composer screen updates to show "Pitch sent at {timestamp} — see contact timeline."

### 6.2 Contact's communication timeline

Add to the contact detail page (likely as a tab alongside Wishlists, Documents, etc.):
- Reverse-chronological list of every send.
- Per row: channel icon, template name, agent, sent timestamp, outcome badge, first-clicked timestamp, expand for body snapshot.
- Action buttons per row: "Update outcome", "Open landing page in new tab", "Resend (creates new send with same body)".

### 6.3 Templates settings page — `/corex/settings/outreach-templates`

CRUD page mirroring the Prospecting Setup pattern:
- Two tabs: WhatsApp templates / Email templates.
- Per template: name, body editor with merge-field picker, "Set as default for channel" toggle, active/inactive toggle.
- Save validates that `{tracking_link}` is present, opt-out clause is present (regex-based check for "STOP" or similar).
- HFC seeded with 3 default templates per S4.

### 6.4 Public landing page — `/m/{shortcode}` (unauthenticated)

Routes outside `corex/*` prefix. Per S8 — three render modes determined by `SellerOutreachLandingService`.

**Mobile-first.** Most sellers click these links on their phones. Single-column layout, large touch targets for the WhatsApp / Email / Callback buttons.

**Branded.** Agency logo top-left. Agency colour scheme if configurable (probably v2; v1 uses CoreX defaults).

**No tracking cookies / no analytics scripts.** Just the server-side click recording. POPIA-friendly.

### 6.5 Callback request form

Small form embedded on the landing page. Captures:
- Seller's name (pre-filled from contact if known).
- Preferred contact time (text or quick options).
- Optional message.

On submit, creates a task on the agent's dashboard (using whatever task system CoreX has, or a simple new `seller_outreach_callbacks` table if no task system).

### 6.6 Sidebar navigation

Adds:
- "Outreach templates" under Settings (gated `outreach_templates.manage`).
- "Compose pitch" button visible on contact pages where the contact has at least one phone or email (gated `outreach.compose`).

### 6.7 Permissions

Two new permissions:
- `outreach.compose` — can open the composer + send. Granted to all agents by default.
- `outreach_templates.manage` — can edit agency templates. Agency admins only.

---

## Section 7 — Build Prompt Sequence

| # | Prompt | Summary | Success criteria |
|---|---|---|---|
| 01 | **Schema + seeders** | 3 new tables + 3 new columns on contacts + HFC default templates seeded | All migrations + rollback proven; 3 templates seeded with all required merge fields including `{tracking_link}` |
| 02 | **Models + services** | 3 Eloquent models, 4 services (composer, sender, landing, template validator), per-request context cache | Tinker tests every service method; dedupe + cooldown + opt-out logic all verified |
| 03 | **Events + listeners** | 4 event classes + 3 listeners (timeline writer, opt-out flag setter, audit log via wildcard) | Each event fires on its trigger; timeline entries created; opt-out blocks subsequent sends |
| 04 | **Templates CRUD UI** | Settings page with WhatsApp + Email tabs; default seeding; merge-field picker; validator on save | Admin creates/edits/archives a template; `{tracking_link}` absence rejected at save |
| 05 | **Composer UI + send action** | `/corex/contacts/{contact}/outreach/compose` page; wa.me / mailto open on send; communication timeline tab on contact | End-to-end: compose pitch → open WhatsApp → send record exists with full facts_snapshot |
| 06 | **Public landing page** | `/m/{shortcode}` route; Active/Generic/Agent-Unavailable modes; click tracking; callback form | Click registers a `seller_outreach_clicks` row; mode-switching verified by archiving the property + reloading |
| 07 | **Contact timeline integration** | Add Outreach tab on contact detail page; outcome update flow; resend action | Timeline shows every send + click; outcome update writes audit row |
| 08 | **Entry-point wiring** | "Compose pitch" button on contact, property, and prospecting-tab listing rows | Each entry point converges on the composer with the right contact+property context |
| 09 | **End-to-end smoke audit** | Investigation-only audit; defensibility, multi-tenancy, opt-out blocking, link tracking verified end-to-end | Single audit doc with PASS/FAIL/WARN; sign-off for production |

Each prompt: mandatory reads + pre-flight + changes + verification + dev-check.

---

## Section 8 — Rollback Plan

Per-prompt rollback via `migrate:rollback` and `git revert`. No destruction of existing data — all writes go to new tables, all reads from existing tables. Existing contact / property / prospecting flows are unaffected.

If a send goes wrong post-deploy, the send record is soft-deleted (the body_snapshot stays in the soft-deleted row for compliance — never hard-deleted). The contact's communication timeline filters out soft-deleted rows but admins can view them.

---

## Section 9 — Acceptance Criteria

1. Three new tables created with full schema per Section 4. All `BelongsToAgency`.
2. Three new columns on `contacts` for opt-out tracking.
3. HFC seeded with 3 default templates (initial sale outreach, follow-up, expired re-pitch). Each contains `{tracking_link}` and an opt-out clause.
4. Composer page opens from a contact, requires a property linkage, blocks send when contact is opted out.
5. Sending creates `seller_outreach_sends` row with full body_snapshot + facts_snapshot.
6. wa.me URL opens with the message pre-filled.
7. Public landing page renders Active mode when property is current.
8. Landing page renders Generic mode when property is archived.
9. Landing page renders Agent-Unavailable mode when agent is deactivated.
10. Click on landing page records `seller_outreach_clicks` row + flips parent `first_clicked_at`.
11. Live demand stats on the landing page are sourced from `ProspectingIntelligenceService::snapshot()` — verified by manipulating buyer data and reloading the page.
12. Contact's communication timeline shows every send + click.
13. Outcome update on a send fires the audit event.
14. Opt-out blocks future sends with a clear notice.
15. Multi-tenancy proven across two agencies — sends, templates, landing pages all isolated.
16. All 4 events emit on writes; audit log captures them.
17. Templates CRUD enforces `{tracking_link}` presence + opt-out clause presence.
18. Permissions: agent default has `outreach.compose`; admin has both.
19. dev-check.ps1 PASS, all migrations cleanly rollback-and-reapply.

---

## Section 10 — Open Questions

1. **Existing communication timeline.** Does the Contact pillar already have a `contact_communications` table or similar? Pre-flight in Prompt 02 identifies. If yes, this spec writes to it. If no, this spec adds a minimal one.
2. **Agency branding on landing page.** Logo upload, colour theme — out of v1 scope. CoreX-default styling with agency name in plain text. v2 enhancement.
3. **Email sending — SMTP vs mailto.** v1 ships mailto: only (opens agent's email client). v2 enhancement: agency SMTP gateway integration.
4. **Inbound WhatsApp replies.** Not handled. Seller replies to agent's personal number; agent manually updates the outreach outcome. v2: optional Cloud API integration to capture replies.
5. **Bulk send.** Not in v1. One pitch at a time. v2 could add a "send to all matching sellers in this town" workflow with per-contact agent-review steps.
6. **A/B testing of templates.** Not in v1. Templates support is_active toggle but no rotation/randomisation. v2 enhancement.
7. **Geo-IP service for click tracking.** Out of v1 scope — `geo_country` column stays NULL initially. v2 enhancement: free-tier MaxMind GeoLite2 lookup.

---

**End of spec.**
