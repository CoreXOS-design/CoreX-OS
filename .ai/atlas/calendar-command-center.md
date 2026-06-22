# Atlas — Calendar / Command Center

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: cross-cutting — surfaces Deal, Property, Contact, Agent, Compliance, Payroll/Leave events on one
> calendar. Cited audit: `calendar_state_audit_2026-05-04.md`. Cited tickets: AT-66/69/70 (viewing feedback),
> M2/M3 (links pivot, leave matrix).

---

## 1. WHAT IT DOES

The Calendar is the agency's single time-axis: it auto-surfaces deadlines and milestones from every pillar
(mandate/lease/FFC/PI expiry, deal step deadlines, payroll runs, SARS submissions, birthdays, showdays,
leave) as colour-coded (RAG) events, plus manual events (viewings, meetings, tasks). Each event belongs to
an **event class** whose per-agency config controls its RAG thresholds, who can see it, and who gets
notified. The Command Center wraps it with Today/Tasks/Reporting. The **viewing-feedback arc** (AT-66/69/70)
captures per-contact/per-property feedback after a viewing and fans out cross-agent notifications.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`, group `command-center`, controllers imported `:1058-1063`)
| Route | Name | Handler |
|-------|------|---------|
| `/command-center/Today` `:1069` | `command-center.today` | `DashboardController::today` |
| `/calendar` `:1133` | `command-center.calendar` | `CalendarController::index` |
| `/calendar/events` `:1134` | `.calendar.events` | `::events` |
| `/calendar/invitations` `:1137` | `.calendar.invitations` | inline |
| `/calendar/check-conflicts` `:1191` | `.calendar.check-conflicts` | (declared before `{calendarEvent}` wildcard) |
| `/calendar/{calendarEvent}` `:1196` | `.calendar.show` | `::show` |
| store/update/destroy `:1197-1199`; complete/dismiss/reschedule `:1200-1202` | — | — |
| `/calendar/{e}/feedback` show/store `:1203-1204` | `.calendar.feedback.*` | `::showFeedback` `:586` / `::storeFeedback` `:702` (the viewing-feedback arc) |
| tasks `:1213-1221` | `command-center.tasks*` | `TaskController` |
| event-class settings `:1290-1292` | `command-center.settings.event-classes` | `SettingsController::eventClasses` `:65` |

Controllers (`app/Http/Controllers/CommandCenter/`): `CalendarController.php` (core, 88KB — `index:33`,
`events:405`, `storeFeedback:702`, `store:923`, `syncEventLinks:1460`, `propertyOwners:1636`),
`DashboardController.php` (`today:27`, `resolveEvent:331`), `TaskController.php`, `SettingsController.php`
(`eventClasses:65`, `updateEventClass:102`, `resetEventClass:189`). Views
`resources/views/command-center/` (`calendar/index.blade.php`, `settings/event-classes.blade.php`,
`feedback/show.blade.php`). Nav `corex-sidebar.blade.php`: Today `:355`, Calendar `:356`, Tasks `:357`,
Invitations `:360`.

---

## 3. THE EVENT-CLASS SYSTEM

**~47 event-class slugs** (the "38" was an undercount), seeded in
`database/seeders/CalendarEventClassSeeder.php` — e.g. `mandate_expiry:73`, `lease_expiry:93`,
`ffc_expiry:113`, `pi_insurance_expiry:133`, `deal_step_deadline:173`, `deal_registration_target:193`,
`fica_renewal_due:213`, `payroll_run:233`, `sars_emp201:253`, `rmcp_review_due:293`, `property_showday:435`,
`signature_expiry:455`, `agent_birthday:717`, `contact_birthday:738`, `viewing:842`, `listing_presentation:888`,
`meeting:908`, `task:928`, `leave_annual:974`, `leave_sick:995`. **Config is entirely DB-driven** (no
`config/*.php`) — `calendar_event_class_settings` rows + a global `agency_id IS NULL` default row.

> ⚠ The resolvers key on the `calendar_events.category` column (the slug), **not** a column literally
> named `event_class`.

### The three resolver services (`app/Services/CommandCenter/Calendar/`)
- **`CalendarThresholdResolver`** — `resolve()` `:22` returns RAG (`red`/`amber`/`green`/`neutral`) from the
  class config thresholds vs `daysUntil`; overdue → always `red` `:37`; `resolveForEvent()` `:67` forces
  informational classes (leave/birthdays) to `neutral` `:74-77`. Per-event RAG overrides only for
  `deal_step_deadline` (from `deal_step_instances.rag_*_days`) `:101-124`.
- **`CalendarVisibilityResolver`** — `canSee(event,user)` `:27` resolution order: agency isolation guard
  `:30-33` → super_admin bypass `:36` → admin/owner same-agency `:41` → creator `:46` →
  invitation attendee `:52-58` → leave matrix (`AgencyLeaveVisibilityMatrix`) `:62-83` → else role must
  appear in the resolved colour's visibility list `:85-95`. `filterVisible()` `:101`; role-widening
  bm→branch_manager / admin→owner/super_admin `:141-146`.
- **NotificationDispatcher — TWO distinct services:**
  - `CalendarNotificationDispatcher` — RAG-transition alerts. `onColourTransition()` `:26` (no-op if
    unchanged `:31`); reads `config->notificationsFor(newColour)` `:40`; sends `EventDueReminderNotification`
    over database/mail. **No push/FCM.**
  - `CommandCenter/NotificationDispatcher` (generic) — `fire()` `:28` used by the cross-agent feedback arc;
    honours user prefs `:30`, **open-hours gate drops with no defer** `:44`, idempotency + cooldown
    `:52-63`, sends `PillarEventNotification` over database/mail **and FCM push** `:105-114`.

### Event creation / scheduling
- **Auto/source events:** nightly `corex:calendar:reconcile` (`ReconcileCalendarEvents.php`): `syncAll()`
  per source `:53`, `upsertEvent()` on unique `(source_type, source_id, category)` `:96-104`, then
  `detectAndFireTransitions()` `:107` fires `onColourTransition` on RAG change `:125`,
  `cleanupSyntheticOrphans()` soft-deletes aged synthetic rows `:159`.
- **Manual events:** `CalendarController::store` `:923`, validated against `MANUAL_CREATABLE_CLASSES` `:22`;
  links written by `syncEventLinks` `:1460`.

---

## 4. THE VIEWING-FEEDBACK ARC (AT-66/69/70)

Endpoints `.calendar.feedback.show/store` `:1203-1204`. **Capture** — `CalendarController::storeFeedback`
`:702`: 403 if `!canSee()` `:705`; branches on `feedback_kind` `:709` — `listing_presentation` →
**per-property** rows `:744-761`; `viewing` → **per-contact** rows `:766-785`. `updateOrCreate` keyed
`(calendar_event_id, contact_id, property_id)` `:767-771` (the per-contact/per-property uniqueness). Audit
row `feedback_captured` `:801-807`. Buyer fan-out (viewings only) writes `BuyerActivityLog` `:819-832`,
upserts `buyer_property_views` `:836-845`, bumps `contact.last_activity_at` `:847`.

**Cross-agent notification (AT-70)** `:866-917`: per touched property, resolve `Property::with('agent')`,
**skip when listing agent == capturing user** `:889-890`, then
`NotificationDispatcher::fire($property->agent, 'property.feedback_captured', …)` `:900-910` → action URL
`corex.properties.show#recent-viewings-feedback`. Wrapped in try/catch so a dispatch failure can't roll
back the capture `:911-915`. Recompute: `buyer-views:recompute` (`RecomputeBuyerPropertyViews.php`) rebuilds
`buyer_property_views` from `calendar_event_feedback`; consumed by `PropertyMatchScoringService.php:231-234`.

---

## 5. DATA READ / WRITTEN

| Table | Key columns | SoftDeletes / audit |
|-------|-------------|---------------------|
| `calendar_events` (`2026_03_31_300001`) | `event_type` (8 pillar buckets), **`category`** (class slug — what resolvers key on), `event_date`/`end_date`, `colour`, `nullableMorphs('source')`, `property_id`/`contact_id`/`branch_id`/`agency_id`, `reminder_offsets` | **SoftDeletes** `:47-48` |
| `calendar_event_links` (M2.2, `2026_05_05_000001`) | morph `linkable`, roles subject_property/attendee/related_deal | **SoftDeletes** + BelongsToAgency |
| `calendar_event_feedback` (`2026_05_05_000005`) | `contact_id` nullable, `feedback_kind`, `visibility`, `concern_option_ids`, `kind_specific_data` | **SoftDeletes**; unique relaxed by `2026_05_06_000009` for per-property rows |
| `calendar_event_audit_log` (`2026_05_05_000006`) | `action`, `old/new_values`, `performed_by_user_id` | **No SoftDeletes — append-only** |
| `buyer_property_views` (`2026_05_05_000020`) | `contact_id`, `property_id`, `last_viewed_at`, `view_count`, `most_recent_feedback_id` | No SoftDeletes (derived cache) |

Model `CalendarEvent.php` (`SoftDeletes, BelongsToAgency, BelongsToBranch` `:20`; `source():MorphTo` `:81`;
`linkedProperties/Contacts/Deals` via the links pivot `:125-146`; `auditEntries()` `:162`).

---

## 6. WHAT FEEDS THE CALENDAR (8 sources)

Registered `AppServiceProvider.php:155-163`, all `CalendarSourceContract::syncAll()`, reconciled nightly:
- **Deals** `DealCalendarSource.php`: `deal_step_deadline` from `deal_step_instances` `:42`,
  `deal_registration_target` from `deals.expected_registration` `:88-108`.
- **Compliance** `ComplianceCalendarSource.php`: `ffc_expiry` from `users.ffc_expiry_date` `:44-55`,
  `pi_insurance_expiry`, `tax_clearance_expiry`, `fica_renewal_due` (10 classes).
- **Payroll/Leave** `PayrollCalendarSource.php` (7 classes): `payroll_run` from `payroll_runs.pay_date`,
  SARS submission dates. Leave events come via `PeopleCalendarSource` (`leave_cycle_end`) +
  `LeaveCalendarService` (see `payroll-leave.md`).
- **People** `PeopleCalendarSource.php`: `agent_birthday` from `users.date_of_birth`, `contact_birthday`
  **opt-in only** (`contacts.birthday_reminder`) `:69-83`, anniversaries.
- **Property/Rental/Document/Recurring** sources (showdays, rent, signature expiry, recurring).
- **Viewings & presentations are NOT auto-sourced** — `viewing`/`listing_presentation` are manual classes;
  feedback drives the buyer arc.

---

## 7. AGENCY PER-EVENT-CLASS SETTINGS

`app/Models/CommandCenter/CalendarEventClassSetting.php` (`calendar_event_class_settings`,
`2026_04_30_142935`): per-colour visibility arrays `green/amber/red_visibility` + notification routing
`green/amber/red_notifications` (casts `:61-66`), threshold days `green/amber/red_days`/`show_days`
`:57-60`, `event_nature` (actionable/informational), `daily_digest_*`, `actor_role`,
`allow_multiple_properties`, `buyer_facing`. Resolution `forAgencyAndClass()` `:78` bypasses the agency
scope `:80`, prefers the agency row, falls back to the global `agency_id IS NULL` default `:83-88`.
Configured via `SettingsController` (`updateEventClass` upsert keyed agency_id+event_class `:158-159`,
`resetEventClass` deletes the override). UI `settings/event-classes.blade.php`.

---

## 8. KNOWN FRAGILITIES

1. **Audit FAIL #1 — broken Alpine root `<div>`** at `calendar/index.blade.php:47` (missing closing `>`),
   the likely single root cause for all broken calendar interactivity (Add Event, shortcuts, drag-to-create,
   feedback modal). Backend is fine; this is purely the view tag. **(TODO: verify fixed on current branch.)**
2. **Audit FAIL #2 — `store()` historically didn't write `calendar_event_links`**, breaking
   `linkedContacts()`/feedback button on user events. Current `store()` calls `syncEventLinks`
   (`CalendarController.php:1460`) — **(TODO: verify resolved).**
3. **View-As vs Switch-User scope gotcha** (STANDARDS.md Known Limitations). `CalendarVisibilityResolver`
   relies on `effectiveAgencyId()`/`effectiveRole()` `:30,69` — exactly what "View As" does NOT change
   (`Auth::user()` unchanged), so View-As testing gives WRONG calendar results. **Test calendar visibility
   with "Switch User" (impersonation), never "View As".**
4. **Visibility resolver edge cases.** Leave-matrix branch needs class informational AND `category`
   string-contains `"leave"` AND `user_id`+`agency_id` set `:63-65` — a leave event missing `user_id` falls
   through to generic class visibility. Global rows (`event->agency_id = null`) bypass the isolation guard
   `:31` → visible to all agencies. Empty visibility role list ⇒ hidden from everyone except
   creator/admin/invitees.
5. **Notification dispatch is lossy.** `CalendarNotificationDispatcher` only fires on a colour transition
   and only for the new colour's routing — empty `*_notifications` silently no-ops `:41`, no push. The
   cross-agent feedback alert goes through the generic `NotificationDispatcher`, whose **open-hours window
   can drop the notification entirely with no defer/queue** (`:44`) — a feedback alert captured outside the
   agent's hours is lost until a later identical trigger.
6. **`calendar_event_class_settings` `$fillable` drift** (model comments `:22-48`): migration-added columns
   were historically missing from `$fillable`, so the seeder's `updateOrCreate` silently dropped them and
   rows fell back to defaults. Guarded now by a fillable regression test — recurring footgun for new columns.
7. **`buyer_property_views` is a derived cache** (no SoftDeletes); if the `storeFeedback` sync loop
   `:835-845` and `RecomputeBuyerPropertyViews` diverge, view counts drift — the recompute is source of truth.

---

## Key file:line index
- `app/Http/Controllers/CommandCenter/CalendarController.php` — `:33` index, `:405` events, `:702` storeFeedback, `:866-917` cross-agent notify, `:923` store, `:1460` syncEventLinks.
- `app/Services/CommandCenter/Calendar/CalendarThresholdResolver.php:22-124`, `CalendarVisibilityResolver.php:27-146`, `CalendarNotificationDispatcher.php:26-93`.
- `app/Services/CommandCenter/NotificationDispatcher.php:28-114` (generic, FCM).
- `app/Console/Commands/CommandCenter/ReconcileCalendarEvents.php:38-159`; `RecomputeBuyerPropertyViews.php`.
- `app/Models/CommandCenter/CalendarEvent.php:20-162`, `CalendarEventClassSetting.php:57-108`.
- Seeder `database/seeders/CalendarEventClassSeeder.php`; audit `.ai/audits/calendar_state_audit_2026-05-04.md`.
