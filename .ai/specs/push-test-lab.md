# Push Test Lab and rentals phone notifications (AT-508)

Johan, 26 Oct 2026: "I need notifications for the app for when anything is sent with the rentals ... to the correct user (a fault reported by a lessee, the agent gets a notification on their phone) ... build me a new section in system dev where I can send a test notification to a certain user's cell ... add all types of notifications the app is supposed to receive/send".

## 1. What was wrong (verified on Staging, 26 Oct 2026)

- Tenant / owner phones: already served (`RentalClientPushService`, `config/rental-push.php`), but there was NO push when an inspection report became readable in their app.
- Agent phones: the app registers its token and `NotificationDispatcher` pushes any alert whose catalogue row has `supports_push = 1` — but **all 40 rentals alert types were registered `supports_push = 0`** (in-app + email only). So a fault reported by a lessee, a quote, a notice, "tenant says not fixed" never buzzed an agent's phone. 23 of 90 types (leads, tasks, events, deals, leave...) did.
- A push carried only `event_key`, `subject_type` (a PHP class name) and `subject_id`: nothing the app could use to open the right screen.

## 2. Agent phones (the fix)

Migration `2026_12_07_100000_turn_on_phone_push_for_agent_rental_alerts` sets `supports_push = 1` on the rentals alerts an agent has to act on or would want at once (faults incl. owner decisions / holds / queries / resolved and fault-settings requests; work orders incl. quotes, owner and tenant actions, "not fixed", dispute, variation, overdue, contractor accept / decline / extra money / invoice / finished / not-sent, completed; crew completed / lines; lease notices; rental applications; lease signing outcome, expiry notice, breach remedy date; inspection due / signing reminders and "copies not delivered"). Left in-app + email on purpose (the agent's own action coming back, or noise): work order logged, go-ahead sent, job sent to contractor, contractor photo / started, lease auto month-to-month, property ready to re-list.

Each agent keeps full control: their own push master switch and per-alert "Phone" tick (Notification settings) still decide, plus open hours, cooldown and the once-only ledger. Nothing about who is told changed (`LeaseAgentService::recipientsForEvent`).

The three own-class inspection notifications (`RentalInspectionDueReminder`, `RentalInspectionSigningReminder`, `RentalInspectionCopiesNotDelivered`) gained `toFcmPayload()` (the gateway only pushes a carried class that can render one).

## 3. Open-the-right-screen fields (every push, staff and client)

`App\Support\Push\PushTarget`. Staff pushes now carry `target` + `target_id` (added to the existing `event_key`, `pillar`, `subject_type`, `subject_id`, `action_url`, `severity`, `notification_id`). Destinations: `fault`, `work_order`, `job_card`, `lease_notice`, `lease`, `inspection`, `application`. A record the app has no screen for carries no `target`. Tenant / owner pushes keep their existing `subject_type` + `subject_id` using the same words (`fault`, `work_order`, `lease_notice`, plus `inspection`).

## 4. Tenant / owner: inspection report ready

New switchable category `inspection_ready` (audience both). Event `Rentals\RentalInspectionReportReady`, dispatched by `RentalInspectionCopiesService::fileAndSend()` (runs when an inspection completes, and on a manual resend). Listener `SendRentalClientPush::inspectionReportReady`: tenants and owners of the inspection's lease, once per inspection per phone (`inspection:{id}`), body = the property address only (nothing about the other party). Appears in `GET /client/rentals/push-preferences` (tenant) and `/landlord/push-preferences` (owner) automatically; the agency default `push_informational_default` applies (no new setting).

## 5. Push Test Lab (System Developer menu)

`/corex/admin/push-test-lab` (`admin.push-test-lab.index`, POST `…send`). Owner-only, **no permission key by design** (a grantable key could push to any agency's phones); every action re-checks `isOwnerRole()`; send is throttled 20/min.

- **Find a person:** one search box (name, cell number or email). Agents / staff by name, email, cell, phone (digits compared ignoring spaces, dashes, +). Tenants and owners (portal logins) through the contact the agency holds for them. Each hit shows how many phones are registered.
- **Their phones:** platform, app version, last seen, registered, last 8 characters of the token. No phone = a plain-words reason and nothing is sent.
- **Send:** a plain test message (custom title / message), or one test per notification type — every agent alert type that supports push (grouped), or every tenant / owner category. "Open this record when tapped" takes a record number so the tap-to-open can be tested on a real fault. Beside each agent type: whether this person would be buzzed **for real** today (their own settings); the test ignores that and always tries to deliver.
- A test is the same payload a real alert carries, title prefixed `[TEST]`, `data.test = "1"`, through `PushNotificationService` (de-dupe, per-phone rate cap, retry, dead-token pruning) with a fresh key each time. Result in plain words: sent to N of M phones / no phone / server not connected to Firebase / safety limit hit / provider refused (stale registration).
- Reference panel: every destination the app may be sent to, and the agent alerts that deliberately do NOT go to the phone.
- Logged: `Push Test Lab: test notification` (who, to, what, ok).

Not a setting, so no Setup Wizard step (NN #10a); not an API (web page, NN #7 N/A).

## 6. Tests

`tests/Feature/Push/RentalPhoneAlertsAndTestLabTest.php`. `AuditP1AlertsAndQueueTest` updated: it asserted every contractor / owner / tenant alert type was "without push"; it now asserts push is on except `contractor_photo` and `go_ahead_sent`.

## 7. New-lead alert and appointment reminders (Johan, 26 Oct 2026)

**New-lead alert (already built, checked):** `lead.portal_received` ("New portal lead on your listing", group Leads) goes to the listing's agent, its second agent and the matched contact's agent (never agency-wide) for every portal lead source: Property24, Private Property, the agency website, shared-link re-engagement and the command-task observer. In-app + phone (+ email if the agent ticks it). Verified on Staging: 204 phone pushes and 250 in-app alerts in 30 days, no drops. It is in the Push Test Lab under Leads. The test now sends it in its real shape (`type: portal_lead`, `portal_lead_id`, `deep_link`) plus `target: portal_lead`, `target_id`.

**Appointment reminders (already built, checked):** `command-center:reminders` runs every minute; `CalendarReminderService` warns every person on the event (owner + accepted agent attendees) once per occurrence. Default: one reminder **one hour before**, on the "popup" channel = a CoreX notification AND a phone push (email is a second, optional channel). The push now also carries `target: calendar_event`, `target_id` (the older `deep_link` is kept).

**What was broken, now fixed:** the agent's own "Calendar event reminder" time (notification settings, `agent.event_due`, stored as `event_reminder_minutes_before`, default 60, 5 min to 7 days) was saved but nothing read it (one agent had set 120 min and still got an hour). And the app could not set a reminder time on an event (only the web form could).

Reminder time for a person on an event, first match wins:
1. the time set on that event itself (web form or the app);
2. the person's own setting, once changed from the standard hour (in agency-controlled mode the agency's value);
3. the agency's default for that kind of event (`calendar_event_class_settings.default_reminder_offsets`), else the system default of one hour.

Each person is warned at their own time (two attendees can differ). A person who never changed theirs keeps the standard hour, or the agency's per-kind default if the agency set one.

App contract (`/api/v1/command-center/calendar`):
- `POST` and `PUT` accept `reminder_minutes_before` (must be one of the agency's lead options, default 0, 5, 10, 15, 30, 60, 120, 1440; else 422), `reminder_channels` (`popup`, `email`), and (PUT) `send_reminder`. `reminder_minutes_before: null` clears the event's own time (it follows the person's default); an edit that does not mention reminders leaves them alone.
- The event payload carries `reminder_minutes_before` and `reminder_channels` (the event's OWN choice, null = none set); the event detail endpoint already returned the effective `reminder_offsets` / `reminder_channels`.
- `GET /api/v1/command-center/calendar/options` gains `reminder: {lead_options:[{minutes,label}], my_default_minutes, channels:[{key,label}], default_channels}`.
- The person's own default stays on `GET/PUT /api/v1/notification-preferences` (`agent.event_due`, threshold in minutes).

Tests: `tests/Feature/CommandCenter/EventReminderLeadTimeTest.php`.
