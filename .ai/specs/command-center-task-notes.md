# Spec — Command Center Task Notes & Checklist

> Status: Approved (verbal, 2026-05-04)
> Module: Command Center → Tasks
> Pillars: **Agent** (assigned_to), optional **Property / Contact / Deal** via existing task linkages

## What this feature does and why

When an agent clicks a task on `/corex/command-center/tasks` (or a task card on the kanban board, or a task row in list view), a detail panel opens showing:

1. **Threaded notes** — append-only conversation thread on the task. Each note has an author, timestamp, body. Authors may delete their own notes.
2. **Checklist** — a list of subtasks each agent can tick off as they complete them. Each item has `id`, `text`, `done`.

The same data is exposed via API so the mobile app can read/write notes and checklist items, and the two clients stay in sync (same DB, same endpoints).

## Pillar connections

- **Agent (User)**: notes carry `user_id` (author); only the assigned agent / assigner / same agency can read.
- **Task** is the container; tasks already link to Property / Contact / Deal so notes inherit that context.

## Data model

### New table: `command_task_notes`

| Column           | Type         | Notes                                  |
|------------------|--------------|----------------------------------------|
| id               | bigint PK    |                                        |
| command_task_id  | FK → command_tasks | cascade on delete                |
| user_id          | FK → users   | author                                 |
| body             | text         | required, max 10 000 chars             |
| agency_id        | bigint, idx  | denormalized for agency scoping        |
| created_at       | timestamp    |                                        |
| updated_at       | timestamp    |                                        |
| deleted_at       | timestamp    | SoftDeletes — non-negotiable rule #1   |

### Existing column reused: `command_tasks.checklist` (json)

Stored as `[ { "id": "<uuid>", "text": "…", "done": false }, … ]`. Already in the model with `array` cast.

## API surface

All endpoints under `/api/v1/command-center/tasks/{task}/…` (session auth, registered in `routes/web.php` with names — appears in `/admin/api` catalog) AND mirrored under `/api/command-center/tasks/{task}/…` (sanctum auth, `routes/api.php`) for the mobile app.

### Notes

| Method | Path                                | Purpose            |
|--------|-------------------------------------|--------------------|
| GET    | `/notes`                            | List notes (newest first) |
| POST   | `/notes`                            | Create note `{ body }` |
| PUT    | `/notes/{note}`                     | Edit (author only) |
| DELETE | `/notes/{note}`                     | Delete (author only) |

### Checklist

| Method | Path                                 | Purpose             |
|--------|--------------------------------------|---------------------|
| GET    | `/checklist`                         | List items          |
| POST   | `/checklist`                         | Add item `{ text }` |
| PATCH  | `/checklist/{itemId}`                | Toggle/edit `{ done?, text? }` |
| DELETE | `/checklist/{itemId}`                | Remove item         |

## UI placement

- **Page**: `/corex/command-center/tasks` (existing).
- **Trigger**: clicking anywhere on a task row (list view) or task card (kanban) that isn't a button/link/form opens a centered modal panel.
- **Panel layout**: header with task title + due date; Checklist section (with progress `n / total`, add input, toggle, remove); Notes section (textarea + Add Note, then thread newest-first).
- **Navigation entry**: not required — feature lives on existing Tasks page (rule #2 satisfied).

## Permissions

Authorization is in-controller: a user can read/write notes & checklist on a task if they are `assigned_to`, `assigned_by`, or in the same `agency_id`. No new permission keys required.

## User flow

1. Agent opens Tasks page.
2. Clicks a task row/card → modal opens, fetches `/notes` and `/checklist` in parallel.
3. Adds a checklist item → POST → appended to list.
4. Ticks a checklist item → PATCH `{ done: true }` → strike-through, progress updates.
5. Adds a note → POST → prepended to thread.
6. Deletes own note → DELETE.
7. Same flow on mobile via sanctum token.

## Acceptance criteria

- [ ] Migration creates `command_task_notes` with soft deletes.
- [ ] Clicking a task on web opens the panel.
- [ ] Notes round-trip: add, list, delete (own only).
- [ ] Checklist round-trip: add, toggle, delete.
- [ ] Mobile-app endpoints respond identically under `/api/command-center/tasks/{task}/notes` (and checklist) with sanctum token.
- [ ] All `/api/v1/command-center/tasks/{task}/*` endpoints appear in `/admin/api` catalog (named).
- [ ] `dev-check.ps1` passes with 0 new failures.

## Files

### Created
- `database/migrations/2026_05_04_193122_create_command_task_notes_table.php`
- `app/Models/CommandCenter/CommandTaskNote.php`
- `app/Http/Controllers/Api/CommandTaskNotesController.php`
- `.ai/specs/command-center-task-notes.md` (this file)

### Modified
- `app/Models/CommandCenter/CommandTask.php` — added `notes()` HasMany relation
- `routes/web.php` — added `/api/v1/command-center/tasks/{task}/notes|checklist` routes
- `routes/api.php` — added sanctum-auth mirror for mobile
- `resources/views/command-center/tasks/index.blade.php` — added detail modal + Alpine state + click handlers
- `resources/views/command-center/partials/task-card.blade.php` — added click handler on cards
