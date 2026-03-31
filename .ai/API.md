# CoreX OS — API Reference

All API routes are defined in `routes/api.php` and prefixed with `/api`.
Authentication uses **Laravel Sanctum** (Bearer token).

---

## Authentication

### POST `/api/login`
Authenticate a user and receive a Sanctum token.

**Auth required:** No

**Request body:**
```json
{
  "email": "user@hfcoastal.co.za",
  "password": "secret"
}
```

**Success response (200):**
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "Johan Smith",
    "email": "johan@hfcoastal.co.za",
    "branch": "Shelly Beach",
    "ffc_status": "Active"
  }
}
```

**Error response (422):**
```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

---

### POST `/api/logout`
Revoke the current access token.

**Auth required:** Yes (`Authorization: Bearer {token}`)

**Success response (200):**
```json
{
  "message": "Logged out"
}
```

---

## User

### GET `/api/profile`
Get the authenticated user's profile.

**Auth required:** Yes (`Authorization: Bearer {token}`)

**Success response (200):**
```json
{
  "id": 1,
  "name": "Johan Smith",
  "email": "johan@hfcoastal.co.za",
  "branch": "Shelly Beach",
  "ffc_status": "Active"
}
```

---

---

## Command Center (NOT YET BUILT — endpoints to create)

> These API endpoints need to be added to `routes/api.php` to support the mobile app.
> The web app uses Blade views + form POSTs. The mobile app needs JSON API equivalents.
> All endpoints require `Authorization: Bearer {token}`.

### GET `/api/command-center/dashboard`
Get the full dashboard data for the authenticated user.

**Auth required:** Yes

**Success response (200):**
```json
{
  "user": { "id": 1, "name": "Johan Smith" },
  "mtd_points": 245,
  "monthly_target": 300,
  "today_events": [
    {
      "id": 1, "title": "Viewing — 12 Beach Rd", "event_date": "2026-03-31T09:00:00",
      "all_day": false, "colour": "#3b82f6", "event_type": "deal", "category": "viewing",
      "priority": "normal", "status": "pending", "send_reminder": true,
      "property_id": 42, "property_address": "12 Beach Rd, Shelly Beach"
    }
  ],
  "overdue_events": [],
  "overdue_tasks": [],
  "week_summary": { "total": 18, "by_type": { "deal": 5, "lease": 3, "manual": 10 } },
  "my_tasks": [
    {
      "id": 1, "title": "Upload mandate", "task_type": "document_upload",
      "status": "todo", "priority": "high", "send_reminder": true,
      "due_date": "2026-04-01T00:00:00", "property_id": 42,
      "property_address": "12 Beach Rd, Shelly Beach", "contact_name": null
    }
  ],
  "task_summary": { "today": 2, "overdue": 3, "this_week": 7, "open": 12 },
  "property_health": [
    {
      "property_id": 42, "address": "12 Beach Rd, Shelly Beach",
      "score": 58, "grade": "attention",
      "top_issue": "No activity in 16 days", "agent": "John D."
    }
  ],
  "health_summary": { "critical": 3, "attention": 8, "good": 24 },
  "scorecard": {
    "overall_score": 72, "tasks_completed": 12, "tasks_total": 15,
    "properties_attended": 8, "properties_total": 12,
    "events_completed": 10, "events_total": 12, "documents_uploaded": 5
  },
  "overdue_popup_tasks": [],
  "overdue_popup_events": []
}
```

---

### GET `/api/command-center/calendar`
Get calendar events for a month.

**Auth required:** Yes

**Query params:** `year` (int), `month` (int)

**Success response (200):**
```json
{
  "year": 2026, "month": 3,
  "events": [
    {
      "id": 1, "title": "Bond Deadline", "event_date": "2026-03-15T00:00:00",
      "end_date": null, "all_day": true, "colour": "#3b82f6",
      "event_type": "deal", "category": "bond_deadline",
      "priority": "critical", "status": "pending", "send_reminder": true,
      "property_id": 42, "contact_id": null
    }
  ],
  "by_date": {
    "2026-03-15": [ { "id": 1, "title": "Bond Deadline", "..." : "..." } ]
  }
}
```

---

### POST `/api/command-center/calendar`
Create a manual calendar event.

**Auth required:** Yes

**Request body:**
```json
{
  "title": "Property viewing",
  "event_date": "2026-04-01T14:00:00",
  "end_date": null,
  "event_type": "manual",
  "priority": "normal",
  "all_day": false,
  "send_reminder": true,
  "description": "Meeting with buyer",
  "property_id": 42,
  "contact_id": null
}
```

**Success response (201):** Returns the created CalendarEvent object.

---

### POST `/api/command-center/calendar/{id}/complete`
Mark a calendar event as completed.

**Auth required:** Yes

**Success response (200):** `{ "ok": true }`

---

### POST `/api/command-center/calendar/{id}/dismiss`
Dismiss a calendar event.

**Auth required:** Yes

**Success response (200):** `{ "ok": true }`

---

### GET `/api/command-center/tasks`
Get tasks for the authenticated user.

**Auth required:** Yes

**Query params:** `status` (optional: todo|in_progress|awaiting|done|overdue)

**Success response (200):**
```json
{
  "tasks": [
    {
      "id": 1, "title": "Upload mandate", "task_type": "document_upload",
      "status": "todo", "priority": "high", "send_reminder": true,
      "due_date": "2026-04-01T00:00:00", "started_at": null, "completed_at": null,
      "resolution": null, "property_id": 42,
      "property_address": "12 Beach Rd, Shelly Beach",
      "contact_id": null, "contact_name": null,
      "is_overdue": true
    }
  ],
  "summary": { "today": 2, "overdue": 3, "this_week": 7, "open": 12 }
}
```

---

### POST `/api/command-center/tasks`
Create a new task.

**Auth required:** Yes

**Request body:**
```json
{
  "title": "Call attorney re: transfer",
  "task_type": "follow_up",
  "priority": "normal",
  "due_date": "2026-04-01",
  "send_reminder": true,
  "description": null,
  "property_id": 42,
  "contact_id": null
}
```

**Success response (201):** Returns the created CommandTask object.

---

### POST `/api/command-center/tasks/{id}/complete`
Mark a task as done.

**Auth required:** Yes

**Success response (200):** `{ "ok": true }`

---

### PATCH `/api/command-center/tasks/{id}/status`
Update a task's status.

**Auth required:** Yes

**Request body:**
```json
{ "status": "in_progress" }
```

Valid values: `todo`, `in_progress`, `awaiting`, `done`, `dismissed`

**Success response (200):** Returns updated task object.

---

### POST `/api/command-center/resolve-task/{id}`
Resolve an overdue task.

**Auth required:** Yes

**Request body:**
```json
{
  "resolution": "completed",
  "extend_days": null,
  "resolution_note": null
}
```

`resolution` values:
- `completed` — marks task as done
- `extended` — pushes due date forward by `extend_days` (required when extended)
- `did_not_happen` — dismisses with "Did not take place" resolution

**Success response (200):** `{ "ok": true }`

---

### POST `/api/command-center/resolve-event/{id}`
Resolve an overdue calendar event. Same params as resolve-task.

**Auth required:** Yes

**Success response (200):** `{ "ok": true }`

---

### GET `/api/command-center/user-settings`
Get the authenticated user's dashboard settings.

**Auth required:** Yes

**Success response (200):**
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

### PUT `/api/command-center/user-settings`
Save the authenticated user's dashboard settings.

**Auth required:** Yes

**Request body:** Same shape as the GET response (all fields).

**Success response (200):** `{ "ok": true, "message": "Dashboard settings saved." }`

**Error (403):** When agency controls settings:
```json
{ "error": "Dashboard settings are managed by your agency administrator." }
```

---

## Technical Notes

- **Package:** Laravel Sanctum v4.3 (`laravel/sanctum`)
- **Token storage:** `personal_access_tokens` table (migration `2026_03_12`)
- **User model:** `HasApiTokens` trait added
- **Routes registered in:** `bootstrap/app.php` via `api:` parameter
- **Token name:** `corex-mobile` (used when creating tokens via login)
- **All authenticated endpoints** return 401 if token is missing/invalid
