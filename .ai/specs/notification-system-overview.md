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
- **Bell feed** → `/api/notifications`. **Overdue snapshot** → `/api/notifications/overdue`.
- **Idempotency** → `notification_dispatch_log` for the new pipeline; `metadata->reminder_sent` for the legacy one.
- **Spec** → `.ai/specs/notification-preferences.md`. **Mobile prompt** → `.ai/specs/notification-preferences-mobile-prompt.md`.

If you read nothing else: a notification fires when a **watcher** sees a row crossing a **threshold**, the **dispatcher** confirms the **preferences** allow it, and Laravel's built-in notification layer carries it to the **bell, the inbox, and the phone**.
