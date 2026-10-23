# E-Sign signing modes — each agency chooses how its signers sign

> Status: **SPEC ONLY — no code.** Written 23 Oct 2026 on the esign-signing-modes-spec-2026-10-23 branch, cut from `origin/QA1` (`dd0047de7`).
> Requested by Sherry (Mega Real Estate, agency 23). Johan's ruling, 23 Oct 2026: *every agency selects how its e-sign works.*
> Code facts below were read on QA1 at `fb9ab8d28` (the tip when the investigation began) and must be re-checked at build time — line numbers drift.
> Related: `esign-ceremony-v3.md` §2 (consent profiles), `ESIGN-CANON.md`, `esign-mdf-mark-amendment.md` (AT-303), `ESIGN-WETINK.md`, `agency-timeline-and-platform-esign.md` §11.7.

---

## 1. Business rules (plain words)

1. **Each agency has one setting, "How my signers sign", with three choices.** It is set by someone with the e-sign settings permission. **Default is Mode 1** — an agency that never opens the screen sees no change at all.
2. **Mode 1 — Sign every place (today, HFC).** The signer opens the document and signs or initials each place on the document, one by one. Nothing about this mode changes.
3. **Mode 2 — Adopt, then click to stamp.** When the signer opens the document they first **adopt** their signature and their initial once (draw or type). A short agreement is shown: *"By signing here you agree this is your signature and initial and that it will be applied wherever you click to sign or initial on this document."* After that, every signature place and initial place is a **click**: the adopted mark is placed there. One click = one place, recorded separately.
4. **Mode 3 — Adopt and fill all.** Same adoption and agreement as Mode 2 (with its own wording, §6), but the signer then signs and initials **once** and CoreX applies the adopted mark to **every signature and initial place that belongs to that signer** on the document. The signer sees a confirmation that says exactly how many places will be filled before it happens. (Johan does not favour this mode; it is built because agencies ask for it.)
5. **The mode used for a document is fixed when it is sent.** If the agency changes the setting later, documents already out for signing finish in the mode they were sent in. Nobody is switched half-way through a signature.
6. **The mode applies to the people who sign by link (tenants, landlords, sellers, buyers, directors, witnesses, supervisors).** The agent keeps the agent's own way of working (saved signature unlocked with a PIN, and apply-to-all — already built and already permitted for agents). See Q3 for confirmation.
7. **Some places are never filled automatically, in any mode.** These are the places where the law or Johan's rule needs the person to act deliberately, one at a time:
   - per-clause condition initials and "every co-tenant must initial this" clauses;
   - initials that accept an amendment or a changed disclosure answer (AT-303);
   - the YES / NO / N/A answers in a disclosure grid (they are answers, not signatures);
   - anything the template marks "individual consent required".
   In **Mode 2** the signer may place their adopted initial on these with a deliberate click (that click is the deliberate act). In **Mode 3** they are **excluded from fill-all** and the signer is told how many remain.
8. **Every place that gets a mark has its own record: who, which place, when, from where, by which method** (drew it there, clicked to stamp, or applied automatically after the one confirmation). The adoption itself is recorded with the exact agreement wording and its version.
9. **Nothing is ever overwritten.** Changing a mark creates a new generation; the original stays on record (§5).
10. **The signed document and certificate look the same to the reader as today**, with the certificate gaining the adoption line and the per-place method.

## 2. What the system does today (investigation findings)

**Signing views.** Recipient: `resources/views/docuperfect/signatures/external/sign.blade.php` (the only recipient view; web/CDS places are inline `data-sig-id` blocks, initials are page boxes injected client-side by `partials/a4-page-styles.blade.php` — one per signing party per page except each document's last page; PDF-marker documents post each place to `/sign/{token}/capture/{marker}`). Agent: `resources/views/docuperfect/signatures/sign.blade.php` — **separate copy of the JS**, served by `SignatureController`, posts to `webSignComplete`. Only `a4-page-styles`, `disclosure-logic` and `_change-initial-affordance` are shared.

**Capture UI.** One shared modal `partials/_capture-modal.blade.php` (Draw via SignaturePad, Type in Dancing Script cropped to ink, and "Use my saved signature" for agents only). Mobile: `touch-action:none` on the canvas, fixed 160px pad, no orientation handling.

**Anything like "adopt once" already?**
- **Agents:** `agent_signatures` (signature image, initial image, PIN hash) + `AgentSignatureService` (8h unlock per document) + "Apply to remaining / Apply to all pages" prompts. Gated on `isAgent`.
- **Recipients: deliberately none.** Code comments (Phase 1B.9, FIX 2): "Recipients must initial each page individually for legal informed-consent reasons".
- **Doctrine** `esign-ceremony-v3.md` §2 already says every signer *adopts once at entry*; client parties then **gate per surface** (each placement a timestamped consent event, "silent apply-all forbidden"); agents apply-all. **Mode 2 is exactly that doctrine; Mode 3 reverses it for clients** (Q1).
- Nearest working model: Platform E-Sign agreement (`platform-esign/agreement/_js.blade.php`) — initials typed once, one tap per page, stored in `platform_esign_initials`; "copy Part A signature" button.
- Every other signature capture (FICA form, inspection sign-by-link and "Sign now", inventory, rental application, title deeds, policy acks) is its own pad and reuses nothing.

**What is stored per place today.**
- PDF-marker path: `signatures` row per marker (image base64, signer name/email, IP, user agent, `signed_at`, drawn/typed). No generation/superseded columns. External `capture()` has no already-signed check so a re-capture appends a second row (the PDF reads the oldest); the agent route returns 409.
- **Web/CDS path (the path CDS leases/mandates use): per-place images live only in the JSON `documents.web_template_data` (`signatures[blockId]`, `signed_initials[partyKey]`) — no per-place timestamp, IP or user agent.** Time/IP/UA exist only per signer on `signature_requests` and in one `web_signing_completed` audit row (`{party_role, field_count, signature_count, disclosure_count}`).
- `condition_initials` (per-condition, immutable: `save()` throws if the row exists); `section_acceptances` (`updateOrCreate` — can overwrite); `amendment_acceptances.initial_image`.

**Stamping.** `SigningController::completeWeb` → `CanonicalInkComposer::bakeInk` bakes images into `canonical_html` by `data-recipient-identity` → `DocumentSealService::seal` writes an append-only, hash-chained `document_sealed_versions` row per hop → `SignaturePdfService` renders the PDF (`injectInitialsPagination` restores per-page initials). Certificate: `SignatureController::downloadCertificate` → `audit-certificate.blade.php` (document hash; per party name, email, method, completed_at, IP, counts; audit trail; legal statement). It prints **no** per-place times and **no** consent wording.

**Server gate today.** `completeWeb` checks consent, "at least one captured mark", condition initials and amendment initials. **It does not check that every place was signed — exact counts are client-gated.** It also does not validate image size/format.

**Consent today.** Checkbox text ("…consent to signing this document electronically … ECTA Section 13") is not stored — only a fixed string + `consent_timestamp` in the `electronic_consent_given` audit row. The gateway consent (exact 5-point text) is stored in `esign_consent_log.consent_text`. **No consent wording has a version number anywhere** except Platform E-Sign (`platform_esign_wording_versions`) and the agent-side `template_esign_acknowledgements.wording_version`.

**Evidence/generations rule — does it hold?** Johan's "a re-signature never overwrites the original" is **not written verbatim in any e-sign spec** (`ESIGN-CANON.md` has no evidence section) and is only partly enforced: it holds for `DocumentSealedVersion`, `SignatureAuditLog`, `ConditionInitial`, and — as an explicit `superseded_at / superseded_by_*` pattern — for rental inspection and inventory signatures (`RentalInspectionSignature.php`, "never edited in place"). It does **not** hold for the web-path JSON (a re-sign of the same block id overwrites the value; only the sealed versions keep history) or for `section_acceptances`. **Modes 2/3 therefore must not rely on the JSON; they get their own append-only per-place table (§5).**

## 3. Where the setting lives

- **Table:** add `signing_mode` (string, default `sign_each`) to the existing per-agency `docuperfect_esign_settings` (model `App\Models\Docuperfect\EsignSettings`, `forAgency()` returns defaults when no row exists — so a never-saved agency is Mode 1 with no data change). Values: `sign_each`, `adopt_then_stamp`, `adopt_and_fill_all`.
- **Screen:** a new sibling page under the E-Sign settings group next to *Finalisation Settings* (`docuperfect.esign.settings.finalization`, sidebar `corex-sidebar.blade.php:2141`): **"How my signers sign"**, route `docuperfect.esign.settings.signing-mode`, same middleware `permission:esign.settings` + `agency.required`, same save pattern as `EsignFinalizationSettingsController`. Three radio cards, each with a one-sentence plain description and a "What this changes" line. Mode 3 carries a plain warning that individual-consent places are still done one by one.
- **Envelope snapshot:** `signature_templates.signing_mode` is set when the envelope is created (`SignatureService::createTemplate`, where `signing_order_json` is set) from the agency setting. Everything downstream reads the envelope's value, never the live setting (rule 5).
- **Setup Wizard (non-negotiable #10a):** a new setting must reach `config/agency-onboarding-copy.php` — or Johan decides it stays out and §5.1 of `agency-onboarding-setup.md` records it (Q5).
- Permission key `esign.settings` already exists; no new permission. No new list screen, so the CRUD/list standard (#8) does not apply to this settings page.

## 4. The three flows, step by step

**Mode 1** — no code path changes. (Regression-protected by tests, §9.)

**Mode 2 — adopt, then click to stamp** (recipient view only)
1. Signer opens the link; existing identity/gateway consent unchanged.
2. **Adoption step** (before any place is clickable): the shared capture modal collects a signature (draw/type) and an initial (draw/type; typed defaults to the name's initials as Platform E-Sign does). The agreement text (§6) sits above a tick box. "Adopt" persists a `signature_adoptions` row (§5) and turns the places into one-click stamps.
3. Every signature/initial place shows as "click to sign / initial". A click places the adopted image immediately (no modal), writes a placement (§5) with the server's time, and moves the bottom-bar "N items remaining → Go to next" (`scrollToNextIncomplete`, `external/sign.blade.php`) on. Consent (existing ECTA checkbox) and the completion gate work as today.
4. A signer who dislikes their adopted mark can re-adopt: a new adoption generation is created; marks already placed keep the old adoption; the signer can remove and re-click a place (new placement generation, old one superseded not deleted).
5. Complete → `completeWeb` as today, plus the stamped-image check (§7).

**Mode 3 — adopt and fill all** (recipient view only)
1. Same adoption step with the Mode 3 agreement wording.
2. The signer clicks **one** place (or a single "Apply to all my places" button). A confirmation states: *"Your signature and initial will be applied to all N places on this document that are yours. M places need you to act on them one by one (listed)."* On confirm, one `fill_all_confirmed` event is recorded and N placements are written, each with method `stamped_auto` pointing at that confirmation.
3. Individual-consent places (§1.7) are not filled; the signer is walked to each with "Go to next".
4. The server builds the list of the signer's own places from the canonical document (`data-recipient-identity` as `CanonicalInkComposer` reads it) — it does **not** trust a client-posted list — so a tampered client cannot add or remove places.

## 5. Evidence design

**New table `signature_adoptions` (append-only).** `id, signature_request_id, envelope_id, signer_name, signer_email, signature_image, initial_image, signature_image_hash, initial_image_hash, disclaimer_version, disclaimer_text (full snapshot), mode, ip_address, user_agent, adopted_at, generation, superseded_at, superseded_by_id`. Re-adoption inserts generation+1 and stamps `superseded_*` on the old row; nothing is updated otherwise or deleted (non-negotiable #1; follow `RentalInspectionSignature` supersede pattern).

**New table `signature_placements` (append-only).** `id, signature_request_id, document_id, place_key, kind (signature|initial), adoption_id, method (stamped_click|stamped_auto), fill_all_event_id (null for click), placed_at (server), ip_address, user_agent, represented_contact_id (proxy), generation, superseded_at, superseded_by_id`. `place_key` is the web block id / `<party>-init-<n>` / marker id used today. Removal or redo = new row, old row superseded.

**Audit log (existing `signature_audit_log`).** New free-string actions: `signature_adopted` (metadata: adoption id, disclaimer version, both image hashes, generation), `signature_placed` (place_key, kind, method, adoption id), `fill_all_confirmed` (count filled, count excluded and why, place-list hash), `signature_readopted`. Document hash carried as on existing rows.

**Mode 1 documents** keep writing exactly what they write today. (Per-place records for Mode 1 would be good evidence but change a working, gated pipeline; deliberately out of scope — a later, separate decision.)

**Generation rule check, per mode:** adoption rows and placement rows are never updated except to stamp `superseded_*`; the sealed-version chain continues to hold full document history at each hop; the `web_template_data` JSON is still written (the renderer reads it) but is no longer the only evidence. **Known gap in the existing code, reported not changed:** web-path JSON overwrite and `section_acceptances` `updateOrCreate` (§2). Outside this spec's scope; it should be raised with Johan separately (Q6).

**Certificate:** `audit-certificate.blade.php` gains, per party in Modes 2/3: the adoption time, agreement version, and a per-place list (place, kind, method, time). Mode 1 certificates unchanged.

## 6. Agreement wording (versioned)

New config `config/esign-adoption.php`: `version` (integer, bumped on any wording change) and the wording per mode. Platform-wide (legal text, not agency-editable — an agency editing legal wording is a risk, not a setting). Each adoption stores the full text it was shown, so changing wording later never rewrites history.
- **v1 Mode 2 (Johan's words):** "By signing here you agree this is your signature and initial and that it will be applied wherever you click to sign or initial on this document."
- **v1 Mode 3 (draft — Johan to approve, Q2):** "By signing here you agree this is your signature and initial and that it will be applied to every signature and initial place on this document that is yours, without you clicking each one."

## 7. What changes where

| Area | Change |
|---|---|
| Recipient signing screen | Adoption step; click-to-stamp (2); confirm-and-fill (3); new shared partial `signatures/partials/adopt-and-stamp.blade.php` so the mode logic is not pasted into a 4,000-line view. Replaces the "recipients cannot apply to all" gate (`isAgent`, `external/sign.blade.php` ~3108 and ~4026) **only when the envelope mode is 2 or 3**; Mode 1 keeps it. Comments citing "Phase 1B.9" are updated. |
| Server | `POST /sign/{token}/adopt`, `POST /sign/{token}/place` (per-click, server time), `POST /sign/{token}/place-all` (Mode 3). Token routes, like the existing `/sign/{token}/capture` — not `/api/v1` (not an API; precedent as existing signing routes). `completeWeb`: in Modes 2/3 every posted signature/initial image must hash-match the signer's current-or-prior adoption (`stamped_*`) or be a deliberate fresh capture for an individual-consent place; reconcile that a placement row exists for each posted place. Hash-matching stops a client posting arbitrary images. Existing "at least one mark" floor stays. |
| Agent signing | Unchanged. Agent view (`signatures/sign.blade.php`, own JS) keeps saved signature + apply-all. (Q3 confirms.) |
| Packs | A web pack is already **one merged document and one signing session** (`ESignWizardController::store`, `WebPackSlotResolver`; `signature_templates` is per envelope; initials restart per member document). Rule: **adopt once per envelope** (the signer's single session). Mode 2 click-stamps across all members. Mode 3 fills all of the signer's places across the whole pack, the confirmation showing the count per document. PDF packs are the same (`is_pdf_pack`). |
| Deferred parties | Nothing to adopt until they open their link; the envelope's mode is the one it was created with. A deferred signer resumed later (`resumeDeferredSigning`) signs in that envelope mode. |
| Proxy signing | Proxy has their own request (`is_proxy`, `proxy_excludes`, `represented_contact_id`); the proxy adopts their **own** mark and signs under their own name as today. Covered parties are `NOT_REQUIRED` and never adopt. Placement rows carry `represented_contact_id`. Mode 3 fills only places that belong to the proxy's own request — not those of parties they cover (covered parties have no places requiring signature anyway). Note: QA1 has zero proxy rows, so this path is untested on real data. |
| Witness | A witness who is a recipient adopts their own mark; a witness cell is never filled by another party's adoption. Where witness cells are template-only (no recipient), nothing changes. |
| Disclosure grids | YES/NO/N/A answers stay individual in all modes. AT-303 proposer initials (`proposeDisclosureAmendment`) require a deliberate action: a click-stamp in Mode 2, a fresh capture or click in Mode 3 — never fill-all. Gating counts (`_computeIncompleteItems`) need the new states. |
| Mobile | Adoption modal reuses `_capture-modal` (Type is the fast path on a phone); the "Go to next" bar becomes the main control in Mode 2; add landscape/orientation behaviour for the pad (none exists today). Tap targets ≥ 44px on stamp places. |
| PDF/certificate | Stamping unchanged (`CanonicalInkComposer`, `SignaturePdfService`); certificate additions in §5. |
| Wet-ink / paper route | Unaffected (not a screen signing). |
| Platform E-Sign / FICA / inspections | Out of scope; they have their own captures. Platform E-Sign's initials-once behaviour is the reference. |

## 8. Multi-agency check (CLAUDE.md rule #9)

Second agency: default Mode 1, no HFC wording, no agency ids in code. The agreement wording is neutral platform text. The setting is per agency; the screen has no agency-specific text. Mega selecting Mode 2 or 3 changes nothing for HFC or any other agency.

## 9. Build steps (each ~1 lane-day, single-file tests via `scripts/lane-test.sh`)

1. **Setting and snapshot.** Migration (`docuperfect_esign_settings.signing_mode`, `signature_templates.signing_mode`), `schema:dump` + DEFINER strip (#12a), model, settings page + sidebar item, resolver, snapshot in `createTemplate`, wizard decision (#10a). *Tests:* default is Mode 1 for a never-saved agency; save/validate; snapshot survives a later setting change; another agency isolated.
2. **Evidence tables and agreement wording.** `signature_adoptions`, `signature_placements` + models (append-only; update/delete throw except `superseded_*`), `config/esign-adoption.php`, audit actions, certificate additions. *Tests:* append-only, re-adoption creates generation 2 and keeps generation 1, wording snapshot stored, certificate prints lines for Modes 2/3 and is unchanged for Mode 1.
3. **Adoption step on the recipient view.** New partial, `POST /sign/{token}/adopt`, places locked until adopted in Modes 2/3, Mode 1 untouched. *Tests (in `tests/Feature/Docuperfect/SigningView/` — required by the e-sign pipeline gate when `SigningController` changes):* adoption required, wording version stored, Mode 1 view renders without the step.
4. **Mode 2 click-to-stamp.** Per-click `POST /sign/{token}/place`, remove/redo with generations, "Go to next" integration, completion gate states, `completeWeb` hash-match and reconcile. *Tests:* each click writes one placement with server time; a forged image is refused; redo supersedes.
5. **Mode 3 fill-all.** `POST /sign/{token}/place-all`, server-built place list from the canonical document, confirmation with counts, `fill_all_confirmed`, exclusion of individual-consent places. *Tests:* count correct; excluded places not filled; client cannot add places; one confirm event referenced by all auto placements.
6. **Packs, proxy, witness, deferred, disclosure/amendment interplay, mobile.** *Tests:* pack of two documents = one adoption; proxy placement carries the represented contact; covered party gets no adoption; AT-303 initial never auto-filled; deferred party signs in the envelope's mode.
7. **Real-browser proof on QA1** (desktop and a phone-width viewport, Mega as the test agency, all three modes, a lease-sized document and a pack), certificate review, regression of Mode 1 with an HFC document, then hand to Johan.

## 10. Risks

- **Legal / doctrine (Mode 3).** Reverses a settled, shipped decision ("recipients initial each page individually for legal informed-consent reasons"). Mitigated by the explicit counted confirmation, the stored agreement, the excluded individual-consent places and the audit — but it remains Johan's call (Q1) and arguably a conveyancer's. ECTA §13(1) still applies independently: e-sign eligibility is the template's setting and is untouched by this mode.
- **Duplicated JS.** The recipient and agent views carry separate copies of the capture/apply-all code; the partial keeps the new logic in one place, but the 4,000-line recipient view is fragile (the pipeline gate and its tests exist because of past regressions).
- **Web-path evidence gap.** Web/CDS documents store no per-place time/IP today; the new tables fill that gap for Modes 2/3 only. A signer in Mode 1 on the same document type still has the old, thinner evidence.
- **Client-gated counts.** The server today does not know how many places a document has; Mode 3 needs a server-derived place list, which depends on the canonical document carrying reliable `data-recipient-identity` on every place. Documents that predate that stamping, or odd templates, need a graceful "this document cannot be filled automatically — sign each place" fallback to Mode 2 behaviour.
- **Network.** Per-click placement writes can fail on a phone; the client queues and the completion reconcile catches any gap rather than losing a click.
- **Pack initials.** Initials restart per member document and are skipped on each document's last page; counts and keys must follow that rule exactly or the count shown in Mode 3 will be wrong.
- **Untested paths.** Proxy signing and witness-as-recipient have no real data on QA1.
- **Moving target.** QA1 advanced (`fb9ab8d28` → `dd0047de7`) while this was being written; re-verify every cited file and line before building.

## 11. Decisions Johan needs to make

- **Q1 — Mode 3 against the settled rule.** Do you accept Mode 3 despite the standing rule that clients initial each page themselves? Proposed safeguards: a counted confirmation, the agreement stored, and condition / amendment / "every co-tenant" places always done one by one.
- **Q2 — Mode 3 wording.** Approve the draft Mode 3 agreement in §6 (your wording only describes Mode 2).
- **Q3 — Agents.** Confirm the mode applies only to people who sign by link, and agents keep their saved-signature and apply-to-all. (The alternative is agents also following the agency mode.)
- **Q4 — Packs.** Confirm adopt once per pack (one signing session), not once per document in the pack.
- **Q5 — Setup Wizard.** This is a new agency setting; rule #10a makes whether it appears in the new-agency wizard your call.
- **Q6 — Existing evidence gap.** Do you want the web-path JSON overwrite and `section_acceptances` overwrite closed as its own piece of work? Separate from this spec.

*Decisions already made in this spec (no ask): the setting snapshots onto each document at send; Mode 1 documents are not changed; wording is platform text not agency-editable; the new setting is a separate "How my signers sign" page.*
