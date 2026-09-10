# Mobile App Prompt — Contact Notes & Testimonials

> Paste the section below into the Claude session running in the **mobile app repo**.
> The CoreX OS web side (routes + controller + tests) is built on the `QA2` branch.
> Do not build against this until Johan has promoted it past QA — check with him first
> if you're unsure which environment/host to point at.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Add **Notes** and **Testimonials** to the Contact detail screen, syncing to the CoreX OS
backend. The web app already has this feature live on a Contact's page (the "Notes &
Testimonials" tab) — this brings full parity to mobile: an agent can add, edit, and delete
both from their phone, and it's the exact same database record the website shows, so a note
written on one shows on the other immediately (no client-side merge logic needed — just
re-fetch).

### Data shapes

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
`type` is optional and, when present, is always one of a fixed quick-pick list: `Contacted`,
`Viewing booked`, `Viewing done`, `Offer discussed`, `Not interested`, `Follow up later`. A note
needs at least one of `type` or `body` — both empty is rejected (422).

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
`published` is always `false` for anything captured from an app — testimonials only go live on
the public website when a staff member explicitly publishes them from Company Settings. Never
show a "publish" control on mobile; there isn't one.

### Endpoints (sanctum bearer token, same auth as the existing mobile contacts endpoints)

Base: `{API_BASE}/api/v1/mobile/contacts/{contactId}`

| Method | Path | Body | Returns |
|---|---|---|---|
| GET    | `/notes` | — | `{ notes: Note[] }` (newest first) |
| POST   | `/notes` | `{ type?: string, body?: string }` | `{ note: Note }` (201) |
| PUT    | `/notes/{noteId}` | `{ type?: string, body?: string }` | `{ note: Note }` |
| DELETE | `/notes/{noteId}` | — | `{ ok: true }` |
| GET    | `/testimonials` | — | `{ testimonials: Testimonial[] }` (newest first) |
| POST   | `/testimonials` | `{ body: string, display_name?: string, rating?: 1-5, agent_id?: number }` | `{ testimonial: Testimonial }` (201) |
| PUT    | `/testimonials/{testimonialId}` | same as POST | `{ testimonial: Testimonial }` |
| DELETE | `/testimonials/{testimonialId}` | — | `{ ok: true }` |

Headers: `Authorization: Bearer <token>`, `Accept: application/json`.

- `422` — validation failed (e.g. neither `type` nor `body` on a note, or `body` missing on a
  testimonial). Show the field error(s) inline.
- `401` — token expired: trigger the existing re-login flow.
- `403` — the contact is visible but outside the signed-in agent's *edit* access (the
  assistant-narrower-than-view case: an assistant can see a colleague agent's contact but not
  write to it). Show "You don't have permission to edit this contact." and disable the
  Add/Edit/Delete affordances rather than retrying.
- `404` — either the contact id itself is outside the agent's visibility entirely (a different
  agency, or an out-of-scope contact), or a note/testimonial id doesn't belong to the given
  contact. Treat both like stale-local-state: re-fetch, don't show a permission error.

### UI

On the existing **Contact detail screen**, add (or extend, if a "Notes" section already exists
read-only) a **Notes & Testimonials** section with two independent parts, matching the web's
layout:

- **Notes**: a text input (+ an optional quick-pick dropdown/chips for `type`, using the fixed
  list above) and an "Add Note" button at the top, then a list of notes newest-first — each
  showing author, relative timestamp, the type chip if set, and the body. Every note gets an
  Edit and a Delete action (not author-restricted — anyone who can edit this contact can edit or
  delete any note on it, same as the web).
- **Testimonials**: a form (body textarea, display name, 1-5 star rating picker, optional
  "about which agent" picker) to add one, then a list showing display name, stars, the
  capturing user + date, "About {agent}" if set, a `Not published` badge (testimonials never
  arrive published from this API), and Edit/Delete actions.
- Optimistic UI: append/update/remove locally first, reconcile with the server response; on
  error, revert and show a toast with the error message.
- Pull-to-refresh re-fetches both `/notes` and `/testimonials`.

### Sync rules (web ↔ mobile)

- Both clients write to the same DB via the same endpoints — no client-side merging needed.
- Re-fetch on screen mount and on pull-to-refresh.
- Do not gate Edit/Delete by "is this my note" — CoreX's edit permission on a contact is
  agent/agency-scoped, not per-note-author (unlike, say, Command Center task notes, which ARE
  author-restricted — don't copy that pattern here).

### Acceptance

- Adding a note or testimonial on web is visible on mobile after pull-to-refresh, and
  vice-versa.
- Editing a note's body on mobile does not clear an existing `type` if the edit UI you build
  only sends `body` — but if you send both fields together that's fine too; just don't send
  `type: null` unless the user explicitly cleared it.
- A testimonial's `agent_id` outside the contact's own agency is silently ignored server-side
  (falls back to the capturing user) — don't rely on client-side validation of that list, the
  agent picker you show should already only offer this agency's agents.

### Files to look at / modify (typical mobile structure)

- API client module (where `MobileContact`, `MobileProperty`, etc. calls live) — add
  `contactNotes` and `contactTestimonials` services.
- Contact detail screen / view-model — add notes + testimonials state slices.
- Reuse the existing token-storage / auth interceptor — do not roll new auth.

### Spec source of truth

Full spec on the backend repo: `.ai/specs/contact-notes-testimonials.md`. If anything below
conflicts with that file, that file wins.

## ▲▲▲ END COPY-PASTE ▲▲▲
