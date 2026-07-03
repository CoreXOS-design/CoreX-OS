# Long-Form Draft-Persistence — AS-BUILT Audit

> **AT-165** (Offline draft persistence). Read-only audit feeding `.ai/specs/offline-draft-persistence.md`.
> No code changed. Investigated 2026-07-03; **independently verified 2026-07-03** (3 verification sweeps —
> reference-impl anchors, POPIA field anchors, and a completeness sweep). Corrections and additions from that
> verification are marked **[VERIFIED]** / **[CORRECTED]** / **[ADDED]** below. file:line anchors given.
> Effort = wiring cost to register a form into the shared draft layer (S/M/L).

## Existing client-side persistence (reuse, don't duplicate)
| Pattern | File:line | What it is |
|---|---|---|
| **Client-side draft autosave — THE reference impl** | `resources/views/marketing/hub.blade.php:27-58`, clear at `:110` | **[VERIFIED]** Alpine `x-data`, per-record key `corex_mktg_copy_{property.id}` (`:27`), `init()` restores (`:32`), `$watch` on **9** fields (`:45-46`) → `persistCopy()` JSON write (`:48-57`), `clearSavedCopy()` on publish (`:58`, called `:110`). Nit: only the 9 text/mode/tab/emoji fields persist — `selectedImages` is NOT persisted. **Per-record keying + field-allowlist already solved here — the canonical mixin to generalise.** |
| **[ADDED] Client-side form-input draft — 2nd precedent (sessionStorage)** | `command-center/calendar/index.blade.php:2779-2795` (persist) + `:2850-2859` (restore) | `persistCreateEventState()` snapshots the **actual create-event form** (`this.form`, picked properties/attendees) to `sessionStorage['corex.calendar.createEventState']`; restores `this.form` on init. Aimed at surviving full-page view switches (session-scoped, not long-term), but it IS real client-side form-input persistence — the audit's original "hub is the ONLY one" claim was **incomplete**. |
| **[ADDED] Draft autosave already shipped — template builder** | `docuperfect/templates/cds-builder.blade.php` (`cdsEditor()`, `manualSaveDraft()`, `draftSaving`) | The CDS template builder already implements its own draft-save. Treat as **done / precedent**, not a form to wire — but reconcile its UX (indicator wording) with the shared module so the agency sees one consistent "draft saved" language. |
| Server-side draft (not client) | `corex/properties/wizard.blade.php:9-22` → `corex.properties.wizard.draft` (`web.php:2369`) | **[VERIFIED]** Wizard is seeded from a server `$draft` model (`draftId` `:11`, `routes.draft` `:14`) — a real draft `property` record server-side, not localStorage. |
| Server-side draft + per-keystroke field autosave (not client) | `docuperfect/esign/wizard.blade.php:2666-2695` (`/esign/{flow}/draft`) + `:2989-2999` (`/autosave-fields`) | **[VERIFIED]** e-sign `saveDraft()` and `refreshPreviewDebounced()` POST to server endpoints. No web-storage involved. |
| sessionStorage / localStorage **UI-state only** (verified NOT form drafts) | `admin/p24/index.blade.php:451-475`, `presentations/analysis.blade.php:326-361`, `presentations/show.blade.php:2318-2364`, `command-center/tasks/index.blade.php:634-649`, `corex/map/index.blade.php` (view/filters), `corex/properties/{index,show}.blade.php`, `docuperfect/{create,templates}`, `command-center/today.blade.php:214`, theme prefs, widget-open flags | **[VERIFIED]** panel/scroll/view-mode/filter/theme only — no field values. `beforeunload` handlers in `role-manager`, `compliance/policy/edit`, `compliance/rmcp/edit`, `properties/show`, `docuperfect/documents/edit`, `presentations/public/show` are unsaved-changes **warnings/beacons**, not storage of field values. |

**Conclusion (updated):** Three client-side draft precedents exist — `marketing/hub` (localStorage, long-term), `calendar` create-event (sessionStorage, view-switch), and `cds-builder` (its own draft-save). The new module **generalises hub** and **absorbs/reconciles** the other two so CoreX has one draft layer, not three dialects. Forms with existing **server-side** drafts (property wizard, e-sign) only need the client layer to bridge the **pre-server-draft window** (before a draft id exists), not mirror everything.

## Qualifying forms
| # | Form | View | Save endpoint | ~Fields | Wizard | Alpine | New/Edit | Sensitive to EXCLUDE (POPIA) | Effort |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Property capture (simple) | `corex/properties/create-edit.blade.php:43` | `store`/`update` (`web.php:2379,2383`) | ~34 | no | partial | both | — (address/price public-ish) | **M** |
| 2 | Property capture (wizard) | `corex/properties/wizard.blade.php:10` | `wizard.draft` (`web.php:2369`) | ~22 / 4 steps | **yes** | **yes** | new | — | **S** (bridge pre-draft-id only) |
| 3 | CMA / analysis (compute) | `presentations/compute.blade.php:28` | `presentations.compute`, `.snapshots.save` | ~11 | no | no | edit | — | **M** |
| 4 | Presentations builder | `presentations/create.blade.php:30` | `presentations.store` (`web.php:2673`) | ~12 | no | no | new (+edit) | — | **M** |
| 5 | Deal register DR2 | `deals-v2/create.blade.php` (`dealWizard()`) **+ `deals-v2/form.blade.php`** | `deals-v2.store` axios (`web.php:616`) | ~30 / multi-step | **yes** | **yes** | new | price/commission internal (not POPIA-block) | **S** |
| 6 | Deal register DR1 | `admin/deals/form.blade.php:77` | `admin.deals.store`/`update` (`web.php:485-493`) | ~25 | no | **no** | both | seller/purchaser names + commission = internal | **L** |
| 7 | **FICA capture** | `fica/form.blade.php:92` (single 918-line file, **no partials**) | `fica.submit` (token) | **[VERIFIED] 125 fields, 121 x-model** | no | **yes** (`ficaForm()` :721) | edit/new by `$token` | **HIGH — see full FICA exclusion list below. `personal[id_number]` :123, beneficial-owner `id_number` :227. [CORRECTED] :797 is a JS tooltip string, not a field; the real funding narrative is `service[payment_method]` :553 + `pep[source_of_wealth]` :643; FICA has NO discrete bank/branch inputs** | **S wire / strict allowlist** |
| 8 | **Staff take-on (HR)** | `staff-take-on/wizard.blade.php` + `_step_*` | `save-step` PATCH (`web.php:1949`) | 8 steps | **yes (8)** | **no** | edit | **HIGH — `tax_reference_number`, bank (`account_holder/bank_name/branch_code/account_number/account_type`), `medical_aid_provider/number` (`_step_tax_banking:13-52`); `marital_status`/`home_address`/next-of-kin in `_step_personal`. [CORRECTED] there is NO id_number field anywhere in this wizard** | **L** |
| 9 | Agent onboarding (public) | `onboarding/create.blade.php:22` | `onboarding.store` | ~14 | no | no | new | **`id_number` :58** | **M** |
| 10 | Buyer capture / detail | `command-center/buyers/detail.blade.php` | `buyers.preferences`, `wishlists.*` (`web.php:1304`) | 8 small forms | no | partial | edit | — | **M** |
| 11 | Contact capture (match) | `corex/contacts/_match-form.blade.php:54` | `contacts.matches.store`/`.update` | ~17 | no | partial | both | — | **M** |
| 12 | Contact (prospecting) | `seller-outreach/entry/prospecting-create-contact.blade.php:64` | outreach store (`web.php:2087`) | ~8 | no | **yes** | new/link | **`id_number` :192** | **S** |
| 13 | Rental capture | `rentals/edit.blade.php:15` | `rentals.store`/`update` (`web.php:1055`) | ~15 | no | no | both | tenant/lease PII (moderate) | **M** |
| 14 | Agency / company settings | `admin/company-settings/index.blade.php` | multiple POSTs | **~106 tabbed** | no | no | edit | **exclude any SMTP/API-credential/secret panels** | **L** |
| 15 | E-sign prepare/recipients | `docuperfect/esign/wizard.blade.php` | `/esign/{flow}/draft` + `/autosave-fields` | very large | **yes** | **yes** | edit | recipient emails/IDs | **SKIP — already server-autosaved; do not double-persist** |

## [ADDED] Qualifying forms found in the completeness sweep (missed by the original 15)
The original table was **not complete**. An independent sweep surfaced these long input forms. Same S/M/L wiring
scale. This roughly **doubles** the roll-out surface — plan the phased roll-out (spec §9) accordingly.

| # | Form | View | ~Fields | Wizard | Alpine | Sensitive to EXCLUDE | Effort |
|---|---|---|---|---|---|---|---|
| 16 | Commercial Market Evaluation (create/edit) | `commercial-evaluations/create.blade.php:96` (+`edit`) | ~18-20 | no (2 `x-show` steps) | partial | — (valuation data) | **M** |
| 17 | Calendar event create | `command-center/calendar/index.blade.php:1986` (`#createEventFormV2`) | ~10 + pickers | no | **yes** | attendee/contact PII (names) | **S** (already sessionStorage-drafts — reconcile) |
| 18 | Task create | `command-center/tasks/index.blade.php:531` | ~6 + checklist | no | **yes** | — | **S** |
| 19 | **Payroll employee take-on** (create/edit) | `payroll/employees/create.blade.php:17` (+`edit`) | ~13 | no | **yes** | **HIGH — Banking section (account/branch/holder); compensation** | **M** |
| 20 | Whistleblow / compliance report intake | `compliance/whistleblow/create.blade.php:34` | tier + repeater ×10 (4 ea) + upload | no | **yes** (idempotency token) | **subject names/identifying allegations — treat as sensitive** | **M** |
| 21 | New Agent Application (internal create) | `onboarding/create.blade.php:22` | ~14 | no | no | **`id_number`** (see #9; same view) | **M** |
| 22 | Ad-builder (social ad canvas) | `corex/properties/ad-builder.blade.php` | ~47 bindings | no | **yes** | — (marketing copy) | **M** |
| 23 | Document Pack builder / Web Pack | `docuperfect/packs/create.blade.php:24` (`packBuilder()`) + `docuperfect/web-packs/form.blade.php:25` | ~17 / ~13 | no | **yes** | — | **M** |
| 24 | Admin User create/edit | `admin/users/create-edit.blade.php:81` | ~41 bindings, multi-tab | no | **yes** (fetch uploads) | **user PII (phone/email/role); NEVER any password/reset field** | **L** |
| 25 | Training lesson / course builder | `training/lesson-form.blade.php:20` + `course-form.blade.php` | content + media | no | multipart POST | — (lesson prose) | **M** |
| 26 | CDS Template Builder | `docuperfect/templates/cds-builder.blade.php` (`cdsEditor()`) | ~50 bindings | no | **yes** | — | **DONE — already drafts; reconcile UX only** |

**Borderline (wire only if TTL/threshold generous):** leave application (`my-portal/leave/apply.blade.php:13`, ~10),
rental-settings property add/edit (`rental/settings/properties/create.blade.php:24`, ~12, distinct from #13 lease edit),
deal pipeline-setup edit (`deals-v2/pipeline-setup/edit.blade.php`, ~26), targets entry
(`admin/targets/monthly-goals.blade.php` ~14, `activity-setup.blade.php` ~16), worksheet (`worksheet/index.blade.php` ~11),
outreach composer (`seller-outreach/_compose-form.blade.php`).

**Confirmed NON-existent / trivial (checked, not missing):** standalone mandate capture, standalone OTP capture
(both flow through DocuPerfect document/e-sign, not typed capture screens), separate listing-detail edit (= Property
create-edit #1), tenant application / maintenance request (no such view), lead capture (`portal-leads` is a triage
list), Ellie/AI prompt (chat box, not capture), agency signup (standard short register form). Settings/permission
panels (`corex/settings.blade.php` ~197 bindings, `role-manager`, `user-settings`, `contact-governance`) are
toggle/config, **out of scope** by policy — do not draft-persist permission toggles.

## POPIA global exclude (enforce via ALLOWLIST, not denylist)
`id_number`, `passport`, `tax_reference_number`, `vat_number`, `account_number`, `branch_code`, `bank_name`,
`account_holder`, `account_type`, `medical_aid_provider`/`medical_aid_number`, `marital_status`, home/residential
addresses, source-of-funds / funding narratives, biometric `signature_data`, and any `password`/`api_key`/`secret`/
`token`/SMTP-credential field. FICA (#7), staff-take-on (#8), payroll (#19) and admin-user (#24) carry the bulk of
sensitive data, so the layer persists an **explicit allowlist per form** (mirroring `hub`), never "everything except
a denylist".

### [VERIFIED] Exact fields to exclude — the two highest-PII forms
**FICA (`fica/form.blade.php`)** — every one of these is EXCLUDED from any allowlist:
- IDs/passports: `personal[id_number]`:123, `principal[id_number]`:468, `representative[id_number]`:522,
  `entity[donor_id_number]`:297, `entity[beneficial_owners][*][id_number]`:227, `entity[trustees][*][id_number]`:318,
  `entity[beneficiaries][*][id_number]`:350, `entity[partners][*][id_number]`:429
- Tax: `personal[tax_number]`:148, `principal[tax_number]`:493, `entity[company_tax_number]`:177,
  `entity[trust_tax_number]`:271, `entity[partnership_tax_number]`:408
- VAT: `entity[company_vat_number]`:181, `entity[trust_vat_number]`:275, `entity[partnership_vat_number]`:412
- Funding narratives (may embed bank refs): `service[payment_method]`:553, `pep[source_of_wealth]`:643
- Other regulated: `entity[trust_master_ref]`:259 (Master's Ref), `signature_data`:707 (biometric)
- Home addresses (if policy covers): `personal[residential_address]`:135, `principal[residential_address]`:480, and
  per-owner/trustee/beneficiary/partner `[address]` (231/322/354/433), entity registered addrs (185/301)
- **Net for FICA:** allowlist only the non-identifying descriptive fields (entity names, deal type, checkbox flags);
  exclude every ID/tax/VAT/funding/address/signature field. If the safe residue is too small to be worth persisting,
  **FICA may opt out of drafting entirely** — a defensible call given its PII density.

**Staff take-on (`staff-take-on/_step_*`)** — EXCLUDE whole steps:
- `_step_tax_banking` (POST `save-step/tax_banking`) — exclude the ENTIRE step: `tax_reference_number`:13,
  `account_holder`:22, `bank_name`:24, `branch_code`:31, `account_number`:33, `account_type`:37,
  `medical_aid_provider`:47, `medical_aid_number`:49 (health = special category), `medical_aid_main_member`:50,
  `medical_aid_dependents_count`:52
- `_step_personal` (POST `save-step/personal`) — exclude identifying/special-category: `date_of_birth`:12, `phone`:16,
  `home_address`:20, `marital_status`:24, `dependents_count`:33, `emergency_contact_*` & `next_of_kin_*`:43-47
- **[CORRECTED]** there is NO `id_number` field anywhere in staff take-on (the original audit was wrong); Step 1
  `_step_user` is read-only (no inputs). Safe-to-draft steps: Employment, Compensation (numeric), Leave, Compliance
  flags — draft those, hard-exclude Personal + Tax/Banking.

## Wiring buckets
- **S (already Alpine — add watcher + allowlist):** deals-v2 (#5), property wizard (#2, bridge only), prospecting contact (#12), FICA (#7, strict allowlist).
- **M (plain POST — wrap in x-data or serialize the `<form>`):** property create-edit (#1), presentations create/compute (#3,#4), onboarding (#9), rentals (#13), contact match (#11), buyer detail (#10).
- **L (no Alpine, big surface, most POPIA exposure):** DR1 deals (#6), staff-take-on (#8, per-step keying + sensitive-step exclusion), company-settings (#14, 106 fields + credential panels), admin user create/edit (#24, exclude all password/reset fields).
- **[ADDED] from the sweep:** S — calendar (#17, reconcile existing sessionStorage draft), task (#18); M — commercial eval (#16), payroll take-on (#19, exclude banking), whistleblow (#20), new-agent create (#21), ad-builder (#22), doc/web-pack (#23), training (#25); DONE — CDS template builder (#26, already drafts).
- **Skip / server-owned:** e-sign wizard (#15); property wizard & e-sign bridge the pre-server-draft window only. Settings/permission panels are out of scope by policy.
