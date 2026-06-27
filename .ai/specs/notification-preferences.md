# Notification Preferences — Spec

> Status: **Draft, awaiting approval (Andre + Johan)**
> Created: 2026-04-27
> Pillars touched: Property, Contact, Deal, Agent (User)
> Depends on: existing `UserDashboardSetting`, `AgencyDashboardSetting`, `ProcessReminders` cron, `CalendarEvent`, `CommandTask`

---

## 1. Business requirement

Today, notification preferences are spread across:
- Two global booleans (`notify_in_app`, `notify_email`) on `UserDashboardSetting`.
- A handful of category booleans (`task_due_reminders`, `doc_reminders_enabled`, `lease_expiry_reminders`, `fica_reminders`, `ffc_reminders`, `idle_alerts_enabled`, `overdue_daily_digest`).
- A separate "hours before" integer per category.
- Per-row `send_reminder` flags on each Task / CalendarEvent.

Users cannot:
- See a single page that lists **every event type the system can notify about**.
- Toggle individual event types on/off (e.g. "remind me about FICA expiring, but not lease expiry").
- Set a **lateness window** per pillar event (e.g. "if a property has no documents 24h after creation, notify me").
- Cover Property / Contact lifecycle events at all — the existing settings only cover Tasks, Calendar Events, Lease, FICA/FFC, Idle, Digest. **There is no notification today for "property created with no docs", "contact missing FICA", "deal stalled at offer stage > 48h"**.

This spec adds:
1. A unified **Notification Catalogue** — one table listing every notifiable event type the system supports.
2. A **per-user preference matrix** — for each catalogue entry, the user picks `enabled` (yes/no) and `threshold` (hours/days, where applicable).
3. A new **Settings → Notifications** page rendering the matrix grouped by pillar.
4. Three new **lifecycle watchers** (Property, Contact, Deal) that emit notifications when threshold conditions are met.
5. Backwards-compatible adapters so the existing `UserDashboardSetting` columns and `send_reminder` flags keep working.

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|---|---|---|
| Property | `properties`, `property_documents`, `created_at` | New notifications row + CommandTask "Upload missing docs for {property}" |
| Contact | `contacts`, FICA fields, `created_at` | Notifications + CommandTask "Complete FICA for {contact}" |
| Deal | `deals`, `status`, `status_changed_at` | Notifications + CommandTask "Deal stalled at {stage}" |
| Agent (User) | `users`, `user_dashboard_settings`, new `user_notification_preferences` | Per-user preference row, in-app + optional email delivery |

---

## 3. Data model

### 3.1 New table `notification_event_types` (catalogue — seeded, reference data)

```
id                       bigint pk
key                      string unique  // e.g. "property.documents_missing"
pillar                   enum('property','contact','deal','agent','system')
group_label              string         // "Documents", "Compliance", "Lifecycle", "Tasks"
label                    string         // "Property documents missing"
description              text           // shown under label in UI
default_enabled          boolean        // catalogue default (per-user can override)
threshold_unit           enum('hours','days','none')
default_threshold        int nullable   // null when unit='none'
threshold_min            int nullable
threshold_max            int nullable
supports_email           boolean default true
supports_in_app          boolean default true
sort_order               int
deleted_at               timestamp      // soft delete (non-negotiable rule #1)
```

### 3.2 New table `user_notification_preferences`

```
id                       bigint pk
user_id                  fk users
notification_event_type_id  fk notification_event_types
enabled                  boolean
threshold                int nullable    // overrides catalogue default
channel_in_app           boolean default true
channel_email            boolean default false
updated_at, created_at, deleted_at
unique(user_id, notification_event_type_id)
```

A row exists **only when the user diverges from the catalogue default**. `getEffective(User, key)` returns the user row if present, else the catalogue row. This keeps the table small and lets us change defaults globally without a backfill.

### 3.3 New table `notification_dispatch_log`

```
id                       bigint pk
user_id                  fk users
notification_event_type_id  fk notification_event_types
subject_type, subject_id // morph (Property / Contact / Deal / etc.)
threshold_hit_at         timestamp
dispatched_at            timestamp
channel                  enum('in_app','email')
deleted_at               timestamp
unique(user_id, notification_event_type_id, subject_type, subject_id, threshold_hit_at)
```

**Why:** prevents duplicate notifications for the same subject + threshold without polluting `metadata->reminder_sent` JSON on the source models. Replaces the existing JSON-flag dedup *only* for new event types — existing CommandTask / CalendarEvent reminders keep using their `metadata->reminder_sent` flag (load-bearing — see §7).

### 3.4 No changes to existing tables

- `user_dashboard_settings` — keep as-is, no columns dropped, no fields renamed.
- `agency_dashboard_settings` — keep as-is.
- `command_tasks.send_reminder`, `calendar_events.send_reminder` — keep as-is.
- All existing notification classes — keep as-is.

---

## 4. Catalogue (seed data — first cut)

### Property
| key | label | unit | default |
|---|---|---|---|
| `property.documents_missing` | Documents not uploaded after listing | hours | 24 |
| `property.mandate_expiring` | Mandate expiring soon | days | 14 |
| `property.no_activity` | No activity (viewing/offer) since listing | days | 21 |
| `property.price_reduction_suggested` | Listed > X days with no offers | days | 30 |
| `property.compliance_doc_missing` | EAAB / compliance cert missing | hours | 48 |

### Contact
| key | label | unit | default |
|---|---|---|---|
| `contact.fica_missing` | FICA documents not uploaded | hours | 48 |
| `contact.fica_expiring` | FICA expiring soon | days | 30 |
| `contact.no_followup` | No follow-up logged in N days | days | 14 |
| `contact.birthday` | Contact birthday today | none | – |

### Deal
| key | label | unit | default |
|---|---|---|---|
| `deal.stalled_offer` | Deal stuck at "offer" stage | hours | 48 |
| `deal.stalled_bond` | Deal stuck at "bond pending" | days | 14 |
| `deal.stalled_conveyancing` | No conveyancing update | days | 7 |
| `deal.documents_missing` | Required deal docs not uploaded | hours | 24 |
| `deal.commission_unpaid` | Commission overdue post-registration | days | 30 |
| `deal.milestone_due` | Deal milestone reaches due date | hours | 24 |

### Agent (existing — adapter only, behaviour unchanged)
| key | label | source |
|---|---|---|
| `agent.task_due` | Task due reminder | adapter → `task_due_reminders` |
| `agent.event_due` | Calendar event reminder | adapter → `event_reminder_minutes_before` (minutes; default **60**, range **[5, 10080]**). Authoritative lead-time `ProcessReminders` reads; legacy `event_reminder_hours_before` is no longer read (fallback ×60 only for un-migrated rows). Threshold clamped to the range on PUT and on read. |
| `agent.lease_expiring` | Lease expiry alert | adapter → `lease_expiry_reminders` |
| `agent.idle` | Idle workspace alert | adapter → `idle_alerts_enabled` |
| `agent.daily_digest` | Daily overdue digest email | adapter → `overdue_daily_digest` |
| `agent.ffc_expiring` | FFC certificate expiring | adapter → `ffc_reminders` |

**Adapter rule:** when ProcessReminders / CheckLeaseExpiry / digest jobs check whether to fire, they call `NotificationPreferenceService::shouldNotify($user, $key, $subject)` which falls through to the legacy column for the six adapter keys. **No existing column is removed.** This is what guarantees the existing reminder pipeline keeps working.

---

## 5. UI

### 5.1 Settings entry
Add a new section to the existing CoreX Settings hub (`/corex/settings?s=notifications`).
- Sidebar link "Notifications" under the user's settings group (next to "Dashboard").
- View: `resources/views/corex/settings/_notifications.blade.php`, included from `corex/settings.blade.php` when `$s === 'notifications'`.
- Controller: extend `app\Http\Controllers\CoreX\SettingsController` with `loadNotificationsSection()` + `updateNotificationPreferences()`. **Do not** create a new top-level controller — keep the settings hub consolidated.

### 5.2 Layout (per spec'd UX)

```
┌─────────────────────────────────────────────────────────────┐
│  Notifications                            [ Reset to defaults ]│
│                                                              │
│  Master switches                                             │
│   In-app  [●○]   Email  [○●]                                 │
│                                                              │
│  ── Property ─────────────────────────────────────────────   │
│   Documents not uploaded after listing                       │
│     [●○ on]    Notify if missing after  [ 24 ] hours         │
│                Channels: ☑ In-app  ☐ Email                   │
│   Mandate expiring soon                                      │
│     [○● off]   Notify  [ 14 ] days before                    │
│                                                              │
│  ── Contact ──────────────────────────────────────────────   │
│   FICA documents not uploaded                                │
│     [●○ on]    Notify if missing after  [ 48 ] hours         │
│   ...                                                        │
│                                                              │
│  ── Deal ─────────────────────────────────────────────────   │
│   ...                                                        │
│                                                              │
│  ── My activity ──────────────────────────────────────────   │
│   Task due reminder, Event reminder, Lease expiry,           │
│   Idle alerts, Daily overdue digest, FFC expiring            │
│   (these mirror existing dashboard settings — changing here  │
│    is the same as changing on the Dashboard tab)             │
└─────────────────────────────────────────────────────────────┘
```

### 5.3 Agency mode does NOT apply to notification preferences
Notification preferences are **always per-user editable**, regardless of `agency.dashboard_settings_mode`. The agency mode flag governs only the user-level **Dashboard** settings tab (idle alerts, working hours, digest time, etc.) — not the Notifications tab. Each user manages their own notification matrix end-to-end. `NotificationPreferenceService::isAgencyControlled()` therefore always returns `false`.

---

## 6. Watchers (the new part that actually fires the notifications)

Three new console commands, all queued, all idempotent via `notification_dispatch_log`:

1. **`notifications:scan-properties`** — every 30 min.
   - For each Property where (`now - created_at`) ≥ user threshold for `property.documents_missing` AND no documents uploaded → fire.
   - For each Property with mandate_expires_at within `property.mandate_expiring` threshold → fire.
   - One pass, walks all active properties, batched by listing agent.

2. **`notifications:scan-contacts`** — hourly.
   - FICA-missing, FICA-expiring, no-follow-up, birthday-today.

3. **`notifications:scan-deals`** — every 30 min.
   - Stalled-stage detection uses `deals.status_changed_at` (must exist; if not, add migration — small, additive).
   - Milestone due: when a deal milestone CalendarEvent comes within threshold.

Schedule them in `routes/console.php` alongside the existing `command-center:reminders`. **Do not modify `ProcessReminders` itself** — those are load-bearing (§7). New watchers run independently.

### 6.1 Dispatch path

All watchers route through one service:

```php
NotificationDispatcher::fire(
    user: $user,
    eventTypeKey: 'property.documents_missing',
    subject: $property,
    payload: ['property_address' => $property->full_address],
);
```

The dispatcher:
1. Calls `NotificationPreferenceService::shouldNotify(...)` → respects user/agency override + master switches + adapter fallback.
2. Checks `notification_dispatch_log` for idempotency (same user + key + subject + threshold_hit window).
3. Sends a single generic `PillarEventNotification` with a `via()` based on the user's per-event channel choice (in_app, email, both).
4. Writes a row to `notification_dispatch_log`.

`PillarEventNotification` is **new** and only used by the new watchers. **No existing notification class is replaced or modified.**

---

## 7. What we are NOT changing (explicit safety list)

To guarantee nothing breaks, the following stay exactly as they are:

- `app/Notifications/TaskDueReminderNotification.php` and `EventDueReminderNotification.php` — keep current channel logic (`UserDashboardSetting::notify_email`).
- `app/Console/Commands/CommandCenter/ProcessReminders.php` — keep 15-min cadence, keep `metadata->reminder_sent` dedup, keep `send_reminder` boolean read.
- `app/Notifications/LeaseExpirationAlert.php` + `CheckLeaseExpiry.php` 06:00 daily.
- `app/Notifications/SignatureActivityNotification.php`, `SignatureTeamAlert.php`, `SendSignatureReminders.php` — signing flows untouched.
- `app/Notifications/AgentInviteNotification.php`, `OnboardingPortalInvitation.php` — onboarding untouched.
- `app/Jobs/OversightDigestJob.php` — manager oversight untouched.
- `UserDashboardSetting` columns — none renamed, none dropped. New table sits beside it.
- `command_tasks.send_reminder`, `calendar_events.send_reminder` — still queried by ProcessReminders.

The "Notifications" settings page will show the six **agent.\*** adapter rows as live controls bound to the existing `UserDashboardSetting` columns (read + write the same column), so the Dashboard settings tab and the Notifications tab stay in sync without duplicating storage.

---

## 8. Permissions

Add to `config/corex-permissions.php`:
- `settings.notifications.view` — see the page.
- `settings.notifications.update` — modify own preferences.
- `settings.notifications.manage_agency` — manage agency-mode catalogue overrides (principals/admin only).

Sidebar link gated on `settings.notifications.view`. Controller methods `Gate::authorize(...)`.

---

## 9. User flow

1. Agent clicks **Settings → Notifications**.
2. Page loads — every catalogue entry rendered grouped by pillar, with the user's effective preference pre-filled.
3. Agent toggles `property.documents_missing` on, sets threshold to `12` hours, ticks Email.
4. POST `/corex/settings/notifications` upserts the `user_notification_preferences` row.
5. 12 hours after a property is listed without docs, `notifications:scan-properties` fires `PillarEventNotification`. Agent sees a bell badge in-app and an email in the inbox.
6. Once the property gets a document uploaded, the dispatch log row is **not** removed — but the watcher's predicate (no docs) becomes false, so no further notifications fire. If docs are deleted again later, a new threshold window opens, a new log row is written, and a new notification fires.

---

## 10. Acceptance criteria

- [ ] `/corex/settings?s=notifications` renders a matrix of all seeded catalogue entries grouped by pillar.
- [ ] Toggling enabled/threshold/channels persists and survives reload.
- [ ] Master `notify_in_app` / `notify_email` switches override individual channels (off = nothing fires regardless).
- [ ] Agency mode banner shown + inputs disabled when `agency.dashboard_settings_mode = 'agency'`.
- [ ] `notifications:scan-properties` fires `property.documents_missing` exactly once per property per threshold window.
- [ ] `notifications:scan-contacts` fires FICA-missing as expected.
- [ ] `notifications:scan-deals` fires stalled-deal as expected.
- [ ] All six adapter keys (`agent.*`) reflect the existing UserDashboardSetting columns and stay in sync with the Dashboard settings tab.
- [ ] All existing notifications (Task/Event/Lease/Signature/Invite/Oversight) still fire in their current cadence, unchanged.
- [ ] `dev-check.ps1` passes with 0 new failures.
- [ ] Soft-delete only: `notification_event_types`, `user_notification_preferences`, `notification_dispatch_log` all use SoftDeletes.

---

## 11. Files to create / modify

**Create**
- `database/migrations/xxxx_create_notification_event_types_table.php`
- `database/migrations/xxxx_create_user_notification_preferences_table.php`
- `database/migrations/xxxx_create_notification_dispatch_log_table.php`
- `database/seeders/NotificationEventTypeSeeder.php`
- `app/Models/CommandCenter/NotificationEventType.php`
- `app/Models/CommandCenter/UserNotificationPreference.php`
- `app/Models/CommandCenter/NotificationDispatchLog.php`
- `app/Services/CommandCenter/NotificationPreferenceService.php`
- `app/Services/CommandCenter/NotificationDispatcher.php`
- `app/Notifications/PillarEventNotification.php`
- `app/Console/Commands/CommandCenter/ScanPropertyNotifications.php`
- `app/Console/Commands/CommandCenter/ScanContactNotifications.php`
- `app/Console/Commands/CommandCenter/ScanDealNotifications.php`
- `resources/views/corex/settings/_notifications.blade.php`

**Modify (additive only)**
- `app/Http/Controllers/CoreX/SettingsController.php` — add `notifications` section handler.
- `resources/views/corex/settings.blade.php` — add sidebar entry + section include.
- `routes/console.php` — schedule three new scan commands.
- `routes/web.php` — add `POST /corex/settings/notifications`.
- `config/corex-permissions.php` — three new keys.

**Do not touch**
- Existing notification classes, ProcessReminders, signature commands, oversight digest job, UserDashboardSetting columns.

---

## 12. Mobile API surface

The mobile app needs to (a) register/unregister its push token, (b) fetch current preferences and update them, (c) pull the live notification feed, (d) read a "what's overdue right now" snapshot for badge counts and home-screen banners. All endpoints sit under `/api/*` with `auth:sanctum`.

### 12.1 Device tokens (push)

New table `device_tokens`:

```
id          bigint pk
user_id     fk users
platform    enum('ios','android','web')
token       string unique           // FCM / APNs / Expo push token
app_version string nullable
last_seen_at timestamp
deleted_at   timestamp                // soft delete on logout
unique(user_id, token)
```

Endpoints:

```
POST   /api/device-tokens            { platform, token, app_version? }      → { ok: true }
DELETE /api/device-tokens/{token}                                           → { ok: true }
```

The dispatcher fans out to every active device token belonging to the user **only when** the per-event preference has `channel_push = true` (new column on `user_notification_preferences`, defaults to `true` when in-app is on). Push transport is FCM via the existing Laravel Notifications channel pattern — adding `laravel-notification-channels/fcm` is allowed.

### 12.2 Notification feed (Sanctum-authenticated mirror of the web feed)

```
GET   /api/notifications?unread=1&limit=20&cursor=…   →
{
  items: [
    {
      id: "uuid",
      type: "App\\Notifications\\PillarEventNotification",
      event_key: "property.documents_missing",
      pillar: "property",
      title: "12 Beach Rd — documents not uploaded",
      body:  "Listed 26 hours ago, no documents on file.",
      subject: { type: "property", id: 123, label: "12 Beach Rd, Margate" },
      action_url: "/properties/123#documents",
      created_at: "2026-04-27T08:14:00+02:00",
      read_at: null,
      severity: "warning"     // info | warning | overdue
    }
  ],
  unread: 7,
  next_cursor: "eyJpZCI6Ii4uLiJ9" | null
}

POST  /api/notifications/{id}/read              → { ok: true }
POST  /api/notifications/mark-all-read          → { ok: true }
```

These mirror the existing web `Api\NotificationController` shape **plus** the pillar fields, so the existing web bell continues to work unchanged. The web controller is extended (not replaced) to include the new fields when present in the notification's `data` payload.

### 12.3 Live overdue snapshot (the home-screen badge feed)

```
GET   /api/notifications/overdue   →
{
  counts: {
    properties: 3,
    contacts: 1,
    deals: 2,
    tasks: 5,
    events: 0,
    total: 11
  },
  items: [
    { event_key, pillar, subject:{...}, threshold_hit_at, age_hours, severity, action_url, title, body }
  ]
}
```

Implementation: `OverdueSnapshotService` runs the same predicates the watchers use, but read-only — no notifications dispatched, no log rows written. Mobile app polls this endpoint when foregrounded, or refreshes after receiving a push.

### 12.4 Preferences API

```
GET   /api/notification-preferences   →
{
  master: { in_app: true, email: true, push: true },
  agency_controlled: false,
  groups: [
    {
      pillar: "property",
      label: "Property",
      items: [
        {
          key: "property.documents_missing",
          label: "Documents not uploaded after listing",
          description: "...",
          threshold_unit: "hours",
          threshold: 24,
          threshold_min: 1, threshold_max: 168,
          enabled: true,
          channel_in_app: true,
          channel_email: false,
          channel_push: true
        },
        ...
      ]
    },
    ...
  ]
}

PUT   /api/notification-preferences
{
  master: { in_app: true, email: false, push: true },
  preferences: [
    { key: "property.documents_missing", enabled: true, threshold: 12, channel_in_app: true, channel_email: false, channel_push: true }
  ]
}                                                  → { ok: true, saved: 1 }
```

If `agency_controlled` is true, `PUT` returns `409 { error: "agency_controlled" }`.

### 12.5 Push payload (FCM)

```
{
  notification: { title, body },
  data: {
    notification_id: "uuid",
    event_key: "deal.stalled_offer",
    pillar: "deal",
    subject_type: "deal",
    subject_id: "456",
    action_url: "/deals/456",
    severity: "overdue"
  }
}
```

Mobile app uses `data.event_key` + `data.subject_id` to deep-link.

### 12.6 New table `device_tokens`, new column `channel_push`

- Migration adds `channel_push boolean default true` to `user_notification_preferences`.
- Migration creates `device_tokens` table.
- `User` model gets `routeNotificationForFcm()` returning all active tokens.

### 12.7 Permissions

`api.notifications.read`, `api.notifications.manage_devices`, `api.notification_preferences.update` — all granted to any authenticated user with a Sanctum token by default. No new sidebar gating.

---

## 13. Open questions for Andre / Johan

1. Should the agent's Daily Digest email roll up the new pillar notifications too, or stay as overdue-tasks-only?
2. Do we want SMS as a third channel now (would mean adding `channel_sms` to the preference table) or defer?
3. For agency mode, should principals get a separate "manage agency notification policy" page, or reuse the agency Dashboard tab?
4. Birthday + price-reduction-suggested — keep in v1 or defer?
