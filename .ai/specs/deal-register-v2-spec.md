# Deal Register V2 — CoreX OS Full Spec
> Single source of truth for Deal Register V2 development
> Save to: `.ai/specs/deal_register_v2_spec.md`
> Version: 1.1 — 2026-03-30 (added status triggers + BM approval gate)

---

## 1. Core Principles

### V2 Alongside V1
V1 deal register stays untouched — live and working. V2 is a parallel build. When ready, migration tool moves existing deals across. V1 gets archived, not deleted.

### Four Pillars
Every deal links to existing CoreX records:
- **Property** — from the properties module (the listing)
- **Contacts** — buyer(s) and seller(s) from the contacts module
- **Documents** — OTP, addendums, compliance docs from DocuPerfect
- **Compliance** — FICA status pulled from contact records

No freeform property addresses. No freeform client names. If they're not in CoreX, the user adds them first. The pillars are the foundation.

### User Flexibility Above All
Zero hardcoded step names, zero hardcoded timelines, zero hardcoded thresholds. Every agency operates differently. CoreX adapts to the agency — the agency never adapts to CoreX. The system ships with a sensible default template that matches the standard SA conveyancing process, but every element is user-configurable.

### Data Scoping
- **Agent** sees own deals only
- **BM** sees branch deals
- **Admin** sees all deals across the company
Standard CoreX `scopeVisibleTo()` pattern via `PermissionService::getDataScope()`.

---

## 2. Deal Types

Three deal types, each with different pipeline behaviour:

### Bond Sale (most common)
Standard flow. Bond approval is the critical suspensive condition. Pipeline includes bond application, bond approval, and all downstream steps.

### Cash Sale
No bond track. Simpler pipeline — deposit, FICA, compliance certificates, rates clearance, transfer. Faster timeline (typically 4–6 weeks).

### Sale of Second Property
Most complex. Two deals are linked — the buyer's purchase depends on the sale of their existing property. The suspensive condition on Deal A references Deal B's progress. Both pipelines are visible and linked.

The deal type determines which **default steps** are pre-loaded from the pipeline template, but the user can always add, remove (non-locked), or reorder steps.

---

## 3. Pipeline Setup (User Configuration)

### Pipeline Templates
Admin/BM creates pipeline templates in a setup screen. Each template defines the steps a deal goes through from creation to registration.

A template has:
- **Name** (e.g. "Standard Bond Sale", "Cash Sale KZN", "Sale of 2nd Property")
- **Deal type** (bond/cash/sale_of_2nd)
- **Branch** (agency-wide or branch-specific)
- **Steps** (ordered list — see below)
- **Is default** — auto-applied when creating a deal of this type

### Pipeline Steps (fully user-defined)

Each step in a template has:

| Field | Description |
|-------|-------------|
| **name** | User-defined label (e.g. "Bond Approval", "Electrical COC", "Agent Addendum Signed") |
| **description** | Optional help text explaining what this step requires |
| **position** | Sort order in the pipeline |
| **is_locked** | If true, cannot be removed from a deal (regulatory requirement) |
| **completion_type** | What's needed to complete this step (see below) |
| **trigger_type** | When does this step activate? (see below) |
| **trigger_step_id** | If trigger is "after_step", which step triggers this one |
| **days_offset** | Days allowed after trigger to complete this step |
| **rag_green_days** | Days remaining where status is GREEN |
| **rag_amber_days** | Days remaining where status turns AMBER |
| **rag_red_days** | Days remaining where status turns RED |
| **notify_agent** | Boolean — notify assigned agent |
| **notify_bm** | Boolean — notify branch manager |
| **notify_admin** | Boolean — notify admin |
| **is_milestone** | Boolean — major stage change (shows prominently on tracker) |
| **required_before** | Array of step IDs that must be complete before this step can be marked done |

### Completion Types

Each step specifies HOW it gets completed:

| Type | Behaviour |
|------|-----------|
| **manual_tick** | User clicks "Mark Complete" — simple checkbox |
| **date_input** | User enters a date (e.g. "Bond granted on [date]") |
| **amount_input** | User enters an amount (e.g. "Deposit received: R___") |
| **document_upload** | User uploads a document (e.g. electrical COC certificate) |
| **document_signed** | Auto-completes when a linked DocuPerfect document is fully signed |
| **text_input** | User enters text (e.g. "Conveyancer reference number") |
| **multi_field** | Multiple inputs needed (e.g. date + amount + reference) |
| **auto_from_linked_deal** | Auto-completes when a linked deal reaches a specified step (sale of 2nd property) |

### Trigger Types (linked step system)

Steps don't all start from the deal creation date — they chain off each other:

| Trigger | Behaviour |
|---------|-----------|
| **on_creation** | Step activates when the deal is created. Due date = creation date + days_offset |
| **after_step** | Step activates when another step is completed. Due date = completion date of trigger step + days_offset |
| **manual** | Step has no auto-trigger. User activates manually. Due date set manually. |
| **on_date** | Step activates on a specific date entered by the user at deal creation |

**Example chain:**
```
OTP Signed (on_creation, 0 days)
  └→ Bond Application (after: OTP Signed, +3 days)
       └→ Bond Approved (after: Bond Application, +30 days)
            ├→ Compliance Certificates (after: Bond Approved, +30 days)
            ├→ Deposit Payment (after: Bond Approved, +7 days)
            └→ Attorney Instructed (after: Bond Approved, +5 days)
                 ├→ FICA Completed (after: Attorney Instructed, +14 days)
                 └→ Rates Clearance (after: Attorney Instructed, +42 days)
                      └→ Deeds Lodgement (after: Rates Clearance, +7 days)
                           └→ Registration (after: Deeds Lodgement, +15 days)
```

When Bond Approved is marked complete on 15 April, the system automatically:
- Activates COC steps with due date 15 May (15 Apr + 30 days)
- Activates Deposit Payment with due date 22 April (15 Apr + 7 days)
- Activates Attorney Instructed with due date 20 April (15 Apr + 5 days)
- Creates calendar events for all activated steps
- Sends notifications to assigned agents
- Recalculates all downstream due dates in the chain

If a step is completed early or late, all downstream dates recalculate automatically from the actual completion date.

### Status Triggers (pipeline drives deal status)

Certain pipeline steps are **status gates** — completing them changes the deal's overall status. This is configurable per step in the template.

Each step can have:
- **status_trigger** — on positive completion, change deal to this status (granted/completed)
- **negative_status_trigger** — on negative outcome, change deal to this status (cancelled)
- **negative_outcome_label** — custom button label for the negative path (e.g. "Bond Declined")
- **requires_bm_approval** — if true, status change is held pending until BM approves

**Positive/negative completion flow:**
Steps with a negative_status_trigger show TWO buttons at completion:
- Green: "[Step Name]" → uses status_trigger
- Red: "[Negative Outcome Label]" → uses negative_status_trigger, requires reason text

**BM approval gate:**
When requires_bm_approval is true:
1. Agent completes the step
2. Deal status does NOT change yet — approval_status set to 'pending'
3. BM notified, reviews, and approves or rejects
4. On approve: deal status changes, downstream steps activate
5. On reject: step reverts to active, agent notified with reason, must re-complete

**Default status triggers on key steps:**
- OTP Signed: negative → cancelled ("OTP Rejected"), no BM approval
- Bond Approved: positive → granted, negative → cancelled ("Bond Declined"), BM approval required
- Deposit Paid (cash): positive → granted, BM approval required
- Registration: positive → completed, no BM approval

### RAG Status (user-configurable per step)

Each step has three thresholds set by the user during pipeline setup:

- **GREEN**: More than X days remaining (comfortable, on track)
- **AMBER**: Between Y and X days remaining (getting close, needs attention)
- **RED**: Less than Y days remaining (critical, act now)
- **OVERDUE**: Past due date (bright red, escalation triggers)

Example for "Bond Approval" step (longer horizon):
- Green: 15+ days remaining
- Amber: 7–14 days remaining
- Red: 1–6 days remaining

Example for "Electrical COC" step (shorter timeline):
- Green: 10+ days remaining
- Amber: 5–9 days remaining
- Red: 1–4 days remaining

The user sets these per step in the template. Different steps have different urgency profiles.

### Default Template (ships with CoreX)

Standard SA bond sale — agencies customise from here:

```
 1. OTP Signed                    [on_creation, 0d]      [milestone] [locked]
 2. Bond Application Submitted    [after: #1, +3d]
 3. Bond Approved                 [after: #2, +30d]      [milestone] [locked]
 4. Deposit Paid                  [after: #3, +7d]       [locked]
 5. Attorney Instructed           [after: #3, +5d]
 6. FICA Completed (Buyer)        [after: #5, +14d]      [locked]
 7. FICA Completed (Seller)       [after: #5, +14d]      [locked]
 8. Electrical COC                [after: #3, +30d]      [locked]
 9. Gas COC                       [after: #3, +30d]
10. Electric Fence COC            [after: #3, +30d]
11. Beetle Certificate            [after: #3, +30d]
12. Water Installation COC        [after: #3, +30d]
13. Rates Clearance               [after: #5, +42d]      [locked]
14. Deeds Office Lodgement        [after: #13, +7d]      [milestone] [locked]
15. Registration                  [after: #14, +15d]     [milestone] [locked]
```

Agencies can:
- Add steps (e.g. "Agent Addendum Signed", "Home Inspection Report")
- Remove non-locked steps (e.g. Gas COC if not applicable in their area)
- Rename ANY step (locked or not — the label is always editable)
- Change days offset on any step
- Change RAG thresholds on any step
- Reorder non-locked steps
- Change trigger links
- Duplicate a template and modify for different branches

They CANNOT:
- Remove locked steps (FICA, COCs, Rates Clearance, Lodgement, Registration)
- Skip locked steps during deal progression

---

## 4. Deal Creation Flow

### Step 1: Select Property
Search/select from CoreX properties module. Property details auto-populate (address, price, listing agent, mandate status).

### Step 2: Select Contacts
- **Seller(s)** — search/select from contacts. Multiple allowed (co-owners). FICA status badge shown inline (green/amber/red).
- **Buyer(s)** — search/select. Multiple allowed (co-purchasers). FICA status badge shown.
- **Listing Agent** — auto-populated from property record, editable
- **Selling Agent** — search/select from users

### Step 3: Deal Details
- **Deal type**: Bond / Cash / Sale of 2nd Property
- **Purchase price**: R amount
- **Commission**: % or R amount (ex VAT, system calculates inc VAT)
- **Offer date**: date picker
- **Pipeline template**: auto-selected based on deal type, can override
- **Linked deal** (sale of 2nd only): search/select existing deal

### Step 4: Review Pipeline
Shows the pipeline steps that will be created from the template. User can:
- Adjust days offset for any step before creation
- Add additional steps
- Remove non-locked steps not needed for this deal
- Set specific known dates (overrides auto-calc)
- Add notes to any step

### Step 5: Confirm & Create
Creates the deal record, creates all step instances, activates steps triggered by "on_creation", creates calendar events, sends notifications to assigned agents and BM.

---

## 5. Deal Tracking Screens

### 5.1 Deal Detail View (single deal)

**Header bar (sticky):**
Back | Deal #[ref] — [property address] | Status badge | Overall RAG | [Actions dropdown]

**Progress Tracker (visual pipeline):**
Visual step-by-step pipeline showing the full chain:
- Each step as a node/card
- Status per step: `not_started` | `active` | `completed` | `overdue` | `skipped`
- RAG colour indicator on active steps (green/amber/red dot or border)
- Completion date stamp on finished steps
- Due date + days remaining on active steps
- Dependency lines showing the chain (which step triggers which)
- Milestone steps visually larger/emphasised
- Completed steps show a checkmark, overdue show an alert icon

**Step Detail (expand any step):**
- Step name + description
- Status + RAG colour
- Triggered by: [parent step name] + date it was triggered
- Due date (auto-calculated, with option to override)
- Days remaining (positive) or days overdue (negative, red)
- Completion form (whatever the completion_type requires — tick, date input, file upload, etc.)
- Documents attached to this step
- Notes / activity log for this step
- "Mark Complete" button (or the required input form)

**Deal Summary Panel:**
- Property: address, listing price, mandate status
- Seller(s): names, FICA badges
- Buyer(s): names, FICA badges
- Agents: listing + selling, commission split
- Commission: amount ex VAT, inc VAT, split breakdown
- Key dates: offer date, expected registration (auto-calculated from pipeline)
- Documents: linked DocuPerfect documents with signing status
- Notes: deal-level notes

**Activity Log (timeline):**
Chronological feed of everything that happened on this deal:
```
28 Mar 2026 — Deal created by Johan Reichel
28 Mar 2026 — OTP Signed marked complete
28 Mar 2026 — System: Bond Application activated, due 31 Mar 2026
31 Mar 2026 — Bond Application submitted by Falan
31 Mar 2026 — System: Bond Approval activated, due 30 Apr 2026
15 Apr 2026 — Bond Approved marked complete by Johan
15 Apr 2026 — System: 5 steps activated (COC, Deposit, Attorney, ...)
15 Apr 2026 — System: Electrical COC due 15 May 2026
22 Apr 2026 — System: AMBER alert — Deposit Payment due in 5 days
25 Apr 2026 — System: RED alert — Deposit Payment due in 2 days
28 Apr 2026 — System: OVERDUE — Deposit Payment was due 27 Apr
```

### 5.2 Pipeline Overview (multiple deals — BM/Admin view)

**Scope Switcher (top of page):**
- Agent: [My Deals] (only option)
- BM: [My Deals] | [Branch: Shelly Beach]
- Admin: [My Deals] | [Branch: dropdown] | [All Company]

**Dashboard Cards (top section):**
| Card | Description |
|------|-------------|
| Active Deals | Total count of non-archived deals |
| Overdue Steps | Count of steps past due date (RED badge) |
| Due This Week | Count of steps due in next 7 days |
| Pending Registration | Deals past lodgement, awaiting registration |
| Total Pipeline Value | Sum of purchase prices of active deals |
| Avg Days to Registration | Average across completed deals |

**Board View (kanban — milestone columns):**
Columns = milestone steps only (OTP Signed → Bond Approved → Lodgement → Registered)
Cards = individual deals showing:
- Property address (truncated)
- Purchase price
- Assigned agent(s)
- RAG dot (worst RAG across all active steps)
- Days in current stage
- Next due step + date

Cards are not draggable — status changes through proper step completion only. This prevents agents from gaming the pipeline.

**Table View:**
Sortable columns:

| Column | Content |
|--------|---------|
| Deal # | Reference number, clickable to detail |
| Property | Address |
| Buyer | Name(s) |
| Seller | Name(s) |
| Agent | Listing + selling |
| Current Stage | Which milestone the deal is at |
| RAG | Worst RAG across active steps (coloured dot) |
| Next Due | Nearest upcoming step + date |
| Days Left | To next due step (negative = overdue) |
| Value | Purchase price |
| Created | Deal creation date |

- Filter by: agent, stage, RAG status, deal type, branch, date range, overdue only
- Search by: property address, contact name, deal reference
- Sort by any column
- Pagination (25 per page default)
- "Showing X of Y deals" count
- Empty state when no deals match filters
- Export to CSV

---

## 6. Calendar Integration

### How Deal Events Reach the Calendar

Every active deal step creates a `calendar_event` record:
- **Title**: "[Step Name] — [Property Address]"
- **Date**: Due date of the step
- **Description**: Deal #[ref], parties, what's needed to complete
- **Colour**: Matches RAG status (updates dynamically as RAG changes)
- **Source link**: Polymorphic link back to `DealStepInstance`
- **Assigned to**: The deal's agent(s)

When a step is completed, the calendar event is marked as completed.
When a step's RAG changes, the calendar event colour updates.
When a step's due date recalculates, the calendar event date updates.

### CoreX In-App Calendar

Every user has a CoreX calendar inside the app.

**Scope switcher (consistent across the app):**
- **Agent**: "My Calendar" — only their deals and events
- **BM**: "My Calendar" | "Branch Calendar" (all agents in branch, colour-coded per agent)
- **Admin**: "My Calendar" | "[Branch Name]" (dropdown per branch) | "All Company"

**Views available:**
- Month (default) — grid with coloured dots per event, click to expand
- Week — 7-day horizontal with time blocks
- Day — detailed single-day view
- Agenda — vertical list grouped by day, best for "what's coming up"

**Filters:**
- Event category (deals, leases, compliance, manual)
- RAG status (green/amber/red/overdue)
- Agent (BM/admin only)
- Property
- Deal type

### iCal Sync to Phone

Each user gets a unique iCal subscription URL:
`https://corexos.co.za/calendar/ical/{user_token}.ics`

This URL serves a dynamically generated ICS file with all their calendar events. Google Calendar, Outlook, Apple Calendar subscribe to it and auto-refresh every 15–60 minutes.

The agent's phone handles native pop-up notifications. CoreX doesn't need a mobile app for reminders — the phone's built-in calendar does it.

**BM iCal feeds:**
A BM can optionally subscribe to a second feed for their branch:
`https://corexos.co.za/calendar/ical/{user_token}.ics?scope=branch`

This gives them two calendars on their phone — personal and branch.

**Admin iCal feeds:**
Same pattern, with `?scope=branch&branch_id=X` or `?scope=company`.

---

## 7. Notification & Escalation System

### When Notifications Fire

| Event | Who | Channel |
|-------|-----|---------|
| Step activated (new due date) | Agent | Calendar + in-app |
| RAG turns AMBER | Agent | Calendar colour update + in-app |
| RAG turns RED | Agent + BM | Calendar update + in-app + email |
| Step OVERDUE | Agent + BM + Admin | In-app + email + escalation |
| Step completed | BM (if milestone) | In-app |
| Deal created | BM | In-app |
| Deal registered (complete) | Agent + BM + Admin | In-app + email |
| Linked deal progress (sale of 2nd) | Agent on both deals | In-app |

### Escalation Chain (user-configurable per step)

Default escalation when a step goes overdue:
1. **Due date**: Agent notified — "Step overdue"
2. **+1 day**: BM notified — "[Agent] has overdue: [step] on [deal]"
3. **+3 days**: Admin notified — "Escalation: [deal] — [step] overdue 3 days"
4. **Every 3 days after**: Repeat to all three until resolved

Agencies can configure the escalation timing per step in the pipeline template. Critical steps (bond approval) might escalate faster than less critical ones.

### Notification Channels

| Channel | How it works |
|---------|-------------|
| **In-app** | Bell icon in CoreX header, real-time count badge, click to see list |
| **Email** | Branded agency email using the email template system |
| **Calendar** | Event created/updated in CoreX calendar, syncs to phone via iCal |
| **SMS** (future) | For critical overdue items — phase 2 |

### Notification Preferences (3 levels)

1. **Agency defaults** — set by admin in Settings, apply to all users unless overridden
2. **Per-category defaults** — different settings per event category (deal steps vs compliance vs manual)
3. **Per-user override** — each user can adjust their own notification preferences

---

## 8. Database Design

### `deal_pipeline_templates` table
```
id                      bigint PK
name                    string 255
deal_type               enum: bond, cash, sale_of_2nd
branch_id               foreignId nullable (null = agency-wide)
is_default              boolean default false
is_active               boolean default true
created_by_id           foreignId
created_at, updated_at, deleted_at
```

### `deal_pipeline_steps` table (template steps)
```
id                      bigint PK
pipeline_template_id    foreignId
name                    string 255
description             text nullable
position                integer
is_locked               boolean default false
is_milestone            boolean default false
completion_type         enum: manual_tick, date_input, amount_input,
                              document_upload, document_signed, text_input,
                              multi_field, auto_from_linked_deal
completion_config       json nullable (field definitions for multi_field type)
trigger_type            enum: on_creation, after_step, manual, on_date
trigger_step_id         bigint nullable (references deal_pipeline_steps.id)
days_offset             integer default 0
rag_green_days          integer default 14
rag_amber_days          integer default 7
rag_red_days            integer default 3
notify_agent            boolean default true
notify_bm              boolean default true
notify_admin            boolean default false
escalation_config       json nullable (override default escalation timing)
required_before         json nullable (array of step IDs)
created_at, updated_at, deleted_at
```

### `deals_v2` table
```
id                      bigint PK
reference               string unique (auto-generated: DL-YYYY-NNNNN)
deal_type               enum: bond, cash, sale_of_2nd
status                  enum: active, completed, cancelled, on_hold
property_id             foreignId
listing_agent_id        foreignId (user)
selling_agent_id        foreignId nullable (user)
pipeline_template_id    foreignId
linked_deal_id          bigint nullable (for sale of 2nd property)
purchase_price          decimal(14,2)
commission_percentage   decimal(5,2) nullable
commission_amount       decimal(12,2)
commission_vat          decimal(12,2)
offer_date              date
expected_registration   date nullable (auto-calc from pipeline)
actual_registration     date nullable
overall_rag             enum: green, amber, red, overdue (computed, cached)
notes                   text nullable
branch_id               foreignId
created_by_id           foreignId
created_at, updated_at, deleted_at
```

### `deal_v2_contacts` pivot table
```
id                      bigint PK
deal_id                 foreignId (deals_v2)
contact_id              foreignId
role                    enum: buyer, seller, co_buyer, co_seller,
                              conveyancer, bond_originator, other
created_at
```

### `deal_v2_agents` pivot table
```
id                      bigint PK
deal_id                 foreignId (deals_v2)
user_id                 foreignId
role                    enum: listing_agent, selling_agent, referral_agent
commission_split        decimal(5,2) nullable (percentage of total commission)
created_at
```

### `deal_step_instances` table (per-deal step tracking)
```
id                      bigint PK
deal_id                 foreignId (deals_v2)
pipeline_step_id        foreignId (deal_pipeline_steps — template reference)
name                    string 255 (copied from template, can be edited per-deal)
description             text nullable
position                integer
is_locked               boolean
is_milestone            boolean
completion_type         enum (same as template)
completion_config       json nullable
status                  enum: not_started, active, completed, overdue, skipped
trigger_type            enum (same as template)
trigger_step_instance_id bigint nullable (references deal_step_instances.id)
days_offset             integer
due_date                date nullable (auto-calculated or manually set)
activated_at            datetime nullable (when this step became active)
completed_at            datetime nullable
completed_by_id         foreignId nullable
completion_data         json nullable (stores the input: date, amount, text, file path, etc.)
rag_green_days          integer
rag_amber_days          integer
rag_red_days            integer
current_rag             enum: grey, green, amber, red, overdue (computed, cached)
notify_agent            boolean
notify_bm              boolean
notify_admin            boolean
notes                   text nullable
created_at, updated_at, deleted_at
```

### `deal_step_documents` table
```
id                      bigint PK
deal_step_instance_id   foreignId
document_id             foreignId nullable (DocuPerfect document)
file_path               string nullable (uploaded file)
file_name               string nullable
uploaded_by_id          foreignId nullable
created_at
```

### `deal_activity_log` table
```
id                      bigint PK
deal_id                 foreignId (deals_v2)
deal_step_instance_id   foreignId nullable
user_id                 foreignId nullable (null = system)
action                  string (step_activated, step_completed, rag_changed,
                                deal_created, note_added, date_overridden, etc.)
description             text
metadata                json nullable (old/new values, etc.)
created_at
```

### `calendar_events` table (shared with Calendar Module)
```
id                      bigint PK
user_id                 foreignId (assigned to)
created_by_id           foreignId (system or user)
event_type              enum: deal, lease, compliance, document, prospecting, portal, manual
category                string (bond_deadline, coc_due, deposit_due, etc.)
title                   string 255
description             text nullable
event_date              datetime
end_date                datetime nullable
all_day                 boolean default true
priority                enum: low, normal, high, critical
status                  enum: pending, completed, overdue, dismissed
colour                  string nullable (hex — auto-set from RAG if deal event)
source_type             string nullable (polymorphic: DealStepInstance, Lease, etc.)
source_id               bigint nullable
property_id             foreignId nullable
contact_id              foreignId nullable
branch_id               foreignId nullable
reminder_offsets        json nullable
reminders_sent          json nullable
is_recurring            boolean default false
recurrence_rule         string nullable
parent_event_id         bigint nullable
metadata                json nullable
created_at, updated_at, deleted_at
```

### `calendar_reminders_log` table
```
id                      bigint PK
calendar_event_id       foreignId
user_id                 foreignId
channel                 enum: app, email, sms
offset_minutes          integer
sent_at                 datetime
read_at                 datetime nullable
actioned_at             datetime nullable
escalated               boolean default false
created_at
```

### `calendar_user_preferences` table
```
id                      bigint PK
user_id                 foreignId unique
default_view            enum: month, week, day, agenda default month
default_scope           enum: own, branch, company default own
working_hours_start     time default 08:00
working_hours_end       time default 17:00
weekend_visible         boolean default false
ical_token              string unique nullable
email_reminders         boolean default true
app_reminders           boolean default true
digest_email            enum: none, daily, weekly default daily
created_at, updated_at
```

---

## 9. Services

### DealPipelineService

The brain. Handles all pipeline logic:

```php
// Deal lifecycle
createDeal(array $data): DealV2
cancelDeal(DealV2 $deal, string $reason): void
holdDeal(DealV2 $deal): void
resumeDeal(DealV2 $deal): void

// Step management
activateStep(DealStepInstance $step): void
completeStep(DealStepInstance $step, array $completionData): void
skipStep(DealStepInstance $step, string $reason): void
overrideDueDate(DealStepInstance $step, Carbon $newDate, string $reason): void

// Chain reaction (called by completeStep)
activateDownstreamSteps(DealStepInstance $completedStep): void
recalculateDueDates(DealV2 $deal): void

// RAG calculation (called by scheduled command + on step changes)
calculateRag(DealStepInstance $step): string
updateDealOverallRag(DealV2 $deal): void

// Query helpers
getActiveDealsForUser(User $user): Collection
getOverdueSteps(?int $branchId = null): Collection
getDealTimeline(DealV2 $deal): Collection
```

### CalendarEventService

Creates/updates/deletes calendar events from deal steps:

```php
createFromDealStep(DealStepInstance $step): CalendarEvent
updateFromDealStep(DealStepInstance $step): void  // RAG change, date change
completeFromDealStep(DealStepInstance $step): void
deleteForDeal(DealV2 $deal): void
syncDealEvents(DealV2 $deal): void  // Full re-sync

// iCal generation
generateIcalFeed(User $user, ?string $scope, ?int $branchId): string

// Scope queries
getEventsForUser(User $user, Carbon $from, Carbon $to): Collection
getEventsForBranch(int $branchId, Carbon $from, Carbon $to): Collection
getEventsForCompany(Carbon $from, Carbon $to): Collection
```

### NotificationService

Handles all notification channels:

```php
notifyStepActivated(DealStepInstance $step): void
notifyRagChanged(DealStepInstance $step, string $oldRag, string $newRag): void
notifyStepOverdue(DealStepInstance $step): void
notifyStepCompleted(DealStepInstance $step): void
notifyDealCompleted(DealV2 $deal): void
escalate(DealStepInstance $step): void

// Channels
sendInApp(User $user, string $title, string $body, ?string $link): void
sendEmail(User $user, string $template, array $data): void
sendCalendarUpdate(CalendarEvent $event): void
```

---

## 10. Scheduled Commands

### `deals:process-rag` (every 15 minutes)
Scans all active deal steps, recalculates RAG status based on current date vs due date. If RAG changed, updates the step, updates the calendar event colour, fires notifications.

### `deals:process-escalations` (every hour)
Scans overdue steps. If escalation timing thresholds met and notification not already sent for this level, sends escalation to next person in chain.

### `calendar:generate-ical` (every 15 minutes)
Regenerates iCal feeds for users with active subscriptions. Cached with a short TTL so phone calendars get near-real-time updates.

### `deals:daily-digest` (daily at 07:00)
Sends a morning digest email to each user with:
- Steps due today
- Overdue steps
- Steps turning amber/red today
- Deals completed yesterday

---

## 11. Navigation & Permissions

### Sidebar
```
Deals (top-level, not buried in submenu)
  ├── Deal Register        (pipeline overview — table + board views)
  ├── New Deal             (creation flow)
  ├── Pipeline Setup       (template management — admin/BM only)
  └── Calendar             (full calendar with scope switcher)
```

### Permissions
```
deals_v2.view            — scoped: own / branch / all
deals_v2.create
deals_v2.edit
deals_v2.archive
deals_v2.manage_pipeline — create/edit pipeline templates
deals_v2.override_dates  — override auto-calculated due dates
access_calendar
calendar.manage          — edit other users' events
```

---

## 12. Build Phases

### Phase 1: Database + Pipeline Setup
- All migrations (templates, steps, deals, instances, contacts, agents, activity log)
- Models with relationships
- Pipeline template CRUD (setup screen)
- Default template seeder
- Pipeline step builder UI (drag to reorder, inline edit, trigger linking)

### Phase 2: Deal Creation + Tracking
- Deal creation wizard (5 steps)
- Deal detail view with visual pipeline tracker
- Step completion flows (all completion types)
- Chain reaction: completing a step activates downstream steps
- Activity log
- RAG calculation engine

### Phase 3: Pipeline Overview + Views
- Board view (kanban by milestone)
- Table view (sortable, filterable, searchable)
- Dashboard cards (counts, pipeline value)
- Scope switcher (own/branch/company)
- CSV export

### Phase 4: Calendar Integration
- `calendar_events` table + model
- CalendarEventService (create/update/delete from deal steps)
- CoreX in-app calendar (month/week/day/agenda views)
- Scope switcher on calendar (own/branch/company)
- Calendar filters (category, RAG, agent, property)

### Phase 5: Notifications + Reminders
- `calendar:process-rag` scheduled command
- In-app notification system (bell icon + count)
- Email notifications (branded templates)
- Escalation chain
- `deals:daily-digest` morning email
- Notification preferences (agency → category → user)

### Phase 6: iCal + Phone Sync
- iCal subscription endpoint per user
- Scope-based feeds (own, branch, company)
- Token-based authentication
- ICS file generation
- `calendar:generate-ical` scheduled command

### Phase 7: Polish + Linked Deals
- Sale of 2nd property linking
- Auto-complete from linked deal progress
- V1 → V2 migration tool
- Dashboard widget (today's events + overdue)
- Deal-level reporting (avg days per step, bottleneck analysis)

---

## 13. What This Replaces

| Current Process | CoreX V2 |
|----------------|-----------|
| Spreadsheet tracking | Visual pipeline with RAG status |
| BM calling agents for updates | Real-time dashboard + escalations |
| Agent forgetting bond deadline | Auto-reminder on phone calendar |
| Admin not knowing deal status | Company-wide pipeline view |
| Addendum not signed in time | RED alert days before deadline |
| Manual commission tracking | Auto-linked to deal + settlement |
| "Where is the COC?" emails | Step status visible to everyone |
| Deal falls through the cracks | Impossible — every step tracked |