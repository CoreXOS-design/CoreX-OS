# AT-240 — Core Matches "Edit" door on every render surface (READY TO LAND)

**Date:** 2026-07-14 · **Lane:** m1 · **Branch:** `AT-240-core-matches-edit` (base origin/Staging `8cd929c8`)
**MODE:BUILD chain:** ticket **AT-240** + Johan's quoted word **"fix"** (present in prompt).
**Status:** BUILT + PROVEN on corex-dev (real vendor). Deploy held for conductor GO (dual-deploy).

---

## Findings (investigate-first)
- **The edit FLOW already exists end-to-end.** `resources/views/corex/contacts/_match-form.blade.php`
  supports edit mode (`$isEdit` → pre-fills from `$match`, `$formAction = matches.update`, `@method('PUT')`,
  self-contained Alpine scope). `ContactMatchController::update()` does a full `validatePayload()` update.
  The **only** gap was a missing **entry point** — no GET edit route/page, and no Edit button on any surface.
- **Render sites of a saved match that lacked an Edit door** (the class):
  1. `corex/contacts/show.blade.php` — contact-record Core Matches (had: Make Primary / View / Delete).
  2. `corex/core-matches/index.blade.php` — Core Matches page (had: View Matches only).
  3. `corex/core-matches/all.blade.php` — oversight All-view (had: View Matches only).
  4. `corex/contacts/match-results.blade.php` — criteria header (had: no criteria edit).
  The buyer-pipeline **Wishlists tab already edits** via `_match-form` (own routes) — not a gap.
- **"Edit" correctly targets** the buyer wishlist/criteria flow: `_match-form(match=$match)` → PUT
  `corex.contacts.matches.update`. Confirmed with the reporter's framing (a & b surfaces).
- **Permissions:** mutation routes live in the contacts group (`access_contacts`, server-side); the Add
  form is view-gated by `access_core_matches`. Edit door uses the **same** gate as Add
  (`access_core_matches`) — consistent, and dead-door-free (every user on these pages already holds
  `access_contacts`, so the update route never 403s on them).

## Build
- **Route** (sibling of matches.*): `GET /{contact}/matches/{match}/edit` → `matches.edit`.
- **Controller** `ContactMatchController::edit()` — `abort_if` cross-contact, supplies the same
  `matchCategories / matchTypes / featureOptions` the create form gets, renders the edit view.
- **View** `corex/contacts/match-edit.blade.php` — thin page (design-system compliant header) that
  reuses `_match-form` in edit mode. No new form, no new endpoint.
- **Edit door on all four render sites**, permission-aware (`access_core_matches`; surface 1 already
  inside that gate). Opens the edit page.

**No migration, no seeder, no reference data, no Vite build** (public/js untouched). Deploy = pull +
clears + fpm reload.

## Proof (verify chain + rendered-door check, both surfaces)
`tests/Feature/CoreX/CoreMatchEditDoorTest.php` — **5 tests, 13 assertions, green** (`OK`):
- `contact_record_surface_renders_edit_door` — real GET of the contact page shows the door (surface a).
- `core_matches_page_renders_edit_door` — real GET of the Core Matches page shows the door (surface b).
- `edit_door_hidden_without_core_matches_permission` — a `viewer` (access_contacts, no
  access_core_matches) reaches the page but the door is **absent** (permission-aware; No Silent Locks —
  hidden, not dead).
- `edit_page_renders_prefilled_edit_form` — GET edit → 200, pre-filled name + `value="PUT"` + posts to
  matches.update (the door opens the real edit flow).
- `update_persists_edited_criteria` — PUT update → redirect to results + DB carries the edited values.
- `php artisan view:cache` — all Blade templates compile (covers all.blade + match-results, which the
  HTTP tests don't render). `php -l` clean on the controller + routes.
- Live rendered-door check as **Johan's account** on QA1 to be done post-deploy (as prior tickets).

## Spec conformance
**No governing spec** for the Core-Matches-surface Edit door specifically. The edit flow it opens
(`_match-form` / `matches.update`) is the same one the buyer-pipeline **Wishlists** spec governs
(referenced as **D5/D8** in `_match-form`). The change upholds **STANDARDS "No Invisible Edits / editable
state self-evident"** and **"No Silent Locks — offer the action"**, and **Non-Negotiable #5**
(permissions on every feature). No deviation.

## Files
- `app/Http/Controllers/CoreX/ContactMatchController.php` (edit method + import)
- `routes/web.php` (matches.edit)
- `resources/views/corex/contacts/match-edit.blade.php` (new)
- `resources/views/corex/contacts/show.blade.php`, `corex/core-matches/index.blade.php`,
  `corex/core-matches/all.blade.php`, `corex/contacts/match-results.blade.php` (Edit door)
- `tests/Feature/CoreX/CoreMatchEditDoorTest.php` (new)
