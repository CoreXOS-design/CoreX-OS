# Phase-A engine-coupling audit — candidate-flow authorising-party signatures

Date: 2026-08-01 · Lane: cc3 (e-sign/DocuPerfect) · Branch: `at-supervisor-sig` (off `origin/QA1` @8fa9cafb)
Trigger: Johan ran a candidate-process e-sign, looked at the authoriser screen — 3 symptoms:
(1) TWO initial blocks for the authoriser, (2) never prompted to sign the final signature place,
(3) inconsistent fill (some marks render, some don't).

## Governing ruling (Johan, 2026-08)
The authorising party (full-status Property Practitioner / Principal, drawn from the shared
authorisation queue) is a **FULL-PARITY signer**: signs AND initials at exactly the same places
as the candidate/recipients. Identified by their **designation** from the user tables, never a
hardcoded "supervisor". **Completeness is legally critical** — banks/attorneys count every party ×
every slot; one empty slot = the whole document is rejected for re-signing.

## Root cause (one architectural fact)
`supervisor` and `supervisor_final` are ROUTING CHECKPOINT pseudo-roles for ONE human, but the
engine treated them as two independent signing identities, and the authoriser was rendered as a
special one-off hardcoded block excluded from the parity machinery and bound by a placeholder name.

## Coupling findings
1. **Placeholder-name identity coupling.** The identity matchers (`_isMyMarker` client,
   `CanonicalInkComposer::markerBelongsToSigner` server) key ownership on `data-name` FIRST. The
   authoriser is the ONE signer whose person is unknown at document creation (shared queue), so the
   baked placeholder name ("Authorised Practitioner (shared queue)") never equals the real claiming
   practitioner → their signature block was never owned/interactive/baked, while name-less ceremony
   spans bound by party-role and DID fill. That split IS symptom 3.
   → Fix: bind the authoriser by ROLE-IDENTITY (`data-recipient-identity`), never a placeholder name.
2. **Internal-vs-external enumeration divergence.** `SignatureController@sign` (internal agent view,
   :1039) deduped `supervisor_final` + `unique('role')`; `SigningController` (external view the
   authoriser actually uses, :499) did NEITHER. So the external per-page initials row emitted a box
   for both `supervisor` and `supervisor_final` = symptom 1.
   → Fix: a single shared authority (`SignatureTemplate::enumeratedSigningParties`) used by both.
3. **Hardcoded-role-label coupling.** `signature-block.blade.php` hardcoded "Supervising
   Practitioner" + a placeholder name; nothing was designation-driven.
   → Fix: designation-labelled block (`authorising_designation`), neutral "Authorising Practitioner"
   until the claiming practitioner's designation binds at sign time; per-page label relabelled off
   the raw "supervisor" token (`CHECKPOINT_DISPLAY_LABELS`).
4. **Checkpoint pseudo-role as a 2nd identity.** `supervisor_final` had NO signature surface, so at
   the final routing checkpoint nothing was ownable/promptable = symptom 2.
   → Fix: `foldIdentity` folds the checkpoint family (supervisor / supervisor_final → supervisor) for
   OWNERSHIP, so one authoriser identity owns the parity surfaces at either checkpoint. Index-
   preserving for everyone else, so seller_1/seller_2 stay strictly isolated.
5. **Authoriser excluded from the parity machinery** (special-cased block instead of a full signer).
   → Fix: the authoriser is a full-parity signer — one per-page initial (enumeration) + a parity
   sig-party-block, identity-stamped, designation-labelled.

## Changes (engine, designation-driven, zero per-doc coupling)
- `SignatureTemplate`: `CHECKPOINT_ROLE_ALIASES`, `CHECKPOINT_DISPLAY_LABELS`,
  `enumeratedSigningParties()` (collapse + dedup + relabel).
- `SigningController` / `SignatureController`: both build `signingParties` via the shared authority.
- `InsertableBlockRenderer::renderInitialSlotsForCondition`: enumerates via the shared authority.
- `signature-block.blade.php`: authoriser parity block — identity-stamped, NO placeholder name,
  designation label.
- `ESignWizardController`: passes `authorising_designation` / `authorising_identity` (neutral).
- `CanonicalInkComposer`: `foldIdentity()` + ownership via fold (server bake).
- `external/sign.blade.php`: `_foldIdentity()` mirror; applied in `_isMyMarker`, `_isMyInitialBox`,
  `makeFieldsInteractive`, `isMyWebSigBlock`.
- `SigningController::completeWeb`: single-authoriser-signing semantics — the post-external
  `supervisor_final` completion touch is exempt from BOTH the "captured ≥1 mark" floor AND the
  per-condition initial gate (it places no fresh mark), gated on the base `supervisor` request
  having COMPLETED (never an empty-completion hole).
- `ESignWizardController` (pack loop): the authoriser parity block now renders on candidate PACK
  segments too — is_candidate_flow / authorising_designation were passed only on the single-doc
  path, so a candidate pack rendered NO authoriser surface and their marks landed nowhere.
- `EsignRegressionWalk`: first-class COMPLETENESS assertion scoped to the document's ACTUAL signers
  (bank-reject guard — an unused-role template block is not a required slot) + self-contained
  authoriser-parity guard.

## Proof — live end-to-end candidate ceremony (QA1, disposable)
Drove a real candidate pack (candidate #26 → authoriser #43 full parity → seller → supervisor_final)
through the actual controllers: document reaches `completed`, and the final canonical carries the
authoriser's identity-stamped signature + ceremony (AUTHLOC) + name — single authoriser signing
completes end-to-end, no fresh mark required at supervisor_final.

## Compose-time authoriser-surface injector — BUILT (Johan-authorised 2026-08)
Closed the imported-template gap: on ANY candidate document/segment, the authoriser gets exactly ONE
full-parity signature surface regardless of how the template was authored.
- `CandidateAuthoriserSurfaceInjector` — compose-time pass over the merged body (same pattern as
  `SigningSurfaceResolver`), wired into BOTH the pack loop and the single-doc path (candidate flows
  only). IDEMPOTENT (component segments already carrying an authoriser surface are skipped);
  PARITY-SCOPED (injects only into segments where another party already signs; pure-info attachments
  get nothing); IDENTITY-STAMPED + DESIGNATION-LABELLED (matches the component block exactly).
  Per-page initials were already universal (client pagination over enumeratedSigningParties).
- Harness AUTH-f guards the non-component / imported-segment case.
- Proven end-to-end (QA1, disposable): the candidate pack (mandate + Mandatory Disclosure + Addendum)
  now yields 3 authoriser surfaces — 1 from the component + 2 injected on the non-component segments —
  ALL baked, and the document completes.

## Proof (disposable, corex_dev3)
- Engine proof (14/14): enumeration collapse; parity block identity-stamped / no placeholder /
  designation; bake ownership across the fold (supervisor_final signer bakes supervisor-identity
  signature + initial + ceremony); co-recipient isolation (seller_1/seller_2 never bleed); per-
  authoriser completeness.
- Harness authoriser-parity block: all-green (6/6).

## Flow semantics — RESOLVED (Johan, 2026-08)
SINGLE authoriser signing event, positioned right after the candidate signs (the natural early point,
as it works today). There is NOT a second mandatory authoriser signing touch under normal flow:
candidate signs → authoriser signs full parity ONCE → externals → done.
- The authoriser signs their complete parity set (all initials + signature) at the initial-review
  checkpoint (`supervisor`) right after the candidate — routing unchanged.
- The post-external `supervisor_final` checkpoint is the completion/distribution act and produces NO
  fresh mark, so `completeWeb`'s "captured ≥1 mark" floor is relaxed for it — GATED on the base
  `supervisor` request having COMPLETED, so it can never be an empty-completion hole
  (`SigningController::completeWeb`).
- The COMPLETENESS guard stays first-class (every party, every slot filled).

## PARKED follow-up (do NOT build here — e-sign enhancement ticket)
When additions/amendments are made to a document later, the signing flow must require the authoriser
(and other relevant parties) to sign/initial again on the additions, as part of the flow. Johan: "the
kicker we'll get to later." Not implemented in this change — landing on the e-sign enhancement ticket.
