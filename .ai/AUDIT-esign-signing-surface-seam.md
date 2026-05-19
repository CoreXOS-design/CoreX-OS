# AUDIT — E-Sign Signing-Surface Seam (spec vs code vs process)

> Status: **REVIEW ARTIFACT — NOT YET APPROVED.** No code written. The spec
> amendment in §5 becomes the contract ONLY after Johan signs off.
> Produced: 2026-05-19. Branch: HFC2402. Audited against
> `.ai/specs/claude_esignature_v2_spec.md` (State of Reality, 2026-03-27).

---

## 0. The seam in one sentence

"Which signature/initial surfaces exist, and which recipient each is keyed
to" is independently re-derived at **four** sites that do not agree, and the
spec never states an invariant that would force them to agree.

---

## 1–3. Three-column audit

### Item A — Single authoritative surface resolver (the core invariant)

| Column | Finding |
|---|---|
| **WHAT THE SPEC SAYS** | §6 (lines 207–230) lists "Four Systems (Historical)" for producing signature elements and says they "must align to the same coordinate system" — a *geometry* invariant, never a *party-identity* invariant. **The spec is SILENT on a single authoritative resolver for surface→recipient keying.** §14 WP1 (line 390) is the only acknowledgement of the keying failure, scoped to **packs** and logged as *resolved*, not generalised. §19.3 (lines 511–516) is the closest: it requires single (non-pack) documents to "derive initial party keys from the SAME canonical recipient source" as `normalizePackMarkerParties()` — but only for **initials**, and it names a canonical source that has **no standalone implementation**. |
| **WHAT THE CODE DOES** | No resolver exists. Surface identity is whatever the HTML-producing site happened to bake. `normalizePackMarkerParties()` ([ESignWizardController.php:1617,2329](app/Http/Controllers/Docuperfect/ESignWizardController.php#L1617)) re-keys to canonical recipient roles **for packs only**. Standalone documents get no equivalent pass. |
| **VERDICT** | **SPEC-GAP** for signatures and for the resolver itself. **CODE-WRONG vs §19.3** for initials: the spec explicitly requires a canonical recipient source for single docs; the code provides none. |

### Item B — Site 1: CDS compile (`TemplateController::compileCdsToBlade`)

| Column | Finding |
|---|---|
| **SPEC** | §3 (lines 100–113) states "Signature Block Parties" (`agency_signing_parties` / per-tag ticks) and "Document Signing Roles" (`templates.signing_parties`) are "NOT duplicates" — but **never defines which is authoritative for the compiled surface party set**, nor that `cds_json.sections[].parties` and `field_mappings[tagId].parties` must reconcile. §6 says "CDS compiler … generates the static signature sections". |
| **CODE** | Two compile paths produce different keys. Inline-section path: render `cds_json` → regex-extract `data-marker-party` ([:749-767](app/Http/Controllers/Docuperfect/TemplateController.php#L749)) — reads **`cds_json.sections[].parties`**. Tagged-html path: `extractPartiesFromTagSpan` ([:959-985](app/Http/Controllers/Docuperfect/TemplateController.php#L959), resolveMap `owner_party→seller`, `acquiring_party→buyer` at :970-975) — reads **`field_mappings[tagId].parties`**. Terminal block ([:844-862](app/Http/Controllers/Docuperfect/TemplateController.php#L844)): a **name heuristic** (`'authority'\|'mandate'` → `['Seller','Agent']` else `['Seller','Buyer','Agent']`) or `Template::mapSigningPartyKeys($signingParties)`. Compile runs only on template save (`file_put_contents` [:874](app/Http/Controllers/Docuperfect/TemplateController.php#L874)) — never at signing. Produced key: an unresolved literal from whichever store that path reads. |
| **VERDICT** | **SPEC-GAP** — the spec never ruled which store is authoritative or that they must agree, and never ruled out compile-time name heuristics for legal party identity. |

### Item C — Site 2: `prepareSigning` snapshot (`ESignWizardController`)

| Column | Finding |
|---|---|
| **SPEC** | §5 (lines 178–188) describes "Document renders with signatures applied" but specifies no party-keying rule. §19.3 requires canonical recipient keys (initials, both pack and single). WP1 (§14) records the pack snapshot was fixed via `normalizePackMarkerParties` + per-wrapper `SignatureSurfaceNormalizer`. |
| **CODE** | Single-doc path ([:1656-1731](app/Http/Controllers/Docuperfect/ESignWizardController.php#L1656)): sets `signing_parties`, `document_context`, `party_names`, `recipients_by_role`, renders `view($blade_view,$viewData)`, stores `web_template_data['merged_html']`. Surface key comes from `signature-block.blade.php` (`markerKey` from `recipients_by_role` role alias, e.g. `seller`/`agent`) and from `signature-line.blade.php` (`$lookupKey` = the include's **hardcoded** `['party'=>…]`). Pack path ([:1500-1620](app/Http/Controllers/Docuperfect/ESignWizardController.php#L1500)) additionally runs `normalizePackMarkerParties` ([:1617](app/Http/Controllers/Docuperfect/ESignWizardController.php#L1617)). **A standalone inline sig surface can only ever carry the party the compiled blade hardcoded — `recipients_by_role` cannot inject a line the blade does not `@include`.** |
| **VERDICT** | **SPEC-GAP** — no spec rule that the snapshot must reconcile the compiled blade's hardcoded inline parties against the actual recipients. |

### Item D — Site 3: live-fallback render (`SigningController::show`)

| Column | Finding |
|---|---|
| **SPEC** | None. The spec never describes the `merged_html`-absent fallback branch or any parity requirement between it and the `prepareSigning` snapshot. |
| **CODE** | [SigningController.php:226-244](app/Http/Controllers/Docuperfect/SigningController.php#L226): if `web_template_data['merged_html']` present → serve the Site-2 snapshot; else live `view($blade_view,$viewData)->render()` with **only `signing_parties` injected** ([:230-231](app/Http/Controllers/Docuperfect/SigningController.php#L230)) — **no `document_context`, no `recipients_by_role`, no `party_names`**. Consequence in `signature-block.blade.php:15-25`: `$isSales=false` → rental keyMap → `owner_party→Lessor`, `markerKey='lessor'` (else-branch, no recipient data). Then `SignatureSurfaceNormalizer::normalize` ([:263](app/Http/Controllers/Docuperfect/SigningController.php#L263)) — **additive only** (adds `data-marker-type`; never re-keys party, never inserts a missing block). Produced key: `signing_parties` mapped *without* sales context and *without* recipients. |
| **VERDICT** | **SPEC-GAP** — render-context parity between the two serve paths is unspecified. |

### Item E — Site 4: runtime fuzzy scan (`isMyWebSigBlock`)

| Column | Finding |
|---|---|
| **SPEC** | None. The spec never defines the surface↔signer match contract. It is a compensating mechanism the spec does not mention. |
| **CODE** | [external/sign.blade.php:2538-2561](resources/views/docuperfect/signatures/external/sign.blade.php#L2538): compares element `data-marker-party` vs `this.signerRole` (= `signature_requests.party_role`, [SigningController.php:339](app/Http/Controllers/Docuperfect/SigningController.php#L339)). Exact match OR alias-group: `ownerTerms=[owner_party,lessor,seller,landlord,owner]`, `acquiringTerms=[acquiring_party,lessee,buyer,tenant,purchaser]`, `agentTerms=[agent,property_practitioner]`. It papers over Site 1–3 disagreement **only when a surface element exists at all** — it cannot create a missing one. |
| **VERDICT** | **SPEC-GAP** — the fuzzy contract exists solely because upstream keying is unreliable; nothing in the spec authorises or constrains it. |

### Item F — Disclosure grid (tonight's gate/key/double-count bugs)

| Column | Finding |
|---|---|
| **SPEC** | §16 (line 425) explicitly **parks** "Registered radio/option CDS field type … the correct root-cause fix" (Option 1) and records the demo uses Option 2 (`.corex-disclosure-checklist` markup + the client converter). The spec therefore *acknowledges* the grid is not a resolver-owned field and that the proper fix is deferred. |
| **CODE** | The grid is not an owned CDS field; client-side converters (`processWebDisclosureChecklists` in the shared partial; legacy `_processDisclosureTable` in external) each independently derive rows/keys → the double-count and gate-key bugs fixed in commits `260dfea`/`f3a052a`. |
| **VERDICT** | **SPEC-GAP — acknowledged.** Same seam: identity of a signable element derived by improvising code, not an owned resolver. §16 already concedes the structural fix is parked. |

### Where the 4 sites disagree (plain statement)

For #119's SIG 1, the four sites produce, respectively: **Site 1** = `agent`
(from `cds_json.sections[2].parties`) *or* `[agent,buyer,seller]` (from
`field_mappings[tag].parties`) — the two stores disagree **inside one
record**; **Site 2** = recipient-aliased keys (`seller`,`agent`) but only for
surfaces the blade physically `@include`s; **Site 3** = `signing_parties`
mapped with no sales context and no recipients (`lessor`-style keys);
**Site 4** = fuzzy match against `signature_requests.party_role`. The party
identity of any surface depends on *which site rendered the HTML the signer
sees*, and Site 4 only rescues a mismatch when the surface exists at all.

---

## 4. The two flagged data points — CONFIRMED

**4a. `docuperfect_templates.signing_parties` for #119**
`["owner_party","agent"]` (template "SALES ADDENDUM B", `template_type=cds`,
`render_type=web`, `isSalesDocument()=true`).

→ **The terminal signature-block does NOT drop Seller.** `owner_party` is
present, so `signature-block.blade.php:15-25` emits a Seller cell (sales
context → "Seller"; even a rental-context fallback → "Lessor", still in
`ownerTerms`, still matchable). **The seller loss is at the inline SIG 1, not
the terminal block.** (Buyer is absent from `signing_parties`, consistent
with "no buyer on a mandate" — the SIG-1 "Buyer" tick is orphan template
config, never a recipient.)

**4b. #119 SIG 1 tag mapping — builder-save vs compile-read divergence**
CONFIRMED, and it is **in-record dual-source**, worse than "builder vs
compiler":

- `field_mappings['tag-mn3c3z0e-l9mr40'].parties = ["Agent","Buyer","Seller"]`
  — what the CDS builder UI writes/shows (matches "Agent, Seller, Buyer
  ticked").
- `cds_json.sections[2] = {type:"signature_section", parties:[{role:"agent"}]}`
  — agent only — the store the inline-section compile path reads.
- `template.updated_at = 2026-03-23 17:23:38`; on-disk
  `template-119.blade.php` mtime `2026-05-19 09:53:00` (commit `5618392`,
  the hand re-author of Addendum B Part B). The blade is **hand-authored,
  not compiler-fresh**, and line 17 is `signature-line ['party'=>'agent']`.

→ The persisted intent (3 parties in `field_mappings`) is not reflected in
the persisted compile source (`cds_json.sections[].parties` = agent) nor in
the hand-edited on-disk blade (agent). One template row carries two
disagreeing surface-party stores that nothing reconciles, and signing never
re-compiles. This is the seam at the data layer.

---

## 5. PROPOSED SPEC AMENDMENT (draft — for Johan's sign-off)

> Drafted as a new spec section. Not yet inserted into
> `claude_esignature_v2_spec.md`. On approval it becomes the contract the
> fix is built to.

### §20 Single Authoritative Signing-Surface Resolver (INVARIANT)

**§20.1 Principle.** A document's signing surfaces (every
`[data-marker-type]` signature/initial element) and their party identity are
resolved **once**, at `prepareSigning`, from the **actual
`signature_requests` recipients of that flow** — the single authoritative
party set. No other site may derive, infer, or re-key surface identity.

**§20.2 Canonical recipient key.** Each recipient has one canonical role key
(`owner_party` | `acquiring_party` | `agent` | `witness` | `supervisor`,
plus `_n` suffix for multiples). The resolver stamps every signature/initial
element with the canonical key of the recipient it belongs to. This is the
**standalone generalisation of `normalizePackMarkerParties()`**: it runs for
*every* document (single and pack), over the already-paginated DOM, before
`merged_html` is persisted.

**§20.3 `merged_html` is the sole signed-surface source of truth.** The
resolver's output is the persisted `merged_html`. The signing views consume
it verbatim. There is no second "live render" that derives surfaces
differently.

**§20.4 Recipient-gated surfaces.** A surface exists in the signed document
**iff** there is a recipient for its canonical role. A template party tick
with no corresponding recipient produces **no** surface (kills orphan
"Buyer"-on-a-mandate cells). A recipient with no compiled surface for its
role is a **resolver error**, surfaced at `prepareSigning`, not silently
dropped (this is the #119 seller case).

**§20.5 Match contract.** "Is this my surface" is **exact canonical-key
equality** between the element's stamped key and the signer's recipient
canonical key. Fuzzy alias-group matching is forbidden.

**§20.6 What becomes redundant on adoption** (must be deleted, not left as
dead compensators):
- CDS-compile party derivation for the *signed* artifact — the name
  heuristic ([TemplateController.php:844-859](app/Http/Controllers/Docuperfect/TemplateController.php#L844)) and the
  two-store ambiguity (`cds_json.sections[].parties` vs
  `field_mappings[tagId].parties`). The compiler may still lay out
  *placeholder* surfaces, but the resolver, not the compiler, assigns party
  identity.
- The live-fallback branch's context gap
  ([SigningController.php:229-244](app/Http/Controllers/Docuperfect/SigningController.php#L229)) — the fallback
  either runs the same resolver or is removed; `merged_html` is always
  resolver-produced.
- The fuzzy `isMyWebSigBlock` alias groups
  ([external/sign.blade.php:2545-2560](resources/views/docuperfect/signatures/external/sign.blade.php#L2545)) and the agent-view
  equivalent — reduced to exact canonical-key equality (§20.5).
- `SignatureSurfaceNormalizer` as a *re-keying* device — its only remaining
  legitimate job (adding a missing `data-marker-type` to a laid-out
  placeholder) folds into the resolver.

**§20.7 Disclosure corollary (ties to §16).** A mandatory-disclosure grid is
a resolver-owned surface like any signature/initial — it is one owned field
type with one converter, counted once. This is §16 Option 1 promoted from
"parked" to "the contract": no client-side improvisation derives disclosure
rows or keys.

**§20.8 Acceptance criteria.**
1. For any document, every signature/initial element in persisted
   `merged_html` carries a canonical recipient key; no element carries a
   heuristic/display/`lessor`-style key.
2. Every recipient of the flow has exactly the surfaces their role requires;
   every surface maps to a recipient; zero orphan surfaces.
3. #119 seller: the seller recipient gets a SIG-1 surface (resolver errors
   loudly at `prepareSigning` if the laid-out template offers none).
4. Single-doc and pack-segment surfaces are byte-keyed identically (the
   §19.3 requirement, now satisfied for signatures too).
5. `isMyWebSigBlock` (both views) is exact-match only; alias groups deleted;
   no surface is gained or lost by removing them.
6. Disclosure grid is one owned, resolver-counted surface (no double-count
   path can exist).

**§20.9 Process rule.** Per §18: this invariant is approved before the fix
is built; the fix is built to §20, not to the symptom list; the four sites
are reconciled to one resolver in a single structural change, not N patches.

**§20.10 AMENDMENT — surfaces are placed PER RECIPIENT by the existing
render-time component loop (APPROVED, implemented).** Investigation confirmed
CoreX's render-time per-recipient loop
(`signature-block.blade.php:45-75`, `signature-line.blade.php:21-31`) ALREADY
places N blocks for N same-role recipients — it works for exclusive web
docs. It did not fire for #119 because `recipients_by_role` was keyed by the
GENERIC role (`owner_party`) at `ESignWizardController.php:1672-1677` while
the component loop looks up the CONCRETE role (`seller`/`landlord`) → lookup
miss → `else`-branch collapsed N sellers into ONE cell. The contradictory
"one canonical recipient role key" vs "one surface per recipient" clause is
resolved as follows:

- The single consistent path is: at `prepareSigning`, resolve each
  recipient's generic role to the CONCRETE key the component derives from
  `signing_parties`+`document_context` (sales: `owner_party`→`seller`,
  `acquiring_party`→`buyer`; rental: `landlord`/`tenant`) BEFORE building
  `recipients_by_role`. The EXISTING component loop then emits one block per
  recipient keyed `seller`, `seller_2`, … — byte-identical to
  `signature_requests.party_role` (`:1837-1844`) and to
  `normalizePackMarkerParties`/`buildCanonicalRecipients`. The component
  loop is NOT modified — it was always correct; it was starved of the right
  key.
- #119's hand-authored inline (`template-119.blade.php:17`,
  static `signature-line ['party'=>'agent']`) is re-authored (non-
  destructive, Approach A — NOT a CDS recompile, which would destroy the
  approved Part B disclosure grid) to the looping
  `signature-line ['party'=>'seller']` + `['party'=>'agent']` so the inline
  SIG rejoins the same recipient loop.
- The `SigningSurfaceResolver` (§20.1–20.6) is DEMOTED — not deleted — to:
  (i) a re-key guard for legacy/mismatched markers, and (ii) a fail-safe
  inject only if a recipient STILL has no surface. It is idempotent against
  the loop's output: verified it does NOT inject when the loop already
  emitted correctly-keyed `seller`/`seller_2` surfaces. It is no longer the
  placement mechanism.

`isMyWebSigBlock`'s exact-suffix rule is retained — correct given consistent
`_n` keying end-to-end.

**§20.11 RATIFIED RULE — no static single-party signature points (APPROVED,
implemented).** No web template may contain a static single-party signature
point. Every signature/initial location uses the looping recipient-driven
component (`signature-block`, or `signature-line` per actual signing party);
the party set is driven by the document's signing parties/recipients per §20,
never hardcoded to `agent` (or any single static party). A full sweep of all
web-template blades found exactly four templates carrying the #119
pre-Part-B defect (a static `signature-line ['party'=>'agent']` at a
legally-required owner/seller certification point, with the terminal
`signature-block` already correctly looping):

- `cds/template-119.blade.php:17` — SALES ADDENDUM B — fixed (Part B).
- `cds/template-120.blade.php:17` — Seller Mandatory Addendum — fixed here →
  `signature-line ['party'=>'seller']` + `['party'=>'agent']`.
- `cds/template-117.blade.php:38` — Sales Mandatory (PPA s70 disclosure) —
  fixed here. Signatory set chosen = **seller + agent** (NOT seller+buyer+
  agent): clause 6 *Owner's certification* + clause 7 *Certification by
  person supplying information* make the owner/seller the certifier and the
  agent/practitioner the co-certifier; clause 9 *Buyer's acknowledgement* is
  receipt-only and is captured by the existing terminal block which already
  includes Buyer. A buyer surface at the certification line would be legally
  wrong and risk a dead cell.
- `web-templates/sales-mandatory-disclosure.blade.php:63` (template 123,
  hand-authored) — Sales Mandatory Disclosure — fixed here → seller + agent
  (owner-certification point).

The sweep is complete: these four are the full set. `cds/template-112`
(no template row; a no-party `signature-line` that renders nothing signable,
terminal block already correct) is a separate minor dead-inline and is
intentionally left untouched. Frozen `merged_html` snapshots predating a
template's fix are NOT retroactively repaired by the blade edit (they serve
the stored snapshot) — affected: #119 (13 frozen docs) and #123/doc#363
(1 frozen doc, `pending_agent_approval`); 117 and 120 have zero documents.
Root-cause guard (the CDS compiler emitting the looping component instead of
a static per-party `signature-line` from incomplete `cds_json.parties`) is
recorded as the durable Delivery-B follow-up, separate from this remediation.

---

## 6. Bottom line for Johan

Tonight's four bugs are **one SPEC-GAP**, not four code defects. The spec
specifies surface *geometry* (§6) and parks the disclosure root cause (§16),
gestures at canonical keys for *pack initials only* (§14/§19.3), but never
states the governing invariant: **one resolver, at `prepareSigning`, keyed
to the actual recipients.** The two data points confirm the seam is real and
sits in the data layer (one #119 row, two disagreeing surface-party stores;
a hand-authored blade that signing never re-derives). Approving §20 converts
"patch the next missing surface" into "build the resolver once and delete
the three compensators." No code has been written; the fix is unapproved
until §20 is signed off.
