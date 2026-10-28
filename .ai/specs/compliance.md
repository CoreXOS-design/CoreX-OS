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

## FICA review — removing a wrongly attached document (2026-10-28, hotfix 3a7369b61; live bug on `/corex/compliance/fica/9908`)

An agent attached the wrong documents to an online FICA and had no way to take them off. The remove / unlink actions existed server-side (`FicaController::removeDocument`, `unlinkContactDocument`) but since the 6 Oct questions-and-answers rewrite (`ecd7f3e19`) the buttons were only drawn for paper (wet-ink) intakes.

**The rule (same on every kind of FICA, paper or online):**
- **Who:** the requesting agent, an owner-role user, or anyone holding `manage_compliance` - the same gate as upload and link. The route needs `access_compliance` and the usual own / branch / agency scope (`authorizeAgency`). No new permission key.
- **When:** only in the stage-1 statuses `submitted`, `under_review`, `corrections_requested` (`FicaSubmission::STAGE_ONE_STATUSES`). From `agent_approved` on (RO queue, referred to the Compliance Officer, approved, rejected, cancelled) the action is refused (400) and no button is drawn. A wrong document found later goes back through "Request corrections", which reopens it to `corrections_requested`; the agent removes it, uploads the right one and resubmits.
- **What it does:** an uploaded document (`fica_documents`) is soft-deleted - archived, admin-recoverable, never a hard delete. A linked contact document (`fica_submission_documents` pivot) is only unlinked: the contact's own document stays on the contact's documents. The FICA status does not change.
- **Audit:** each action writes an append-only `fica_status_history` row (status from = to) with the actor, officer tier and `meta` = the document id, type and file name: `action = document_removed` (uploaded document) or `document_unlinked` (linked contact document).
- **Where:** a Remove link beside View on each document in the "Questions and answers" review (`partials/submitted-data.blade.php`: question-row documents and "Other documents") and Unlink on the "Linked contact documents" panel; the paper-intake block already had them. The screen passes `canRemoveDocs` to the partial, off by default, so the Compliance Officer screen (`compliance-review`) stays read-only.
- **Test:** `tests/Feature/Compliance/FicaDocumentRemoveTest.php`.

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

## FICA time windows are agency settings (2026-10-08, cc1 — cc2 cross-cut audit item; QA1 only)
Four literals in code are now per-agency settings, each defaulting to exactly the old value (columns on `agencies`, NULL = the default; read through `App\Services\Compliance\FicaWindows`, bounded): **FICA link stays valid** `fica_link_expiry_days` 14 (1–90; every place a FICA request / resend / corrections / e-sign-created link is minted); **an approved FICA counts as current** `fica_current_months` 11 (1–60; `Contact::ficaStatus()` "expiring" and the rental-application FICA bucket); **an approval is valid for** `fica_validity_months` 24 (1–120; `fica_expires_at` stamped on compliance approval); **"expiring soon" looks ahead** `fica_expiring_soon_days` 60 (1–365; `FicaSubmission::expiringSoon()` when no window is passed). Settings → Profile & Account → *FICA time windows* (saver `FicaOfficerAppointmentsController::saveWindowSettings`, permission `manage_compliance_officer`, has()-guarded per field, blank = default), the Setup Wizard's Compliance step (4 number controls + that saver; rule #10a), and Settings search keywords. Note: the old `FicaGate` doc comment's "12 months" is not enforced anywhere — the stamp was and is 24 months. Tests: `FicaWindowsTest` (11).

## Public FICA uploads take their agency from the submission (13 Oct 2026, demo-prep fix)
An applicant opens the FICA link logged out, so `BelongsToAgency` has no user to stamp `agency_id` from. `FicaPublicController::uploadDocument()` therefore writes `fica_documents.agency_id` from the submission the token resolves to - never a default, never a default. Any other public/token path that creates an agency-owned record must do the same. Proven in `tests/Feature/Rentals/DemoPrepFixesTest.php`.

## RMCP, policy and register "PDF" buttons are real PDF downloads (13 Oct 2026, cc2; Johan's bug report, Staging `/corex/compliance/rmcp-manager/1/pdf`)

**The fault.** `RmcpController::downloadPdf` and `PolicyController::downloadPdf` returned the on-screen viewer view with an unused `pdfMode` flag; a comment said "Puppeteer handles PDF conversion server-side" but nothing did (merged 2 Aug 2026, `bd5204ac0`; Staging and QA1 identical). The two staff-acknowledgement register exports (`compliance.rmcp.dashboard.report`, `compliance.policy.dashboard.report`, URLs ending `/report.pdf`) did the same — an HTML page. The buttons also opened a new tab (`target=_blank`), so nothing downloaded and a second copy of the app screen appeared. No error is logged (a 200 HTML response), so nothing showed in `laravel.log`.

**The rule now.** Every compliance "PDF" / "Export report" control returns `application/pdf` with `Content-Disposition: attachment`, printed by `App\Services\Compliance\CompliancePdf` (headless Chromium via `scripts/html-to-pdf.mjs`, footer "<label> · Page x of y" on every page). The document is a standalone print view (`compliance/pdf/version.blade.php`: cover with the agency logo/name/colour, version, status line, contents, every section, approval block) — never the app layout. If the PDF cannot be created the user is sent back to the screen with a plain message; the HTML page is never returned in its place.
- **Files named** `rmcp-v<n>-<agency>-<approved|draft|superseded>-<date>.pdf`; policies `<policy-name>-v<n>-<agency>-…pdf`; registers `rmcp-compliance-register-<agency>-<date>.pdf` / `<policy-key>-acknowledgement-register-<agency>-<date>.pdf`.
- **Scoping unchanged:** route middleware `access_rmcp` / `access_policy` / `access_compliance_dashboard`, the in-controller permission check, and `BelongsToAgency` on the version (another agency's id is a 404).
- **Same-cause check (all compliance PDFs):** FICA completion certificate, FICA questions & answers, RMCP and policy acknowledgement receipts and the PPRA pack already print a real PDF — fine. Agency documents are stored-file downloads — fine. Fixed here: RMCP version, policy version, RMCP register, policy register.
- **Reported, not changed:** `rmcp-ack/receipt-print` and `policy-ack/receipt-print` style with `var(--…)` CSS variables that do not exist in a standalone print file (header colours may not render) — a styling question for those receipts, different cause.

**Tests.** `CompliancePdfDownloadTest` (5): RMCP PDF is a real named download for an active AND a draft version, with agency name, version, approval line, every section, page numbers · another agency's version 404, no permission refused · printer failure → redirect with message, never HTML · print document is standalone (no app CSS variables, links or sidebar) · policy PDF named after the policy, cross-agency 404 · both registers are PDFs. PDF-rendering tests need Node + Puppeteer + Chromium and skip without them.


## A rental is not a sale - the marketing-compliance panel (13 Oct 2026, cc1; Sherry walk)

`/corex/properties/{id}` > Compliance Status demanded "Disclosure" and "Verify seller FICA" for a LANDLORD on a rental and blocked marketing until both were green. `MarketingReadinessService` evaluated the agency's required-document list as one list and spoke of sellers throughout.
**Rule for a rental mandate (listing type rental):**
- **Kept:** the mandate (the letting mandate), **FICA on the landlord** (FICA applies to every client and party - buyer, seller, landlord, tenant; the landlord is the client who mandates the letting), the photos, the property details. Everything else the agency has ticked as required stays required.
- **Dropped:** the **Mandatory Disclosure Form** (`document_types.slug = 'disclosure'`). It is the seller's statement of the condition of a property being SOLD; a letting has no equivalent in the rules CoreX applies. The list is `config/corex-compliance.php` -> `rental_not_required_slugs` (one place; an agency's own configuration of what it requires is untouched, a rental simply skips these).
- **Wording:** the panel, the next-action buttons and the Drive-tab checklist say "landlord" for a rental ("All landlords FICA approved", "Verify landlord FICA", "Link a landlord", "landlord-level compliance") and "seller" for a sale, through one helper (`partyWord`). The gate and the Drive-tab checklist read the same `evaluateRequiredTypes()`, so they cannot disagree. A sale is unchanged.
Tests: `MarketingReadinessDriveGateTest` (3 new: rental not blocked on disclosure and says landlord; the same agency's sale still needs it; a rental with no landlord is told to link one).

### FICA form payment guidance names the AGENCY's trust account, never one agency's (14 Oct 2026, QA1 - re-walk fault)
The payment and cash hints on the tenant / landlord FICA form (`resources/views/fica/form.blade.php`) said "paid via EFT to the Reos trust account" - one agency's name hardcoded in client wording. The name is now the agency's own setting `agencies.rental_trust_account_name` (varchar 100, NULL = not set): Settings -> Profile & Account -> FICA (beside the time windows) and the Setup Wizard's Compliance step, one saver (`FicaOfficerAppointmentsController@saveWindowSettings`, `has()`-guarded, blank clears). `App\Services\Compliance\FicaTrustAccount::phraseFor($agencyId)` returns "the <name>" (a typed leading "the" is not doubled) or the neutral "the agency's trust account"; `FicaPublicController::form()` hands it to the page as `$trustPhrase`. No existing agency bank-details field fits (the only bank columns are the payroll `employer_bank_*`), so none was reused. Tests: `tests/Feature/Onboarding/FicaTrustAccountSettingTest.php` (a second agency's form, neutral wording, wizard + settings saver). **Found, not changed (client-facing, HFC- or reOS-specific wording baked into DocuPerfect document templates - Johan's call, they are legal text):** the commercial lease template's Payments clause names "reOS" and describes reOS's own invoice/reference emails (`web-templates/commercial-lease-agreement-v5.blade.php:1180-1188` and its imported copy); several imported letting-mandate / lease templates carry "Home Finders Coastal" in their titles and text; `seller-outreach/_compose-form.blade.php:11` has an "On HFC books" label (staff-side).

### The public FICA form opens filled in for a known person (14 Oct 2026, QA1 - cc3's new-agency walk; tests `FicaFormPrefillTest`)

The tenant/landlord FICA form used to open empty. It now opens with what the contact record holds - full name, ID (or passport number), residential address (the free-text address, else the structured one), phone, email - and "Natural Person" selected; SA citizen is set from the ID (an SA ID, or a 13-digit number with no recorded type, is "yes"; a passport is "no"; anything else is left for them to answer). Every field is an ordinary editable input and nothing is stored until they sign and submit, so a wrong value is simply corrected. A company/trust/entity contact is not a person: its own name is not offered as "the person completing the form" and the entity type is left to choose - only its phone, email and address carry. `App\Services\Compliance\FicaFormPrefill`. The data comes only from the submission's own contact, so one person's link never shows another's details.

**ID / passport number is masked (14 Oct 2026, Johan's ruling).** The pre-filled number never reaches the page in full: the box shows `****` + the last four characters (the convention the e-sign consent page already uses; four characters or fewer show `****`), read-only, with an **Edit** button that clears it so the person types the full number. Untouched, the form posts the masked text plus `personal[id_number_keep]=1` and the server (`FicaFormPrefill::keptIdentityFor`) keeps the contact's stored number - only when the posted text is exactly that number's masked form, so a tampered or another person's value is never swapped for a real one. A masked value without the keep flag is refused (`not_regex /\*/`), so a mask can never be stored. A passport number (shown in the same box) is treated identically. Company/registration numbers are not pre-filled at all, so there is nothing of them to mask. Tests in `FicaFormPrefillTest`.


## RMCP v2 loaded from the approved text (AT-506, 22 Oct 2026; Johan + Elize approved the v2 text)

**What it does.** The approved RMCP v2 (31 sections, Schedules 1-8, Annexures A and B, the Approval block) is a versioned master text, `database/data/rmcp/v2.md`. `php artisan rmcp:import-version <agency> --apply` loads it into an agency as a NEW **draft** version 2 (`rmcp_versions` + 39 `rmcp_sections`, v2's own numbering and order). Nothing else changes: the active version stays active until the draft is approved through `RmcpController::approve` (which sets `approved_at` / `effective_from` and supersedes it).

**Importer rules** (`App\Services\Compliance\RmcpVersionImporter`, command `App\Console\Commands\Compliance\RmcpImportVersion`):
- Dry run is the default; `--apply` writes. It prints the section list, every merge field and what it is for that agency now (`*** BLANK ***` where the agency has nothing), and refuses unknown merge fields.
- Idempotent: if the agency already has that version number it changes nothing and says so. It never edits or deletes an existing version. No hard deletes.
- `--var=key=value` (repeatable) sets an RMCP variable only when the agency has none yet — never overwrites what the agency set.
- Staff-acknowledgement steps follow v1: sections 2+, Schedule 8 and Annexure A require acknowledgement; definitions, other schedules and the Approval block do not. Annexure B is the declaration (`section_type = acknowledgement`) the e-sign step shows. Prompts are generated ("I have read and understood section N (title).").
- The text is the agency-neutral master: every per-agency fact is a `{{token}}`. Other agencies edit their own draft after import (the s28 sentence "the Company has no branches or subsidiaries outside South Africa" is the approved HFC wording and is not a token).

**New merge fields** (`RmcpVariableResolver`; any manual `rmcp_variables` row with the same key wins, as before):
- `agency.ppra_number` (agencies.ppra_number), `agency.legal_name` (defaults to the agency name; HFC has a manual row), `rmcp.approved_by_name` (the approving user; blank on a draft), `fic.directive12_submitted_on` (a blank line until the FIC tracker, AT-505, records it).
- `agency.branches_statement` / `agency.branches_and_responsible`: from the agency's live branches that have a physical address ("one office, X, to which all agents are attached" / "N offices …"; falls back to the agency address when no branch has one), plus the manual `agency.subsidiaries_statement`. Branch managers attached to each branch are named; a blank line where there is none.
- `agency.area_of_operation`: manual, per agency.
- Business profile, worked out at render time per agency: `profile.avg_sales_per_year` ("N registrations in the last 12 months" — `deals_v2` with `actual_registration` in the last 12 months, cancelled/declined excluded), `profile.active_leases`, `profile.avg_monthly_rent` (`leases` where status = active; "R 9,985"). An agency that manages leases in another system states the figure with a manual override of the same key.
- **Stating the offices (Johan's ruling, 22 Oct).** The offices sentence and the section-28 responsible-persons line come from the branch records by default; an agency whose branch records do not describe its real offices states them with the variables `agency.branches_statement` and `agency.branches_and_responsible` (a stated value wins). A stated value may refer to other fields, e.g. `Shelly Beach: {{compliance_officer.full_name}} (Compliance Officer)`, so it keeps following the Compliance Officer on file (one pass, no nesting). `rmcp:import-version --var=…` also fills variables when the version already exists (never overwrites a value the agency set).
- **Variables screen** (`/corex/compliance/rmcp-manager/variables`): an admin can ADD a variable key (`POST …/variables`, `group.name`, lowercase), which also creates an override of a computed one. Computed values show as "Computed" and become editable once their override row exists.

**Printing.** The existing RMCP PDF (`RmcpController::downloadPdf`, `CompliancePdf`) prints the version as A4 with a footer on every page: "<agency> · RMCP v2 · page x of y · Initials ____" (`PDF_FOOTER_PAGE_WORD` / `PDF_FOOTER_SUFFIX` in `scripts/html-to-pdf.mjs`, opt-in; every other PDF is unchanged). The cover carries "FIC registration number … · PPRA FFC …". A version whose text has its own Approval block does not get a second, generated one (screen or PDF).

**Staff re-sign** (`App\Services\Compliance\RmcpReSignService`, called by `approve`): the acknowledgements staff signed on earlier versions become `superseded` (kept, never deleted); every active user of the agency gets an in-app notification (`rmcp.version_approved`, in-app only, registered by migration `2026_11_28_100000` and `NotificationEventTypeSeeder`). The existing wizard already asks everyone to sign the active version, so v2 starts "not started" for everyone.

**Reported, not changed:** the CO's name on the appointment row reads "Elize Reicel"; `agencies.trading_name` holds "Johan and Elize Properties T/A" (the legal-name half). Both print in the RMCP; Johan corrects them in the company profile.

**Tests:** `tests/Feature/Compliance/RmcpVersion2LoadTest.php` (13), `CompliancePdfDownloadTest` (footer assertion updated).

## RMCP board approval letter (27 Oct 2026, QA1; Johan)

**What it does.** The board no longer has to print, sign and upload a PDF. CoreX writes the board resolution for a DRAFT RMCP version (FIC Act s42(2A): the agency's legal name and registration number, the version number, the date it takes effect, the review date, and an RMCP fingerprint), emails each board member a secure link, and each signs it with the same Type/Draw signature pad staff use. When everyone has signed, CoreX files a signed PDF, links it to the version, and Approve RMCP uses it. The old way (upload a signed PDF) still works.

**Pillars.** Agent (board members who are users; the approver), Contact-style recipients (board members outside CoreX), RMCP version. Reads the agency profile (`RmcpVariableResolver`); writes the Approval block back through the same resolver.

**Where it lives (navigation).**
- **Company Settings > Compliance tab** (`/corex/admin/company-settings#compliance`, shown to `manage_rmcp_board_approval`): the **Board / senior management** list.
- **Compliance > RMCP & Policies > RMCP > open a version > "Board approval" panel** (`compliance.rmcp.show`, `#board-approval`): send, track, resend, cancel, open the signed letter. Shown to holders of `manage_rmcp_board_approval` or `approve_rmcp`.
- **Approve RMCP screen** (`compliance.rmcp.approve.form`) links to the panel and offers "Approve RMCP with the signed board letter".
- A board member who is a CoreX user also gets "Sign the letter" on the panel (`compliance.rmcp.board.sign`).
- Emailed members open `/rmcp-board-approval/{token}` (public, no sign-in).

**Permission.** New key `manage_rmcp_board_approval` (section Compliance, module rmcp): edit the board list, send/resend/cancel the letter, re-create the PDF. Owner-role users hold it automatically; any other role is granted it in Role Manager. `approve_rmcp` is unchanged (Approve) and may also open the panel and download the signed letter. `access_rmcp` alone sees the RMCP exactly as before (no panel).

**Data.** `agency_board_members` (name, title, email, optional `user_id`, sort order; soft delete; cap 20). `rmcp_board_letters` (one row per send = a numbered round: frozen wording `letter_html`, effective/review dates, `content_hash` of the RMCP text, status `sent|complete|cancelled|superseded`, signed PDF path + sha256). `rmcp_board_signatures` (one per member per round: copied name/title/email, 40-char token, status `pending|sent|viewed|signed`, dates, send count, signature image or typed name, `signed_via` link|in_app, IP, user agent). `rmcp_board_audit_log` (append-only). `rmcp_versions.board_letter_id` = the signed letter it was approved with. All soft-delete / append-only: nothing is ever hard-deleted.

**Rules.**
1. **Default list.** First time an agency opens the list (or sends a letter) it is started from the agency's active users holding an owner-flagged role; when the agency has none (owner roles are the platform owner's and sit outside agencies - true for HFC) it falls back to the agency's active `admin` users. Titles come from the user's designation (else "Director"). Never re-seeds once the agency has ever had a row, archived ones included. It is only a starting point: the admin edits it, and nothing is sent until someone presses Send.
2. **Send** (draft only): needs at least one member and a valid email on every member; asks for the effective date (not in the past) and review date (after it; default one year). A new send supersedes the open round (kept) and creates the next round. Wording and fingerprint are frozen at send.
3. **Link + code (same pattern as the DR2 secure document links).** The email carries only the link. Opening it shows nothing of the letter until the member asks for a six-digit code, sent by `OtpService` to the address on their signature line (never one typed on the page), and enters it (throttled 5/min, single use, 10 minutes). Unknown, cancelled or replaced links give the app's standard "isn't valid any more" page (410). The email goes out as the person who pressed Send, through their own mailbox where they have one (`ComplianceMailDispatcher`).
4. **Signing.** Same Type/Draw pad as the staff acknowledgement; a ticked "I have read the letter" box. A drawn signature must be a real PNG. The image is stored on the private disk, timestamped, never overwritten; a signed line is never changed (a second attempt is refused). A board member who is a CoreX user may sign in-app from the panel with no code (they are already signed in); only the user on the open line can.
5. **Status per member** (Not sent yet / Sent / Opened / Signed, with dates), **Send again** per member, **Cancel this letter** (round kept, link closed). "Opened" means the member passed the code and the letter was shown.
6. **Complete.** When the last member signs the round becomes `complete` and the signed letter PDF (resolution + one signature line per member with image or typed name and the time signed) is filed as a new timestamped file. If the printer fails the round stays complete without a PDF and the panel offers "Create the PDF again" (each press makes a new file; the older one is kept).
7. **Approve gate.** `RmcpController::approve` has two explicit paths. (a) Button "Approve RMCP with the signed board letter" (`use_board_letter=1`): allowed only when the newest round is complete, has its PDF, and its `content_hash` equals the RMCP text now - otherwise nothing is approved and the user is sent to the panel with the plain reason (still waiting for X / the RMCP was changed after the board signed / the PDF is not made yet). Dates come from the letter (the board resolved them; the "not in the past" rule applied when the letter was sent). (b) The uploaded signed PDF as before (title, file, dates). With neither, the screen says so and links to the panel. Both paths write an `approved` audit row; (a) also sets `board_letter_id` and stores the letter PDF as the board approval document.
8. **Approval block.** New merge field `rmcp.approved_by_title`; `rmcp.approved_by_name`, `rmcp.approved_by_title` and `rmcp.approved_on` read the signing members' names, titles and the date the last one signed, from the version's linked letter (approved) or from the signed letter ready for Approve (draft); without a letter they keep their old meaning (the approving user, the title typed at Approve, the approval date). The Approval table's Title row in the RMCP master text (`database/data/rmcp/v2.md`) is now `{{rmcp.approved_by_title}}` instead of the fixed word "Principal"; migration `2026_12_12_100100` makes the same change to DRAFT versions only (approved versions are records and are untouched). Signature cell is left for the signed letter itself.
9. **Audit** (`rmcp_board_audit_log`): sent, resent, superseded, cancelled, code_sent, code_verified, code_failed, viewed, signed, letter_completed, pdf_filed, approved. The panel's "Activity" shows the latest 12.

**Search / sort / pagination on the board list.** Deliberately none: the list is capped at 20 people, ordered by its Order number then id; archived members are shown on request ("Show archived") and can be restored.

**Wording is not a setting.** The resolution text is one fixed, statute-driven wording (s42(2A)) with the agency's name, registration number, version and dates merged in; it has no HFC text. If an agency needs different wording that is a later, separate decision.

**Not in the Setup Wizard (non-negotiable 10a).** The board list is recorded in `agency-onboarding-setup.md` §5.1 as pending Johan's confirmation - it is a list of named people, not a switch, and is needed only when an agency approves an RMCP.

**Left for later / known limits.** The staff acknowledgement page still carries its own copy of the pad (the new letter uses a vanilla-JS twin of the same component so it also works on the public page); moving the staff page onto the shared partial is a separate change. A board member removed from the list after a letter was sent stays on that round (cancel and resend to change who signs). Policies (`PolicyVersion`) have the same upload-a-PDF approval and are not changed here.

**Tests:** `tests/Feature/Compliance/RmcpBoardApprovalTest.php`.
