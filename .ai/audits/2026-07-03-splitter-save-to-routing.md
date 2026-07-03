# PDF Splitter — Save-to routing investigation (AT-167)

> READ-ONLY investigation. No code changed, no data moved. Johan approves any fix/data-move.
> Live example: IDs doc-type is Save-to=Contact only, yet ID docs appear under the PROPERTY's
> Document Folders (property 6049 `…ids__unassigned__g8.pdf`; property 5794 g4). Verified against
> `origin/Staging` + live `nexus_os`. File:line anchors are on `PdfSplitterController.php`.

## Verdict (one line)
The settings screen is **faithful** — Save-to IS honoured. The mis-file is a **no-contact fallback**:
a split page of a **contact-only** doc-type (ids/fica/por) that is filed with **no contact assigned**
(`__unassigned__`) has no valid destination, so the code files it to the **property** rather than
orphan it. That fallback is what puts an ID under the property despite Save-to=Contact.

## (a) What Save-to controls at assignment time
Filing happens in `PdfSplitterController::fileGroupsToDestinations()` (`:714`). Per output group:
- `destinationForSlug($agencyId, $slug)` → `{property: bool, contact: bool}` — the **per-agency**
  Save-to (an override merged over the grouping default in `AgencyComplianceDocTypeService`;
  grouping `contact` → contact-only default, everything else → property).
- Then (`:760-783`):
  ```php
  if ($dest['property'])                          { attach to property; didAttach = true; }
  if ($dest['contact'] && ! empty($contact_ids))  { attach to each assigned contact; didAttach = true; }
  if (! $didAttach)                               { attach to property; result['fallback']++; }   // ← the bug path
  ```
  So Save-to genuinely gates property-vs-contact attachment. It is **not** cosmetic.

## (b) The `unassigned` — where it routes, and the bug
`__unassigned__` in the filename means the page group had **no contact ticked** (`empty($contact_ids)`,
set at `:559`). For a **contact-only** doc-type (`dest = {property:false, contact:true}`) with no
contact assigned:
- the `dest['property']` branch is skipped (property=false),
- the `dest['contact']` branch is skipped (`! empty($contact_ids)` is false — no contact),
- → `$didAttach` stays false → the **fallback attaches the doc to the property** (`:780-783`).

**This is the mechanism.** It is a deliberate "don't orphan the document" fallback (better filed
somewhere than nowhere), and the UI *does* disclose it — the success banner says
`"N to the property (no contact assigned)"` (`:597`). But it defeats the Save-to=Contact intent and
reads to the agent as "Save-to is ignored." The upstream trigger is that the **ID page was filed with
no contact assigned** (agent didn't tick a contact, or auto-sticky-assign found none).

## (c) Does the settings screen display the stored config faithfully?
**Yes.** The screen renders the same `destinationMap`/`routingMap` (`destinationForSlug`) that the
filing path uses — IDs correctly shows Save-to=Contact, and filing DOES honour it when a contact is
assigned. There is **no UI-vs-stored discrepancy**. → This **de-risks AT-166**: the redesign can
reproduce the current display faithfully; the problem is filing behaviour, not display.

## (d) Affected files on live (`nexus_os`, non-deleted, `source_type='pdf_splitter'`, name `%unassigned%`)
- **497** total `unassigned` splitter files attached to a property.
- Of those, the **contact-only** doc-types (which should route to a contact, not the property):
  **fica 49 + ids 48 + por 20 = 117** files filed to a property via the fallback — the genuine
  mis-files (for the default / HFC config where these are contact-only; an agency that overrode one
  of these to also save-to-property would be correct).
- The other **380** are property/shared-grouped doc-types (mandate/disclosure/rates_taxes/levy/…),
  for which the property IS the destination — their `unassigned` is expected and correct.
- Johan's examples confirmed: property 5794 g4 = `ids`; property 6049 carries `ids` + `fica`.

## Proposed fix (Johan approves before any build/data move)
**Prevent at source (recommended, per BUILD_STANDARD prevent-or-absorb).** At "Link to CoreX", if a
page's doc-type is contact-only (`dest.property=false, dest.contact=true`) and it has **no contact
assigned**, do NOT silently fall back to the property — instead **block that page with a clear
per-page message** ("This ID page is set to file to the contact — assign a contact, or change its
type / Save-to"). This stops the mis-file where it starts and matches the agent's mental model.
Optionally soften to **absorb**: still fall back, but tag the doc (`filed_as_fallback`) and show it in
a "needs a contact" list for one-click re-assignment.

**Existing 117 files — do NOT auto-move.** Re-filing requires knowing *which contact* each ID belongs
to — a human judgement the system can't infer (the page was filed with no contact precisely because
none was chosen). Recommend a **surfaced re-assignment list** (the 117 files, per property) so an
agent re-assigns each to the correct contact; the move then detaches from the property (if the
doc-type is contact-only) and attaches to the chosen contact. No blind data migration.

## Relationship to AT-166 (settings redesign)
Precondition answered: the screen displays config faithfully, so the AT-166 rebuild can safely
reproduce the Save-to / routing / eligibility display. The fallback behaviour is a **separate**
filing fix (this ticket), not a settings-screen fix.
