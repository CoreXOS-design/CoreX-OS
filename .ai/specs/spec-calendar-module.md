# CoreX Calendar Module — Design Spec
> Monday.com calendar on steroids, purpose-built for real estate
> Version: 1.0 — 2026-03-30

> **⚠ PARTIALLY SUPERSEDED (2026-07-02, AT-155).** This is the original **design** doc. The **interactive calendar as-built** — tiles (times + duration overlay/lane-packing), panel stacking, private events, `occupies_time` marker-vs-appointment, `event_nature`/requires-feedback, double-booking/self-conflict warnings, the **recurring-events model** (materialise-on-view, synthetic occurrence ids, this/this-and-future/all edit+delete, exception children, tombstones, soft-delete), the delete action, and AT-154 attendee auto-fill — now lives in **`.ai/specs/calendar-interactive.md`**, which is the current truth where the two overlap. See that file's §0 for the exact sections it supersedes. The reminder engine, auto-generation observers, iCal, and dashboard-widget vision below remain valid.

---

## What Monday.com Does Well (and where we beat it)

Monday.com's calendar is a **generic work management calendar** — boards with dates rendered as calendar events, automations that fire on date triggers, colour coding by status/person, day/week/month views, Google Calendar sync, and mobile access.

Where it falls short for real estate:
- It doesn't **know** what a lease expiry or mandate renewal is
- Automations require manual setup per board — nothing is auto-wired
- No concept of regulated deadlines (bond approval, FICA, suspensive conditions)
- No understanding of tenant/landlord/buyer/seller relationships
- Reminders are generic "notify someone" — no escalation chains
- No auto-generation of events from operational data

**CoreX Calendar is different.** It's not a generic calendar with manual events bolted on. It's a **real estate operations calendar** where 80% of events auto-generate from the work the agency is already doing in CoreX. The remaining 20% is manual entries. The moment an agent captures a deal, the calendar already knows every deadline. The moment a lease is signed, the renewal reminder is already set for 90 days before expiry.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│                  CALENDAR UI                         │
│   Month / Week / Day / Agenda / Timeline views       │
│   Filter by: agent, type, property, status           │
│   Colour coded by event category                     │
├─────────────────────────────────────────────────────┤
│               CALENDAR EVENTS TABLE                  │
│   Unified storage for ALL calendar entries            │
│   Both auto-generated and manual                     │
├──────────┬──────────┬───────────┬───────────────────┤
│  AUTO    │  AUTO    │   AUTO    │     MANUAL        │
│  Deal    │  Lease   │ Compliance│   User-created    │
│  Events  │  Events  │  Events   │   events          │
├──────────┴──────────┴───────────┴───────────────────┤
│            REMINDER ENGINE                           │
│   Scheduled jobs that scan events and fire           │
│   notifications at configured intervals              │
├─────────────────────────────────────────────────────┤
│            NOTIFICATION CHANNELS                     │
│   In-app (bell icon) │ Email │ Calendar sync (iCal) │
└─────────────────────────────────────────────────────┘
```

---

## Auto-Generated Events: Where Dates Live in CoreX

This is the killer feature. Every date already captured in CoreX becomes a calendar event automatically. No agent has to create a reminder — the system does it the moment the data is entered.

### DEALS MODULE
| Date Source | Calendar Event | Auto-Reminders |
|-------------|---------------|----------------|
| Deal registered date | "New Deal: [property] — [buyer/seller]" | — |
| Offer acceptance date | "Offer Accepted: [property]" | — |
| Bond approval deadline | "Bond Approval Due: [property]" | 14d, 7d, 3d, 1d before |
| Bond approved date | "Bond Approved: [property]" | — |
| Transfer date (estimated) | "Transfer Due: [property]" | 30d, 14d, 7d before |
| Registration date | "Registration: [property]" | 7d, 3d before |
| Commission payment due | "Commission Due: [deal]" | 7d, on-day |
| Suspensive condition deadlines | "Condition: [description] — [property]" | 7d, 3d, 1d before; auto-escalate on expiry |
| Settlement date | "Settlement: [property]" | 3d, 1d before |

### RENTAL / LEASE MODULE
| Date Source | Calendar Event | Auto-Reminders |
|-------------|---------------|----------------|
| Lease start date | "Lease Start: [property] — [tenant]" | — |
| Lease expiry date | "Lease Expiry: [property] — [tenant]" | 90d, 60d, 30d, 14d before |
| Rental escalation date | "Rental Escalation: [property]" | 30d, 7d before |
| Deposit invested date | "Deposit Invested: [property]" | — |
| Deposit refund due | "Deposit Refund Due: [property]" | 14d, 7d before |
| Inspection dates | "Inspection: [property]" | 3d, 1d before |
| Move-in / move-out dates | "Move-in/out: [property]" | 7d, 1d before |

### COMPLIANCE MODULE
| Date Source | Calendar Event | Auto-Reminders |
|-------------|---------------|----------------|
| FFC expiry (per agent) | "FFC Expiry: [agent name]" | 90d, 60d, 30d, 7d before |
| PPRA registration renewal | "PPRA Renewal: [agent name]" | 60d, 30d before |
| Training course deadlines | "Training Due: [course] — [agent]" | 14d, 7d, 1d before |
| FICA document expiry | "FICA Review: [contact name]" | 30d, 14d before |
| Mandate expiry | "Mandate Expiry: [property]" | 30d, 14d, 7d before |

### DOCUMENT / E-SIGN MODULE
| Date Source | Calendar Event | Auto-Reminders |
|-------------|---------------|----------------|
| Document sent for signing | "Awaiting Signature: [doc] — [party]" | 24h, 48h, 72h chase |
| Signing deadline | "Signing Deadline: [doc]" | 3d, 1d before |
| Document expiry (if applicable) | "Document Expires: [doc]" | 7d, 3d before |

### PROSPECTING MODULE
| Date Source | Calendar Event | Auto-Reminders |
|-------------|---------------|----------------|
| Claim auto-expiry (48h) | "Claim Expiring: [property] — [agent]" | 12h, 4h before |
| Meeting set date | "Prospect Meeting: [property]" | 1d, 2h before |
| Follow-up due | "Follow-up: [property] — [agent]" | On day |

### PORTAL SYNDICATION
| Date Source | Calendar Event | Auto-Reminders |
|-------------|---------------|----------------|
| Listing refresh due | "Refresh Listing: [property] — [portal]" | 3d before |
| Listing expiry on portal | "Listing Expires: [property] — [portal]" | 7d, 3d before |

---

## Manual Events

Users can also create their own calendar events:
- Viewings / showings (property, contact, time, agent)
- Client meetings
- Valuations / CMAs
- Office meetings
- Personal reminders
- Custom recurring events (e.g. monthly trust interest capture)

Manual events have the same reminder system as auto events — configurable alerts.

---

## Calendar Views

### Month View (default)
- Traditional calendar grid
- Events colour-coded by category (deals=blue, leases=green, compliance=amber, manual=grey, overdue=red)
- Click a day to see all events, click an event to see details
- Dot indicators for days with many events

### Week View
- 7-day horizontal layout with time slots
- Events shown as blocks within time slots
- Drag-and-drop to reschedule manual events

### Day View  
- Detailed single-day view with hourly slots
- Full event details visible inline

### Agenda View
- Vertical list of upcoming events, grouped by day
- Most useful for "what's coming up" — default for the dashboard widget
- Filterable: "Show me all lease expiries in the next 90 days"

### Timeline View (Gantt-style)
- Horizontal bars showing event durations (lease periods, deal pipelines)
- Best for BM/admin to see portfolio-wide timelines
- Properties on Y-axis, time on X-axis

---

## Calendar Filters

Every view supports filtering by:
- **Agent** — see one agent's calendar or all
- **Event category** — deals, leases, compliance, prospecting, manual
- **Property** — all events for one property
- **Contact** — all events involving one person (buyer, seller, tenant, landlord)
- **Priority** — overdue, due today, due this week, due this month
- **Branch** — filter by office (data scoping applies)
- **Status** — pending, completed, overdue, dismissed

---

## Reminder Engine

### How It Works
A scheduled Laravel command runs every 15 minutes:
`php artisan calendar:process-reminders`

It scans all calendar events where:
- Event date minus reminder offset = now (within the 15-min window)
- Reminder has not already been sent for this offset
- Event is not dismissed/completed

### Reminder Configuration (3 levels)
1. **Agency defaults** — set by admin in Settings. Applied to all events unless overridden.
2. **Per-category defaults** — different reminder schedules per event type (compliance events get longer lead times than viewings)
3. **Per-event override** — user can customise reminders on any individual event

### Escalation Rules
- If a reminder is sent and no action taken within 24h, escalate to BM
- If BM doesn't act within 24h, escalate to admin
- Configurable per event category (compliance escalates faster than viewings)
- Escalation can be turned off per category

### Notification Channels
- **In-app** — bell icon notification in CoreX header, real-time count badge
- **Email** — branded agency email using the email template system
- **iCal sync** — users can subscribe to their CoreX calendar via iCal URL, which syncs to Google Calendar, Outlook, Apple Calendar automatically
- **SMS** (future) — for critical reminders (signing deadlines, compliance)

---

## Dashboard Widget

A compact calendar widget on the CoreX dashboard showing:
- Today's events (top 5)
- Overdue items (red, count badge)
- Coming this week (summary count by category)
- "View Full Calendar" link

This is the first thing agents see when they log in — immediate awareness of what's due.

---

## Database Design

### `calendar_events` table
```
id                  bigint PK
user_id             foreignId (owner/assigned agent)
created_by_id       foreignId (who created it — system or user)
event_type          enum: deal, lease, compliance, document, prospecting, portal, manual
category            string (sub-type: bond_deadline, lease_expiry, ffc_expiry, viewing, etc.)
title               string 255
description         text nullable
event_date          datetime (when it happens)
end_date            datetime nullable (for duration events)
all_day             boolean default true
priority            enum: low, normal, high, critical
status              enum: pending, completed, overdue, dismissed
colour              string nullable (hex, auto-set from event_type if null)

-- Polymorphic link to source record
source_type         string nullable (App\Models\Deal, App\Models\Lease, etc.)
source_id           bigint nullable

-- Links
property_id         foreignId nullable
contact_id          foreignId nullable
branch_id           foreignId nullable

-- Reminder config (JSON: array of offsets in minutes)
-- e.g. [129600, 60480, 10080, 1440] = 90d, 42d, 7d, 1d
reminder_offsets    json nullable
reminders_sent      json nullable (tracks which offsets have been sent)

-- Recurrence  [AS-BUILT: these columns are now ACTIVE — see calendar-interactive.md §7.
--  Note: the shipped RecurrenceRule is a hand-rolled RFC-5545 SUBSET (FREQ=DAILY|WEEKLY|MONTHLY,
--  INTERVAL, UNTIL/COUNT; NO BYDAY/BYMONTHDAY) — not the YEARLY;BYMONTH example below.]
is_recurring        boolean default false
recurrence_rule     string nullable (RRULE subset: e.g. FREQ=WEEKLY;INTERVAL=2;COUNT=10)
parent_event_id     bigint nullable (links recurring instances/exceptions to parent)

-- Metadata
metadata            json nullable (extra data: deal amount, lease rental, etc.)

created_at, updated_at, deleted_at (soft delete)
```

### `calendar_reminders_log` table
```
id                  bigint PK
calendar_event_id   foreignId
user_id             foreignId (who was notified)
channel             enum: app, email, sms
offset_minutes      integer (which reminder interval this was)
sent_at             datetime
read_at             datetime nullable
actioned_at         datetime nullable (user clicked/acknowledged)
escalated           boolean default false
created_at
```

### `calendar_user_preferences` table
```
id                  bigint PK
user_id             foreignId unique
default_view        enum: month, week, day, agenda (default: month)
working_hours_start time default 08:00
working_hours_end   time default 17:00
weekend_visible     boolean default false
ical_token          string unique nullable (for iCal subscription URL)
email_reminders     boolean default true
app_reminders       boolean default true
digest_email        enum: none, daily, weekly (default: daily)
created_at, updated_at
```

---

## Event Auto-Generation System

### CalendarEventService

Central service that all modules call to create/update/delete calendar events:

```php
CalendarEventService::createFromDeal(Deal $deal);        // Creates all deal-related events
CalendarEventService::createFromLease(Lease $lease);      // Creates all lease-related events
CalendarEventService::createFromCompliance(User $agent);  // Creates compliance events
CalendarEventService::createManual(array $data);          // Manual event
CalendarEventService::updateFromSource($source);          // Re-syncs events when source changes
CalendarEventService::deleteForSource($source);           // Removes events when source deleted
```

### Model Observers
Each module's model fires calendar events through observers:
- `DealObserver` — on create/update: sync deal dates to calendar
- `LeaseObserver` — on create/update: sync lease dates to calendar
- `ComplianceObserver` — on FFC/training changes: sync deadlines
- `DocumentObserver` — on send-for-signing: create chase events

When a deal's bond deadline changes, the calendar event auto-updates. No manual intervention.

---

## iCal Subscription

Each user gets a unique iCal URL:
`https://corexos.co.za/calendar/ical/{token}.ics`

This URL serves a dynamically generated ICS file with all their calendar events. Google Calendar, Outlook, and Apple Calendar can subscribe to this URL and auto-refresh (configurable, typically every 15-60 minutes).

Benefits:
- Agent sees CoreX events on their phone's native calendar
- No app needed — works with what they already use
- Reminders from both CoreX AND their phone's calendar
- Works offline (events synced to device)

---

## Build Phases

### Phase 1: Foundation (Prompt 1)
- Database tables (events, reminders log, preferences)
- CalendarEvent model with scopes, relationships
- CalendarEventService (create, update, delete, query)
- Manual event CRUD (create personal events)
- Month/Week/Day views (Alpine.js + Blade)
- Agenda view
- Sidebar navigation
- Basic colour coding by event type
- Permissions

### Phase 2: Auto-Generation (Prompt 2)
- Model observers for Deal, Lease, Compliance
- Auto-create events when deals/leases are captured
- Auto-update when dates change
- Auto-delete when source is archived
- Backfill command: generate events for existing data

### Phase 3: Reminder Engine (Prompt 3)
- `calendar:process-reminders` scheduled command (every 15 min)
- In-app notification system (bell icon + count)
- Email reminders (branded templates)
- Reminder preferences per user
- Escalation rules
- Reminders log

### Phase 4: Dashboard + Polish (Prompt 4)
- Dashboard widget (today's events, overdue count)
- iCal subscription endpoint
- Timeline/Gantt view for BMs
- Daily digest email
- Event search
- Recurring events

### Phase 5: Document Integration (Prompt 5)
- Signing chase reminders (24h/48h/72h)
- Document deadline events
- Prospecting module integration
- Portal syndication reminders

---

## Navigation
- Sidebar: "Calendar" — main entry, top-level item (not buried in a submenu)
- Dashboard: compact widget showing today + overdue
- Every relevant detail page (deal, property, contact, lease): "Events" tab showing calendar entries for that record

---

## What Makes This Better Than Monday.com

| Feature | Monday.com | CoreX Calendar |
|---------|-----------|----------------|
| Auto-generated events | ✗ Manual setup per board | ✓ Automatic from deals, leases, compliance |
| Real estate aware | ✗ Generic | ✓ Knows bond deadlines, lease expiries, FICA, mandates |
| Escalation chains | ✗ Basic notify | ✓ Agent → BM → Admin with configurable timelines |
| Regulatory compliance | ✗ Nothing built-in | ✓ FFC, PPRA, FICA, training deadlines tracked automatically |
| iCal sync to phone | ✓ Yes | ✓ Yes — plus phone reminders as backup |
| Per-event reminder config | ✗ Board-level only | ✓ Agency → category → event level |
| Property/contact context | ✗ Generic items | ✓ Every event links to property, contact, deal |
| Signing chase | ✗ Not applicable | ✓ Auto-chase unsigned documents with escalation |
| Cost | R200-800/user/month | ✓ Built into CoreX — zero extra cost |
