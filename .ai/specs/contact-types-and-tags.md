# Spec — Contact Types as 4 E-Sign Parents + Nested Custom Sub-Tags

> Branch: `AT-79-Contact-pop-up-box-with-contact-type-with-custom-tags`
> Status: DRAFT — awaiting approval
> Author: Andre (drafted with Claude)
> Last updated: 2026-06-22

---

## 1. What this feature does and why

Today **Contact Types** are a flat, free list (Seller, Buyer, Lessor, Lessee, plus
ad-hoc extras like Witness/Tenant/Agent), each optionally carrying an `esign_role`.
**Contact Tags** are a *separate*, independent, free-form labelling system.
A contact has exactly **one** `contact_type_id` and many tags.

This is wrong for the workflow. A contact is frequently more than one thing
(a person can be a Seller on one deal and a Buyer on another), and the "extra"
types muddy the clean 1:1 mapping the e-sign engine depends on.

**New model:**

- Contact Types collapse to **exactly 4 fixed, global parents** — Seller, Buyer,
  Lessor, Lessee — each permanently bound to its `esign_role`. These are
  system-locked: no adding a 5th, no renaming, no deleting.
- The existing **Contact Tags become "sub-tags"**, each belonging to exactly one
  of the 4 parents (agency-scoped — every agency defines its own sub-tags).
- A contact can carry **multiple (parent + optional sub-tag) assignments**
  — e.g. *Seller → "Cash seller"* **and** *Buyer → "First-time buyer"*.
- On the contact form, the plain Contact-Type dropdown is replaced by a
  **pop-up picker**: choose a parent (the 4 e-sign roles), then pick or create a
  sub-tag under it; add as many parent+sub-tag rows as needed.
- In **Settings → Contacts**, only the 4 parents show, each expanding to a
  managed list of its sub-tags. (The old standalone "Contact Tags" accordion is
  retired — tags now live nested under their parent type.)

**Hard constraint: the e-sign flow must not break.** See §6.

---

## 2. Pillar connections

- **Contact** (primary) — the typed/tagged entity.
- **Deal / Document (e-sign)** — consumes the parent type's `esign_role` to assign
  signing roles. The 4 fixed parents guarantee the 1:1 role mapping the wizard
  relies on.
- **Property** — e-sign auto-population reads a property's linked contacts and
  resolves each one's signing role from its parent type(s).

---

## 3. Data model & migrations

### 3.1 `contact_types` — reduce to 4 fixed parents
- No schema change to columns. A data migration **normalises to exactly 4 rows**:
  Seller (`esign_role=seller`), Buyer (`buyer`), Lessor (`lessor`), Lessee (`lessee`),
  names fixed, `is_active=true`, sort 1–4.
- These are **global** (no `agency_id`) — shared by every agency.
- Locked in code: store/update/destroy for parents are disabled (see §5).

### 3.2 `contact_tags` — become sub-tags of a parent
- **Add column** `contact_type_id` (FK → `contact_types.id`, **not null** after
  backfill, indexed). Each tag belongs to exactly one of the 4 parents.
- Keeps existing `agency_id` (per-agency), `name`, `color`, `sort_order`,
  `is_active`, `deleted_at`.
- Unique-ish guard: `(agency_id, contact_type_id, name)` should not duplicate
  (validation-level; soft-deletes mean no hard DB unique).

### 3.3 `contact_contact_type` — NEW pivot (multi-parent assignment)
- Columns: `id`, `contact_id` (FK), `contact_type_id` (FK), `timestamps`.
- Unique `(contact_id, contact_type_id)`.
- Records which parent(s) a contact belongs to. A parent can be assigned with
  **no** sub-tag (just "Seller"), so this pivot is the source of truth for
  parent membership — independent of whether a sub-tag was chosen.

### 3.4 `contact_tag` pivot — unchanged
- Existing `(contact_id, contact_tag_id)` pivot still records sub-tag
  assignments. Because every tag now has a parent, an assigned sub-tag also
  *implies* its parent — the controller keeps `contact_contact_type` in sync so
  parent membership is never implicit-only.

### 3.5 `contacts.contact_type_id` — kept as maintained PRIMARY mirror
- **Not dropped.** 36 app files + the e-sign reverse-mapping read it. It becomes
  a denormalised mirror of the contact's **primary parent** (lowest-sort parent
  in `contact_contact_type`), re-derived on every write. This keeps all existing
  readers and e-sign correct with zero edits to them.

### 3.6 Migration data step (REQUIRES SIGN-OFF per env)
Decision locked: *auto-map each existing non-parent type to its closest parent,
preserve the old name as a sub-tag.* The mapping is data-driven and printed for
sign-off before it runs (extras differ per env; local has only "Witness").
Proposed default map:

| Existing type | Action |
|---|---|
| Seller / Buyer / Lessor / Lessee | keep as parent (canonicalise name + esign_role) |
| Tenant | → sub-tag "Tenant" under **Lessee** |
| Landlord | → sub-tag "Landlord" under **Lessor** |
| Witness / Agent / other (no clean parent) | **FLAGGED** — needs human decision; default park as sub-tag under Seller OR drop if 0 contacts |
| any extra **with** an `esign_role` | sub-tag under the parent matching that role |

Contacts pointing at a retired type are re-pointed: the contact gets the mapped
parent in `contact_contact_type` (+ the preserved sub-tag in `contact_tag`), and
`contacts.contact_type_id` is set to that parent. No contact loses its meaning.
**A dry-run report is generated and approved before the destructive step runs on
Staging/prod.**

---

## 4. UI

### 4.1 Contact form — pop-up type/tag picker (replaces the dropdown)
- Locations: create form ([contacts/index.blade.php](../../resources/views/corex/contacts/index.blade.php)) and
  edit form ([contacts/show.blade.php](../../resources/views/corex/contacts/show.blade.php)).
- A button/field labelled **Contact Type** shows current assignments as chips
  (e.g. `Seller · Cash seller ✕`). Clicking opens an Alpine modal:
  1. **Parent dropdown** — Seller / Buyer / Lessor / Lessee.
  2. **Sub-tag** under the chosen parent — searchable dropdown of that parent's
     existing sub-tags **+ "Create new"** (inline create → POSTs a new tag under
     that parent, agency-scoped).
  3. **Add** appends the (parent, sub-tag?) row. Repeat for more parents.
- Submits as structured arrays (e.g. `assignments[][type_id]`,
  `assignments[][tag_id]`). The old standalone Tags checkbox block on the edit
  form is absorbed into this picker.

### 4.2 Settings → Contacts
- The **"Contact Types"** accordion lists the 4 parents (read-only names + role
  badge), each expanding to its **sub-tags** with inline add/edit/delete
  (agency-scoped). No "add parent type" form.
- The separate **"Contact Tags"** accordion is removed (merged into the above).
- "Contact Sources" accordion unchanged.

### 4.3 Navigation
- No new page → no new nav entry needed. Settings entry already exists
  (`?tab=feature&fsec=contacts`). (Non-negotiable #2 satisfied.)

---

## 5. Permissions
- Parent types are system-locked: `ContactTypeController@store/update/destroy`
  reject all writes (the 4 are seeded/migrated only). Keep routes but gate them
  to a no-op / 403 to honour "locked to exactly 4".
- Sub-tag CRUD reuses existing contact-tag permission gating + `BelongsToAgency`
  scope. Contact assignment uses existing contact edit permissions.

---

## 6. E-Sign preservation (THE non-break contract)

The wizard ([ESignWizardController.php](../../app/Http/Controllers/Docuperfect/ESignWizardController.php)) couples to types two ways:

1. **Forward (auto-populate property contacts → recipients), lines ~505-538:**
   reads `$contact->contact_type_id` → type `name` (lowercased) as the role +
   `esign_role` to filter against the template's allowed roles.
   - Preserved because the 4 parents are named exactly Seller/Buyer/Lessor/Lessee
     and carry the matching `esign_role`, and `contact_type_id` still resolves to
     a real parent.
   - **Enhancement:** update this loop to iterate the contact's *parent types*
     (`contact_contact_type`) and pick the one whose `esign_role` matches the
     template — so a Seller-and-Buyer contact resolves to the correct role per
     document instead of just the primary mirror. Contained to this method.

2. **Reverse (create contact from a signing role), line ~803:**
   `ContactType::where('esign_role', $role)->value('id')` — assumes one type per
   role. Preserved/strengthened: exactly 4 parents = guaranteed unique per role.

3. **Filters & lookups (lines ~1124-1166, ~1028-1032):** key off `esign_role`
   via `contact_type_id`. Still correct against the primary mirror; broadened to
   also match `contact_contact_type` so multi-role contacts surface correctly.

No change to pipeline-gated files. Tests added for the wizard role-resolution
change.

---

## 7. User flow

1. Agent opens New Contact → clicks **Contact Type** → modal.
2. Picks **Seller**, picks sub-tag **Cash seller** (or creates it) → Add.
3. Picks **Buyer**, leaves sub-tag empty → Add. Saves.
4. Contact now: parents {Seller, Buyer}; sub-tags {Cash seller}; primary mirror
   = Seller. Tags chips render on the contact.
5. E-sign a sale doc on a property this contact is linked to → wizard resolves
   them to **Seller** (template's required role), unchanged behaviour.

---

## 8. Acceptance criteria

- [ ] `contact_types` holds exactly 4 global parents, each with its `esign_role`; parent CRUD is locked.
- [ ] Every `contact_tag` has a non-null `contact_type_id` parent; sub-tag CRUD is agency-scoped and nested under its parent in Settings.
- [ ] A contact can be saved with ≥2 parent+sub-tag assignments; `contact_contact_type` + `contact_tag` + the `contact_type_id` primary mirror all stay consistent on every write.
- [ ] Contact form shows the pop-up picker (create + edit); chips reflect assignments; inline sub-tag create works.
- [ ] Settings → Contacts shows only the 4 parents, each expanding to its sub-tags; no "add parent" UI; standalone Tags accordion gone.
- [ ] Migration dry-run report produced + approved; after run, no contact lost its type meaning; "Witness"/extras resolved per sign-off.
- [ ] E-sign: forward auto-populate, reverse create, and filters all still pass — multi-role contact resolves to the template-correct role. Single most-relevant test file green.
- [ ] `php -l`, view/route/cache clear, targeted test all clean. Demo migrated + parity verified.

---

## 9. Phased build (one concern per prompt)

1. **Data layer** — migrations (tag `contact_type_id`, `contact_contact_type` pivot), models/relations, primary-mirror maintenance helper + data migration with dry-run report. *(needs migration sign-off)*
2. **Settings UI** — 4 locked parents + nested sub-tag CRUD; lock parent controller writes; remove standalone Tags accordion.
3. **Contact form pop-up picker** — modal, multi-assignment, inline sub-tag create; wire store/update to keep all three stores in sync; absorb old tags block.
4. **E-sign role resolution** — iterate parent types in the wizard; broaden filters; add the wizard test.
5. **Close-out** — dev-check targeted, demo deploy + parity, CHAT_STARTER update.

---

## 10. Files to create / modify

**Create:** migrations (2), `database/seeders` parent normaliser (or in-migration),
spec dry-run command (optional), wizard test.

**Modify:** `app/Models/ContactType.php`, `ContactTag.php`, `Contact.php`;
`app/Http/Controllers/CoreX/ContactTypeController.php`, `ContactTagController.php`,
`ContactController.php` (store/update sync); `ESignWizardController.php`
(role resolution); `resources/views/corex/settings.blade.php`,
`resources/views/corex/contacts/index.blade.php`, `contacts/show.blade.php`;
`SettingsController.php` (pass nested data).

---

## 11. "Tenant" joins the picker, and a save can no longer silently strip a type it didn't offer (AT-392, 2026-09-11, cc6) — BUILT

### The bug

The rental-approval feature (`AddTenantTypeOnRentalApproval`, `.ai/specs/rental-applications.md`) adds the pre-existing "Tenant" `ContactType` row (id 11 on QA1, `esign_role='lessee'`, distinct from the canonical "Lessee" row id 10 — predates this ruling, ~78 contacts already carried it) to a contact on approval, per Johan's standing rule: **contact type is added, never removed or replaced.** His own words: "the scenario exists where a seller or any contact type can become a tenant... so that contact will be dealt with as a seller on their property but also as a tenant inside rentals."

That rule was silently false in practice. `ContactType::scopeParents()` (§1's "4 fixed parents" + Owner/Other = 6) never included Tenant, so:
- The contact-type picker (`_type_picker.blade.php`) never offered it as an option — it seeds and submits only from `ContactType::parentIds()`.
- `ContactController::applyTypeAssignments()` validates `parent_type_ids.*` against that same 6-item allow-list and then calls `Contact::syncTypeAssignments()`, which does a **full-replace** `sync()`.

Net effect: editing *anything* unrelated on a Tenant contact through the normal Contacts edit form (a phone number, say) silently dropped Tenant, because the picker's submitted set never included it in the first place. This wasn't scoped to Tenant specifically — it was a structural gap: **any type the picker doesn't know about vanishes on the next save through it**, regardless of how the contact came to hold it.

A second, related confusion — cc4's end-to-end walk on `/corex/rentals/contacts` — same root cause, different symptom: the list row showed only the primary-type mirror (`$contact->type`), plus, in the rental lens only, a *separately*-sourced `rentalRoleLabels()` badge for Tenant/Landlord. That produced two bugs on the same row: (a) a contact whose *primary* type was Tenant showed "Tenant" twice (once from each source — same fact printed twice), and (b) a Seller+Tenant contact showed only "Tenant" — Seller never appeared, so an agent scanning the list couldn't tell a tenant was also a seller without opening the record.

### The fix — one thing, not three patches

**1. Tenant becomes selectable**, without touching the CANONICAL invariant. `ContactType::ADDITIONAL_PARENTS = ['Tenant']` — a new constant, matched by **name**, OR'd into `scopeParents()` alongside the existing esign_role-keyed CANONICAL branch and the null-esign_role EXTRA_PARENTS branch. `CANONICAL` itself (the strict one-name-per-esign_role dict the e-sign wizard's 1:1 role resolution depends on — confirmed by reading every `esign_role`-keyed lookup in the codebase, none of which assume uniqueness beyond CANONICAL's own 4) is untouched; Tenant sharing `esign_role='lessee'` with Lessee was already true before this fix and remains true — this fix only makes it *visible and selectable*, not a new ambiguity. `ContactType::parentIds()` (and therefore the picker's allow-list, and `ContactTagController`'s validation) picks this up automatically since both read through `scopeParents()`.

**2. The class-level hardening** — `ContactController::applyTypeAssignments()` now computes, before syncing: any parent type the contact **currently holds that is NOT in `ContactType::parentIds()`** (the picker's full offered set) is unioned into the submitted set. This is deliberately type-agnostic — it doesn't check for "Tenant" by name, it protects *whatever* the contact holds that the picker never offered a chance to keep. A type that WAS offered can still be deliberately unchecked and removed (the hardening only protects the unoffered set, not a one-way ratchet). Per Johan's rule and the BUILD_STANDARD "fix the class, not the instance" charge: if a future type is added to a contact by some other mechanism and the picker hasn't caught up yet, it survives here too, automatically.

**3. Badges show the full set, not just the primary mirror.** `_header-badges.blade.php` (contact detail page) now loops `$contact->parentTypes` instead of the single `$contact->type` mirror (falls back to the mirror only for the rare writer-created contact with no pivot rows). `index.blade.php` — shared by both `/corex/contacts` and `/corex/rentals/contacts` (same controller action, same template) — replaces the old two-source badge rendering (`$contact->type` + a separate `rentalRoleLabels()` loop) with one computed, deduped `$typeBadges` list per row: every held `parentTypes` name, plus — rental lens only — "Landlord" when the contact is linked to a property with a landlord/lessor role but holds no formal Lessor type (the property-pivot-only signal AT-403 added, skipping it would silently drop most real landlords — 13 vs 66 real matches, measured live). No duplication: a contact who already holds the Lessor (or Tenant) type isn't shown "Landlord"/"Tenant" a second time from the inferred signal.

### Verified live, in a browser, on QA1

Contact 18752 ("QA ProofBadgeFix1214", throwaway, soft-deleted after): created as Seller only, then a real `RentalApplicationApproved` event fired against a real rental application (128, also soft-deleted after) — the exact rentals-side mechanism §"Tenant added on approval" in `rental-applications.md` describes — added Tenant. Seller survived (proving the "reverse" direction: an edit from the rentals side never touches an existing type).
- `/corex/rentals/contacts?search=ProofBadgeFix` — row reads `Seller` `Tenant`, each exactly once. No duplicate, no missing Seller.
- Contact detail header — both badges shown.
- The edit form's own type-picker chips — already seeded with `Seller ×` `Tenant ×` (the picker's existing seed-from-pivot logic picked Tenant up automatically once it joined `scopeParents()`, no picker-template change needed) — and the "Add contact type" dropdown's role list now reads `Owner, Other, Tenant, Seller, Buyer, Lessor, Lessee`.
- Editing only the phone number and clicking Save (Tenant never touched, never in the change) — both `Seller` and `Tenant` chips still present on reload.
- `canonical()` re-verified unchanged: still exactly Seller/Buyer/Lessor/Lessee, 4 rows, Tenant not among them — the e-sign wizard's own resolution path is untouched.
- Application 76 untouched throughout (`updated_at` unchanged from Johan's own last touch).

### Files changed

- `app/Models/ContactType.php` — `ADDITIONAL_PARENTS` constant, `scopeParents()` extended
- `app/Http/Controllers/CoreX/ContactController.php` — `applyTypeAssignments()` hardening (preserve unoffered-but-held types)
- `resources/views/corex/contacts/_header-badges.blade.php` — loops `parentTypes`, not the primary mirror
- `resources/views/corex/contacts/index.blade.php` — single deduped `$typeBadges` computation, replacing the two-source badge rendering (shared by `/corex/contacts` and `/corex/rentals/contacts`)
- `tests/Feature/Contacts/ContactTypeAssignmentTest.php` — extended: `parents()` now includes Tenant (was `test_parents_includes_owner_and_other_without_esign_role`, renamed `test_parents_includes_owner_other_and_tenant`), a picker-save-shape test proving Tenant survives an unrelated field save, a test proving a deliberately-offered type can still be unchecked, a test proving the contact page shows every held type

### Test-infra gap found while verifying (not fixed — out of scope, flagged for whoever owns `schema:dump`)

Could not get a green `php artisan test` run for the file above, including on the test's own **pre-existing, untouched** `test_exactly_four_canonical_parents_exist_and_are_locked` — confirmed the environment, not the change: the committed schema snapshot (`database/schema/mysql-schema.sql`) bakes in `2026_03_27_100000_add_esign_role_to_contact_types` and `2026_07_03_000001_seed_owner_other_contact_parents` as already-applied migrations, but `schema:dump` captures structure only — no general table data — so a fresh `RefreshDatabase` test database never gets the 6 base `contact_types` rows those migrations insert. Every test in this file that resolves a canonical type by `esign_role` fails with `ModelNotFoundException` in any worktree created after the last `schema:dump`, independent of any change in this pass. Verified instead via Tinker + a live browser walk against real QA1 data (above). Whoever next runs `php artisan schema:dump` for an unrelated reason should confirm the base `contact_types` seed rows survive it, or add them back via a dedicated always-safe-to-rerun migration the way `2026_09_11_000001_seed_tenant_contact_type_if_missing.php` already does for Tenant.
