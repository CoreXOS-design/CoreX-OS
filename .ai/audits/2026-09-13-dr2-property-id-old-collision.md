# DR2: a failed add-property validation corrupts the deal's own primary-property display

**Date:** 2026-09-13. **Found by:** the conductor, live on QA1, by fat-fingering a
required field while walking an unrelated fixture (the `contact_property` /
`DealPropertyOwnerGate` verification — see that session's findings). **Status:**
confirmed, real, reproducible. **Not fixed** — QA1 is frozen for the night; this goes
on tomorrow's board.

## The defect

`resources/views/dr2/create.blade.php` has two unrelated `<form>`s on the same page
that both post a field named `property_id`:

- **Line 312** — the multi-property "Add to deal" form (`id="dr2mp_add_form_real"`,
  posts to `deals-dr2.properties.add` / `DealRegisterController::addProperty()`):
  `<input type="hidden" name="property_id" id="dr2mp_add_property_id" form="dr2mp_add_form_real">`
  — the **candidate** property being added.

- **Lines 181–182** — the deal's own top-level primary-property display, which
  reads back whatever was POSTed last:
  ```blade
  <input type="hidden" name="property_address" id="dr2_property_address" value="{{ old('property_address', $deal->property_address) }}">
  <div id="dr2_property_linked" ...>✓ Linked to property <span id="dr2_property_linked_id">#{{ old('property_id', $deal->property_id) }}</span> ...</div>
  ```

`old()` is global to the redirect, not scoped to a form. When the add-property form's
`$request->validate([...])` (in `DealRegisterController::addProperty()`) throws on a
missing required field, Laravel's default exception handling redirects back with
`withInput()` — carrying `old('property_id')` = whatever the *add* form posted (the
candidate property), even though the field name collides with the *deal's own,
completely unrelated* primary-property field.

The result: after a failed add attempt, the deal-level display shows
**`old('property_address')` (untouched, still the deal's real primary property's
address) next to `old('property_id')` (the REJECTED candidate's id)** — two different
properties' identifiers shown together as if they were one.

## Confirmed reproduction (2026-09-13, live on QA1, deal 178)

1. Deal 178's primary property is 21034, "12 Fixture Lane, Uvongo".
2. Attempted to add property 21035 ("14 Fixture Lane") via the multi-property form,
   omitting `allocated_commission` (required for a non-first property).
3. Validation failed: *"The allocated commission field is required."*
4. Page re-rendered. The deal's own primary-property block showed:
   - Address: **"12 Fixture Lane, Uvongo"** (correct — property 21034, unchanged)
   - "✓ Linked to property **#21035**" (WRONG — that's the rejected candidate, not
     the deal's actual primary property)

## Why this is more than cosmetic

A deal screen is where an agent confirms which property they're transacting.
Showing one property's address next to a different property's id — specifically
right after a failed click, when the agent is most likely to be re-reading the screen
to see what went wrong — is exactly the kind of screen state that ends up quoted into
a contract, an email, or a distribution against the wrong property.

## Scope of the fix (not attempted tonight — named so whoever picks this up doesn't
have to re-derive it)

The two forms need non-colliding field names, or the deal-level display needs to stop
trusting a bare `old('property_id')`/`old('property_address')` that could have come
from either form on the page. Simplest correct fix is probably renaming the
add-property form's hidden input (`property_id` → e.g. `add_property_id`) and updating
`DealRegisterController::addProperty()`'s `$request->validate([...])` key to match —
narrow, single-file-plus-one-field change, but not attempted here since QA1 is frozen
and this needs its own walk to confirm nothing else reads that field name.
