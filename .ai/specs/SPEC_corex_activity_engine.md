# SPEC: CoreX Activity Engine

**Status:** Active build spec
**Author:** Claude (commissioned by Johan)
**Created:** Monday 4 May 2026
**Target:** HFC go-live 1 July 2026
**Branch:** HFC2402

This spec is the single source of truth for the CoreX Activity Engine — eight integrated modules that turn the calendar into a complete activity capture, buyer management, and seller transparency system. Every build prompt references this file.

---

## 1. Vision

The CoreX Activity Engine is one feature with eight modules:

```
┌──────────────────────────────────────────────────────────────┐
│              CoreX ACTIVITY ENGINE                            │
│                                                                │
│  CALENDAR ──▶ FEEDBACK ──▶ BUYER CRM ──▶ MATCHING (existing)  │
│       │                          │                             │
│       ├──▶ ACTIVITY POINTS (existing daily activity engine)   │
│       │                                                         │
│       └──▶ PROPERTY ACTIVITY ──▶ SELLER LIVE LINK             │
│                                                                │
└──────────────────────────────────────────────────────────────┘
```

Agent does their job (creates calendar events, captures feedback once). System does the rest:
- Updates buyer profile with revealed preferences
- Updates seller activity report
- Auto-credits agent activity points
- Surfaces cold buyers / lost stock / why-we-lost reasons
- Feeds reporting dashboards across agent / BM / admin

Calendar is the input layer. Buyer CRM is the brain. Seller Live Link is the output layer. Feedback is the connective tissue. Activity Points is the gamification/integrity layer.

---

## 2. Locked Decisions

Every decision agreed in chat 4 May 2026, captured here for reference.

### 2.1 Calendar UX
- Date header clickable with month/year/date picker on every view
- Cell click navigates (does NOT open create modal). Single "Add event" button in toolbar
- Time slots on week + day views: all-day swim-lane at top + hourly grid below
- Drag-to-reschedule on manual events only. Source-driven events lock
- Click-drag to create in week/day views with pre-filled time range
- Today indicator with current-time line on day/week views
- Keyboard shortcuts: T (today), M/W/D/A (view switch), arrows (nav), N (new event), Esc (close panel)
- Reschedule preserves history — old time logged, visible in audit trail and seller report

### 2.2 Event Classes (3 new added to existing 38, total 41)
- `viewing` — buyer viewing a property
- `valuation` — agent valuing for potential seller
- `listing_presentation` — agent presenting CMA to potential seller

### 2.3 Event Linking
- Manual events link to: 1 property (optional) + N contacts (optional, multi-select) + 1 deal (optional)
- New `calendar_event_links` polymorphic pivot for many-to-many
- Multi-buyer events: separate feedback row per (event × contact) pair

### 2.4 Feedback
- Two-pass model: provisional on event creation → confirmed on feedback capture
- Feedback structure: outcome (agency-configurable list), concerns (agency-configurable multi-select), seller-visible notes, internal notes, next action
- Feedback per (event × contact) pair, not per event
- **Privacy**: buyer names NEVER appear on seller live link. "A buyer viewed" not "John Smith viewed"
- Auto-task created 24h after event passes if no feedback captured
- Lost reasons + concerns + outcomes unified in `agency_feedback_options` (single table, `category` column)

### 2.5 Contact Governance (POPIA / CPA compliant)
- Three sharing modes (agency-configurable, default Branch):
  - **Open** — all contacts visible agency-wide
  - **Branch** — contacts visible within branch only, BM sees branch, admin sees all
  - **Closed** — contact owned by capturing agent, BM sees branch, admin sees all
- Three duplicate handling modes (agency-configurable, default Soft Warn):
  - **Hard Block** — PropCon-style, agent can't proceed without permission
  - **Soft Warn** — proceed allowed, surfaces to admin cleanup queue
  - **Auto-Link** — existing contact found, both agents linked to it
- Managerial chain ALWAYS sees: agent + BM + admin chain regardless of opt-out (contractual model)
- Per-channel opt-out schema designed in (whatsapp, email, sms, call) — wired fully when consent module lands
- POPIA/CPA hooks: consent record on contact, audit trail of who viewed when

### 2.6 Buyer CRM
- **Buyer profiles SEPARATE from contacts** — new `buyer_profiles` table, one-to-one with contacts
- A contact may have a separate `seller_profile` too (separate concerns)
- Lifecycle states: New, Active, Warm, Cold, Lost, Bought (with us), Bought (elsewhere)
- State transitions: agency-configurable freshness windows (e.g. HFC: 7 days = Warm threshold)
- Auto-flag, never auto-progress (agent retains control of state with one-click confirmation)
- **Snooze with return date**: agent parks buyer ("comes back in September"). Snoozed buyers don't appear in cold detection until return date. On return date the buyer auto-resurfaces; escalation timers reset.
- Revealed preferences engine: stated criteria from `contact_matches` (Andre's V2) + revealed preferences from feedback patterns
- Lost-deal reason capture required when state moves to Lost / Bought Elsewhere

### 2.7 Seller Live Link
- Per-property unique tokenised URL (token + expiry pattern, like signature_requests)
- Seller bookmarks the link, no login required, refreshes live
- **Agency white-label** — branding shown is the agency that listed the property, not HFC
- Page contents:
  - Property header: address, photo, listed price, days on market, agency branding
  - Activity timeline (reverse chronological): viewings (anonymised), online activity (P24/PP views/leads), marketing actions, listing milestones
  - Footer: agent contact details + "Request another viewing" CTA
- All buyer identity redacted ("a buyer viewed", never names)

### 2.8 Activity Points Engine (integrates existing daily_activity_entries)
- Hooks into existing `daily_activity_entries` table (1492 rows of production data)
- Hooks into existing 41-row `activity_definitions` table
- Existing scope structure (`global` → renamed to `system`, plus new `agency` and `branch` scopes)
- New mapping: `activity_definition_calendar_classes` — maps calendar event classes to activity definitions per agency
- Two point states:
  - **Provisional** — credited when event is created with feedback-eligible class (gives agent committed-effort visibility)
  - **Confirmed** — credited when feedback is captured (the points become real)
- Auto-revoke triggered by: event passes + 24h with no feedback → provisional points removed, task created
- Late feedback re-credits points
- Anti-gaming integrity layer:
  - Same buyer + same property + same week = duplicate, second viewing earns 0
  - Self-cancelled events earn 0
  - Back-dated more than 48h earns 0
  - BM/admin can override with audit trail
  - Daily cap per activity type (configurable, default no cap)
- Manual entry path preserved for non-calendar activities (existing flow unchanged)
- Daily summary view shows split: auto-credited vs manually captured

### 2.9 Reporting Dashboards
- Agent productivity (own performance + targets)
- BM oversight (all branch agents, cold buyers per agent, lost-deal reasons)
- Admin agency-wide (all branches, lost-deal patterns, cold buyer trends, activity point trends)
- All dashboards filter by date range, branch, agent

### 2.10 Activity Definitions Scope (HFC's 41 split)
- Existing 41 rows have `scope='global'`. Migration:
  - Rename `global` → `system` enum value
  - Add `agency_id` column (nullable)
  - Identify ~12-15 universal real estate activities → keep as `scope=system`
  - Move HFC-specific phrasing/items → `scope=agency, agency_id=1`
- New agencies get `system` defaults at signup, build their own `agency` list on top

### 2.11 Out of Scope (deferred)
- Wizards (will be separate spec, integrated with Ellie AI later)
- Full Outlook calendar parity (recurring rule editor UI, attendee invitations with RSVP, free/busy lookup, room booking, time zones)
- Mobile app integration (Andre handles separately after web ships)
- Self-serve agency signup with payment/billing (manual onboarding only)
- Timeline/Gantt calendar view
- iCal feed for phone subscriptions

---

## 3. Data Model — All New Tables

### 3.1 Calendar Linking + Feedback (Module 2)

```
calendar_event_links               (polymorphic pivot)
  id
  calendar_event_id    FK calendar_events
  linkable_type        string       (App\Models\Property | App\Models\Contact | App\Models\DealV2\DealV2)
  linkable_id          unsignedBigInt
  role                 string       (e.g. 'attendee', 'subject_property', 'related_deal')
  created_by_user_id   FK users
  timestamps
  softDeletes
  unique [calendar_event_id, linkable_type, linkable_id, role]

calendar_event_feedback
  id
  calendar_event_id    FK
  contact_id           FK contacts (nullable — feedback may be for the event in general)
  outcome_option_id    FK agency_feedback_options (nullable until captured)
  concern_option_ids   json (array of agency_feedback_options.id where category=concern)
  seller_visible_notes text
  internal_notes       text
  next_action_notes    text
  captured_by_user_id  FK users
  captured_at          datetime
  agency_id            FK
  branch_id            FK (nullable)
  timestamps
  softDeletes

calendar_event_audit_log           (for reschedules and other history-preserving changes)
  id
  calendar_event_id    FK
  action               string       ('created'|'rescheduled'|'cancelled'|'completed'|'feedback_captured'|...)
  old_values           json (nullable)
  new_values           json (nullable)
  performed_by_user_id FK users
  performed_at         datetime
  notes                text (nullable)
  created_at
```

### 3.2 Agency Feedback Options (Module 7)

```
agency_feedback_options
  id
  agency_id            FK (nullable for system defaults)
  category             enum('outcome', 'concern', 'lost_reason')
  label                string
  description          string (nullable)
  is_active            boolean
  sort_order           int
  is_system_default    boolean       (true = seeded, can be disabled but not deleted)
  timestamps
  softDeletes
```

### 3.3 Contact Governance (Module 3)

```
agency_contact_settings
  id
  agency_id            FK unique
  sharing_mode         enum('open','branch','closed') default 'branch'
  duplicate_mode       enum('hard_block','soft_warn','auto_link') default 'soft_warn'
  duplicate_match_fields json     (which fields trigger dup detection: phone, email, id_number)
  cold_buyer_days      int default 7   (days before BM gets cold-buyer notification)
  warm_buyer_days      int default 14
  lost_buyer_days      int default 60
  timestamps

contact_consent_records              (POPIA compliance)
  id
  contact_id           FK
  consent_type         enum('whatsapp','email','sms','call','marketing','data_share')
  consent_given        boolean
  consent_method       string      ('verbal','written','digital','imported')
  consent_evidence     text         (nullable — what was said/signed)
  recorded_by_user_id  FK users
  recorded_at          datetime
  expires_at           datetime (nullable)
  revoked_at           datetime (nullable)
  revocation_reason    text (nullable)
  timestamps
  softDeletes

contact_access_log                   (POPIA audit trail — who viewed when)
  id
  contact_id           FK
  user_id              FK
  accessed_at          datetime
  context              string       (e.g. 'index_view','show_view','edit','calendar_link','match_results')
  agency_id            FK
  created_at

contact_duplicate_queue              (admin cleanup queue when soft_warn triggers)
  id
  proposed_contact_id  FK contacts (the new one being created)
  existing_contact_id  FK contacts (the suspected duplicate)
  match_field          string       ('phone','email','id_number')
  match_confidence     decimal(3,2) (0-1.00)
  status               enum('pending','merged','dismissed','duplicate_kept')
  resolved_by_user_id  FK users (nullable)
  resolved_at          datetime (nullable)
  agency_id            FK
  timestamps

contact_access_grants                (Hard Block mode: agent A grants agent B access)
  id
  contact_id           FK
  granted_to_user_id   FK users
  granted_by_user_id   FK users
  granted_at           datetime
  expires_at           datetime (nullable, null = permanent)
  revoked_at           datetime (nullable)
  reason               text
  agency_id            FK
  timestamps
```

### 3.4 Buyer CRM (Module 4)

```
buyer_profiles                       (one-to-one with contacts)
  id
  contact_id           FK contacts unique
  agency_id            FK
  branch_id            FK (nullable)
  primary_agent_id     FK users
  lifecycle_state      enum('new','active','warm','cold','lost','bought_with_us','bought_elsewhere','snoozed') default 'new'
  state_changed_at     datetime
  state_changed_by     FK users (nullable, null = system)
  state_change_reason  text (nullable)
  
  -- Snooze
  snoozed_until        date (nullable)
  snooze_reason        text (nullable)
  
  -- Activity tracking
  last_contacted_at    datetime (nullable)
  last_viewing_at      datetime (nullable)
  last_engaged_at      datetime (nullable)   -- any meaningful interaction
  total_viewings       int default 0
  total_offers_made    int default 0
  
  -- Stated vs revealed
  stated_budget_min    bigint (cents, nullable)
  stated_budget_max    bigint (cents, nullable)
  stated_beds          int (nullable)
  stated_baths         int (nullable)
  stated_suburbs       json (nullable)
  stated_property_types json (nullable)
  
  -- Revealed (computed from feedback patterns)
  revealed_preferences json (nullable)   -- {single_storey: true, hates_open_plan: true, ...}
  revealed_dealbreakers json (nullable)  -- ['damp', 'school_zone_outside_X']
  revealed_at          datetime (nullable) -- last time engine recomputed
  
  -- Lost capture
  lost_reason_option_id FK agency_feedback_options (nullable)
  lost_at              datetime (nullable)
  lost_to_agency_name  string (nullable, free text)
  
  notes                text (nullable)
  
  timestamps
  softDeletes

buyer_property_history               (every property shown / matched / rejected with reasoning)
  id
  buyer_profile_id     FK
  property_id          FK properties
  status               enum('matched','sent','viewed','rejected_by_agent','rejected_by_buyer','offer_made','offer_accepted','offer_declined','withdrawn')
  source               enum('contact_match','manual','calendar_event','automatch')
  source_match_id      FK contact_matches (nullable)
  calendar_event_id    FK (nullable)
  feedback_id          FK calendar_event_feedback (nullable)
  agent_notes          text (nullable)
  outcome_summary      string (nullable)
  status_changed_at    datetime
  status_changed_by    FK users
  timestamps
  softDeletes
  unique [buyer_profile_id, property_id, status_changed_at]

buyer_lifecycle_log                  (history of state transitions for audit)
  id
  buyer_profile_id     FK
  from_state           string (nullable)
  to_state             string
  changed_by_user_id   FK users (nullable, null = system)
  reason               text (nullable)
  metadata             json (nullable)
  changed_at           datetime
  created_at
```

### 3.5 Seller Live Link (Module 5)

```
seller_share_tokens
  id
  property_id          FK properties unique
  token                string(64) unique
  agency_id            FK
  branch_id            FK (nullable)
  is_active            boolean default true
  created_by_user_id   FK users
  last_accessed_at     datetime (nullable)
  access_count         int default 0
  expires_at           datetime (nullable)  -- null = never expires
  revoked_at           datetime (nullable)
  timestamps

seller_link_access_log
  id
  seller_share_token_id FK
  ip_address            string (nullable)
  user_agent            text (nullable)
  accessed_at           datetime
  created_at

property_activity_log               (unified timeline for seller link)
  id
  property_id          FK
  activity_type        enum(
    'listed','price_changed','viewing_completed','viewing_scheduled','viewing_cancelled',
    'feedback_captured','offer_received','offer_withdrawn','marketing_action',
    'portal_view_summary','lead_received','enquiry_received','status_changed',
    'mandate_signed','mandate_renewed','show_day_held','agent_assigned'
  )
  occurred_at          datetime
  agency_id            FK
  branch_id            FK (nullable)
  
  -- Display content (seller-visible, redacted)
  seller_visible_title       string
  seller_visible_description text (nullable)
  
  -- Internal content (full detail)
  internal_title       string
  internal_description text (nullable)
  
  -- Source linking
  source_type          string (nullable)  -- e.g. App\Models\CommandCenter\CalendarEvent
  source_id            unsignedBigInt (nullable)
  
  metadata             json (nullable)
  
  is_seller_visible    boolean default true
  
  created_by_user_id   FK users (nullable, null = system)
  timestamps
  softDeletes
```

### 3.6 Activity Points Engine (Module 6) — extends existing tables

```
ALTER TABLE activity_definitions
  ADD agency_id        FK agencies (nullable)
  MODIFY scope ENUM('system','agency','branch') (after data migration from 'global')

activity_definition_calendar_classes
  id
  activity_definition_id  FK
  event_class             string         -- matches calendar_events.category
  agency_id               FK (nullable for system defaults)
  point_value_override    int (nullable)  -- if null, uses activity_definition.weight
  requires_feedback       boolean default true   -- only credit when feedback captured
  is_active               boolean default true
  timestamps
  unique [activity_definition_id, event_class, agency_id]

ALTER TABLE daily_activity_entries
  ADD source_type      string (nullable)    -- 'manual','calendar_auto','admin_override'
  ADD source_event_id  FK calendar_events (nullable)
  ADD point_state      enum('provisional','confirmed','revoked') default 'confirmed'
  ADD provisional_at   datetime (nullable)
  ADD confirmed_at     datetime (nullable)
  ADD revoked_at       datetime (nullable)
  ADD override_by_user_id FK users (nullable)
  ADD override_reason  text (nullable)

activity_point_overrides             (audit trail of BM/admin manual adjustments)
  id
  daily_activity_entry_id FK
  old_value            int
  new_value            int
  old_state            string
  new_state            string
  reason               text
  performed_by_user_id FK users
  performed_at         datetime
  created_at
```

### 3.7 Reporting (Module 8) — primarily views and computed materialised summaries; minimal new tables

```
buyer_cold_alerts                    (snapshot table, refreshed nightly for fast BM dashboards)
  id
  buyer_profile_id     FK
  agent_id             FK users
  branch_id            FK
  agency_id            FK
  days_since_contact   int
  current_state        string
  computed_at          datetime
  acknowledged_at      datetime (nullable)
  acknowledged_by      FK users (nullable)
  created_at
```

---

## 4. Module-by-Module Build Plan

### Module 1 — Calendar Industry-Standard UX
**Why:** Current calendar is functional but not intuitive. UX critical for adoption.
**Prompts:** ~5
- M1.1: Demo data seeder (realistic events across all 8 sources, all 3 branches, 30+ manual events, mix of past/today/future/with-feedback/without-feedback, idempotent, tagged `metadata.demo=true`)
- M1.2: Clickable date header with month/year/date picker on every view + Today button
- M1.3: Click cell behaviour fix (cell click navigates to day view, "Add event" only via toolbar button)
- M1.4: Time slots on week + day views (all-day swim-lane top + hourly grid below)
- M1.5: Today indicator (current-time line on day/week views) + keyboard shortcuts (T/M/W/D/A/N/arrows/Esc)

### Module 2 — Calendar Event Linking + Feedback Capture
**Why:** Foundation for buyer CRM, property activity, activity points.
**Prompts:** ~6
- M2.1: 3 new system event classes (viewing/valuation/listing_presentation) seeded into calendar_event_class_settings
- M2.2: `calendar_event_links` polymorphic pivot table + relationships on CalendarEvent + Property + Contact + DealV2
- M2.3: Manual event create modal V2 (link to property search/select, contacts multi-select, deal optional)
- M2.4: `calendar_event_feedback` table + `agency_feedback_options` table + seeder with system defaults
- M2.5: Feedback capture modal (per event-contact pair) + auto-task creation on missed feedback
- M2.6: `calendar_event_audit_log` table + reschedule preserves history + drag-to-reschedule for manual events only

### Module 3 — Contact Governance + POPIA/CPA
**Why:** Foundation for everything contact-touching that follows. Compliance built in from day one.
**Prompts:** ~6
- M3.1: `agency_contact_settings` table + settings UI for sharing mode + duplicate mode + freshness windows
- M3.2: Contact visibility scoping (Open/Branch/Closed modes wired into Contact model global scope)
- M3.3: Duplicate detection on contact create (Hard Block / Soft Warn / Auto-Link) + `contact_duplicate_queue` + admin cleanup screen
- M3.4: `contact_consent_records` + `contact_access_log` + audit trail middleware on contact controllers
- M3.5: `contact_access_grants` table + agent-grants-access flow for Hard Block mode
- M3.6: Per-channel opt-out schema design (whatsapp/email/sms/call) — schema only, full UI deferred

### Module 4 — Buyer CRM Lifecycle
**Why:** The competitive moat. Sits on top of Module 3.
**Prompts:** ~7
- M4.1: `buyer_profiles` table + one-to-one with contacts + auto-create on first viewing event
- M4.2: Lifecycle state machine + manual state changes + snooze with return date
- M4.3: `buyer_property_history` table + integrate with Andre's `contact_matches` (enrich, don't replace)
- M4.4: Revealed preferences engine (computes from feedback patterns, runs nightly + on-demand)
- M4.5: Cold buyer detection (daily scan, surfaces to dashboards) + `buyer_cold_alerts` snapshot table
- M4.6: Buyer index page enriched (list view with state badges, last contact, revealed preferences)
- M4.7: Buyer detail page (full lifecycle, property history, feedback timeline, snooze/state controls)

### Module 5 — Seller Live Link + Property Activity
**Why:** The killer demonstrable feature. Wins recruitment battles.
**Prompts:** ~5
- M5.1: `property_activity_log` table + populate from existing data backfill (calendar events, feedback, listing milestones)
- M5.2: Activity log writers — every relevant event auto-logs (calendar feedback, price changes, mandate events, etc.)
- M5.3: `seller_share_tokens` table + token generate / revoke / regenerate UI on property show page
- M5.4: Public seller live link page (tokenised URL, no auth, agency white-label, redacted activity timeline, agent CTA)
- M5.5: P24/PP online activity integration (existing portal data surfaces in seller view)

### Module 6 — Activity Points Engine (Calendar → Daily Activities)
**Why:** Closes the integrity loop. Agent gets points for doing the work and capturing feedback.
**Prompts:** ~5
- M6.1: Migrate `activity_definitions.scope` from 'global' → 'system' + add `agency_id` + split HFC's 41 (system vs agency)
- M6.2: `activity_definition_calendar_classes` mapping table + admin UI to map classes → activities per agency
- M6.3: Calendar event creation hooks → provisional activity entry creation (with point_state)
- M6.4: Feedback capture hooks → confirm provisional points + auto-revoke on missed-feedback timeout
- M6.5: Daily activity view enhancement (show split: auto-credited vs manual + provisional vs confirmed) + override audit trail

### Module 7 — Lost-Deal Reasons + Feedback Concerns
**Why:** Standalone settings module that Module 2 + Module 4 both depend on.
**Prompts:** ~2 (most logic builds with M2.4)
- M7.1: `agency_feedback_options` settings UI (CRUD per category, sort order, system defaults visible but read-only)
- M7.2: Reporting hooks — lost reason aggregations, concern frequency, cited-elsewhere capture

### Module 8 — Reporting Dashboards
**Why:** Makes the data actionable. BMs need oversight; admins need agency-wide patterns.
**Prompts:** ~5
- M8.1: Agent productivity dashboard (own performance, target vs actual, point breakdown by source)
- M8.2: BM dashboard (branch agents, cold buyers per agent, lost reasons per agent, activity heatmap)
- M8.3: Admin dashboard (agency-wide lost reason patterns, cold buyer trends, branch comparisons, stock vs buyer matching gaps)
- M8.4: Agent leaderboard (gamification, weekly/monthly, branch + agency)
- M8.5: Property activity report PDF export (for offline seller meetings, mirrors live link content)

**Total estimated prompts: ~41**

---

## 5. Build Sequence (dependency-aware)

```
Phase A — Foundation (no dependencies):
  M1.1  Demo data seeder
  M2.1  Three new event classes
  M7.1  Agency feedback options (the core enum table)

Phase B — Core mechanics (depend on Phase A):
  M1.2  Date picker
  M1.3  Click behaviour fix
  M2.2  Event links pivot
  M3.1  Contact governance settings
  M3.2  Contact visibility scoping

Phase C — Linked feedback (depend on Phase B):
  M2.3  Event create modal V2 (with linking)
  M2.4  Feedback capture table + agency feedback options
  M2.5  Feedback capture modal + auto-task on missed
  M3.3  Duplicate detection + cleanup queue
  M3.4  Consent + access log
  M2.6  Audit log + reschedule history + drag

Phase D — UX polish (parallelisable with C):
  M1.4  Time slots
  M1.5  Today indicator + keyboard shortcuts

Phase E — Buyer CRM (depends on C+D):
  M4.1  Buyer profiles table + auto-create
  M4.2  Lifecycle state machine + snooze
  M4.3  Property history + contact_matches integration
  M4.4  Revealed preferences engine
  M4.5  Cold detection + alerts
  M4.6  Buyer index page
  M4.7  Buyer detail page
  M3.5  Access grants for Hard Block (after buyer flows clarify need)
  M3.6  Per-channel opt-out schema

Phase F — Seller-facing (depends on C):
  M5.1  Property activity log + backfill
  M5.2  Activity log writers
  M5.3  Share tokens + management UI
  M5.4  Public seller link page (white-label)
  M5.5  Portal data integration

Phase G — Activity points (depends on C):
  M6.1  Scope migration + activity split
  M6.2  Class mapping table + admin UI
  M6.3  Provisional point creation
  M6.4  Confirm/revoke logic
  M6.5  Daily view enhancement

Phase H — Reporting (depends on E+F+G):
  M8.1  Agent dashboard
  M8.2  BM dashboard
  M8.3  Admin dashboard
  M7.2  Lost reason reporting hooks
  M8.4  Leaderboard
  M8.5  PDF export

Phase I — Cleanup:
  Final integration testing
  Performance tuning
  Production cutover prep
```

---

## 6. Integration with Existing Systems

### Existing Calendar (already built — 22 prompts shipped)
- 41 event classes seeded (38 + 3 new in M2.1)
- 8 source services registered, ~460 events live
- ThresholdResolver, VisibilityResolver, NotificationDispatcher operational
- Daily digest worker + nightly reconciliation operational
- New work plugs into existing CalendarEvent model + CalendarEventService

### Existing Daily Activity System
- 41 `activity_definitions` rows (HFC's list, scope=global)
- 1492 `daily_activity_entries` rows of production data
- DailyActivityController write path: `DB::table('daily_activity_entries')->updateOrInsert()`
- New work creates ActivityDefinition Eloquent model + extends existing schema
- Existing manual capture flow stays unchanged — we ADD an auto-credit path

### Andre's contact_matches V2 (Staging branch, 1d061c8)
- 767 lines of new matching engine on Staging
- ContactMatch model with status states, suburbs, must_have_features, nice_to_have_features
- ContactMatchFeedback (interested/not_interested/saved per match-property)
- ContactMatchNotification (dedup tracking)
- MatchingService with weighted scoring (price 25, suburb 20, bed/bath 15, type 10, nice-to-have 15, freshness 10, engagement 5)
- Buyer CRM Module 4 ENRICHES contact_matches:
  - `buyer_property_history.source_match_id` references contact_matches.id
  - Revealed preferences engine may eventually feed back as suggestions to ContactMatch.must_have_features
  - Lifecycle state on buyer_profiles becomes a filter on contact_matches scoring (cold buyers de-prioritised)

### Andre's branch isolation (Staging branch)
- BelongsToBranch trait + BranchScope global scope
- Split Branches toggle per agency
- All new tables in this spec inherit BelongsToAgency + BelongsToBranch where appropriate

### POPIA / CPA compliance
- Schema designed day one for full compliance
- Per-channel opt-out columns reserved on contact_consent_records
- Audit trail (contact_access_log) wired from M3.4
- Managerial chain visibility (agent + BM + admin) enforced regardless of opt-out per contractual model

### Existing P24 / PP portal data
- 3878 `p24_listings`, 4691 `portal_listings`, 10324 `portal_listing_observations`
- Module 5 surfaces this as "Online activity" on seller live link
- No new portal tables — read-only consumption

---

## 7. Hard Rules

These apply to every prompt in this spec. No exceptions.

1. Every VS Code prompt starts with: read .ai/CLAUDE.md, .ai/STANDARDS.md, .ai/specs/SPEC_corex_activity_engine.md
2. Every prompt ends with: php -l on changed files, view:clear, dev-check.ps1 zero new failures, Tinker functional verification
3. Investigation before every code change — never guess at schema or routes
4. Every "delete" is a soft-delete (CoreX rule). No hard deletes.
5. Every new entity must have full CRUD path (Rule 13)
6. Every reversible action has an audit log entry
7. Multi-tenancy: every new table either has agency_id + BelongsToAgency trait, OR explicit reason documented why not (e.g. system-level reference data)
8. Branch isolation: every tenant table where Split Branches matters gets BelongsToBranch
9. PPRA terminology only (never EAAB)
10. Buyer names NEVER appear on seller-facing UI. Privacy by design.
11. Manager chain (agent + BM + admin) always sees data regardless of opt-out (per contractual model)
12. Settings over hardcoding — every threshold/window/limit configurable per agency
13. No demo modes. No "good enough for now". Production quality only.
14. UI matches existing CoreX design: dark navy + teal accent, Plus Jakarta Sans, sharp 2-3px borders, no emojis, no rounded corners
15. Wizards are explicitly out of scope — do not add wizard scaffolding to any module

---

## 8. Out of Scope (for this build)

- Wizards (separate spec, integrated with Ellie AI later)
- Mobile app (Andre handles after web ships)
- Self-serve agency signup (manual onboarding via direct outreach)
- Full Outlook calendar parity (recurring rule editor UI, attendee invitations with RSVP, free/busy lookup, room booking)
- Timeline/Gantt calendar view
- iCal feed for phone calendar subscriptions
- Multi-tier activity point rules (e.g. first 3/day = full, rest = half) — start with flat per-class rates
- Self-serve buyer access to view their own profile / history — agent-mediated only
- Full commission engine integration with activity points (gates, downgrades) — points feed reports for now
- Recurring manual events (every Wednesday valuation visit) — single events only

---

## 9. Glossary

- **Source-driven event**: calendar event auto-generated from a database row (FFC expiry, mandate expiry). Date locked, no drag.
- **Manual event**: calendar event created by an agent (viewing, valuation). Date editable, draggable.
- **Synthetic event**: computed recurring event (SARS EMP201, monthly rent). No backing row. Idempotent via crc32 source_id.
- **Provisional points**: credited on event creation, visible on agent's daily total but not yet "earned".
- **Confirmed points**: provisional points that were confirmed by feedback capture.
- **Revoked points**: provisional points removed because feedback wasn't captured within 24h grace.
- **Buyer lifecycle state**: New / Active / Warm / Cold / Lost / Bought (with us) / Bought (elsewhere) / Snoozed.
- **Snooze**: agent parks a buyer until a return date. They don't appear in cold detection until snoozed_until passes.
- **Stated criteria**: what a buyer told the agent they want (in contact_matches).
- **Revealed preferences**: what feedback patterns show the buyer actually wants (computed engine output).
- **Seller live link**: per-property tokenised URL the seller bookmarks. White-labelled to listing agency. No login.
- **Privacy redaction**: buyer names + identifying details are NEVER shown on the seller-facing surface.
- **Managerial chain**: agent + BM + admin. Always have visibility regardless of contact opt-out, per contractual model.

---

## 10. Build Start Trigger

This spec is committed to `.ai/specs/SPEC_corex_activity_engine.md`.

Build sequence starts with **M1.1 — Demo Data Seeder** (Phase A). 

Each prompt:
- References this file
- Investigates before code
- Builds surgically
- Verifies before claiming done
- One module-task at a time

No more architecture questions. Build starts now.
