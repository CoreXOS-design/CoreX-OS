# Spec: Company-Owned Properties (Contact ↔ Natural-Person Representative)

**Status:** DRAFT — design only, no build. Read-only research + proposal (cc3, 2026-08-13).
**Trigger:** Johan — "many properties are owned by companies/CCs/trusts, not natural
persons; if a prospect asks 'how do you handle companies?' we need a real answer."
Launch-blocker-level gap.
**Interim state (already decided, cc5 building it separately):** the deeds/CMA
scraper simply **blocks companies with a "coming soon" popup** and captures only
natural-person owners for now. This doc is the proper build for later — it does
not change or need to touch that interim gate.

**SUPERSEDED IN PART (2026-08-13):** Johan decided the actual model — see
`.ai/specs/contact-entity-type.md` for the build-ready spec. That doc
replaces §4's 4-way `entity_type` proposal with a minimal 2-way
`type` (natural_person/entity) field, and **withdraws** §2.4/§5.4's proposed
FICA-status requirement — verified in code that FICA already drills every
entity submission down to a real natural person in the same document
(confirmed, not a gap). This doc's codebase-gap research (§2) and pillar
framing (§3) still stand as background; treat `contact-entity-type.md` as
the authoritative build spec.

---

## 1. What this feature does and why

Today a `Contact` in CoreX is shaped for a natural person only — first name,
last name, an SA ID number. Real property ownership on the KZN South Coast
routinely isn't: holiday homes held in family trusts, sectional-title units
owned by a close corporation, commercial stock owned by a (Pty) Ltd. When a
deal touches one of these, someone still has to actually sign — a director, a
trustee, a partner — and CoreX currently has no way to say "this Contact is a
company" *or* "this natural person signs for that company."

This spec adds: (a) an entity-type distinction on Contact (natural vs
company/CC/trust/partnership), (b) a real Contact-to-Contact link from a
company to its natural-person representative(s), and (c) the ingestion/e-sign/
FICA wiring needed so a company-owned property flows through the system with
a clear, compliant answer to "who signs."

---

## 2. Current state — what exists, what's missing

Full read-only sweep of `/corex-qa1` (branch QA1, 2026-08-13). Exact
file:line citations kept so the build phase can jump straight to them.

### 2.1 Contact — no entity-type field
`Contact` has no general "is this a company" flag. The only related thing is
`contacts.id_type` (`'sa_id' | 'company_reg'`), added by the deeds-capture
feature (`database/migrations/2026_08_12_000001_add_deeds_capture_fields.php:36-38`)
purely to label which kind of number sits in the shared `id_number` column.
It drives **no logic** anywhere — it's a cosmetic string swap ("Company reg" vs
"ID") in `resources/views/corex/deeds-capture/index.blade.php:74,91` and is not
read by FICA, property-linking, or e-sign. `ContactType` (`app/Models/ContactType.php`)
is a completely different, orthogonal concept — the Seller/Buyer/Lessor/Lessee
*role* taxonomy, not natural-vs-company.

### 2.2 No director/representative link exists
No pivot, FK, or model links one Contact to another as director, trustee,
partner, or authorised signatory. Grepped the whole app + migrations for
`represented_by`, `director_of`, `signatory`, `authorised_representative`,
`linked_contact`, `parent_contact_id` — zero hits of that shape. The only
"signatory"-adjacent code is e-sign **document** authoriser placement
(`app/Services/Docuperfect/CandidateAuthoriserSurfaceInjector.php` and friends)
— where on a PDF someone signs, not a Contact-ownership relationship.

The closest thing that exists is a free-text, **unlinked** "Principal (Acting
on Behalf)" block inside one FICA submission's JSON `form_data`
(`resources/views/compliance/fica/partials/submitted-data.blade.php:80-89`),
gated to `entity_type === 'natural'` only (a person acting for another
person, e.g. power of attorney) — not company representation, and never
resolved to a real `Contact` row.

### 2.3 "Company docs" already in CoreX ≠ this problem — important disambiguation
AT-279/AT-236 ("company-docs package") and AT-234 ("NCC") are real, live
features, but both are about **the agency's own** corporate paperwork, not a
property seller's:
- AT-279 built **Compliance → Agency Documents** — a per-agency card grid
  (`database/seeders/AgencyDocumentTypeConfigSeeder.php:26-32`: FFC
  Certificate, Bank Confirmation Letter, BEE Certificate, **Company
  Registration (CIPC)**, VAT Registration Certificate) tracked on
  `AgencyComplianceProvision` (`app/Models/Compliance/AgencyComplianceProvision.php`),
  scoped to `agency_id`/`branch_id` — **no relationship to `contacts` or
  `properties` at all.**
- AT-234 added `agencies.ncc_registration_number`
  (`database/migrations/2026_07_26_090000_add_ncc_registration_number_to_agencies_table.php`,
  `app/Models/Agency.php:149`) — the agency's own National Consumer
  Commission number, for letterhead.

Neither stores a *seller's* company registration docs, resolution, or
director list. Do not build on top of these tables for this feature — they
are a different pillar relationship (Agency's own compliance), and reusing
them would incorrectly scope a seller's company documents to the agency
tenant instead of the property/contact.

### 2.4 FICA already models company/trust/partnership — but as a disconnected JSON blob
This is the richest existing raw material, and the shape to reuse:
`fica_submissions.entity_type` enum `['natural', 'company', 'trust', 'partnership']`
(`database/migrations/2026_03_26_100000_create_fica_tables.php:18`,
`app/Models/FicaSubmission.php:27`). For `entity_type === 'company'` the form
captures — inside `form_data` JSON, per
`resources/views/compliance/fica/partials/submitted-data.blade.php:25-44` —
`company_name`, `company_reg_number`, `company_authority_source` (the
"authority to act," i.e. a resolution reference, as free text), and a
`beneficial_owners[]` array of `{name, id_number, address}`. Trust
(lines 46-64) and partnership (lines 66-75) entity types have their own
equivalent `trustees[]` / `partners[]` arrays.

**The gap:** none of `beneficial_owners` / `trustees` / `partners` link to a
real `Contact` row — they're name+ID-number strings inside one JSON blob on
one `FicaSubmission`, tied to a single `contact_id`
(`FicaSubmission belongsTo Contact`, `app/Models/FicaSubmission.php:79-82`).
`Contact::ficaStatus()` (`app/Models/Contact.php:303-333`) only checks "does
*this* Contact have an approved FicaSubmission" — entity-type-agnostic, with
**no cross-check that a company's own signatory has their own approved
FICA.** A company-owned property today can be fully "FICA compliant" per the
system's own gate while the actual human who will sign the mandate has never
been identified or vetted. This is the real compliance hole behind Johan's
concern, not just a UI gap.

### 2.5 Property-contact pivot — flat role vocabulary, no "on behalf of"
`contact_property` pivot (`database/migrations/2026_03_05_200001_create_contact_property_table.php:12-20`):
`contact_id`, `property_id`, `role` (free string). Canonical roles enforced
at the write path — `PropertyContactController::LINK_ROLES` (`app/Http/Controllers/CoreX/PropertyContactController.php:86`)
= `['seller', 'buyer', 'owner', 'landlord', 'tenant', 'lessor']`.
`Property::sellerSidePivotRolesForListingType()` / `buyerSidePivotRolesForListingType()`
(`app/Models/Property.php:666-687`) and `Property::esignRoleForPivotRole()`
(`app/Models/Property.php:711+`) all key off this same flat vocabulary. There
is no "authorised signatory for the seller" role or concept anywhere in this
pivot or the `createAndLink` form (`app/Http/Controllers/CoreX/PropertyContactController.php:150-161`).
A company owner and its human signatory today can only exist as two
*unrelated* `contact_property` rows, with nothing tying them together.

### 2.6 Deeds/CMA scraper — company owners already ingest, just unmodelled
Spec: `.ai/specs/deeds-capture.md` (Status: Built (QA1), 2026-08-12). Payload
per owner carries `name`, `surname`, `first_names`, `id_number`, and `id_type`
(`'sa_id' | 'company_reg'` — same shared field, validated in
`app/Http/Controllers/Api/DeedsCaptureController.php:69-74`). No raw
"(PTY) LTD" / "CC" / "TRUST" name-pattern signal is exposed by the source
today — `id_type` is the only entity signal, and it comes from whatever the
(not-yet-landed) cc5 Chrome extension sends.

Ingestion (`DeedsCaptureController::resolveOwnerContact()`, lines 240-283)
creates/dedupes a Contact per owner (keyed on the raw `id_number`, SA ID or
company reg — same dedupe path either way) and just copies `id_type` through.
Multi-owner support **already exists and is useful groundwork**:
`tracked_property_owners` (`database/migrations/2026_08_12_000005_create_tracked_property_owners_table.php:9-19`)
— `tracked_property_id`, `contact_id`, `name`, `id_number`, `id_type`,
`is_primary` — model `app/Models/Prospecting/TrackedPropertyOwner.php`.
Promotion to a real Property (`app/Http/Controllers/CoreX/DeedsCaptureController.php::promote()`,
lines 179-248) links **every** captured owner to the Property via
`contact_property` with `role='owner'`, regardless of `id_type` — a company
owner flows through identically to a natural person today, with only a
cosmetic label difference. No blocking/gating logic exists in this repo
today (confirmed: cc5's interim "coming soon" popup is external/future work,
consistent with what Johan described).

### 2.7 No prior design documentation
`.ai/specs/contacts.md` and `.ai/specs/compliance.md` — both read in full —
never mention company contacts, juristic persons, CIPC, or company FICA.
`contacts.md`'s own "Pending Spec Items" already lists **"Full contact record
design (all fields, all relationships to pillars)"** as outstanding. This
spec is that missing piece, scoped to the company-ownership slice of it.

---

## 3. Pillar connections

- **Contact** (primary) — entity-type distinction + new Contact→Contact
  representative link.
- **Property** — unchanged pivot mechanics (company Contact still links via
  `contact_property` role=`owner`); what's new is resolving *who signs* for
  that owner.
- **Deal / Document (e-sign)** — signing-role resolution must walk
  company-owner → primary representative instead of signing the company
  Contact directly (a company cannot hold a pen).
- **Agent (User)** — capture UX: the agent working a company-owned listing
  needs to see, at a glance, who the representative is (or a clear prompt to
  add one).
- **FICA/Compliance** — closes the "signatory has no independent FICA"
  hole described in §2.4; also gives the *existing* FICA `entity_type`
  vocabulary somewhere real to attach to.

---

## 4. Proposed data model

### 4.1 `contacts` — add entity type + a real registration-number column
```
entity_type            enum('natural','company','trust','partnership')
                        default 'natural', not null
registration_number    string, nullable   -- company/CC/trust/partnership reg no.
```
Reuses the exact vocabulary FICA already has (`fica_submissions.entity_type`)
so the two stop being independent schemes. `id_number` stays **natural-person
only** (SA ID) going forward — do not keep dual-purposing it. Backfill: any
existing contact with `id_type = 'company_reg'` gets `entity_type = 'company'`,
`registration_number = id_number`, `id_number = null`, `id_type` retired
(kept as a deprecated column or dropped once every reader is migrated — the
deeds-capture blade label swap moves to reading `entity_type` instead).

### 4.2 `contact_representatives` — NEW pivot (Contact → Contact)
```
id
company_contact_id         FK -> contacts, cascadeOnDelete
representative_contact_id  FK -> contacts, cascadeOnDelete
relationship_type          enum('director','trustee','partner','authorised_signatory')
is_primary_signatory       boolean, default false
authority_document_id      FK -> documents, nullable   -- resolution / letter of authority,
                                                          reuses the existing unified
                                                          Document store, no new file storage
timestamps, deleted_at
unique (company_contact_id, representative_contact_id)
```
`representative_contact_id` must reference a Contact with
`entity_type = 'natural'` — enforced at the write path (mirrors how
`PropertyContactController::LINK_ROLES` already validates role vocabulary).
One company can have multiple representatives; `is_primary_signatory` marks
the one e-sign/mandate flows default to (agent can re-point per deal if a
different director signs that particular transaction).

### 4.3 `contact_property` pivot — unchanged
No new role, no schema change. A company Contact still links to a Property
exactly as today (`role = 'owner'`/`'seller'`/`'landlord'`). The new
relationship lives entirely on the Contact side (§4.2), not the property
pivot — keeps the existing e-sign role-mapping (`esignRoleForPivotRole()`)
untouched and correct; it's the *resolution* of who physically signs that
changes (§5.3), not the ownership link itself.

### 4.4 FICA — link, don't duplicate
No schema break needed on `fica_submissions`. Add an optional
`beneficial_owner_contact_id` / equivalent FK-per-array-entry resolution step
at the UI layer (§5.4) so a `beneficial_owners[]`/`trustees[]`/`partners[]`
entry can be matched-or-created into a real Contact and a
`contact_representatives` row, instead of staying a dead JSON string. Old
submissions keep their JSON as-is — additive, not a breaking migration.

---

## 5. Proposed flow / UI

### 5.1 Contact form
New **Entity Type** selector (Natural person / Company / CC / Trust /
Partnership — CC and Company share `entity_type = 'company'` with a
sub-label, since CoreX doesn't need a 5th value for a legacy entity type
that behaves identically to a Pty Ltd for this purpose). When not
`natural`: swap the ID Number field for **Registration Number**, and show a
new **Representatives** panel below it — same Match-or-Create contact-picker
pattern already used across the app (property/DR2 contact pickers, the
evaluation-certificate contact picker built this session) so a director gets
searched-and-linked or quick-added with zero new UI pattern invented. Each
row: name, relationship type, primary-signatory toggle.

### 5.2 Property page
When a linked owner Contact is a company, its representative(s) render
inline (read-only chips, "Add representative" shortcut) directly under the
owner in the Contacts tab — an agent sees at a glance who needs to sign
without leaving the property.

### 5.3 E-sign / mandate signing resolution
`Property::esignRoleForPivotRole()` stays as the role-mapping source of
truth. New resolution step ahead of recipient assignment: if the linked
owner Contact's `entity_type !== 'natural'`, resolve the actual signing
Contact to its `is_primary_signatory` representative (falling back to a
required-selection prompt if none is captured yet — this is the structural,
permanent version of cc5's interim "coming soon" block: instead of refusing
the whole flow, it asks for exactly the one missing thing, a representative).

### 5.4 FICA form
Entity type on the FICA submission auto-derives from the Contact's own
`entity_type` (stop asking the agent to re-select it independently — today's
two schemes silently could disagree). Each `beneficial_owners[]` /
`trustees[]` / `partners[]` entry gets an inline "Link to Contact" action
(Match-or-Create, Non-Negotiable #10) that creates the corresponding
`contact_representatives` row. `Contact::ficaStatus()` for a company Contact
becomes: approved **and** its primary representative also has an approved
FicaSubmission — closing the hole in §2.4. This is a real behavioural change
agencies may want to phase in gradually; expose it as an agency-level toggle
(mirrors the existing Go-Live Migration Mode opt-in pattern in
`.ai/specs/compliance.md` §"Go-Live Migration Mode") — if this toggle ships,
Non-Negotiable #10a requires it also land in the Setup Wizard the same
build prompt.

### 5.5 Deeds/CMA ingestion
`resolveOwnerContact()` stamps `entity_type` + `registration_number`
correctly (from `id_type`) instead of today's cosmetic-only handling.
`tracked_property_owners` needs no schema change — `is_primary` already
supports multi-owner capture. Director data is **not** expected from the
deeds source itself (basic deeds extracts don't carry director names) — the
representative link is a deliberate human-capture step post-promotion, via
the Contact form panel in §5.1, not an automated extraction. This is also
exactly where cc5's interim scraper-side block eventually retires: once this
model exists, a scraped company owner can be captured immediately (no
"coming soon" wall) with the representative prompted for on the CoreX side
instead of gated at the browser-extension layer.

### 5.6 Domain events (Non-Negotiable #9)
Build against `.ai/specs/corex-domain-events-spec.md`, not ad-hoc calls.
`Fica\FicaApproved` (`submission`, `contact`, `approvedByUserId`, `agencyId`
— spec line 323) already exists and is exactly the hook a representative's
FICA approval should fire through — a listener recomputes the company
Contact's `ficaStatus()` gate rather than the company's status being
polled/derived ad hoc on every read.

---

## 6. Permissions

No new permission keys anticipated — representative management is an
extension of existing contact-edit gating (`access_contacts` +
`LogsContactAccess:edit`, matching the pattern `ContactController` already
uses); FICA linkage reuses existing FICA permission gates. Confirm at build
time whether a distinct `contacts.manage_representatives` key earns its
keep, or whether piggybacking on contact-edit is sufficient — default to the
simpler option unless a real need for finer-grained gating surfaces.

---

## 7. Acceptance criteria (for the eventual build, not this doc)

- [ ] `contacts.entity_type` + `registration_number` added; existing
      `id_type='company_reg'` rows backfilled cleanly; dry-run report
      produced and approved before the destructive backfill runs on
      Staging/prod (matches the migration-safety pattern already used
      elsewhere in this codebase, e.g. `contact-types-and-tags.md` §3.6).
- [ ] `contact_representatives` pivot created; write path validates the
      representative Contact is `entity_type='natural'`.
- [ ] Contact form: entity-type selector + Representatives panel (Match-or-
      Create), create + edit.
- [ ] Property Contacts tab shows a linked company owner's representative(s)
      inline.
- [ ] E-sign recipient resolution walks company-owner → primary
      representative; prompts for one if missing instead of blocking.
- [ ] `Contact::ficaStatus()` for a company Contact requires its primary
      representative's own approved FICA (behind an agency-level toggle,
      surfaced in the Setup Wizard if shipped as a toggle per #10a).
- [ ] FICA company/trust/partnership beneficial-owner/trustee/partner rows
      gain a "Link to Contact" action creating real `contact_representatives`
      rows; old submissions unaffected.
- [ ] Deeds-capture `resolveOwnerContact()` stamps `entity_type`/
      `registration_number` instead of the cosmetic `id_type`-only scheme.
- [ ] Domain event `Fica\FicaApproved` drives company FICA-status
      recomputation (no ad-hoc polling).
- [ ] Single most-relevant test file green per phase; demo migrated + parity
      verified per Non-Negotiable #12.

---

## 8. Files to create / modify (build-time reference)

**Create:** migration(s) for `contacts.entity_type`/`registration_number` +
`contact_representatives` table; `app/Models/ContactRepresentative.php`;
representative Match-or-Create controller endpoints (mirrors the DR2/
evaluation-certificate contact-picker pattern already in the codebase);
feature tests under `tests/Feature/Contacts/` and
`tests/Feature/Docuperfect/SigningView/` (e-sign resolution touches the
pipeline-gated signing controllers — a test diff there is required per the
existing e-sign integration-moat gate in `CLAUDE.md`).

**Modify:** `app/Models/Contact.php` (entity_type cast, `representatives()`/
`representedCompanies()` relations, `ficaStatus()` company-aware logic);
`app/Http/Controllers/CoreX/ContactController.php` (entity-type field,
representatives sync); `app/Http/Controllers/Api/DeedsCaptureController.php`
(`resolveOwnerContact()`); `app/Http/Controllers/CoreX/DeedsCaptureController.php`
(`promote()` — no pivot change expected, verify); `app/Models/Property.php`
(signing-resolution helper, additive, `esignRoleForPivotRole()` unchanged);
e-sign recipient-assignment code path (wherever `esignRoleForPivotRole()` is
consumed — locate at build time, not guessed here); `resources/views/corex/contacts/index.blade.php`,
`contacts/show.blade.php` (entity-type UI + representatives panel);
`resources/views/corex/deeds-capture/index.blade.php` (read `entity_type`
instead of `id_type` for the label); FICA views under
`resources/views/compliance/fica/` (Link-to-Contact action);
`config/agency-onboarding-copy.php` (if the FICA-gate toggle ships, per
Non-Negotiable #10a).

---

## 9. Explicitly out of scope for this doc

- Rebuilding or touching cc5's interim deeds-scraper "coming soon" block —
  it stays exactly as-is until this model lands.
- The agency's own CIPC/company-doc compliance package (AT-279/AT-234) —
  confirmed unrelated (§2.3), not touched.
- Any code, migration, or deploy — this is a design document only, per
  Johan's explicit instruction.
