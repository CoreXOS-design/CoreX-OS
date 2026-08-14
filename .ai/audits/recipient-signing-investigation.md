# Recipient signing-surface investigation — root-cause map

Scope: `/sign/{token}` → `App\Http\Controllers\Docuperfect\SigningController::show`
(view returned: **`docuperfect.signatures.external.sign`**, file
`resources/views/docuperfect/signatures/external/sign.blade.php`). Read-only
audit. No files were edited.

Reference documents named in the brief: MDF = Document 472 / template 71 "Sales
Mandatory Disclosure" (blade `docuperfect.web-templates.sales-mandatory-disclosure`)
vs Addendum B = Document 481 / template 120 (seeder `HfcAddendumBEsignSeeder`,
blade `docuperfect.web-templates.cds.template-120`).

## How the signing surface is assembled (context for all bugs)

1. `SigningController::show` (canonical-serve path, lines **269-324**) composes
   `$webTemplateHtml` and passes it to the view (line **561**).
2. The document body is NOT server-printed into the page. It is handed to Alpine
   as component state `webTemplateHtml: @json($webTemplateHtml)`
   (`sign.blade.php:1447`) and injected at runtime via **`x-html`** into the
   `_document-body` host div (`resources/views/docuperfect/shared/_document-body.blade.php:79-82`,
   `x-html="{{ $alpineXHtml }}"` with `alpineXHtml => 'webTemplateHtml'`,
   `sign.blade.php:471-476`).
3. On `init()` the body is then **re-built** by `paginateDocument()`
   (`sign.blade.php:1567-1585` → `resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php:217`),
   which runs inside `$nextTick(() => setTimeout(…, 150))` — i.e. well after the
   page's `DOMContentLoaded` / `alpine:initialized` events. `paginateDocument`
   **moves** the real DOM nodes (detach at `a4-page-styles.blade.php:386`,
   re-append the same node objects at `:465`), so event listeners bound to those
   nodes survive pagination.

This lifecycle — server HTML → `x-html` inject → deferred `paginateDocument()` —
is the backdrop to bugs 1 and 2.

---

## BUG 1 — Recipient "+ Add condition" is dead and per-condition initial slots can't be clicked

### 1a. Server side is CORRECT for recipients (the buttons ARE emitted)

`InsertableBlockRenderer::renderBlockPartialInner` emits the "+ Add condition"
button for the recipient: `$canAdd` includes `CONTEXT_RECIPIENT_SIGNING`
(`InsertableBlockRenderer.php:295-299`) and the button is stamped with the
recipient's own token by `stampConditionSigningToken` (`show()` line **303-304**,
renderer `:55-71`). Per-condition initial slots: `renderInitialSlotsForCondition`
emits a clickable `<button class="btn-add-initial initial-slot initial-active" …
data-signing-token=…>` when `$isMine` (`:600-608`), where

```php
// InsertableBlockRenderer.php:596-598
$isMine = $currentPartyKey !== null
    && strcasecmp($currentPartyKey, $partyKey) === 0
    && $signingToken !== null;
```

`$currentPartyKey` is `$signingRequest->party_role` (passed from `show()` line
**300/417**) and `$partyKey` comes from `$doc->parties_json[].role`. So the
recipient's slot renders as an active button **only if the recipient's
`party_role` string exactly matches a `role` token in `parties_json`.**

### 1b. Client side — the click handlers are one-shot and race the async body build (ROOT CAUSE)

The `.btn-add-condition` and `.btn-add-initial` click handlers are attached by a
plain IIFE inside the add-condition modal partial
(`resources/views/docuperfect/signatures/_partials/add-condition-modal.blade.php:62-152`):

- `attachAddConditionHandlers()` (`:63-78`) and `attachInitialHandlers()`
  (`:122-131`) do `document.querySelectorAll('.btn-add-condition' / '.btn-add-initial')`.
- They are invoked ONLY at page-load lifecycle points: `DOMContentLoaded`/immediate
  (`:133-141`) and `alpine:initialized` (`:142-145`), plus a bespoke
  `phase-1b7-reattach-initial-handlers` event (`:149-151`) that is dispatched in
  exactly one place — after a NEW condition row is appended
  (`add-condition-modal.blade.php:277`) — and re-runs **only** `attachInitialHandlers()`,
  never `attachAddConditionHandlers()`.

The buttons those selectors target do not exist as static HTML — they arrive via
`x-html` and are then relocated by `paginateDocument()` (step 3 above). Handler
attachment is a one-shot keyed on early lifecycle events and is **never re-run
after the document body is injected/paginated**. There is no call to
`attachAddConditionHandlers()` / `attachInitialHandlers()` anywhere in
`sign.blade.php`'s init/paginate flow (grep-verified). The result is exactly the
reported symptom: the "+ Add condition" button and the `.btn-add-initial` slots
render but do nothing.

Why "the agent mostly works": the agent's normal path for ADDING conditions is
the preparation wizard (Step 4/5, `ESignWizardController`), a different surface
whose wiring is unaffected. On the `/sign/{token}` surface the race hits any
role; recipients are simply the ones who need `+ Add condition` / per-condition
initialing there, so they are where it surfaces.

### 1b(ii). Secondary contributor for the initial slots — party token parity

If a recipient's `SignatureRequest.party_role` token does not literally equal a
`parties_json[].role` token (e.g. `seller` vs `owner_party`), `$isMine` is false
and `renderInitialSlotsForCondition` emits the **non-interactive
`.initial-pending` `<div>`** (`:610-616`) instead of the `.btn-add-initial`
button — so there is nothing to click regardless of the JS wiring. This should be
verified against the live `parties_json` for docs 472/481 (memory note
`esign-ceremony-v3-conformance`: parties link by property-link role, not global
contact type).

### Fix (minimal) — BUG 1

Bind the two handlers with **event delegation on `document`** (a single
`document.addEventListener('click', e => { const b = e.target.closest('.btn-add-condition') … })`)
so they survive every `x-html` inject and every `paginateDocument()` rebuild —
replacing the one-shot `querySelectorAll` attachment in
`add-condition-modal.blade.php:62-152`. (Alternatively, dispatch
`phase-1b7-reattach-initial-handlers` AND a new add-condition reattach at the end
of the `paginateDocument()` block in `sign.blade.php:1567-1585` — but delegation
is the robust, rebuild-proof fix.) Separately, confirm `party_role` ↔
`parties_json[].role` parity so `$isMine` resolves for recipients.

---

## BUG 2 — Agent is NOT required to initial the conditions they added before their signature is accepted

### Root cause — condition-initial slots are absent from the required-to-complete set

The submit gate ("N remaining / complete all before submitting") is computed
entirely client-side by two sibling methods in `sign.blade.php`:

- `_computeIncompleteItems()` (`:1842-1926`) — enumerates: unsigned DB markers
  (`:1847`), inline `.web-sig-interactive` signatures (`:1856`),
  `this.webInitialElements` **page** initials (`:1864`), ceremony fields
  (`:1872`), disclosure rows (`:1881`), recipient-editable text fields
  (`:1900`), and consent (`:1909`).
- `_computeWebCounts()` (`:1932-1993`) — the same set, feeding
  `updateIncompleteCount()` (`:1999-2006`, sets `webIncompleteCount` /
  `totalRequired`).

**Neither method ever queries the per-condition initial slots**
(`.condition-initial` / `.btn-add-initial` / `.initial-active[data-condition-id]`).
`this.webInitialElements` is built by `_makeWebInitialsInteractive()`
(`:1775-1814`) exclusively from `container.querySelectorAll('[data-marker-type="initial"]')`
— the page-break initial boxes — which the per-condition slots are NOT (they are
`<button class="btn-add-initial">` / `<span class="condition-initial">`, emitted
by `InsertableBlockRenderer::renderInitialSlotsForCondition`, carrying
`data-condition-id`, no `data-marker-type="initial"`). So condition initials are
invisible to the gate for every signer, agent included.

The server side does not backstop it either: `SigningController::completeWeb`
(`:1418-1495`) enforces only the AT-293 "floor" — consent + at least one
signature/initial + at least one editable field if any exist (`:1475-1495`). It
performs **no check that agent-added `DocumentCondition`s have a matching
`ConditionInitial` from the agent** before accepting the signature and moving the
template toward `STATUS_PENDING_AGENT_APPROVAL` (`:1976-1977`).

Net effect: when the agent adds conditions, `webIncompleteCount` does not include
them, `canSubmitWeb` is true without them, and the agent submits un-initialed
conditions.

### Fix (minimal) — BUG 2

Include the current signer's un-filled, "mine" condition-initial slots in BOTH
`_computeIncompleteItems()` (`:1842`) and `_computeWebCounts()` (`:1932`) — e.g.
count `container.querySelectorAll('.btn-add-initial.initial-active[data-condition-id]')`
that are not yet `.initial-filled` as required items (label "Condition initial").
Belt-and-braces: add a server check in `completeWeb` (`:1418`) that every
non-superseded `DocumentCondition` for the template has a `ConditionInitial` for
`$signingRequest->party_role` before accepting an agent submission (`ConditionInitial`
is already imported at `SigningController.php:7`; the initial endpoint is
`initialCondition` at `:3993`).

### Shared machinery that fixes BUG 1 and BUG 2 together

Both bugs are the per-condition initial slot treated as a second-class citizen.
A single change — make the condition-initial slots FIRST-CLASS interactive items
during `init()`: (a) give them a delegated/re-attachable click handler that POSTs
`initialCondition`, and (b) register the current signer's un-filled ones into the
same required-count pipeline (`_computeIncompleteItems` / `_computeWebCounts` /
`updateIncompleteCount`) exactly as page initials are — fixes the recipient's
"can't click" (1b) and the agent's "not required to initial" (2) in one place.
The cleanest home is a new `_initConditionInitials(container)` invoked from the
`init()` setup block (`sign.blade.php:1567-1585`, alongside
`_makeWebInitialsInteractive`), which both wires clicks and pushes the slots into
the counted set — retiring the fragile IIFE in the modal partial.

---

## BUG 3 — Addendum B (doc 481 / tpl 120) radios not selectable by the recipient, while MDF (doc 472 / tpl 71) radios work

The two templates emit two DIFFERENT disclosure structures, processed by two
DIFFERENT client converters with DIFFERENT editability rules.

### MDF (works) — bare `.corex-table`, radios injected UNCONDITIONALLY

MDF emits a plain table with empty option cells:
`<table class="corex-table"><thead>…<th>YES</th><th>NO</th><th>N/A</th></thead>…<td></td><td></td><td></td>`
(`resources/views/docuperfect/web-templates/sales-mandatory-disclosure.blade.php:55`).
This is handled by `_processDisclosureTable()` (`sign.blade.php:3222-3323`), which
detects the YES/NO(/N-A) headers (`:3243-3249`) and **injects real
`<input type="radio">` with a `change` listener into every option cell
(`:3289-3312`) with NO role/party gate at all.** So on MDF, any recipient viewing
the grid gets clickable radios.

### Addendum B (broken) — `.corex-disclosure-checklist`, radios GATED to the owner/seller party

Addendum B pre-renders a checklist:
`<div class="corex-disclosure-checklist" … data-disclosure-party="owner_party" contenteditable="false"><table class="corex-disclosure-table">…<span class="corex-radio-placeholder" data-item="0" data-value="yes">○</span>…`
(`resources/views/docuperfect/web-templates/cds/template-120.blade.php:31`). This
is handled by `processWebDisclosureChecklists()`
(`resources/views/docuperfect/signatures/partials/disclosure-logic.blade.php:107-161`).
`_processDisclosureTable` explicitly SKIPS these (`sign.blade.php:3240`:
`if (table.classList.contains('corex-disclosure-table') || table.closest('.corex-disclosure-checklist')) return;`).

The checklist converter attaches a click handler to each `.corex-radio-placeholder`
**only when `editable` is true** (`disclosure-logic.blade.php:136-148`); otherwise
the placeholder gets `cursor:default` and no listener (`:149-151`). `editable` is:

```js
// disclosure-logic.blade.php:20-26
_disclosureEditable(disclosureParty) {
    const ownerTerms = ['owner_party','lessor','seller','landlord','owner'];
    const dp = (disclosureParty || 'owner_party').toLowerCase();
    const role = (this._currentSignerRole() || '').toLowerCase();
    return ownerTerms.includes(role) && ownerTerms.includes(dp);
}
```

`_currentSignerRole()` returns `this.signerRole` = `$signingRequest->party_role`
(`sign.blade.php:3006-3008`, `:1491`). So Addendum B radios are clickable ONLY
when the current signer's `party_role` is one of `owner_party / seller / lessor /
landlord / owner` (PPA-s70 "the seller is the sole discloser" — deliberate, see
`disclosure-logic.blade.php:15-38`).

### Why it presents as "recipient can't select"

- The asymmetry is real and structural: **MDF's converter is UNGATED (every
  recipient can click), Addendum B's converter is GATED to the owner/seller
  party.** For any recipient that is NOT an owner-term role — a buyer
  (`acquiring_party`), a co-signer, or the agent — Addendum B is correctly
  read-only while MDF is (arguably wrongly) editable. That is the exact
  "MDF works, Addendum B doesn't" report if testing as the buyer/co-signer.
- If the recipient under test IS the disclosing seller, Addendum B's party_role
  should be `owner_party` (seeder `signing_parties => ['owner_party',
  'acquiring_party', 'agent']`, `HfcAddendumBEsignSeeder.php:44`), which IS in
  `ownerTerms`, so the seller should get clickable radios. If a seller still
  cannot click, the determinant is a `party_role` token that is not in the
  ownerTerms whitelist (verify the actual `SignatureRequest.party_role` bound for
  the Addendum B seller in the pack).

### The prompt's `data-viewer-editable` hypothesis is a red herring for the radios

The radios are `.corex-radio-placeholder` spans, NOT `.field-editable` inputs, so
they are never touched by `RoleBlockExpansionService::applyViewerEditabilityOverlay`
(which stamps `data-viewer-editable="1"` on `data-field` elements,
`RoleBlockExpansionService.php:641`/`:874`) nor by `getEditableFieldsFromMappings`
(`SigningController.php:1792-1830`) / `WebTemplateFieldPartyMap`. Radio
editability is governed SOLELY by `processWebDisclosureChecklists` +
`_disclosureEditable`. Separately note that Addendum B seeds
`field_mappings => json_encode([])` (`HfcAddendumBEsignSeeder.php:45`) and has no
`insertable_blocks`, so `getEditableFieldsFromMappings` returns `[]` and its
`coc_*_when` date spans (`.corex-field-value[data-field]`,
`template-120.blade.php:31`) are never converted to editable inputs either — a
genuine secondary gap: even the disclosing seller can tick YES on a CoC but
cannot enter the "when issued" date.

### Fix (minimal) — BUG 3

Decide the intended editor and make the two converters consistent:
- If the disclosing party is meant to fill Addendum B, verify the seller's bound
  `party_role` is an ownerTerm; the gate at `disclosure-logic.blade.php:20-26`
  then already permits it. If a non-`owner_party` token is legitimately used,
  extend `ownerTerms` or map it, in ONE place (`_disclosureEditable`).
- The real inconsistency to resolve is that MDF's `_processDisclosureTable`
  (`sign.blade.php:3222`) applies **no** party gate while the checklist path does.
  Either gate the bare-table path with the same `_disclosureEditable(...)` check
  (so both honour PPA-s70), or confirm both should be owner-only — but they must
  not diverge.
- Also convert Addendum B's `coc_*_when` date fields to editable inputs for the
  disclosing party (they are currently inert because `field_mappings` is empty).

---

## Note on "four bugs"

The brief says "four things are broken" but enumerates three items. Item 1 is two
distinct failures — (1a) the dead "+ Add condition" button and (1b) the
un-clickable per-condition initial slots — which is the natural fourth. Both are
covered above under BUG 1.
