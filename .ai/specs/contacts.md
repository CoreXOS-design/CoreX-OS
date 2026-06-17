# Spec: Contacts

**Status:** Live (basic) — spec to be written during consolidation sprint

---

## What Exists

- Contact creation and management
- Basic contact types
- Core Matches feature (Andre — `contact_matches` table)
- Excel import (`ContactImportController`) and Excel export (`ContactExportController`)

---

## Import / Export (Excel)

**Pillars:** Contact (read/write), Agent/User (read — ownership resolution).

The import and export share one column layout so a file exported from CoreX
re-imports cleanly. **Engine: `openspout/openspout`** — a streaming reader/writer
that holds constant memory regardless of row count. (PhpSpreadsheet loads the
whole workbook into memory as cell objects and exhausts the 128 MB
`memory_limit` at a few thousand rows — it caused a hard 500 on staging,
`Allowed memory size exhausted … Cell.php`. Streaming is the root-cause fix, not
a higher memory_limit.) Legacy binary `.xls` (which openspout can't read) still
falls back to PhpSpreadsheet on import — those files are capped at 65k rows.

**Deploy note:** openspout is a new Composer dependency — every environment must
run `composer install` after pulling, or both import and export 500 with
"Class OpenSpout\… not found".

Round-trip twin controllers:

- Export: `app/Http/Controllers/CoreX/ContactExportController.php` →
  `GET /corex/contacts/export` (`corex.contacts.export`), gated
  `permission:contacts.export`. Streams `.xlsx`.
- Import: `app/Http/Controllers/CoreX/ContactImportController.php` →
  `POST /corex/contacts/import` (`corex.contacts.import`).

### Column layout (exact order)

`Category | Name | Surname | Email | Cell | Phone | Type | *ID Number |
BirthDay | Tags | Source | Address | Wish Lists | Matches | SMS | Emails |
WhatsApp | Opt-In | Agents | Loaded | Modified | Last Contacted |
Additional Info`

| Column | Stored field | Notes |
|--------|--------------|-------|
| Name / Surname | `first_name` / `last_name` | |
| Email | `email` | |
| Cell | `phone` | exported as text (no numeric coercion) |
| Type | `contact_type_id` → `type.name` | auto-created on import |
| *ID Number | `id_number` | exported as text |
| BirthDay | `birthday` | |
| Tags | `contact_tag` pivot | comma-joined; auto-created on import |
| Source | `contact_source_id` → `source.name` | auto-created on import |
| Address | `address` | |
| Matches | `matches` count | export-only (real count) |
| Emails / WhatsApp | `email_count` / `whatsapp_count` | |
| **Agents** | `created_by_user_id` → `createdBy.name` | **drives ownership** |
| Loaded / Modified / Last Contacted | `loaded_at` / `modified_at` / `last_contacted_at` | fall back to `created_at`/`updated_at` on export |
| Additional Info | `notes` | |
| Category, Phone, Wish Lists, SMS, Opt-In | — | **no native field — exported blank** |

### Ownership via the "Agents" column

On import, `ContactImportController::resolveAgent()` reads the `Agents` column
and resolves it to a `User` (email match → fuzzy name match → unique
first-name), then sets `created_by_user_id`. The export writes each contact's
owning agent's **full name** into `Agents`, so a CoreX export re-imported into
CoreX re-assigns every contact to the same agent.

### Scope & permissions

- Export ALWAYS enforces the caller's `contacts` data-scope: `own` agents only
  ever export their own contacts even via "Export all".
- Two UI actions on the Contacts toolbar (gated `@permission('contacts.export')`):
  "Export current view" (carries the active `search`/`type`/`agent_id` filters)
  and "Export all contacts" (`?all=1`).
- `ContactExportController::buildQuery()` mirrors `ContactController::index()` —
  keep the two in sync.

### Acceptance criteria

- [x] Export streams `.xlsx` with the exact header row above.
- [x] `Agents` column = owning agent name; round-trips through import.
- [x] Data-scope enforced (agent sees only own; `agent_id`/`search` honoured).
- [x] Feature test: `tests/Feature/Contacts/ContactExportTest.php`.

---

## Consolidation Items (Phase 1)

- [ ] All contact types from settings table (not hardcoded)
- [ ] Property ↔ Contact owner link — bidirectional (contact shows owned/rented properties; property shows owner/tenant contact)
- [ ] POPIA consent block on contact record
- [ ] FICA status flag visible on contact record
- [ ] Navigation: all contact actions reachable

---

## Agent Assignment on a Contact (2026-06-17)

**Pillar:** Contact ↔ Agent (`User`).

`created_by_user_id` stays the immutable capture audit. Operational ownership now
lives in two new nullable columns mirroring `Property.agent_id` /
`pp_second_agent_id`:

- `contacts.agent_id` — primary agent (reassignable). Defaulted to the creator on
  capture for **every** ingress path via `ContactObserver::creating()` (quick-add,
  property inline create, imports), so every contact always has a primary.
- `contacts.second_agent_id` — optional co-agent. `different:agent_id`; collapses
  to null if the primary is cleared.

Both `nullOnDelete` (non-negotiable #1 — deactivating a user never deletes a
contact). Migration: `2026_06_17_120000_add_agent_assignment_to_contacts_table.php`.
Relationships: `Contact::agent()`, `Contact::secondAgent()`.

**UI:** Contact show → **Info** tab, "Assigned Agents" section (two agency-scoped
selects) above Save. The header "Agent:" line now shows the primary agent (falls
back to creator) and a "Co-Agent:" line when set. Validation constrains both
selects to active members of the contact's agency (tamper-proof). Gated by the
existing `access_contacts` + contact-edit middleware.

**Duplicate-detection box fix (all roles):** two bugs.
1. *No box rendered.* The quick-add form's Alpine `checkDuplicate()` set `dupFound`
   (which greyed the Save button) but **nothing was bound to `dupData`** — so a
   duplicate silently disabled Save with no explanation. Added a live amber
   warning box (`resources/views/corex/contacts/index.blade.php`) showing the
   existing contact's name, **the agent it sits under**, phone, email, type, last
   contacted, and an "Open existing contact" link.
2. *Scope.* `checkDuplicate()` now drops only the role-based `ContactScope`
   (keeping `AgencyScope`), so an agent with `own` data scope sees agency-wide
   duplicates — matching `ContactDuplicateService::findDuplicates`. The endpoint
   returns the **primary agent** (`agent` → fallback `createdBy`).

**Back-catalogue backfill:** the migration seeds `agent_id = created_by_user_id`
for every existing contact that has a capturer, so the whole back-catalogue is
assigned, not just contacts created from here on. Creator-less imports stay
unassigned.

**Tab rename:** Contact show "Properties" tab → **"Properties & Core Matches"**,
with a "Core Matches" section header above "Add New Match Criteria".

**Property-upload ID capture:** the property "create new contact & link" inline
form on a *new* property (the `$isNew` Alpine `newForm` flow) now also captures an
optional SA **ID number** next to Contact Type — previously only the
existing-property `createAndLink` form did. `PropertyController` persists it on the
`pending_new_contacts` loop (normalised, SA-ID-validated, with POPIA audit fields
`id_number_captured_at` / `id_number_source='property_inline_create'`); one
malformed entry is dropped, never blocking the property save.

Tests: `tests/Feature/Contacts/ContactAgentAssignmentTest.php` (8 green),
`tests/Feature/CoreX/PropertyUploadContactTest.php` (2 green — ID persisted +
primary agent defaulted; malformed ID dropped but contact still saved).

---

## Pending Spec Items

- Full contact record design (all fields, all relationships to pillars)
- Tenant pre-approval workflow (Phase 2)
- Contact-to-Flow integration (contact selection within flows)

---

*Full spec to be completed during Phase 1 consolidation sprint.*
