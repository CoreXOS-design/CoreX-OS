# Mobile App Prompt — Task Notes & Checklist

> Paste the section below into the Claude session running in the **mobile app repo**.
> The CoreX OS web side (web routes + API + DB migration) is already built and merged on `Staging`.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Add **Task Notes (threaded)** and **Task Checklist** to the Command Center → Tasks screen, syncing to the CoreX OS backend at `https://corex.hfcoastal.co.za`. The web app already has these features live; this brings parity to mobile.

### Data shapes

**Note**
```json
{
  "id": 12,
  "body": "Called the seller, awaiting bank confirmation",
  "user_id": 5,
  "user_name": "Jane Agent",
  "created_at": "2026-05-04T18:30:12+02:00",
  "updated_at": "2026-05-04T18:30:12+02:00"
}
```

**Checklist item** (lives inside the task; not its own table)
```json
{ "id": "f3a1-…-uuid", "text": "Upload FICA docs", "done": false }
```

### Endpoints (sanctum bearer token, same auth as existing mobile endpoints)

Base: `https://corex.hfcoastal.co.za/api/command-center/tasks/{taskId}`

| Method | Path                              | Body                       | Returns           |
|--------|-----------------------------------|----------------------------|-------------------|
| GET    | `/notes`                          | —                          | `{ notes: Note[] }` (newest first) |
| POST   | `/notes`                          | `{ body: string }`         | created `Note` (201) |
| PUT    | `/notes/{noteId}`                 | `{ body: string }`         | updated `Note` (author only) |
| DELETE | `/notes/{noteId}`                 | —                          | `{ ok: true }` (author only) |
| GET    | `/checklist`                      | —                          | `{ items: ChecklistItem[] }` |
| POST   | `/checklist`                      | `{ text: string }`         | created `ChecklistItem` (201) |
| PATCH  | `/checklist/{itemId}`             | `{ done?: bool, text?: string }` | updated `ChecklistItem` |
| DELETE | `/checklist/{itemId}`             | —                          | `{ ok: true }` |

Headers: `Authorization: Bearer <token>`, `Accept: application/json`.
On 401: token expired — trigger re-login flow (existing pattern).
On 403: not allowed to view this task — show "You don't have access to this task." and pop back.

### UI

- On the existing **Task detail screen** (or, if a task list is the only screen, on tap of a task, push a new TaskDetailScreen):
  - Header: task title, due date, status badge.
  - **Checklist section**: a list of rows, each a `Checkbox + text + (swipe to delete or trailing × icon)`. Show progress like `3 / 5`. An "Add item" input at the bottom.
  - **Notes section** below it: a multi-line text input + Send button at the top, then a list of notes (newest first), each showing author, timestamp (`timeago`), body, and a Delete button visible only if `note.user_id === currentUser.id`.
- Optimistic UI: append/remove locally first, reconcile with server response; on error, revert and show a toast.
- Pull-to-refresh on the screen re-fetches both `/notes` and `/checklist`.

### Sync rules (web ↔ mobile)

- Both clients write to the same DB via the same endpoints — no client-side merging needed.
- When the screen mounts or the user pulls to refresh, re-fetch.
- Note edits/deletes are author-restricted server-side; mirror that in the UI (only show Delete on own notes).
- Checklist items use server-generated UUIDs as the `id` — do not generate locally.

### Acceptance

- Adding a note on web is visible on mobile after pull-to-refresh, and vice-versa.
- Ticking a checklist item on either client is reflected on the other after refresh.
- Deleting another user's note returns 403 (don't expose the Delete control for it).

### Files to look at / modify (typical mobile structure)

- API client module (where `MobileProperty`, `MobileContact`, `Notification` calls live) — add `taskNotes` and `taskChecklist` services.
- Task detail screen / view-model — add notes + checklist state slices.
- Reuse the existing token-storage / auth interceptor — do not roll new auth.

### Spec source of truth

Full spec on the backend repo: `.ai/specs/command-center-task-notes.md`. If anything below conflicts with that file, that file wins.

## ▲▲▲ END COPY-PASTE ▲▲▲
