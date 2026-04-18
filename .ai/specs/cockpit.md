# CoreX Cockpit — Dashboard Redesign Spec
> Evolution of `spec-command-center.md` — replaces the density-first dashboard with an action-first cockpit.
> Version: 1.0 — 2026-04-18
> Status: DRAFT — Phase 1 approved by Andre for build

---

## 1. Why This Spec Exists

The current Command Center dashboard (`/corex`) ships the right data but the wrong hierarchy. Agents logging in see stat cards, a momentum chart, overdue popups, scorecards, property health, candidate docs, and mini-calendar — 18+ data blocks stacked vertically, competing for attention.

Agents open CoreX to answer one question: **"What do I do now?"** Performance scorecards, YTD momentum, and branch comparisons are motivational but not operational — they belong in a Performance tab, not the daily-driver screen.

The cockpit is the **single pane of glass** that connects to every CoreX module. It is action-first, not data-first. Every item on screen must be something the agent can act on — complete, snooze, reassign, open. Passive information moves off the main screen.

Reference: "Advanced calendar / Monday.com for real estate agents."

---

## 2. Pillar Connections

| Pillar | Reads | Writes |
|--------|-------|--------|
| **Property** | Timeline events, Inbox items (mandate expiry, missing docs, health alerts), address display | Property activity log, health score updates on action completion |
| **Contact** | Timeline events linked to contacts, Inbox items (FICA chase, follow-ups, birthdays) | Activity notes on completion |
| **Deal** | Timeline events, Inbox items (bond deadline, OTP chase, commission follow-up) | Deal step advancement on action completion |
| **Agent** | Scorecard (now on Performance page), streak, activity points, working hours preference | Agent scorecard recalc on action completion |

**Rule:** Every Timeline item and every Inbox item must resolve to at least one pillar. No free-floating alerts.

---

## 3. Architecture — Two Layers

```
┌────────────────────────────────────────────────────────────┐
│  COCKPIT VIEW (command-center/dashboard.blade.php)           │
│                                                              │
│  [Cmd+K quick-add bar]                          [streak chip]│
│                                                              │
│  ┌──── TIMELINE (left, 2/3) ────┬──── INBOX (right, 1/3) ──┐│
│  │ Today / Tomorrow              │ Action queue              ││
│  │ Hour-by-hour                  │ Overdue + urgent          ││
│  │ Events + tasks inline          │ Per-item inline actions   ││
│  └───────────────────────────────┴────────────────────────────┘│
│                                                              │
│  [Performance summary strip: score • streak • points]        │
│  [View Performance →]                                        │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│  PERFORMANCE VIEW (command-center/performance.blade.php)     │
│                                                              │
│  Scorecard (full) • Property Health • Momentum chart         │
│  Candidate Documents (if supervisor) • Branch comparison     │
└────────────────────────────────────────────────────────────┘
```

---

## 4. Phase 1 — What Gets Built Now

### 4.1 Cockpit Dashboard (`command-center/dashboard.blade.php` — rebuild)

**Header (compact — 1 row):**
- Greeting + date (existing)
- "Quick Add" primary button (opens modal — task OR event toggle)
- "Full Calendar" and "Tasks" secondary links (existing)

**Left column — Today Timeline (2/3 width):**
- Tabs: **Today** (default) / **Tomorrow** / **This Week**
- Hour-by-hour vertical stripe, 7am–7pm (configurable later via user prefs)
- Render events (from `$todayEvents`) and tasks with `due_date` today (from `$myTasks`) placed at their scheduled time
- All-day items pinned at the top of the column
- Tasks without a specific time bucketed into "Unscheduled" at the bottom
- Each row: time • colored type stripe • title • pillar link (property/contact) • inline actions (Complete ✓ / Reschedule / Open)
- Empty state: "Nothing scheduled — your day is clear. [+ Add Event] [+ Add Task]"

**Right column — Action Inbox (1/3 width):**
- Header: "Inbox" with count badge
- Card list, ordered by urgency:
  1. Overdue tasks (with inline Complete / Reschedule +X days / Did not happen)
  2. Overdue events (same inline actions)
  3. Candidate Documents awaiting authorisation (if supervisor) — inline "Review & Authorise" button
  4. (Phase 2) Expiring mandates, FICA chase, birthday, Ellie suggestions
- Each card is actionable — no "acknowledge and read later." Actions resolve items off the Inbox immediately.
- Empty state: "Inbox clear. You're on top of things."

**Footer — Performance strip:**
- Thin row, not a panel
- Score badge • streak days • MTD points / target progress bar
- Single link: "View Performance →"

**Remove from dashboard (moved to Performance page):**
- Full scorecard panel
- Properties Needing Attention panel
- Momentum 7-day chart (belongs on Performance — it's historical, not actionable)
- Candidate documents big banner — moves to Inbox as compact cards
- Overdue auto-popup on load (items stay in Inbox; no modal takeover)

### 4.2 Performance Page (new)

Route: `GET /corex/command-center/performance` → `command-center.performance`
Controller: `DashboardController::performance(Request $request)`
View: `resources/views/command-center/performance.blade.php`

Content:
- Scorecard panel (full — all metrics, all bars) — moved verbatim from dashboard
- Properties Needing Attention (full list with filters) — moved verbatim
- Activity Points + monthly target progress — with 7-day momentum chart (phase 2 can add it)
- Candidate Documents Review section (supervisors only) — full list
- Branch comparison (phase 2)

Permissions: `view_dashboard` (same as dashboard — no new permission for Phase 1).

### 4.3 Sidebar Navigation

File: `resources/views/layouts/corex-sidebar.blade.php`

Dashboard group (reorder + add one):
- **Today** → `/corex` (renamed from "Overview")
- **Calendar** → `/corex/command-center/calendar` (unchanged)
- **Tasks** → `/corex/command-center/tasks` (unchanged)
- **Performance** → `/corex/command-center/performance` (new)
- **User Settings** → `/corex/command-center/user-settings` (unchanged)

### 4.4 Quick-Add Modal

Single modal with toggle: Task ↔ Event.
- Existing task modal fields (title, priority, due date, reminder)
- Existing event modal fields (title, event_date, priority, reminder)
- Keeps POST to `command-center.tasks.store` / `command-center.calendar.store`
- No AI natural-language parsing in Phase 1

---

## 5. Phase 2 — Deferred

Documented here so scope is clear. Do not build in Phase 1.

1. **Command palette (Cmd+K)** — type "Book viewing 14 Seaview Fri 4pm" → parsed into event with property link. Uses Ellie / OpenAI for parsing.
2. **Drag-to-schedule** — Inbox card dragged onto Timeline slot reschedules the task/event.
3. **Keyboard shortcuts** — `t` new task, `e` new event, `/` search, `g d` go dashboard, etc.
4. **Customisable widget library** — agents pin/hide widgets on Performance and below the cockpit (Monday.com vibe).
5. **Unified reminder engine** — replaces 5 per-module panels on user-settings with rule syntax ("Remind me 24h before any FICA doc expires via in-app + email").
6. **Automation rule builder UI** — expands on the existing `automation_rules` table (20 seeded rules) with an admin-editable form, custom triggers, custom actions.
7. **Phone-parity notifications** — WhatsApp channel, push via mobile app.
8. **Density toggle** — Compact / Comfortable switch in header.

---

## 6. Data Model

**No new tables in Phase 1.** The cockpit reads entirely from existing Command Center infrastructure:
- `calendar_events`, `command_tasks` — Timeline + Inbox source
- `property_health_scores`, `agent_scorecards` — Performance page source
- `user_dashboard_settings` — already has `calendar_view`, `working_hours_start`, `working_hours_end`, `show_weekends` fields

Phase 2 will add:
- `cockpit_widgets` (user_id, widget_key, sort_order, config_json) — for pinnable widgets
- Extensions to `automation_rules` for user-authored rules

---

## 7. User Flow (Phase 1)

```
1. Agent opens /corex
   → Sees Today's Timeline + Action Inbox
   → Overdue items live in the Inbox (not a popup)
   → Performance strip at bottom: quick glance at score/streak

2. Agent clicks "Complete" on an Inbox item
   → Item disappears
   → Scorecard updates (recalc on next scorecard job)
   → Activity point logged if applicable

3. Agent clicks Timeline event
   → Navigates to event detail OR inline quick-actions (Complete/Reschedule)

4. Agent clicks "Quick Add"
   → Modal with Task/Event toggle
   → Fills fields → POSTs to existing endpoints

5. Agent clicks "View Performance →"
   → Navigates to /corex/command-center/performance
   → Full scorecard, property health, candidate docs (if supervisor)
```

---

## 8. Permissions

Phase 1: No new permission keys. Both dashboard and performance use `view_dashboard` (existing).

Phase 2 may add:
- `command_center.automation.create_custom` — let agents author their own automation rules
- `command_center.performance.view_branch` — BMs viewing branch comparison

---

## 9. Acceptance Criteria (Phase 1)

Cockpit is done when:

1. **Dashboard loads** with Timeline (left) + Inbox (right) + compact Performance strip (bottom). No auto-popup on load.
2. **Timeline** shows today's events AND today's tasks combined, ordered by time, with all-day items pinned at top and unscheduled tasks at the bottom.
3. **Inbox** shows overdue tasks, overdue events, and candidate documents (for supervisors) — all with inline resolve actions (Complete / Reschedule / Did not happen / Authorise).
4. **Empty states** are warm and directive, not blank gray panels.
5. **Quick-Add** modal creates a task or event using existing endpoints.
6. **Performance page** exists at `/corex/command-center/performance` with Scorecard, Property Health, Candidate Docs review — all content that used to clutter the dashboard.
7. **Sidebar** shows Today / Calendar / Tasks / Performance / User Settings under the Dashboard group.
8. **No breaking changes** to `CalendarEventService`, `TaskService`, `PropertyHealthCalculator` — view/controller changes only.
9. **Responsive**: Timeline/Inbox stack vertically on mobile (Timeline first, Inbox second).
10. **dev-check.ps1 passes** with 0 new failures.

---

## 10. Files to Create / Modify

### Create
```
resources/views/command-center/performance.blade.php    — new Performance page
resources/views/command-center/partials/
    timeline.blade.php                                  — Timeline column partial
    inbox.blade.php                                     — Action Inbox column partial
    quick-add-modal.blade.php                           — Unified task/event modal
    performance-strip.blade.php                         — Footer performance strip
.ai/specs/cockpit.md                                    — this file
```

### Modify
```
resources/views/command-center/dashboard.blade.php      — rebuild into cockpit layout
resources/views/layouts/corex-sidebar.blade.php         — rename Overview → Today, add Performance
app/Http/Controllers/CommandCenter/DashboardController.php — add performance() method
routes/web.php                                           — add command-center.performance route
```

### Untouched
```
app/Services/CommandCenter/*                             — no service changes
app/Models/CommandCenter/*                               — no model changes
database/migrations/*                                    — no schema changes
```

---

## 10.5 Phase 1.5 — Density + Archival + Pillar Tags (shipped 2026-04-18)

Shipped as a follow-up to Phase 1 based on agent feedback.

### 10.5.1 Compact Tasks board
- Card padding reduced (`p-3` → `p-2`), gaps tightened, typography 1pt smaller
- Column header padding reduced; inline action icons downsized to 12px
- Priority + pillar chips on a single metadata row above the title
- Status/priority badges use `9px` uppercase letter-spacing chips (fewer pixels, more scannable)
- Footer action buttons (next-status arrow, complete) icon-only, 12px

### 10.5.2 Pillar tags on Today + Tasks
- Added `pillarTag()` on `CommandTask` and `CalendarEvent` models. Returns `'property' | 'deal' | 'contact' | null`. Priority: `property_id` → `deal` (by `deal_id` or `event_type`) → `contact_id`.
- Timeline rows render a colored chip (Property orange / Deal blue / Contact purple) next to priority and kind.
- Tasks kanban + list cards render the same chip, matching colour.
- A new "Group by" toggle on the Tasks kanban switches columns from **Status** (default) to **Pillar** (Property / Deal / Contact / Other). Same cards, different grouping.

### 10.5.3 Archive Done + Archived view
- **Clear Done button** (manual) on Tasks page header → soft-deletes all current Done-column tasks for the user, with a confirm dialog.
- **Archived view** at `/corex/command-center/tasks/archived` → lists soft-deleted tasks grouped by `deleted_at` date (Today / Yesterday / Friday 14 Apr 2026 etc.).
- **Restore** button per archived row → `restore()` puts it back on the Done column.
- Everything follows the no-hard-deletes rule: "archived" = soft-deleted.

### 10.5.4 Auto-archive setting (per user, overridable by agency)
- New column `auto_archive_done_days` on `user_dashboard_settings` and `agency_dashboard_settings` (unsigned smallint, nullable).
- **null** = never auto-archive, **0** = archive immediately when marked Done, **N** = archive N days after completion.
- New "Task Board" section on user-settings page with the numeric input + explanatory help.
- New scheduled command `command-center:archive-done-tasks` runs daily at 03:00 (added to `routes/console.php`).
- Agency override: when agency `dashboard_settings_mode = 'agency'`, the agency's value wins.

### 10.5.5 Files added / modified in Phase 1.5

Added:
```
database/migrations/2026_04_18_100001_add_auto_archive_done_to_dashboard_settings.php
app/Console/Commands/CommandCenter/ArchiveDoneTasks.php
resources/views/command-center/tasks/archived.blade.php
resources/views/command-center/partials/task-card.blade.php
```

Modified:
```
app/Models/CommandCenter/UserDashboardSetting.php       — fillable + defaults
app/Models/CommandCenter/AgencyDashboardSetting.php     — fillable
app/Models/CommandCenter/CommandTask.php                — pillarTag()
app/Models/CommandCenter/CalendarEvent.php              — pillarTag()
app/Http/Controllers/CommandCenter/TaskController.php   — archiveDone, archived, restore
app/Http/Controllers/CommandCenter/UserSettingsController.php — validate + save auto_archive_done_days
resources/views/command-center/tasks/index.blade.php    — compact + group-by + tag chips + archive buttons
resources/views/command-center/partials/timeline-row.blade.php — pillar chip
resources/views/command-center/user-settings.blade.php  — new Task Board section
routes/web.php                                          — archived, archive-done, restore routes
routes/console.php                                      — daily 03:00 schedule
```

---

## 11. Mobile App Parity

The CoreX mobile app consumes the same dashboard data. The mobile app must mirror this cockpit direction:
- Today Timeline as the primary screen
- Action Inbox as a swipe-left or tab
- Performance moved to its own tab (bottom-nav)
- No passive notifications bell — actionable items only, in the Inbox

A separate build prompt will be issued for the mobile app codebase once Phase 1 web is shipped and audited.

---

## 12. Standards Alignment

- **Non-Negotiable #2** (every new page has nav entry): Performance link added to sidebar same day as page is built. ✓
- **Non-Negotiable #4** (pillars are the spine): Timeline + Inbox items all carry pillar links (property/contact/deal). ✓
- **Non-Negotiable #5** (permissions): Phase 1 reuses `view_dashboard` — no orphans. ✓
- **Non-Negotiable #6** (production quality): No feature flag, no "later." Ships complete or does not ship. ✓
- **STANDARDS UX** (status always visible): Timeline colour stripes + Inbox urgency ordering keep state visible. ✓
- **STANDARDS UX** (mobile awareness): Column stack on mobile. ✓
