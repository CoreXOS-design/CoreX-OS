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

### cc1 Phase-4 — LANDED (commit `23a5e124`), reconciled to cc3/cc6's real shapes — 13 Aug 2026

Deps landed (cc3 `26e133ce` model+migration, cc6 `9adcb4a2` dompdf view + Download). Built + verified against the REAL shapes, which differ from the proposals above — **use these, not the earlier ask**:

- **Route for cc5 to link "accept + sign" into → `POST /tools/cma/evaluation/{certificate}/sign`, name `tools.cma.evaluation.sign`** (aligned to cc3/cc6's `tools.cma.evaluation.*` + `permission:access_calculators` convention — NOT the `evaluation-certificate.sign` I proposed). One endpoint, both paths: full-status direct sign AND full-status authoriser accepting a candidate cert. cc5's queue decides who reaches it; the endpoint does not fork. It bakes + files in one shot, so cc5's "accept" action is just `POST .../sign` — no second surface.
- **Columns written at sign time** (cc3's real `_user_id`-suffixed names): `signed_by_user_id`, `status = 'authorised'`, `signed_pdf_path`; plus `authorised_by_user_id` when the cert was `pending_authorisation` (authoriser-accept path). Signature bytes baked into cc6's `renderCertificatePdf($cert, $sig, $init)` → immutable PDF at `signed_pdf_path` (default disk; `download()` streams it). No extra column.
- **Guards:** `access_calculators`; agency-scoped 404; impersonation 403 (up-front + service-level); candidate 403 (cannot finalise alone); not-configured 422; already-signed 409; wrong/absent PIN 422.
- **Verified:** `tests/Feature/Tools/EvaluationCertificateSignTest.php` — 6 passed / 21 assertions.
- Front-end note: eval-cert is **saved-signature-only, no markers**, so I reused the saved-sig *machinery* (`AgentSignatureService` + `signature.*` endpoints, contextKey `evalcert:{id}`) rather than mounting the marker/draw `_capture-modal` verbatim (its Draw/Type/`activeMarker` shape doesn't fit a no-marker one-click place). The sign endpoint accepts the PIN inline (`verifyPinAndUnlock`) — a focused PIN confirm is the correct host UI. Flag if you want the literal `_capture-modal` instead.

### cc1 — full /tools/cma screen + persist + Share LANDED (commits `4047a51d`, `e6a6ba26`) — 13 Aug 2026

Johan assigned cc1 the last-mile (persist endpoint + screen wiring) — BLOCKER #1 below is now RESOLVED. The old client-side CMA generator is replaced by the real Evaluation Certificate builder on `/tools/cma`.

Final route surface (all `permission:access_calculators` unless noted):
- `POST tools.cma.evaluation.store` / `PUT tools.cma.evaluation.update` — persist (cc1). Agency-scoped; property/contact links re-checked against agency visibility; a signed cert is immutable (409).
- `GET tools.cma.evaluation.share-meta` — linked contact's deep-link WA number + 30-day temporary SIGNED public URL + message.
- `GET tools.cma.evaluation.public/{certificate}` — **public, `signed` middleware, no auth** — the client-openable cert (Download is agent-only). Streams filed signed PDF else preview.
- (existing) `search-properties`, `property-contact`, `search-contacts`, `contact-inline` (cc3); `download` (cc6); `sign` (cc1 Phase 4).

Screen (Alpine `evalCert()` in `tools.blade.php`): property typeahead→prefill EDITABLE fields + auto-link seller/owner contact; manual capture; contact search + inline match-or-create; Save persists; **Sign** (PIN modal → `tools.cma.evaluation.sign`, full-status only, candidate/not-configured messaged); Download (cc6); Print (cc6 inline PDF); **Share** (WhatsApp did-you-send, AT-323 model, reuses `corex.contacts.increment` + `whatsapp-send-confirm-modal`). Terminology scrubbed to "evaluation".

Cross-cutting fix landed here: the cert's linked contact resolves via an **agency-scoped resolver that bypasses the personal `ContactScope`** — an auto-linked seller/owner (created by someone else) must still show for Share + on the filed/shared PDF (applied in `sign` + `publicView` render). cc6's `download()` preview still reads `$certificate->contact` raw — if you want the contact name guaranteed on the unsigned preview too, apply the same resolver there.

Verified: `EvaluationCertificateScreenTest` 10/10 + `EvaluationCertificateSignTest` 6/6.

### cc1 — post-live-test fixes (Johan, 13 Aug 2026) — flow + PDF render

After Johan's live test + PDF review. cc1 owns these end-to-end incl. cc6's dompdf blade (cc6 idle).

**PART 1 — progressive button disclosure (`tools.blade` `evalCert()`):** actions now gate by state instead of showing disabled — Draft/unsaved shows ONLY Save; after Save (`certId`) Download/Print/Sign appear; **Share appears only after Sign** (`isSigned`). Removed the confusing "visible but dead until Save" buttons.

**PART 2 — PDF render (`resources/views/tools/evaluation-certificate/pdf.blade.php` + `renderCertificatePdf`):**
- **Agency logo in header** — `agencyLogoData()` embeds `agency->logo_path` as a **base64 data-URI** (raster png/jpg/gif; svg/missing → falls back to the agency-name text). Embedded, not a remote URL → self-contained/fast render. Header now shows logo + name.
- **Signature placement fixed** — the old CSS put the image in flow then pushed the line down with `margin-top:46px`, so the signature floated disconnected at top-left. Now a fixed-height `.sig-slot` holds the signature **directly on the ruled line** above "Evaluated & signed by / {name}". The stray bordered **initial box was removed** (`$initialImage` no longer rendered in this block) — one tidy signature area.
- **"Authorised by" is conditional** — `showsAuthoriser()`: shows only when the cert has an `authorised_by_user_id` OR its **creator is a candidate practitioner**; a full-status practitioner signing directly gets **no authoriser block**. Determined from the certificate's own data (creator designation), NOT `auth()->user()` — so it is correct on the public/client render (no session) and during the authoriser's sign (where `auth()` is the full-status finaliser, not the candidate). This realises the spec's candidate-vs-full-status intent robustly.

Also: **nav renamed** "CMA Certificate Generator" → "Evaluation Certificate" (`corex-sidebar.blade.php`, `navigation.blade.php`).

Verified: `EvaluationCertificateScreenTest` (view hides/shows "Authorised by" by flag; `showsAuthoriser` false for full-status creator, true for candidate creator / authorised cert) + Sign PDF still bakes. Kept OFF `SignaturePdfService` (no docuperfect regression).

### cc1 — v5 PDF header + filename (Johan + Elize, 13 Aug 2026)

- **Full company letterhead** — the certificate header now `@include`s the SAME shared e-sign block `docuperfect.web-templates.components.company-header` (trading name, address, reg no, VAT, NCC, email, phone, fax, **FFC**, FIC — whatever the e-sign header carries), tied to the certificate's agency via `previewAgency` (correct on the public/no-auth render) with the logo passed as `logo_url` (base64). The component was authored for e-sign's **Chromium** renderer, so a scoped `.ec-letterhead` wrapper overrides CSS `grid`→table (the two-column contact strip) and constrains the logo (dompdf ignores `object-fit`, AT-367, which would stretch it). The shared component is untouched → future letterhead edits flow through automatically. **Verified by rendering to PDF→PNG** (pdftoppm): bordered letterhead, undistorted centred logo, two-column contact strip, all details present.
- **Download filename from the address** — `certificateFilename()` slugs the property address → e.g. `380-Wilfred-Street-Shelly-Beach-Margate-Evaluation-Certificate.pdf` (non-alphanumeric → hyphen, capped 120 chars); falls back to `Evaluation-Certificate-EC-{id}.pdf` when there is no address. Used by both `download()` and `publicView()`.
- Note: the fuller letterhead can push a short certificate onto a 2nd page (signature block lands there) — acceptable for a formal cert; logo capped at 74px to keep it tight.

### cc1 — property-drive filing (spec item 4) + candidate flow PARKED (Johan, 13 Aug 2026)

- **Filed to the property drive on sign** — `fileToPropertyDrive()` creates a canonical `Document` (the signed PDF at `signed_pdf_path`, `source_type='evaluation_certificate'`, `source_id`=cert id, named by the property address) and attaches it via `document_properties` (`$doc->properties()->syncWithoutDetaching`) — the SAME pivot `Property::documents()` / the PDF splitter / DR2 use, so the certificate shows on the property's drive like any other filed doc. Called from `sign()` after the PDF is baked+filed; **non-fatal** (a filing hiccup logs a warning, never fails the signature); **idempotent** (one Document per certificate); no-op when the cert has no `property_id`. Property resolved agency-scoped (bypasses personal visibility, never crosses agency). `document_type_id` left null (no dedicated eval type exists). Verified: `EvaluationCertificateSignTest` — signed+linked → doc on the property drive (named by address, one copy); unlinked → nothing filed.
- **Candidate flow PARKED** — Johan is (correctly) impersonation-blocked from setting up a candidate's saved signature/PIN, so the candidate authorise→sign path can't be exercised until a real candidate practitioner tests it. The authoriser-section render conditional (`showsAuthoriser`) is built + unit-verified; end-to-end candidate testing is deferred to a candidate on QA1. cc5's Phase-4b authorisation queue remains the outstanding piece.

### cc1 — CANDIDATE FLOW finished end-to-end (Johan, 13 Aug 2026; absorbs old Phase-4b)

The candidate dead-end modal ("you cannot finalise… Cancel") is replaced by a full submit→authorise/reject→return loop. cc1 owns it end-to-end (cc5 off it). Mirrors the e-sign Authorise-Documents pattern using the eval cert's own model.

- **Migration** `…000070_add_candidate_signature…`: `candidate_signature_image` (longText, **encrypted** cast, `$hidden`). The final PDF is baked at the AUTHORISER's sign — when the candidate isn't present to unlock their saved signature — so the candidate's signature is **snapshotted at submit** and baked into "Evaluated & signed by" at authorisation.
- **Endpoints** (all `access_calculators`, impersonation-blocked, saved-sig-gated via `guardSigner()` + `unlock()`):
  - `submitForAuthorisation` (candidate only, own draft/rejected cert) → snapshots candidate sig, `signed_by=candidate`, `status=pending_authorisation`, clears `reject_note`. NOT finalising.
  - `authorise` (full-status, `canAuthoriseFor(candidate)`, pending cert) → bakes candidate snapshot ("Evaluated & signed by") + authoriser live sig ("Authorised by"), `authorised_by=authoriser`, `status=authorised`, files to drive.
  - `reject` (full-status, pending) → `status=rejected` + required `reject_note`.
  - `queue` (GET) → role-scoped list: candidate sees own pending/authorised/rejected; authoriser sees pending certs they may authorise (`canAuthoriseFor`).
  - `sign` is now **direct full-status only** (candidate/pending → routed to submit/authorise).
- **PDF**: `renderCertificatePdf($cert, $signerImage, $authoriserSignatureImage)` — two signature slots; the "Authorised by" slot now bakes the authoriser's signature image.
- **Screen** (`tools.blade` `evalCert()`): candidate "Sign & submit for authorisation" (was the dead-end); a role-aware **queue panel** (candidate: my submissions + status/download; authoriser: pending → Review); the sign modal drives 3 modes (`submit`/`finalise`/`authorise`); **Reject** modal (note); status badge + returned-note banner; `fieldset :disabled="formLocked"` makes a submitted/authorised cert read-only; rejected → editable + "Sign & resubmit". Statuses colour-coded both sides.
- Authoriser eligibility = `CandidatePractitionerService::canAuthoriseFor` (agency admin agency-wide, OR BM/full-status of the candidate's branch) — reused, no new logic.
- Verified: `EvaluationCertificateSignTest` — candidate submit→pending (+snapshot), full-status authorise→authorised (2 sigs, filed), reject+note→resubmit, candidate/stranger-branch cannot authorise, queue role-scoping.

### cc1 — DISCOVERABILITY: authorisers can now FIND pending authorisations (Johan, 13 Aug 2026)

Johan (full-status) had a candidate cert but "nowhere to be found on my side." Root cause was two-fold: (a) the only surface was the buried `/tools/cma` queue panel, AND (b) it only shows when something is actually pending — and the candidate's certs were still `draft` (never submitted). Fix adds real entry points:

- **`EvaluationAuthorisationService`** — single source of truth: `pendingFor(User)` (pending certs the user may authorise) + `pendingCountFor(User)` (cached 30s, badge count). `queue()` now uses it.
- **Bell notification on submit** — `submitForAuthorisation()` creates a `DatabaseNotification` (`type=evalcert.authorisation_pending`, `data.action_url` → `/tools/cma`) for every `getEligibleAuthorisers(candidate)`, and busts their badge cache. Non-fatal.
- **Sidebar entry + count badge** — under Tools, a **"Pending Authorisations"** item appears for eligible authorisers whenever `pendingCountFor > 0`, with a red count badge, linking to the eval screen. So a submit lights up the sidebar without a magic URL.
- The `/tools/cma` "Evaluations awaiting your authorisation" queue panel remains the list where each pending cert opens to Authorise+sign / Reject.
- ⚠️ Field note: Angelique's live certs were `draft`, not `pending` — she must click **"Sign & submit for authorisation"** (she has a saved sig+PIN, so it will work) for it to reach Johan.

### cc1 — DEDICATED authorise screen (Johan's 2 defects, 13 Aug 2026)

Johan's authorise experience was broken: clicking Review on the /tools/cma queue loaded the pending cert into the **create/edit builder** (blank/editable "new cert" chrome), and "Authorise & sign" threw a JS **"Save your changes first"** alert (the builder's dirty-watch fired when the cert was loaded). Root cause = reusing the editable form as the authorise surface. Fix = a **dedicated read-only review view**:

- **New page** `GET /tools/cma/evaluation/authorisations` → `tools.evaluation-certificate.authorisations` (Alpine `evalAuth()`). A **LIST** of pending certs (property, value, submitting agent, submitted date, status) each with **Review** → a **read-only** review (the finished PDF in an iframe: `download?inline=1`) → **Authorise & sign** (PIN → direct `POST …/authorise`, NO save step, NO dirty-check) or **Reject** (note). Scales to many pending at once. `queueItem` gains `submitted_at`.
- **Entry points repointed** to the new page: sidebar "Pending Authorisations" badge + the submit notification's `action_url`.
- **/tools/cma is now candidate-only for the queue** — the "My submitted evaluations" panel shows only for `queueRole==='candidate'`; the authoriser authorise/reject buttons were removed from the builder. Authorisers never touch the editable form → both defects gone by construction.
- Verified: `EvaluationCertificateScreenTest` — the authorisations page renders the dedicated `evalAuth()` review (has "Authorise & sign", does NOT have "Find a property"/"Save evaluation").

### ⚠️ REMAINING FLAGS from cc1 (Phase 4) — need owner decisions

1. ~~No certificate SAVE/PERSIST endpoint~~ — **RESOLVED**: Johan assigned cc1 the last-mile; `store`/`update` + the full screen are built (see section above). End-to-end in the browser now works.
2. **`signed_at` column absent** — cc3's table has no `signed_at`/`authorised_at`/`filed_at` (the proposed contract had them). cc1 relies on `updated_at` + `status`. cc5 (Phase 4b/5) likely wants explicit `signed_at`/`authorised_at`/`filed_at` for the queue + POPIA trail — flag to cc3 if so.
3. **cc6 dompdf render fetches a remote font** (`isRemoteEnabled=true`) — in a no-network env the render blocks ~200s until timeout (seen in test). QA1 has network so it's fast there, but for determinism/latency cc6 may want to embed the font locally. cc6's render path — reporting, not changing.
