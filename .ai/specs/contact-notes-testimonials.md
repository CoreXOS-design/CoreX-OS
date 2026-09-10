# Spec — Contact Notes & Testimonials: note editing + Mobile API

> Status: Approved (Johan, 2026-09-08 — "Add editing everywhere" chosen for note editing)
> Module: Contacts → contact detail page, "Notes & Testimonials" tab
> Pillars: **Contact** (primary), **Agent** (author/user_id)

## What exists today (unchanged by this spec unless noted)

The Contact detail page (`/corex/contacts/{contact}#tab-notes`) has one tab, "Notes &
Testimonials", with two independent sections:

1. **Notes** — free-text notes (optionally tagged with a quick-pick `type` like
   "Contacted", "Viewing booked") on a contact. Model `App\Models\ContactNote`
   (table `contact_notes`, `SoftDeletes`, `BelongsToAgency`). Web: add + delete only
   (`ContactNoteController::store/destroy`, routes `corex.contacts.notes.store` /
   `.destroy`). **No edit existed before this spec.**
2. **Testimonials** — client testimonials captured by an agent, never auto-published
   (publishing is a separate Company Settings → Website action).
   Model `App\Models\ContactTestimonial` (table `contact_testimonials`, `SoftDeletes`,
   `BelongsToAgency`). Web: full add/edit/delete
   (`ContactTestimonialController::store/update/destroy`).

Both are reached via `Contact::contactNotes(): HasMany` / `Contact::testimonials(): HasMany`
(both `->latest()`). Authorization on every write is
`App\Http\Controllers\Concerns\AuthorizesContactAccess::authorizeContact()` — the per-record
mutation check already used everywhere else on the Contact pillar (non-assistants: same as
their view scope; assistants: own-agent's contacts, or an unowned contact, only). Route-group
gate is `permission:access_contacts` (no separate `notes.*`/`testimonials.*` permission key
exists or is needed — capture rides on the existing contacts-access gate, same as before).

## What this spec adds

### 1. Note editing (web) — new capability

Johan's call (2026-09-08): note editing becomes a real feature on **both** clients, not a
mobile-only convenience, so a note edited on the phone shows the same edit on the website and
vice versa.

- New route `PUT /{contact}/notes/{note}` → `ContactNoteController::update()`, name
  `corex.contacts.notes.update`.
- Validation identical in shape to `store()`: `type` (nullable, `required_without:body`, one of
  `ContactNote::QUICK_PICK_TYPES`), `body` (nullable, `required_without:type`, max 5000).
- Same `abort_unless($note->contact_id === $contact->id, 404)` guard as `destroy()`.
- UI: `_note-item.blade.php` gains an inline Edit affordance identical in structure to the
  Testimonials edit pattern already on this same tab (`x-data="{ editing:false }"`, an Edit
  button that toggles a `x-show="editing"` form with `@method('PUT')`, Cancel/Save buttons) —
  copied, not reinvented, so the tab stays visually consistent.

### 2. Mobile API — agent-facing CRUD on notes & testimonials

New endpoints under the existing canonical `v1/mobile/contacts/{contact}/...` surface
(`routes/api.php`, inside `Route::middleware(['auth:sanctum','app_access'])->prefix('v1')->prefix('mobile/contacts')`),
mirroring the `MobileContactController` / `CommandTaskNotesController` conventions: plain JSON
array responses (no API Resource classes — matches every existing mobile contact endpoint),
`auth:sanctum` + `app_access` middleware only (no extra permission key — matches the rest of
`mobile/contacts/*`, which never carried `permission:access_contacts`).

**Authorization decision:** uses `AuthorizesContactAccess::authorizeContact()` — the same trait
web uses — rather than `MobileContactController`'s own narrower `created_by_user_id === user->id`
check. This is a deliberate, scoped choice: the ask was parity ("just like on the web"), and
notes/testimonials are collaborative artifacts on a contact (any agent who can edit the contact
on the web can leave a note on it), unlike the core identity fields on `PUT
/mobile/contacts/{contact}` which stay creator-only. **Business consequence:** on mobile, adding
or editing a note/testimonial is allowed for anyone who could do it on the web for that contact
(their own contacts, plus any contact within their normal edit scope) — not restricted to
contacts they personally created, unlike editing a contact's name/phone on mobile.

| Method | Path | Controller method | Mirrors |
|---|---|---|---|
| GET    | `/api/v1/mobile/contacts/{contact}/notes` | `MobileContactNotesController::notesIndex` | new |
| POST   | `/api/v1/mobile/contacts/{contact}/notes` | `notesStore` | `ContactNoteController::store` |
| PUT    | `/api/v1/mobile/contacts/{contact}/notes/{note}` | `notesUpdate` | `ContactNoteController::update` (new, §1) |
| DELETE | `/api/v1/mobile/contacts/{contact}/notes/{note}` | `notesDestroy` | `ContactNoteController::destroy` |
| GET    | `/api/v1/mobile/contacts/{contact}/testimonials` | `testimonialsIndex` | new |
| POST   | `/api/v1/mobile/contacts/{contact}/testimonials` | `testimonialsStore` | `ContactTestimonialController::store` |
| PUT    | `/api/v1/mobile/contacts/{contact}/testimonials/{testimonial}` | `testimonialsUpdate` | `ContactTestimonialController::update` |
| DELETE | `/api/v1/mobile/contacts/{contact}/testimonials/{testimonial}` | `testimonialsDestroy` | `ContactTestimonialController::destroy` |

Testimonial field-resolution rules (`resolveAgentId`, `resolveDisplayName`, the validation
array) are extracted from `ContactTestimonialController` into a shared trait
`App\Http\Controllers\Concerns\ResolvesTestimonialFields`, used by both the web controller and
the new mobile controller, so the two never drift (agent-must-belong-to-contact's-agency,
display_name fallback chain, capture-never-publishes).

Routes registered ONLY in the canonical `v1/mobile/contacts` group (not the parallel
`// LEGACY: remove after 2026-08-21` duplicate block further down `routes/api.php` — that block
is past its stated removal date and out of this task's scope; new mobile builds target `v1/`).

Named routes under `api/v1/...` → auto-discovered by `Admin\ApiCatalogController` and appear in
`/admin/api` under "API v1 — Mobile" (non-negotiable #7 — no manual registration needed).

### Response shapes

**Note**
```json
{
  "id": 12,
  "contact_id": 8534,
  "type": "Viewing booked",
  "body": "Showed the property, client liked the kitchen.",
  "user_id": 5,
  "user_name": "Jane Agent",
  "created_at": "2026-09-08T10:15:00+02:00",
  "updated_at": "2026-09-08T10:15:00+02:00"
}
```

**Testimonial**
```json
{
  "id": 3,
  "contact_id": 8534,
  "body": "Jane made the whole process painless.",
  "display_name": "Sam Buyer",
  "rating": 5,
  "agent_id": 12,
  "agent_name": "Jane Agent",
  "user_id": 5,
  "user_name": "Jane Agent",
  "published": false,
  "created_at": "2026-09-08T10:15:00+02:00",
  "updated_at": "2026-09-08T10:15:00+02:00"
}
```

## Data model

No migration — reuses `contact_notes` and `contact_testimonials` exactly as they exist. Both
already carry `agency_id` (multi-tenancy) and `SoftDeletes` (non-negotiable #1).

## User flow

```
Mobile: contact detail screen → Notes & Testimonials tab
   ├─ GET notes + GET testimonials on screen load / pull-to-refresh
   ├─ Add note (type + body)        → POST .../notes         → appears on web instantly
   ├─ Edit own or colleague's note  → PUT .../notes/{id}      → same record web shows
   ├─ Delete a note                 → DELETE .../notes/{id}
   ├─ Add testimonial (+ rating)    → POST .../testimonials
   ├─ Edit a testimonial            → PUT .../testimonials/{id}
   └─ Delete a testimonial          → DELETE .../testimonials/{id}
Web: same tab, same records, refresh shows mobile's writes and vice versa (same DB, same
     validation rules, same authorization trait — no client-side merge logic needed).
```

## Permissions

No new permission key. Gated by:
- Mobile: `auth:sanctum` + `app_access` (route group) + `AuthorizesContactAccess::authorizeContact()` (per-record, in-controller).
- Web: `permission:access_contacts` (route group, unchanged) + the same trait.

## Acceptance criteria

- [ ] `PUT /corex/contacts/{contact}/notes/{note}` edits a note's `type`/`body` on web; Blade UI
      shows an Edit affordance on each note matching the Testimonials pattern.
- [ ] All 8 new `v1.mobile.contacts.{notes,testimonials}.*` routes resolve and appear in `/admin/api`.
- [ ] A note or testimonial created/edited/deleted via the mobile endpoints is visible on the
      web Contact → Notes & Testimonials tab on next page load, and vice versa.
- [ ] `AuthorizesContactAccess` blocks a write outside the caller's mutation scope (403) on both
      web and mobile, including the assistant-narrower-than-view case.
- [ ] Testimonial `agent_id` outside the contact's agency is rejected the same way on mobile as
      on web (`resolveAgentId` falls back to the capturing user — shared trait, not reimplemented).
- [ ] `php -l` clean on all changed/new PHP files.
- [ ] `php artisan route:list --path=api/v1/mobile/contacts` shows the 8 new routes.
- [ ] Feature tests: `tests/Feature/Contacts/ContactNoteUpdateTest.php` (web edit) and
      `tests/Feature/Contacts/MobileContactNotesTestimonialsTest.php` (mobile CRUD + scope +
      cross-tenant rejection), run individually — pass with 0 new failures.

## Files

### Created
- `app/Http/Controllers/Api/MobileContactNotesController.php`
- `app/Http/Controllers/Concerns/ResolvesTestimonialFields.php`
- `tests/Feature/Contacts/ContactNoteUpdateTest.php`
- `tests/Feature/Contacts/MobileContactNotesTestimonialsTest.php`
- `.ai/specs/contact-notes-testimonials.md` (this file)
- `.ai/specs/contact-notes-testimonials-MOBILE-PROMPT.md`

### Modified
- `app/Http/Controllers/CoreX/ContactNoteController.php` — added `update()`
- `app/Http/Controllers/CoreX/ContactTestimonialController.php` — refactored to use `ResolvesTestimonialFields`
- `routes/web.php` — added `notes.update`
- `routes/api.php` — added 8 `v1.mobile.contacts.{notes,testimonials}.*` routes
- `resources/views/corex/contacts/_note-item.blade.php` — inline edit UI
