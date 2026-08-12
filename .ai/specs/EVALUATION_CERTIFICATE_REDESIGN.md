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
