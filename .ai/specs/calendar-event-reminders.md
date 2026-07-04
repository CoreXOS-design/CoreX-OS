# Spec — Calendar Event Reminders (AT-178)

> **Status:** Johan-authorized build (AT-164 family). Staging only — NO live promotion.
> **Author:** Claude (cc2 lane, worktree corex-dev-2). Reviewer: Johan.
> Last updated: 2026-07-04

---

## 1. What this feature does & why (business requirement)

A standard-calendar reminder system for Command Center events. Per Johan:

> "like a std calendar — how long before the event do you want a reminder — on screen
> popup, or email... I'm busy loading a property and the popup appears reminding me that
> I have to meet a client in an hour."

The whole point: the reminder **finds the agent wherever they are in CoreX** (loading a
property, in a deal, anywhere) — not only on the calendar page. Two channels, chosen per
event: an on-screen **popup** (in-app toast, default ON) and **email** (default OFF).

## 2. Pillars

- **Agent** (`User`) — reminders are delivered to the users ON an event (owner + agent
  attendees with accounts). Read-back: `calendar_reminders_log` records who was reminded,
  on what channel, whether they read/actioned it.
- **Property / Contact / Deal** — read-only context in the reminder body + deep link
  (the event's linked property/contact/deal). No writes to those pillars.

Reminders are **internal scheduling** — never sent to `Contact` people (no consent surface,
POPIA out of scope). This is deliberate and specced.

## 3. Reconciliation with EXISTING infrastructure (ADAPT, do not rebuild)

The schema was scaffolded ahead of this build. We wire it, we do not duplicate it.

| Piece | State before | This build |
|-------|--------------|------------|
| `calendar_events.send_reminder` (bool) | present, written (default true) | keep as master on/off per event |
| `calendar_events.reminder_offsets` (json) | present, **never populated/read** | populated by form; read by engine |
| `calendar_events.reminder_channels` (json) | **absent** | ADD — per-event channel set |
| `calendar_events.reminders_sent` (json) | present, unused | left unused (superseded by log) |
| `calendar_reminders_log` (table+model) | present, **UNWIRED** | becomes the idempotency ledger; ADD `occurrence_key` |
| `calendar_event_class_settings` | per-agency per-class config | ADD `default_reminder_offsets`, `default_reminder_channels` |
| `agency_contact_settings` | calendar agency config | ADD `calendar_reminder_lead_options` (json) |
| `ProcessReminders` (`command-center:reminders`) | dedups on single event-level `metadata->reminder_sent` | event section rewritten to per-user/channel/occurrence via log |
| `EventDueReminderNotification` | db+mail+fcm | reused for the DB (in-app) channel + FCM |

### The bug this fixes (fix-the-class)
`ProcessReminders` marked a whole event "reminded" with one `metadata->reminder_sent`
flag. Consequences: **only the first user processed** ever got a reminder (breaks per-user
independence), it fired **at most once** per event (no per-offset), and recurring series
reminded **at most once ever** (occurrences 2..N silently got nothing). The
`calendar_reminders_log` table exists precisely to key sends on
`(event, user, channel, offset, occurrence)` — we wire it and delete the flag path.

## 4. Data model / migrations

**Migration `add_reminder_config_and_occurrence_key`:**
1. `calendar_events`: add `reminder_channels` JSON nullable (per-event channels, e.g.
   `["popup"]` or `["popup","email"]`).
2. `calendar_reminders_log`: add `occurrence_key` VARCHAR(16) NOT NULL default `'single'`
   (`'single'` for non-recurring; `YYYYMMDD` of the occurrence start for a recurring
   occurrence). Add UNIQUE index
   `(calendar_event_id, user_id, channel, offset_minutes, occurrence_key)` — the
   idempotency contract. (VARCHAR not-null avoids MySQL's "NULLs are distinct" unique-index
   trap.)
3. `calendar_event_class_settings`: add `default_reminder_offsets` JSON nullable,
   `default_reminder_channels` JSON nullable (agency default reminder per event class).
4. `agency_contact_settings`: add `calendar_reminder_lead_options` JSON nullable
   (agency-configurable lead-time option list). Default constant
   `[0,5,10,15,30,60,120,1440]`.

Re-run `php artisan schema:dump` after (non-negotiable 12a).

### Lead-time semantics
- Offsets are **minutes before start**. `0` = "at time of event". Stored as a sorted
  unique int array on `reminder_offsets`.
- Channels: subset of `['popup','email']`. `popup` → in-app DB notification + toast.
  `email` → mail. Independent toggles.

### Effective resolution (per event, per the doctrine "no hardcoding")
`CalendarEvent::effectiveReminderOffsets()` / `effectiveReminderChannels()`:
1. Per-event value if the user set one (`reminder_offsets` / `reminder_channels` not null).
2. else agency class default (`calendar_event_class_settings.default_reminder_*` for this
   agency+category, falling back to the global NULL-agency row).
3. else system default: offsets `[60]`, channels `['popup']` (popup ON, email OFF — Johan).

`send_reminder=false` → no reminders regardless.

## 5. Delivery engine

`App\Services\CommandCenter\CalendarReminderService` (new, holds the logic; command stays thin).

**Tick cadence:** `command-center:reminders` moved from every-5-min to **everyMinute** so a
`0`-offset ("at time of event") and 5-minute leads are punctual. `withoutOverlapping`.

**Per tick:**
1. Candidate events: `status='pending'`, not soft-deleted, `send_reminder=true`, with a
   start (or any recurring occurrence) within the max look-ahead
   (`max(effective offsets) + 1 min`) of now. Recurring parents are expanded via
   `RecurrenceExpander::expand(parent, now, now+lookAhead)` — occurrences are computed on
   the fly (never persisted), so the engine enumerates them itself. The parent row carries
   the reminder config (occurrence clones strip `reminder_offsets`).
2. For each event/occurrence, for each recipient user (owner + non-declined/-cancelled
   agent invitees), for each effective offset, compute `fireAt = occurrenceStart − offset`.
   Due when `now >= fireAt` AND `now < occurrenceStart + grace` (don't fire reminders for
   an occurrence already well past — grace = the offset window; a `0` reminder for a start
   30 min ago is stale). Precisely: due when `fireAt <= now <= occurrenceStart`
   (never remind after the event has started; a missed tick within the minute still fires
   because `fireAt<=now`).
3. Idempotency: attempt an insert into `calendar_reminders_log` for
   `(event,user,channel,offset,occurrence_key)`. The UNIQUE index guarantees exactly-once —
   a duplicate insert is caught and skipped (double-tick = one send). Insert happens
   **before** dispatch inside the same guarded path so a crash mid-send cannot double-fire.
4. Dispatch per channel: `popup` → `$user->notify(EventDueReminderNotification)` (DB row →
   surfaced by the toast poll) ; `email` → `EventReminderMail` to `$user->email`. FCM push
   rides the popup channel through the existing storm-guarded funnel (unchanged behaviour).
   Every dispatch is failure-isolated (a mail hiccup never blocks the log/other channels).

**Timezone:** all comparisons in app tz (SAST, `config('app.timezone')` = `Africa/Johannesburg`).
`event_date` is stored/cast as datetime in app tz. The near-midnight edge is covered by a test.

**Suppression:** soft-deleted (SoftDeletes default), `status != pending`
(completed/dismissed/overdue), `send_reminder=false`, declined/cancelled invitees — all
yield zero sends. A private event still reminds **its owner only** (never leaks to others;
owner is a recipient regardless).

## 6. Popup toast (global, all pages)

`resources/views/components/reminder-toast.blade.php` — modeled on
`components/portal-lead-toast.blade.php`. `@auth`, fixed bottom-right, `z-[9999]`, own
Alpine component. Injected into BOTH shells next to portal-lead-toast:
`layouts/corex.blade.php` and `layouts/corex-app.blade.php`. Renders on every authenticated
page (property, deal, anywhere) — that is the requirement.

**Poll:** `GET /api/v1/command-center/reminders/due` every 60s (agency
`calendarPollSeconds()`), guarded `!document.hidden`, plus immediate refetch on window
`focus` / tab `visibilitychange` (the calendar RAG-refresh pattern, mirrored — the
calendar's own poll in index.blade is NOT touched). Returns the user's unread popup
reminders (DB notifications of type `event_due_reminder` created since last seen, joined to
the log for read/snooze state).

**Behaviours:** dedupe by id (shown once), dismissible (× → POST mark read), auto-dismiss
after 30s, **10-minute snooze** (POST snooze → hidden, re-surfaces after 10 min),
click "View event →" → deep link `command-center.calendar.show` (opens the event), marks read.

**Endpoints** (all `/api/v1/*`, auth, self-scoped, in the API catalog):
- `GET  /api/v1/command-center/reminders/due` → `{ reminders: [...] }`
- `POST /api/v1/command-center/reminders/{log}/read`
- `POST /api/v1/command-center/reminders/{log}/snooze` (10 min)

Self-scoped: a user only ever sees/acts on their own `calendar_reminders_log` rows
(enforced in the controller by `where('user_id', auth id)` + agency scope on the model).

## 7. Email channel

`App\Mail\CommandCenter\EventReminderMail` — clean template
`resources/views/emails/command-center/event-reminder.blade.php`, CoreX design language.
Subject `Reminder: <title> — <when>`. Body: title, start time (SAST, humanised lead
"in 1 hour"), linked property/contact, deep link to the event. Deep link targets the
specific occurrence date for recurring events.

## 8. Form UI (New / Edit event)

- New partial `resources/views/command-center/calendar/partials/reminder-fields.blade.php`
  holds ALL reminder markup (lead-time `<select>` built from the agency option list +
  humanised labels; popup + email toggles; a "no reminder" affordance via the master
  `send_reminder`). Included with ONE line inside the existing `<form>` in
  `index.blade.php`. **No cockpit geometry/windowing/layers/deck touched** (cc3 owns those).
- `index.blade.php` form edits confined to: the `form:{}` object (add `sendReminder`,
  `reminderOffset`, `reminderPopup`, `reminderEmail` keys), the 3 form initializers
  (openBlank/openForDate/openEditModal), consistent with every existing field. Native
  full-page POST already submits any `name=`d input.
- Defaults on a blank form come from the selected event **type**'s agency default (via the
  `#classConfigMap` island, extended with the class's `default_reminder_*`), falling back
  to popup-ON/email-OFF/60-min.
- `show()` JSON extended with `send_reminder`, `reminder_offsets`, `reminder_channels` for
  edit round-trip.
- `store()`/`update()` validation extended:
  `send_reminder` boolean; `reminder_offset` integer in the agency option list (single
  lead-time in the UI → stored as a 1-element `reminder_offsets` array; the schema supports
  multiple for future/mobile); `reminder_popup`/`reminder_email` booleans → assembled into
  `reminder_channels`. Passed through `CalendarEventCreator::create()` and `update()`'s
  whitelist.

Input-space (BUILD_STANDARD §2/§3):
- No channel selected but `send_reminder` on → treated as popup (never a silent no-op that
  looks enabled); OR the master toggle is what governs — if both channels off we set
  `send_reminder=false` and show it off. Decision: **both channels off ⇒ send_reminder=false**
  (prevent the "enabled but goes nowhere" state).
- Offset not in the option list → validation rejects with a clear message.
- Missing reminder fields entirely (legacy/mobile/API) → fall through to effective defaults;
  never 500.

## 9. Permissions & navigation

- Reminders are **personal + self-scoped** — a user only ever receives/sees their own. The
  poll/read/snooze endpoints gate on `auth` and filter by the authenticated user (mirrors
  the existing `/api/v1/notifications` endpoints, which are auth-only, not permission-gated).
  The event itself is already permission-gated (`command_center.calendar.view`) on the
  deep-link.
- Navigation: the toast is global (no nav entry needed — it comes to the user). The reminder
  config lives inside the existing New/Edit event form (already navigable). Agency defaults
  live in existing calendar settings surfaces (class settings + agency contact settings).

## 10. Acceptance criteria / test matrix (BUILD_STANDARD §5)

Feature tests (`tests/Feature/CommandCenter/`):
1. **Idempotency** — two consecutive ticks in the due window ⇒ exactly one log row / one
   notification per (user,channel,offset).
2. **Per-user independence** — owner + agent attendee both get their own reminder; marking
   one read/snoozed doesn't affect the other.
3. **Channel routing** — popup-only event ⇒ DB notification, no mail; email-on event ⇒ mail
   sent (Mail::fake assertion); both ⇒ both.
4. **Recurring per-occurrence** — a weekly series reminds the SECOND occurrence (not just
   the first), keyed by occurrence_key; first-occurrence log doesn't suppress the second.
5. **Suppression** — dismissed/completed event, soft-deleted event, `send_reminder=false`,
   declined invitee ⇒ zero sends.
6. **Timezone edge** — event at 00:15 SAST with a 30-min lead fires at 23:45 the prior day,
   correctly, no off-by-a-day.
7. **Effective resolution** — per-event overrides class default overrides system default.
8. **Endpoint** — `due` returns only the auth user's unread popup reminders; read/snooze
   mutate only own rows; snooze hides for 10 min.
9. **Form round-trip** — store persists offsets+channels; show() returns them; update edits
   them; both-channels-off ⇒ send_reminder false.
10. **Fillable guard** — `CalendarEventClassSettingFillableTest` still green after new columns.

Headless proof (puppeteer, `tests/js/`):
- Seed a due popup reminder; load a **property page** (non-calendar); assert the toast
  appears; assert "View event →" href = the event deep link. Screenshot evidence to
  `.ai/audits/`.

## 11. Files to create / modify

**Create:**
- `database/migrations/2026_07_04_*_add_reminder_config_and_occurrence_key.php`
- `app/Services/CommandCenter/CalendarReminderService.php`
- `app/Http/Controllers/Api/CommandCenter/ReminderController.php`
- `app/Mail/CommandCenter/EventReminderMail.php`
- `resources/views/emails/command-center/event-reminder.blade.php`
- `resources/views/components/reminder-toast.blade.php`
- `resources/views/command-center/calendar/partials/reminder-fields.blade.php`
- tests: `EventReminderEngineTest.php`, `EventReminderEndpointTest.php`,
  `EventReminderFormTest.php`; `tests/js/reminder-toast-proof.cjs`

**Modify:**
- `app/Models/CommandCenter/CalendarEvent.php` (fillable/cast + effective helpers + recipients)
- `app/Models/CommandCenter/CalendarReminderLog.php` (occurrence_key fillable)
- `app/Models/CommandCenter/CalendarEventClassSetting.php` (fillable/cast)
- `app/Models/AgencyContactSettings.php` (lead-options accessor + default constant)
- `app/Console/Commands/CommandCenter/ProcessReminders.php` (event section → service)
- `routes/console.php` (everyMinute)
- `routes/api.php` (3 endpoints under v1)
- `app/Http/Controllers/CommandCenter/CalendarController.php` (store/update validation,
  show JSON, classConfigMap columns)
- `app/Services/CommandCenter/Calendar/CalendarEventCreator.php` (persist reminder fields)
- `resources/views/command-center/calendar/index.blade.php` (form subtree ONLY:
  @include + form keys)
- `resources/views/layouts/corex.blade.php`, `layouts/corex-app.blade.php` (toast include)
- `database/schema/mysql-schema.sql` (schema:dump)

## 12. Coordination note (cc3 lane)

cc3 is actively rewriting `index.blade.php` cockpit geometry/windowing/layers/deck. This
build touches `index.blade.php` ONLY in the event-form subtree (form object keys + one
@include). Rebase on latest origin/Staging before every push; expect cc3 merges mid-build;
keep the index.blade footprint minimal and localized to reduce conflicts.
