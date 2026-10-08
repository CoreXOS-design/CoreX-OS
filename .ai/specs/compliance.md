# Spec: Compliance

**Status:** Partially implemented across modules — needs centralisation

---

## SA Compliance Context

CoreX operates under South African property law. Compliance in this context means:

| Framework | Applies To |
|-----------|-----------|
| **PPRA** (Property Practitioners Regulatory Authority) | Agent licensing — FFC per agent |
| **FICA** (Financial Intelligence Centre Act) | KYC on all parties — buyer, seller, landlord, tenant |
| **POPIA** (Protection of Personal Information Act) | Data consent on every contact |
| **CPA** (Consumer Protection Act) | Disclosure obligations in mandates and offers |
| **Property Practitioners Act 22 of 2019** | Overarching legislation governing all practitioners |

---

## Current State

Compliance is currently scattered:
- FICA checklist items referenced in documents but not tracked as a status
- No central compliance dashboard
- Agent FFC status not surfaced in the system
- POPIA consent not captured at contact level

---

## Consolidation Items (Phase 1)

- [ ] FICA status flag on Contact record
- [ ] FICA status flag on Deal record
- [ ] PPRA/FFC status field on Agent (User) profile
- [ ] POPIA consent block on Contact record (captured at creation, logged with timestamp)
- [ ] FICA checklist items configurable in Settings → Compliance

---

## Pending Spec Items (Phase 2)

- Central compliance dashboard (per-deal compliance checklist with status per item)
- FICA document upload and verification workflow
- Ellie: Document Legal Review (clause checking against SA legislation)
- Automated compliance reminders (FFC renewal approaching, FICA docs expiring)
- Compliance audit trail (who verified what, when)

---

## Rulings (decided — do not re-propose without a new mandate from Johan)

### FICA form fields are never pre-filled, from any source

**2026-09-13, AT-392 round 5.** While building the rental-application → FICA
hand-off, it was flagged that four FICA fields (full name, ID number, phone,
email) duplicate data already held on the rental application — and the ID
number is literally what the applicant just typed to pass the return gate
seconds earlier. Pre-filling was proposed to cut the typing roughly in half
at exactly the point applicants are most likely to abandon the form.

No FICA/CDD legal requirement blocks this — legal responsibility attaches at
the signed declaration to whatever values are in the form at submission, not
to the act of typing them. That analysis was put to Johan and he overruled
it on a stricter-than-legal-minimum compliance line, verbatim: **"for fica we
do not fill anything. for compliance the applicant needs to fill it. same
like on normal fica. do not build any auto fills."** His reasoning: on his
FICA process, the client fills the form themselves, the same as any other
FICA client — the applicant's own act of entering each value is part of what
he considers the declaration to attest to, regardless of where legal
liability technically sits.

**The rule:** `fica/form.blade.php` never receives, and must never receive,
any value from `RentalApplication`, `Contact`, or any other prior record —
not full name, not ID number, not phone, not email, not anything else. Every
field starts blank for every applicant, every time, exactly as it does today.
The downstream question this would have raised — what happens to the Contact
record if an applicant's FICA entry disagrees with a value already on file —
is moot as a result and was not decided; it only existed as a consequence of
pre-filling. If pre-fill is ever reconsidered, that question (a real one,
with a genuine existing pattern to build from — see `App\Models\Docuperfect\
DocumentAmendment`'s propose/pending/accept-reject shape) comes back with it.

---

*Full spec to be completed after Phase 1 consolidation.*

---

## Go-Live Migration Mode (agency on-boarding only)

When a new agency signs up to CoreX they bring across their existing book — typically ~350 P24 listings plus a contacts spreadsheet — that is already compliant in the real world. Forcing them through the full FICA / mandate / marketing-permission gates before they can transact would block them for weeks and cost real business. Two opt-in toggles let the on-boarding admin flag these legacy records as already compliant. **Both toggles are intended for the agency go-live event only, not for ongoing imports.**

### 1. Property compliance auto-stamp (P24 CSV importer)

- **Where:** Admin → Importer → "Listings & Images" upload form (`/admin/importer`).
- **Control:** Checkbox `mark_compliant_on_confirm`, default **checked**.
- **Persistence:** Stored on `p24_import_runs.mark_compliant_on_confirm` (migration `2026_05_28_140000`) so every row confirmed from that run inherits the flag — rows are often confirmed via the public onboarding portal, possibly days later.
- **Effect at confirm time:** `ConfirmP24PropertyRowJob` writes `compliance_snapshot_at = now()` and a `compliance_snapshot_data` JSON tagged `source: 'p24_go_live_migration'` with the run id, listing number, and an explanatory note. This trips the existing short-circuit in `MarketingReadinessService::statusFor()` (line 31) so the property is treated as fully marketable.
- **Active stock only (Johan, 2026-08-18):** the toggle applies per-row, gated on that row's own P24 status having normalised (`P24ListingsCsvParser::normaliseStatus()`) to `Active`. A run commonly carries a mix of active, sold, rented, and withdrawn stock — only the Active rows are grandfathered as "already compliant"; Sold/Rented/Withdrawn/anything else is imported as a record only, on this same run, with no compliance auto-stamp. This is a per-row check inside the same transaction, not a separate run-level filter, so one CSV upload correctly produces both stamped and unstamped properties.
- **Audit:** The snapshot data is preserved on the property forever; the run id links back to the source CSV upload.

### 2. Contact FICA auto-approve (contacts Excel importer)

- **Where:** Contacts → "Import Contacts from Excel" panel (`/corex/contacts`).
- **Control:** Checkbox `mark_fica_approved`, default **checked**.
- **Effect per contact:** `ContactImportController::import` inserts an approved `fica_submissions` row with `status = 'approved'`, `verified_at = now()`, `verified_by = importer`, `verification_method = ['source' => 'go_live_migration']`, and a reviewer note recording the import provenance.
- **Result:** `Contact::ficaStatus()` returns `compliant` for the contact immediately, satisfying the seller-FICA gate in `MarketingReadinessService`.

### Why this is safe

- Both flags are explicit opt-ins on the upload form — never automatic for ongoing CSV/Excel uploads after go-live.
- Every stamped record carries a `source` marker (`p24_go_live_migration` / `go_live_migration`) so compliance officers can later audit which records were grandfathered vs which passed the full workflow.
- The compliance pillar's data model is untouched — no new fake "always compliant" column, no service-layer bypass. We use the same snapshot column and the same `fica_submissions` table the regular workflow writes to.

---

## FICA online completion — questions AND answers on the review screens (2026-10-06, cc1; Johan 15:11, from Elize)

**Request.** When a client completes FICA online, the agent must be able to see the QUESTIONS asked as well as the
ANSWERS given in order to approve, and the RO (responsible officer) and CO (compliance officer) the same. Two screens:
the FICA record page `/corex/compliance/fica/{id}` and the compliance review page
`/corex/compliance/fica/{id}/compliance-review`. Johan: "the top section where we display the answer is where the
questions should be shown as well."

### Investigation findings (what existed, with evidence)

- **The questionnaire is hard-coded, one form, four entity branches.** `resources/views/fica/form.blade.php` (Blade + Alpine) — Natural Person, Company/CC, Trust, Partnership; section numbering differs by entity (natural 1,2,4,5,6,7,8,9; others 1–7); conditional follow-ups are Alpine `x-show`; upload slots come from the form's JS `computedUploadTypes`. It is not data-defined, not per-agency, not versioned.
- **Answers are stored as keys only.** `FicaPublicController::submit()` stores `$validated` — the validated answers — as JSON in `fica_submissions.form_data` (`personal`, `entity`, `principal`, `representative`, `service`, `pep`, `declaration`, `entity_type`), plus `signature_data`/`signed_at`. **Neither the question wording nor any form version is stored with a submission.** Documents are `fica_documents` rows keyed by `document_type` = the upload slot key (e.g. `id_copy`).
- **What the top section rendered.** `resources/views/compliance/fica/partials/submitted-data.blade.php` — one partial, included by BOTH screens — printed answers under hand-written labels in separate cards ("Person Completing Form", "Service & Payment", …), with an "Uploaded Documents" card and a separate signature card. It showed no questions, no follow-up logic, no "not answered", and documents were not tied to a question. The record page uses it for online intakes only (a separate block for paper intakes); the review page includes it for every intake.
- **Who can see each screen (unchanged by this work).** Both: `permission:access_compliance` + `agency.required` + `feature:compliance` (`routes/web.php` ~2643), then `FicaController::authorizeAgency()` — agency match and the per-user own/branch/company tier (`FicaSubmission::scopeVisibleTo`, AT-346; owner roles bypass). The review page additionally requires `isComplianceOfficer($agency)` (any active `FicaOfficerAppointment`: primary CO or MLRO = the "RO"). Default role grants (`config/corex-permissions.php`): `branch_manager` (branch scope) and `admin` hold `access_compliance`; the default **`agent` role does not** (it holds `compliance.fica.send` and `fica.view` only) — so who in practice may open the page as an agent is a Role-Manager setting per agency. **This build adds no access and changes no scope.**
- **Is the wording the client actually saw recoverable? Yes — reliably, for every submission that exists.** The question-bearing lines of the form are byte-identical from 2026-03-26 to today (diffed commit by commit; only CSS, a hidden `return_context` field and the brand name inside guidance tooltips changed). Every genuine online completion on QA1 (8 of 209 rows with answers; the other 201 are paper intakes) is dated 2026-04-17 or later; the single row dated before 2026-03-31 is a paper intake with a back-filled date. The one thing that changed was guidance text (tooltips), which is not a question.
- **Existing print/PDF.** There is no print/PDF of the *review screens*. There is a **completion report** PDF for APPROVED submissions (`FicaCompletionReportService`, `compliance/fica/completion-report.blade.php`; legacy `pdf.blade.php`), generated once and persisted on first download. It prints answers under hard-coded labels, is a certificate of an approved record rather than "the review", and a persisted copy would not change retroactively — **left as it is; reported** (see below).

### What is built

1. **`App\Support\Compliance\FicaQuestionnaire`** — the questionnaire as the client saw it: ordered sections, exact question wording, the client's option wording for yes/no, choices and tick-lists, the follow-up conditions, the repeating people blocks and the upload slots, per entity type, with the section numbering the client saw. `forSubmission()` turns one submission into the question-and-answer view.
2. **The shared partial is rewritten in place** (`submitted-data.blade.php`), so both screens render the same block and cannot disagree. One compact card: a header line (submitted date/time, by whom, link sent by whom/when, form wording version); then each section as a thin header row and one row per question — question left, answer right, follow-up questions indented; **unanswered questions say "not answered"**; follow-ups the client was never shown are **not listed**; documents sit **on the row of the question that asked for them** with a View link, "not uploaded" when none; the signature and "Signed at" sit in the Declaration section (the separate signature card is gone — nothing is repeated). Documents on the record that answer no question (staff uploads) are listed apart as "Other documents on this record". Anything the client stored that the question list does not account for appears under "Other recorded answers" by its stored key — nothing is hidden. Paper (wet-ink) intakes show a one-line note, the intake facts and their documents; a form not yet completed says so. Read-only; the existing linked-contact-documents block still follows.
3. **No new access, route, permission, setting or migration.** No change to either controller or to `show.blade.php`/`compliance-review.blade.php` (both already include the partial).

### How the wording stays honest (rule for whoever changes the public form)

A submission cannot tell you what it was asked, so `FicaQuestionnaire` is the record. **If you change a question on the public form you must, in the same commit,** keep the old wording in `FicaQuestionnaire` under its own version with the date it stops being asked and add the new wording as a new version from its go-live date (`versionFor()` picks by `signed_at`); never edit a version in place. `tests/Feature/Compliance/FicaQuestionnaireWordingTest.php` compares the form's Blade and the catalogue in both directions (labels, option wording, section titles, upload-slot labels and keys) and **fails if either changes alone**; it also asserts it extracts enough strings to be meaningful. A submission signed before the first recorded version (2026-03-26) is flagged "wording may differ" rather than shown with wording it never saw (none exists today).

### Acceptance / tests

`FicaQuestionsAnswersReviewTest` (17 tests: each role, scope, conditional/unanswered/entity/paper/draft/unknown-key rendering, parity of the two screens) and `FicaQuestionnaireWordingTest` (5 tests: drift guard) — 22/22. The review tests seed the REAL role defaults (`corex:sync-permissions --seed-defaults`) and force the production posture, because the suite's default "empty grants table = allow everything" would let every user through `permission:access_compliance` and make the "no new access" assertions prove nothing. The drift guard was shown to fail in both directions when one question is reworded on the form. Proven paths: record page as branch-scoped reviewer · primary CO and MLRO on both screens, same questions in questionnaire order · both screens render identical row blocks · follow-ups present/absent by the client's answers · blank optional/conditional answers → "not answered", unasked documents → "not uploaded" · documents beside their question + staff documents apart · company (renumbered sections, beneficial owners) · trust (beneficiaries yes/no follow-ups) · paper intake · not-completed form · unknown stored key and unknown option value shown · markup in an answer escaped · pre-2026-03-26 caution · **no new access:** default agent role 403 on both, admin who is not an appointed officer 200 on the record page and 403 on the CO screen, branch-scoped user 403 on another branch's record, other-agency admin blocked.

### Reported, not changed

1. **Multi-agency:** the public form's guidance tooltips hard-code "Reos trust account" and agency payment practice (`fica/form.blade.php` `paymentTooltip`/`cashTooltip`) — HFC's arrangement shown to every agency's clients (CLAUDE.md #9). Guidance text, not questions; not touched.
2. The completion report PDF (above) prints answers under hard-coded labels, not question and answer; the legacy `pdf.blade.php` still exists beside `completion-report.blade.php`. Say the word if the completion report should carry the questions too (only newly generated reports would change).
3. The default `agent` role lacks `access_compliance`, yet the agent approval step lives behind it — agencies grant it through the Role Manager; worth confirming for the second agency.
4. `FicaPublicController::submit()` stores only the validated keys, so a field the form posts but the rules do not list is dropped silently.

## FICA Questions & answers — Download PDF and Print (2026-10-07, cc2; Johan 08:52, screen `/corex/compliance/fica/9837`)

**What Johan asked.** A Download and a Print of the "Questions & answers" view — the questions WITH the client's answers AND the client's signature — as a form the agent keeps on file.

**Investigation (what existed).**
- The completion-report certificate (`FicaCompletionReportService` + `completion-report.blade.php`) is rendered by `scripts/html-to-pdf.mjs` (Node + Puppeteer/Chromium, browser path from `services.pdf.puppeteer_browser_path`), frozen at approval, and prints answers under hard-coded labels with no questions. Its letterhead is the agency logo (`agencies.logo_path`) plus `agencies.default_color` (platform default `#0b2a4a` when unset) — never an agency constant.
- The online client's signature is `fica_submissions.signature_data` (a canvas data-URL PNG) with `signed_at`; the signed-at location is `form_data.declaration.signed_at_location`; there is no separate typed signed name — the person who completed the form is `form_data.personal.full_name`. The declaration the client ticked is fixed form text, now `FicaQuestionnaire::DECLARATION_TEXT`. **Paper (wet-ink) intakes have no digital signature or answers** — only the uploaded scan (`intake_type = wet_ink`).
- `FicaQuestionnaire::forSubmission()` (cc1, 6 Oct) is the one source of the questions, order, grouping, follow-up rules and "not answered".

**Built.** `FicaQuestionsAnswersDocument` (service) + `compliance/fica/questions-answers-document.blade.php` (the document) + two routes in the existing `access_compliance` group (`compliance.fica.questions-answers.pdf` / `.print`) + two actions on `FicaController` + a **Download questions & answers (PDF)** and a **Print questions & answers** control in the PAGE HEADER action area of both screens (see "Placement and names" below). `scripts/html-to-pdf.mjs` gained an OPT-IN page-number footer (`PDF_PAGE_NUMBERS=1`, optional `PDF_FOOTER_LABEL`); every other caller leaves the env unset and gets byte-for-byte the old output.
- **Document:** agency letterhead (logo or agency name); client/entity name (company / trust / partnership name, else contact name) and FICA reference (`FICA #<id>`); submitted date/time and by whom; who sent the link; form wording version; wording caution if any; every section and question in the questionnaire's order with the answer; unanswered → "not answered", un-uploaded → "not uploaded"; documents by file name; "other recorded answers" and "other documents" so nothing is hidden; then the declaration text, the signature IMAGE, signed by / on / at. Page numbers "Page n of N" with the client and FICA reference in the footer. Dense A4, no chrome.
- **File name:** `FICA-questions-answers-<client>-<submitted date>.pdf`.
- **Placement and names (Johan, 7 Oct 2026, QA1 test of `/corex/compliance/fica/9834`).** The two controls used to appear twice — inside the Questions & answers panel AND beside a header "Download PDF" (which is a *different* document, the certificate) — so two buttons called "Download PDF" sat in two places. Now: both Q&A controls live in ONE partial, `compliance/fica/partials/qa-header-actions.blade.php`, included in the page header action area of `compliance.fica.show` and `compliance.fica.compliance-review` (next to Back / Compliance Review), and nowhere else on those screens. Names: **"Download questions & answers (PDF)"** and **"Print questions & answers"**. Shown by the same rule the routes enforce (`FicaQuestionsAnswersDocument::available()`), so a button never leads to a 404. The header "Download PDF" on an approved record is renamed **"Download FICA certificate (PDF)"** (the frozen FICA Compliance Certificate, `compliance.fica.pdf` — unchanged). The certificate's small "PDF" links on the compliance list (`compliance/fica/index`) and the contact FICA tab (`corex/contacts/_fica-tab-body`) are other screens and were left as they are. What the PDF/print contains is unchanged.
- **Print** opens the same document as an HTML page that calls the browser's print dialog (no second layout; the PDF and the print page are one Blade view).
- **Paper intake / not-completed form:** no button; both routes 404 (nothing fabricated); the screen's existing note says the answers are on the uploaded form.
- **Access: none new.** Same route group as the screens (`permission:access_compliance` + `agency.required` + `feature:compliance`) and the same `FicaController::authorizeAgency()` own/branch/company scope. The CO-only gate on the review screen is not added — the record page already shows the same data to the same people.
- **Audit:** every PDF download and every print writes an append-only `fica_status_history` row (`questions_answers_downloaded` / `questions_answers_printed`, actor, tier, format); the workflow status is unchanged. Refused requests write nothing.
- **Signature safety:** only an inline PNG/JPEG/WebP data URI is ever placed in the page; anything else (a URL, an SVG) prints as "Signature not captured". The public form posts the signature as a free string (`required|string`) and this page is rendered by a server-side browser, so an unchecked value could make the server fetch an arbitrary URL.

**Tests.** `FicaQuestionsAnswersPrintTest` (11): every question in screen order with every answer, signature image, declaration, header facts, own-agency letterhead (no HFC wording) · "not answered"/"not uploaded", signature printed once · company name heading and file name · hostile signature values never rendered · markup escaped · real PDF: right file name, questions + answers + "Page 1 of N" + embedded signature image · buttons on both screens · paper intake and not-completed form: no button, 404, nothing audited · default agent role 403, other branch / other agency refused, admin and branch manager allowed · audit rows. Neighbour: `FicaQuestionsAnswersReviewTest`.

**Reported, not changed.**
1. **Should the completion-report certificate also carry the questions?** Recommendation: yes, for newly generated certificates — it is the document an inspector reads on its own, and today it shows answers under hard-coded labels, so it is the one place the question and the answer can still be separated. Cheapest correct shape: render its answers section from `FicaQuestionnaire::forSubmission()` (one source) instead of its own labels. Trade-off: a longer certificate, and already-approved records keep their frozen copy until re-approved. Not changed — Johan's call.
2. `completion-report.blade.php` puts `$submission->signature_data` straight into an `<img src>`; with the public form accepting any string for it (`FicaPublicController` `'signature_data' => 'required|string'`), the same server-side-fetch exposure the new document guards against exists there. Fix is two lines (validate as an inline image on submit, and guard in the certificate); not touched.
3. Tests that render a PDF need Node + Puppeteer + Chromium on the box (as the certificate always has); they skip without `/usr/bin/chromium`.

## FICA — an officer cannot review their own FICA (blocked at the START) (2026-10-07, cc1; Johan's bug report)

**Johan's bug.** A Responsible Officer (RO) could open their OWN FICA submission, mark it up, do all the work, and only at the very end the save was refused ("an RO cannot approve their own FICA"). The agreed fix — block the Review button for the reviewer's own submission — had never been built: AT-236 (Jul 2026) shipped only the end-of-flow refusal in `FicaController::complianceApprove`. This closes that gap.

**Rule (unchanged from AT-236, now enforced at entry).** An appointed officer (any active `FicaOfficerAppointment`: RO/MLRO or CO) who is NOT the primary Compliance Officer may not review or mark up a FICA that is their own work. **The PRIMARY Compliance Officer is the one exception and may self-approve** (Johan's composed rule in AT-236 — "secondaries can never self-approve; only the primary may"). Loosening that to "no CO may approve their own" is a one-line change in `FicaSubmission::ownReviewBlockFor()` and is Johan's call.
- "Their own work" (`FicaSubmission::ownWorkUserIds()`): the user who requested it (`requested_by`) or who did the stage-1 agent approval (`agent_verified_by`) — exactly the set the AT-236 end-of-flow guard already used. **A FICA whose *subject* is the officer** (the officer's own contact/agent record) is NOT detected: the data model has no link between a contact and a staff user (`contacts.client_user_id` points at the separate `client_users` portal table, not `users`, so it cannot be used). Detecting it needs a deliberate user↔contact link — Johan's decision, not built.
- Non-officers are NOT blocked: an agent doing the stage-1 check on a FICA they sent is the normal flow; the officer steps are what stay separate.

**Where it is enforced (server, at the start — `FicaController::refuseOwnReview()`):** GET `compliance-review`; POST `agent-approve`, `request-corrections`, `reject`, `compliance-approve`, `compliance-reject`, `tfs-decision`, `return-to-referrer`, `reopen`, **`resubmit-corrections`** (added in the audit-fix round: it moves the pack straight to `agent_approved` with no stage-1 check, so for the requesting officer it was a back door round the blocked stage-1 step; a *different* officer, an admin or a non-officer requester may still resubmit). A blocked request is redirected to the record page with the reason and saves nothing. A blocked POST writes a `self_approval_blocked` row to `fica_status_history` (meta `attempt`) **only when there was something to review** — the record is in a review state (`FicaSubmission::OWN_REVIEW_STATUSES`: submitted, under_review, corrections_requested, agent_approved, referred_to_co), or the attempt is `reopen_rejected` on a rejected record; a blocked POST on an approved / cancelled / draft record is still refused but not recorded. Repeats are collapsed: the same user repeating the same action on the same FICA within 60 seconds is ONE row (the first), a different action or a later attempt gets its own. A GET writes nothing (a refresh must not flood the ledger). The end-of-flow guard in `complianceApprove` stays as a backstop. NOT blocked, by design: viewing the record, Q&A download/print, document upload/link, screening runs, **Escalate to CO** (the sanctioned way out).

**Where it shows (disabled, with the reason "You cannot approve your own FICA - another Responsible Officer or the Compliance Officer must review it."):** FICA record page (notice at top; "Compliance Review"/"Open review" disabled; checklist/approve/corrections/reject not offered; "Resubmit for CO Review" not offered; TFS decision form hidden), Compliance list (the row's Verify/Review action), contact FICA tab (chip beside View). The compliance-review screen itself refuses to open.

**Only while it can apply.** The notice and the disabled buttons show only while the record is in a review state (`ownReviewNoticeFor()`); on an approved / cancelled / draft record there is no notice and no dead button. On a **rejected** record there is no banner either; the one action that exists there is Reopen for Corrections, so for a blocked officer the Reopen button is replaced by a one-line plain reason (`ownReopenBlockFor()`) rather than a button the server would refuse. Other viewers keep the button.

**Wording tells the truth.** Stage 1 (submitted / under_review / corrections_requested) is open to ANY user with compliance access, not only officers, so the stage-1 message never says nobody else can do it: "…another Responsible Officer or the Compliance Officer must review it, or any other user with compliance access can do this first check." A **referred** pack (`referred_to_co`) is decided only at the referral station (`FicaReferralService::isReferralStationOwner`: the resolved recipient or the primary CO), so its message says "this pack was referred, so only the Compliance Officer it was referred to (or the Primary Compliance Officer) can decide it" and "no other Compliance Officer able to decide this referred pack" when none can; it never promises "another Responsible Officer may review it". Who is allowed to approve is unchanged; this is wording and the "is there anyone else" check only.

**Cost.** The list and the contact tab ask the question once per row; the per-agency officer facts (is the viewer an officer / the primary CO, the active appointments, the referral recipient) are loaded once per page render through `App\Support\Compliance\FicaOwnReviewContext` and reused, so officer lookups do not grow with the number of rows (asserted in the tests). A context is created per render, never cached across requests.

**No other eligible reviewer.** If no other active officer could review it (the primary CO always counts; any other officer counts only if the FICA is not also their own work), the notice says so plainly — "…there is no other Responsible Officer or Compliance Officer in your agency to review it. An administrator must appoint one under Company Settings → Compliance Officers." The spec (AT-236) defines no automatic fallback for this case — it is an agency-staffing gap, not something the system may waive — so there is no override.

**Tests:** `FicaOwnApprovalEntryBlockTest` (37: entry refusal on every endpoint incl. resubmit-corrections, another RO and the primary CO pass, resubmit allowed for a non-officer requester / a different officer, real branch_manager and admin roles through the scope path, backstop holds if the entry check is bypassed, screens, notice and Reopen button absent on approved / rejected / cancelled / draft and present in every review state, officer-lookup count independent of row count on the list and the contact tab, truthful stage-1 and referred wording, no ledger row for unreviewable records, 60-second ledger de-dup, fixtures with `agent_verified_by` NULL at stage 1); `FicaMultiOfficerWorkflowTest` (AT-236; one redirect expectation updated — the refusal now lands on the record page).

**Open business questions (owner's call, NOT built, raised by the audit):** (1) what "own FICA" means — today only "I requested it / I did the stage-1 check on it", not "the FICA is about me" (an officer who is the *subject* of a FICA someone else sent is not blocked); (2) a sole-officer / small-agency override and whether the primary-CO self-approval exception should be an agency setting (it is a code constant; if it becomes a setting it must also reach the Setup Wizard, Non-negotiable 10a); (3) whether an officer may still remove documents from their own FICA while it is in review (`documents.remove` / `linked-documents.unlink` are not blocked); (4) `AgentDeletionService` reassigns `requested_by` / `agent_verified_by` to another user when an agent is removed, which can retroactively make that user's officer role "own work".

**Reported, not changed.** The Command Centre "RO Approvals" quick link (`CommandCentreService::ficaRoApprovals`, whole `agent_approved` pool) and the Compliance list RO queue count still include an officer's own FICA; the click-through now lands on the disabled record page.

## The FICA gate — one rule, shared by sales and rentals (2026-10-08, cc3; Johan's rulings of 7–8 Oct as relayed by the conductor)

**The bug (Johan's rentals test on QA1, 7 Oct).** FICA stopped the agent from continuing with the tenant where it should have warned (as reported by the conductor, paraphrased). His ruling of 8 Oct (a paraphrase relayed by the conductor, not a verbatim quote): the FICA gate lifts on **SUBMITTED**, not only on approved — that is the designed behaviour for sales, and rentals must work the same.

**The rule.** `App\Services\Compliance\FicaGate` is the one place it lives. A contact's gate is **open** when they have a non-deleted `FicaSubmission` in `submitted`, `under_review`, `agent_approved`, `referred_to_co` or `approved` (`FicaGate::OPEN_STATUSES`). `draft` (requested, not submitted), `corrections_requested` / `rejected` (sent back), `expired` and "nothing at all" are **closed**. An approved submission outranks a newer unsubmitted draft. Before this class the status list was copy-pasted into the signing controller and the e-sign wizard, and rentals asked a different question (`Contact::ficaStatus()` — "complete" only for APPROVED), which is how a tenant who had submitted still read as "FICA outstanding" on the rentals side.

**Closed gate = a warning, with the link.** `FicaGate::describe()` returns the plain-language warning ("… You can carry on — …") and the link to request/complete FICA (the existing submission, otherwise the contact's own page where an agent sends the request). Screens show it; the agent carries on.

**Where FICA STOPS (mirrors sales exactly — the only intended hard stops):**
1. **The external signer's own signing page** (`SigningController::show()`): a signer whose request has `fica_required` and who has not submitted is held at the FICA page. Sales mandates and a lease agreement's tenant/landlord are the same page. The agent can untick "FICA verification required before signing" per recipient in the e-sign wizard.
2. **The agency's own opt-in "FICA before authoriser" setting** (`require_fica_before_authorisation`, rental-applications; **OFF by default**): when an agency switches it on, sending an application to the authoriser stops for an applicant who has **not submitted** (a submitted applicant goes through).
3. **Marketing readiness** (`MarketingReadinessService`, mandate/syndication — unchanged, sales-side, still reads APPROVED): not part of this work.
4. **FICA's own review controls** (an officer cannot review their own FICA — section above; TFS sanctions gate): staff-side compliance controls on the FICA record itself, not the gate; unchanged.

**Where FICA only WARNS (rentals path — each proven in every FICA state):** send application to authoriser (response carries `fica_warning` + `fica_url`; the review screen shows it), approve ("Approved, subject to FICA verification" is a label until compliance verifies — it never stops), the review-screen badge (a submitted applicant reads **FICA Submitted** in amber, not red "Outstanding"), link tenant to property, create lease (the "Who signs" panel shows each party's FICA state under the same rules), activate lease, send lease for signature (agent side), signing, portal access (the lease screen warns per party). The e-sign "My documents" page shows **FICA submitted** (not "Awaiting FICA") once the signer has submitted.

**Not changed (named so nobody assumes otherwise):** `Contact::ficaStatus()` and `RentalApplication::ficaOutstanding()` still mean "compliance has VERIFIED this person" (they drive the "subject to FICA verification" label, the FICA tiles and the Contact badge); the new `RentalApplication::ficaGateOpen()` / `ficaGateDescribe()` answer "may the agent carry on?".

**Tests:** `FicaGateTest` (every state), `SigningView/FicaGateLiftsOnSubmittedTest` (signing page, every state), `RentalApplicationFicaGateStepsTest` (send to authoriser with the setting off and on, approve, review screen — every state), `LeaseFicaWarnNotBlockTest` (link → create → activate → portal for tenant and landlord, lease screen warning, party-check — every state).
