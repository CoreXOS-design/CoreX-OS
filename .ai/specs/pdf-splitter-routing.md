# PDF Splitter — Destination-Aware Routing + FICA Auto-Kickoff (AT-105)

> Spec for AT-105. Build #5 on `Staging`, stacks with MIC / matcher / portal-leads / map
> builds for a combined live promotion. Extends existing systems — NO forks.

## Business requirement

When the PDF Pack Splitter imports a multi-document pack split against a selected
property, each split document must auto-file to the right place (the property
record and/or the linked seller/owner contact) per a per-doc-type, agency-configurable
setting. When a FICA document is in the pack, the agent may (toggle, not silent)
kick off a wet-ink FICA verification pre-populated with the contact, the FICA form,
and any ID copy / Proof of Residence found in the pack. Saves agents major manual
filing + FICA setup time.

## Pillars

- **Property** — split target; documents file to its Drive (`document_properties`).
- **Contact** — seller/owner derived from `contact_property` (role seller/owner/landlord/lessor);
  documents file to the contact (`document_contacts`); FICA verification is contact-keyed.
- **Compliance (FICA)** — wet-ink `fica_submissions` / `fica_documents`, the existing flow, pre-filled.

## Found systems (extended, not forked)

| System | Location |
|--------|----------|
| PDF Splitter | `app/Http/Controllers/Tools/PdfSplitterController.php` (`confirm()` line 306, `linkOutputsToProperty()` line 462) |
| Splitter UI | `resources/views/tools/pdf_splitter_review.blade.php`, `pdf_splitter.blade.php` |
| Doc-type catalogue | `document_types` table (`SplitterDocType` / `DocumentType`), `grouping` col contact/property/shared |
| Per-agency doc-type config | `agency_document_type_compliance` + `App\Services\Compliance\AgencyComplianceDocTypeService` |
| Doc-type settings UI | `app/Http/Controllers/Admin/SplitterDocTypeController.php`, `resources/views/admin/splitter/doc-types.blade.php` |
| FICA wet-ink | `app/Http/Controllers/Compliance/FicaController.php` (`storeWetInk()` line 173) + `FicaSubmission` / `FicaDocument` |

## Data model

`document_types` is a GLOBAL catalogue; per-agency config already lives in
`agency_document_type_compliance` (the compliance-required flag). The destination
config extends that same per-agency table — single source of truth, no new island.

Migration `add_destination_flags_to_agency_document_type_compliance`:
- `save_to_property` boolean NULLABLE (NULL = use grouping default)
- `save_to_contact`  boolean NULLABLE (NULL = use grouping default)

Default resolution (when no explicit row value): grouping `contact` → contact only;
grouping `property`/`shared`/null → property only (preserves current behaviour where
everything files to the property). Defaults: Mandate → Property; ID copy + Proof of
Residence + FICA → Contact (these three are grouping=`contact`).

## Part 1 — Destination settings

`AgencyComplianceDocTypeService` gains `destinationMapFor()`, `destinationForSlug()`,
`setDestination()`, `defaultDestinationForGrouping()`. Settings UI (Document Types)
gains a **"Save To"** column with two independent checkboxes (Property, Contact) per
row — tick either or both. `bulkSave` persists them per-agency.

## Part 2 — Splitter routes per settings

`confirm()` derives the seller/owner contact from the selected property
(`Property::sellerOwnerContact()`), then `linkOutputsToDestinations()` creates ONE
`Document` per split output and attaches it to property and/or contact per the
resolved destination. **No-orphan guarantee:** the property is always the split target,
so any doc whose configured destination is unavailable (e.g. contact-only with no
linked contact) falls back to the property. Both-ticked → attached to both.

## Part 3 — FICA auto-kickoff (agent toggle)

Trigger shown on the review screen ONLY when a FICA form (`fica`) page is present AND
the user holds `access_compliance` AND a seller/owner contact resolves. Toggling on:
`FicaWetInkService` (extracted from `storeWetInk`, used by BOTH the controller and the
splitter — single source of truth) creates a wet-ink `FicaSubmission` for the contact,
attaches the split `fica` → `fica_form`, `ids` → `id_copy`, `por` → `proof_of_address`
PDFs that are present, fires `FicaSubmitted`, and surfaces a link to the submission so
the agent completes the remaining verification steps manually. Entity defaults to
`natural`; received date defaults to today; source tagged `pdf_splitter`.

## Permissions

Splitter: `access_pdf_splitter` (unchanged). Settings: `access_settings` (unchanged).
FICA kickoff gated by `access_compliance` — hidden + server-enforced if absent.

## Robustness (input space)

- No property selected → no filing (existing behaviour); ZIP still produced.
- Property with no linked contact → contact-destined docs fall back to property (no orphan).
- Both destination flags off for a type → doc stays in ZIP, not auto-filed (agency choice; reported).
- FICA toggle off / absent → no FICA created; docs still file per Part 2.
- FICA form absent → toggle not offered; ID/POR still file to contact.
- All writes inside a transaction; no half-created records.

## Acceptance criteria

- Doc-type settings show Property/Contact tickboxes, either-or-both, per-agency.
- Splitting files each doc to its configured destination(s); both → both; Mandate→property, ID/POR→contact.
- FICA doc present → toggle appears; triggering creates a pre-populated wet-ink FICA
  (contact + form + any ID/POR auto-attached); agent finishes the rest.
- Existing splitter + FICA extended, not forked; `storeWetInk` still works via the shared service.
- Nav/settings present; configurable; no hardcode. php -l, view:clear, dev-check pass; Tinker-verified.

---

# AT-105 ENHANCEMENT — Many-to-many per-page contact routing + multi-FICA (2026-06-27)

Extends the above (no fork). The splitter review is now a PER-PAGE assignment
surface where each page links to ONE OR MANY contacts ACROSS MULTIPLE roles, and
"Link to CoreX" is split from "Download ZIP". FICA becomes one wet-ink process
per distinct assigned contact.

## Part 1 — Doc-type routing config (contact_roles SET + fica_slot)

`document_types` gains two catalogue columns (migration
`2026_06_27_120000_add_contact_role_and_fica_slot_to_document_types`):
- `contact_roles` JSON — the SET of parties a page of this type may route to:
  any of `seller_owner | buyer | tenant | landlord | lessor`. `seller_owner`
  resolves across the pivot SET `[seller, owner]`. `[]` = routes to no contact.
  Seeded: mandate/fica/ids/por/disclosure/listing_form → `[seller_owner]`;
  **offer_to_purchase → `[seller_owner, buyer]`** (the OTP links to all parties).
- `fica_slot` string — `id | por | fica_form | none`. Seeded fica→fica_form,
  ids→id, por→por.

Per-agency OVERRIDE lives on `agency_document_type_compliance` as nullable
`contact_roles` (JSON) + `fica_slot` — NULL inherits the catalogue (the AT-105
Save-To pattern). `AgencyComplianceDocTypeService` gains `routingForSlug()`,
`routingMapBySlugFor()`, `routingMapFor()`, `setRoleConfig()`. These REPLACE the
two former hardcodes (the slug→FICA-slot map and the party_role default).

Settings → Document Types: "Routes To" role CHECKBOXES (tick any) + "FICA Slot"
select, per row, agency-overridable. The **admin screen keeps `sort_order`**; the
**splitter review picker lists doc types ALPHABETICALLY by label** (display-only
sort in `pdf_splitter_review.blade.php`).

## Part 2 — Role-aware multi-contact resolver

`Property::contactsForRole(string $contactRole): Collection` returns ALL attached
contacts in the role-set (joint sellers/buyers), case-insensitive on the pivot
role. `Property::pivotRolesForContactRole()` is the canonical role→pivot-set map.
`sellerOwnerContact()` is KEPT (still used by `searchProperties`).

## Part 3 — Per-page assignment review screen

Rebuilt `pdf_splitter_review.blade.php` (one Alpine component). Each page row:
doc-type select + a contact-assignment cell that, for each of the doc-type's
allowed roles, lists that role's property contacts as CHECKBOXES. The agent ticks
ANY number ACROSS any/all roles (OTP page → all sellers + all buyers at once).
- **Auto-resolve:** first render ticks the role-resolved SET (all contacts in the
  doc-type's roles).
- **Sticky inheritance (per doc-type, whole SET):** the first page of a type
  defaults to its role-resolved set; each later page of the SAME type defaults to
  the previous page's tick-SET; an override replaces the set and becomes the new
  sticky. Independent per doc-type (FICA set vs OTP set never mix).
- **Unresolved role** (no attached contact) → inline select-existing / create-new,
  which links the contact to the property in that role (reuses
  `corex.properties.contacts.{search,link,createAndLink}`) and re-resolves.
- Posted as `contacts[page][]` (a SET per page). Submission uses hidden inputs
  mirrored from Alpine state (NOT checkbox `:checked`) so the post is
  deterministic. Shortcut legend REMOVED.

## Part 4 — Two distinct actions

`confirm()` = **Download ZIP** only (no filing, no FICA). New `link()` =
**Link to CoreX** (file + FICA, no ZIP). Same form, two `formaction` submit
buttons. Manifest retained in session so either action can follow a split.

## Part 5 — Multi-FICA kickoff (contact-keyed, per party)

`link()` groups pages by `(label, contact-SET)`, extracts each via qpdf
`extractPageSet` (arbitrary page lists), files one Document per group to the
property and/or EACH ticked contact (`fileGroupsToDestinations`, no-orphan
fallback to property). Then `kickoffMultiFica` groups the FICA-slot pages by EACH
assigned contact → ONE wet-ink `FicaSubmission` per distinct contact (a FICA page
ticked for two contacts → two processes), slotting each page per its `fica_slot`
via the shared `FicaWetInkService`. Per-contact dedupe (`existingActiveFica`),
agent TOGGLE (default on when an assigned FICA contact isn't `complete`),
compliance-permission gated. NO `fica_submissions` schema change.

## Addition 1 — FICA auto-attach ID/POR by assignment

`kickoffMultiFica` collects EVERY fica-tagged group (doc-types whose `fica_slot`
is `id`/`por`/`fica_form`) by EACH assigned `contact_id` — so a contact's wet-ink
verification pre-fills its ID, Proof-of-Residence and FICA-Form slots from the
pages THAT contact was ticked on (matched by tick, never by role). Attach-what's-
present: any of id/por/form that exist are attached; the rest are left for the
agent. Multi-contact correctness falls out of the per-`contact_id` collection —
Elize-as-buyer gets HER id/por, a seller gets THEIRS, independently; a contact
with only an ID page still starts a verification with just the ID. (This is the
enhancement's existing slot-collection — no new code; tests added to lock it.)

## Addition 2 — Guided tour (reuses the AT-41 tour engine, no fork)

Two coordinated tours in `app/Support/Tours/defs/pdf-splitter.php` (data only,
merged by `TourRegistry::all()`): `tools-pdf-splitter` (route
`tools.pdf_splitter.index` — name the pack → choose PDF → Upload & Split) and
`tools-pdf-splitter-review` (route `tools.pdf_splitter.review` — link a property,
fix doc types, assign pages to contact(s) incl. multi-tick + sticky + select/
create, FICA toggle, Link to CoreX vs Download ZIP, post-link FICA/Open-to-finish).
The flow spans two screens, so it's two tours because the engine skips any step
whose `data-tour` anchor isn't on the current page. `data-tour` anchors added to
both Blades. Auto-launch once + re-launch from each page's "?" launcher + listed
in the Guided Tours directory (`tools-` prefix → "Tools & Calculators"). Gated
`access_pdf_splitter`.

## FICA slot-collapse fix (2026-06-27) — root cause was the LABEL, not the slot

Live test: ID + POR + FICA pages to one contact → both ID and POR filed to
`id_copy`, POR slot empty. **Investigation (proven on staging):** the slot
mapping/loop/config are all CORRECT — `FICA_SLOT_TO_DOC_TYPE` is distinct
(`id→id_copy`, `por→proof_of_address`, `fica_form→fica_form`), `por`'s
`fica_slot` is `por` (catalogue + agency override), and calling `kickoffMultiFica`
with labels `ids`/`por`/`fica` yields three distinct slots. The collapse is
UPSTREAM: the POR page was classified `ids`, not `por`. A SA proof-of-residence
is an AFFIDAVIT headed "Republic of South Africa" quoting the deponent's ID
number + date of birth, so `classifyPage()` scores it higher on the `ids` keyword
bucket than `por`, and `ids` outranks `por` in the priority list → auto-label
`ids` → `fica_slot` `id` → `id_copy`. **Fix:** the score→label decision is
extracted into `resolveLabel()` with a strong Proof-of-Residence override — an
explicit "proof of residence"/"proof of address" phrase wins over `ids`; a pure
ID page (no such phrase) is unaffected. The agent can still relabel on review.

## Buyer-drops-pages + slot-collapse — root cause was the CLIENT sticky (2026-06-27)

Live 6-page test (seller FICA/ID/POR + buyer FICA/ID/POR): buyer ended with only
its FICA doc; seller collected both parties' ID/POR. **Investigation via a REAL
HTTP test** (`test_real_link_submit_*` — POSTs to `link()` with a real qpdf PDF +
manifest, not a hand-built array): the SERVER is correct — the exact submit yields
seller `{fica_form,id_copy,proof_of_address}` + buyer `{fica_form,id_copy,proof_of_address}`,
2 processes, 6 docs, nothing dropped/merged. So the bug is in the CLIENT submit.
**Root cause:** `resolveAssignments()` sticky was keyed **per doc-type, globally**
(`sticky[dt]`). A real pack is laid out per PARTY (seller's three docs, then
buyer's three), so the seller's `ids`/`por` sticky bled onto the buyer's same-type
pages, silently reverting the buyer's ID + POR to the seller. **Fix:** carry the
**previous page's** set (page-order, filtered to each page's candidates), so each
party's contiguous run stays on that party; the agent only switches at the party
boundary. The "both ID+POR → id_copy" symptom is a LABEL issue (the POR page
submitted as doc-type `ids`), proven by `test_real_link_two_id_labelled_pages_*`;
the slot is always derived correctly from the page's label (no server collapse).

## Final assignment model (2026-06-27) — the agent's tick is absolute

Headless-chromium proof (driving the exact deployed markup) showed the POST is
always faithful to the on-screen Alpine state — so the bug was never stale
serialisation. It was the auto-resolve FIGHTING the agent: it pre-ticked BOTH
parties on every FICA/ID/POR page and re-ran on every click, so the screen (and
thus the POST) drifted from what the agent ticked. Rebuilt to this exact spec:
- **No default** — pages load with zero contacts (killed "default both parties").
- **Touch is absolute** — once the agent ticks a page, nothing re-evaluates or
  overrides its contacts (`resolveAssignments` and reassign-on-click REMOVED).
- **Forward-fill convenience** — `forwardFill()` carries a just-set page's set to
  the following UNTOUCHED pages only, filtered to each page's valid candidates;
  it stops dead at the first touched page and never overrides one. So a per-party
  pack (seller's three docs, then buyer's) needs only a tick at each party
  boundary; everything else pre-fills, and the POST can only ever be the ticks.
Page state field renamed `manual`→`touched`. Verified headless: on-load empty;
natural ticking seller→1-3 / buyer→4-6 posts `[1,1,1,2,2,2]` (ticks==posted);
touching page 3 leaves page 4's buyer intact.

## Manual-QA flags (cannot prove statically)

- The Alpine `:checked` submission gotcha is avoided by design (hidden inputs);
  still worth one real-browser click-through of Link + ZIP.
- Legacy `lessee` (vs canonical `tenant`) pivot rows, if any, won't resolve under
  role `tenant` until normalised.

## Enhancement files

- NEW `database/migrations/2026_06_27_120000_add_contact_role_and_fica_slot_to_document_types.php`
- EDIT `app/Models/Property.php` (`contactsForRole()`, `pivotRolesForContactRole()`)
- EDIT `app/Models/SplitterDocType.php` (casts/consts)
- EDIT `app/Services/Compliance/AgencyComplianceDocTypeService.php` (routing resolvers)
- EDIT `app/Http/Controllers/Tools/PdfSplitterController.php` (`link()`, `propertyContacts()`, group helpers, multi-FICA; `confirm()` now ZIP-only)
- EDIT `app/Http/Controllers/Admin/SplitterDocTypeController.php` (roles/slot persist)
- EDIT `resources/views/admin/splitter/doc-types.blade.php` (Routes-To checkboxes + FICA-slot select)
- EDIT `resources/views/tools/pdf_splitter_review.blade.php` (per-page assignment rebuild)
- EDIT `resources/views/tools/pdf_splitter.blade.php` (multi-FICA banner)
- EDIT `routes/web.php` (`properties/{property}/contacts`, `link`)
- EDIT `tests/Feature/Tools/PdfSplitterDestinationRoutingTest.php`

---

## Files (original build)

- NEW `database/migrations/*_add_destination_flags_to_agency_document_type_compliance.php`
- NEW `app/Services/Compliance/FicaWetInkService.php`
- EDIT `app/Services/Compliance/AgencyComplianceDocTypeService.php`
- EDIT `app/Models/Property.php` (`sellerOwnerContact()`)
- EDIT `app/Http/Controllers/Tools/PdfSplitterController.php`
- EDIT `app/Http/Controllers/Compliance/FicaController.php` (route storeWetInk through service)
- EDIT `app/Http/Controllers/Admin/SplitterDocTypeController.php`
- EDIT `resources/views/admin/splitter/doc-types.blade.php`
- EDIT `resources/views/tools/pdf_splitter_review.blade.php`
- EDIT `resources/views/tools/pdf_splitter.blade.php`

---

# AT-278-follow — Multi-file upload (2026-08-11, reworked same day after live QA)

## Business requirement

The Splitter accepted exactly one PDF per upload. An agent splitting several
packs back-to-back (e.g. a batch of signed OTPs handed over at once) had to
re-visit `/tools/pdf-splitter` and re-upload for every single file. This
extends the upload to accept multiple PDFs at once.

**Design history (both iterations shipped the same day, first was replaced
after live QA on `qatesting2`):**

1. **First cut — queue, one file reviewed at a time.** Each uploaded PDF got
   its own manifest, but only the first was OCR'd immediately; the rest sat
   queued and the agent stepped through them one screen at a time via a
   "Continue to next file" button. Rationale at the time: reuse the
   single-file review/confirm/link pipeline verbatim, bound each request to
   one file's OCR cost.
2. **Final — one stacked review screen, one combined action.** Live QA
   feedback: "it should show all pdfs underneath each other... with clear
   [dividers]... names of the pdfs", and confirmed explicitly that Download
   ZIP / Link should be **one combined action for the whole batch, all
   against the same property** — not per-file actions. Every file in the
   batch is still split and OCR'd into its own manifest (so page provenance,
   qpdf extraction, and per-page labels/contacts stay file-scoped — this was
   NEVER a request to literally merge pages from different PDFs), but the
   review screen renders every file's table on ONE page, one property/deal/
   FICA-toggle picker for the whole batch, and ONE "Download ZIP" / "Link"
   submission that acts on every file at once. The queue/"Continue" UI is
   gone entirely — there is no "one file at a time" state anymore.

## Pillars

No pillar change. Same targets as the existing splitter (Property, Contact,
Compliance via FICA) — this only changes how many source PDFs one visit to
the tool can carry through that pipeline, and how many at once land on the
review screen / in a single Download ZIP or Link submission.

## Design

**Upload (`run()`)** accepts `pdf[]` (array, 1–20 files, `mimes:pdf|max:51200`
each). `base_name` is optional: blank → each file's own filename (slugified)
becomes its base; supplied → used as a shared prefix (`{base_name}_1`,
`{base_name}_2`, …) when more than one file is posted. Every file's storage
base is additionally namespaced with the uploader's user ID (`_u{userId}`) so
two different agents uploading identically-named files (a blank Base Name on
a file their scanner both happened to call `OTP.pdf`) in the same second can
never collide onto the same storage path/manifest ID and cross-file each
other's documents.

Every file is OCR'd in `run()`, in upload order, into its own
`buildManifestForFile()` manifest — `set_time_limit(300)` is called inside
that method, which RESETS PHP's execution timer on every call, so each file
gets its own fresh window rather than the whole batch sharing one countdown.
A file that fails (corrupt / 0 pages / empty upload) is skipped (recorded in
`splitter_skipped`, rendered as a banner) rather than aborting the rest of
the batch; if every file fails, `run()` redirects back to the upload page
with an error naming them. Session holds `splitter_batch` — the ordered list
of manifest IDs for the batch — and `splitter_skipped`.

**Review (`review()`)** loads every manifest in `splitter_batch` and renders
them ALL on one page (`loadBatchManifests()`), each in its own clearly
divided section headed by the original filename + page count. ONE property
picker, ONE optional deal picker, ONE FICA toggle apply to the whole batch.
A "Bulk (all files)" doc-type toolbar (Set ALL pages / Reset to
auto-detected) also spans every file. The Alpine component holds `files: [{
manifestId, base, originalName, pCount, pages: [...] }]` instead of a flat
`pages` array; `forwardFill()` (the per-type "next page inherits the last
tick" convenience) is scoped to `file.pages`, so one file's contact ticks
never bleed into the next file's same-labelled pages — the batch is reviewed
together but each file's page sequence stays independent.

**Download ZIP / Link (`confirm()`/`link()`)** iterate every manifest in the
batch. `confirm()` extracts each file's labelled ranges from ITS OWN source
PDF (qpdf only ever operates on one source file at a time) and drops all the
resulting output PDFs into ONE shared ZIP — filenames stay collision-free
because each file's storage base is already unique. `link()` builds groups
PER FILE (same reason — one extraction call per source PDF) then flattens
every file's groups into one list before the single
`fileGroupsToDestinations()` / `kickoffMultiFica()` pass — so a contact who
appears in two different uploaded packs still gets ONE wet-ink verification
covering pages from both, and every group files against the ONE property
picked for the batch. Two files that coincidentally produce the same
(label, contact-set) are never pdfunite'd together — they stay two distinct
filed Documents, so a page from file A can never physically merge into a
page from file B.

**Batch-binding defense.** The review form posts `manifest_ids[]` (the exact
set the page was rendered for) alongside the nested `labels[manifestId][page]`
/ `contacts[manifestId][page][]` fields. `confirm()`/`link()` call
`rejectIfStaleBatch()` first — if a NEW upload replaced the session's batch
in another tab since this page loaded, the stale tab's submission is rejected
(redirect to `review()` with an error) instead of silently applying its
labels/contacts to whatever batch is now active. `serveThumb()` takes an
explicit `?manifest=` (there is no single "current" manifest anymore — a
batch has several open on the same page) and checks it against
`session('splitter_batch')` membership, not just a shape regex, so a
thumbnail can never be pulled from a manifest outside the agent's own batch.
`manifest_ids` is optional server-side (omitted ⇒ no check), so the existing
`PdfSplitterDestinationRoutingTest` real-HTTP tests — updated to
`session(['splitter_batch' => [$id]])` + nested `labels[$id]`/`contacts[$id]`
— still work without posting it.

**"Link"** — renamed from "Link to CoreX" per live QA feedback; the 🔗 emoji
was dropped too. "Download ZIP" is unchanged.

## Robustness (input space)

- 1 file uploaded → `splitter_batch` has one entry; review shows one section,
  Download ZIP/Link behave the same as the original single-file flow.
- Two files with the same/blank original name in one batch → base-name
  collision resolved with a numeric suffix (`_2`, `_3`, …) before OCR runs.
- Two different agents' concurrent uploads with the same blank-name file →
  resolved by the `_u{userId}` storage namespace, not just intra-batch dedup.
- A file that fails OCR — first in the batch or last — is skipped; the rest
  of the batch still lands on the review screen. If EVERY file fails, the
  agent stays on the upload page with a clear error (nothing to review).
- A stale review tab (a new upload replaced the batch elsewhere) submitting
  Download ZIP/Link, or lazy-loading a thumbnail for a manifest outside the
  current batch → rejected server-side, never silently applied/rendered.
- Contacts ticked on one file's pages never forward-fill onto another file's
  pages (`forwardFill()` is scoped per `file.pages`, not the whole batch).
- No new scheduled cleanup was added for `private/splitter/*` — matches
  existing behaviour (originals/outputs were never swept before either).

**Fixed after a second audit pass (2026-08-11, same day):**

- **Cross-tenant ZIP collision (real regression, now fixed).** The combined
  batch ZIP's path was originally keyed only by the shared per-second
  timestamp (`batch_{ts}__split_pack.zip`) with no per-user component, unlike
  every other storage path in this file. Two different agents finishing a
  split within the same wall-clock second could overwrite each other's ZIP —
  `downloadLastZip()` performs no ownership check, so one agent's browser
  could download another's FICA/ID/proof-of-residence documents. Fixed by a
  random per-REQUEST token (`$batchToken`, 6 chars) folded into every file's
  storage `base` in `run()` (on top of the existing `_u{userId}` namespace),
  and the ZIP path now keys off `$manifests[0]['base']` instead of the bare
  timestamp — closing both this leak and a same-user double-submit race
  (double-click / browser retry landing on identical paths) in one fix.
- **Silent partial-batch processing (real gap, now fixed).** If a manifest's
  `manifest.json` went missing between upload and confirm/link (a long review
  session, a tmp-cleanup, a deploy), `loadBatchManifests()` used to silently
  drop it and proceed with the surviving subset — the agent would see a
  normal "Documents linked" / "ZIP generated" success banner with no
  indication a file (and any FICA pages on it) was never processed. Fixed:
  `loadBatchManifests()` now reports which IDs failed to load;
  `confirm()`/`link()` refuse to run at all (`loadCompleteBatchOrFail()`)
  unless every manifest in the batch loaded, and `review()` shows a blocking
  banner naming the count so the agent sees it before attempting either
  action.
- **Deactivated doc-type silently drops a page (edge case, now fixed).** If
  an admin deactivated a `SplitterDocType` while an unrelated batch sat in
  review, a page still carrying that now-inactive auto-label as its
  untouched-by-the-agent label would vanish from the ZIP with zero error
  (confirm()'s output loop only iterates currently-active slugs).
  `resolveFinalLabels()` now falls back such a page to `other` so it still
  lands somewhere visible instead of disappearing.
- **FICA double-kickoff on a double-click (partial mitigation).** The FICA
  dedupe check (`existingActiveFica()`) runs as an unlocked SELECT before the
  submission-creating transaction — a genuine race pre-dating this rework,
  not introduced by it, and not fully closed here (would need a DB-level
  lock/unique constraint in `FicaWetInkService`, shared by other callers,
  out of scope for a same-day fix). Mitigated at the UI layer: the review
  form's Link/Download ZIP buttons disable themselves on submit
  (`submitting` Alpine state), closing the common trigger (an impatient
  double-click) without touching the shared service.
- **Flagged, not fixed — pre-existing, out of scope.** `searchProperties()`
  and `propertyContacts()` are JSON endpoints under `/tools/pdf-splitter/
  properties/*` instead of `/api/v1/*`, so they're invisible to the Admin →
  API catalog — a violation of CLAUDE.md non-negotiable #7. These predate
  this batch rework (part of the original AT-105 build) and the pattern
  likely repeats across the PDF Suite's sibling tools; moving them is a
  separate, deliberately-scoped piece of work, not bundled into this fix.

**Known limitation, accepted (not silently patched over) — batch OCR time.**
`run()` now OCRs every file in the batch, sequentially, inside ONE HTTP
request before any of it reaches session; `set_time_limit(300)` resets PHP's
own timer per file, but the front-end web server's own request timeout is a
separate ceiling PHP cannot override, and nothing is persisted until the
whole loop finishes — a batch that runs long enough to hit that ceiling
loses the request (and every already-OCR'd file in it) with no partial save.
This is the direct cost of the "one page, one combined action" redesign
(explicitly chosen over the queue/JIT-OCR design, which bounded each request
to one file's cost but was confusing in practice). Acceptable for the batch
sizes this feature is actually used for (a handful of packs); the 20-file cap
in `run()`'s validation is a blunt backstop, not a real guarantee against a
large/slow batch. Flagged to Johan rather than re-architected unilaterally —
if large batches become routine, the fix is incremental/resumable OCR, not a
bigger request timeout.

**Fixed after a fourth audit pass (2026-08-11, same day) — multi-tenancy
lens.** First pass to specifically trace agency/authorization boundaries
rather than batch-combination logic. Found no exploitable cross-agency IDOR
(`Property::query()->visibleTo($request->user())` is used at every
property-resolution point; session-derived manifest paths are always checked
against the current session's own batch), but did find one real access-scope
defect and several data-integrity gaps in the batch rework:

- **ContactScope wrongly hides legitimately-attached contacts (real bug,
  now fixed).** `propertyContacts()` and `link()`'s `$attached` resolution
  both read `$property->contacts()` under the default `ContactScope`
  ('own'/'branch' role-based visibility) on top of `AgencyScope`. A contact
  properly attached to the property but captured by a *different* agent or
  branch was invisible to the splitter — the contact picker wouldn't show
  them, `$attached->has($cid)` would reject their ticked pages, and AT-167
  would block the whole Link with "assign a contact" for a page that
  genuinely had one. `PropertyContactController::search()` already patched
  around this exact defect on the identical relation, with the comment
  *"otherwise ... contacts captured by others [wrongly] come back empty"* —
  the splitter never adopted that fix. Both usages now
  `->withoutGlobalScope(ContactScope::class)`, matching the established
  pattern (AgencyScope + SoftDeletes still apply — only the role-based
  visibility filter is bypassed).
- **An uncaught OCR-pipeline exception could abort an entire batch (real
  bug, now fixed).** `buildManifestForFile()`'s already-caught "0 pages"
  case only covers a clean qpdf failure; a genuinely corrupt *page* inside
  an otherwise-fine PDF can make `pdftoppm` throw a `RuntimeException`
  mid-classification. Uncaught, that would 500 the whole `run()` request —
  losing every already-OCR'd file in the loop, since nothing reaches session
  until the loop finishes. Now wrapped in try/catch, logged, and treated
  exactly like the null-return skip path.
- **Stale-batch check misfired on a missing manifest, trapping the agent in
  an unrecoverable reload loop (real bug, now fixed).** `rejectIfStaleBatch()`
  compared the posted `manifest_ids[]` (built only from the manifests that
  actually LOADED) against the full, unpruned session batch using exact
  array equality — so the "one file went missing mid-review" case (which
  `loadCompleteBatchOrFail()` is supposed to catch with an accurate message)
  was instead misdiagnosed as "you started a new upload in another tab,"
  and reloading never fixed it since the same manifest stayed missing. Now a
  subset check (every posted ID must be IN the session batch; the session
  batch is allowed to have more) — the genuine "batch replaced elsewhere"
  case is still caught, the "batch shrank because a file expired" case now
  falls through to the correct message.
- **Missing-manifest banner said the actions were "blocked" but didn't
  actually disable them (real bug, now fixed).** The warning banner added
  in the previous audit pass claimed "Download ZIP and Link are blocked,"
  but the buttons' `:disabled` bindings never checked the missing-file
  count — only `submitting`/`!property`. Added `hasMissing` to the Alpine
  state, wired into both buttons.
- **`@copy()`/`ZipArchive::addFile()` failures were silently ignored (real
  gap, now fixed).** A failed single-part copy (disk pressure, permissions)
  or a failed ZIP add would still let `confirm()` report "ZIP generated"
  success with a document quietly absent from the archive. Now both are
  checked: a missing/empty extracted output is logged and skipped before it
  reaches the ZIP list; any `addFile()` failure aborts the whole ZIP with an
  explicit error rather than shipping a silently-incomplete archive.
- **0-byte extracted file masked as `size: null` instead of `0` (minor,
  now fixed).** `@filesize($abs) ?: null` collapsed a genuinely empty/
  truncated output the same way as a real stat() failure, hiding a corrupt
  filed document behind "size unknown." Now only an actual `false` return
  becomes `null`.
- **`bulkType` hardcoded to `'other'` regardless of the dropdown's actual
  first option (minor, now fixed).** If `'other'` were ever deactivated for
  an agency, the Bulk dropdown would visually show a different first option
  while the underlying Alpine state silently stayed `'other'` — "Set ALL
  pages" would then mislabel everything to an option the dropdown never
  offered. Now seeded from the doc-type list's own first entry.
- **Flagged, not fixed — qpdf error string never logged.** Added
  (`Log::warning` in `buildManifestForFile()` and the wrapped-exception
  path) so production support can distinguish "this one file is corrupt"
  from "qpdf is misconfigured for every upload" without reproducing
  manually.
- **Flagged, not fixed — deeper architectural items.** (1) No transaction/
  idempotency wrapping around `fileGroupsToDestinations()`'s per-group
  filing loop — a mid-batch failure leaves a partial commit, and a natural
  agent retry re-files the already-committed groups as duplicates. Fixing
  this touches `DealDocumentService::fileClassifiedDocument`'s transaction
  contract, shared by DR2/e-sign callers — out of scope for a same-day
  splitter-only pass. (2) The FICA dedupe race (`existingActiveFica()` is
  an unlocked SELECT before the submission-creating transaction) is
  unchanged from the previous audit's assessment — the client-side
  submit-disable mitigates the common trigger (a double-click) but the
  underlying race needs a DB-level lock/unique constraint in
  `FicaWetInkService`, shared by other callers. (3) `destinationForSlug()`
  is called per-group (not batched via the service's own
  `destinationMapFor()`) in both the AT-167 pre-flight check and
  `fileGroupsToDestinations()` — a real N+1 that scales with batch size (a
  40-group batch issues on the order of 160 queries for destination
  resolution alone), but fixing it touches the core filing-destination
  logic twice; deferred rather than risk a subtle behavioural change to
  compliance filing this late in the pass. (4) `resolveFinalLabels()`'s
  `'other'` fallback (added to stop a deactivated-doc-type page from
  vanishing) only works if `'other'` itself stays active — an admin
  deactivating the universal catch-all is an extreme, self-inflicted edge
  case not further hardened against. (5) `pdf.*` MIME validation is a hard
  whole-request 422 before the loop, inconsistent with every other
  per-file failure (empty/corrupt PDF) being a soft in-loop skip — a UX
  papercut (one bad file forces re-selecting the whole batch), not a
  correctness bug.

## Permissions

Unchanged: `access_pdf_splitter`, already required on every splitter route
(`index`, `run`, `review`, `confirm`, `link`, `thumb`, `download`,
`properties.search`, `properties.contacts`). The multi-file batch introduces
no new permission surface — FICA kickoff stays gated on `access_compliance`,
the deal picker on `access_deal_register_v2`, exactly as before.

## Acceptance criteria

- Uploading 2+ PDFs lands on ONE review screen showing every file, each in
  its own divided section (filename + page count), not a queue/one-at-a-time
  flow.
- One property/deal/FICA-toggle selection applies to the whole batch.
- Download ZIP produces ONE archive containing every file's split output,
  correctly labelled per file, with no filename collisions.
- Link files every page from every file against the ONE selected property;
  a contact assigned FICA-relevant pages in more than one file gets exactly
  ONE wet-ink verification carrying slots from every file it appears in
  (proven by `test_real_link_submit_two_file_batch_combines_into_one_property_and_one_fica_per_contact`).
  qpdf-extracted pages never physically merge across two different source
  PDFs, even when they share a label and contact set — each keeps the
  destination it inherits from its own type's default.
- A file that fails to OCR is skipped, named in a banner, and never silently
  drops the rest of the batch.
- A stale browser tab (batch replaced by a newer upload elsewhere) is
  rejected server-side on submit, not silently misfiled.
- "Link" button text has no emoji; "Download ZIP" unchanged.
- php -l clean; both Blade views render with 1-file and multi-file fixtures
  (verified via Tinker — the local test DB could not be bootstrapped in this
  environment, see the session's manual QA/audit notes).

## Files

- EDIT `app/Http/Controllers/Tools/PdfSplitterController.php` — `run()`
  OCRs every file inline (no queue deferral); `buildManifestForFile()`
  (renamed from the queue-era `buildManifestForQueueItem()`); new
  `loadBatchManifests()`, `rejectIfStaleBatch()`; `index()` back to trivial;
  `review()` loads/renders every manifest in the batch; `serveThumb()`
  requires `?manifest=` and checks batch membership; `confirm()`/`link()`
  rewritten to loop every manifest, combine into one ZIP / one filing+FICA
  pass. Removed: `continueQueue()`, `cancelQueue()`, `popNextQueuedManifest()`,
  `rejectIfStaleManifest()` (single-manifest version) — the queue no longer
  exists. `resolveFinalLabels()` now takes a plain posted-array slice instead
  of the whole `Request` (called once per manifest).
- EDIT `routes/web.php` — removed `tools.pdf_splitter.continue_queue` /
  `tools.pdf_splitter.cancel_queue` (queue-era only).
- EDIT `resources/views/tools/pdf_splitter.blade.php` — multi-file input,
  optional base name, skipped-files banner; queue/"continue batch" panel
  removed entirely (`index()` no longer needs queue state).
- EDIT `resources/views/tools/pdf_splitter_review.blade.php` — rebuilt for
  the stacked multi-file layout: per-file divider + table sections, shared
  property/deal/FICA panel, nested hidden inputs, `files[]`-based Alpine
  state, "Link" button (renamed, no emoji).
- EDIT `tests/Feature/Tools/PdfSplitterDestinationRoutingTest.php` — the 3
  real-HTTP `link()` tests updated to `session(['splitter_batch' => [$id]])`
  and nested `labels[$id][page]` / `contacts[$id][page][]`.

---

# WIRING HOOKS — batch intake by reference (2026-08-11, QA1)

Three ADDITIVE hooks so the e-sign "Recipient additional docs" flow (cc2) can
hand a batch of already-stored uploads to the splitter without a browser
re-upload. **Hard rule honoured: the existing `/run` (direct multipart
upload) → `review()` (session-driven) → `link()` (files + FICA) flow is
byte-for-byte unchanged in behaviour** — every addition is either a new
parallel entry point, a new optional session key nothing else reads, or a
new array key alongside existing ones. cc2's `SignatureController`/
`myDocuments` were not touched — this is splitter-side only.

## Hook 1 — Intake by reference

`POST /tools/pdf-splitter/intake-supporting` (`tools.pdf_splitter.intake_supporting`),
`PdfSplitterController::intakeSupporting()`. Payload: `signature_request_id`
(required int), `version_ids[]` (required array of `SignedDocumentVersion`
ids), `property_id` (optional int, explicit override — see Hook 2).

Every version is re-verified server-side against BOTH
`kind='supporting'` AND the stated `signature_request_id` (`scopeSupporting()`
+ `where('signature_request_id', ...)`) — a version id alone is never
sufficient to pull a file in. Each file is copied from its `file_path` on the
`local` disk into the splitter's own `private/splitter/originals/` (same
convention `run()` uses) and OCR/classified via the SAME private
`buildManifestForFile()` `run()` calls — no duplicated OCR logic. Lands in
the identical `session(['splitter_batch' => [...], 'splitter_skipped' => [...]])`
shape `run()` produces, then redirects to the SAME `review()`. A brand new
`splitter_context` session key (`signature_request_id`, `version_ids`,
`property_id`) rides alongside — `run()` never sets this key, so it does not
exist for a normal upload and every consumer of it is null-safe.

## Hook 2 — Property prefill

`review()` reads `session('splitter_context')['property_id']` (present only
for an intake-by-reference batch) and, if it resolves via the normal
`Property::visibleTo()` scope, passes a `$prefillProperty` shaped via the
SAME `Property::toSearchResult()` the client-side search endpoint already
uses — so it is indistinguishable from a manually-picked result to every
other part of the Alpine component. The blade seeds `property:
@json($prefillProperty ?? null)` (null for every existing flow) and a new
`init()` hook calls the EXISTING `loadContacts()` when a property is
prefilled — mirrors `pickProp()` exactly, no new contact-loading path. The
agent can still search/clear/repick freely; this is a default, not a lock.

`property_id` resolution: explicit param wins; otherwise falls back to the
first version's parent `docuperfect_documents.property_id` **read as a raw
column**, not via `Document::property()` (that relation targets
`RentalProperty`, a different table — verified mismatched against real QA1
data, id 6060 exists in `properties`, not `rental_properties`; not fixed,
out of scope, flagged here so nobody trusts that relation for this purpose).

## Hook 3 — Completion correlation

`fileGroupsToDestinations()` gained one additive key, `document_ids` (the
created `Document::id` for every group filed — `DealDocumentService::
fileClassifiedDocument()` already returned the `Document` model, just wasn't
being collected). At the end of `link()`, if `splitter_context` is present,
dispatches `App\Events\Docuperfect\SupportingBatchFiled` (extends the house
`AbstractDomainEvent`, auto-audited to `domain_event_log`) carrying
`signature_request_id`, the pulled-in `version_ids`, the newly created
`document_ids`, `property_id`, actor, agency — then clears `splitter_context`
(one-shot; never leaks into the agent's next unrelated upload). Fires even
when nothing was filed (0 documents) — the recipient-docs side decides what
that means for its own bookkeeping. **No listener registered here** — cc2
owns subscribing and stamping `SignedDocumentVersion::filed_at` /
`filed_by_user_id`; the splitter only fires the signal.

## Files (wiring hooks)

- NEW `app/Events/Docuperfect/SupportingBatchFiled.php`
- EDIT `app/Http/Controllers/Tools/PdfSplitterController.php` — new
  `intakeSupporting()`; `review()` gains `$prefillProperty`; `link()`
  dispatches the event + clears `splitter_context`;
  `fileGroupsToDestinations()` collects `document_ids`.
- EDIT `routes/web.php` — new `tools.pdf_splitter.intake_supporting`.
- EDIT `resources/views/tools/pdf_splitter_review.blade.php` — `property`
  seeded from `$prefillProperty`; new Alpine `init()`.

# SAME-AS-PREVIOUS / SAME-AS-NEXT PER-PAGE BUTTONS (2026-09-11)

## Business requirement

Johan, on the tedium of typing a document type on every page of a scanned
bundle: *"I have 20 pages. if I mark page 1 it should auto change down...
The part that we have to be clear about here is that if a user selected that
it doesnt get overriden."* — then, after thinking it through further, he
rejected the cascade model himself and specified the actual build:

*"we tried ocr and it doesnt really work as page scans are not readable. And
the start and stop part can work, but we still have to cater for the scan
thats scattered. so pg1 is fica, pg2 fica, pg3 id, pg4 fica - now the marker
will go pg1 to pg4 fica and override the id. I think for now Im happy with 2
buttons - same as previous page, same as next page?"*

**Why a cascade/auto-detect was explicitly rejected, on the record, so it is
never reintroduced:** a cascade from a document's first page sweeping forward
correctly handles a NEATLY sequential bundle, but real scanned bundles are
scattered — Johan's own example is pg1/pg2/pg4 FICA with pg3 an ID sandwiched
in between. A "mark the start of each document" cascade model would sweep
straight over pg3's ID. OCR-based auto-detection was tried separately and
rejected on its own merits — real page scans aren't reliably readable, so
nothing in this feature depends on reading page content to guess its type.
**The two buttons are the whole feature.** No cascade, no auto-detect, not
even as an opt-in — both were tried in some form and both were rejected by
the business owner for a stated, sound reason.

## Design

Two buttons per page row, "↑ Same as prev" / "↓ Same as next", each doing
exactly one explicit, one-shot copy: the CURRENT value of exactly one
same-file neighbour onto this page. Nothing else changes, no other page is
touched, and clicking one is never triggered by clicking another — this is
strictly per-page, agent-driven, same as the existing per-page contact
assignment model on this screen (`pg.touched` / `forwardFill`) already is.

**Edge pages:** the first page of a file has no "Same as prev" button; the
last page of a file has no "Same as next" button — `x-show` on each button
individually (`prevPage(file, pg)` / `nextPage(file, pg)`, same-file only via
`file.pages.indexOf(pg)`), so the button is ABSENT, not merely disabled.
Proven live: page 1 of a 17-page bundle shows only "Same as next", page 17
shows only "Same as prev", and a middle page shows both.

**"Neighbour has no type set yet":** every page always carries SOME label —
auto-detected, or the `other` fallback when nothing was recognised — there is
no blank/null state in the data model. Given Johan's own stated distrust of
the auto-detector, an auto-detected/default guess is deliberately never
treated as a confirmed choice. A new per-page flag, `labelTouched` (seeded
`false`), flips to `true` only on a genuine human action: the dropdown's own
`@change` (`onLabelChange`), a successful same-as-* copy, or (from
2026-09-12 — see "SET ALL PAGES — BULK-APPLY DID NOT RESPECT `labelTouched`"
below; between 2026-09-11 and 2026-09-12 this was FALSE) the "Set ALL pages"
action. "Reset to auto-detected" flips every
page's `labelTouched` back to `false`, since it's explicitly reverting to
unconfirmed guesses. Attempting to copy FROM an untouched neighbour refuses
silently-copying-a-blank by refusing outright: a toast — *"Page N hasn't had
its document type set yet — set that page first, then copy from it."* — and
the target page is left completely unchanged. Proven live.

**Chaining is not a cascade.** Once page N has been explicitly set — by
hand, or by a prior same-as-* click — it becomes a valid source for a LATER,
separately-clicked same-as-* on an adjacent page. This is not automatic
propagation: each page still requires its own explicit button click. Proven
live (page 10 set manually → page 9 "same as next" copies it → page 11 "same
as prev" then also copies it, each a distinct click).

**Keyboard-reachable:** plain `<button type="button">` elements, real
`disabled`-equivalent behaviour via `x-show` (removed from the tab order
entirely at an edge, not merely visually dimmed) — no custom click-only
affordance.

## Robustness (input space)

- File boundary: `prevPage`/`nextPage` index within `file.pages` only — a
  "previous/next page" never crosses into a different uploaded PDF in the
  same batch.
- Untouched neighbour → refused with a toast, target page provably
  unchanged (verified via Alpine's own reactive state, not just the visible
  `<select>`).
- Scattered-bundle proof (Johan's own example, walked live on a real 17-page
  bundle): pg1 FICA (manual) → pg2 "same as prev" (copies FICA) → pg3 ID
  (manual, deliberately breaking the run) → pg4 FICA (manual) → pg3 checked
  AFTER pg4 is set and confirmed still ID, untouched by anything.
- Existing contact-assignment behaviour on doc-type change (`onLabelChange`
  dropping contacts no longer valid for the new type) is preserved
  identically when the type changes via same-as-* — `copyNeighbourLabel`
  runs the same `allCandidateIds` filter the manual-select path already used.
- No server round-trip: this is pure client-side Alpine state, mirrored into
  the existing hidden `labels[...]` inputs on submit exactly as before —
  no new backend route, no new validation surface.

## Manual-QA proof (2026-09-11, QA1)

Real login (throwaway QA account, soft-deleted after), real browser
(Puppeteer + system Chromium), a real 17-page PDF uploaded through the actual
upload form (no lag beyond the pre-existing OCR/thumbnail pass, ~11s for 17
pages — unrelated to this change, the review screen itself is instant):

- Page 1: only "Same as next" visible. Page 17 (last): only "Same as prev"
  visible. Page 8 (middle): both visible.
- pg1→FICA (manual), pg2 "same as prev"→FICA, pg3→ID (manual), pg4→FICA
  (manual) — pg3 re-checked after pg4 and still reads ID.
- pg6 "same as prev" against untouched pg5 → refused, toast shown verbatim,
  pg6 unchanged.
- pg9 "same as next" against manually-set pg10 (FICA) → copies correctly.
  pg11 "same as prev" against pg10 (now itself a confirmed source, not
  auto-cascaded) → also copies correctly — proves chaining works without
  being a cascade.
- Every untouched page (5, 6, 7, 8, 12–17) remained on the default/auto label
  throughout — nothing spread anywhere it wasn't explicitly sent.
- Download ZIP remained enabled/functional after all of the above — the
  existing submission path is untouched.

## PAGE MULTI-SELECT — BUILT (2026-09-11, greenlit by Johan)

First investigated and estimated (small — reuses the existing bulk-apply
pattern, not a rebuild), then greenlit: *"GREENLIT — build the page
multi-select you proposed for the PDF splitter. This is Johan's original ask
and the two buttons alone don't answer it."*

Johan's original ask, restated for the record: *"I have 20 pages. if I mark
page 1 it should auto change down... if I select pg1 as rental application,
and everything down changes to this, and then on pg5 I select fica and it
changes down same."* — and the non-negotiable protection: *"if a user
selected that it doesnt get overriden... User changes pg 16-20. then for
some stupid reason goes and changes pg1 and the whole thing changes again"*
must never happen.

### Design (matches the estimate exactly)

- `pg.selected` — a new per-page boolean (seeded `false`, PHP `$fileSeed`),
  a tick box on each page's thumbnail cell ("Select"), no server persistence
  — it's a working-selection flag, cleared once acted on.
- `selectedCount` getter + a toolbar (doc-type picker + "Apply to selected" +
  "Clear selection") that only appears once at least one page is ticked —
  same visual shape as the existing "Bulk (all files)" toolbar, scoped to the
  ticked set instead of every page.
- "Select all pages" / "Clear selection" toggle link per file, in the
  file-divider header (`toggleSelectAllInFile`) — ticks/un-ticks every page
  in THAT file only, not across files in a multi-file batch.
- `applyToSelected()`: for every ticked page — sets the chosen type, marks
  `labelTouched = true` (an explicit choice from here on, same protection
  same-as-*/Set-ALL already use), re-filters `contactIds` through
  `allCandidateIds` exactly like every other type-change path, then clears
  that page's own `selected` flag. Pages NOT ticked are never touched,
  regardless of what page number they sit at relative to the ticked set.

### Why "16-20 survive a page-1 change" is a structural property, not a check

There is no cascade anywhere in this screen for multi-select to have
inherited. `applyToSelected()` iterates `allPages().filter(p => p.selected)`
— its target set is exactly and only the pages the agent ticked in THIS
action. Ticking page 1 and applying does not walk "down" the document in any
sense; it has no notion of "down." Pages 16-20 being touched or untouched by
a page-1 apply depends entirely on whether 16-20 were ALSO ticked in that
same action — nothing else. `labelTouched` is the same flag same-as-*/Set-ALL
already respected at the time this was written, so a page set via
multi-select was protected from being silently re-swept by same-as-*/
`applyToSelected()` afterward.

**CORRECTION (2026-09-12) — this sentence originally also named "Set ALL"
as a flag that already respected `labelTouched`. That was FALSE.** It did
not, at the time this section was written or for the following day. See
"SET ALL PAGES — BULK-APPLY DID NOT RESPECT `labelTouched`" below for the
bug, the live reproduction, and the fix. `applyToSelected()` itself was
never affected by that bug — it always filtered on `p.selected`, a
ticked-set the agent built explicitly, so it had no path to silently sweep
an untouched page. No new protection mechanism was needed — the existing one already covers this
apply path because it was designed to be path-agnostic.

### Manual-QA proof (2026-09-11, QA1, real 20-page bundle)

Real login (throwaway QA account, soft-deleted after), real browser
(Puppeteer + system Chromium), a real 20-page PDF through the actual upload
form:

- Ticked pages 16-20, applied "IDs / Identity" via multi-select — all five
  changed correctly, ticks cleared after apply.
- Ticked ONLY page 1, applied "FICA" — page 1 changed; pages 16-20 (and
  every other untouched page) re-checked immediately after and **confirmed
  still "IDs / Identity", exactly as set, untouched by the page-1 apply.**
  Screenshots taken of both the page-1 row and the pages-16-20 rows,
  confirming the same visually.
- Separately walked Johan's own scattered example via multi-select instead
  of the two buttons: ticked pages 2, 3, 5 (deliberately skipping 4),
  applied "Rental Agreements" once — pages 2/3/5 changed, page 4 (never
  ticked) remained on its prior value throughout.
- "Select all pages" ticked all 20 pages in the file; "Clear selection"
  (same link, now retitled) un-ticked all 20.
- Regression-checked the existing "Same as previous/next" buttons alongside
  multi-select in the same session — both still work correctly, unaffected.
- Download ZIP remained enabled/functional throughout.

Pure client-side change, same as same-as-*/Set-ALL before it — no new
route, no new query, no new data model beyond the one `selected` boolean, so
OWN/BRANCH/AGENCY scoping is unaffected by construction.

## SET ALL PAGES — BULK-APPLY DID NOT RESPECT `labelTouched` (found 2026-09-12, fixed same day)

**This section corrects false "proven live" claims made above on
2026-09-11.** Both the "SAME-AS-PREVIOUS / SAME-AS-NEXT" section and the
"PAGE MULTI-SELECT" section above asserted, in more than one place, that
`labelTouched` was "the same flag same-as-*/Set-ALL already respect." That
was true for same-as-*/`applyToSelected()`. It was **not** true for
"Set ALL pages" — that action set every page unconditionally, silently
overwriting any page the agent had already hand-set. Found by an
independent walk of this screen (not by the lane that wrote the original
claim), reproduced twice, and is exactly the failure Johan named as the
hard constraint when he first asked for multi-select: *"User changes pg
16-20. then for some stupid reason goes and changes pg1 and the whole thing
changes again."* Recorded here rather than silently corrected, per standing
instruction: a false "proven" line in a spec is worse than no line, because
the next lane trusts it.

### The bug

`setAll(slug)` iterated every page in the file unconditionally:
```js
setAll(slug) {
    this.allPages().forEach(p => { p.label = slug; p.labelTouched = true; ...});
},
```
No `labelTouched` check anywhere. A hand-set page was exactly as exposed to
being overwritten as an untouched one, on every call.

### The fix

```js
setAll(slug) {
    this.allPages().filter(p => !p.labelTouched).forEach(p => { p.label = slug; p.labelTouched = true; p.contactIds = p.contactIds.filter(id => this.allCandidateIds(slug).includes(id)); });
},
```
The predicate is the exact logical complement of "already touched" — every
page the agent has NOT explicitly set gets set, and only those. This is the
same shape of guarantee `applyToSelected()` already gave for its own ticked
set, now given to "Set ALL" for its own (implicit: "every untouched page")
target set. Every other bulk/copy path on this screen (`applyToSelected()`,
`copyNeighbourLabel()`/`sameAsPrevious()`/`sameAsNext()`) was individually
re-read during this fix and confirmed to already gate correctly — this was
the one unprotected path, not a symptom of a wider pattern.

Toolbar buttons also gained a `title` tooltip stating the guarantee in
plain language, so an agent isn't relying on tribal knowledge that "Set
ALL" is safe to use after hand-fixing a few pages.

### On the reported "skip" symptom

The blocker report described a second, mirror-image symptom on a different
application: "Set ALL" instead SKIPPING an untouched page and leaving it
unset. That could not be reproduced against this screen's actual code as it
stood immediately before the fix — the pre-fix `setAll()` had no
conditional logic at all, so there was no code path by which it could have
skipped a page; every page was always written unconditionally. This is
reported honestly rather than papered over: the discrepancy was not
root-caused. What can be stated with certainty is that the fix's predicate
(`filter(p => !p.labelTouched)`, inclusion of every untouched page) makes a
skip-of-a-genuinely-untouched-page structurally impossible now, regardless
of whatever mechanism produced the original report — the target set is
"every page where `labelTouched` is false," full stop, so no untouched page
can fall outside it.

### Override control — decided, not built

The blocker asked whether an agent should be able to deliberately override
their own earlier hand-set choices in bulk, and if so, said explicitly not
to build a new control without asking first. Decision: **no new control is
needed.** "Reset to auto-detected" already exists on this screen, already
clears every page's `labelTouched` back to `false` in one click, and is
already the deliberate, clearly-labelled escape hatch for "I want Set ALL
to touch pages I've already set" — an agent who wants that runs Reset first,
then Set ALL. This matches Johan's own stated rule, which was given with no
carve-out: hand-set choices are protected, full stop. Adding a second,
different "override" control would create two ways to achieve the same
outcome with different blast radii, which is a worse design than the one
already on the screen.

### Manual-QA proof (2026-09-12, local worktree, real 10-page bundle)

Real login (throwaway QA account `qa-cc5-bulkapply-bug-repro@example.invalid`,
id 212, soft-deleted after — see cleanup below), real browser (Puppeteer +
system Chromium), a real 10-page PDF generated via DomPDF and uploaded
through the actual upload form. Four tests run in one continuous session
(not four isolated runs) so each proof builds on genuinely-live state:

**TEST 1 — "Set ALL pages" respects hand-set pages 1, 4, 7.**
Hand-set 1→IDs/Identity, 4→FICA, 7→Mandate. Before Set ALL: 1=IDs, 2=Other,
3=Other, 4=FICA, 5=Other, 6=Other, 7=Mandate, 8=Other, 9=Other, 10=Other.
Ran "Set ALL pages → Proof of Residence". After: 1=IDs (unchanged),
2=Proof of Residence, 3=Proof of Residence, 4=FICA (unchanged),
5=Proof of Residence, 6=Proof of Residence, 7=Mandate (unchanged),
8=Proof of Residence, 9=Proof of Residence, 10=Proof of Residence. Pages
1/4/7 untouched; every other page set. PASS.

**TEST 2 — "Apply to selected" scoped correctly, 1/4/7 still untouched.**
Ticked pages 2 and 5 (both currently Proof of Residence from Test 1, i.e.
still `labelTouched=false`), applied "Rates & Taxes". After: 1=IDs,
2=Rates & Taxes, 3=Proof of Residence (untouched, not ticked), 4=FICA,
5=Rates & Taxes, 6=Proof of Residence, 7=Mandate, 8-10=Proof of Residence.
1/4/7 read exactly IDs/FICA/Mandate throughout. PASS.

**TEST 3 — same-as-prev still works correctly alongside the fix.**
Page 9 "same as prev" against page 8 (Proof of Residence, `labelTouched=true`
via Test 1's Set ALL) → page 9 copied to Proof of Residence correctly. PASS.

Final state before reload, all 10 pages: 1=IDs/Identity, 2=Rates & Taxes,
3=Proof of Residence, 4=FICA, 5=Rates & Taxes, 6=Proof of Residence,
7=Mandate, 8=Proof of Residence, 9=Proof of Residence, 10=Proof of
Residence.

**TEST 4 — reload persistence, reported honestly.** Reloading the page
reset all 10 pages back to "Other" (this test PDF's auto-detect fallback,
since its pages carry no recognisable real document content). This is
**not a defect introduced or left by this fix** — it is this screen's
pre-existing, unrelated architecture: all doc-type/contact state is pure
client-side Alpine state until the final Link/Download-ZIP submit; a plain
reload has always rebuilt the review screen fresh from the server-seeded
auto-detected manifest, with or without this fix. Recorded here because the
blocker explicitly asked for the reload check and the honest answer is "no,
it doesn't persist, and that's unrelated to what changed today" — not
silently omitted.

### Gates run before push

- `scripts/verify-alpine-render.mjs` against a real authenticated fetch of
  `/tools/pdf-splitter` (the review screen itself requires session state
  built by a prior file-upload POST that a single stateless authenticated
  `curl` cannot replicate, so the static leaked-attribute-text scan was run
  against the reachable page in the same Alpine component tree; the review
  screen's own correctness was instead proven by the live Puppeteer walk
  above, which executes its real Alpine bindings in a real browser — a
  strictly stronger check for this screen than the static scanner alone):
  PASS, 0 leaked-attribute/execution failures (three pre-existing WARN-only
  scope-gap notices, unrelated to this change, left for whoever owns those
  components).
- `scripts/rental-smoke.mjs`, `pdf_splitter_review` screen: PASS, 0 console
  errors, real-data assertion passed.
- `dev-check.ps1` cannot run on this box (no `pwsh` available) — stated
  plainly rather than cited as having run.

### Cleanup

Throwaway test user `qa-cc5-bulkapply-bug-repro@example.invalid` (id 212)
soft-deleted after proof captured. Local `php artisan serve` test instance
stopped.

## AT-410 — "File as…" direct filing, an alternate path INTO this same routing story (2026-09-13, cc5)

Johan, on rental-application 230's review screen: *"this applicant sent
split docs. so I know what they are. dont need to run them through the
splitter. can we give the option right here to file directly as well. so
you keep the splitter but allow selecting document type and click file and
its files it without going via the splitter?"*

This changes the routing story for rental-application documents because
there are now **two entry points that both terminate in the exact same
filed-document shape**, not one:

```
Untyped upload
   ├── Splitter path (unchanged): intakeRentalApplicationDocument() → OCR/
   │   manifest → review UI → linkForRentalApplication() → N typed Document
   │   rows (one per label group), original soft-deleted.
   └── Direct-file path (NEW): RentalApplicationReviewController::
       fileDocumentDirectly() → ONE typed Document row (the whole file,
       unsplit, as ONE type), original soft-deleted.
```

Both paths are reachable from the SAME row of the SAME screen at the SAME
time, on any untyped, owned (not pulled-from-contact) supporting document —
Johan's own requirement: *"THE SPLITTER STAYS, unchanged and equally
available... an agent must be able to choose the splitter on the very same
document if she opens it and finds it is a mixed bundle after all."*
Neither path is aware of the other; there is no shared session state, no
"has this document started down one path" flag — an agent can open the
splitter, back out, and use direct-filing instead (or vice versa) freely,
because nothing is committed until whichever action's own POST succeeds.

**Why direct-filing reproduces linkForRentalApplication()'s exact output
shape rather than a lighter in-place update:** Johan's hard constraint —
*"Filing this way must produce exactly the same result as filing via the
splitter would: same record shape, same storage, same relationships...
indistinguishable afterwards."* The splitter's own single-group case
already does this (copies the source bytes to a new storage path, creates
a brand-new `Document` row, attaches the same `contacts()`/`properties()`
pivots, soft-deletes the original) — direct-filing is that same sequence,
minus the OCR/manifest/page-extraction machinery a single-type file never
needed. See `.ai/specs/rental-applications.md`'s own AT-410 section for
the full build, guards, and proof.

**Scope difference from the splitter's own "Split & File" trigger, by
design:** Split & File stays PDF-only (only a PDF has pages to split).
Direct-filing is offered for ANY mime type an upload can arrive as
(`pdf,jpg,jpeg,png,doc,docx` — `RentalApplicationController::
uploadDocument()`'s own allowlist) — Johan's own example names a payslip
and an ID, and an ID just as often arrives as a photo as a PDF scan. This
is a genuine, deliberate widening of what's fileable on this screen: before
this build, a non-PDF untyped document had no path to being typed at all.

**The live-mark guard is shared code, not a parallel copy.** Extracted
from `linkForRentalApplication()` into
`RentalApplicationDocumentMark::blockingMarksMessageFor(int $documentId): ?string`
— both the splitter's commit action and the new direct-file action call
the same method, so the guard can never drift out of sync between the two
entry points the way two independently-maintained copies eventually would.
