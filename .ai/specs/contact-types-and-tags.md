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
