# Spec: Contact Enhancement — Entity Type (Build Spec)

**Status:** FOUNDATION BUILT on QA1 (commits `0f1a79e49`, incident-fixed in
`e57f8bebb`), other threads (§6) not yet wired.

**COLUMN NAME CORRECTION (2026-08-13, read before building anything else):**
every `type`/`contacts.type` reference below is **historical** — the actual
shipped column is **`contacts.contact_kind`**, not `type`. The original
build used `type`, which shadowed the pre-existing
`Contact::type()` relationship (`belongsTo(ContactType::class,
'contact_type_id')` — the Seller/Buyer/Lessor/Lessee taxonomy) and broke
~20 call sites app-wide (blades, e-sign role resolution, exports, mobile
API, calendar) with a live 500 on the property page. Fixed via an additive
rename migration (`2026_08_21_000100_rename_type_to_contact_kind_on_
contacts_table.php`). Read `contact_kind` everywhere you see `type` below —
left as originally written rather than rewritten throughout, so this note
also serves as the incident record.
**Supersedes the model proposed in** `.ai/specs/company-owned-properties.md`
§4 — that doc's broader FICA-gap analysis stands, but its 4-way
`entity_type` on `contacts` and its "representative needs their own FICA"
requirement are **replaced** by the minimal model below. See §3 for why the
FICA half of that concern turned out not to be a gap at all.

**This is the "do first" foundation.** Every other thread — scraper capture,
e-sign document rendering, evaluations, DR2, FICA — reads from this one
Contact-level decision. Build this before anything downstream.

---

## 1. What this feature does and why

A `Contact` today is shaped for a natural person only (`first_name`,
`last_name`, SA `id_number`). Many property owners on the KZN South Coast are
companies, CCs, or trusts — a CoreX Contact needs to represent that cleanly,
with the actual human who signs (director/trustee/partner) reachable from it.
Johan's decided scope is deliberately minimal: a `type` radio
(natural person / entity) plus two fields shown only when `type = entity`
(entity name, entity registration number). One field pair covers Pty/CC/
Trust/etc. — CoreX does not need a finer legal taxonomy at the Contact level
(FICA already carries that detail where it actually matters — see §3).

---

## 2. Pillar connections

- **Contact** (primary) — the `type`/`entity_name`/`entity_reg_no` fields and
  the entity→representative link.
- **Property** — unchanged; an entity Contact links via the existing
  `contact_property` pivot exactly as a natural person does (`role=owner`
  etc.). No new pivot role.
- **Deal / Document (e-sign)** — the seller-line merge must render an entity
  correctly instead of a broken " (ID: )" string.
- **Compliance/FICA** — already entity-aware end-to-end (§3); this feature
  only needs to *thread into* it, not rebuild it.
- **Agent (User)** — scraper/DR2/evaluation-certificate capture flows all
  read/write through this same Contact shape.

---

## 3. FICA already handles entities — CONFIRMED, not a gap, do not rebuild

Verified directly in code (this session, 2026-08-13):

- `fica_submissions.entity_type` enum `natural|company|trust|partnership`
  (`database/migrations/2026_03_26_100000_create_fica_tables.php:18`) already
  drives distinct form sections per entity type.
- The **online FICA form** (`FicaPublicController::submit()`,
  `app/Http/Controllers/Compliance/FicaPublicController.php:42-165`) requires
  Section 2 ("Person completing form" — `personal.full_name`,
  `personal.id_number`, `personal.residential_address`, `personal.phone`,
  `personal.email`) **unconditionally, regardless of `entity_type`** (lines
  46-53, outside every `if ($entityType === …)` branch). A company/trust/
  partnership submission additionally requires its own entity block —
  `entity.company_name`/`company_reg_number`/`beneficial_owners[]` for
  company (lines 78-97), equivalent `trustees[]`/`donor_*` for trust (100-125),
  `partners[]` for partnership (128-146) — **in the same submission.**
- This means every entity FICA submission already structurally drills down
  to a real natural person's identity (whoever is completing/signing the
  form on the entity's behalf) in the same document that also captures the
  entity's own registration and beneficial-ownership detail. There is no
  need for a signatory to complete a *second, separate* FICA submission —
  the earlier draft's proposed "`Contact::ficaStatus()` requires the
  representative's own approved FicaSubmission" requirement is **withdrawn**;
  it solved a problem that doesn't exist.
- Wet-ink intake (`FicaWetInkService::create()`,
  `app/Services/Compliance/FicaWetInkService.php:40-78`) carries the same
  `entity_type` and stores the same `'personal'` block shape — consistent
  across both intake paths.

**The only real thread for this build:** `fica_submissions.entity_type` is
its own, more detailed classification (4-way, used to pick the right legal
form section) and stays exactly as-is. `contacts.type` (this spec, 2-way:
natural/entity) is a coarser, different-purpose field — for linking,
ownership, and dedup — not a replacement or a duplicate. The only new wiring
is cosmetic: the FICA intake form MAY pre-select its `entity_type` selector
based on `contact.type === 'entity'` (a UX nicety, optional, does not change
FICA's own validation/storage in any way).

---

## 4. Data model

### 4.1 `contacts` — new columns
```
type            enum('natural_person','entity') default 'natural_person', not null
entity_name     string, nullable   -- shown/required only when type='entity'
entity_reg_no   string, nullable   -- company/CC/trust registration number
```

**First-name/last-name wrinkle (verified on QA1 schema):**
`contacts.first_name` and `contacts.last_name` are `NOT NULL` (`SHOW COLUMNS`
confirmed 2026-08-13). An entity Contact has no natural first/last name.
Two options, decision needed at build time:
- **(recommended) Keep the NOT NULL columns, auto-mirror them.** On
  `ContactObserver::creating()`/`saving()` (`app/Observers/ContactObserver.php`
  — already the home of the `agent_id` default-on-create logic per
  `.ai/specs/contacts.md` §"Agent Assignment"), when `type === 'entity'`: set
  `first_name = entity_name`, `last_name = ''`. Zero schema relaxation, zero
  null-safety sweep across the dozens of places that read `first_name`
  directly (Excel export's Name/Surname columns, WhatsApp/email templates,
  `getInitialsAttribute()`, etc.) — they keep working, just show the full
  entity name in "Name" and a blank "Surname". `getFullNameAttribute()`
  (§4.3) still branches explicitly so the *composed* display never shows a
  trailing space.
- (rejected) Relax `first_name`/`last_name` to nullable and null-safe every
  consumer — correct in principle, much larger blast radius for no real
  benefit given the mirror achieves the same visible result safely.

### 4.2 `contact_representatives` — NEW pivot (entity Contact → natural-person Contact)
```
id
entity_contact_id          FK -> contacts, cascadeOnDelete
representative_contact_id  FK -> contacts, cascadeOnDelete
is_primary                 boolean, default false
timestamps, deleted_at
unique (entity_contact_id, representative_contact_id)
```
Deliberately minimal per Johan's scope — no `relationship_type` enum, no
authority-document FK (can be added later without a breaking change if
needed; not required for the launch-blocker answer). **The representative
may be absent** — an entity Contact with zero rows here is valid and
expected (the scraper case, §6.1: entity owner captured, director unknown).
`representative_contact_id` must reference a Contact with
`type = 'natural_person'` — enforced at the write path, mirrors how
`PropertyContactController::LINK_ROLES` already validates its own role
vocabulary.

#### 4.2.1 SHARED FOUNDATION EXTENSION — capacity + proxy (Johan, 2026-08-15) — BUILT `feat/entity-rep-foundation`
Two additive columns on `contact_representatives` (migration `2026_08_26_000001`,
no backfill; existing rows default to "all reps sign, no label"):
```
capacity        varchar(40) NULL   -- Director|Executor|Trustee|Member|Other (per-link; a
                                      person can be Director of X, Executor of Y)
signs_as_proxy  boolean default 0  -- this rep signs for ALL reps of the entity
index (entity_contact_id, signs_as_proxy)
```
Capacity vocabulary = `ContactRepresentative::CAPACITIES` (fixed for v1, agency-editable later).
Single-`signs_as_proxy` and single-`is_primary` per entity are enforced at BOTH write paths
(`ContactRepresentativeController::attachRepresentative`/`updateRepresentative`;
`TvaCompanyDirectorsController::linkDirector` defaults `capacity='Director'`), demoting any prior holder.

**CANONICAL API (the contract esign + DR2 both consume — do NOT re-implement per lane):**
```
Contact::signingRepresentatives(): Collection<Contact>   // proxy → [proxy]; else ALL reps; empty for
                                                         // natural person / rep-less entity. Each carries
                                                         // ->pivot->capacity for phrasing.
Contact::emailRepresentatives():   Collection<Contact>   // who receives the e-sign email (= signers for now)
Contact::hasProxyRepresentative(): bool
```
Setup UI: `corex/contacts/show.blade.php` Representatives panel — per-rep capacity dropdown + single
"Proxy — signs for all" toggle + badges; route `PATCH representatives.update`. Test:
`tests/Feature/Contacts/ContactRepresentativeCapacityProxyTest.php`. Consumed by esign §6.2 (recipient
builder) and DR2 §6.5 (company attorney/supplier signers).

### 4.3 `Contact::getFullNameAttribute()` — the single highest-leverage fix
Current (`app/Models/Contact.php:523-526`):
```php
public function getFullNameAttribute(): string
{
    return $this->first_name . ' ' . $this->last_name;
}
```
Change to branch on `type`, returning `entity_name` cleanly (no trailing
space) for entity contacts. **This one accessor is read by nearly every
downstream consumer** — `toSearchResult()` (used by DR2's and the
Evaluation Certificate's search endpoints), general contact search results,
WhatsApp/email compose, property Contacts tab — so fixing it here means most
of §6's insertion points need **no separate display change**, only the
entity-aware *input*/*validation* handling specific to each.

---

## 5. THE ONE MODEL DECISION — recommend (a)

### (a) Entity as its OWN Contact (`type='entity'`), linked to representative Contact(s) — RECOMMENDED
An entity is a first-class Contact row. A representative is a **separate**,
ordinary `type='natural_person'` Contact, linked via `contact_representatives`.
- A director sitting on 3 entities stays **ONE** Contact record, linked via
  3 separate pivot rows — matches Non-Negotiable #10 (Universal
  Match-or-Create: one real person, one contact, never duplicated).
- Multi-director entities: trivial — add more pivot rows, mark one
  `is_primary`.
- The scraper case (entity owner known, director unknown) is the natural
  resting state — create the entity Contact alone, add representative rows
  later whenever a human captures that detail.
- Every existing Contact-consuming feature (property linking, FICA, e-sign,
  DR2, evaluations) keeps treating "a Contact" as one thing; the only new
  concept is a second, optional relationship hanging off it.

### (b) Entity fields bolted onto a single natural-person Contact row — rejected
`entity_name`/`entity_reg_no` set directly on the representative's own
Contact row (no separate entity Contact, no pivot).
- Simpler for the single-director-single-entity case — one row, no join.
- **Breaks on the first real-world case that matters most**: a director on
  3 entities would need either 3 duplicate Contact rows for the same human
  (violates Non-Negotiable #10 directly — Match-or-Create exists precisely
  to prevent this), or one row that can only ever represent one entity at a
  time, silently wrong the moment a second one shows up.
- The scraper case doesn't fit either — a scraped entity-only owner (no
  known director yet) has no natural person to attach the entity fields to
  at all; you'd need a placeholder person-row wearing entity clothing, which
  is worse than just... having an entity Contact.
- Property ownership (§6.3) already links a Contact by `role`, not by "is
  this a person" — bolting entity fields onto a person row doesn't save any
  work there either.

**Recommendation: (a).** Same two new fields either way; the only
difference is where they live and how the person-link works, and (a) is the
only one of the two that survives contact with multi-director reality and
Non-Negotiable #10 without a workaround.

---

## 6. Insertion-points map

### 6.1 Scraper capture — REPLACES cc5's interim "coming soon" block
`DeedsCaptureController::resolveOwnerContact()`
(`app/Http/Controllers/Api/DeedsCaptureController.php:240-283`) currently
creates/dedupes a Contact per owner and copies the cosmetic `id_type`
(`sa_id`/`company_reg`) through unchanged. Change: when the incoming
`id_type === 'company_reg'`, create/match the Contact with `type='entity'`,
`entity_name` = the captured name, `entity_reg_no` = the captured
`id_number` (moving off the overloaded shared column) — **representative
left blank**, exactly the case §5(a) is built for.
`tracked_property_owners` needs no schema change (`is_primary` already
supports multi-owner capture); promotion (`DeedsCaptureController::promote()`,
`app/Http/Controllers/CoreX/DeedsCaptureController.php:179-248`) links the
entity Contact to the Property via `contact_property` role=`owner` exactly
as today — no pivot change.
**Coordination note:** this is the CoreX-side counterpart to cc5's
browser-extension "coming soon" block. Once this lands, entity owners can be
accepted immediately instead of hard-blocked at the scraper — but retiring
cc5's block is cc5's call/timing, not this build's; this spec only needs to
make the CoreX side ready to receive entity owners cleanly.

### 6.2 E-sign seller-line hook — PIPELINE-GATED, needs a test diff
`RoleBlockExpansionService::resolveContactValue()`
(`app/Services/Docuperfect/RoleBlockExpansionService.php:2184-2219`) is
where a Contact's identity merges into a document's role block. The
`name`/`full_name` case (2200-2205) and `name_surname_id` case (2206-2209,
which composes `"{full name} (ID: {id_number})"`) both currently assume a
natural person — for an entity Contact today this silently renders a
broken `" (ID: )"` string. Add an entity branch:
`name`/`full_name` → `entity_name`;
`name_surname_id` → `"{entity_name} (Reg: {entity_reg_no})"`, and — when a
primary representative exists — extend to the SA legal convention
`"{entity_name} (Reg: {entity_reg_no}), herein represented by {representative full_name}"`.
`RoleBlockExpansionService.php` is on the e-sign pipeline gate list in
`CLAUDE.md` — this change **requires** an accompanying test diff in
`tests/Feature/Docuperfect/SigningView/` per the existing dev-check gate;
do not bypass with `-SkipPipelineGate`.

### 6.3 Property ownership — unchanged
`contact_property` pivot, `PropertyContactController::LINK_ROLES`,
`Property::sellerSidePivotRolesForListingType()`/`esignRoleForPivotRole()`
(`app/Models/Property.php:666-720+`) all need **zero changes** — an entity
Contact links exactly like a natural person Contact today. Verify this
holds at build time; flagged here so nobody "fixes" something that isn't
broken.

### 6.4 Evaluation Certificates (this session's Phase 1 build)
`EvaluationCertificateController` (`app/Http/Controllers/Tools/EvaluationCertificateController.php`,
built 2026-08-12) — `propertyContact()`/`searchContacts()` inherit entity
display for free via `getFullNameAttribute()`/`toSearchResult()` (§4.3), no
change needed. `contactInline()` (lines 135-179) validates
`first_name => required` — needs an entity branch: when the payload
indicates `type='entity'`, require `entity_name` instead and set
`first_name`/`last_name` per §4.1's observer-mirror path (or pass through
the same observer so this controller doesn't duplicate that logic).

### 6.5 DR2 (Deal Register V2)
`DealRegisterController::contactInline()`
(`app/Http/Controllers/Dr2/DealRegisterController.php:949-993`) — the exact
same shape and the exact same fix as §6.4 (these two `contactInline()`
implementations are already near-verbatim mirrors of each other, confirmed
this session). `contactSearch()`/`propertyContacts()` inherit for free, same
as §6.4.

### 6.6 FICA link
No FICA code changes (§3). One optional UX thread: the FICA intake form
(`resources/views/fica/form.blade.php`, `resources/views/compliance/fica/create-wet-ink.blade.php`)
MAY pre-select its own `entity_type` radio from `contact.type === 'entity'`
when opening a FICA flow for an entity Contact, saving a redundant click —
does not touch FICA's validation, storage, or the 4-way `entity_type`
distinction in any way.

### 6.7 Dedup — `ContactDuplicateService`
`ContactDuplicateService::findDuplicates()`
(`app/Services/ContactDuplicateService.php:29-80`) matches on
`AgencyContactSettings::forAgency($agencyId)->duplicate_match_fields`
(default `['phone', 'email', 'id_number']`) via a generic
`normalizeValue()`/`normalizeDbExpression()` pair per field. Add
`entity_reg_no` as a recognised field in that same normalize pair (mirrors
how `id_number` is already normalized — strip whitespace, uppercase/trim
per SA CIPC number conventions) and include it in the **default**
`duplicate_match_fields` list. Per the task's explicit instruction: an
**entity** contact dedups on `entity_reg_no`; a **natural person** contact
dedups on `id_number` — these are already disjoint fields (only one is ever
populated per `type`), so no special-casing beyond "recognise the new field"
is needed — the existing per-field-match-if-present loop handles the
type split for free.

---

## 7. UI

- Contact form: **Type** radio (Natural person / Entity), defaulting to
  Natural person. When Entity: swap the ID Number field for **Entity Name**
  + **Registration Number**; show a **Representatives** panel below (search-
  existing-or-quick-add natural-person Contact, same Match-or-Create picker
  pattern already used by DR2/Evaluation Certificate — no new UI pattern to
  invent), each row with a "Primary" toggle.
- Property Contacts tab: a linked entity owner shows its representative(s)
  inline (read-only chips, "Add representative" shortcut) — mirrors the
  design already laid out in `company-owned-properties.md` §5.2, unchanged
  by this refinement.
- Deeds-capture view (`resources/views/corex/deeds-capture/index.blade.php:74,91`):
  currently swaps a label based on `id_type`; update to read `contact.type`/
  `entity_name` instead once §6.1 lands, so a captured entity shows its real
  name+reg number, not just a relabelled ID field.

---

## 8. Permissions

No new permission keys anticipated — entity fields and the representative
picker sit inside existing contact-create/edit gating (`access_contacts` +
`LogsContactAccess:edit`), the same gate every insertion point in §6 already
uses. Confirm at build time; default to reusing existing gates unless a
concrete need for finer-grained control surfaces.

---

## 9. Acceptance criteria

- [ ] `contacts.type`/`entity_name`/`entity_reg_no` migrated; existing rows
      default `type='natural_person'` (zero behaviour change for the
      existing book of contacts).
- [ ] `contact_representatives` pivot created; write path validates the
      representative Contact is `type='natural_person'`.
- [ ] `ContactObserver` mirrors `entity_name` into `first_name`/blank
      `last_name` for `type='entity'` contacts on create/save.
- [ ] `Contact::getFullNameAttribute()` branches correctly (§4.3); verified
      against a real entity Contact — no trailing space, no blank.
- [ ] Contact form: Type radio + entity fields + Representatives panel,
      create + edit.
- [ ] `ContactDuplicateService` recognises `entity_reg_no` as a match field;
      an entity contact with a matching reg number is caught as a duplicate.
- [ ] Deeds-capture ingestion (`resolveOwnerContact()`) stamps
      `type='entity'`/`entity_name`/`entity_reg_no` for `id_type='company_reg'`
      owners, representative left blank; promotion links unchanged.
- [ ] E-sign `resolveContactValue()` renders an entity's name/reg line
      correctly (with "herein represented by" when a primary representative
      exists) — **test diff in `tests/Feature/Docuperfect/SigningView/`
      required**, dev-check pipeline gate must pass ungated.
- [ ] DR2 and Evaluation Certificate `contactInline()` both accept an entity
      payload (`entity_name` instead of `first_name`) without duplicating
      the observer's mirror logic.
- [ ] FICA untouched and verified still green (§3 — this is a
      non-regression check, not new FICA work).
- [ ] Single most-relevant test file green per phase; demo migrated +
      parity verified per Non-Negotiable #12.

---

## 10. Files to create / modify (build-time reference)

**Create:** migration for `contacts.type`/`entity_name`/`entity_reg_no` +
`contact_representatives` table; `app/Models/ContactRepresentative.php`;
feature tests under `tests/Feature/Contacts/` and
`tests/Feature/Docuperfect/SigningView/` (pipeline-gate requirement, §6.2).

**Modify:** `app/Models/Contact.php` (`type` cast, `getFullNameAttribute()`,
`representatives()`/`representedEntities()` relations);
`app/Observers/ContactObserver.php` (entity first/last-name mirror);
`app/Services/ContactDuplicateService.php` (`entity_reg_no` match field);
`app/Http/Controllers/Api/DeedsCaptureController.php` (`resolveOwnerContact()`);
`app/Services/Docuperfect/RoleBlockExpansionService.php` (`resolveContactValue()`
— pipeline-gated, test diff required); `app/Http/Controllers/Tools/EvaluationCertificateController.php`
(`contactInline()` entity branch); `app/Http/Controllers/Dr2/DealRegisterController.php`
(`contactInline()` entity branch); `resources/views/corex/contacts/index.blade.php`,
`contacts/show.blade.php` (Type radio + entity fields + Representatives panel);
`resources/views/corex/deeds-capture/index.blade.php` (read `type`/`entity_name`).

---

## 11. Phased build (one concern per prompt, per project convention)

1. **Data layer** — migrations, model relations, `ContactObserver` mirror,
   `getFullNameAttribute()` fix. *(foundation — everything else depends on
   this landing first, per the "do first" framing.)*
2. **Contact form UI** — Type radio, entity fields, Representatives panel.
3. **Dedup** — `ContactDuplicateService` entity_reg_no matching.
4. **Scraper thread** — `resolveOwnerContact()` entity stamping (coordinate
   timing with cc5, §6.1).
5. **E-sign thread** — `resolveContactValue()` entity rendering + required
   pipeline test.
6. **DR2 + Evaluation Certificate threads** — `contactInline()` entity
   branch in both controllers.
7. **Close-out** — dev-check targeted run, demo deploy + parity,
   `CHAT_STARTER.md` update.

---

## 12. Explicitly out of scope

- FICA code changes — confirmed complete, do not touch (§3).
- cc5's interim scraper-side "coming soon" block — this spec makes CoreX
  ready to receive entity owners; retiring cc5's block is a separate,
  coordinated decision, not part of this build.
- Any code, migration, or deploy — this is a design/build spec only, per
  Johan's explicit instruction. `company-owned-properties.md`'s broader
  compliance-gap framing (§2.4 of that doc) stands as background context,
  but its specific FICA-status proposal is withdrawn per §3 above.
