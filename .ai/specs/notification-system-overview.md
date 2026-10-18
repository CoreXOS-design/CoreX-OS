# CoreX OS — Notification System: A Plain-English Guide

> Audience: anyone new to the codebase. No prior knowledge assumed.
> Last updated: 2026-04-27

---

## 1. What "notifications" mean in CoreX

A notification is a message the system sends to a user telling them something needs attention — a task is due, a property has no documents, a deal is stuck, FICA is missing, a signature is awaiting them. Notifications can land in **three places**:

1. **In-app** — the bell icon at the top of the web dashboard, plus a notifications page.
2. **Email** — a real email in the user's inbox.
3. **Push** — a banner/sound on the user's mobile phone (CoreX OS mobile app).

A single event can fire to one, two, or all three channels depending on user preferences.

---

## 2. The two halves of the system

The notification system is split into two pipelines that happen to share the same delivery layer:

### A) The **legacy / direct** pipeline
Triggered when something specific happens in the app. Hard-coded — fires regardless of user choice (only the channel mix is configurable).

Examples:
- A signer signs a document → the agent gets a "party signed" notification.
- An agent is invited → an invitation email goes out.
- A lease is 30 days from expiry → a tiered alert fires.
- A task with `send_reminder = true` is approaching its due time → a reminder fires.

### B) The **pillar / preference-driven** pipeline (new, the focus of this guide)
A catalogue of "things you can be notified about", each user choosing which ones they want, on which channels, and how soon. Background scanners walk the database every 30 minutes / hour and decide what to fire.

Both pipelines deliver via the **same** Laravel notifications layer (database table + mail driver + FCM push), so the bell icon shows everything regardless of which pipeline produced it.

---

## 3. Mental model: the four moving parts

```
┌──────────────┐     ┌────────────────┐     ┌──────────────┐     ┌──────────────┐
│  CATALOGUE   │     │  PREFERENCES   │     │   WATCHERS   │     │  DISPATCHER  │
│              │     │                │     │              │     │              │
│ "what can be │ ──> │ "what does THIS│ ──> │ "is anything │ ──> │ "deliver via │
│ notified on" │     │  user want"    │     │  due now?"   │     │  in-app/mail/│
│              │     │                │     │              │     │  push"       │
└──────────────┘     └────────────────┘     └──────────────┘     └──────────────┘
       │                    │                      │                    │
       ▼                    ▼                      ▼                    ▼
  notification_      user_notification_     scheduled         Laravel notifications
  event_types        preferences            scan-* commands   (database / mail / FCM)
```

**Catalogue**: a table that lists every event type the system supports (e.g. *"property documents missing"*, *"contact FICA missing"*, *"deal stuck at offer"*). Sysadmin-managed reference data.

**Preferences**: a table that stores **only the differences** between a user's choice and the catalogue defaults. If a user is happy with the defaults, they have zero rows here.

**Watchers**: three console commands that run on a schedule, sweep the database, and ask the dispatcher to fire when a row hits a user's threshold.

**Dispatcher**: the single point that respects the user's master switches + per-event toggles + idempotency log, then hands off to Laravel's built-in `$user->notify(...)` for in-app + email and a soft hook for FCM push.

---

## 4. Where everything lives

### Database tables
| Table | Purpose |
|---|---|
| `notifications` | Laravel's standard table — every in-app notification ever delivered, JSON `data` column holds the payload. |
| `notification_event_types` | The catalogue. ~20 rows seeded today. |
| `user_notification_preferences` | Per-user overrides. Sparse — many users will have 0 rows here. |
| `notification_dispatch_log` | "We already told user X about Y for subject Z at time T" — prevents duplicate spam. |
| `device_tokens` | FCM/APNs push tokens registered by the mobile app. |
| `user_dashboard_settings` | Legacy column-based preferences (Tasks, Events, FICA, Lease, Idle, Digest). Still in use; new system **adapts** these via the catalogue, doesn't replace them. |
| `agency_dashboard_settings` | Agency-mode central override of the above. |

### Code paths

| Layer | File |
|---|---|
| Catalogue model | `app/Models/CommandCenter/NotificationEventType.php` |
| User overrides model | `app/Models/CommandCenter/UserNotificationPreference.php` |
| Idempotency log | `app/Models/CommandCenter/NotificationDispatchLog.php` |
| Push token model | `app/Models/DeviceToken.php` |
| Catalogue seed | `database/seeders/NotificationEventTypeSeeder.php` |
| **Preference resolver** | `app/Services/CommandCenter/NotificationPreferenceService.php` |
| **Dispatcher** | `app/Services/CommandCenter/NotificationDispatcher.php` |
| **Overdue snapshot (read-only)** | `app/Services/CommandCenter/OverdueSnapshotService.php` |
| **Generic notification class** | `app/Notifications/PillarEventNotification.php` |
| Watcher: properties | `app/Console/Commands/CommandCenter/ScanPropertyNotifications.php` |
| Watcher: contacts | _removed 2026-07-01_ — its only job (contact birthdays) now ships in the daily digest, see `SendCalendarDigests.php` |
| Watcher: deals | `app/Console/Commands/CommandCenter/ScanDealNotifications.php` |
| Legacy reminder (Tasks/Events) | `app/Console/Commands/CommandCenter/ProcessReminders.php` |
| Legacy reminder (Lease) | `app/Console/Commands/CheckLeaseExpiry.php` |
| Legacy reminder (Signature) | `app/Console/Commands/SendSignatureReminders.php` |
| Schedule | `routes/console.php` (everything cron-ish lives here) |
| Web bell-icon API | `app/Http/Controllers/Api/NotificationController.php` (mounted in both `web.php` and `api.php`) |
| Mobile API: device tokens | `app/Http/Controllers/Api/DeviceTokenController.php` |
| Mobile API: preferences | `app/Http/Controllers/Api/NotificationPreferenceController.php` |
| Settings UI controller | `app/Http/Controllers/CoreX/SettingsController.php::updateNotificationPreferences` |
| Settings UI view | `resources/views/corex/settings/_notifications.blade.php` |

### Routes

| URL | What it does |
|---|---|
| `GET /corex/settings?s=notifications` | The user's "Notifications" preferences page (web). |
| `POST /corex/settings/notifications` | Save preferences from the web page. |
| `GET /api/notifications` | Mobile/web bell feed — list of notifications. |
| `POST /api/notifications/{id}/read` | Mark one read. |
| `POST /api/notifications/mark-all-read` | Mark all read. |
| `GET /api/notifications/overdue` | "What's overdue right now" snapshot for badge counts. |
| `GET /api/notification-preferences` | Mobile preferences load. |
| `PUT /api/notification-preferences` | Mobile preferences save. |
| `POST /api/device-tokens` | Mobile registers its push token. |
| `DELETE /api/device-tokens/{token}` | Mobile unregisters on logout. |

### Schedule (in `routes/console.php`)
```
command-center:reminders          every 15 min   (legacy: Task + Event reminders)
notifications:scan-properties     every 30 min   (new pillar pipeline)
notifications:scan-deals          every 30 min   (new pillar pipeline)
corex:calendar:send-digests       daily 06:30    (calendar items + contact birthdays — one email/user)
signatures:send-reminders         daily 08:00    (legacy)
signatures:check-lease-expiry     daily 06:00    (legacy)
OversightDigestJob                hourly         (manager oversight)
```

---

## 5. End-to-end: tracing a single notification

Let's follow *"property documents missing"* from listing to phone:

1. **Agent lists a new property** at 09:00 — a row is inserted into `properties`.
2. **30 minutes later**, the cron runs `notifications:scan-properties` (`ScanPropertyNotifications.php`). It loops every active property and asks `NotificationPreferenceService->effective($agent, 'property.documents_missing')`.
3. The service returns:
   - `enabled: true`
   - `threshold: 24` (hours — pulled from catalogue default since the user has no override)
   - `channel_in_app: true`, `channel_email: false`, `channel_push: true`
4. The watcher checks: is the property older than 24 hours **and** has zero rows in `property_documents`? Not yet — skip.
5. The next day at 09:30, the watcher runs again. Now it's been > 24h. The watcher calls `NotificationDispatcher->fire(...)`.
6. The dispatcher:
   - Re-resolves the preference (channels still active).
   - Looks up `notification_dispatch_log` for `(user, event_type, subject)` since the last threshold window — none → proceed.
   - Calls `$agent->notify(new PillarEventNotification(...))` with channels `['database']` (in-app).
   - Calls the soft FCM hook (if `App\Services\Push\FcmService` is registered) with the payload from `PillarEventNotification::toFcmPayload()`.
   - Inserts log rows so we don't fire again for the same threshold window.
7. The agent's bell icon now shows a +1 badge. The mobile app receives a push with `data.action_url = "/properties/123"`.
8. Tapping the push deep-links into Property #123 in the mobile app.
9. If the agent uploads a document, the watcher's predicate (`no docs`) becomes false next pass, so no new fires until the row gets deleted again.

---

## 6. The catalogue — what's in it today

Twenty event types live in `notification_event_types`. Grouped by pillar:

**Property** (4) — `documents_missing`, `mandate_expiring`, `no_activity`, `compliance_doc_missing`
**Contact** (4) — `fica_missing`, `fica_expiring`, `no_followup`, `birthday`
**Deal** (6) — `stalled_offer`, `stalled_bond`, `stalled_conveyancing`, `documents_missing`, `commission_unpaid`, `milestone_due`
**Agent** (6 adapter rows) — `task_due`, `event_due`, `lease_expiring`, `idle`, `daily_digest`, `ffc_expiring`

The "Agent" rows are special — they're **adapters**. Their `is_adapter = true`, and toggling them in the UI actually writes to the existing `user_dashboard_settings` columns (e.g. `task_due_reminders`, `task_reminder_hours_before`) so the legacy `ProcessReminders` cron keeps working without any changes. This is the key compatibility decision that makes the new system safe.

To add a new event type:
1. Add a row to `NotificationEventTypeSeeder.php` and re-run the seeder.
2. Make sure one of the watchers (or a hook in your code) calls `NotificationDispatcher::fire($user, 'your.new.key', $subject, [...])`.

---

## 7. How preferences resolve (the override chain)

When the dispatcher needs to know "should I fire?", it asks `NotificationPreferenceService::effective($user, $key)`. The resolution order is:

1. **Master switches** (top of the page): if `notify_in_app = false` AND `notify_email = false`, nothing fires regardless of per-event settings. Push is gated by the in-app master.
2. **Agency mode does NOT apply here.** The `agency.dashboard_settings_mode = 'agency'` flag only locks the user-level Dashboard tab (idle alerts, working hours, digest time). Notification preferences are always per-user editable, end-to-end.
3. **Adapter rows**: if the catalogue row has `is_adapter = true`, read the live value from the matching column in the **user's own** `user_dashboard_settings` row (e.g. `task_due_reminders` / `task_reminder_hours_before`) — never the agency-level row.
4. **Per-user override**: if a row exists in `user_notification_preferences` for this user + event type, use it.
5. **Catalogue default**: otherwise use the catalogue row's `default_enabled` + `default_threshold`.

This is why `user_notification_preferences` is sparse — most users never write to it.

---

## 8. Idempotency: how we don't spam

`notification_dispatch_log` is the single source of truth for "we already told them". Every successful dispatch writes one row per channel. Before firing, the dispatcher checks for a matching row at or after the current `threshold_hit_at`. If found → skip silently.

`threshold_hit_at` is usually rounded (e.g. `now()->startOfHour()` or `created_at + threshold_hours`) so the check is stable across cron runs within the same window.

The legacy reminders (Tasks, Events, Signatures) use a **different** dedup mechanism — a `metadata->reminder_sent` JSON flag on the source row — which is left untouched.

---

## 9. The user experience

### Web — bell icon
Top-right of every page. Polls `GET /api/notifications` periodically. Shows unread count + a dropdown of the 20 most recent. Items are tappable and link to whatever URL is in `data.action_url`.

### Web — preferences page
Settings hub → **Notifications** tab. Path: `/corex/settings?s=notifications`. Shows:
- Master switches at the top.
- Four pillar groups (Property, Contact, Deal, My activity).
- Each row: enable toggle, threshold input with unit suffix, three channel checkboxes.
- One "Save preferences" button at the bottom.
- Banner when the agency has taken over.

### Mobile
- Push lands as a normal OS notification.
- Bell screen mirrors the web feed via the same JSON shape.
- "Overdue" widget on the home screen pulls `GET /api/notifications/overdue` and shows pill counts per pillar.
- Settings → Notifications screen: same matrix as web, save calls `PUT /api/notification-preferences`.

### Email
Standard Laravel mail. Subject = the notification title, body = the notification body, action button = `action_url`. Sent via the configured mail driver — no special infra.

---

## 10. Frequently-asked debug questions

**"Why didn't user X get a notification?"** — Check, in order:
1. `select * from notification_event_types where key = '...'` — is the event type seeded?
2. `select * from user_dashboard_settings where user_id = X` — `notify_in_app` true? (or `notify_email`)
3. Is the user's agency in `dashboard_settings_mode = 'agency'`? Check agency settings.
4. `select * from user_notification_preferences where user_id = X and notification_event_type_id = ...` — is there an override that disables it?
5. `select * from notification_dispatch_log where user_id = X order by id desc limit 5` — was it already sent for this window?
6. Did the watcher actually run? Look for the entry under `php artisan schedule:list` and check the queue/log.

**"How do I test the new system locally?"**
```
php artisan migrate
php artisan db:seed --class=NotificationEventTypeSeeder
php artisan notifications:scan-properties
php artisan notifications:scan-contacts
php artisan notifications:scan-deals
```
Then look at `notifications` table or hit `/api/notifications` in the browser/Postman.

**"Where do I add a new pillar event?"**
1. Add a row to the seeder.
2. Add the predicate to the relevant `Scan*Notifications` command (or a hook in the controller that creates/changes the model — e.g. on deal status change, fire directly).
3. The UI auto-renders it from the catalogue — no view change needed.

**"How does push actually go out?"**
The dispatcher calls `App\Services\Push\FcmService::send($tokens, $payload)` **if that class exists**. Today it doesn't — push is a no-op until someone wires up Firebase. The class signature is one method `send(array $tokens, array $payload): void`. Adding it is the one and only step needed to make push live.

**"What happens if a user is deleted?"**
All three new tables cascade-delete on `users.id`, so preferences and dispatch logs go with them. Notifications (Laravel's table) stay until manually purged.

---

## 11. The non-negotiables (project rules that apply here)

- **Soft delete only.** All four new tables use SoftDeletes.
- **Pillar-aware.** Every notification is tagged with `pillar` so UI can group/filter.
- **No replacement of existing logic.** The new system runs *alongside* `ProcessReminders` etc. Removing or modifying the legacy classes would break Tasks, Events, Lease, Signature flows.
- **Permissions.** Reaching the settings page requires `access_settings`. Mobile API endpoints are guarded by `auth:sanctum`.

---

## 12. What's next (open work)

- Wire a real FCM transport into `App\Services\Push\FcmService` — push currently no-ops.
- Replace the deal stalled-stage proxy (`updated_at`) with a real `status_changed_at` column on `deals`.
- Per-event push channel currently follows the in-app master; if the team wants push to be independently switchable, expand the master switches to three separate booleans.
- Email digest rollup — the current `overdue_daily_digest` only covers the legacy items; consider folding pillar overdue items in too.

---

## 13. TL;DR for the impatient

- **Catalogue** → `notification_event_types` (what can fire).
- **Preferences** → `user_notification_preferences` (what the user wants), plus the legacy `user_dashboard_settings` for adapter rows.
- **Watchers** → three `notifications:scan-*` cron commands.
- **Dispatcher** → `NotificationDispatcher` is the only thing that calls `$user->notify(...)` for the new pipeline.
- **Channels** → in-app (Laravel `database`), email (Laravel `mail`), push (FCM via a soft hook).
- **UI** → web at `/corex/settings?s=notifications`, mobile via `/api/notification-preferences`.
- **Alert feed** → `/api/v1/notifications` (mobile app + the live popup, §14). The bell in `layouts/corex-header.blade.php` is **not mounted in any layout** (checked on QA1, 12 Oct 2026): staff see alerts on the Today "Unread Notifications" tile and `/corex/notifications`. **Overdue snapshot** → `/api/notifications/overdue`.
- **Idempotency** → `notification_dispatch_log` for the new pipeline; `metadata->reminder_sent` for the legacy one.
- **Spec** → `.ai/specs/notification-preferences.md`. **Mobile prompt** → `.ai/specs/notification-preferences-mobile-prompt.md`.

If you read nothing else: a notification fires when a **watcher** sees a row crossing a **threshold**, the **dispatcher** confirms the **preferences** allow it, and Laravel's built-in notification layer carries it to the **bell, the inbox, and the phone**.

---

## 14. Live popup, and "all alerts off" is never silent (AT-451, Johan, 12 Oct 2026)

Found while tracing why the tenant's agent got nothing for a tenant-reported fault: the agent's three master switches (in-app, email, push) were all off, so `NotificationDispatcher::dispatch()` dropped the alert **silently**, and nothing on screen would have told them anyway until a page reload. **Design rule (Johan): off stays off — the dispatcher keeps dropping. What changes is that nobody is unaware.**

**A. Live popup** (`resources/views/components/alert-popups.blade.php`, reached through the ONE shared include `layouts/partials/_staff-alerts.blade.php` — **on every staff layout: `layouts/corex.blade.php` (394 views, and everything using `<x-app-layout>`) and `layouts/corex-app.blade.php` (183 views: contact page, Market Intelligence, Road to Sold, buyers report, compliance, payroll, presentations, title deeds, BM pages …)**; `@once` stops a second include in a request. `corex-app` has no toast of its own, so the include adds `<x-toast-notifications :flash="false" />` there (`flash=false`: the page's session flash is NOT turned into a toast on those pages — they keep showing it their own way). Never the public portal / public signing / onboarding / agency-setup pages, never inside a frame. Until 14 Oct 2026 `corex-app` pages had no popup at all. **Round 2 (12 Oct 2026): the first build polled `/api/v1/notifications`, which is api.php's token-only mobile route — a staff browser session answered 401 and the poll stopped for good, so the popup never fired in any real browser (its tests used `Sanctum::actingAs`, which hid it). Now:**
- Feed: **`GET /api/v1/alerts/unread`** (`api.v1.alerts.unread`, `Api\V1\AlertFeedController`, in the session-authenticated api/v1 group of `routes/web.php` beside `command-center/reminders/due`; throttled 120/min). It is a **cursor feed, never "all unread"**: a poll sends `?after=<cursor>` and gets only that user's *unread* alerts created at or after the cursor (less 5 s of overlap, so a row that committed just after the previous poll's query is still seen), newest first, at most 20 (+1 to set `has_more`), look-back capped at 24 h. **No cursor = a brand-new tab (14 Oct 2026):** the server looks back N minutes instead (`catchup: true` in the answer), N = agency setting `agency_dashboard_settings.alert_catchup_minutes` ("Show alerts still unread from the last (minutes)", **default 10, 1 to 240**, blank = default; `AlertSwitchService::catchupMinutesFor()`, Settings → Dashboard and the Setup Wizard's Notifications step, `$request->has()`-guarded in `SettingsController@updateAgencyDashboardSettings`). No count of the unread history is ever made. **Index: `notifications_feed_index` = (`notifiable_type`, `notifiable_id`, `created_at`)** (migration `2026_10_22_100300`); the stock Laravel index (type, id) cannot answer a created_at range, so a user like QA1's user 22 (71,646 unread) would have been read end to end every poll. `in_app` false (the user's own switch, agency-lock aware) => no items.
- Interval = agency setting `agency_dashboard_settings.alert_poll_seconds` (**default 30, minimum 15, maximum 600**; blank = default), `AlertSwitchService::pollSecondsFor()`, clamped on save AND on read; edited on Settings → Dashboard and in the Setup Wizard's Notifications step through `SettingsController@updateAgencyDashboardSettings` (`$request->has()`-guarded).
- **The "last checked" point survives a page change (14 Oct 2026).** The cursor the server hands back is kept in the tab's `sessionStorage` (`corex_alerts_cursor_<userId>`), so the next page asks for everything since the previous page's last poll — it is no longer re-started from "now" on every page load (that was the bug: an alert that arrived while the agent was changing page fell between two pages' starting points and never popped up; reproduced on QA1 from Johan's demo). A new tab has no stored point and gets the catch-up window above. **The first poll of every page runs immediately**, not after the interval. An alert not yet seen in this tab (ids in `sessionStorage`, per user, capped at 300) pops up through `window.showToast(title, 'info', 9000, url)`; the toast component takes an optional 4th argument `url` (same-site only) so the popup is clickable through to the alert's `action_url`.
- Pauses while the tab is hidden; polls straight away when it is visible again. **A failed poll is retried, never abandoned**: the delay grows (interval x2, x4 … capped at 5 minutes) and resets on success. A 401/419 is checked against the session probe `/api/v1/csrf-token`; **only if that also answers 401/419 (the login is really gone) does polling stop**. No console noise.
- **Superseded 14 Oct 2026 by section 16:** the toast stack, the bell and the inbox are rebuilt there (toasts stay until clicked or closed, up to 5 stack with a "+N more" row, a bell with an unread badge sits beside the theme toggle, `/corex/alerts` is the inbox). The "two toasts plus N more" rule and the "no bell" note that stood here are gone. (The unused bell in `layouts/corex-header.blade.php`, which called the token-only route, is still unused.)

**B. All alerts off.** "All off" = none of in-app / email / push is on, by the same rules the dispatcher applies (`NotificationPreferenceService::masterSwitchState()` — the agency's in-app/email when the agency locks settings, the user's own push always).
1. *The user:* one amber line at the top of the staff layouts (`layouts/partials/_alerts-off-bar.blade.php`): "Your alerts are switched off - you will not be told about new faults, leads or approvals." with **Turn alerts on** (in-app on; email and push untouched) and **Keep them off** (records the decision, hides the bar). Both act on the logged-in user only (`POST /corex/alerts/bar/turn-on|keep-off`, no id accepted). Where the agency sets the switches for everyone, only "Keep them off" is shown and turn-on is refused with a plain message. The bar returns whenever the notification settings are saved again and are still all off (the acceptance is tied to the settings' `updated_at`, `settings_stamp`) — a superset of "turned on and off again".
2. *The agency:* people with **`manage_users`** (the existing user-management permission; same gate as the Users page) see "N users have all alerts off" on the Rental Command Centre and the Users page, opening **`/corex/alerts-off`**. Columns: user, role, branch, off since (= when the notification settings were last saved), decision (Not decided / Accepted by the user / by an administrator (name)), actions **Turn in-app alerts on** / **Accept - no alerts for this user**, and a per-user history of every decision. *Search:* name, email. *Sort:* name, role, branch, off since (**default: off since, oldest first**). *Filter:* decision (all / not decided / accepted), branch, off-since date range. *Paging:* 25. *Empty states:* "No users match these filters" vs "Everyone you can see has at least one alert switched on." *Scoping, in the query* (`AlertSwitchService::scopedUsers`): the viewer's agency is the hard boundary; within it the `users` data scope — all = agency, branch = the viewer's branch, own = themselves, **no scope row = branch** (never "nothing", never wider). Per-user actions resolve the target inside the same scoped query: outside scope = 404, whatever the URL.
3. *The record:* `user_alert_decisions` (agency, user, decided_by, `accepted_off` | `turned_on`, the three switch states as they stood once the decision took effect, `settings_stamp`, soft-delete columns). **Append-only** — the model refuses edits; nothing archives rows. Shown as history in the list.
4. *The drop ledger:* where the dispatcher drops an alert because every channel is off it writes one row to **`notification_drop_log`** (event, intended recipient, subject, reason `all channels off`), at most one per user + event + subject + threshold so a scanner re-offering the same fact does not flood it. It is deliberately NOT `notification_dispatch_log` (that is the "already told them" idempotency ledger; a row there would stop the alert ever arriving after the user switches alerts on). Delivery is otherwise unchanged. Not logged: a per-event "off" choice, and the case where the user wants only a channel the event does not support.

**Tests:** `tests/Feature/Alerts/AlertSwitchTest.php` (bar, keep-off record, bar returns, turn-on + alert arrives, locked agency, drop ledger once + no log for on-users, endpoint `in_app`, popup interval clamp, poll setting validation, list scoping incl. branch manager + other agency + direct URL 404, permission, decisions + history, filters/empty states, compact line, append-only, bulk-vs-per-user rule parity, review-step "Save draft" label).

---

## 15. Who is told at every step of the fault chain (AT-451 round 2, Johan, 12 Oct 2026)

Johan's rule: **the same people who are told about a new fault are told what happens next.** One rule, `LeaseAgentService::recipientsForEvent()`: the people `faultRecipients()` names — the lease's **owner-side AND tenant-side agent** (once each; once if one person), else the property's agent, else the branch manager → the branch's office admin → the agency admin; inactive users skipped — **minus the staff member who performed the action** (they already know). The actor is passed explicitly where the caller has them (the agent who captured a fault, recorded an owner decision, set an outcome, or recorded the tenant's answer); otherwise it is the logged-in web-guard staff user, so a staff click never alerts its own author, a portal click (no staff user) or a scheduled scan (nobody) alerts everyone responsible. Each person's own settings (event on/off, channels, open hours) still decide how it reaches them. Mails to tenants / owners / contractors are unchanged.

| Alert (event key) | Told BEFORE | Told AFTER |
|---|---|---|
| Fault reported (`rental_fault_report.created`) | both lease agents, else property agent, else branch/office/agency admin | same, minus the agent who captured it |
| Owner approved / declined a fault (`rental_fault_report.owner_decided`) | the owner-side agent only | both lease agents (same chain), minus a staff member who recorded the decision |
| Fault outcome set (`rental_fault_report.resolved`) | the property's agent only | both lease agents, minus the agent who set it |
| Work order created / completed / overdue (`rental_work_order.created|completed|overdue`) | the property's agent only | both lease agents |
| Extra work raised (`rental_work_order.variation_raised`) | the property's agent only | both lease agents |
| Owner decided the quote / set the appointment / reported progress (`rental_work_order.owner_decided|owner_appointment|owner_progress`) | the owner-side agent only | both lease agents |
| Tenant confirmed the work (`rental_work_order.completion_confirmed`) | the property's agent only | both lease agents, minus a staff member who recorded it |
| Tenant says NOT complete (`rental_work_order.disputed`) | the property's agent + the branch manager | both lease agents + the branch manager, minus a staff member who recorded it |
| Crew sent parts / labour (`rental_job_card.crew_lines_submitted`) | the property's agent only | both lease agents |

Reason-in-the-spec check: `rental-work-orders.md` §4 names "the property's assigned agent" for created / completed / overdue because lease agents did not exist when it was written; `leases.md` (lease agents) and §17 above supersede it. Where a lease has no named agents the chain falls back to the property's agent, so nothing changes for such leases. Tests: `tests/Feature/RentalFaultFlow/BothAgentsHearEveryStepTest.php`.


---

## 16. Alert bell, alert inbox, toasts that stay, one address rule (Johan, 14 Oct 2026, from his live walk)

Three faults found on QA1 with user 22: (1) the popup faded after seconds and there was no bell or inbox anywhere, so an agent away from the screen never saw an alert again; (2) a second alert replaced the first; (3) `rental_work_order.owner_decided` read "Owner approved the quote (R4,120.00) - send it to the contractor" with no property while other rentals alerts ended " - 8 Harbour View Road, Shelly Beach, Margate".

**Causes:** (1)+(2) `components/toast-notifications.blade.php` `toastSystem()` is the flash-message toast: it auto-removes after 4-15 s and keeps at most 3, dropping the oldest; `components/alert-popups.blade.php` fed it through `window.showToast(..., 9000, url)` and showed at most 2 of a burst; nothing ever marked an alert read. (3) The title is built at each call site; `ClientLandlordRentalsController::decideQuote` (the `owner_decided` call into `RentalWorkOrderService::notifyAgentOfOwnerAction`) never appended the address, and the other funnels each did it their own way (" - " vs " — ", or not at all).

**Read state** is Laravel's own `notifications.read_at` (already present; no migration). Alerts are system-generated and never deleted: the only states are unread / read (**archive-only: nothing here deletes a row**). Scope = the logged-in user's own alerts, enforced in every query (`notifiable_type = User`, `notifiable_id = auth id`); an id belonging to anyone else is a 404.

**A. Bell** - in the left menu's top bar, left of the Ellie button (moved there from the user block on Johan's ask, 2026-10-18; styled like the Ellie button beside it - no border, no shaded box, 2.25rem, muted icon, a soft hover and the brand colour while its panel is open - own `.corex-alert-bell` style in the component, no npm build; `layouts/corex-sidebar.blade.php`, therefore on both staff layouts). Near the top of the screen its panel opens downward from the bell (beside the menu); a bell in the bottom half still opens upward. Red badge = unread count, capped "99+" (the count query stops after 100 rows, so a user with tens of thousands of unread costs the same as one with five). Click opens a panel: unread alerts newest first (max 15): title (ends with the property), body, time-ago; click an item = open its link and mark it read (`GET /corex/alerts/{id}/open`, which marks read then redirects to the alert's own link, same-site only); **Mark all read**; **View all** -> the inbox. The badge refreshes with every feed poll.

**B. Inbox** - `GET /corex/alerts` (`corex.alerts.index`, `CoreX\AlertInboxController`), sidebar link "Alerts" in the Dashboard group. *Search:* title and body. *Filter:* type (the alert types this user has), unread / read / all, received-from and received-to date. *Sort:* newest / oldest (**default newest first**). *Paging:* 25. *Empty state:* "No alerts match these filters" vs "No alerts yet". Each row shows read state, title, body, type, time; click = open (marks read). Controls: **Mark all read**, and a per-row **Mark read**. No delete.

**C. Toasts** (`components/alert-popups.blade.php` now owns its own stack; the shared flash toast is untouched): newest on top, at most 5 shown, older ones collapse into one "+N more" row linking to the inbox filtered to unread. A toast stays until it is clicked (opens + marks read) or closed with x (marks read) - **no auto-fade**. It survives page changes: the tab keeps the ids it has toasted and not dismissed (sessionStorage, per user); each page re-shows those that are still unread (the poll asks the server which of them still are, so reading it in the bell or another tab removes it). Only alerts newer than the tab's last poll become NEW toasts; a tab opened after sleeping gets the existing short catch-up window (agency setting, default 10 min) as toasts and everything older through the bell.

**D. API** (session group of `routes/web.php`, catalogued under Admin -> API): `GET /api/v1/alerts/unread` (existing cursor feed; now also returns `unread_count` capped at 100 and, for `?pending=id,id`, `still_unread` of those ids), `GET /api/v1/alerts/bell` (count + latest 15 unread), `POST /api/v1/alerts/{id}/read`, `POST /api/v1/alerts/read-all`.

**E. One title rule for rentals** - `App\Support\RentalAlertTitle::withProperty($title, $property)` returns `"<title> - <property short address>"` (the address = `Property::buildDisplayAddress()`, else the property title, else "Property #id"); it is idempotent (a title already ending with the address is only re-joined with " - ") and it normalises " — ". It is applied at every rentals funnel that builds an alert title: `RentalWorkOrderService::notifyAgentOfOwnerAction` and `::fireInternal` (covers owner_decided, contractor_job_sent / responded / quote_received, owner_progress / appointment, variation_raised and the go-ahead messages), `RentalCompletionService::notifyStaff`, `RentalOwnerHoldService::tellAgents`, `RentalOwnerFaultSettingsService::alertAgents`, and the fault-report created / resolved alerts. Amounts and contractor names stay where they were. A new rentals alert title goes through the same helper.

**Tests:** `tests/Feature/Alerts/AlertBellTest.php`.
