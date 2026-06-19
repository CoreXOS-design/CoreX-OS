# Spec — Half Bathrooms on the Property Upload Wizard

> Status: building · Pillar: **Property** · Owner: Andre

## What this feature does and why

Estate-agent listings routinely have a half bathroom (a guest toilet / cloakroom —
toilet + basin, no bath or shower). Property24 and Private Property both express
this as a separate "½ bath" figure alongside the full bathroom count
(e.g. "2 + ½"). The property **upload wizard** (`properties/create-edit.blade.php`)
only had a single whole-number **Bathrooms** counter, so an agent capturing a
2½-bath home had no way to record the half.

The rich per-room spaces editor on the property **show** page already stores
half-units as a fractional `count` inside `spaces_json`, and the legacy `baths`
column is *floored* to an integer (`show.blade.php` `bathsCount` getter). That
precision is therefore trapped in `spaces_json` and invisible to the simple
wizard. This spec adds a first-class `half_baths` column so the half-bath count
is a real, queryable field captured directly on the wizard, next to Bathrooms.

## Pillars

- **Property** — reads from / writes back to the `properties` table. No other
  pillar interaction; this is a property-attribute field.

## Data model / migrations

- New column `properties.half_baths` — `tinyint unsigned NOT NULL DEFAULT 0`,
  placed `after('baths')`. Mirrors the existing `baths` / `garages` shape.
- `App\Models\Property`: add `half_baths` to `$fillable`; cast `integer`.
- Migration: `2026_06_29_000003_add_half_baths_to_properties_table.php`.

The existing `spaces_json` fractional representation is left untouched — the
show-page rich editor keeps its behaviour. `half_baths` is the wizard-owned
scalar; the show-page edit form does not submit it, so a spaces-driven update
leaves `half_baths` unchanged (validation rule is `nullable`).

## UI placement and navigation

The live upload wizard is `PropertyWizardController` + `properties/wizard.blade.php`
(route `corex.properties.wizard`), NOT the legacy `create-edit.blade.php` (which is
unreferenced — `create`/`edit` both render `show`).

- `properties/wizard.blade.php` — the Rooms steppers (Beds / Baths / Garages)
  gain a **½ Bath** stepper immediately after **Baths**. Grid widens from
  `grid-cols-3` to `grid-cols-2 sm:grid-cols-4`. `half_baths` is added to the
  Alpine `s1` state so `submitStep1()` ships it. The review summary appends
  "+ ½" when set.
- `properties/show.blade.php` — the main Baths stat and the compact summary line
  append the half-bath when `half_baths > 0` (e.g. "2 + ½"), so the captured
  value is never orphaned.
- No new page → no new navigation entry required.

## User flow

1. Agent opens the property upload wizard (step 1 — Rooms).
2. Enters Baths (full) and, if applicable, ½ Bath via the stepper.
3. Continues — `createDraft` persists `half_baths` on the draft property.
4. Property show page renders the full + half figure.

## Permissions

Uses the existing `properties` create/update permissions and route middleware.
No new permission keys (the field lives inside the already-gated wizard).

## Acceptance criteria

- The wizard shows a ½ Bath counter next to Bathrooms (create and edit).
- Saving a value persists to `properties.half_baths` and reloads on edit.
- Validation: `nullable|integer|min:0|max:20`.
- Show page renders the half-bath when present; unchanged when zero.
- A spaces-driven update from the show page does not zero out `half_baths`.
- `php -l` clean on all changed PHP; view/route/cache cleared.

## Files to create or modify

- CREATE `database/migrations/2026_06_29_000003_add_half_baths_to_properties_table.php`
- MODIFY `app/Models/Property.php` (fillable + cast)
- MODIFY `app/Http/Controllers/CoreX/PropertyWizardController.php` (createDraft validation — the live wizard)
- MODIFY `app/Http/Controllers/CoreX/PropertyController.php` (create default + store & update validation — show-mode create/edit)
- MODIFY `resources/views/corex/properties/wizard.blade.php` (½ Bath stepper + Alpine state + review summary)
- MODIFY `resources/views/corex/properties/show.blade.php` (display full + half)
- CREATE `tests/Feature/CoreX/PropertyWizardHalfBathsTest.php`
- MODIFY `tests/Feature/CoreX/PropertyUploadContactTest.php` (half_baths assertion)
- MODIFY `database/schema/mysql-schema.sql` — regenerate via `php artisan schema:dump`
  from an up-to-date DB before merge (local dev DB is behind Staging; do NOT
  dump from it — the migration runs on top of the snapshot so tests stay correct).
