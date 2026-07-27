# MDF two-phase signing lifecycle — foundation (2026-07-27)

Branch `esign-input-followup`. QA1 only. The Mandatory Disclosure (Immovable Property
Condition Report) is signed in TWO phases:

- **Phase 1 (now):** the SELLER (+ property practitioner) completes and signs the MDF —
  the YES/NO/N-A disclosures, the ADDITIONAL INFORMATION frames + per-frame initials, and
  the Seller + Property Practitioner signature blocks. The seller-signed MDF is then a
  persistent, retrievable, immutable artifact for that property.
- **Phase 2 (later, via OTP):** when an Offer to Purchase is made for the property, the
  filed seller-signed MDF is selected and the PURCHASER signs THEIR portion — the Purchaser
  signature block + the Buyer's Acknowledgement (prescribed sections 9 & 10) — **on top of**
  the already-seller-signed document, without re-signing or re-rendering the seller's portion.

## Chosen model: ONE ceremony, deferred purchaser (not a second ceremony)

The prescribed MDF form already contains BOTH the Seller and the Purchaser signature blocks
(and the Buyer's Acknowledgement) as markers in the one document. Therefore the purchaser is a
**deferred signer on the same MDF ceremony**, activated in phase 2 — NOT a brand-new signer
grafted onto a separate OTP ceremony. This deliberately avoids the three hardest gaps of the
alternative "attach the filed PDF to a new OTP ceremony and inject a purchaser block" model:
no filed-PDF→ceremony bridge, no post-completion block injection, no new-signer-on-a-COMPLETED
template. Every piece the chosen model needs already exists.

## How each requirement is satisfied by existing infrastructure

| Requirement | Mechanism (already exists) |
|---|---|
| Seller content frozen/immutable after phase 1 | `web_template_data.canonical_html` + `canonical_version`. Once any ink bakes, `canonical_version >= 1` and `CanonicalDocumentRenderer::forDisplay()` returns the stored bytes VERBATIM (never re-composes). |
| Purchaser ink lands without disturbing seller | `CanonicalInkComposer::bakeInk()` paints ONLY markers the current signer owns (matched by `data-name` / `data-recipient-identity="{role}_{index}"`, else sole-of-role fallback). The purchaser (buyer) owns only buyer markers; the seller's baked `<img>` ink is structurally inert to the purchaser's pass. |
| Purchaser is a planned deferred phase | Create the purchaser `SignatureRequest` up-front with `STATUS_DEFERRED` (the wizard "sign_later" action). When the last active signer completes, the flow parks the template in `SignatureTemplate::STATUS_AWAITING_DEFERRED` (it does NOT complete). |
| Activate the purchaser later | `SignatureService::resumeDeferredSigning($template, $purchaserRequest, name, email, …)` sets the request back to WAITING and `advanceToNextParty` picks it up. Route `docuperfect.signatures.resumeDeferred`. |
| Per-property linkage / retrievability | `docuperfect_documents.property_id` (+ `property_address`), template `document_type` slug `disclosure`. New accessor `Document::sellerSignedDisclosure()->forProperty($id)` (this build). |
| Filed durable artifact | At full completion `completeDocument()` generates the signed PDF (`signed_pdf_path`) and files an `App\Models\Document` (`source_type='esign'`, `document_type_id=disclosure`, property/deal/contact links). Before that, the immutable `canonical_html` IS the exact retrievable artifact (renders exact PDF on demand via `SignaturePdfService::buildInjectedRenderHtml`; screen == PDF). |

## Lifecycle state model (no new status needed)

```
DRAFT → SIGNING → (seller signs, agent signs; purchaser DEFERRED)
      → STATUS_AWAITING_DEFERRED     ← PHASE 1 SEALED: seller-signed, immutable, filed per property
      → (OTP: resumeDeferredSigning(purchaser)) → SIGNING (purchaser only)
      → STATUS_COMPLETED             ← PHASE 2 DONE: purchaser-signed on top, fully filed
```

`STATUS_AWAITING_DEFERRED` is the "phase-1 sealed, awaiting purchaser" state. The document is
never marked `COMPLETED` (terminal) until the purchaser signs, so it is never a throwaway
ceremony — it persists, retrievable and immutable, until phase 2.

## Immutability guarantee (why the seller's portion cannot change)

1. **Version gate:** after phase 1, `canonical_version >= 1`, so `forDisplay` serves the stored
   canonical bytes verbatim — the seller's baked ink is never re-composed.
2. **Identity-scoped baking:** the purchaser's `bakeInk` pass only mutates buyer-owned markers;
   seller markers (different `data-name`/identity) return false from `markerBelongsToSigner` →
   never touched.
Together: the purchaser signs strictly additively; the seller's signed content is byte-stable.

## What this build ADDS (the foundation, minimal)

- `Document::scopeSellerSignedDisclosure()` + `scopeForProperty()` — the first-class accessor the
  OTP will use to select a property's phase-1 MDF (gap #6 from the infra audit). No schema change.
- This spec (the model of record), so the OTP phase is a composition step, not a retrofit.
- QA1 proof (persistent doc): a seller+agent-signed MDF parked at `AWAITING_DEFERRED`, property-
  linked, canonical immutable; `resumeDeferredSigning` activates the deferred purchaser; the
  seller markers' baked ink is byte-identical across the purchaser phase.

## OTP integration points (build LATER — not now, but no rework needed)

When the OTP is built it will, for the property being offered on:
1. Select the phase-1 MDF: `Document::sellerSignedDisclosure()->forProperty($propertyId)->first()`.
2. Present/attach the immutable seller-signed MDF to the purchaser (render from `canonical_html`).
3. Resume the deferred purchaser: `resumeDeferredSigning($template, $purchaserRequest, buyer name/
   email from the OTP)` → purchaser signs sections 9 & 10 → `bakeInk` (seller immutable) →
   `completeDocument` files the fully-signed MDF.

Optional (clean extension, not required by the model): auto-generate a durable phase-1 PDF
snapshot when the template enters `AWAITING_DEFERRED` (a hook alongside the existing completion
filing), so the seller-signed MDF also exists as a filed PDF before phase 2. The canonical already
guarantees exact on-demand rendering, so this is an optimisation, not a correctness requirement.

## Reusable primitive
The deferred-signer + immutable-accumulation pattern is general: any document with a later,
planned second-party signing phase (MDF → purchaser via OTP is the first) rides the same
`DEFERRED → AWAITING_DEFERRED → resumeDeferredSigning` machinery with identity-scoped baking.
