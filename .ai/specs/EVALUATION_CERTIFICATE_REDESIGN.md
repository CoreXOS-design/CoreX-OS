# Evaluation Certificate — Redesign Spec
Screen: /tools/cma (Tools → "CMA Certificate" tab → CMA Certificate Generator). Captured 12 Aug 2026 from Johan.
TERMINOLOGY RULE (legal, non-negotiable): always "evaluation", NEVER "valuation" — valuation carries a legal meaning agents may not use. Scrub every "valuation" from this screen, output, labels, filenames, share text, and the certificate itself.
1. PROPERTY SEARCH + PREFILL (or manual): add a property search; agent can load an existing property (fields prefill) OR capture manually. Both must work.
2. EDITABLE WHEN LINKED: when linked, fields prefill but stay editable; agent can edit and SAVE the evaluation (persist, not just print). The evaluation is its own record; don't clobber the source property's data.
3. OUTPUT = SHARE / PRINT / DOWNLOAD: replace the single "Print CMA Certificate" button with three actions. Share = same as elsewhere (WhatsApp + link). Print. Download (PDF).
4. FILE TO PROPERTY DRIVE: if linked to a property, the signed certificate is filed on that property's document drive (reuse existing property doc storage).
5. ON-SCREEN E-SIGN (PIN) + CANDIDATE→FULL-STATUS AUTHORISATION:
   - Full-status signer: clicks Sign → enters PIN → signed → distribute + filed.
   - Candidate practitioner: CANNOT complete alone. Mirror the e-sign principal-authorise flow: candidate creates+signs (PIN) → queues for a full-status practitioner to authorise ("sits somewhere"; both parties see there's something waiting) → full-status sees it, accepts+signs (PIN) OR rejects-with-note. Authorised → back to candidate to distribute (Share/Print/Download), filed to property drive. Rejected → back to candidate with note; candidate fixes + resubmits. Status visible to both: pending authorisation → authorised / rejected.
   - Determination: user has a `designation` field (auth()->user()?->designation, seen in tools.blade). Confirm it distinguishes candidate vs full-status and which designation(s) may authorise.
BUILD APPROACH: reuse the existing e-sign "Authorise Documents" mechanism for the authorisation chain; reuse the proven saved-signature PIN machinery (_capture-modal/_placer + /corex/signature/*). /tools/cma produces no persisted PDF today — decide the certificate output (dompdf view like the commercial-evaluations PDF, or a DocuPerfect template) before a signature can be placed/filed. Verify by rendering as the actual signing user (don't repeat the e-sign wrong-modal / wrong-route-prefix mistakes).

## Addendum — 12 Aug 2026, from Johan

6. CONTACT LINKING: if the certificate is linked to a property we already know the contact; OR allow the agent to link a contact at this step. REUSE the existing contact-link pattern used elsewhere (Johan cited Pitch Now — link a contact; DR2 — link a supplier; DR2 deal capture — link seller/buyer). Do not build a new picker.
7. SHARING = the standard CoreX WhatsApp "did-you-send" model — IDENTICAL to the Core Matches feature shipped this afternoon (resources/views/corex/contacts/match-results.blade.php). Flow: Share → WhatsApp → open WhatsApp in a NEW tab and share → on return to the current tab, show the "did the message send? yes / no" prompt → if YES: capture the send against the linked contact + increment the WhatsApp count → if NO: still record it on the contact (as we do now). Same principle used everywhere.

## Build assignment + cross-lane contract — 12 Aug 2026

Phase 0 locked: certificate output = **dompdf** (port `generateCmaPrintHtml()` from `tools.blade.php` to a server-side Blade view). Lanes: cc3 = Phase 1 (model + property search + contact link) · cc6 = Phase 2 (dompdf cert + valuation→evaluation scrub) + Phase 3 (Share/Print/Download) · cc1 = Phase 4 (saved-sig PIN placement) · cc5 = Phase 4b (candidate→full-status authorisation queue) + Phase 5 (property-drive filing) + Phase 6 (end-to-end verification), and cc5 coordinates the model shape across lanes.

### Contract: fields Phase 4b/5/6 need on cc3's Phase 1 model (proposed by cc5, please confirm/adjust rather than diverge silently)

Mirrors the proven `SignatureTemplate`/`SignatureService` candidate-authorisation pattern (same names/shapes where sensible, so the review-screen build is a port, not a reinvention):

| Column | Type | Purpose |
|---|---|---|
| `status` | string | `draft` / `ready` / `pending_authorisation` / `returned_to_candidate` / `authorised` |
| `is_candidate_flow` | bool, default false | Set true at creation if the creator is a candidate practitioner |
| `created_by` | FK users | Creator (the candidate, in the candidate flow) |
| `authorised_by` | nullable FK users | Full-status practitioner who accepted + PIN-signed |
| `authorised_at` | nullable timestamp | |
| `returned_notes` | nullable text | Reject-with-note message shown back to the candidate |
| `signed_by` | nullable FK users | Whoever applied the final PIN signature (full-status directly, or the authoriser) |
| `signed_at` | nullable timestamp | |
| `signed_pdf_path` | nullable string | Where Phase 2's rendered signed PDF lives — Phase 5 files this |
| `property_id` | nullable FK properties | Already implied by point 1 — Phase 5 needs it to know where to file |
| `filed_at` | nullable timestamp | Phase 5 stamps this once attached to the property's document drive |

Authoriser determination needs no schema — `App\Services\CandidatePractitionerService::isCandidate()/canAuthorise()/getEligibleAuthorisers()` operates on `User` alone and is reused as-is.

cc5 is blocked on this model landing on `origin/QA1` before Phase 4b/5 code can be written — watching for it. If cc3's actual column names differ, flag here rather than cc5 guessing against a stale contract.

### Phase 4 / 4b boundary (cc1 + cc5 — avoid building two sign surfaces)

Mirror the real e-sign pattern: `authoriseSigning()` has no PIN-sign UI of its own — it routes the authoriser into the SAME sign ceremony a direct signer uses. Same split here: **cc1 (Phase 4) owns the one PIN-sign surface** (modal/route — used both when a full-status practitioner signs directly, and when a full-status authoriser accepts+signs a candidate's certificate). **cc5 (Phase 4b) owns the queue/gating/reject-with-note state machine only** — the authoriser's "accept" action routes into cc1's existing sign surface rather than a second one cc5 builds. cc1: please post the route/component name once Phase 4 lands so Phase 4b can link to it.

### cc1 Phase-4 sign-surface contract — 12 Aug 2026 (published for cc5 to link into; cc3/cc6 to build to)

cc1 is **blocked on cc3's model + cc6's dompdf output landing on `origin/QA1`** — same blocker cc5 recorded above. There is no `EvaluationCertificate` record to sign (grep: zero references in app/database/routes/resources) and no server-side output view where the signature lands, so placement cannot be written against a real target or verified in-browser (done-criteria require rendering as the actual signing user). Rather than build placement into a vacuum against guessed field names, cc1 locks the interface now so the moment cc3+cc6 land it drops in as a verified one-pass build. The surface below mirrors the proven e-sign machinery already shipped: `AgentSignatureService` (encrypted-at-rest, PIN-hashed, impersonation-blocked — commit `f7fda6d4`) + the saved-sig `_capture-modal` wiring on `external/sign.blade.php` (commit `d6d99dcc`, route prefix corrected `385ae0d8`).

**1. The one PIN-sign surface (what cc5's "accept + sign" routes into):**
- Route: `POST corex/tools/evaluation-certificate/{certificate}/sign` → name **`evaluation-certificate.sign`**. Single endpoint for BOTH a full-status practitioner signing directly AND a full-status authoriser accepting+signing a candidate's certificate — cc5's state machine decides *who* reaches it; the route itself does not branch on candidate-vs-authoriser.
- Front-end: reuse `docuperfect/signatures/partials/_capture-modal.blade.php` with `$savedSignatureSupport = true` (the gate already added in `d6d99dcc`) + the proven `signaturePlacer` / saved-sig Alpine state. cc1 adds one thin partial `resources/views/tools/_evaluation-certificate-sign.blade.php` that mounts that modal on the /tools/cma screen — the ONE modal both entry points open.
- PIN unlock uses the existing foundation endpoints via route helpers (never hardcoded `/signature/*`): `route('signature.status')`, `route('signature.unlock')`, `route('signature.asset', ['type'=>'signature'])`. Unlock `contextKey = 'evalcert:'.$certificate->id`.

**2. Guards the route enforces itself (independent of cc5's queue):**
- agent-only; `AgentSignatureService::guardNotImpersonating()` → 403 under switch-user/impersonation; `savedSigConfigured` (`AgentSignatureService::isConfigured($user)`) — if unset, the modal shows the "set up your signature in My Portal" path, not the PIN box.

**3. Columns cc1 WRITES at sign time — CONFIRMED against cc5's contract, no divergence, no new columns:**
- `signed_by = auth()->id()`, `signed_at = now()`. cc1 hands the rendered signed-PDF path back for `signed_pdf_path` (cc6 renders it, cc5/Phase-5 files it). The saved-signature bytes are **baked into cc6's dompdf render at sign time** (immutable filed artifact — the legal cert must reflect the signature as it was at signing, unaffected by a later My-Portal signature change) rather than stored loose on the row, so **no extra `signed_signature_image` column is needed**. cc5's table stands as-is.

**4. What cc1 needs from cc6 (the "where the signature lands" coordination Johan called for):**
- A server-side render entry that accepts the PIN-unlocked signature image (a `data:` URI) and returns the signed PDF — e.g. the `tools.evaluation-certificate-pdf` dompdf Blade view exposing a signature-block slot: `$signatureImage` (+ optional `$initialImage`, signer display name, `signed_at`). cc1 passes `AgentSignatureService::image($user,'signature')` into that slot; cc6 owns the visual placement in the certificate layout.

**5. What cc1 needs from cc3:**
- Model on `origin/QA1` with at least `id`, `status`, `signed_by`, `signed_at`, `signed_pdf_path` named exactly as the contract table above. cc1 only writes `signed_by` / `signed_at`.

**6. Verification (runs the instant 4+5 exist):** render `/tools/cma` as a real full-status agent → create+link a cert → Sign → PIN once → confirm the saved signature bakes into the downloaded/filed PDF; then repeat as an authoriser accepting a candidate's cert (routed in by cc5). Impersonation attempt on the sign route must 403.
