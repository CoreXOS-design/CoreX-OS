# Audit — E-Sign + Communications work in 6545f0262..57407d5a5 (Prod promotion)

Prepared 2026-09-16, read-only. Repo `C:\Users\USER-PC\Documents\Projects\hfc-dash`, branch `Prod` at `57407d5a5`.
No test file was run: local MySQL is down (`SQLSTATE[HY000] [2002] connection refused` on `hfc_dash_test`), so every finding below is reasoned from the code path read end-to-end, not from a test run.

---

## 0. Range topology — read this first

| Fact | Evidence |
|---|---|
| `6545f0262` is an ancestor of `57407d5a5`; 694 non-merge commits, 601 files net. | `git merge-base --is-ancestor` → yes |
| `origin/Prod` is **already** at `57407d5a5`. | `git log -1 origin/Prod` → `57407d5a5 2026-09-16 fix` |
| The ~135 e-sign/communications commits in the range (AT-395 `75cf670ef`, false-Sent sweeps `7cc7b33de`/`b4f3bb76d`/`cb8b23158`, WhatsApp `a0280e415`/`5f03b1ff8`/`a2c92fa82`, identity/reauth, etc.) are **content-duplicates**: their file contents were already in the `6545f0262` tree via re-committed copies (e.g. `582ec0762` = same subject as `75cf670ef`; `git log 6545f0262 -- app/Services/Communications/PerMailboxMailTransportBuilder.php` lists it). | `git diff --stat 6545f0262 57407d5a5 -- app/Services/Communications/ app/Services/Docuperfect/ app/Mail/Signatures/ app/Models/Communications/` → **empty** |
| **Net e-sign/comms tree delta is small**: `ESignWizardController.php` (+24/−10, recipient→property link extracted to `ContactPropertyLinker`), `Models/Docuperfect/Document.php` (`branch()->withTrashed()`), `Models/Docuperfect/SignatureRequest.php` (`attestationIdentity()` non-persisted fallback), `Communications/CommunicationTriageController.php` (+filters/pagination), 4 views (restyle/filters), 1 new test `tests/Feature/Docuperfect/SigningView/RecipientPropertyLinkRelinkTest.php`, plus the rental-application signing/identity-gate controller + model + 3 migrations. | `git diff --name-only 6545f0262 57407d5a5 \| grep -iE "docuperfect\|esign\|sign\|communications\|mailbox\|Mail/"` |
| The 601-file net diff is dominated by AT-392 rental applications (92 migrations) and Core Matches. | `git diff --name-only … \| awk` breakdown |

Consequence: the AT-395 / signing-surface code audited in §1–§2 is **the same bytes on both ends of the range**. Findings against it are real findings about what is being promoted, but they are not regressions introduced by this range. They are labelled "pre-existing (unchanged in range)" where that applies.

---

## 1. Findings (ranked)

### F1 — HIGH — Recipient ID-verification gate is bypassable by POSTing straight to the completion endpoints — CONFIRMED, pre-existing (unchanged in range)

- **Where:** `app/Http/Controllers/Docuperfect/SigningController.php`
  - `completeWeb()` :1726–1745 — resolves by token, gates only on `isSigningBlocked()` and `STATUS_WAITING`; reads `$request->input('signatures', [])` and `initials` (≈:2032, :2047), bakes them via `CanonicalInkComposer::bakeInk()` (≈:2130–2170) and sets `SignatureRequest::STATUS_COMPLETED` (≈:2283).
  - `complete()` :2396–2410, `saveWebFields()` :1453, `saveFields()` :1372, `decline()` :3670, `chooseMethod()` :1192 — same shape, no verified-session check.
  - The ID gate lives only in `show()` :133–139 (`if (!session("signing_verified_{$token}")) redirect → gateway`). It is a UI redirect, not an authorisation check.
  - Contrast: `capture()` :1287, `uploadWetInk()` :2582, `flattenedPageImage()` :3704, `uploadSupportingDocuments()` :2684 **do** check `session("signing_verified_{$token}")`.
- **Route layer:** `routes/web.php` :5148–5216 — `Route::prefix('sign')` group has no auth/verification middleware; only `throttle:30,1` on `show` and `throttle:5,1` on `verify`.
- **Root cause:** verification state is stored in the session under a token-scoped key (good: recipient A's verified flag cannot be reused for recipient B because the key embeds B's token), but the write endpoints never read it.
- **Failure scenario:** anyone holding the link (forwarded invitation email, or — new in this range — the WhatsApp deep link from AT-385, `SigningWhatsAppLinkService.php:145–152`) POSTs `consented=1` plus a signature data-URL to `/sign/{token}/complete-web` and completes the recipient's turn without ever entering the ID/passport number. The certificate's "identity verified" evidence is absent (no `identity_verified` audit row), but the ceremony advances and the document is marked signed by that party.
- **Note on the pipeline gate:** `SigningController.php` is a gated file — any fix must ship with a diff in `tests/Feature/Docuperfect/SigningView/`.

### F2 — MEDIUM — E-sign invitation mail is sent synchronously inside the HTTP request (per-mailbox SMTP + IMAP append per recipient) — CONFIRMED, pre-existing pattern (unchanged in range)

- **Where:** `app/Services/Docuperfect/SignatureService.php`
  - `dispatchSigningMail()` :60–115 — `Mail::to($recipientEmail)->send($mail)` (Situation A) or `$this->mailTransportBuilder->send($mailbox, $mail)` then `$this->sentFolderAppender->append($mailbox, $rawMime)` (Situation B/C). No `->queue()`, no `ShouldQueue`.
  - `sendSigningRequestEmail()` :5440–5484 calls it and only then stamps `sent_at` / `invite_send_status='sent'`.
  - Timeout per SMTP leg: `config('communications.smtp_timeout_seconds', 15)` (`PerMailboxMailTransportBuilder.php:55`); the IMAP append is a second live connection (`ImapSentFolderAppender.php:77–98`).
- **Project rule:** "request-triggered mail must be queued" + memory note on 08:30–09:00 SMTP contention. Handover §5 states plainly: "sends happen synchronously in the request/controller flow, same as before."
- **Failure scenario:** a pack with N recipients through a slow provider = N × (SMTP up to 15 s + IMAP connect/append). Hitting PHP/FPM execution limits mid-loop leaves a partially sent pack: earlier recipients `sent`, later ones untouched or `failed`, and the agent sees a 504 rather than the honest per-recipient status the false-Sent sweep built.
- **Mitigation already present:** the status is only flipped after a real transport success, so this cannot produce a *false* Sent — it produces a slow or aborted request.

### F3 — MEDIUM — Deploy-order landmine: `contact_property.deleted_at` migration must run before the new code serves traffic — CONFIRMED, introduced in range

- **Where:** `database/migrations/2026_09_16_100000_add_deleted_at_to_contact_property.php` (in net diff, no `hasColumn` guard); `app/Models/ContactProperty.php:29` (`SoftDeletes`); `app/Models/Contact.php:722–728` and `app/Models/Property.php:928–934` (`withPivot([... 'deleted_at'])->wherePivotNull('contact_property.deleted_at')`); `app/Services/Property/ContactPropertyLinker.php:13–16` (`ContactProperty::withTrashed()`); `app/Http/Controllers/Docuperfect/ESignWizardController.php:985–996` (new `linkRecipientToProperty()` → `ContactPropertyLinker::link()`).
- **Failure scenario:** code deployed and php-fpm reloaded before `php artisan migrate --force` → every e-sign recipients-step save with a property, and every contact/property page that loads the pivot, throws `QueryException: Unknown column 'contact_property.deleted_at'`. Same class as the handover's `outgoing_enabled` warning.
- **Also:** the AT-395 migration `2026_09_07_134131_add_outgoing_smtp_fields_to_communication_mailboxes_table.php` is **not** in the net diff (already in the `6545f0262` tree). If the live DB has not yet run it, `CommunicationMailbox::resolveOutgoingFor()` (`app/Models/Communications/CommunicationMailbox.php:163–168`, `->where('outgoing_enabled', true)`) throws on **every** e-sign send. It is `hasColumn`-guarded (17 guards) so running it twice is safe.

### F4 — MEDIUM — Resend of an invitation ignores ceremony/token state — CONFIRMED, pre-existing (unchanged in range)

- **Where:** `app/Http/Controllers/Docuperfect/SignatureController.php:2170–2200` (`resendEmail()`) → `SignatureService::resendInvitationEmail()` :5489 → `sendSigningRequestEmail()` :5440.
- Guards present: agent role, blank email, `STATUS_COMPLETED` (→ completion email). Guards absent: `isSigningBlocked()` (expired token, lapsed/cancelled template, revoked authority).
- **Failure scenario:** agent clicks Resend on an expired or cancelled document; a dead link is emailed, `sent_at` is re-stamped and `invite_send_status='sent'` — an honest transport result but a misleading business result (the UI says "Re-sent the signing invitation" for a link that renders "unavailable").

### F5 — LOW — `Event::forget(MessageSent::class)` wipes every listener on that event process-wide — CONFIRMED, latent (unchanged in range)

- **Where:** `app/Services/Communications/PerMailboxMailTransportBuilder.php:89–104` — registers a closure on `Illuminate\Mail\Events\MessageSent` to capture the raw MIME, then `finally { Event::forget(MessageSent::class); }`.
- Today nothing else listens on that event (grep of `app/` and `vendor/` excluding Illuminate's own — none). So no current breakage. Any future listener (sent-mail archive, delivery tracking) silently stops firing after the first per-mailbox send in a long-lived queue worker. Should use a dedicated dispatcher or remove only its own listener.

### F6 — LOW — Two of three mailbox-configuration surfaces enforce different scopes — CONFIRMED, pre-existing (handover §7 lists it)

- `Compliance\CommunicationMailboxController` `edit/update/destroy/restore/testConnection` :138–205 — `visibleTo(Auth::user())` (data scope of `communication_mailboxes` — own/branch/all, `CommunicationMailbox.php:131–149`).
- `Settings\EmailSetupController` `update()` :100, `destroy()` :110, `testConnection()` :185 — **no** visibility/ownership assertion; only the route group `permission:manage_communication_mailboxes` + `agency.required` (`routes/web.php:2583`). Cross-agency reach is blocked by `BelongsToAgency` global scope on route-model binding (`CommunicationMailbox.php:15`), with the known owner-role bypass when no `active_agency_id` is in session (`AgencyScope.php:66–83`).
- `MyPortal\CommunicationCaptureController` `update/destroy/testConnection` :68–102 — `assertOwn()` (:189–192, 403).
- Consequence: a `manage_communication_mailboxes` holder whose `communication_mailboxes` data scope is `own` or `branch` can still edit/test/archive any mailbox in the agency via `/settings/email-setup/...` but not via `/compliance/communication-mailboxes/...`.

### F7 — LOW — E-sign state transitions emit no domain events; `DocumentSigned` is registered but never dispatched — CONFIRMED, pre-existing

- `SignatureService.php` dispatches only the two new `Communications\OutgoingMail*` events (:68, :106) and `FinalizeSignedDocumentJob` (:3950, :3971). No `Docuperfect`/`Document` event on sent/viewed/signed/completed/declined/cancelled.
- `App\Events\Document\DocumentSigned` — registered `AppServiceProvider.php:711` → `LogDocumentEvent`, **zero dispatch sites** (grep).
- Auto-file (`autoFileSignedDocument()` :4357), completion emails, FICA gate (`SigningController::show()` :143+) are direct calls. Non-negotiable #9 gap; audit rows *are* written via `SignatureAuditLog::log()` on verify/consent/complete/decline/cancel (e.g. :761, :783, :1898, `ESignWizardController.php:8417`).
- The two new events extend `AbstractDomainEvent` → picked up by the generic `Log*` listeners (`AppServiceProvider.php:357–363`); no dedicated listener is registered for them (audit-only by spec §3.4). No queued listener → no fatal.

### F8 — LOW — Spec self-contradiction on the fallback rule — CONFIRMED (doc only)

- `.ai/specs/at395-outgoing-mail-per-mailbox-smtp.md:247` test step 4 still says "Prove: the recipient still receives it (via shared-mailer fallback)" while §3.3 Situation B (:101) and §15 (:297) decide "no silent fallback". Code implements fail-loudly (`dispatchSigningMail()` rethrows; `tests/Feature/Communications/OutgoingMailPerMailboxTest.php:128 test_broken_mailbox_fails_loudly_and_does_not_mark_sent`).

### F9 — LOW — `env()` outside `config/` in mail-adjacent files — CONFIRMED, pre-existing (none in net range)

- `app/Support/OutboundMailGuard.php:199,204,209` (`MAIL_GUARD_SINK_*`, `MAIL_HOST`, `MAIL_PORT`), `app/Mail/OtpMail.php:36–37`, `app/Mail/ClientAuthOtpMail.php:25–26` (`MAIL_OTP_FROM_*`). Under `config:cache` these read `null` → OTP From falls back to the literal `Otp@corexos.co.za` default; sink host falls back to `127.0.0.1` (non-prod only).
- Related deploy fact (not a code bug): `OutboundMailGuard::isSendingConfirmed()` :52–54, :82–97 only returns true for `APP_ENV=production` **and** `APP_URL` host `corexos.co.za` / `www.corexos.co.za` (or staging). Anything else — or a forced `DevSetting mail_intercept_forced` — intercepts **every** send, including per-mailbox SMTP (`MessageSending` listener returns false → no `MessageSent` → builder throws `send_rejected`) and the IMAP append (`ImapSentFolderAppender.php:48`).

### F10 — INFO — AT-395 credential handling is sound — CONFIRMED

- Storage: `encrypted` cast on `encrypted_password` and `smtp_encrypted_password` (`CommunicationMailbox.php:42,62`); both in `$hidden` (:106–108); write-only on all four surfaces (`($data['password'] ?? null) !== null` — `CommunicationMailboxController.php:362,395`, `EmailSetupController.php:358,390`, `CommunicationCaptureController.php:239,271`); the only read is `EmailSetupController::reveal()` :287–305 gated by `reveal_mailbox_credential` and recorded via the `reveals` relation.
- No `Log::` call in the AT-395 files includes a password field (grep). Raw SMTP/IMAP server responses are persisted (`last_send_error_detail`) and logged (`ImapSentFolderAppender.php:83,96`) — server text, not credentials.
- Per-agency mailer is built at send time from the row (`PerMailboxMailTransportBuilder::send()` :39–115: `EsmtpTransport` + `new Mailer('per_mailbox', …)`), resolved by `(agency_id, user_id, outgoing_enabled, outgoing_active)` (`resolveOutgoingFor()` :156–169). From address = agent's `outward_email` (`BaseSignatureMail.php:126–131`).
- No silent fallback: configured-but-broken throws `OutgoingMailboxSendFailedException` and increments `consecutive_send_failures` (`SignatureService.php:88–97`); only a **no-mailbox** agent uses the shared mailer, and that path dispatches `OutgoingMailFellBackToSharedMailer` for audit.
- "Sent" flips only after transport success (`sendSigningRequestEmail()` :5461–5467); failure path writes `invite_send_status='failed'` + `invite_send_error` (:5477–5480). Tests for the false-Sent class exist: `OutgoingMailPerMailboxTest.php:112–262` (9 tests).
- Test Connection is rate-limited per mailbox (`MailboxConnectionRateLimiter`, Laravel `RateLimiter` → cache store) and host-circuit-broken (`HostCircuitBreaker` → table `communication_host_circuit_breakers`, migrations `2026_09_09_*`, already in tree).

### F11 — INFO — Rental-application submission identity gate (new in range, `055033cc4`) — CONFIRMED sound, two notes

- `app/Http/Controllers/RentalApplicationSigningController.php` `verifyIdentityGate()` :406–447: verified state is stamped on the **row** (`identity_verified_at`), not a cookie — cannot be reused across applications. ID compare is digits-only + `hash_equals` (:114–121). OTP: `Hash::make`, single-use, `attempts < 5` (`app/Models/Otp.php:88–93`, `OtpService::verify()` :110–143), per-token throttle `rental-application-identity-gate` (`AppServiceProvider.php:1169–1186`, agency-configurable), resend via `OtpService::throttle()` cooldown + hourly cap (:154–180). Gate enforced on `show()` :582, `pdf()` :1180, `viewDocument()` :1233. Token `Str::random(64)`.
- Note A: the gate is a *hold before agency notification* — the submission is already persisted before it (`submit()` :929–943). By design (Johan's ruling quoted in code).
- Note B: OTP delivery is synchronous and failure-swallowing (`OtpService::deliver()` :190–203 `Mail::mailer('otp')->send()` inside try/`report()`), so an applicant can be told a code was sent when SMTP failed. The `otp` mailer needs `MAIL_OTP_*` (or falls back to `MAIL_*`) on live.

### F12 — INFO — WhatsApp — CONFIRMED clean

- Pure client-side deep link `https://wa.me/{digits}?text=…` (`app/Services/Docuperfect/SigningWhatsAppLinkService.php:152`) carrying `route('signatures.external', $token)` (:145, APP_URL-driven). Server only logs "opened" (`SignatureController::whatsappOpened()` :2216–2249, `auth` + `authorizeDocument` + `authorizeSignatureRequestForDocument`). No WAHA involvement; `WahaSessionClient` default `http://127.0.0.1:3111` is `config('communications.waha.base_url')`-driven and untouched. `a2c92fa82` is a Blade/Alpine quoting fix inside `x-data` (`_whatsapp-resend-button.blade.php`). No hardcoded tokens/URLs. Exposure note: the signing token now travels over WhatsApp — see F1.

### F13 — INFO — Debug leftovers: none

- No `dd(`, `dump(`, `var_dump(`, `ray(`, `print_r(` in `app/Http/Controllers/Docuperfect`, `app/Services/Docuperfect`, `app/Services/Communications`, `app/Http/Controllers/Communications`, the three mailbox controllers, `app/Mail`, `app/Models/Docuperfect`, `app/Models/Communications`, `RentalApplicationSigningController.php`, or the in-scope views. `SigningController.php:3579` mentions `localhost`/`127.0.0.1` only in an asset-inlining guard. No `console.log`/URLs/tokens added in the four changed views.

---

## 2. Pipeline gate (priority 3)

| Gated file | Net diff 6545f0262..57407d5a5 | Touched by any in-range commit |
|---|---|---|
| `app/Models/Docuperfect/Template.php` | none | no |
| `app/Models/Docuperfect/CdsDraft.php` | none | no |
| `app/Services/Docuperfect/SignatureSurfaceNormalizer.php` | none | no |
| `app/Services/Docuperfect/LetterheadRefresher.php` | none | no |
| `app/Services/Docuperfect/InsertableBlockRenderer.php` | none | no |
| `app/Services/Docuperfect/RoleBlockDetectionService.php` | none | no |
| `app/Services/Docuperfect/RoleBlockExpansionService.php` | none | no |
| `app/Services/Docuperfect/RoleBlockNormalizer.php` | none | no |
| `app/Services/Docuperfect/MergedHtmlFreshnessGuard.php` | none | no |
| `app/Http/Controllers/Docuperfect/SigningController.php` | none | no |

`tests/Feature/Docuperfect/SigningView/` net diff: `RecipientPropertyLinkRelinkTest.php` **+103** (new; 2 tests covering the `ESignWizardController::linkRecipientToProperty()` change). Gate satisfied (trivially for the gated files; substantively for the wizard change).

---

## 3. State machine (priority 4) — summary

- **Advance chain:** `advanceToNextParty()` (`SignatureService.php:2071`) → `advanceToNextSigningParticipant()` :2008 picks the next WAITING participant, falls to DEFERRED, sets template status from a role map, marks next request PENDING, then sends; on send failure `invite_send_status='failed'` is visible and the controller checks it (:1120, :1730, :1938). Wet-ink `submitInspection()` still discards the advance result (handover §7 / spec §15.5) — known, scoped gap.
- **Double completion:** `completeWeb()` has no early return for an already-`STATUS_COMPLETED` request; re-entry is intentional for the returning-signer/re-initial case (:1806–1822, `electronic_consent_given` evidence). Combined with F1 this means a re-POST re-bakes ink. Not separately rated.
- **Re-send of completed doc:** `resendEmail()` routes COMPLETED to the completion email, never the invitation (:2181). Cancelled/expired: see F4.
- **Cancel/void:** `ESignWizardController::cancelDocument()` :8380–8425 — permission check, refuses COMPLETED/CANCELLED, transaction cancels pending requests + template, writes `ACTION_CANCELLED` audit row. `isSigningBlocked()` (`SignatureRequest.php:292–298`) then blocks every recipient write (expired ∨ lapsed ∨ cancelled ∨ authority revoked).
- **Decline:** `SigningController::decline()` :3670 → `declineRequest()` with ip/ua (audit inside service). Not verified-gated (F1).
- **Viewed:** stamped in `show()` :214 and :974 with `viewed_at`.
- **Audit rows:** identity verified/failed (:761, :783), consent (:942–953), completion (:1898 region), cancel (:8417), decline (service). Present for every transition read.
- **Side effects as domain events:** no — see F7.

---

## 4. Route inventory — new/changed routes in the net diff, in scope

| Route | Method | Gate | Scoping |
|---|---|---|---|
| `rental-applications.public.show` `/rental-application/{token}` | GET | none (public) + `throttle:rental-application-show` (per token) | token lookup `queryWithoutAgencyScope()`; return-gate + identity-gate checks in `show()` |
| `rental-applications.public.verify-gate` | POST | `throttle:rental-application-gate` | token; session flag per token |
| `rental-applications.public.gate.resend-otp` | POST | none at route; `OtpService::throttle()` | token |
| `rental-applications.public.identity-gate` `/{token}/verify-identity` | GET | none | token; only when `identityVerificationAwaitingApplicantAction()` |
| `rental-applications.public.verify-identity-gate` | POST | `throttle:rental-application-identity-gate` | token; stamps `identity_verified_at` on row |
| `rental-applications.public.identity-gate.resend-otp` | POST | none at route; `OtpService::throttle()` | token |
| `rental-applications.public.autosave` / `.submit` / `.documents` / `.pdf` / `.documents.view` / `.documents.remove` / `.documents.replace` | POST/GET | named per-token throttles | token; `{document}` scoped to the token's application (`scopedDocument()` :1200) |
| `corex.settings.rental-applications.identity-gate` | POST | `auth` + `permission:rental_applications.manage_settings` | agency settings row |

Existing (unchanged) routes that the audited features ride on:

| Route group | Gate | Scoping |
|---|---|---|
| `/sign/{token}/*` (`signatures.external.*`, 35 routes) | none; `throttle:30,1` on show, `5,1` on verify; **no verified-session middleware** (F1) | token lookup; session keys per token |
| `compliance.comm-mailboxes.*` | `permission:manage_communication_mailboxes` + `agency.required` + `feature:communications` | `visibleTo()` data scope (own/branch/all) |
| `settings.email-setup.*` | `permission:manage_communication_mailboxes` + `agency.required` | `BelongsToAgency` global scope only (F6); `reveal` additionally `reveal_mailbox_credential` |
| `my-portal.comm-capture.*` | `permission:access_communication` + `agency.required` | `assertOwn()` (user_id) |
| `docuperfect.signatures.whatsappOpened` | `auth` + `authorizeDocument()` + `authorizeSignatureRequestForDocument()` | document agency |

New permission keys in range (`config/corex-permissions.php` net diff): `rental_applications.{view,create,view_returned,manage_settings,archive}`, `contact_rental_history.view`, `access_imported_stock`, `buyer_pipeline.view`, `core_matches.reassign`. AT-395's `communication_mailboxes.view` (:179, scope default :825) is already in the base tree.

---

## 5. Deploy prerequisites

1. **Order:** `git pull` → `php artisan migrate --force` → `php artisan deploy:sync-reference-data` → `config:clear` / `route:clear` / `view:clear` → reload php-fpm → restart workers (supervisor groups need the trailing colon). Do **not** reload php-fpm before migrate (F3).
2. **Migrations:** 92 new in the range (all AT-392 / core-matches / contact_property). E-sign-critical: `2026_09_16_100000_add_deleted_at_to_contact_property.php` (unguarded, additive). Confirm live already has `2026_09_07_134131_add_outgoing_smtp_fields_to_communication_mailboxes_table.php` applied (`Schema::hasColumn('communication_mailboxes','outgoing_enabled')`), and the `communication_host_circuit_breakers` table (`2026_09_09_030000`, `_040000`, `_060000`).
3. **Env keys:** no new required keys for AT-395 (`COMMUNICATIONS_SMTP_TIMEOUT_SECONDS` optional, default 15). Rental identity gate OTP mail uses the `otp` mailer: `MAIL_OTP_HOST/PORT/ENCRYPTION/USERNAME/PASSWORD` (fall back to `MAIL_*`) and `MAIL_OTP_FROM_ADDRESS/NAME` (read via `env()` at runtime — F9). `APP_ENV=production` + `APP_URL` host `corexos.co.za`/`www.corexos.co.za`, else `OutboundMailGuard` intercepts all mail; verify `DevSetting mail_intercept_forced` is not left on.
4. **Cache store:** rate limiters (`MailboxConnectionRateLimiter`, OTP cooldown/hourly, all per-token rental limiters) use Laravel `RateLimiter` → `CACHE_STORE` (default `database` → `cache` table must exist and be shared across FPM workers; `array` would disable limiting).
5. **Queues:** no new queue. E-sign sends and OTP mail are synchronous (F2, F11-B). Existing `FinalizeSignedDocumentJob` still needs the worker restarted after deploy.
6. **PHP extensions / binaries:** nothing new. Per-mailbox SMTP uses Symfony `EsmtpTransport` (openssl for TLS); Sent-folder append reuses the existing webklex IMAP client (`ImapMailboxPoller::connect()`); encrypted casts need a stable `APP_KEY` (rotation invalidates every stored mailbox password).
7. **Data (not code):** per handover §5, no agent's mail will leave through their own mailbox until a `communication_mailboxes` row on live has `outgoing_enabled=1` + working credentials; until then every send uses the shared mailer exactly as before. Prove one real send + Sent-folder copy on live before announcing AT-395 as on.
8. **Tests to run on a box with MySQL up:** `tests/Feature/Docuperfect/SigningView/RecipientPropertyLinkRelinkTest.php` (the only SigningView change), `tests/Feature/Communications/OutgoingMailPerMailboxTest.php`, `tests/Feature/RentalApplications/RentalApplicationSignatureAgencyScopeTest.php`.
