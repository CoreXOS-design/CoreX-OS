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
