# Rental Application — Per-Agency Field Configuration

**Status:** Spec — not yet built. NO CODE has been written against this spec.
**Date:** 2026-09-19
**Author:** cc5
**Pillar:** Contact (the applicant) — no new pillar; this is intake-form configuration for the existing
`RentalApplication` model.

---

## 0. Johan's instruction, verbatim in substance

> "another critical part here in the build. we need to ensure that we can customize the rental process
> for each agency. so if an agency wants more or less fields on their application we can do it
> specifically for them, not change the whole corex."

One codebase, configured per agency as data. Never a code branch per agency, never a hardcoded
`if (agency === X)`. Rental applications are already built and on Staging — **this is a retrofit onto
live-bound code**, not a greenfield build, and it must be designed properly before a line is written.

---

## 1. What already exists — read before designing anything new

This is not a greenfield problem. Two real, working per-agency configuration mechanisms already exist
for this exact form, and the spec below extends one of them rather than inventing a parallel system.

### 1a. The document checklist (partial precedent)

`RentalApplicationChecklistConfig` / `RentalApplicationDocumentRequirement` — an agency can already
configure which supporting documents are required, per employment type. A sentinel row
(`RentalApplicationChecklistConfig`) distinguishes "agency configured this to be empty" from "agency
never touched this" — the same Rule-17-safe pattern used throughout this module. **Not enforced at
submission** — missing documents only show as outstanding on the Returned Applications screen.

### 1b. The field-requiredness registry — the real, whole-form precedent, already built

This is the closest existing thing to what Johan is now asking for, and it already spans every
applicant-facing field, not just documents:

- `RentalApplication::submissionFieldRegistry()` (`app/Models/RentalApplication.php:411-456`) — one
  canonical array of `[key, label, group]` for every field that can be made compulsory, checked
  against the actual rendered form wording, not guessed (the method's own docblock, lines 405-409,
  quotes Johan: *"a wrong label is worse than a missing entry, because a missing entry fails the build
  and a wrong label ships silently"*).
- Requiredness lives as a **nullable JSON column**, `required_field_keys`, on
  `rental_application_qualifying_settings` (migration
  `2026_09_13_140000_add_submission_requirements_to_rental_application_qualifying_settings.php:41`).
  `RentalApplicationQualifyingSetting::requiredFieldKeysFor(?int $agencyId)` (lines 708-719): NULL
  column → falls back to `DEFAULT_REQUIRED_FIELD_KEYS` (lines 294-297); a *saved empty array* is a
  genuine agency choice ("nothing compulsory") and is honoured exactly as saved, never force-populated.
- The **same registry** drives both the server-side validation rule
  (`RentalApplication::submissionValidationRules()`, lines 494-547) and the applicant-facing form's
  `required` attribute (`resources/views/rental-applications/public/show.blade.php`, e.g.
  `:required="in_array('fieldkey', $requiredFieldKeys, true)"`) — the two cannot drift because they
  read the identical source.
- Conditional groups already work: a ticked field only becomes actually required if its group's
  trigger is true for *this* submission (`submissionGroupApplies()`, lines 474-482) — e.g. the
  `employed` group only applies when `employment_type === 'permanently_employed'`.
- Cross-consistency is test-enforced: a build-time test fails if a field is ever added to
  `fieldValidationRules()`/the public form without a matching registry entry.

**What this means for this spec:** the "required or optional, per agency, per field" half of Johan's
ask is *already shipped*. This spec does not rebuild it — it adds the parts that are genuinely
missing: shown/hidden, label wording, help text, order, and brand-new custom fields.

### 1c. What is genuinely NOT built today

Every downstream consumer of the field set is otherwise **hardcoded**, confirmed by direct reading,
not assumed:
- **Public form** (`rental-applications/public/show.blade.php`) — field existence, label, and position
  are one hand-written Blade component per field. Only `required` is dynamic.
- **Review screen** (`review.blade.php`) — hardcoded `$application->employer_name` style property
  access throughout.
- **PDF** (`RentalApplicationPdfService` → `pdf.blade.php`) — same hardcoded pattern; prints every
  field's row regardless of whether the agency currently requires it (shows blank if unset).
- **Exports** — confirmed: **no CSV/export functionality exists on rental applications at all**, so
  there is nothing to break there today, only something to build correctly if it's ever added.
- **Approval/decline emails** — a small, hand-picked field subset only (`full_name`, agency name,
  `approved_rental_amount`, or a pre-drafted free-text body an agent edits before sending) — genuinely
  low risk, not a broad field-flow-through surface.
- **Qualifying/scoring logic** — the automated 30%-of-gross-income calculator was **removed entirely**
  on 2026-09-14, on Johan's own explicit instruction (`RentalApplicationAssessment.php:121-133`),
  because its output had gone unread since an earlier rework. The authoriser's affordability judgement
  today is manual — free-form income/expense line items — not an automated field-driven formula. This
  consumer is currently close to moot; noted for whoever ever re-introduces automated scoring.

---

## 2. What an agency may NOT switch off — the honest answer, not a guess

**This is the one place I will not paper over a gap with a guess, because you asked me not to.**

I searched exhaustively — every field-level comment, every docblock, `.ai/specs/rental-applications.md`,
`.ai/specs/compliance.md` — for any statement tying a specific field to FICA, POPIA, the Rental
Housing Act, or "required by law." **I found none.** No field carries a legal citation anywhere in the
code today — not `id_number`, not `declaration_signature`, not `tpn_consent_signature`.

**More than that — I found the opposite of a locked set, already ruled on.** `RentalApplication.php`
carries Johan's own prior, explicit, twice-repeated ruling (2026-09-13, "round 5"): *"every field on the
applicant form is agency tick/untick, no locked set"* — and the settings controller's own comment:
*"we provide the system, they set it up the way they want to use it."* `id_number`,
`declaration_signature`, and `tpn_consent_signature` are all in the agency-untickable registry today,
exactly like `special_conditions` or `adults`. They ship required *by default*
(`DEFAULT_REQUIRED_FIELD_KEYS` includes all three), but an agency can currently untick every one of
them, including both signatures and the ID number.

**The one thing that is explicitly, deliberately NOT settings-gated** is signature *well-formedness*,
not signature *presence* — `RentalApplication.php:526-536` quotes Johan directly: *"that one is a
correctness bug and is NOT a settings question."* If a signature is present, it must be a genuine,
non-blank PNG; whether it's present at all is still an agency's call today.

**The one genuinely load-bearing legal citation that exists anywhere in this module** is not a field —
it's the qualifying formula's ceiling: `RentalApplicationQualifyingSetting.php:17-24` cites *"the
actual legal figure: rent must not exceed 30% of GROSS income (Johan, from his own reading of the
law)"*, and the settings screen shows a persistent warning (citing "Rental Housing Act affordability
guideline" verbatim) if an agency configures above it — a warning, never a block, and an agency can
still lower it. As noted in §1c, this formula's output isn't even read by anything today.

**So: this task asks me to identify compliance-locked fields, and the codebase's own most recent,
explicit ruling says there currently are none, by design.** I am not resolving this tension either way.
Two real possibilities, and only Johan can say which:
1. The 2026-09-13 "no locked set" ruling was correct for where the product was then, and this task is
   a genuine, deliberate policy change — introduce a locked floor now, for named legal reasons Johan
   can state even though the code never has.
2. The "no locked set" ruling stands, and what's actually wanted is a **default-on, agency-can-untick**
   set (already the mechanism, per §1b) plus a **visible warning** when an agency unticks something
   Johan considers risky (ID number, both signatures) — softer than a hard lock, consistent with how
   the 30%-formula ceiling is already handled (warn, don't block).

**Candidates, listed as UNKNOWN per your own instruction, not silently decided either way:**
- `id_number` — plausible FICA/identity basis, never stated in code.
- `declaration_signature` — plausible basis (an unsigned application may not be a valid record of
  consent to anything captured), never stated in code.
- `tpn_consent_signature` — the real-world correct characterization is very likely POPIA consent for a
  third-party credit check (TPN), but the codebase itself never makes this connection explicit anywhere
  — I checked.

---

## 3. Historical integrity — versioned snapshot on the application, argued

CoreX is a no-delete system; FICA requires five years' retention after the relationship ends. A
configuration change must never rewrite, hide, or orphan an application already captured.

**The mechanism:** a new JSON column, `rental_applications.field_config_snapshot`, written **once, at
submission** — not at creation, not on every save. Reasoning for the freeze point: a draft application
is still being worked on under whatever configuration is *currently live* — freezing at creation would
show a half-filled draft a stale, possibly-already-changed form, which is actively wrong while the
applicant is still using it. Submission is the one moment the record becomes a fact of what was asked
and what was answered — from that instant, the snapshot is authoritative and immutable, exactly the
same principle `rental_application_qualifying_settings`' own draft (`decline_email_subject`/`_body`
sealing pattern, and this module's PDF caching-per-generation) already use elsewhere in this codebase.

**What gets snapshotted:** the fully-resolved field list at the moment of submission — for every field
(shipped and custom): key, label, help text, order, group, and whether it was shown/required at that
moment. Not just a pointer to "config version 3" — the literal resolved values, so the record survives
even if the underlying config table is later restructured, and so no downstream reader ever needs to
resolve historical config through a separate lookup with its own edge cases.

**Rendering rule, applied everywhere a submitted application is displayed (review screen, PDF, any
future export):** if `field_config_snapshot` is set, render from the snapshot, always. If it's null
(the application is still a draft, pre-submission), render from the live, current configuration — this
is correct and expected, since a draft isn't a historical record yet, it's an in-progress one that
should track whatever the agency currently has configured.

---

## 4. Where the config is edited, and by whom

**A settings screen, not the wizard** — confirmed directly with cc4, who is specifying the Rentals
wizard step right now: their spec is scoped strictly to agency-configurable business-rule
settings in the numeric/toggle mould (my own `expiry_notice_window_days`, their two rental-inspection
windows) and **deliberately does not touch, reference, or reserve space for the application's own field
set** — confirmed zero overlap. Johan's own framing already called this correctly: the wizard is
first-time setup, a field editor is ongoing admin.

**New permission**: `rental_applications.manage_field_config`, following the established
`{module}.manage_settings` convention.

**New dedicated screen**, not an addition to the existing `resources/views/corex/settings/
rental-applications.blade.php` (957 lines already, covering the checklist, qualifying formula, decline
email wording, and more) — a field-by-field editor with reordering, label/help-text editing, and
custom-field authoring is a genuinely different, larger UI than that file's existing settings-toggle
shape, and would make an already-large screen materially harder to navigate. **[cc5 design call,
flagged]**: recommend `/corex/settings/rental-applications/fields` as its own screen, linked from the
existing settings screen, rather than growing it further — if Johan wants it folded into the existing
screen instead, that's a straightforward placement change, not a data-model change.

---

## 5. Defaults and existing agencies — the backfill is free, argued

**A new agency**: no row exists in the new field-config table → every field falls back to "shown, in
the shipped default order, with the shipped default label/help text" — the exact same null-fallback
pattern §1b already proves out in production.

**Every agency already on the system today**: because the new configuration table starts genuinely
empty for every agency (nobody has ever been able to configure this before now), the null-fallback
state **is, by construction, identical to what every agency already has** — the same fields, in the
same order, with the same labels, because the recorded "default" is a one-time, careful transcription
of exactly what `show.blade.php` renders today, not a fresh design. **No backfill migration is needed
at all** — the fallback IS the backfill, as long as the default field list/order/labels captured at
build time are transcribed from the real current template, not reconstructed from memory (the same
discipline `submissionFieldRegistry()`'s own docblock already insists on: *"every label... checked
against the exact wording on show.blade.php, not guessed"*).

---

## 6. Everything downstream — what each consumer must do

| Consumer | Today | What it must become |
|---|---|---|
| Applicant-facing form (`show.blade.php`) | Hardcoded Blade, one component per field | Loops over the resolved field list (shipped + custom, in configured order), rendering each with its resolved label/help/shown/required state. A field an agency added and a field CoreX ships render through the *same* loop — no special-casing custom fields into a separate "extra questions" appendix. |
| Review screen (`review.blade.php`) | Hardcoded property access | Same resolved-list iteration; custom field values read from a new `custom_field_values` JSON column (§7 below), not a real column per field. |
| PDF (`pdf.blade.php` via `RentalApplicationPdfService`) | Hardcoded template, prints every row regardless of requiredness | Same resolved-list rendering, but from the **snapshot** (§3), never live config — a PDF is a historical document by nature. |
| Approval/decline emails | Small hand-picked subset, or pre-drafted free text | Unaffected by default — neither mailable iterates the field set today. A future "surface a custom field's answer in the approval email" is a real, separate ask, not requested here, not built. |
| Qualifying/scoring | Manual (automated formula removed 2026-09-14) | Nothing to wire today; if automated scoring is ever rebuilt, it must resolve fields (including custom ones) through the same registry rather than a fresh hardcoded list. |
| Exports | Do not exist | Nothing to break. Any future export must be built against the resolved/snapshotted config from day one — named here so it's never built as a fresh hardcoded column dump. |

**The rule that prevents "a custom field shows on the form and nowhere else"**: there is exactly ONE
resolver — a `resolveFieldConfigFor($application)`-shaped service call — that every consumer (form,
review, PDF) goes through. No consumer is ever allowed its own field-iteration logic. This mirrors
`submissionFieldRegistry()`'s own existing discipline (one canonical array, everything reads it) rather
than inventing a second, parallel field-listing mechanism per screen.

---

## 7. Custom fields — the genuinely new piece, sized honestly

Configuring *existing* fields (show/hide, relabel, reorder, help text) is mostly extending the proven
§1b mechanism — additive JSON columns on a settings row, same fallback discipline. **Custom fields —
an agency adding a field CoreX doesn't ship — are architecturally different and the larger piece of
this spec.**

**Nearest existing precedent, and why it can't be reused as-is**: `DocumentCustomField`
(`app/Models/Docuperfect/DocumentCustomField.php`) already defines "a field as data" — key, label,
`field_type` (text/date/number), default value, sort order. But it's scoped per DocuPerfect *template*,
and its `assigned_to` enum (agent/lessor/lessee/buyer/seller) is a **signing-party** concept — rental
applications have no signing-party concept at all (`RentalApplication.php:16-18`'s own docblock:
"deliberately NOT routed through the e-sign wizard... that pipeline structurally requires an
agent-signing party"). The shape (key/label/type/default/sort_order) is worth mirroring; the
signing-party scoping is not.

**Proposed shape** (new table, `rental_application_custom_fields`): `agency_id`, `key` (agent-chosen,
namespaced e.g. `custom.pet_deposit` to guarantee it can never collide with a real column name),
`label`, `help_text`, `field_type` (text / number / date / yes-no / choice-list / **file upload**, per
Johan's own list of types asked for), `options` (JSON, for choice-list type), `section` (which of the
existing sections it's grouped under, or a dedicated "Additional questions" section), `sort_order`,
`required` (bool), SoftDeletes.

**Where a captured answer lives**: a new JSON column on `rental_applications`,
`custom_field_values` — keyed by the custom field's own `key`, not a new real DB column per field
(avoids a schema-per-tenant explosion as agencies add fields over time; consistent with how
`fields_json`-style JSON storage is already used elsewhere in this codebase for exactly this
"agency/document-defined field" problem).

**File-upload custom fields** are the one type that doesn't fit a JSON value cleanly — a file needs
real storage. **[cc5 flagged, not solved here]**: this would reuse the existing document-upload
pipeline (`RentalApplicationController::uploadDocument()`/the documents table) with a pointer back to
the custom field's key, rather than inventing a second file-storage mechanism — but the exact
attachment shape (one document row tagged with the custom field key vs. a dedicated join table) is a
real design decision for the build prompt, not settled here.

---

## 8. Does this architecture carry to inspections and leases? (Johan's question, not designed here)

**Inspections — yes, the same shape would carry over cleanly, but it is new, separate work, not free.**
Rental Inspections (cc4's build, `RentalInspection`/`RentalInspectionItem`) already has a structurally
analogous problem — "which items get checked" is a per-agency configurable list with exactly the same
historical-integrity concern (an inspection from a year ago must still show exactly what was checked
then, even if the agency's item list has since changed). The config-table-plus-snapshot-on-record
pattern this spec proposes would generalize directly: a `rental_inspection_item_configs`-shaped table,
a snapshot column on the inspection event. This is a real, separate build — its own spec, its own
consumers (the inspection capture screen, any inspection report/PDF) — not something this spec builds
or assumes free.

**Leases — no, and here's why rather than just asserting it.** A lease doesn't have a long,
variable-length applicant-facing intake form the way a rental application does — it's a small, fixed
set of business facts agreed between two parties (property, tenant(s), rent, dates, deposit, type).
There is no comparable "which questions do we ask" problem to configure per agency; the fields a lease
needs are the fields a lease needs, structurally, everywhere. Per-agency field configurability doesn't
map onto it in any way that would do real work — flagged as "does not apply," not silently skipped.

---

## 9. One coordination note for the conductor, not a decision needed from me

While investigating, I confirmed cc4's Rental Inspections work has landed on `origin/QA1` in four
stages (2026-09-13 through 2026-09-15), with their own commit message stating "Stage 5 — Setup Wizard
entries for the two window settings" is still outstanding. That means cc4's own wizard work and
whatever lane eventually builds this spec's settings screen will both be touching the Rentals area of
the Setup Wizard/settings hub around the same time. Worth sequencing or reviewing together so the
Rentals section of the wizard doesn't get two uncoordinated passes — not a blocker to writing this
spec, just naming it now rather than discovering it at build time.

Separately, and purely for the record since it surfaced during this investigation and I didn't chase it
further (out of this spec's scope): `git log --all` shows two commits touching
`.ai/specs/rental-inspections.md` (`9e9be1e7f`, `3b1e57bb7`), but that file is not present in
`origin/QA1`'s current tree under that name — the same "spec commit exists in history, file never
actually landed" pattern I found and fixed for `leases.md` a few days ago. Flagging it, not fixing it —
not this spec's job.

---

## 10. Out of scope (this spec)

- Custom fields surfacing in approval/decline emails or any future export — named as a real future ask
  in §6/§7, not built or designed here.
- The exact file-storage join shape for file-upload custom fields (§7) — flagged as a build-time
  decision.
- Whether a locked/compliance floor gets introduced at all (§2) — Johan's call, not decided here either
  way.
- Building the Rental Inspections item-config generalization (§8) — named as carryable, not designed
  or built.
- Any code, migration, or UI — nothing here is built. Spec only, per explicit instruction.
