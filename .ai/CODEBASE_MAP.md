# CoreX OS — Codebase Map & Dependency Reference
> **MANDATORY READ** before any code changes.
> Every VS Code prompt MUST read this file alongside CLAUDE.md and STANDARDS.md.
> Last updated: 2026-03-31 | Total tables: 203
>
> **If your change touches a table listed here, check the DEPENDENCY CHAIN
> section for that table. If you add/rename/remove a column, every file
> listed in the chain must be verified.**

---

## CRITICAL RULES

1. **NEVER add columns directly to the database.** Always create a Laravel migration file, commit it, then run it.
2. **Every migration must be in git** before running on ANY server — local, staging, or production.
3. **If you change a column**, check every model, controller, service, and view that reads/writes it (use the dependency chains below).
4. **Property addresses**: ALWAYS use `Property::buildDisplayAddress()` for display and `Property::scopeSearchAddress()` for searching. NEVER use `$property->title` as an address.
5. **Contact roles**: The `contact_types.esign_role` column maps contact types to e-sign roles. The `contact_property.role` column links contacts to properties with a role.
6. **Commission is ALWAYS captured inc VAT**, all internal calculations are ex VAT. Use `PerformanceSetting::get('vat_rate', 15)`.
7. **Signing order**: Tenant/Buyer signs BEFORE Landlord/Seller. Agent signs first. Enforced by `sortRecipientsBySigningOrder()`.

---

## REPOSITORY STRUCTURE

```
/corex
├── .ai/
│   ├── CLAUDE.md               ← Master instructions for VS Code Claude
│   ├── STANDARDS.md            ← UX Standards (dark theme, sticky headers, etc.)
│   ├── CODEBASE_MAP.md         ← THIS FILE — schema + dependency map
│   └── specs/                  ← Feature specs (source of truth per module)
├── app/
│   ├── Http/Controllers/
│   │   ├── Admin/              ← Deal register V1, user mgmt, targets, daily summary
│   │   ├── Agent/              ← Agent-facing controllers
│   │   ├── CommandCenter/      ← Dashboard, Calendar, Tasks, Settings, UserSettings
│   │   ├── CoreX/              ← Properties, contacts, settings, role manager
│   │   ├── DealV2/             ← Deal Register V2 (pipeline, steps, settlement)
│   │   ├── Docuperfect/        ← E-sign wizard, templates, signing, importer, packs
│   │   ├── Compliance/         ← FICA
│   │   ├── PrivateProperty/    ← PP syndication
│   │   └── Property24/         ← P24 syndication
│   ├── Models/
│   │   ├── CommandCenter/      ← CalendarEvent, CommandTask, AutomationRule, HealthScore, etc.
│   │   ├── DealV2/             ← V2 deal models (DealV2, DealStepInstance, etc.)
│   │   ├── Docuperfect/        ← Template, Flow, SignatureTemplate, etc.
│   │   └── [root]              ← Property, Contact, Deal (V1), User, etc.
│   ├── Services/
│   │   ├── CommandCenter/      ← CalendarEventService, TaskService, HealthCalculator, etc.
│   │   ├── DealV2/             ← DealPipelineService
│   │   ├── Docuperfect/        ← SignatureService, CdsParser, DocumentFlattener, etc.
│   │   ├── PrivateProperty/    ← PP SOAP client, mapper, syndication
│   │   ├── Syndication/Property24/ ← P24 API client, mapper, syndication
│   │   └── Finance/            ← CommissionCalculator, RollupService
│   ├── Notifications/          ← TaskDueReminder, EventDueReminder
│   └── Observers/              ← PropertyObserver (+ Command Center hooks)
├── resources/views/
│   ├── admin/                  ← Deal register V1, users, targets, trust interest
│   ├── command-center/          ← Dashboard, calendar, tasks, settings, user-settings
│   ├── corex/                  ← Properties, contacts, settings, role-manager
│   ├── deals-v2/               ← Deal Register V2 (create, show, form, settlement, pipeline)
│   ├── docuperfect/            ← E-sign wizard, templates, signing, importer
│   ├── deposit-interest-calculator/
│   └── layouts/                ← corex-app, corex-sidebar, corex-header
├── database/migrations/        ← ALL schema changes (MUST be here, NEVER raw SQL)
├── routes/web.php              ← All web routes
└── config/corex-permissions.php ← Permission definitions
```

---

## BRANCH STRATEGY

| Branch | Purpose | Rule |
|--------|---------|------|
| `main` | Production-ready code | Only merge tested code |
| `HFC2402` | Johan's dev branch | Feature work → merge to main |
| `Staging` | Andre's dev branch (GitHub) | Andre's work → must merge to main |

**Sync process:** Fetch origin → merge other branches into yours → resolve conflicts → push → merge to main.

---

## MODULE: PROPERTIES

### Tables

**`properties`** (49+ columns) — THE CENTRAL PROPERTY RECORD
```
Address columns (MULTIPLE SOURCES — use buildDisplayAddress()):
  title           — listing headline, NOT a street address
  address         — legacy field, often NULL on newer properties
  street_name     — actual street name (from PP/P24 syndication)
  street_number   — actual street number
  suburb, city, region, district, province, town
  complex_name, unit_number, floor_number, unit_section_block
  property_number, stand_number

Financial:
  price, rental_amount, deposit_amount, commission_percent
  admin_fee, marketing_fee, rates_taxes, levy, special_levy

Syndication (PP):
  pp_syndication_enabled, pp_syndication_status, pp_ref
  pp_listing_feed_ref, pp_last_submitted_at, pp_activated_at
  pp_exclusive_days, pp_delay_until, pp_last_error
  pp_images_last_synced_at, pp_listing_last_synced_at
  pp_hide_street_name, pp_hide_street_number, pp_hide_complex_name
  pp_hide_unit_number, pp_suburb_id, pp_second_agent_id

Syndication (P24):
  p24_syndication_enabled, p24_syndication_status, p24_ref
  p24_last_submitted_at, p24_activated_at, p24_last_error
  p24_images_last_synced_at, p24_listing_last_synced_at

Geo: latitude, longitude
FK: agent_id → users, branch_id → branches, agency_id → agencies
```

**`contact_property`** — Links contacts to properties with roles
```
contact_id → contacts, property_id → properties
role (string) — 'lessor', 'landlord', 'owner', 'tenant', etc.
```

**`property_files`** — Documents attached to properties
```
property_id → properties, contact_id → contacts
document_type_id → document_types, user_id → users
```

**`rental_properties`** (legacy) — Separate rental property table
```
address_line_1, full_address, suburb, city
landlord_name, landlord_email, landlord_phone
monthly_rental
```

### Property Dependency Chain
```
PROPERTY TABLE CHANGED?
├── Model: app/Models/Property.php
│   ├── buildDisplayAddress() — uses: unit_number, complex_name, street_number, street_name, address, suburb, city
│   ├── scopeSearchAddress() — searches: address, street_name, street_number, title, suburb, city, complex_name, unit_number, property_number
│   └── contacts() — belongsToMany via contact_property
├── Controllers that SEARCH properties:
│   ├── CoreX/PropertyController.php → index() search
│   ├── CoreX/ContactPropertyController.php → search() for linking
│   ├── Docuperfect/ESignWizardController.php → searchProperties()
│   └── DealV2/DealV2Controller.php → searchProperties()
├── Controllers that READ property data:
│   ├── CoreX/PropertyController.php → show(), edit()
│   ├── Docuperfect/ESignWizardController.php → showStep() (step 2 property, step 4 auto-fill)
│   ├── Docuperfect/ESignWizardController.php → prepareSigning() (propertyAddress)
│   └── Services/WebTemplateDataService.php → resolve() (template field values)
├── Views that DISPLAY property:
│   ├── corex/properties/index.blade.php
│   ├── corex/properties/show.blade.php
│   ├── corex/contacts/show.blade.php (linked properties tab)
│   ├── docuperfect/esign/wizard.blade.php (step 2 property selection)
│   └── deals-v2/create.blade.php (step 1 property selection)
└── Syndication (reads ALL property columns):
    ├── Services/PrivateProperty/PrivatePropertyListingMapper.php
    └── Services/Syndication/Property24/Property24ListingMapper.php
```

---

## MODULE: CONTACTS

### Tables

**`contacts`** (26 columns)
```
first_name, last_name, phone, email, id_number, address, notes
contact_type_id → contact_types
contact_source_id → contact_sources
created_by_user_id → users
birthday, bank_* fields (6 columns)
```

**`contact_types`** (9 columns) — CRITICAL for e-sign role mapping
```
name — user-defined label (e.g. "Seller", "Landlord", "Prospect")
esign_role — maps to e-sign system: 'seller', 'buyer', 'lessor', 'lessee', null
color, sort_order, is_active
```

**`contact_property`** — Links contacts to properties
```
contact_id → contacts, property_id → properties
role — free text: 'lessor', 'landlord', 'owner', 'tenant', etc.
```

**`contact_testimonials`** — Testimonials a contact gave (captured on the
"Notes & Testimonials" tab; published to the agency website via the Agency
Public API). Spec: `.ai/specs/testimonials.md`
```
agency_id (NOT NULL), contact_id → contacts, user_id (captured by)
body, display_name (public author), rating (1-5, nullable)
published (bool) — the Settings publish tick; published_at, published_by_user_id
Model: App\Models\ContactTestimonial (BelongsToAgency, SoftDeletes)
Publish toggle fires testimonial.* webhooks via ContactTestimonialObserver →
TestimonialVisibilityChanged → DispatchTestimonialWebhooks (mirrors agent sync).
Public read: GET /api/v1/website/testimonials (scope testimonials:read).
```

### Contact Dependency Chain
```
CONTACT TABLE CHANGED?
├── Model: app/Models/Contact.php
│   ├── properties() — belongsToMany via contact_property
│   ├── contactType() — belongsTo ContactType
│   └── documents(), notes(), tags()
├── Controllers that SEARCH contacts:
│   ├── CoreX/ContactController.php → index() search
│   ├── Docuperfect/ESignWizardController.php → searchContacts()
│   └── DealV2/DealV2Controller.php → searchContacts()
├── Controllers that READ contact data:
│   ├── CoreX/ContactController.php → show()
│   ├── Docuperfect/ESignWizardController.php → showStep() (recipients step)
│   ├── Docuperfect/ESignWizardController.php → resolveContactValue()
│   └── Services/WebTemplateDataService.php → resolve()
├── E-Sign role resolution:
│   ├── contact_types.esign_role → used by buildAllowedEsignRoles()
│   ├── contact_property.role → used by searchProperties() to find lessor
│   └── wizard.blade.php → roleMatchesTemplate(), requiredSigningRoles
└── Views:
    ├── corex/contacts/index.blade.php
    ├── corex/contacts/show.blade.php
    └── docuperfect/esign/wizard.blade.php (recipients step)
```

---

## MODULE: E-SIGN / DOCUPERFECT

### Tables

**`docuperfect_templates`** (25 columns) — Document templates
```
name, template_type, render_type ('pdf' or 'web'), blade_view
document_type_id → document_types
fields_json, cds_json, field_mappings — field definitions
signing_parties (JSON array — MUST be cast to array in model)
  Values: 'owner_party', 'acquiring_party', 'agent', 'witness'
party_mode, sections, header_display, editor_state
is_esign, is_global, allowed_delivery_modes, security_tier
```

**`docuperfect_named_fields`** — Reusable field definitions
```
name, field_type, source_type, source_column, source_contact_type
```

**`docuperfect_field_groups`** — Groups of named fields
```
name, fields (JSON), layout, is_global
agency_id → agencies, created_by → users
```

**`flows`** — Active e-sign wizard sessions
```
template_id → docuperfect_templates
user_id → users, property_id → properties, contact_id → contacts
step_data (JSON) — stores ALL wizard state across steps
current_step, status
```

**`signature_templates`** — Signing sessions
```
document_id → docuperfect_documents
parties_json, signing_order_json
status, completed_at, signed_pdf_path
```

**`signature_requests`** — Per-party signing entries
```
signature_template_id → signature_templates
party_role, signing_order, signer_name, signer_email
status, token, contact_id → contacts
```

**`signature_markers`** — Signature/initial placement on pages
```
signature_template_id → signature_templates
page_number, x_position, y_position, width, height
type, assigned_party
```

### E-Sign Dependency Chain
```
E-SIGN FLOW:
1. User selects template → docuperfect_templates.signing_parties
   ├── buildAllowedEsignRoles() filters which contacts can be recipients
   └── Template model MUST cast signing_parties to array

2. User selects property → properties table
   ├── searchProperties() uses Property::searchAddress()
   ├── Property contacts loaded via contact_property pivot
   └── Lessor query: $p->contacts()->where(role IN ['lessor','landlord'])
       ⚠ BUG: orWherePivot without scoping can return wrong contact

3. User adds recipients → stored in flow.step_data['recipients']
   ├── Role from contact_types.esign_role
   ├── roleMatchesTemplate() validates against requiredSigningRoles
   ├── requiredSigningRoles resolves from template.signing_parties
   └── sortRecipientsBySigningOrder() enforces tenant before landlord

4. Fields resolved → resolveFieldValue() → resolveContactValue()
   ├── Finds contact by role match in recipients
   ├── Reads contact columns: first_name, last_name, id_number, etc.
   └── Field groups expand via buildFieldsFromMappings()

5. Document generated → prepareSigning()
   ├── propertyAddress from stepData['property']['address']
   ├── Must use buildDisplayAddress() value, not title
   └── signing_parties passed to expandMarkersToIndividualParties()

KEY FILES:
  Controller: app/Http/Controllers/Docuperfect/ESignWizardController.php (4000+ lines)
  Service: app/Services/Docuperfect/SignatureService.php
  Service: app/Services/WebTemplateDataService.php
  View: resources/views/docuperfect/esign/wizard.blade.php
  View: resources/views/docuperfect/signatures/sign.blade.php
  View: resources/views/docuperfect/signatures/external/sign.blade.php
```

---

## MODULE: DEALS V1 (Live — do not modify)

### Tables
```
deals (28 cols) — deal_no, property_value, total_commission (INC VAT)
  listing_split_percent + selling_split_percent = 100
  listing_external, listing_our_share_percent
  selling_external, selling_our_share_percent
  accepted_status: P(ending) / G(ranted) / R(egistered) / D(eclined)
  commission_status: 'Not Paid' / 'Paid' / 'Loss'

deal_user (17 cols) — pivot: side, agent_split_percent, agent_cut_percent
  paye_method, paye_value, deductions, paid_at, sliding_* fields

deal_settlements (14 cols) — overrides pivot values as "actual paid"
  Unique: deal_id + user_id + side

deal_money_lines (25 cols) — computed from deals + deal_user + deal_settlements
  Rebuilt by DealMoneyLineRebuilder::rebuildDealId()
  Consumed by worksheets, performance dashboards, targets
```

### V1 Commission Calculation Chain
```
total_commission (INC VAT)
  → commissionExVat() = total_commission / (1 + vatRate)
  → listingPool() = commExVat × listingSplitPct × ourSharePct
  → sellingPool() = commExVat × sellingSplitPct × ourSharePct
  → allocations() per side:
      Settlement overrides → pivot splits → equal split → company remainder
  → Per agent: allocated × cut% = gross, gross - PAYE - deductions = net
  → DealMoneyLineRebuilder::rebuildDealId() writes to deal_money_lines
  → RollupService::refreshPeriod() updates finance_computed_values
  → Consumed by: worksheets, BM dashboard, agent dashboard, targets
```

---

## MODULE: DEALS V2 (New — under development)

### Tables
```
deals_v2 (32 cols) — reference, deal_type, status, property_id → properties
  Same commission model as V1: listing/selling split, external, our_share
  commission_status, overall_rag

deal_v2_agents (17 cols) — same structure as deal_user
deal_v2_contacts (5 cols) — deal_id, contact_id, role
deal_v2_settlements (14 cols) — same as deal_settlements

deal_pipeline_templates (10 cols) — configurable step workflows
deal_pipeline_steps (27 cols) — step definitions with trigger chains
deal_step_instances (38 cols) — per-deal step tracking with RAG
deal_step_documents (7 cols) — files attached to steps
deal_activity_log (8 cols) — chronological event log
```

### V2 does NOT feed into V1 dashboards yet.

---

## MODULE: USERS / AUTH

### Tables
```
users (39 cols) — name, email, role, designation, branch_id, agency_id
  agent_cut_percent, paye_method, paye_value — snapshotted to deal_user on deal creation
  sliding_enabled, sliding_tier*_cut_percent
  supervised_by → users, sponsored_by_user_id → users

roles (12 cols) — is_owner flag bypasses all permission checks
role_permissions (7 cols) — permission_key, scope (own/branch/all)
nexus_permissions (10 cols) — permission definitions

branches (21 cols) — agency_id → agencies
```

### Data Scoping Pattern
```
Every module uses PermissionService::getDataScope($user, 'module'):
  'all'    → see everything
  'branch' → see records where agent's branch_id matches
  'own'    → see only records created by / assigned to the user

Applied via scopeVisibleTo() on models.
```

---

## MODULE: FINANCE / PERFORMANCE

### Key flow
```
Deal created/updated
  → DealMoneyLineRebuilder::rebuildDealId() — writes deal_money_lines
  → RollupService::refreshPeriod() — updates finance_computed_values
  → Consumed by:
      worksheets (monthly planning vs actual)
      admin/branch performance dashboards
      agent dashboards
      targets
```

### Tables
```
deal_money_lines — computed per-agent per-side commission breakdown
finance_computed_values — period-level aggregated metrics
finance_definitions — what metrics exist
worksheets — monthly planning sheets per agent
targets — sales/listing targets per agent
```

---

## MODULE: PRESENTATIONS

### Tables (13)
```
presentations → presentation_versions, presentation_sections
  presentation_fields, presentation_uploads, presentation_links
  presentation_snapshots, presentation_url_snapshots
  presentation_articles, presentation_sold_comps
  presentation_active_listings, presentation_listing_price_history
  presentation_document_library_items
```

---

## MODULE: PROSPECTING

### Tables (7)
```
prospecting_listings, prospecting_searches, prospecting_claims
prospecting_price_history
portal_listings, portal_captures, portal_listing_observations
```

---

## MODULE: DEPOSIT INTEREST CALCULATOR

### Tables (2)
```
deposit_trust_interest — monthly trust account data (89 seeded records)
deposit_interest_calculations — saved calculation history
```

---

## MODULE: SYNDICATION

### Tables
```
PP: properties.pp_* columns (syndication state lives ON the property)
P24: properties.p24_* columns + p24_syndication_logs
```

---

## MODULE: COMMAND CENTER (Dashboard, Calendar, Tasks, Automation)

### Tables (13)
```
calendar_events (33+ cols) — unified event storage (auto + manual)
  user_id, event_type, category, title, event_date, end_date, all_day
  priority, send_reminder, status, resolution, resolution_note, colour
  source_type + source_id (polymorphic), property_id, contact_id, branch_id
  reminder_offsets (JSON), reminders_sent (JSON), is_recurring, recurrence_rule
  metadata (JSON)

calendar_reminders_log — reminder delivery audit trail
calendar_user_preferences — per-user calendar view settings

command_tasks (22+ cols) — actionable task items with kanban status
  title, task_type, status (todo/in_progress/awaiting/done/dismissed)
  priority, send_reminder, resolution, resolution_note
  assigned_to → users, assigned_by → users, due_date
  property_id, contact_id, deal_id, calendar_event_id
  checklist (JSON), metadata (JSON)

automation_rules — trigger → action rule definitions (20 system defaults)
automation_log — rule execution audit trail

property_health_scores — computed health 0-100 per property
  score, grade (excellent/good/attention/critical), factors (JSON)

agent_scorecards — computed agent metrics (weekly/monthly)
  tasks_completed, tasks_overdue, properties_attended, documents_uploaded
  fica_complete, avg_response_hours, deals_progressed, overall_score

command_document_expectations — expected docs per property type
command_reminder_defaults — reminder config per event category

user_dashboard_settings — per-user reminder/calendar preferences
  idle_alerts_enabled, idle_threshold_days, idle_alert_day, idle_alert_time
  task_reminder_hours_before, event_reminder_hours_before
  doc_reminders_enabled, lease_expiry_reminders, fica_reminders, ffc_reminders
  notify_in_app, notify_email

agency_dashboard_settings — agency-wide settings (when mode = agency)
  Same fields as user_dashboard_settings
  agencies.dashboard_settings_mode = 'user' | 'agency'
```

### Command Center Dependency Chain
```
COMMAND CENTER:
├── Controllers: app/Http/Controllers/CommandCenter/
│   ├── DashboardController.php — main dashboard (replaces CoreX/DashboardController)
│   ├── CalendarController.php — full calendar page + CRUD + JSON API
│   ├── TaskController.php — kanban board + list view + CRUD
│   ├── SettingsController.php — automation rules, doc expectations, reminders
│   └── UserSettingsController.php — per-user reminder/calendar settings
│
├── Models: app/Models/CommandCenter/
│   ├── CalendarEvent, CalendarReminderLog, CalendarUserPreference
│   ├── CommandTask, AutomationRule, AutomationLog
│   ├── PropertyHealthScore, AgentScorecard
│   ├── DocumentExpectation, ReminderDefault
│   ├── UserDashboardSetting, AgencyDashboardSetting
│
├── Services: app/Services/CommandCenter/
│   ├── CalendarEventService — CRUD + month grid + date range queries
│   ├── TaskService — CRUD + kanban columns + status management
│   ├── PropertyHealthCalculator — 6-factor scoring
│   ├── AgentScorecardCalculator — weekly/monthly metrics
│   └── AutoEventService — auto-creates tasks on property create, flags idle
│
├── Notifications: app/Notifications/
│   ├── TaskDueReminderNotification — email + in-app before task due
│   └── EventDueReminderNotification — email + in-app before event due
│
├── Commands: app/Console/Commands/CommandCenter/
│   ├── ProcessReminders — every 15 min, sends notifications per user settings
│   ├── CalculatePropertyHealth — nightly at 02:00
│   ├── CalculateAgentScorecards — nightly at 02:30
│   ├── FlagIdleProperties — daily at 07:00
│   └── BackfillCalendarEvents — one-time backfill
│
├── Observer hooks: app/Observers/PropertyObserver.php
│   ├── created() — auto-generates document expectation tasks
│   └── saved() — updates last_activity_at for health tracking
│
├── Views: resources/views/command-center/
│   ├── dashboard.blade.php — full dashboard with 8 widgets + overdue popup
│   ├── calendar/index.blade.php — month grid + agenda view
│   ├── tasks/index.blade.php — kanban board + list view
│   ├── settings/index.blade.php — admin automation settings
│   └── user-settings.blade.php — per-user reminder preferences
│
├── Routes (22): routes/web.php under /corex/command-center/*
│   command-center.calendar, .calendar.store, .calendar.complete, .calendar.dismiss
│   command-center.tasks, .tasks.store, .tasks.complete, .tasks.update-status
│   command-center.resolve-task, .resolve-event
│   command-center.settings, .settings.toggle-rule, .settings.store-expectation
│   command-center.user-settings, .user-settings.update
│
├── Permissions (17): config/corex-permissions.php section 'command-center'
│   command_center.view, .calendar.*, .tasks.*, .health.view
│   command_center.scorecards.own/branch/all, .automation.*, .settings
│
├── Sidebar: Dashboard (expandable group)
│   ├── Overview — /corex (main dashboard)
│   ├── Calendar — /corex/command-center/calendar
│   ├── Tasks — /corex/command-center/tasks
│   └── User Settings — /corex/command-center/user-settings
│
├── Settings integration: /corex/settings?tab=feature&fsec=dashboard
│   ├── Mode selector: Individual vs Agency settings
│   └── Agency-wide settings form (when mode = agency)
│
└── Scheduler: routes/console.php
    ├── command-center:reminders — everyFifteenMinutes
    ├── command-center:health — dailyAt 02:00
    ├── command-center:scorecards — dailyAt 02:30
    └── command-center:flag-idle — dailyAt 07:00
```

### Modified tables
```
properties — added: last_activity_at (datetime, nullable)
agencies — added: dashboard_settings_mode (string, default 'user')
```

---

## KNOWN DATA INTEGRITY ISSUES

1. **Property address fragmentation** — address data split across `title`, `address`, `street_name`, `street_number`. Always use `buildDisplayAddress()`.
2. **contact_property.role is free text** — no enum, no validation. Values vary: 'lessor', 'landlord', 'owner', 'tenant'. Always check multiple role values.
3. **signing_parties on templates** — some stored as JSON string, not array. Model MUST cast to array. `buildAllowedEsignRoles()` MUST handle strings defensively.
4. **V1 deals use free-text party names** — `seller_name`, `buyer_name` on the deal record. V2 links to contact records via `deal_v2_contacts`.

---

## ENVIRONMENT

| Environment | DB | Server | Codebase |
|-------------|-----|--------|----------|
| Local (Johan) | MySQL 8.4.3 via Laragon, DB: nexus_os | 127.0.0.1:8000 | HFC2402 branch |
| Staging | MySQL, DB: hfc_staging | 91.99.130.85 /corex-staging | Staging branch |
| Production | MySQL, DB: nexus_os | 91.99.130.85 /corex | main branch |

**Python AI Service:** `/opt/hf-ai/app.py` on port 3100. Not git-tracked. Manual restart.