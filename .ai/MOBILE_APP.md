# CoreX Mobile — App Status

> Flutter companion app for CoreX OS
> Last updated: 2026-03-12

---

## Project Location

`c:\Users\USER-PC\Documents\Projects\corex_mobile\`

---

## Tech Stack

| Item | Value |
|------|-------|
| Framework | Flutter (Dart SDK ^3.10.7) |
| State management | Provider (ChangeNotifier) |
| HTTP | `http` package |
| Token storage | `shared_preferences` |
| Fonts | Google Fonts (Inter) |
| API target | `http://91.99.130.85:8084/api` (staging) |
| Mock data | **OFF** — real API calls only |

---

## File Structure

```
lib/
├── main.dart                    — Entry point, CoreXApp, AuthGate
├── theme.dart                   — AppTheme (colours, geometry, dark theme)
├── providers/
│   └── auth_provider.dart       — Auth state (login, logout, checkAuth)
├── screens/
│   ├── login_screen.dart        — Login form (email/password)
│   └── home_screen.dart         — Empty home screen (greeting + logout)
├── services/
│   └── api_service.dart         — HTTP client (login, profile, properties endpoints)
└── widgets/
    ├── quick_action_card.dart   — UNUSED (was for old tab grid)
    └── property_list_tile.dart  — UNUSED (was for old properties tab)
```

---

## What's Built

### Authentication (working)
- Native login screen with email/password
- Calls `POST /api/login` on staging server
- Stores Sanctum Bearer token in SharedPreferences
- Auto-login on app restart via `checkAuth()` (reads stored token, fetches profile)
- Logout clears token and returns to login screen

### Home Screen (minimal)
- Shows time-based greeting ("Good morning/afternoon/evening")
- Shows authenticated user's name
- "CoreX OS" watermark centered
- Logout button at bottom

### Theme/Design System (done)
- Dark theme matching CoreX web app
- Background: `#0D0F14`, Surface: `#13161D`, Surface 2: `#1A1E28`
- Brand Blue: `#0EA5E9`, Brand Dark: `#0B2A4A`
- Text: Primary `#EEF0F5`, Secondary `#8890A4`, Muted `#545B6E`
- Border radius: 6px everywhere
- Font: Inter via google_fonts
- Inputs: surface fill, no border, focus ring in brand blue
- Buttons: brand blue, white text, semibold, full-width, 48px height

### API Service (done)
- Base URL: `http://91.99.130.85:8084/api`
- Bearer token sent in Authorization header
- Endpoints implemented: login, profile, properties (properties not used in UI currently)
- Mock data available but disabled

---

## What's NOT Built Yet

### Navigation
- No bottom navigation bar (removed intentionally — will be added back with custom tabs)
- No sidebar or drawer

### Screens needed
- **Command Center Dashboard** — main home screen with overdue popup, today's agenda, tasks, property health, scorecard, mini calendar
- **Calendar** — month grid + agenda view, create events, complete/dismiss events
- **Tasks** — task list with swipe actions, create tasks, filter by status
- **User Settings** — reminder preferences, calendar preferences, notification toggles
- Properties list
- Contacts list
- Deals list
- Agent profile/dashboard
- Document viewer
- Notifications

### Features needed
- Push notifications
- Offline support / data caching
- Pull-to-refresh patterns
- Image/photo upload (for properties, documents)
- Deep linking
- Biometric login (fingerprint/face)
- Light theme toggle (dark only for now)

### API endpoints needed on web app

**Command Center (priority — build these first):**
- `GET /api/command-center/dashboard` — dashboard summary (today events, overdue, tasks, health scores, scorecard, activity points)
- `GET /api/command-center/calendar?year=&month=` — calendar events for month
- `GET /api/command-center/calendar/events?start=&end=` — events in date range (JSON)
- `POST /api/command-center/calendar` — create event (title, event_date, event_type, priority, send_reminder, description, property_id)
- `POST /api/command-center/calendar/{id}/complete` — mark event completed
- `POST /api/command-center/calendar/{id}/dismiss` — dismiss event
- `GET /api/command-center/tasks` — task list (filterable by status)
- `POST /api/command-center/tasks` — create task (title, task_type, priority, due_date, send_reminder, description, property_id)
- `POST /api/command-center/tasks/{id}/complete` — mark task done
- `PATCH /api/command-center/tasks/{id}/status` — update task status (todo/in_progress/awaiting/done)
- `POST /api/command-center/resolve-task/{id}` — resolve overdue task (resolution: completed/extended/did_not_happen, extend_days)
- `POST /api/command-center/resolve-event/{id}` — resolve overdue event (same params)
- `GET /api/command-center/user-settings` — get user's dashboard settings
- `PUT /api/command-center/user-settings` — save user's dashboard settings

**Other modules:**
- `GET /api/properties` — list properties (endpoint exists in api_service.dart but not in Laravel routes yet)
- `GET /api/contacts` — list contacts
- `GET /api/deals` — list deals
- `GET /api/notifications` — notification feed

### Command Center Data Shapes (for Flutter models)

**Dashboard response:**
```json
{
  "user": { "id": 1, "name": "Johan" },
  "mtd_points": 245,
  "monthly_target": 300,
  "today_events": [
    { "id": 1, "title": "Viewing — 12 Beach Rd", "event_date": "2026-03-31T09:00:00", "all_day": false, "colour": "#3b82f6", "event_type": "deal", "property_id": 42, "status": "pending" }
  ],
  "overdue_events": [...],
  "overdue_tasks": [...],
  "week_summary": { "total": 18, "by_type": { "deal": 5, "lease": 3 } },
  "my_tasks": [
    { "id": 1, "title": "Upload mandate", "task_type": "document_upload", "status": "todo", "priority": "high", "send_reminder": true, "due_date": "2026-04-01T00:00:00", "property_id": 42, "property_address": "12 Beach Rd, Shelly Beach" }
  ],
  "task_summary": { "today": 2, "overdue": 3, "this_week": 7, "open": 12 },
  "property_health": [
    { "property_id": 42, "address": "12 Beach Rd", "score": 58, "grade": "attention", "top_issue": "No activity in 16 days", "agent": "John D." }
  ],
  "health_summary": { "critical": 3, "attention": 8, "good": 24 },
  "scorecard": { "overall_score": 72, "tasks_completed": 12, "tasks_total": 15, "properties_attended": 8, "properties_total": 12, "events_completed": 10, "events_total": 12, "documents_uploaded": 5 },
  "overdue_popup_tasks": [...],
  "overdue_popup_events": [...]
}
```

**Task create request:**
```json
{
  "title": "Call attorney re: transfer",
  "task_type": "follow_up",
  "priority": "normal",
  "due_date": "2026-04-01",
  "send_reminder": true,
  "description": null,
  "property_id": 42
}
```

**Event create request:**
```json
{
  "title": "Property viewing",
  "event_date": "2026-04-01T14:00:00",
  "event_type": "manual",
  "priority": "normal",
  "send_reminder": true,
  "description": null,
  "property_id": 42
}
```

**Resolve overdue request:**
```json
{
  "resolution": "completed|extended|did_not_happen",
  "extend_days": 7,
  "resolution_note": "optional note"
}
```

**User settings shape:**
```json
{
  "idle_alerts_enabled": true,
  "idle_threshold_days": 14,
  "idle_alert_day": "wednesday",
  "idle_alert_time": "08:00",
  "doc_reminders_enabled": true,
  "doc_reminder_hours_before": 24,
  "lease_expiry_reminders": true,
  "lease_reminder_days_before": 90,
  "fica_reminders": true,
  "ffc_reminders": true,
  "task_due_reminders": true,
  "task_reminder_hours_before": 4,
  "event_reminder_hours_before": 24,
  "default_calendar_view": "month",
  "weekend_visible": false,
  "working_hours_start": "08:00",
  "working_hours_end": "17:00",
  "notify_in_app": true,
  "notify_email": true,
  "is_agency_controlled": false
}
```

---

## Backend Requirements (Laravel side)

All API routes live in `routes/api.php`. See `.ai/API.md` for current endpoints.

Currently available:
- `POST /api/login` — Sanctum token auth
- `GET /api/profile` — authenticated user profile
- `POST /api/logout` — revoke token

New endpoints must be added to both:
1. `routes/api.php` (Laravel)
2. `lib/services/api_service.dart` (Flutter)
3. `.ai/API.md` (documentation)

---

## Unused Files (can be cleaned up)

These files remain from the old tabbed layout and are no longer imported:
- `lib/widgets/quick_action_card.dart`
- `lib/widgets/property_list_tile.dart`
- `lib/screens/properties_screen.dart` (if still exists)
- `lib/screens/profile_screen.dart` (if still exists)
