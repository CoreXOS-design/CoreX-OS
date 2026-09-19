# Rental Applications module — production promotion audit

Branch `Prod` @ `57407d5a5` (diff base `6545f0262`). Read-only audit, 2026-09-16.
Scope: AT-392 Rental Applications (Johan / QA1 -> Staging 2026-09-15 -> Prod).
Method: full read of the public signing controller, all five agent-side controllers and the two concerns, the model + all 18 child models, mailer/notifier/PDF/highlight services, the queued job, the FICA listener, AppServiceProvider limiter + event registrations, routes, permissions config, sidebar, onboarding-copy config, the relevant spec passages and the migrations named below. No tests were run; no files were changed.

CONFIRMED = full code path read. PLAUSIBLE = logic confirmed but depends on prod data/host state I cannot see from here.

---

## Findings (ranked)

### BLOCKER
None found. Nothing in this module should 500 on first request on prod, provided the deploy prerequisites in section 4 are met.

### HIGH

**H1. Every rental e-mail is sent synchronously inside the request — none is queued.** CONFIRMED.
- `app/Services/RentalApplications/RentalApplicationMailer.php:32,61,84,118,155` and `app/Services/RentalApplications/RentalApplicationNotifier.php:31,57` all call `Mail::to(...)->send(...)`. None of `app/Mail/RentalApplication{Invite,Reopened,MoreInfoRequest,Decline,Approved,Decision,Returned}Mail.php` implements `ShouldQueue` (`Mail::send()` on a non-queued mailable is synchronous SMTP).
- Root cause: the mailer was written "best-effort, swallow and log" instead of "queue and retry".
- Failure scenario: the applicant's public `POST /rental-application/{token}/submit` (`RentalApplicationSigningController::releaseSubmissionToAgency()` -> `notifyAgentOfReturn()`) blocks on SMTP inside the applicant's request. During the documented 08:30–09:00 SMTP contention window the request can exceed the FPM timeout: the submission is already committed (good), but the applicant never gets the FICA redirect and the agent notification is swallowed with only a `Log::warning` — no retry, no visible failure. Agent-side `send()` / `sendDecline()` / `reopen()` / `requestMoreInfoFromApplicant()` block the agent's browser on SMTP the same way. This is the exact class the CLAUDE.md rule "request-triggered mail must be queued" exists for.
- Also: all of these use the DEFAULT mailer. On staging (`MAIL_MAILER=log`) invite/reopen/decline/approval/agent-notification mails never leave the box; only the OTP gate mails (`OtpService` uses the dedicated `otp` mailer, `app/Services/Otp/OtpService.php:192`) deliver. Any "the applicant never got the link" report from staging is expected behaviour, not a bug.

**H2. Public document upload ignores both applicant gates and lets any link-holder write to the root volume without limit per token.** CONFIRMED.
- `app/Http/Controllers/RentalApplicationSigningController.php:1002-1075` (`uploadDocuments()`): checks token expiry, `documentUploadsOpen()` and `status !== 'draft'`, then files up to 10 files x 15 MB per request into `storage/app/private/rental-applications/{id}/documents`, links them to the contact (`$document->contacts()->syncWithoutDetaching`) and property, and flips `sent -> in_progress`. It never calls `returnGatePassed()` or `identityGateAwaiting()`, unlike `show()`, `autosave()`, `submit()`, `pdf()` and `viewDocument()`.
- The spec (`.ai/specs/rental-applications.md:921-925`) only records `uploadDocuments()` as exempt from the *submission lock* (`assertDocumentsNotLocked`), written before the Return Gate existed; the Return Gate test file (`tests/Feature/RentalApplications/RentalApplicationReturnGateTest.php`) gates show/pdf/document-view and never asserts anything about upload. So the exemption from the gate is an omission, not a recorded decision.
- Failure scenario: a forwarded or leaked applicant link (before or after submission) lets a stranger attach arbitrary PDFs/images to the applicant's contact record — they surface on the agent's review screen as "From applicant". Capacity: the `rental-application-documents` limiter is keyed on the token only (`AppServiceProvider.php:1011-1018`, defaults 60 req / 10 min, agency-configurable) so one link allows ~9 GB per 10 minutes onto the ROOT volume (`disk => 'local'`, not `data_volume`) — the volume Johan's disk-hygiene rule says must never grow unbounded. Requires possession of a valid 64-char token, so this is abuse-of-link, not enumeration.

### MEDIUM

**M1. The "identity gate" verifies the applicant against values the applicant typed moments earlier, not against agency-held data.** CONFIRMED (design).
- `RentalApplicationSigningController.php:243-250` (`identityGateChannelFor()`): channel = email OTP to `recipientEmail()` if set, else ID-number match. `recipientEmail()` (`app/Models/RentalApplication.php:805`) is `$this->email ?: contact->email`, and both `email` and `id_number` are in `fieldValidationRules()` — i.e. writable by the applicant through `autosave()` and `submit()` in the same session, overwriting the agent's prefill from the contact.
- Consequence for Johan: with the ID channel the gate asks the applicant to re-type the ID number they entered on the previous screen; with the e-mail channel it proves control of whatever inbox they typed. Neither compares to what the agency has on file. It adds friction, not identity assurance. The Return Gate (post-submission, third-party-holding-a-link threat) is still meaningful; this first-submission gate is not.

**M2. Rule 10a — none of the module's ~35 settings are in the Setup Wizard, and only three are recorded as deliberately excluded.** CONFIRMED.
- `config/agency-onboarding-copy.php` contains zero rental-application entries (only the unrelated AT-402 `rental_fee_max_amount`).
- Recorded exclusions: `reopen_link_expiry_days` (`.ai/specs/rental-applications.md:4970-4982`), `lock_property_after_submission` and `tag_contact_as_tenant_on_approval` (`.ai/specs/agency-onboarding-setup.md §5.1:286-302`, explicitly "Pending Johan's confirmation").
- NOT recorded, not in wizard: qualifying formula %, RO/CO tiers, decline e-mail wording, document checklist per employment type, document validity windows, approval-email max properties, autosave debounce + two autosave limits, document/show/pdf/document-view/submit rate limits (10 numbers), uploads-open-after-approval, require-FICA-before-authorisation, required fields, marital-status options, return-gate method/attempts, identity-gate enabled/length/expiry/cooldown/attempts, highlighter collection, decline-reason templates. The spec itself says this is an open gap "reported to the coordinator, not fixed" (`:4978-4982`, `:7053`, onboarding-setup `:293-295`). This is Johan's call per the rule; it has not been made.

**M3. `data_volume` disk root is a Hetzner mount path that prod almost certainly does not have; PDF cache degrades silently.** CONFIRMED (code) / PLAUSIBLE (host).
- `config/filesystems.php:73-79` (new in this promotion): root `env('DATA_VOLUME_ROOT', '/mnt/HC_Volume_103099143') . '/corex-data-volume'`. `DATA_VOLUME_ROOT` is not in `.env.example`. Prod is 62.238.31.82, not the Hetzner box.
- Laravel builds the local adapter non-lazily (`vendor/laravel/framework/.../FilesystemManager.php:185`, `LocalFilesystemAdapter` ctor line 90 -> `ensureRootDirectoryExists()`), so `Storage::disk('data_volume')` throws `UnableToCreateDirectory` on a missing mount. `RentalApplicationPdfService::tryServeFromCache()/tryStoreInCache()/forgetCacheFor()` (`:163-231`) catch it and `Log::warning` — so no 500, but the sealed-generation PDF cache is permanently off: every open of a submitted application's PDF re-runs headless Chromium (~9 s per the job's own docblock) and writes a warning to `laravel.log`.
- Only consumer: `app/Services/RentalApplications/RentalApplicationPdfService.php:51`.

**M4. Authoriser (RO/CO) visibility silently depends on the `rental_applications.view` scope — a Reviewer without it sees an empty queue and 403s on every application.** CONFIRMED logic / PLAUSIBLE for prod role data.
- `RentalApplicationAuthorisationController::index():178-181` filters with `->visibleTo($user)`; `guardCanView():136-150` and `guardCanDecide():102-134` call `guardRentalApplication()` (`app/Http/Controllers/Concerns/AuthorizesRentalApplicationAccess.php:20-48`) which reads `PermissionService::getDataScope($user, 'rental_applications')` = stored scope on `rental_applications.view` (`PermissionService.php:243-272`). No grant -> `null` -> `clampScope()` -> `'own'`. Because self-approval is blocked, an RO/CO scoped 'own' can act on nothing.
- The sidebar shows "Rental Application Authorisation" on tier membership alone (`corex-sidebar.blade.php:1088`), so the link appears and the page is empty. Migration `2026_09_10_070000_restore_agency_1_admin_rental_applications_view_scope.php` (hard-codes `agency_id = 1`, sets admin scope 'all') suggests this already bit QA1. On prod, whichever agency has id 1 gets that change.
- What Johan should check: log in as a configured Reviewer who is not an admin, open Rentals -> Rental Application Authorisation, confirm the queue is not empty.

**M5. One real hard delete: document validity-window overrides are deleted and re-inserted on every save.** CONFIRMED.
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php:859-861` `RentalApplicationDocumentValidityWindow::where(...)->whereNotNull('document_type_id')->delete()`; the model (`app/Models/RentalApplicationDocumentValidityWindow.php`) has no `SoftDeletes`. Non-negotiable #1 says no exceptions. Every other `->delete()` in the module (application, highlighter, template, document, marks, income/expense items, document requirements) lands on a `SoftDeletes` model — verified. Models without `SoftDeletes` but also without any delete path: Assessment, QualifyingSetting, ChecklistConfig, Generation, StatusHistory, ApprovalEmailSetting, DeclineEmailSetting.

**M6. Non-negotiable #7 — ~25 JSON endpoints live under page URIs, none under `/api/v1`, none in the Admin -> API catalog.** CONFIRMED.
- Agent side (`routes/web.php:2955-2978, 2992-2994, 3042, 3051-3068, 3077-3078, 3083, 3086`): highlight-data/first, highlight-data/remaining, highlight, capture-entries (create/update/delete/strike/manual), assessment income/expense items + strike, review/assessment, submit-for-approval, reopen, attach-existing, file-direct, retype, search-properties, contacts/quick-create. Public: `/rental-application/{token}/autosave` and the `wantsJson()` branches of documents upload/remove/replace.
- All routes ARE named (checked every rental route line; the settings routes' `->name()` on the following line is fine). This is a catalogue/discoverability rule breach, not a security one.

**M7. Settings routes have no `agency.required`; an owner-role user with no agency switched saves nothing or 500s.** CONFIRMED logic / PLAUSIBLE in practice.
- `routes/web.php:2837-2928` carry only `permission:rental_applications.manage_settings` inside the `auth,verified` group; `/corex/settings` itself has `agency.required` (`:2821`). Every writer uses `$request->user()->effectiveAgencyId()` unguarded: `updateRO()/updateCO()` do `Agency::whereKey(null)->update()` (no-op, then flashes "Reviewers saved."); `updateQualifyingFormula()/updateDeclineEmail()` do `updateOrCreate(['agency_id' => null])` -> `BelongsToAgency` honours the explicit null -> `agency_id` is NOT NULL on both tables (`2026_09_07_150001:22`, `2026_09_08_130000:26`) -> SQL 1048 -> 500. Only affects System Owner accounts browsing without the switcher.

### LOW

**L1. Public rate limiters are token-only with no IP fallback.** CONFIRMED. `AppServiceProvider.php:1011-1185` key every limiter on the raw `{token}` path segment; an unknown token gets its own bucket, so per-IP request volume against the public routes is unbounded (2 DB lookups per hit — limiter + controller). Token is `Str::random(64)` with a uniqueness loop (`RentalApplicationController.php:1495-1500`), ~380 bits, so enumeration is infeasible; this is a DoS-shape note only. Comparison is a DB `where('token', ...)` — fine for a random opaque token; ID-number comparison uses `hash_equals` (`SigningController.php:114-120`).

**L2. `pdf()` on the public route does not check `token_expires_at`.** CONFIRMED. `RentalApplicationSigningController.php:1146-1170` checks the return gate and identity gate only. Decline (`AuthorisationController.php:484`) and withdrawal (`RentalApplicationController.php:1050`) "kill the link" by setting `token_expires_at = now()`; a session that had already passed the gate can still download the PDF afterwards. A fresh session is redirected to `show()`, which does enforce expiry.

**L3. `FicaSubmission::create()` in the public submit path is not wrapped in `withoutAgencyStamping()`.** CONFIRMED. `SigningController.php:1118-1126` and `RentalApplicationMailer.php:202-210` pass an explicit `agency_id` but `BelongsToAgency::creating` overrides it with the acting user's agency whenever an authenticated non-owner (or an owner switched into another agency) is the one submitting — the exact "agent testing their own link while logged in" case the same file guards for `Document` and `RentalApplicationSignature`. Wrong-agency FICA row in that edge case only.

**L4. `RecomputeRentalApplicationStatus` uses `withoutGlobalScopes()`, which also drops `SoftDeletes`.** CONFIRMED. `app/Listeners/Contact/RecomputeRentalApplicationStatus.php:62` — an archived application can be the "latest" row that sets the contact's cached `rental_application_status`.

**L5. Temp PDFs are not cleaned up on the filing path.** PLAUSIBLE. `RentalApplicationPdfService::fileAsDocument():121` copies `$path` (a `storage/app/temp/doc_*.pdf` from Puppeteer, or a cache temp copy from `tryServeFromCache()`) and never unlinks it; the download paths use `deleteFileAfterSend(true)`, this one does not. No scheduled sweep of `storage/app/temp` found in `routes/console.php`/`app/Console`. One leaked PDF per decision/approval on the root volume.

**L6. Hard-coded agency id in a data migration.** CONFIRMED. `database/migrations/2026_09_10_070000_restore_agency_1_admin_rental_applications_view_scope.php` updates `role_permissions` for `agency_id = 1` unconditionally. Written for QA1's data; on prod it changes whichever agency is id 1.

**L7. Return-gate resend always reports success.** CONFIRMED and acknowledged in code (`SigningController.php:517-531`): `resendGateOtp()` flashes "A new code has been sent." even when `OtpService::throttle()` swallowed it. The identity-gate resend (`:441-460`) already tells the truth; the return gate's does not.

**L8. Unthrottled public GET/POSTs.** CONFIRMED. `GET /{token}/verify-identity`, `POST /{token}/gate/resend-otp`, `POST /{token}/verify-identity/resend-otp` (`routes/web.php:5094, 5101, 5103`) have no `throttle:` middleware. OTP sends are capped by `OtpService::throttle()` (60 s cooldown, 5/hour per e-mail — `OtpService.php:154-172`), so the exposure is DB load, not inbox spam.

### Verified OK (no finding)
- Token: `Str::random(64)`, uniqueness-checked across agencies, expiry enforced on show / verify-gate / identity gate / autosave / submit / upload / view / remove / replace. ID-number gate uses digit-normalised `hash_equals`. OTP hashed at rest by `OtpService`.
- Public mass-assignment: `autosave()` fills only `array_keys(fieldValidationRules())`; `submit()` fills `validated()` minus signatures; `submissionValidationRules()` derives from the same key set. `status`, `token`, `agency_id`, `identity_verified_at` are unreachable from the public POSTs. Signatures are validated well-formed before decode.
- File uploads: `mimes:pdf,jpg,jpeg,png,doc,docx` (content-sniffed), 15 MB, max 10 per request; `store()` generates the filename (no traversal); `{document}` is re-scoped to the token's own application (`scopedDocument()`, 404 not 403).
- Tenant scoping: `RentalApplication` and all 18 child models use `BelongsToAgency`; every agent-side action loading a `RentalApplication` calls `guardRentalApplication()` (own/branch/agency) — checked all 60 public methods across the five controllers and both concerns. `{document}` bindings are agency-scoped by `Document`'s own trait plus `guardDocumentBelongsToApplication()`; `{item}` via `assessment->rental_application_id`; `{match}` via `contact_id` equality; `{highlighter}`/`{declineReasonTemplate}` via explicit agency checks; `restore()` paths use `withTrashed()` which keeps `AgencyScope`. `queryWithoutAgencyScope()` appears only where a public token must resolve cross-tenant; `withoutGlobalScopes()` only in listeners on contact-bound ids. Public child writes (Generation, StatusHistory, AuditLog, Signature, Document) all pass the application's `agency_id` explicitly (unauthenticated inserts on a 2-agency box are safe).
- Status enum: every write is one of the nine DB enum values (`2026_09_08_220000` widened the column); agent status endpoint is `Rule::in(AGENT_SETTABLE_STATUSES)`; withdrawn is exit-only via override-tier `reopen()`. Reopen bumps `current_generation` on resubmit; signatures are keyed `(application, agency, kind, generation)` so prior rounds are never overwritten; `RentalApplicationGeneration::seal()` is hash-chained.
- FICA listener `ResolveConditionalApprovalOnFicaVerified` (registered `AppServiceProvider.php:589`): looks up by `contact_id` (contacts are single-agency) and re-checks `status === 'approved'`; cannot fire for the wrong agency or contact. All four rental domain events and both onboarding seeders are explicitly registered (discovery is off).
- Permissions: five keys in `config/corex-permissions.php:109-120` + `contact_rental_history.view:130`; `manage_settings` deliberately admin-only; sidebar gated (`corex-sidebar.blade.php:1052-1089`); settings page link gated (`corex/settings.blade.php:104-106`); decline-templates page linked from the settings page (`rental-applications.blade.php:890`). Authorisation prefix group has no `permission:` middleware by design — every action gates on RO/CO tier in the controller (`guardCanView`/`guardCanDecide`/inline check in `requestMoreInfo`).
- Queue: `FileRentalApplicationDecisionPdfJob` has no `onQueue()` -> `default` queue, which live serves. `ShouldQueue`, `tries=2`, `timeout=60`.

---

## Route inventory (route -> gate -> record scoping)

Prefix `/corex` group = `auth`,`verified`. Public group = none.

| Route(s) | Middleware / permission | Record scoping |
|---|---|---|
| `/settings/rental-applications` GET+POST, `/qualifying-formula`, `/reopen-link-expiry`, `/autosave-debounce`, `/autosave-rate-limit`, `/document-rate-limit`, `/document-uploads-after-approval`, `/route-rate-limits`, `/require-fica-before-authorisation`, `/required-fields`, `/marital-status-options`, `/return-gate`, `/identity-gate`, `/property-lock`, `/tenant-tagging`, `/approval-email`, `/decline-email`, `/validity-windows`, `/ro`, `/co` | `permission:rental_applications.manage_settings` (no `agency.required` — M7) | writes keyed on `effectiveAgencyId()`; RO/CO ids re-validated against the agency's users |
| `/settings/rental-applications/highlighters` store / `{highlighter}` update, archive, restore / reorder | `manage_settings` | `authorizeAgency()` explicit agency compare; reorder `where agency_id` |
| `/settings/rental-applications/decline-reason-templates` index, store, `{id}` update, archive, restore | `manage_settings` | `where('agency_id')` + `guardOwnAgency()` (404) |
| `/rental-applications/authorisation` index | none on route; `abort_unless(isRO||isCO)` | `visibleTo($user)` (M4) |
| `.../authorisation/{ra}` show, documents view/highlighted-file, highlight-data first/remaining, highlight, capture-entries store/update/delete/strike, assessment income/expense store + strike | none on route; `guardCanView()` = tier + `guardRentalApplication()` | own/branch/agency + document/item ownership |
| `.../authorisation/{ra}` approve, decline, request-more-info | none on route; `guardCanDecide()` / inline tier check + `guardNotSelfApproving()` | own/branch/agency; decline template `where agency_id` |
| `/rental-applications` index, `/returned`, `{ra}` show, update, pdf, pdf-inline, documents/{doc} download | `permission:rental_applications.view` (+`view_returned` on /returned) | `visibleTo` / `guardRentalApplication()`; document `source_id` check |
| `/rental-applications` create, search-properties, contacts/quick-create, store, `{ra}` send, documents upload, attach-existing, status, link/unlink-tenant-property | `view` + `permission:rental_applications.create` | `ExistsInScope(Contact)`, `Property::findLinkableForRentalApplication()` (visibleTo), guard |
| `/rental-applications/{ra}` destroy, restore | `view` + `permission:rental_applications.archive` | guard; restore uses `withTrashed()` (AgencyScope retained) |
| `/rental-applications/{ra}/review` show, assessment, link-property, documents view/highlight-data/highlight/capture-entries/highlighted-file/referenced-download/file-direct/retype, request-more-info, submit-for-approval, reopen, wishlist add/update, send, send-decline, generations/{gen} | `permission:rental_applications.view` | `guardRentalApplication()` on every action; reopen of declined/withdrawn additionally `isRentalApplicationOverrideTier()` |
| `/tools/pdf-splitter/rental-applications/{ra}/...` (2) | `permission:access_pdf_splitter` | `guardRentalApplication()` per route comment (not re-read; outside module files) |
| PUBLIC `/rental-application/{token}` show | `throttle:rental-application-show` (token-keyed) | token; expiry; return gate; identity gate |
| `/{token}/verify-gate` | `throttle:rental-application-gate` | token; expiry; `hash_equals` / OTP |
| `/{token}/gate/resend-otp`, `/{token}/verify-identity` GET, `/{token}/verify-identity/resend-otp` | none (L8) | token; OtpService throttle |
| `/{token}/verify-identity` POST | `throttle:rental-application-identity-gate` | token; expiry; OTP / ID (M1) |
| `/{token}/autosave` | `throttle:rental-application-autosave-request` + in-controller per-application counter | token; expiry; return gate; `fieldValidationRules()` keys only |
| `/{token}/submit` | `throttle:rental-application-submit` | token; expiry; return gate; validated fields only; transaction |
| `/{token}/documents` POST | `throttle:rental-application-documents` | token; expiry; uploads-open; NO return/identity gate (H2) |
| `/{token}/pdf` | `throttle:rental-application-pdf` | token; return gate; identity gate; NO expiry (L2) |
| `/{token}/documents/{doc}` GET, `/remove`, `/replace` | document-view / documents limiters | token; expiry; gates (view); `scopedDocument()`; submission lock (remove/replace) |

All rental routes are named (verified line-by-line, including the two-line settings declarations).

---

## Deploy prerequisites for prod (62.238.31.82, `/corex`, php-fpm pool for live)

Env keys the code reads (none new are in `.env.example` except the splitter ones):
- `DATA_VOLUME_ROOT` — MUST be set to a real mounted volume on prod (the default `/mnt/HC_Volume_103099143` is the Hetzner box's mount). Create `<root>/corex-data-volume`, owned by the FPM user. Without it: M3 (cache off, one warning per PDF open, ~9 s Chromium render each time).
- `RENTAL_APPLICATIONS_PDF_RENDER_WORKERS` — optional, default 4 concurrent `pdftoppm` processes (`config/rental_applications.php`). Size to prod's core count.
- `SPLITTER_PDFTOPPM_PATH` (default `pdftoppm`) — also needs `pdfinfo` on PATH (`RentalApplicationDocumentHighlightService.php:925`, no config key for it). Both from poppler-utils.
- `PUPPETEER_BROWSER_PATH` / node — already required by e-sign's `SigningController::generatePdfFromHtml()` (`Docuperfect/SigningController.php:3062-3074`), which this module reuses. Verify per AT-169.
- `MAIL_MAILER` — must be a delivering mailer on prod; all rental mail uses the default mailer (H1). `otp` mailer must be configured for the two gates.
- `QUEUE_CONNECTION=database` — `FileRentalApplicationDecisionPdfJob` runs on `default`; restart the live worker group after deploy (trailing-colon gotcha).

Binaries / extensions on the live FPM pool AND CLI (the job renders PDFs from the worker):
- `pdftoppm`, `pdfinfo` (poppler-utils)
- `node` + Chromium for Puppeteer (existing)
- PHP `gd` — `imagecreatefrompng`, `imagecreatetruecolor`, `imagecreatefromstring` in `RentalApplicationDocumentHighlightService.php:306,468,979` (Imagick is explicitly not used). Confirm `php -m | grep gd` on the live pool per AT-169; a missing `gd` 500s every "View & Mark Up" open.

Writable paths (root volume):
- `storage/app/private/rental-applications/**` (documents, signatures, filed PDFs)
- `storage/app/private/rental-applications/document-highlights/cache/**` (rasterised pages, one PNG per page at `DPI`)
- `storage/app/temp/**` (Puppeteer HTML/PDF; L5 leak)

Migrations: 79 files in the promotion; three are data migrations that touch permissions/seed rows on prod: `2026_09_10_070000` (agency 1 admin scope — L6), `2026_09_12_100000` (copies `.create` grants to `.archive`, logs the list), `2026_09_09_060100` + `2026_09_15_090100` (seed highlighters / decline templates for every non-archived agency). Run `deploy:sync-reference-data` afterwards as usual; `document_types` seeded by `2026_09_04_150003`.

Post-deploy smoke for Johan (plain language): create an application, Send it, open the link in a private window, upload a file, sign and submit, confirm the FICA page appears, then re-open the link in a new private window and confirm you are asked for the ID number / e-mail code. Log in as a Reviewer and confirm the Authorisation queue shows the application (M4). Open the application's PDF twice and check `laravel.log` for "PDF cache" warnings (M3).
