# CoreX Command Center — Design Spec
> The operational heartbeat of CoreX OS
> Version: 1.0 — 2026-03-31
> Status: DRAFT — requires approval before build

---

## What This Is

The Command Center is the **dashboard home screen** of CoreX OS — replacing the current minimal dashboard with a full operations hub. Think Monday.com's work management + a real estate-aware calendar + automated compliance tracking + property health monitoring + agent accountability — all wired into the four pillars.

This is not a standalone module. It is the **lens through which every agent, BM, and admin sees the state of their business** the moment they log in.

### Why This Matters
Right now, agents log in and see activity points. That's it. They don't know:
- Which properties haven't had any activity in 2 weeks
- Which documents are overdue for upload
- Which deals are stuck at a pipeline step
- Which leases expire in the next 90 days
- Which FICA submissions are incomplete
- What they need to do today vs this week vs this month

The Command Center answers all of these questions automatically, without the agent lifting a finger.

---

## Architecture: Three Layers

```
┌───────────────────────────────────────────────────────────┐
│                 COMMAND CENTER DASHBOARD                    │
│                                                            │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────────┐ │
│  │ Today's  │ │ Overdue  │ │ Property │ │ Agent        │ │
│  │ Agenda   │ │ Items    │ │ Health   │ │ Scorecard    │ │
│  └──────────┘ └──────────┘ └──────────┘ └──────────────┘ │
│  ┌──────────────────────────────────────────────────────┐ │
│  │              FULL CALENDAR VIEW                       │ │
│  │   Month / Week / Day / Agenda / Timeline              │ │
│  └──────────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────────┐ │
│  │              TASK BOARD (Kanban)                       │ │
│  │   To Do → In Progress → Awaiting → Done               │ │
│  └──────────────────────────────────────────────────────┘ │
├───────────────────────────────────────────────────────────┤
│              AUTOMATION ENGINE                             │
│                                                            │
│  Rule: "When {trigger} → create {event/task} with         │
│         {reminders} assigned to {agent/role}"              │
│                                                            │
│  Built-in rules (auto-active):                            │
│    Property uploaded → expect mandate document             │
│    Deal created → expect FICA for all parties              │
│    Lease signed → set renewal reminder at -90d             │
│    FFC approaching expiry → alert agent + BM               │
│    Property idle > 14 days → flag for attention            │
│                                                            │
│  Custom rules (admin-configurable):                       │
│    Any trigger + any action + any assignment               │
├───────────────────────────────────────────────────────────┤
│              DATA LAYER                                    │
│                                                            │
│  calendar_events     — unified event storage               │
│  command_tasks       — actionable items with status        │
│  automation_rules    — trigger → action definitions        │
│  automation_log      — audit trail of rule executions      │
│  property_health     — computed health scores              │
│  agent_scorecards    — computed accountability metrics     │
│  notifications       — in-app notification queue           │
└───────────────────────────────────────────────────────────┘
```

---

## Pillar Connections

| Pillar | Reads From | Writes Back |
|--------|-----------|-------------|
| **Property** | Address, status, listing date, last activity, agent, documents, syndication status | Property health score, neglect flags, attention alerts |
| **Contact** | FICA status, linked properties, linked deals, document signing status | Compliance reminders, follow-up tasks |
| **Deal** | Pipeline step, dates (bond deadline, transfer, registration), commission status, parties | Deal health score, deadline events, overdue flags |
| **Agent** | FFC expiry, training status, assigned properties, assigned deals, daily activity | Agent scorecard, accountability metrics, workload distribution |

---

## Part 1: Calendar System

> Inherits from the existing `spec-calendar-module.md` — that spec covers the calendar in detail. This section adds what the calendar spec doesn't cover.

### Calendar Summary (from existing spec)
- `calendar_events` table with polymorphic source links
- Auto-generated events from Deals, Leases, Compliance, Documents, Prospecting
- Manual event creation
- Month/Week/Day/Agenda/Timeline views
- Reminder engine with escalation chains
- iCal subscription for phone sync
- In-app + email notifications

### Additions Beyond Calendar Spec

#### 1. Event Categories (expanded)
The calendar spec covers deal/lease/compliance/document/prospecting events. Add:

| Category | Trigger | Events Created |
|----------|---------|----------------|
| **Property Health** | Property idle > X days (configurable) | "Property Needs Attention: [address]" |
| **Document Expectations** | Property created with listing type | "Upload Mandate: [property]" due in 48h |
| **Showday Followup** | Showday registered on property | "Showday Follow-up: [property]" at +24h |
| **Contact Followup** | New contact created (buyer/tenant type) | "Follow-up: [contact name]" at +48h |
| **Agent Onboarding** | New agent added | "Complete Onboarding: [agent]" checklist events |
| **Trust Interest** | Monthly recurring | "Capture Trust Interest" on 1st of each month |
| **Branch Meeting** | Recurring per branch | "Branch Meeting: [branch]" weekly/monthly |

#### 2. Smart Scheduling
When creating manual events:
- **Conflict detection** — warn if the agent already has an event at that time
- **Travel time awareness** — if two viewings are in different suburbs, suggest buffer time
- **Working hours respect** — default events to business hours, flag after-hours

#### 3. Shared Calendars
- BMs see their branch's full calendar (all agents)
- Admins see all branches
- Agents see only their own (unless granted branch/all scope via permissions)
- Property calendars — view all events for a specific property across all agents

---

## Part 2: Task Board (Kanban)

This is the actionable counterpart to the calendar. Calendar shows **when**. Task board shows **what needs doing**.

### Task Structure
```
command_tasks table:
  id                  bigint PK
  title               string 255
  description         text nullable
  task_type           string (document_upload, follow_up, compliance, review, custom)
  status              enum: todo, in_progress, awaiting, done, dismissed
  priority            enum: low, normal, high, critical
  
  -- Assignment
  assigned_to         foreignId → users
  assigned_by         foreignId → users nullable (null = system-generated)
  
  -- Dates
  due_date            datetime nullable
  started_at          datetime nullable
  completed_at        datetime nullable
  
  -- Pillar links
  property_id         foreignId nullable
  contact_id          foreignId nullable
  deal_id             foreignId nullable (deals_v2)
  
  -- Source (what created this task)
  source_type         string nullable (automation_rule, manual, calendar_event)
  source_id           bigint nullable
  calendar_event_id   foreignId nullable (links task to calendar event)
  
  -- Tracking
  checklist           json nullable (sub-items with done/not-done)
  notes               text nullable
  
  -- Metadata
  metadata            json nullable
  branch_id           foreignId nullable
  agency_id           foreignId nullable
  created_at, updated_at, deleted_at
```

### Task Board Views

#### Kanban Board
```
┌─────────────┬──────────────┬──────────────┬─────────────┐
│   TO DO     │ IN PROGRESS  │  AWAITING    │    DONE     │
│             │              │              │             │
│ ┌─────────┐ │ ┌──────────┐ │ ┌──────────┐ │ ┌─────────┐│
│ │Upload   │ │ │FICA for  │ │ │Mandate   │ │ │Bond app ││
│ │mandate  │ │ │J. Smith  │ │ │signature │ │ │submitted││
│ │Property │ │ │Deal #42  │ │ │from owner│ │ │Deal #38 ││
│ │12 Beach │ │ │High      │ │ │High      │ │ │         ││
│ └─────────┘ │ └──────────┘ │ └──────────┘ │ └─────────┘│
│ ┌─────────┐ │              │              │             │
│ │Follow up│ │              │              │             │
│ │Mrs Nkosi│ │              │              │             │
│ │Contact  │ │              │              │             │
│ └─────────┘ │              │              │             │
└─────────────┴──────────────┴──────────────┴─────────────┘
```

- Drag-and-drop between columns (Alpine.js)
- Cards show: title, linked entity, priority badge, due date, assigned agent avatar
- Filter by: agent, property, deal, priority, task type
- Click card → slide-out panel with full details

#### List View
- Table format with sorting/filtering
- Bulk actions: assign, change priority, mark done
- Group by: agent, property, due date, type

#### My Tasks (Agent View)
- Personal task list — only tasks assigned to the logged-in agent
- Sorted by priority then due date
- Quick-complete checkboxes for simple tasks

### Task Completion → Pillar Write-back
When a task is marked done:
- If it's a `document_upload` task → check if the document was actually uploaded to `property_files`. If yes, auto-complete. If no, ask for confirmation.
- If it's a `compliance` task → check the relevant compliance record. Auto-verify where possible.
- If it's a `follow_up` task → log a contact activity note.

---

## Part 3: Automation Engine

This is the brain. It watches what happens in CoreX and automatically creates calendar events and tasks based on configurable rules.

### Rule Structure
```
automation_rules table:
  id                  bigint PK
  name                string 255
  description         text nullable
  is_active           boolean default true
  is_system           boolean default false (system rules can't be deleted, only deactivated)
  
  -- Trigger
  trigger_model       string (Property, Contact, Deal, DealV2, User, Lease, etc.)
  trigger_event       string (created, updated, status_changed, date_approaching, idle)
  trigger_conditions  json nullable
  -- Example conditions:
  -- { "field": "listing_type", "operator": "=", "value": "sale" }
  -- { "field": "status", "operator": "changed_to", "value": "under_offer" }
  -- { "field": "last_activity_at", "operator": "older_than", "value": "14 days" }
  
  -- Action
  action_type         string (create_event, create_task, send_notification, create_event_and_task)
  action_config       json
  -- Example config for create_task:
  -- {
  --   "title_template": "Upload Mandate for {property.address}",
  --   "task_type": "document_upload",
  --   "priority": "high",
  --   "due_offset": "+48h",
  --   "assign_to": "property.agent",
  --   "checklist": ["Signed mandate", "ID copy of owner", "Proof of ownership"]
  -- }
  
  -- Example config for create_event:
  -- {
  --   "title_template": "Bond Deadline: {property.address}",
  --   "event_type": "deal",
  --   "category": "bond_deadline",
  --   "date_field": "bond_approval_deadline",
  --   "reminder_offsets": [20160, 10080, 4320, 1440],
  --   "priority": "critical"
  -- }
  
  -- Scope
  agency_id           foreignId nullable (null = global/all agencies)
  branch_id           foreignId nullable (null = all branches)
  
  sort_order          integer default 0
  created_at, updated_at, deleted_at
```

### Built-In System Rules (ship active, admin can deactivate)

| # | Rule Name | Trigger | Action |
|---|-----------|---------|--------|
| 1 | **New Listing → Expect Mandate** | Property created with `listing_type = sale` | Task: "Upload signed mandate" due in 48h, assigned to listing agent |
| 2 | **New Listing → Expect Owner ID** | Property created | Task: "Upload owner ID copy" due in 72h |
| 3 | **New Listing → Expect Proof of Ownership** | Property created | Task: "Upload proof of ownership (title deed)" due in 72h |
| 4 | **New Rental → Expect Lease Agreement** | Property created with `listing_type = rental` | Task: "Prepare lease agreement" due in 7d |
| 5 | **New Rental → Expect Landlord FICA** | Rental property with landlord contact linked | Task: "Complete FICA for landlord" due in 5d |
| 6 | **Deal Created → FICA for All Parties** | DealV2 created | Tasks: One per party — "Complete FICA for [contact name]" due in 7d |
| 7 | **Deal Created → Bond Application** | DealV2 created with type = sale | Task: "Submit bond application" due in 3d |
| 8 | **Lease Signed → Renewal Reminder** | Lease status = active, end_date set | Event: "Lease Renewal Due" at end_date - 90d with reminder chain |
| 9 | **FFC Approaching Expiry** | User.ffc_expiry_date approaching | Event + Task: "Renew FFC" starting at -90d, escalating reminders |
| 10 | **Property Idle > 14 Days** | Property with no activity_log entry in 14d | Task: "Review property — no activity in 14 days" assigned to agent |
| 11 | **Property Idle > 30 Days** | Property with no activity in 30d | Task: "Urgent — property neglected for 30 days" + escalate to BM |
| 12 | **Offer Accepted → Transfer Checklist** | Deal status changed to "accepted" | Tasks: Transfer attorney, compliance docs, rates clearance |
| 13 | **Signing Overdue > 48h** | SignatureRequest pending > 48h | Task: "Chase signature from [party]" + escalation |
| 14 | **Monthly Trust Interest** | 1st of every month (recurring) | Task: "Capture trust interest for [month]" assigned to bookkeeper role |
| 15 | **Mandate Expiry Warning** | Property mandate_expiry approaching | Event: "Mandate Expires" with 30d, 14d, 7d reminders |
| 16 | **Training Deadline** | TrainingCourse deadline approaching per agent | Event: "Training Due: [course]" with 14d, 7d, 1d reminders |
| 17 | **New Contact → Follow-up** | Contact created with type = buyer/tenant | Task: "Initial follow-up with [contact]" due in 48h |
| 18 | **Showday Registered → Follow-up** | PropertyShowday created | Task: "Post-showday follow-up" due at showday_date + 24h |
| 19 | **Deal Stuck > 7 Days** | DealStepInstance unchanged for 7d | Task: "Deal stuck at [step name] for 7+ days" assigned to deal agent |
| 20 | **Commission Unpaid > 30 Days** | Deal registered > 30d, commission_status = 'Not Paid' | Task: "Follow up unpaid commission" + escalate to admin |

### Custom Rule Builder (Admin UI)
Admin can create custom rules via a form:
1. Pick a trigger model (Property, Contact, Deal, User, Lease)
2. Pick a trigger event (created, updated, field changed, date approaching, idle)
3. Set conditions (optional filters)
4. Pick an action (create event, create task, both, send notification)
5. Configure the action (title template, priority, due date offset, assignment, checklist)
6. Save and activate

### Automation Log
```
automation_log table:
  id                  bigint PK
  rule_id             foreignId → automation_rules
  trigger_model_type  string
  trigger_model_id    bigint
  action_type         string
  action_result_type  string nullable (CalendarEvent, CommandTask)
  action_result_id    bigint nullable
  executed_at         datetime
  success             boolean
  error_message       text nullable
  created_at
```

Every rule execution is logged. Admin can see: "Rule #7 fired 42 times this month, created 42 tasks, 38 completed, 4 overdue."

---

## Part 4: Property Health Monitor

Every property gets a computed **health score** based on configurable criteria. This surfaces neglected properties before they become problems.

### Health Factors (configurable weights in Settings)
| Factor | Measurement | Impact |
|--------|-------------|--------|
| **Last activity** | Days since any logged activity | Score drops after 7d, critical after 21d |
| **Documents complete** | Expected docs uploaded vs missing | Major penalty for missing mandate/FICA |
| **Photos** | Number of listing photos | Penalty below 10, bonus above 20 |
| **Syndication** | Active on P24/PP or not | Penalty if listing-ready but not syndicated |
| **Price currency** | Days since last price change | Neutral first 30d, penalty after 60d |
| **Enquiries** | Showday/viewing activity | Bonus for activity, penalty for none in 30d |
| **Contact links** | Owner/landlord linked with full details | Penalty if no owner contact linked |
| **Agent assigned** | Has an active assigned agent | Critical penalty if unassigned |

### Health Score Display
```
Property Health Score: 72/100  ████████████████████░░░░░  NEEDS ATTENTION

 ✓ Documents complete (3/3)
 ✓ Photos sufficient (18)
 ✗ No activity in 16 days          ← -15 points
 ✗ Not syndicated on P24           ← -8 points
 ✗ No viewing in 30 days           ← -5 points
```

### Property Health Table
```
property_health_scores table:
  id                  bigint PK
  property_id         foreignId unique
  score               integer (0-100)
  grade               enum: excellent (90+), good (70-89), attention (50-69), critical (<50)
  factors             json (breakdown of each factor's contribution)
  last_calculated_at  datetime
  created_at, updated_at
```

Recalculated by a scheduled command: `php artisan command-center:calculate-health` (runs nightly or on-demand).

### Dashboard Widget: Properties Needing Attention
```
┌─────────────────────────────────────────────────┐
│  PROPERTIES NEEDING ATTENTION          View All →│
│                                                   │
│  🔴 12 Beach Rd, Shelly Beach     Score: 32      │
│     No activity 28 days • Missing mandate         │
│     Agent: John D.                                │
│                                                   │
│  🟡 45 Marine Dr, Margate         Score: 58      │
│     No viewings 21 days • Price stale 45 days     │
│     Agent: Sarah M.                               │
│                                                   │
│  🟡 8 Palm Blvd, Uvongo           Score: 61      │
│     Not syndicated • Missing FICA                 │
│     Agent: John D.                                │
│                                                   │
│  Total: 3 critical, 8 needs attention, 24 good    │
└─────────────────────────────────────────────────┘
```

---

## Part 5: Agent Scorecard

Each agent gets a real-time accountability scorecard — visible to the agent (their own) and to BMs/admins (all agents).

### Scorecard Metrics
| Metric | Source | Display |
|--------|--------|---------|
| **Tasks completed this week** | command_tasks (done this week) | 12/15 (80%) |
| **Tasks overdue** | command_tasks (overdue count) | 3 overdue |
| **Properties attended** | Properties with activity in last 7d | 8/12 (67%) |
| **Documents uploaded** | property_files created this week | 5 |
| **FICA completion rate** | Contacts with complete vs incomplete FICA | 85% |
| **Average response time** | Time from task created to started | 4.2 hours |
| **Deals progressed** | Deal steps moved forward this week | 3 steps |
| **Calendar events completed** | Events marked done vs total due | 90% |
| **Activity points** | From existing daily activity system | 245 pts |

### Scorecard Views
- **Agent sees**: Their own scorecard on the dashboard — motivational, shows progress
- **BM sees**: All agents in their branch — comparison view, identify who needs support
- **Admin sees**: All agents, all branches — full oversight with drill-down

### Agent Scorecard Table (Computed)
```
agent_scorecards table:
  id                  bigint PK
  user_id             foreignId
  period_type         enum: daily, weekly, monthly
  period_start        date
  period_end          date
  
  tasks_completed     integer
  tasks_overdue       integer
  tasks_total         integer
  properties_attended integer
  properties_total    integer
  documents_uploaded  integer
  fica_complete       integer
  fica_total          integer
  avg_response_hours  decimal(8,2)
  deals_progressed    integer
  events_completed    integer
  events_total        integer
  activity_points     integer
  
  overall_score       integer (0-100)
  
  computed_at         datetime
  created_at, updated_at
```

---

## Part 6: Notification System

### Unified Notification Queue
```
notifications table (Laravel built-in):
  id                  uuid PK
  type                string (class name)
  notifiable_type     string (User)
  notifiable_id       bigint
  data                json (title, body, action_url, icon, category)
  read_at             datetime nullable
  created_at
```

Uses Laravel's built-in notification system with custom channels.

### Notification UI
- **Bell icon** in the CoreX header bar — shows unread count badge
- Click bell → dropdown panel with recent notifications
- Each notification shows: icon, title, time ago, action link
- "Mark all read" + "View all" links
- Notification categories: calendar, task, compliance, deal, property, system

### Notification Channels
| Channel | When | Implementation |
|---------|------|----------------|
| **In-app** | Always | Laravel notification + Alpine.js polling or Pusher |
| **Email** | Configurable per user | Laravel mail notification with branded template |
| **iCal** | Calendar events only | ICS feed endpoint per user |
| **Daily Digest** | Configurable | Scheduled command compiles day's summary into one email |

---

## Part 7: Dashboard Layout

The Command Center replaces the current dashboard. It is responsive and role-aware.

### Agent Dashboard
```
┌──────────────────────────────────────────────────────────────┐
│  Good morning, John                          Mon 31 Mar 2026 │
├──────────────┬───────────────┬───────────────┬───────────────┤
│  TODAY       │  OVERDUE      │  THIS WEEK    │  ACTIVITY PTS │
│  5 events    │  3 items      │  18 events    │  245/300      │
│  2 tasks     │  ● ● ●       │  7 tasks      │  ████████░░   │
├──────────────┴───────────────┴───────────────┴───────────────┤
│                                                               │
│  TODAY'S AGENDA                                    Full Cal → │
│  ─────────────                                                │
│  09:00  Viewing — 12 Beach Rd, Shelly Beach                  │
│  10:30  Follow-up call — Mrs Nkosi (Buyer)                   │
│  14:00  Signing — Lease Agreement, 8 Palm Blvd               │
│  16:00  Branch Meeting — Shelly Beach Office                  │
│                                                               │
│  ⚠ OVERDUE                                                    │
│  ─────────                                                    │
│  Upload mandate — 45 Marine Dr (3 days overdue)              │
│  FICA for J. Smith — Deal #42 (5 days overdue)               │
│  Chase signature — Lease, 8 Palm Blvd (48h overdue)          │
│                                                               │
│  MY TASKS                                         All Tasks → │
│  ────────                                                     │
│  ☐ Upload proof of ownership — 12 Beach Rd (due tomorrow)    │
│  ☐ Follow-up — Mr & Mrs Van Der Merwe (due Wed)              │
│  ☐ Submit bond application — Deal #44 (due Thu)              │
│  ☑ Capture daily activity (done today)                        │
│                                                               │
│  MY PROPERTIES                                  All Props →   │
│  ─────────────                                                │
│  🔴 45 Marine Dr — Score: 58 — No activity 21d               │
│  🟡 8 Palm Blvd — Score: 65 — Missing syndication            │
│  🟢 12 Beach Rd — Score: 88 — All good                       │
│                                                               │
│  CANDIDATE DOCUMENTS AWAITING AUTHORISATION                   │
│  (existing functionality — preserved from current dashboard)  │
│                                                               │
├───────────────────────────────────────────────────────────────┤
│  MINI CALENDAR (current month)                                │
│  ┌──┬──┬──┬──┬──┬──┬──┐                                     │
│  │Mo│Tu│We│Th│Fr│Sa│Su│  ● = events on that day              │
│  ├──┼──┼──┼──┼──┼──┼──┤                                     │
│  │  │ 1│ 2│ 3│ 4│ 5│ 6│                                     │
│  │ 7│ 8│ 9│10│11│12│13│  Click day → jump to full calendar   │
│  │  │  │  │  │  │  │  │                                     │
│  └──┴──┴──┴──┴──┴──┴──┘                                     │
└───────────────────────────────────────────────────────────────┘
```

### BM Dashboard (adds to agent view)
```
┌───────────────────────────────────────────────────────────────┐
│  BRANCH OVERVIEW — Shelly Beach                    March 2026 │
├─────────────┬─────────────┬──────────────┬────────────────────┤
│  AGENTS     │  PROPERTIES │  DEALS       │  COMPLIANCE        │
│  8 active   │  35 listed  │  12 active   │  94% FICA          │
│  2 on leave │  3 critical │  2 stuck     │  1 FFC expiring     │
├─────────────┴─────────────┴──────────────┴────────────────────┤
│                                                                │
│  AGENT SCORECARDS                                              │
│  ─────────────────                                             │
│  Agent          Tasks    Props    FICA    Score                 │
│  John D.        12/15    8/12     85%     72                   │
│  Sarah M.       15/15    6/6      100%    94                   │
│  Peter K.       8/18     4/10     60%     45 ⚠                │
│  ...                                                           │
│                                                                │
│  PROPERTIES NEEDING ATTENTION (branch)                         │
│  (same widget as agent but showing all branch properties)      │
│                                                                │
│  DEALS AT RISK                                                 │
│  (deals stuck at a step for > 7 days, sorted by value)         │
└───────────────────────────────────────────────────────────────┘
```

### Admin Dashboard (adds to BM view)
- Cross-branch comparison
- Agency-wide compliance overview
- Automation rule performance stats
- System health (rules fired, tasks auto-created, completion rates)

---

## Part 8: Settings

All configurable via Settings → Command Center:

### General Settings
| Setting | Default | Description |
|---------|---------|-------------|
| Property idle threshold (days) | 14 | Days before a property is flagged |
| Property critical threshold (days) | 30 | Days before property flagged as critical |
| Default task due offset | 48h | Default due date for auto-generated tasks |
| Scorecard calculation frequency | daily | How often agent scorecards recalculate |
| Health score calculation frequency | nightly | How often property health recalculates |

### Document Expectations
Admin defines what documents are expected per property type:
```
command_document_expectations table:
  id                  bigint PK
  property_type       string (sale, rental, commercial, vacant_land)
  document_type_id    foreignId → document_types
  required            boolean default true
  due_offset_hours    integer default 72
  label               string ("Signed Mandate", "Owner ID Copy", etc.)
  sort_order          integer
  agency_id           foreignId nullable
  created_at, updated_at, deleted_at
```

When a property is created, the system checks this table and auto-creates tasks for each expected document.

### Reminder Defaults (per event category)
```
command_reminder_defaults table:
  id                  bigint PK
  event_category      string (bond_deadline, lease_expiry, fica, mandate, etc.)
  reminder_offsets    json ([129600, 60480, 10080, 1440] — in minutes)
  escalation_enabled  boolean default true
  escalation_delay    integer default 1440 (minutes before escalating)
  escalation_to       string (bm, admin, both)
  agency_id           foreignId nullable
  created_at, updated_at
```

---

## Database Summary

### New Tables
| Table | Purpose | Est. Rows |
|-------|---------|-----------|
| `calendar_events` | All calendar events (auto + manual) | 10k+ |
| `calendar_reminders_log` | Reminder delivery audit trail | 50k+ |
| `calendar_user_preferences` | Per-user calendar settings | ~30 |
| `command_tasks` | Actionable task items | 5k+ |
| `automation_rules` | Trigger → action definitions | ~50 |
| `automation_log` | Rule execution audit trail | 50k+ |
| `property_health_scores` | Computed property health | ~200 |
| `agent_scorecards` | Computed agent metrics | ~500 |
| `command_document_expectations` | Expected docs per property type | ~20 |
| `command_reminder_defaults` | Reminder config per event category | ~15 |

### Modified Tables
| Table | Change |
|-------|--------|
| `properties` | Add `last_activity_at` (datetime, nullable) — updated by observer |
| `users` | Already has FFC fields — no changes needed |

---

## Permissions

Add to `config/corex-permissions.php`:

```php
// Command Center
'command_center.view'              => 'View Command Center dashboard',
'command_center.calendar.view'     => 'View calendar',
'command_center.calendar.create'   => 'Create manual calendar events',
'command_center.calendar.edit'     => 'Edit calendar events',
'command_center.calendar.delete'   => 'Delete calendar events',
'command_center.tasks.view'        => 'View task board',
'command_center.tasks.create'      => 'Create tasks',
'command_center.tasks.edit'        => 'Edit tasks',
'command_center.tasks.assign'      => 'Assign tasks to other agents',
'command_center.tasks.delete'      => 'Delete tasks',
'command_center.health.view'       => 'View property health scores',
'command_center.scorecards.view_own'    => 'View own scorecard',
'command_center.scorecards.view_branch' => 'View branch scorecards',
'command_center.scorecards.view_all'    => 'View all scorecards',
'command_center.automation.view'   => 'View automation rules',
'command_center.automation.manage' => 'Create/edit/delete automation rules',
'command_center.settings'          => 'Manage Command Center settings',
```

Data scoping follows the standard CoreX pattern:
- `own` scope → agent sees their events, tasks, properties, scorecard
- `branch` scope → BM sees the full branch
- `all` scope → admin sees everything

---

## Navigation

### Sidebar
```
Dashboard (Home) ← this IS the Command Center now
Calendar         ← full calendar page (month/week/day/agenda/timeline)
  ├── My Calendar
  └── Branch Calendar (BM+)
Tasks            ← full task board page (kanban/list)
  ├── My Tasks
  └── All Tasks (BM+)
```

### Contextual Links (on existing pages)
- **Property show page** → "Events" tab showing all calendar events for that property
- **Property show page** → "Tasks" tab showing all tasks for that property
- **Property show page** → Health score badge in header
- **Contact show page** → "Events" tab showing all events involving that contact
- **Deal show page** → "Timeline" tab showing all deal events chronologically
- **Agent profile** → Scorecard tab

---

## Files to Create

### Models
```
app/Models/CommandCenter/CalendarEvent.php
app/Models/CommandCenter/CalendarReminderLog.php
app/Models/CommandCenter/CalendarUserPreference.php
app/Models/CommandCenter/CommandTask.php
app/Models/CommandCenter/AutomationRule.php
app/Models/CommandCenter/AutomationLog.php
app/Models/CommandCenter/PropertyHealthScore.php
app/Models/CommandCenter/AgentScorecard.php
app/Models/CommandCenter/DocumentExpectation.php
app/Models/CommandCenter/ReminderDefault.php
```

### Services
```
app/Services/CommandCenter/CalendarEventService.php
app/Services/CommandCenter/TaskService.php
app/Services/CommandCenter/AutomationEngine.php
app/Services/CommandCenter/PropertyHealthCalculator.php
app/Services/CommandCenter/AgentScorecardCalculator.php
app/Services/CommandCenter/ReminderProcessor.php
app/Services/CommandCenter/NotificationService.php
```

### Controllers
```
app/Http/Controllers/CommandCenter/DashboardController.php    ← replaces CoreX/DashboardController
app/Http/Controllers/CommandCenter/CalendarController.php
app/Http/Controllers/CommandCenter/TaskController.php
app/Http/Controllers/CommandCenter/AutomationController.php
app/Http/Controllers/CommandCenter/SettingsController.php
app/Http/Controllers/CommandCenter/ICalController.php
```

### Views
```
resources/views/command-center/
  dashboard.blade.php              ← the new dashboard home
  calendar/
    index.blade.php                ← full calendar page
    partials/
      month-view.blade.php
      week-view.blade.php
      day-view.blade.php
      agenda-view.blade.php
      timeline-view.blade.php
      event-card.blade.php
      event-form.blade.php
  tasks/
    index.blade.php                ← task board page
    partials/
      kanban-board.blade.php
      list-view.blade.php
      task-card.blade.php
      task-form.blade.php
  widgets/
    todays-agenda.blade.php
    overdue-items.blade.php
    my-tasks.blade.php
    property-health.blade.php
    agent-scorecard.blade.php
    mini-calendar.blade.php
    branch-overview.blade.php
    candidate-documents.blade.php  ← preserved from current dashboard
  settings/
    index.blade.php
    automation-rules.blade.php
    document-expectations.blade.php
    reminder-defaults.blade.php
```

### Observers
```
app/Observers/CommandCenter/PropertyActivityObserver.php      ← updates last_activity_at
app/Observers/CommandCenter/DealEventObserver.php             ← auto-creates deal events
app/Observers/CommandCenter/DocumentEventObserver.php         ← signing chase events
```

### Commands
```
app/Console/Commands/ProcessReminders.php           ← php artisan command-center:reminders
app/Console/Commands/CalculatePropertyHealth.php    ← php artisan command-center:health
app/Console/Commands/CalculateAgentScorecards.php   ← php artisan command-center:scorecards
app/Console/Commands/RunAutomationRules.php         ← php artisan command-center:automations
app/Console/Commands/BackfillCalendarEvents.php     ← one-time: generate events from existing data
app/Console/Commands/SendDailyDigest.php            ← php artisan command-center:digest
```

### Migrations (12+)
```
create_calendar_events_table
create_calendar_reminders_log_table
create_calendar_user_preferences_table
create_command_tasks_table
create_automation_rules_table
create_automation_log_table
create_property_health_scores_table
create_agent_scorecards_table
create_command_document_expectations_table
create_command_reminder_defaults_table
add_last_activity_at_to_properties_table
seed_default_automation_rules
```

---

## Build Phases

### Phase 1: Foundation + Dashboard Shell (2-3 prompts)
- Migrations for all tables
- Models with relationships, scopes, casts
- New dashboard layout (widgets as empty shells with hardcoded sample data)
- Sidebar navigation entries
- Permissions
- Route registration

### Phase 2: Calendar System (3-4 prompts)
- CalendarEventService — full CRUD
- Manual event creation form
- Month/Week/Day/Agenda views (Alpine.js)
- Event detail slide-out panel
- Filters and colour coding

### Phase 3: Task Board (2-3 prompts)
- TaskService — full CRUD
- Kanban board with drag-and-drop
- List view with filtering
- Task creation form with pillar linking
- Quick-complete from dashboard

### Phase 4: Automation Engine (3-4 prompts)
- AutomationEngine service
- Built-in system rules (seeder)
- Model observers that fire rules
- Automation log
- Admin UI for viewing/toggling rules
- Custom rule builder form

### Phase 5: Property Health + Agent Scorecards (2 prompts)
- PropertyHealthCalculator service
- AgentScorecardCalculator service
- Scheduled commands
- Dashboard widgets (populated from real data)
- Property show page health badge

### Phase 6: Reminder Engine + Notifications (2-3 prompts)
- ReminderProcessor scheduled command
- Laravel notification system integration
- Bell icon + dropdown in header
- Email notifications with branded template
- Escalation logic
- Daily digest email

### Phase 7: iCal + Polish (1-2 prompts)
- iCal subscription endpoint
- Timeline/Gantt view
- Backfill command for existing data
- Event search
- Contextual tabs on property/contact/deal pages

### Phase 8: Settings UI (1-2 prompts)
- Document expectations management
- Reminder defaults management
- Automation rules management
- General Command Center settings

---

## Acceptance Criteria

The Command Center is done when:

1. **Dashboard**: Agent logs in and immediately sees: today's agenda, overdue items, tasks, property health, activity points, and mini calendar — all populated from real data
2. **Calendar**: Full month/week/day/agenda views with colour-coded events, manual creation, and filtering
3. **Auto-events**: Creating a deal auto-generates all deadline events. Creating a property auto-generates document expectation tasks. Lease dates auto-generate renewal reminders.
4. **Tasks**: Kanban board works with drag-and-drop. Tasks link to properties/contacts/deals. Completion writes back to pillars.
5. **Health scores**: Every property has a calculated health score. Neglected properties surface automatically.
6. **Scorecards**: Agents see their own scorecard. BMs see branch comparison. Admin sees all.
7. **Reminders**: Overdue events escalate. Agents get reminded before deadlines. BMs get alerted on stale items.
8. **Notifications**: Bell icon with count. Clickable notifications that navigate to the relevant record.
9. **Settings**: Admin can configure document expectations, reminder schedules, automation rules, and health thresholds.
10. **Permissions**: Every widget, view, and action respects CoreX permission scoping.
11. **iCal**: Agents can subscribe to their CoreX calendar from their phone.
12. **Existing functionality preserved**: Candidate document authorisation widget, activity points — still visible on dashboard.

---

## What Makes This Better Than Monday.com

| Feature | Monday.com | CoreX Command Center |
|---------|-----------|----------------------|
| Auto-generated events | Manual automation setup per board | Built-in — deals, leases, compliance auto-wire |
| Real estate awareness | Generic boards | Knows mandates, FICA, FFC, leases, bond deadlines |
| Property health | Not a concept | Automated scoring with neglect detection |
| Agent accountability | Basic workload view | Full scorecard with task completion, FICA rates, response times |
| Escalation chains | Basic notify | Agent → BM → Admin with configurable timelines |
| Document expectations | Manual checklists | Auto-creates tasks when property is listed |
| Regulatory compliance | Nothing | FFC, PPRA, FICA, training deadlines tracked automatically |
| Commission tracking | Not applicable | Overdue commission alerts with deal context |
| iCal sync | Yes | Yes + phone reminders as backup |
| Cost | R200-800/user/month | Built into CoreX — zero extra cost |
| Pillar integration | Generic entities | Every event/task links to Property, Contact, Deal, Agent |

---

## Role-Based Visibility Scope — Calendar, Tasks & Today (2026-06-23)

> Added to give each agency control over how much of the Command Center an
> agent sees. Built on the existing Role Manager Data Scope mechanism — not a
> bespoke system.

### Problem
The Calendar, Today, and Tasks surfaces showed the whole agency's events and
tasks to every user. An agent should, by default, see only **their own**
calendar, tasks and Today entries — while managers and admins can be granted
wider visibility per role.

### Model
Two **independent** Data Scope toggles live in **Role Manager → Command Center**,
rendered as their own module cards:

| Card | Permission key | Controls |
|------|----------------|----------|
| **Calendar** | `command_center.calendar.view` | Calendar page + Today's appointment/overdue-event cards |
| **Tasks** | `command_center.tasks.view` | Task board + archived tasks + Today's overdue-task cards |

Each toggle is **None / Own / Branch / All**:

- **None** — no access. Sidebar entry hidden; the route returns 403
  (`permission:` middleware on the read routes).
- **Own** — only the user's own records (`user_id` / `assigned_to`).
- **Branch** — records in the user's branch (`branch_id`); falls back to Own
  when the user has no branch.
- **All** — the whole agency (agency isolation via `BelongsToAgency` still
  applies — never cross-agency).

The **Today** page has no toggle of its own: its appointment/overdue-event
cards inherit the **Calendar** scope, its task/overdue-task cards inherit the
**Tasks** scope.

### Enforcement
- `PermissionService::calendarScope($user)` / `taskScope($user)` resolve the
  role's scope (default `own`).
- `PermissionService::clampScope($requested, $ceiling)` clamps the Calendar
  page's My/Branch/All pills to the role ceiling — a user can narrow, never
  widen beyond what Role Manager grants. Pills above the ceiling are hidden.
- Query narrowing is centralised in `CalendarEvent::scopeVisibleTo()` and
  `CommandTask::scopeVisibleTo()`.

### Defaults
- Rollout default (migration `…default_command_center_visibility_scope`):
  **every existing non-owner role → Own** for both keys. Grants access (no
  lockouts) while constraining breadth; admins widen per role afterward.
- Owner roles bypass permission checks → always **All**.
- Mutations (create/assign/archive-done) remain owner-scoped regardless of
  view scope; only the **read** surfaces honour the scope.

### Acceptance criteria
1. Role Manager → Command Center shows three cards: **Command Center**,
   **Calendar**, **Tasks**, the latter two each with a None/Own/Branch/All
   Data Scope toggle.
2. An `agent` (scope Own) sees only their own events on the Calendar, their own
   tasks on the Task board, and only own entries in Today's schedule/overdue
   cards.
3. A role set to Branch sees their branch's events/tasks; a role set to All
   sees the whole agency's — never another agency's.
4. A role set to None has the sidebar entry hidden and gets 403 on
   `/command-center/calendar` and `/command-center/tasks`.
5. The Calendar My/Branch/All pills never exceed the role ceiling; a requested
   `?scope=` wider than the ceiling is clamped down.
6. Changing a role's scope in Role Manager takes effect after the
   per-request permission cache clears (immediately on next request).

### Files
- `config/corex-permissions.php` — calendar/tasks keys split into
  `command_center_calendar` / `command_center_tasks` modules; `.view` keys are
  type `action` so the scope toggle renders and persists.
- `app/Services/PermissionService.php` — `calendarScope` / `taskScope` /
  `clampScope`.
- `app/Models/CommandCenter/CalendarEvent.php`, `CommandTask.php` —
  `scopeVisibleTo`.
- `app/Services/CommandCenter/CalendarEventService.php`, `TaskService.php`,
  `CommandCentreService.php` — consume scope.
- `app/Http/Controllers/CommandCenter/CalendarController.php`,
  `TaskController.php` — ceiling + clamp.
- `resources/views/command-center/calendar/index.blade.php` — ceiling-aware
  scope pills.
- `app/Http/Controllers/CoreX/RoleManagerController.php` — module labels.
- `routes/web.php` — `permission:` middleware on calendar/tasks read routes.
- `database/migrations/2026_06_23_120000_default_command_center_visibility_scope.php`.
