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

# AT-278-follow — Multi-file upload (queue, not merge) (2026-08-11)

## Business requirement

The Splitter accepted exactly one PDF per upload. An agent splitting several
packs back-to-back (e.g. a batch of signed OTPs handed over at once) had to
re-visit `/tools/pdf-splitter` and re-upload for every single file. This
extends the upload to accept multiple PDFs at once; each file is still split,
reviewed, and filed **independently**, one at a time, through the existing
single-file review/confirm/link screens — nothing about the per-file
experience changes. Decision (confirmed with Johan): **queue, not merge** — a
batch of N files becomes N independent manifests processed in sequence, not
one manifest with pages from N documents mixed together. This was the
lower-risk option: it reuses the review/confirm/link pipeline verbatim (no
per-page "which source file" bookkeeping) and matches how these packs
actually arrive — as N unrelated documents, not one long one.

## Pillars

No pillar change. Same targets as the existing splitter (Property, Contact,
Compliance via FICA) — this only changes how many source PDFs one visit to
the tool can carry through that pipeline.

## Design

**Upload (`run()`)** accepts `pdf[]` (array, 1–20 files, `mimes:pdf|max:51200`
each). `base_name` is now optional: blank → each file's own filename
(slugified) becomes its base; supplied → used as a shared prefix
(`{base_name}_1`, `{base_name}_2`, …) when more than one file is posted. Every
file's storage base is additionally namespaced with the uploader's user ID
(`_u{userId}`) so two different agents uploading identically-named files (a
blank Base Name on a file their scanner both happened to call `OTP.pdf`) in
the same second can never collide onto the same storage path/manifest ID and
cross-file each other's documents.

Every file is stored to `private/splitter/originals` immediately (cheap
I/O) and the **entire batch is committed to the session queue before any
OCR runs** — so a corrupt/unreadable file anywhere in the batch, including
the first, never orphans the rest. Activation (OCR + manifest build) then
runs through `popNextQueuedManifest()` — the SAME code path for every file
in the batch, first or last — one at a time: `run()` calls it once to
activate file 1, `continueQueue()` calls it again for every file after.
This keeps one HTTP request bounded to one file's OCR cost regardless of
batch size — a 10-file batch never risks a single request timing out the
way upfront-OCR-everything would.

**Session state**: `splitter_manifest_id` (unchanged — the active manifest),
`splitter_queue` (remaining un-OCR'd items), `splitter_queue_total`,
`splitter_queue_position` (incremented on every dequeue, skipped or not, so
`count(splitter_queue) + splitter_queue_position === splitter_queue_total`
always holds). A file that fails to OCR (0 pages / corrupt) is skipped —
recorded in `splitter_queue_skipped` and rendered as a banner on both the
upload page and the review page — rather than aborting the rest of the batch.

**Progression is explicit, not automatic.** `confirm()` (Download ZIP) and
`link()` (Link to CoreX) keep the same redirect to `index()` and the same
"Download ZIP still lets you Link the same file afterwards" behaviour the
AT-105 enhancement established — the queue never auto-advances underneath
either action. `index()` additionally shows a "Batch upload in progress — N
more files queued, next: {name}" panel with a **Continue to next file**
button (`continueQueue()` — pops + OCRs the next queued item, redirects to
`review()`) and a **Cancel remaining** button (`cancelQueue()` — drops the
queue only; the already-processed file is untouched). The upload form itself
is hidden while a queue is pending, so a fresh upload can never silently
orphan queued files. The review screen shows a small "File X of N" chip plus
its own Cancel-remaining link.

**Stale-tab defense.** The review page renders a hidden `manifest_id` field
(the manifest it was built from) and passes the same ID as a `?manifest=`
query param on every thumbnail request. `confirm()`/`link()` reject (redirect
back to `review()` with an error) if the posted `manifest_id` no longer
matches the session's current manifest; `serveThumb()` `abort(409)`s on the
same mismatch. Without this, an agent who leaves a review tab open, advances
the queue in another tab, then submits (or lazy-loads a thumbnail in) the
stale tab would silently apply/render the WRONG file's labels, contacts, and
property/FICA destination — a real misfiling risk in a compliance system, not
a cosmetic one. `manifest_id` is optional server-side (omitted ⇒ no check),
so the two existing `PdfSplitterDestinationRoutingTest` real-HTTP tests that
POST directly to `confirm()`/`link()` without it are unaffected.

## Robustness (input space)

- 1 file uploaded → `splitter_queue` ends up empty; behaviour is byte-for-byte
  identical to the pre-existing single-file flow (no regression surface).
- Two files with the same/blank original name in one batch → base-name
  collision resolved with a numeric suffix (`_2`, `_3`, …) before OCR runs.
- Two different agents' concurrent uploads with the same blank-name file →
  resolved by the `_u{userId}` storage namespace (see Design), not just
  intra-batch dedup.
- A file that fails page-count/OCR — first in the batch or last — is skipped,
  batch continues; the full remaining queue is already committed to session
  before OCR ever runs, so a first-file failure cannot orphan the rest.
  Skipped files are named in a banner on the upload and review pages.
- Agent starts a **second** fresh upload while a queue is still pending →
  blocked at the UI (upload form hidden behind the batch panel); the prior
  queue is not touched by any other action still on the page.
- A stale review tab (queue advanced elsewhere) submitting Download
  ZIP/Link, or lazy-loading a thumbnail → rejected server-side (manifest ID
  mismatch), never silently applied to the wrong file.
- A double-submitted "Continue to next file" click (queue already drained by
  the first request) → the second call's exhaustion branch leaves
  `splitter_manifest_id` untouched, never wiping the file the first request
  just activated.
- Cancelling the queue never touches the currently-active/already-processed
  file's manifest, outputs, or ZIP — only pending, not-yet-OCR'd items.
- No new scheduled cleanup was added for `private/splitter/*` — matches
  existing behaviour (originals/outputs were never swept before either).

## Files

- EDIT `app/Http/Controllers/Tools/PdfSplitterController.php` (`run()`
  reworked for `pdf[]`, delegates activation to `popNextQueuedManifest()`;
  new `deriveBase()`, `buildManifestForQueueItem()`,
  `popNextQueuedManifest()`, `continueQueue()`, `cancelQueue()`,
  `rejectIfStaleManifest()`; `index()`/`review()` pass queue + skipped-file
  state to the views; `serveThumb()` takes `Request` and checks `?manifest=`;
  `confirm()`/`link()` gain a `rejectIfStaleManifest()` guard as their first
  line, otherwise unchanged.)
- EDIT `routes/web.php` (`tools.pdf_splitter.continue_queue`,
  `tools.pdf_splitter.cancel_queue`)
- EDIT `resources/views/tools/pdf_splitter.blade.php` (multi-file input,
  optional base name, batch-in-progress panel, skipped-files banner)
- EDIT `resources/views/tools/pdf_splitter_review.blade.php` (File X of N
  chip + cancel-remaining, hidden `manifest_id` field, `?manifest=` on thumb
  URLs, skipped-files banner)
