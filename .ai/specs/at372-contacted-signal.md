# AT-372 — "Contacted" is an explicit signal, never a side effect of a pitch

Status: BUILT (QA1) — 2026-08-05. Pillar: **Contact**. Builds on the AT-323 counting model.

## Business requirement
An agent's "Last Contacted" must reflect genuine contact, not an attempted-but-not-sent
pitch. Sending a WhatsApp that the agent then confirms as **not sent** must NOT mark the
contact as contacted. "Contacted" is set only by:
- (a) a **modal-confirmed sent** communication (AT-323 `send_status = sent`), or
- (b) an **explicit agent "contacted" action** (Mark as Now / Pick Date / Mark contacted + note).

## Model — the key architectural point
Before AT-372, `contacts.last_contacted_at` was re-derived (`recomputeLastContacted`) PURELY
from sent comms, so any explicit mark was wiped by the next send's recompute. AT-372 makes the
explicit action a first-class signal:

- **New column** `contacts.contacted_marked_at` (nullable datetime) — the explicit "agent
  marked contacted" timestamp.
- **`last_contacted_at` is DERIVED** = `max(contacted_marked_at, latest sent-comm occurred_at)`.
  `recomputeLastContacted()` now takes the max of BOTH signals, so neither wipes the other and
  a not-sent send (send_status ≠ sent) never contributes.
- `Contact::markContacted($at = null)` sets `contacted_marked_at` and recomputes.

## Surfaces (both write the SAME signal + note — one endpoint, no parallel systems)
1. **Last Contacted tile** (`info` tab) — existing "Mark as Now" + "Pick Date" now route through
   `markContacted` (so they persist). NEW third control **"+ Contacted & note"** opens a modal
   with a feedback textarea; confirm → POST `contacts.notes.store` with `mark_contacted=1`,
   `redirect_to=info` → writes the note AND marks contacted, tile updates.
2. **Notes tab** — the Add-note form gains a second submit **"Add note & mark contacted"**
   (`mark_contacted=1`) → saves the note AND marks contacted; the Last Contacted tile reflects it.

Both post to `ContactNoteController::store`, which creates the note and (when `mark_contacted`)
calls `markContacted()`. Sync is by construction — one endpoint, one signal, one Note table.

## The not-sent fix
`ContactController::incrementChannel` already births a WhatsApp comm `not_delivered` and calls
`recomputeLastContacted()` (AT-323). With the new max-of-both recompute, a not-sent send leaves
`last_contacted_at` at `max(explicit, sent)` — unchanged by the attempt. A modal "Yes"
(`markSent`) recomputes and picks up the new sent comm.

## Files
- `database/migrations/*_add_contacted_marked_at_to_contacts.php` (new column)
- `app/Models/Contact.php` — cast + fillable + `markContacted()`; `recomputeLastContacted()` max-of-both
- `app/Http/Controllers/CoreX/ContactController.php` — `touch()` → `markContacted`
- `app/Http/Controllers/CoreX/ContactNoteController.php` — `store()` optional `mark_contacted` + `redirect_to`
- `resources/views/corex/contacts/show.blade.php` — tile "+ Contacted & note" modal; Notes second submit

## Acceptance
(a) tile → "+ Contacted & note" → modal → confirm → `last_contacted_at`=now AND a Note appears;
(b) Notes → "Add note & mark contacted" → note saved AND tile updates;
(c) a not-sent WhatsApp attempt leaves `last_contacted_at` unchanged;
(d) a modal-confirmed sent comm DOES update `last_contacted_at`.

## Deliberately NOT in scope
The Performance-report prospected-vs-contacted split (cc3's lane). Email flows unchanged.
