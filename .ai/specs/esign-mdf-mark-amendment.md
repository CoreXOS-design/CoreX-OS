# E-Sign — MDF mark lock + mark-level amendment / counter-initial flow

> AT-303 (Johan approved, 2026-07-29). Legally-sensitive. Extends the existing
> amendment engine (AT-302 `amendment-review-v2.md`, ES-3 initialing cascade) to
> cover **MDF disclosure marks (radio ticks)** — which today no amendment path
> touches. Single source of truth for: lock-on-sign → downstream read-only →
> strike-through mark amendment + amender initial → route-back counter-initial →
> all-parties-consent completion gate.

## 0. The bug (root cause, confirmed in code)

The MDF = **Mandatory Disclosure Form** (`template-117` / the `#119`/`#123` checklist
structure). Its radio ticks ("marks") are **not** signature records — there is no
`checkbox`/`radio` marker type (`signature_markers.type` ∈ `signature|initial|date|text`).

Marks live as **shared, document-scoped state**:

- Client keeps `webDisclosureAnswers[key]`, keyed `disclosure_<docKey>_<ordinal>`
  (`resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php:555-617`,
  `window.CoreXDisclosure.keyForRow`). The key carries **no `data-recipient-identity`** —
  it is one key per question for the whole document.
- Posted on `complete-web` and merged into
  `docuperfect_documents.web_template_data['disclosure_answers']`
  (`SigningController::completeWeb` ~`:1500-1507`), then re-baked into
  `merged_html` / `canonical_html`.

Editing is gated by **role class**, not identity: `_disclosureEditable()`
(`disclosure-logic.blade.php:20-26`) allows the grid to any `owner_party`
(`owner_party|lessor|seller|landlord|owner`). So:

- A **buyer** 2nd recipient already cannot edit the grid (owner-only) — NOT the bug.
- **Two owner-party recipients (e.g. 2 co-sellers)** BOTH pass the check. There is
  **no lock** once the first signs: seller 2 opens the ceremony, sees seller 1's
  answers restored (`restoreStoredDisclosure`), can re-tick any mark, and on
  `complete-web` the `array_merge` **overwrites** seller 1's answer and re-bakes the
  document under seller 1's already-applied signature. **Seller 1's agreement is
  silently voided.** This is the confirmed bug.

Corroborating gaps (agent findings, for the spec's design):
- `SignatureService::detectAmendment` only diffs `other_conditions_text`
  (`:3294-3304`). **Nothing** promotes a changed disclosure answer into an amendment.
- `SignatureService::isFullyComplete` (`:1136`) — the real completion gate — checks
  only "no non-terminal requests remain". It does **not** verify any amendment was
  counter-initialled.
- `checkInitialingCascadeComplete` (`SigningController:4183-4208`) keys required
  initials on **`party_key` (party_role)** — so two joint sellers collapse to ONE
  required initial. For a legally-sound counter-initial this must key on
  **`signature_request_id` (identity)**.

## 1. Pillars

Deal + Contact + Property (the MDF is a property/mandate document signed by owner
Contacts, routed through the Deal's signing ceremony). No new pillar.

## 2. What we build (3 stages, each its own commit, READY-FOR-QA1)

### Stage 1 — Lock on sign + downstream read-only (the security fix)

**Server (authoritative):**
- On owner-party completion in `completeWeb`, after persisting this signer's
  `disclosure_answers`, write a lock into `web_template_data['disclosure_lock']`:
  `{ locked: true, request_id, role_identity, signer_name, locked_at, answers: {key:value} }`
  (the frozen snapshot of exactly what this signer signed).
- **Enforcement:** before merging incoming `disclosure_answers`, if a lock exists and
  it was set by a **different** request, and any incoming key's value **differs** from
  the locked snapshot → reject the completion `422` with a clear message
  (`"The disclosure answers were locked when {name} signed. To change an answer you
  must propose an amendment."`). Identical values (a genuine agree) pass untouched.
  Audit-log `disclosure_lock_write_denied` with the offending key(s).
- The lock is set by the FIRST owner-party signer and is not re-set by later signers
  (later owner signers who merely agree do not mutate it).

**UI (read-only downstream):**
- `show()` computes `$disclosureMarksLocked` = a lock exists AND the current request is
  not the locking request. Pass into `externalSign()` as `disclosureMarksLocked`.
- `_disclosureEditable()` returns **false** when `this.disclosureMarksLocked` — the
  downstream owner recipient sees the grid read-only (reusing the existing read-only
  render path already used for agent/buyer), with a small "Locked by {name} on
  {date} — propose a change" affordance (the amend entry point wired in Stage 2).

**Verify:** on a real 2-seller MDF on qa1 — seller 1 ticks + signs; seller 2's grid is
read-only; a crafted `complete-web` from seller 2 changing a tick is rejected `422`;
seller 1's baked answers are unchanged.

### Stage 2 — Mark-level amendment: strike-through + amender initial

- New `DocumentAmendment` of `amendment_type = TYPE_MODIFICATION`, with
  `section_reference = 'Disclosure'`, `flag_clause_ref = <disclosure_key>`,
  `original_text = "<statement>: YES"`, `new_text = "<statement>: NO"`,
  `flag_origin = FLAG_ORIGIN_SIGNING_PARTY`, `status = STATUS_PENDING`. (No new enum —
  a mark change is a `modification`; we reuse the type. New nothing on the schema.)
- Downstream recipient, on the read-only grid, clicks a mark → "Propose change"
  affordance: pick the new value, then **draw/type their initial** via the existing
  `showWebSigCapture` modal (`sign.blade.php:1343-1403` / `applyWebSignature()`).
  New endpoint `POST /sign/{token}/disclosure/{key}/amend` →
  `SigningController::proposeDisclosureAmendment`: creates the amendment + the
  amender's own `AmendmentAcceptance` (accepted=true, `initial_image` = their initial),
  strikes the original mark visibly in the baked HTML (`<span class="corex-mark-struck">`
  old + `<span class="corex-mark-new">` new + the amender's initial glyph beside it),
  bumps `canonical_version`, snapshots pre-change HTML into
  `web_template_data['amendment_snapshots'][]`. Reuses the `<del>/<ins>` tracked-change
  convention from AT-302.

**Verify:** seller 2 strikes a mark, draws initial; the doc shows the struck original +
new value + seller 2's initial; an amendment row + seller 2's acceptance row exist.

### Stage 3 — Route-back counter-initial + all-parties-consent completion gate

- On a mark amendment, route the document back to **every earlier signer whose signature
  now covers a changed mark** — reusing `requeueAllPartiesForInitialing` BUT keyed on
  **`signature_request_id`, not `party_role`** (fix the co-owner collapse). Template →
  `STATUS_AMENDMENT_INITIALING`; each earlier owner request re-opened with a fresh token;
  one `amendment_acceptances` row per earlier request.
- Earlier party counter-initials on the existing **drawn-pad** view
  (`external/amendment-review.blade.php` → `POST /sign/{token}/amendment/{id}/accept`
  with `initial_image`). Their prior signature stays valid (initial-only, not re-sign).
- **Completion gate (new, authoritative):** extend `isFullyComplete` (or gate
  `completeDocument`) to also require: every `DocumentAmendment` for the template with
  `status ∈ {pending, accepted}` has an `amendment_acceptances` row with `accepted=true`
  **for every affected `signature_request_id`** (identity-keyed). The doc cannot complete
  until every affected party has counter-initialled every mark amendment.

**Verify:** seller 2 amends → doc routes back to seller 1 → seller 1 counter-initials on
the drawn pad → only then can the doc complete; with the counter-initial outstanding,
completion is refused.

## 3. Data model

**No new tables.** Reuse: `document_amendments` (`modification` type),
`amendment_acceptances` (identity-keyed counter-initial + `initial_image`),
`web_template_data['disclosure_lock' | 'amendment_snapshots']` (JSON, no migration).
The only schema-adjacent change considered — a dedicated `initialed_at` on
`amendment_acceptances` — is **deferred**; `updated_at` suffices for the audit line.

## 4. Files (create / modify) — e-sign only

- `app/Http/Controllers/Docuperfect/SigningController.php` — lock write + enforcement in
  `completeWeb`; `show()` lock flag; Stage 2 `proposeDisclosureAmendment`; Stage 3
  routing call.
- `app/Services/Docuperfect/SignatureService.php` — identity-keyed requeue + the
  completion-gate helper (`allMarkAmendmentsCounterInitialled`).
- `resources/views/docuperfect/signatures/partials/disclosure-logic.blade.php` —
  `_disclosureEditable` honours `disclosureMarksLocked`; Stage 2 amend affordance.
- `resources/views/docuperfect/signatures/external/sign.blade.php` — pass the lock flag;
  wire the amend affordance to `showWebSigCapture`.
- `routes/web.php` — `sign/{token}/disclosure/{key}/amend` (named, external group).
- Tests under `tests/Feature/Docuperfect/SigningView/` (pipeline gate requires a test
  diff for `SigningController.php`).

## 5. Permissions / nav

No new nav (rides the existing ceremony + amendment surfaces). No new permission key —
counter-initialing is authorised by ownership of a valid signing token, same as the
existing amendment cascade.

## 6. Acceptance (full loop, deployed qa1)

2-seller MDF: seller 1 ticks + signs (marks LOCK) → seller 2 sees read-only grid →
seller 2 strikes a mark, draws initial (mark amendment) → doc routes back to seller 1 →
seller 1 counter-initials on the drawn pad → doc completes; every step versioned +
audit-logged; the final PDF shows the struck original + new mark + both parties' initials.
Completion is refused while any mark amendment lacks a per-party counter-initial.

## 7. Deliberately flagged for Johan (product calls, NOT guessed)

1. **Scope of the lock — marks only, or marks + owner-editable text fields?** Johan's
   brief says "marks (radio ticks)". Stage 1 locks the disclosure marks only. Owner-
   editable `field_values` on the MDF are ALSO shared/overwritable — same class of bug —
   but out of the stated scope. Recommend a follow-up ticket; not built here without a go.
2. **Reject-a-mark-amendment path.** AT-302 gives the agent Reject-change / Reject-doc.
   For a mark amendment proposed by seller 2, if seller 1 *disagrees* with the change on
   counter-initial, is that a rejection back to seller 2, or an agent escalation? Reusing
   the existing reject path (`rejectAmendment`) is the default; confirm the desired
   terminal behaviour.
