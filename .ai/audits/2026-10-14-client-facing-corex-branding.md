# Client-facing "CoreX" branding audit - 14 Oct 2026

**Ruling (Johan):** anything a CLIENT (tenant, landlord, buyer, seller, applicant, contractor) sees carries the AGENCY name / branding, never "CoreX" / "CoreX OS". Staff screens keep CoreX. A "Powered by CoreX" line is not allowed unless it already exists as an agency setting - **none exists** (checked: no column, config key or migration), so every such line is gone.

**Method.** Every `CoreX` / `COREX` / `corexos` / lowercase `corex` wordmark in `resources/views`, `resources/js`, `public/js`, `app`, `config` and `routes` (display strings only: comments, namespaces, route names, `window.CoreX*` globals and the OTP sender mailbox are ignored), plus every `config('app.name')` / `config('mail.from.name')` fallback - both resolve to CoreX's own name, so neither may ever be a client-facing default. `ClientBrand` (`app/Support/ClientBrand.php`) is the one fallback now: the document / property / lease agency, else the portal / public-link agency, else a neutral label (`Property Portal`, `My Rentals`) - never CoreX.

**Guard.** `tests/Feature/Branding/ClientFacingNeverSaysCoreXTest.php` renders the client-facing pages and mails for a second agency (with `app.name` / `mail.from.name` set to "CoreX OS") and scans every client-facing source file; a new CoreX word in one of them fails the build.

## A. Client-facing - FIXED (file -> what it said -> now)

| Surface (who sees it) | File : line (before) | It said | Now |
|---|---|---|---|
| **404 page** (any stranger / client with a dead link) | `errors/404-guest.blade.php` :40 :86 :104 | title "Page not found - CoreX OS", header "CoreX", button "Contact CoreX support" (mailto support@corexos.co.za) | title "Page not found", neutral header `Property Portal`, no CoreX button (the person to ask is whoever sent the link) |
| **403 page** | `errors/403-guest.blade.php` :21 :70 :85 | same | same |
| **Maintenance page** | `errors/maintenance.blade.php` :16 :106 :109 :118 | "CoreX OS - Down for maintenance", wordmark "CoreX OS", "CoreX is being updated", "{app.name} · KZN South Coast" (one agency's region hard-coded) | neutral wording; no brand, no region |
| **E-sign gateway** (signer, ID check) | `docuperfect/signatures/external/gateway.blade.php` :120 | "Secure document signing powered by CoreX OS" | "Secure document signing" (the agency name already shows beneath) |
| **E-sign consent** | `.../external/consent.blade.php` :108 | same | same |
| E-sign "already completed" fallback | `Docuperfect/SigningController.php` :1076 :1083 | `config('app.name','CoreX OS')` | `ClientBrand` (agency, else neutral) |
| E-sign signing page (source comment printed `app.name` into the page) | `.../external/sign.blade.php` :2347 | "{{ config('app.name') }}, shop 5, The emporium" in a script comment | example text only |
| **Public auction page** footer | `public/auctions/_layout.blade.php` :23 | "Powered by CoreX OS" | "© year {agency}" |
| **Public property pages** footer | `public/agency-properties/show.blade.php` :115, `index.blade.php` :93 | "Powered by CoreX OS" | "© year {agency}" |
| Public company footer (shared by every shared link) | `public/shared/_company-footer.blade.php` :10 :38 | fallback name "CoreX OS" + "Powered by CoreX OS" | agency, else neutral; line removed |
| Public dead-end / link-unavailable pages | `public/shared/_dead-end.blade.php` :143 :258, `_link-unavailable.blade.php` :61, `PublicLinkUnavailableResponder.php` :100 | fallback "CoreX" / "CoreX OS" | agency, else neutral |
| Seller landing page | `seller-outreach/landing.blade.php` :110 | "Powered by CoreX" | "© year {agency}" |
| Public layout title | `layouts/public.blade.php` :9 | `config('app.name','CoreX OS')` | neutral |
| **Core Matches digest** footer | `emails/matches/digest.blade.php` :68 | "Sent by CoreX OS - Core Matches" | "Sent by Core Matches" |
| **Presentation mail** | `emails/presentations/send-presentation.blade.php` :20 :37 :40, `Mail/Presentations/SendPresentationEmail.php` :55 | fallbacks "CoreX OS", "Powered by CoreX OS", from-name fallback | agency, else neutral; line removed |
| **OTP / verification-code mail** (applicants, clients) | `emails/otp.blade.php` :21 :55, `Mail/OtpMail.php` :48 :51 | logo "CoreX Os", "© CoreX OS", from-name env "CoreX OS", subject "Your CoreX code" | neutral label; "Your verification code" |
| **Seller-info mails** (tiers 1-3) | `emails/compliance/seller-info/tier1:192, tier2:143, tier3:145` | "Powered by CoreX OS Compliance Module" | removed (agency name/phone/email stay) |
| Inspection link mail + page text | `emails/rentals/inspection-signing-link.blade.php` :29, `RentalInspectionSigningLinkService.php` :555, `rental-inspections/public/show.blade.php` :68, `partials/sign-section.blade.php` :44 :134 | "the agent signs inside CoreX", "Back to CoreX", "Open this inspection in CoreX" | "the agent signs with their own login and PIN", "Back to the inspection", "Open this inspection" |
| **Every e-sign / sales / title-deed / distribution / rental / seller-outreach mail footer** (~45 files) | `emails/{signatures,sales,title-deeds,distribution,rentals,seller-outreach}/**` | "This email was sent by {agency ?? app.name}" (= CoreX OS when the agency is missing) | `ClientBrand::NEUTRAL` fallback |
| **Mail senders** (17 classes) | `Mail/RentalApplication*Mail`, `FicaRequestMail`, `Rentals/*`, `Signatures/*`, `RentalApplicationDeclineEmailSetting.php` :150, `DealV2/CocRequestGenerator.php` :59 | `config('mail.from.name','CoreX OS')` as the agency name | `ClientBrand` (agency, else `My Rentals` / `Property Portal`) |
| E-sign "personal-email agent" sender | `Mail/Signatures/BaseSignatureMail.php` :122 :147 :232 :245 :268, `PartySignedNotificationMail.php` :31, `config/signatures.php` :23 | "{Agent} via CoreX OS" and system name "CoreX OS" | "{Agent} via {their agency}"; neutral system name |
| Calendar invite organiser | `Support/IcsCalendarInvite.php` :88 | organiser fallback "CoreX OS" | neutral |
| **Certificate of compliance request** | `documents/coc-request.blade.php` :87 | "Auto-generated by CoreX OS for {agency}" | "Prepared for {agency}" |
| Work authorisation PDF | `documents/work-authorisation.blade.php` :113 | "Generated by {co} via CoreX" | "Issued by {co}" |
| Rental PDFs (9 documents) | `Services/Rentals/RentalDocumentPdfService.php` :38 :91 :119 :152 :173 :205 :242 :291 :318 | agency fallback "CoreX" | `My Rentals` |
| Brochure, viewing packs, presentation / commercial-evaluation / match-list PDFs | `PropertyBrochureService.php` :189, `ViewingPack{Buyer,Agent}PdfService` + `ViewingPackPdfSupport` , `PresentationDeliveryService.php` :90, `PresentationPdfService.php` :535, `CommercialEvaluationPdfService.php` :38 :704 :722, `CoreMatchListPdfService.php` :162 | fallback "CoreX" / `app.name`; "This report was generated by {app.name}" | agency, else neutral; "prepared by {agency}" |
| FICA, evaluation certificate, proforma, deposit-interest, street/complex PDFs | `compliance/fica/pdf` :183 :184, `completion-report` :291, `tools/evaluation-certificate/pdf` :39 :194, `proforma/pdf` :8, `deposit-interest-calculator/pdf` :237, `corex/contacts/street-complex-search-pdf` :119 :220 | "generated by CoreX OS", "via CoreX OS", `app.name` fallbacks | agency, else neutral |
| Sales-document return pages (5) | `sales-documents/{verify,upload,upload-success,expired,already-returned}.blade.php` | title and footer `config('app.name')` ("CoreX OS - Document Management") | the sending agency |
| FICA form + confirmation | `fica/form.blade.php` :7, `fica/confirmation.blade.php` :6 | title fallback `app.name` | agency, else neutral |
| TV display | `tv/index.blade.php` :6 :115, `tv/deactivated.blade.php` :75 | logo text `app.name` | neutral |
| Seller live presentation | `presentations/seller-live.blade.php` :295 | brand line `app.name` | the presentation's agency |
| Property / agent live preview (public) | `corex/properties/live-preview.blade.php`, `corex/agents/live-preview.blade.php` | `{agency ?? app.name}` x8 | `{agency ?? neutral}` |
| Owner "what changed" log | `Rentals/RentalOwnerFaultSettingsService.php` :172 | actor "CoreX" | "The agency" |
| Owner / dispute portal pages | `RentalFaultSettingsDisputeController.php` :29, `RentalOwnerNoticeController.php` :61 | fallback `config('app.name','CoreX OS')` | `My Rentals` |
| E-sign audit trail actor | `Rentals/LeaseSigningLauncher.php` :522 | actor name "CoreX" | "System" |
| **Marketing ads (images posted publicly)** | `public/js/corex-ad-render.js` :1130 :1137, `corex/properties/_ad-templates.blade.php` :16 :30 | no logo -> drew a "corexos" wordmark; default watermark "COREX" | the agency's own name |
| Print-settings default (printed tools) | `tools/tools.blade.php` :1054 | company default `app.name` | the agency's print-settings name |


## B. Client-facing items deliberately NOT changed (reported)

- **Sender mailbox `Otp@corexos.co.za`** (`Mail/OtpMail.php`, `ClientAuthOtpMail.php`): the From *name* is now the agency, but the address is CoreX's sending domain (deliverability / SPF). Changing it needs a per-agency sending domain - an infrastructure decision, not a wording fix.
- **`tools/tools.blade.php` print defaults** (`DEFAULT_SETTINGS`): the address, phone and FFC of one agency (Shelly Beach) are hard-coded as the defaults printed on client calculator PDFs. That is a multi-agency fault, not CoreX branding, so it is reported and not touched (scope).
- **Invisible plumbing**: ICS `PRODID`, `X-CoreX-*` webhook / mail-guard headers, the mobile app's token label "CoreX Client App", `window.CoreX*` JavaScript globals. A client never reads these.
- **CoreX's own legal pages** (`public/legal/*`: privacy, terms, support, data deletion) are the company's own documents; no client-facing page links to them (checked).
- **Demo pages** (`seller-link/demo`, `buyer-portal/demo`, `demo/*`) are product demos shown to prospects, not to an agency's clients.


## C. Remaining display hits - all staff-only (kept)

Client-facing display hits remaining after the fixes: **0**.


### staff-only (CoreX's own Subscription Agreement with the agency)

- `app/Console/Commands/PlatformEsignDemo.php` - 23, 25, 47, 50, 57
- `app/Console/Commands/PlatformEsignVerifyWording.php` - 57
- `app/Http/Controllers/Admin/PlatformCompanyController.php` - 116
- `app/Http/Controllers/PlatformEsign/AgreementSigningController.php` - 166, 214
- `app/Mail/PlatformEsign/AgreementInviteMail.php` - 27
- `app/Models/Platform/PlatformCompany.php` - 44, 52, 58
- `app/Services/Platform/PlatformCompanyService.php` - 196
- `app/Services/PlatformEsign/Agreement/AgreementConsistency.php` - 60
- `app/Services/PlatformEsign/Agreement/AgreementContent.php` - 17, 145, 146, 154, 155, 156, 157, 158
- `app/Services/PlatformEsign/Agreement/AgreementFields.php` - 43, 280
- `app/Services/PlatformEsign/Agreement/AgreementPdf.php` - 57
- `app/Services/PlatformEsign/Agreement/AgreementRenderer.php` - 23
- `app/Services/PlatformEsign/Agreement/AgreementService.php` - 109, 134
- `app/Services/PlatformEsign/Agreement/AgreementVersions.php` - 24, 25, 26, 27, 29, 31
- `resources/views/platform-esign/agreement/_owner-panel.blade.php` - 47
- `resources/views/platform-esign/agreement/_sheets.blade.php` - 12
- `resources/views/platform-esign/agreement/completed.blade.php` - 7, 18
- `resources/views/platform-esign/agreement/message.blade.php` - 3, 11, 12
- `resources/views/platform-esign/agreement/send.blade.php` - 21, 102, 103, 104
- `resources/views/platform-esign/agreement/sign.blade.php` - 8, 15, 30
- `resources/views/platform-esign/documents/index.blade.php` - 17
- `resources/views/platform-esign/email/agreement-countersign-reminder.blade.php` - 7
- `resources/views/platform-esign/email/agreement-invite.blade.php` - 7, 9, 12
- `resources/views/platform-esign/email/agreement-received.blade.php` - 7
- `resources/views/platform-esign/email/agreement-signed.blade.php` - 7, 17
- `resources/views/platform-esign/email/invite.blade.php` - 6
- `resources/views/platform-esign/email/signed.blade.php` - 6
- `resources/views/platform-esign/hub.blade.php` - 30
- `resources/views/platform-esign/pdf/agreement-attestation.blade.php` - 21
- `resources/views/platform-esign/pdf/agreement.blade.php` - 35
- `resources/views/platform-esign/pdf/sealed.blade.php` - 42
- `resources/views/platform-esign/public/sign.blade.php` - 22
- `resources/views/platform-esign/templates/form.blade.php` - 9
- `resources/views/platform-esign/templates/index.blade.php` - 16
- `resources/views/platform-esign/wording/index.blade.php` - 21


### staff-only (CoreX-to-agency: demo, onboarding, billing, login, legal)

- `app/Console/Commands/Auctions/SeedDemoAuctions.php` - 165, 178
- `app/Console/Commands/Demo/DemoReset.php` - 44, 45
- `app/Http/Controllers/Admin/DemoAccessController.php` - 416, 478, 485, 501
- `app/Http/Controllers/Admin/DemoConnectionController.php` - 74, 96, 112, 122, 123, 135, 141
- `app/Http/Controllers/Api/V1/WebinarApiController.php` - 220
- `app/Http/Controllers/CoreX/AgencySetupWizardController.php` - 3, 475
- `app/Http/Controllers/Public/AgencySetupGateController.php` - 101
- `app/Http/Controllers/Public/OnboardingPortalController.php` - 468
- `app/Listeners/Demo/SendDemoAccessGrantEmail.php` - 89
- `app/Mail/AgencyOnboardingSetupMail.php` - 36
- `app/Mail/Billing/AgencyPlanChangedMail.php` - 67
- `app/Mail/DemoAccessExtendedMail.php` - 57, 59
- `app/Mail/DemoAccessGrantMail.php` - 64, 66
- `app/Mail/UserInviteMail.php` - 39, 40
- `app/Mail/WebinarConfirmationMail.php` - 67, 149
- `app/Mail/WebinarJoinLinkMail.php` - 91
- `app/Mail/WebinarReminderMail.php` - 44
- `app/Notifications/OnboardingPortalInvitation.php` - 28, 29, 30, 34
- `app/Services/Demo/DemoControlClient.php` - 110, 118, 166
- `config/agency-onboarding-copy.php` - 53, 54, 59, 78, 81, 107, 116, 119, 127, 137, 138, 142 (+101 more)
- `resources/views/admin/webinars/_form.blade.php` - 21
- `resources/views/admin/webinars/create.blade.php` - 19
- `resources/views/admin/webinars/index.blade.php` - 19, 53
- `resources/views/admin/webinars/show.blade.php` - 74
- `resources/views/agency-setup/expired.blade.php` - 22
- `resources/views/agency-setup/login.blade.php` - 7, 10
- `resources/views/agency-setup/steps/branding.blade.php` - 172, 199
- `resources/views/agency-setup/steps/capabilities-modules.blade.php` - 18
- `resources/views/agency-setup/steps/notifications.blade.php` - 68
- `resources/views/agency-setup/steps/rentals-inspection-lists.blade.php` - 173
- `resources/views/agency-setup/steps/rentals-lease-agreement.blade.php` - 13
- `resources/views/agency-setup/steps/roles.blade.php` - 12, 39
- `resources/views/buyer-portal/demo.blade.php` - 13
- `resources/views/demo/gate.blade.php` - 14
- `resources/views/emails/agency-onboarding-setup.blade.php` - 6, 19, 32, 39, 56, 77
- `resources/views/emails/billing/plan-changed.blade.php` - 29
- `resources/views/emails/bulk-announcement.blade.php` - 19, 29, 57
- `resources/views/emails/demo-access-extended.blade.php` - 24, 40, 99
- `resources/views/emails/demo-access-grant.blade.php` - 29, 45, 130
- `resources/views/emails/user-invite.blade.php` - 6, 19, 37, 43, 81
- `resources/views/emails/webinars/confirmation.blade.php` - 31, 48, 112, 184, 185
- `resources/views/emails/webinars/join-link.blade.php` - 26, 101, 102
- `resources/views/emails/webinars/reminder.blade.php` - 24, 101, 102
- `resources/views/layouts/agency-setup.blade.php` - 9, 52, 75, 76
- `resources/views/layouts/guest.blade.php` - 8, 174, 187
- `resources/views/layouts/onboarding-portal.blade.php` - 66, 67
- `resources/views/layouts/partials/welcome-onboarding-modal.blade.php` - 51, 55
- `resources/views/onboarding/portal/completed.blade.php` - 13, 36
- `resources/views/onboarding/portal/expired.blade.php` - 6, 14, 17
- `resources/views/onboarding/portal/finish.blade.php` - 12
- `resources/views/onboarding/portal/invites.blade.php` - 12, 57, 76
- `resources/views/onboarding/portal/welcome.blade.php` - 8
- `resources/views/public/agency-timeline/inactive.blade.php` - 5, 7
- `resources/views/public/agency-timeline/show.blade.php` - 3, 7, 8, 150, 152, 224, 255, 274
- `resources/views/public/legal/agreement-terms.blade.php` - 7, 8, 66
- `resources/views/public/legal/data-deletion.blade.php` - 7, 9, 16, 21, 24, 27, 32, 37, 38, 44, 50
- `resources/views/public/legal/extension-privacy.blade.php` - 3, 7, 8, 15, 24, 32, 35, 56
- `resources/views/public/legal/layout.blade.php` - 6, 56, 66
- `resources/views/public/legal/privacy.blade.php` - 7, 12, 18, 21, 30, 35, 53, 93, 94, 95, 108, 112 (+18 more)
- `resources/views/public/legal/support.blade.php` - 7, 20, 38, 40, 48, 49, 54, 55, 60, 61, 66, 67 (+1 more)
- `resources/views/public/legal/terms.blade.php` - 7, 8, 9, 10, 13, 15, 20, 35, 41, 43, 50, 52 (+9 more)
- `resources/views/seller-link/demo.blade.php` - 49


### staff-only (staff screen / mail / log / setup copy)

- `app/Console/Commands/AgentsReconcilePhotos.php` - 31
- `app/Console/Commands/ArchiveAndDiscardOversightNudgeFailures.php` - 51, 113
- `app/Console/Commands/ArchiveStaleMatches.php` - 12
- `app/Console/Commands/BackfillContactPropertyRoles.php` - 30
- `app/Console/Commands/BillingReconcileCommand.php` - 29
- `app/Console/Commands/CommandCenter/ReconcileCalendarEvents.php` - 32
- `app/Console/Commands/CommandCenter/SendCalendarDigests.php` - 22
- `app/Console/Commands/DeactivateP24Agents.php` - 25, 71, 72
- `app/Console/Commands/Deploy/SyncReferenceData.php` - 91, 149
- `app/Console/Commands/Esign/PublishReferencePack.php` - 33, 83
- `app/Console/Commands/Leave/AccrueDailyCommand.php` - 10
- `app/Console/Commands/Leave/CycleRolloverCommand.php` - 10
- `app/Console/Commands/Leave/RecalculateBalancesCommand.php` - 12
- `app/Console/Commands/Leave/SeedPublicHolidaysCommand.php` - 10
- `app/Console/Commands/Leave/SendLeaveRemindersCommand.php` - 11
- `app/Console/Commands/MailInterceptStaleAlert.php` - 24
- `app/Console/Commands/MaintenanceModeCommand.php` - 26
- `app/Console/Commands/Matches/SendMatchDigests.php` - 38
- `app/Console/Commands/MovePropertyFilesToLocalDisk.php` - 23
- `app/Console/Commands/OversightNudgeVolumeReport.php` - 23
- `app/Console/Commands/P24AgentAudit.php` - 34, 87
- `app/Console/Commands/P24ImportVettingAgents.php` - 25, 100
- `app/Console/Commands/P24SyndicateSmokeTest.php` - 29
- `app/Console/Commands/Properties/ReconcilePropertyAddresses.php` - 33, 149
- `app/Console/Commands/QueueHealthcheck.php` - 58, 218, 295, 312, 333, 345
- `app/Console/Commands/QueueWorkerLivenessAlert.php` - 25, 37, 86, 98
- `app/Console/Commands/ReconcileRoleGrants.php` - 41, 159, 177
- `app/Console/Commands/SyncPermissions.php` - 14
- `app/Console/Commands/Syndication/AuditPortalInventory.php` - 29, 85, 86, 97, 98, 103
- `app/Console/Commands/ValidateFeatures.php` - 16
- `app/Console/Commands/WarmP24AgentsCache.php` - 94
- `app/Exceptions/SeatReinstatementLockedException.php` - 38, 62, 63
- `app/Http/Controllers/Admin/AgencyController.php` - 20
- `app/Http/Controllers/Admin/SystemUpdateController.php` - 138, 184, 218, 294
- `app/Http/Controllers/Admin/UserManagementController.php` - 143, 298, 448
- `app/Http/Controllers/Agent/AgentPortalController.php` - 391
- `app/Http/Controllers/Api/DeedsCaptureController.php` - 435
- `app/Http/Controllers/Api/PropertyPullController.php` - 201
- `app/Http/Controllers/Api/V1/AgentQrController.php` - 170
- `app/Http/Controllers/Api/V1/AppAccessController.php` - 41
- `app/Http/Controllers/Api/V1/ClientAuthController.php` - 215, 297
- `app/Http/Controllers/Communications/WaSessionWebhookController.php` - 60
- `app/Http/Controllers/Compliance/CommunicationMailboxController.php` - 243, 244, 272, 282
- `app/Http/Controllers/CoreX/AddressReviewController.php` - 5
- `app/Http/Controllers/CoreX/AgentPreviewController.php` - 3
- `app/Http/Controllers/CoreX/AlertSwitchController.php` - 3
- `app/Http/Controllers/CoreX/BuyerNotesController.php` - 3
- `app/Http/Controllers/CoreX/ContactController.php` - 3
- `app/Http/Controllers/CoreX/ContactDocumentController.php` - 3
- `app/Http/Controllers/CoreX/ContactExportController.php` - 3
- `app/Http/Controllers/CoreX/ContactIdentifierLabelController.php` - 3
- `app/Http/Controllers/CoreX/ContactImportController.php` - 3
- `app/Http/Controllers/CoreX/ContactMatchController.php` - 3
- `app/Http/Controllers/CoreX/ContactMatchReassignmentController.php` - 3
- `app/Http/Controllers/CoreX/ContactMatchShareController.php` - 3
- `app/Http/Controllers/CoreX/ContactMatchShareHistoryController.php` - 3
- `app/Http/Controllers/CoreX/ContactNoteController.php` - 3
- `app/Http/Controllers/CoreX/ContactPropertyController.php` - 3, 22
- `app/Http/Controllers/CoreX/ContactRepresentativeController.php` - 3
- `app/Http/Controllers/CoreX/ContactSourceController.php` - 3
- `app/Http/Controllers/CoreX/ContactTagController.php` - 3
- `app/Http/Controllers/CoreX/ContactTestimonialController.php` - 3
- `app/Http/Controllers/CoreX/ContactTypeController.php` - 3
- `app/Http/Controllers/CoreX/DashboardController.php` - 3
- `app/Http/Controllers/CoreX/DeedsCaptureController.php` - 5
- `app/Http/Controllers/CoreX/FeatureSettingsController.php` - 3
- `app/Http/Controllers/CoreX/GuidedToursController.php` - 3
- `app/Http/Controllers/CoreX/LeaseAgreementConfirmController.php` - 3
- `app/Http/Controllers/CoreX/LeaseController.php` - 3
- `app/Http/Controllers/CoreX/LeasePortalAccessController.php` - 3
- `app/Http/Controllers/CoreX/LeaseRenewalController.php` - 3
- `app/Http/Controllers/CoreX/LeaseSettingsController.php` - 3
- `app/Http/Controllers/CoreX/LossAnalysisController.php` - 5
- `app/Http/Controllers/CoreX/MarketIntelligenceController.php` - 3
- `app/Http/Controllers/CoreX/MarketReportController.php` - 5
- `app/Http/Controllers/CoreX/OtherAgencyStockUnlockController.php` - 3
- `app/Http/Controllers/CoreX/OutreachCanvassingController.php` - 5
- `app/Http/Controllers/CoreX/OutreachQueueController.php` - 3
- `app/Http/Controllers/CoreX/P24ListingNumberFixController.php` - 3
- `app/Http/Controllers/CoreX/PhotoUploadDiagnosticsController.php` - 3
- `app/Http/Controllers/CoreX/PlaceholderController.php` - 3
- `app/Http/Controllers/CoreX/PortalLeadController.php` - 3
- `app/Http/Controllers/CoreX/PropertyAdTemplateController.php` - 3
- `app/Http/Controllers/CoreX/PropertyContactController.php` - 3
- `app/Http/Controllers/CoreX/PropertyController.php` - 3, 3594, 3595
- `app/Http/Controllers/CoreX/PropertyFileController.php` - 3
- `app/Http/Controllers/CoreX/PropertyListedDateController.php` - 3
- `app/Http/Controllers/CoreX/PropertyNoteController.php` - 3
- `app/Http/Controllers/CoreX/PropertyRentalDetailsCustomFieldController.php` - 3
- `app/Http/Controllers/CoreX/PropertySgController.php` - 5
- `app/Http/Controllers/CoreX/PropertyWizardController.php` - 3
- `app/Http/Controllers/CoreX/ProspectingListingListedDateController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationChecklistTemplateController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationCustomFieldController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationDeclineReasonTemplateController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationHighlighterController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` - 3
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalCatalogueImportController.php` - 3
- `app/Http/Controllers/CoreX/RentalCatalogueItemController.php` - 3
- `app/Http/Controllers/CoreX/RentalCommandCentreController.php` - 3
- `app/Http/Controllers/CoreX/RentalCompletionSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalCrewController.php` - 3
- `app/Http/Controllers/CoreX/RentalCrewLinkController.php` - 3
- `app/Http/Controllers/CoreX/RentalDetailsSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalEmergencyApprovalController.php` - 3
- `app/Http/Controllers/CoreX/RentalFaultAuthorityController.php` - 3
- `app/Http/Controllers/CoreX/RentalFaultReportController.php` - 3
- `app/Http/Controllers/CoreX/RentalFaultTypeCauseController.php` - 3
- `app/Http/Controllers/CoreX/RentalFaultTypeController.php` - 3
- `app/Http/Controllers/CoreX/RentalFirstRespondersController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionAttendanceController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionComparisonController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionCopiesController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionDueController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionPhotoNoteController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionRoomChecklistController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionScanController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalInspectionSigningLinkController.php` - 3
- `app/Http/Controllers/CoreX/RentalInventoryBuyerAcceptanceController.php` - 3
- `app/Http/Controllers/CoreX/RentalInventoryCaptureController.php` - 3
- `app/Http/Controllers/CoreX/RentalInventoryController.php` - 3
- `app/Http/Controllers/CoreX/RentalInventoryRecordingController.php` - 3
- `app/Http/Controllers/CoreX/RentalInventorySettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobCardController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobCardCrewLineController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobCardCrewLinkController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobCardPriceRequestController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobCardPricingController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobCardSignedCopyController.php` - 3
- `app/Http/Controllers/CoreX/RentalJobDispatchController.php` - 3
- `app/Http/Controllers/CoreX/RentalLeaseTemplateController.php` - 3
- `app/Http/Controllers/CoreX/RentalListsWizardSaver.php` - 3
- `app/Http/Controllers/CoreX/RentalNoticeController.php` - 3
- `app/Http/Controllers/CoreX/RentalNoticeTemplateController.php` - 3
- `app/Http/Controllers/CoreX/RentalPortalSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalPropertyFaultSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalReportController.php` - 3
- `app/Http/Controllers/CoreX/RentalTakeOnImportController.php` - 3
- `app/Http/Controllers/CoreX/RentalWhoPaysController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderCompletionController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderCostController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderInvoiceController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderInvoiceSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderPricingSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderQuoteController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderSettingsController.php` - 3
- `app/Http/Controllers/CoreX/RentalWorkOrderVariationController.php` - 3
- `app/Http/Controllers/CoreX/RoleManagerController.php` - 3
- `app/Http/Controllers/CoreX/SettingsController.php` - 3, 1185
- `app/Http/Controllers/CoreX/SoldPropertyImportController.php` - 3
- `app/Http/Controllers/CoreX/SyndicationApprovalController.php` - 5
- `app/Http/Controllers/CoreX/ThirdPartySaleController.php` - 5
- `app/Http/Controllers/CoreX/TrackedPropertyController.php` - 5
- `app/Http/Controllers/CoreX/WhatsNewController.php` - 3
- `app/Http/Controllers/CoreX/WhatsappOutreachSummaryController.php` - 5
- `app/Http/Controllers/DealV2/DealIcalController.php` - 102, 105, 122
- `app/Http/Controllers/Docuperfect/ESignWizardController.php` - 6192, 6198
- `app/Http/Controllers/Docuperfect/TemplateTransferController.php` - 173
- `app/Http/Controllers/MyPortal/CommunicationCaptureController.php` - 129, 130, 150, 159
- `app/Http/Controllers/PrivateProperty/AgentPpController.php` - 238
- `app/Http/Controllers/Settings/EmailSetupController.php` - 218, 219, 241, 250
- `app/Http/Middleware/EnsureAuctionRunMode.php` - 24
- `app/Http/Requests/CoreX/LeaseCaptureRequest.php` - 3
- `app/Jobs/DeliverAgencyWebhook.php` - 78, 79
- `app/Listeners/Billing/NotifyCoreXOfPlanChange.php` - 31
- `app/Listeners/MarketReports/NotifyOnMarketReportParseFailure.php` - 121
- `app/Mail/MailInterceptStillOnMail.php` - 39
- `app/Mail/QueueBacklogAlertMail.php` - 33
- `app/Mail/QueueFailedJobsGrowthAlertMail.php` - 34
- `app/Mail/QueueJobFailureDigestMail.php` - 36
- `app/Mail/QueueWorkerDownMail.php` - 30
- `app/Models/AgentApplication.php` - 94, 96
- `app/Models/BulkEmailBroadcast.php` - 56
- `app/Models/Compliance/Rcr/RcrAnswerEvidence.php` - 16
- `app/Models/Contact.php` - 642
- `app/Models/Docuperfect/EsignRecipientPreset.php` - 130
- `app/Models/Lease.php` - 87
- `app/Models/RentalInspectionSetting.php` - 388
- `app/Models/SiteConnector.php` - 66
- `app/Notifications/Compliance/RcrDeadlineApproachingNotification.php` - 61
- `app/Notifications/DealV2/DealStepAlertNotification.php` - 71
- `app/Notifications/PillarEventNotification.php` - 61
- `app/Providers/AppServiceProvider.php` - 888
- `app/Providers/OutboundMailGuardServiceProvider.php` - 155
- `app/Services/AI/Ellie/EllieAgentService.php` - 420, 424, 529, 530, 559, 563, 565
- `app/Services/AI/Ellie/EllieToolkit.php` - 73, 96, 101, 114, 129, 204, 374, 387
- `app/Services/AI/NavigationAtlasService.php` - 165
- `app/Services/AI/TourKnowledgeService.php` - 133
- `app/Services/Address/AddressMatchScorer.php` - 374
- `app/Services/Address/AddressStructurer.php` - 69
- `app/Services/ClientAuthService.php` - 352
- `app/Services/CommandCenter/OverdueSnapshotService.php` - 76
- `app/Services/Communications/TranscriptionService.php` - 52
- `app/Services/Compliance/PpraInspectionPackChecklistService.php` - 230, 286, 300
- `app/Services/Compliance/PpraSalesFileAggregationService.php` - 230
- `app/Services/Compliance/Rcr/EvidenceGatheringService.php` - 188, 189, 201, 203
- `app/Services/Compliance/WhistleblowComplaintService.php` - 1190, 1210, 1214, 1216
- `app/Services/DealV2/Dr2DistributionSendService.php` - 101
- `app/Services/Docuperfect/AgreementRecordWriteBack.php` - 152
- `app/Services/Docuperfect/ClaudeVisionParserService.php` - 230
- `app/Services/Docuperfect/ImporterAiService.php` - 160
- `app/Services/Docuperfect/TemplateTransfer/TemplatePackage.php` - 70, 79, 86, 173, 217, 221
- `app/Services/Docuperfect/TemplateTransfer/TemplatePackageImporter.php` - 230, 379
- `app/Services/Images/PropertyImageRotator.php` - 60
- `app/Services/PrivateProperty/PrivatePropertySyndicationService.php` - 124, 923, 938
- `app/Services/Properties/OtherAgencyStockAlreadyAgencyStockException.php` - 19
- `app/Services/RentalApplications/RentalApplicationChecklistService.php` - 77, 78, 264, 268
- `app/Services/Rentals/RenewalDraftService.php` - 36
- `app/Services/Rentals/RentalInspectionAttendanceService.php` - 30
- `app/Services/Rentals/RentalInspectionCalendarSyncService.php` - 157
- `app/Services/Rentals/RentalPortalAccessService.php` - 105
- `app/Services/Rentals/TakeOnImport/RentalTakeOnTemplateService.php` - 125
- `app/Services/Security/PermissionLockdownAlarm.php` - 68, 158, 159
- `app/Services/Syndication/PortalAgentGuard.php` - 45
- `app/Services/Syndication/PortalInventoryGuard.php` - 204
- `app/Services/Syndication/Property24/Property24ApiClient.php` - 544
- `app/Services/Syndication/Property24/Property24ListingMapper.php` - 283, 1575, 1586
- `app/Services/Syndication/Property24/Property24SyndicationService.php` - 1064, 1185, 1208, 1313
- `app/Services/System/ServerHealthService.php` - 44, 166
- `app/Support/IcsCalendarInvite.php` - 61
- `app/Support/OutboundMailGuard.php` - 53, 54, 79, 294
- `app/Support/Tours/TourRegistry.php` - 154, 177, 195, 208, 213, 276, 433, 466, 475, 489, 601, 609 (+13 more)
- `app/Support/Tours/defs/agency-tracker-tools.php` - 86, 295, 445, 470, 490, 492
- `app/Support/Tours/defs/assistants.php` - 192
- `app/Support/Tours/defs/auctions.php` - 56, 74, 177, 314, 350, 356, 370
- `app/Support/Tours/defs/command-center.php` - 37, 163, 168, 222, 255, 271, 281, 321
- `app/Support/Tours/defs/compliance-a.php` - 43, 130, 200
- `app/Support/Tours/defs/compliance-b.php` - 43, 142, 246, 257, 279, 283, 284, 291
- `app/Support/Tours/defs/docuperfect.php` - 162, 201, 215, 243, 290, 309, 462, 490
- `app/Support/Tours/defs/market-intelligence.php` - 36, 190, 201, 208, 229, 240, 247
- `app/Support/Tours/defs/mobile-app.php` - 24
- `app/Support/Tours/defs/outreach-compose.php` - 66
- `app/Support/Tours/defs/outreach-queue.php` - 53
- `app/Support/Tours/defs/pdf-splitter.php` - 50, 54, 56, 65, 107, 115, 123
- `app/Support/Tours/defs/portal-comms.php` - 23, 81, 109, 123, 157, 271, 290, 298, 315, 341, 348, 355 (+1 more)
- `app/Support/Tours/defs/presentations.php` - 225, 254, 294
- `app/Support/Tours/defs/property-page.php` - 60, 109, 141, 188, 213, 217, 240, 270, 285, 310, 338, 375
- `app/Support/Tours/defs/real-estate-core.php` - 87, 120, 152, 206, 282, 320, 333
- `app/Support/Tours/defs/rentals-deals.php` - 37
- `app/Support/Tours/defs/training-ai-calc.php` - 52, 73, 81, 95, 131, 230, 264, 284, 291, 298, 339, 483
- `config/clientauth.php` - 36
- `config/communications.php` - 390, 391, 392, 393, 394, 395, 396, 397
- `config/corex-billing.php` - 29, 46
- `config/corex-features.php` - 114, 144, 152, 160, 168, 184, 199, 225, 303, 313, 351, 383 (+5 more)
- `config/corex-images.php` - 36, 37
- `config/corex-navigation-atlas.php` - 176, 370, 376, 382, 404, 428
- `config/corex.php` - 19, 45, 46, 47, 51, 57, 72, 88
- `config/deals.php` - 49
- `config/esign-acknowledgement.php` - 24
- `config/integrations.php` - 35, 47, 57
- `config/mail.php` - 123, 124, 125, 126, 127, 130, 131
- `public/js/corex-ad-render.js` - 177, 1130
- `public/js/corex-connection-guard.js` - 107, 139, 211, 267, 320, 344
- `resources/js/corex-api.js` - 70, 71, 74
- `resources/views/admin/agencies/create-edit.blade.php` - 727, 878, 880, 881, 882, 885, 886, 890, 892
- `resources/views/admin/api/index.blade.php` - 14
- `resources/views/admin/assistants/_sign-in-fields.blade.php` - 55
- `resources/views/admin/assistants/show.blade.php` - 71, 72
- `resources/views/admin/billing/index.blade.php` - 31
- `resources/views/admin/company-settings/index.blade.php` - 322, 449, 827
- `resources/views/admin/demo-access/connection.blade.php` - 130, 185, 263, 275, 299, 307, 317
- `resources/views/admin/demo-connection/edit.blade.php` - 17, 31, 40, 86, 97, 118, 120
- `resources/views/admin/dev-settings/index.blade.php` - 203, 355, 508
- `resources/views/admin/ellie/reference-sources/index.blade.php` - 13, 35
- `resources/views/admin/importer/index.blade.php` - 126
- `resources/views/admin/importer/preview.blade.php` - 58
- `resources/views/admin/integrations/index.blade.php` - 10, 19
- `resources/views/admin/outbound-mail-captures/index.blade.php` - 9
- `resources/views/admin/p24-suburbs.blade.php` - 11
- `resources/views/admin/platform-company/index.blade.php` - 76, 103, 134
- `resources/views/admin/pp/agents.blade.php` - 43, 119, 120, 238
- `resources/views/admin/pp/mapping-email.blade.php` - 6, 18, 33
- `resources/views/admin/ppra-inspection-pack/transformation.blade.php` - 12, 41
- `resources/views/admin/soft-deletes/index.blade.php` - 13, 77
- `resources/views/admin/soft-deletes/show.blade.php` - 12, 69, 95
- `resources/views/admin/splitter/doc-types.blade.php` - 64
- `resources/views/admin/system-health/index.blade.php` - 129, 132, 134, 136, 143, 146, 164, 170, 178, 185, 186, 194 (+4 more)
- `resources/views/admin/system-updates/_form.blade.php` - 90
- `resources/views/admin/system-updates/bulk-email.blade.php` - 14, 67, 69, 100, 177
- `resources/views/admin/system-updates/create.blade.php` - 27
- `resources/views/admin/system-updates/edit.blade.php` - 66
- `resources/views/admin/system-updates/index.blade.php` - 14, 15
- `resources/views/admin/system-updates/preview.blade.php` - 18, 33
- `resources/views/admin/system-updates/show.blade.php` - 34
- `resources/views/admin/users/_delete-modal.blade.php` - 47
- `resources/views/admin/users/archived.blade.php` - 54, 203, 205
- `resources/views/admin/users/create-edit.blade.php` - 319, 395, 1162, 1163
- `resources/views/admin/users/index.blade.php` - 497, 498
- `resources/views/agent/assistants/matrix.blade.php` - 250, 251, 307
- `resources/views/agent/portal.blade.php` - 452, 631, 788, 804, 1089, 1121, 1155, 1201, 1206, 1280, 1307, 1346
- `resources/views/billing/index.blade.php` - 24, 334
- `resources/views/billing/no-agency.blade.php` - 20, 28
- `resources/views/command-center/calendar/_event-feedback-modal.blade.php` - 39, 461
- `resources/views/command-center/calendar/_linked-events.blade.php` - 116, 212
- `resources/views/command-center/settings/contact-governance.blade.php` - 178
- `resources/views/communications/capture-consent/my-capture.blade.php` - 14, 82
- `resources/views/communications/wa-devices/index.blade.php` - 120
- `resources/views/compliance/communication-archive/mailboxes/index.blade.php` - 10, 77, 205
- `resources/views/compliance/communications/index.blade.php` - 15
- `resources/views/compliance/rcr/export-pdf.blade.php` - 93
- `resources/views/compliance/rcr/index.blade.php` - 32, 114
- `resources/views/compliance/rcr/show.blade.php` - 146
- `resources/views/compliance/whistleblow/create.blade.php` - 75
- `resources/views/compliance/whistleblow/pdf/tier1.blade.php` - 209
- `resources/views/compliance/whistleblow/pdf/tier2.blade.php` - 192
- `resources/views/compliance/whistleblow/pdf/tier3.blade.php` - 198
- `resources/views/components/row-actions-popup.blade.php` - 56
- `resources/views/corex/address-review/edit.blade.php` - 9, 21
- `resources/views/corex/address-review/index.blade.php` - 20, 129
- `resources/views/corex/auctions/bidders/create.blade.php` - 23
- `resources/views/corex/contacts/_match-form.blade.php` - 249
- `resources/views/corex/contacts/index.blade.php` - 169
- `resources/views/corex/contacts/show.blade.php` - 366
- `resources/views/corex/diagnostics/photo-uploads.blade.php` - 12, 40, 89, 154
- `resources/views/corex/guided-tours/index.blade.php` - 53
- `resources/views/corex/map/index.blade.php` - 116, 581, 2626, 2670
- `resources/views/corex/map/partials/_embed-map.blade.php` - 109, 177
- `resources/views/corex/market-intelligence/_slideover-buyer-row.blade.php` - 53
- `resources/views/corex/market-intelligence/_slideover-tab-source.blade.php` - 28, 29
- `resources/views/corex/market-intelligence/_suburb-report-body.blade.php` - 176, 179, 508
- `resources/views/corex/market-intelligence/partials/opportunities-stats-strip.blade.php` - 19
- `resources/views/corex/market-intelligence/suburb-report-index.blade.php` - 9
- `resources/views/corex/market-intelligence/suburb-report.blade.php` - 101, 103
- `resources/views/corex/outreach-queue/index.blade.php` - 24
- `resources/views/corex/properties/import-sold.blade.php` - 47, 74
- `resources/views/corex/properties/index.blade.php` - 1114
- `resources/views/corex/properties/intelligence/_website-performance.blade.php` - 28
- `resources/views/corex/properties/partials/expiry-popup.blade.php` - 136
- `resources/views/corex/properties/partials/p24-number-fix.blade.php` - 92
- `resources/views/corex/properties/show.blade.php` - 158, 159, 160, 550, 596, 10830, 10890, 11004, 11514, 11604, 11752, 11782
- `resources/views/corex/rental-applications/review.blade.php` - 1380
- `resources/views/corex/rental-fault-reports/_who-pays-summary.blade.php` - 10
- `resources/views/corex/rental-fault-types/index.blade.php` - 84, 109
- `resources/views/corex/rental-inspections/due.blade.php` - 83
- `resources/views/corex/rental-lease-templates/_field-map.blade.php` - 10
- `resources/views/corex/rentals/command-centre/index.blade.php` - 166
- `resources/views/corex/role-manager.blade.php` - 164
- `resources/views/corex/settings.blade.php` - 600, 602, 616, 1323, 1324, 2787, 3270, 3397, 3970, 4211
- `resources/views/corex/settings/auctions.blade.php` - 33
- `resources/views/corex/settings/leases.blade.php` - 15, 113, 163, 290
- `resources/views/corex/settings/rental-applications.blade.php` - 480, 605
- `resources/views/corex/settings/rental-details.blade.php` - 17, 49
- `resources/views/corex/settings/rental-inspections.blade.php` - 78, 301, 379, 763, 796
- `resources/views/corex/tracked-properties/index.blade.php` - 27
- `resources/views/corex/whats-new/index.blade.php` - 14, 108
- `resources/views/docuperfect/compiler/index.blade.php` - 32, 49
- `resources/views/docuperfect/esign/download.blade.php` - 6
- `resources/views/docuperfect/esign/settings/finalization.blade.php` - 49, 71, 91
- `resources/views/docuperfect/importer/index.blade.php` - 13, 327
- `resources/views/docuperfect/parser-test.blade.php` - 10
- `resources/views/docuperfect/recipient-templates/index.blade.php` - 128, 156
- `resources/views/docuperfect/renderer-test.blade.php` - 6, 48
- `resources/views/docuperfect/signatures/partials/_whatsapp-resend-button.blade.php` - 133
- `resources/views/dr2/create.blade.php` - 199
- `resources/views/emails/command-center/calendar-daily-digest.blade.php` - 95
- `resources/views/emails/command-center/event-reminder.blade.php` - 46
- `resources/views/emails/command-center/task-due-digest.blade.php` - 71
- `resources/views/emails/deals-v2/daily-digest.blade.php` - 50
- `resources/views/emails/feedback-report.blade.php` - 48
- `resources/views/emails/mail-intercept-still-on.blade.php` - 6
- `resources/views/emails/oversight-nudge.blade.php` - 11
- `resources/views/filing-register/index.blade.php` - 163
- `resources/views/layouts/corex-app.blade.php` - 119
- `resources/views/layouts/corex-header.blade.php` - 13
- `resources/views/layouts/corex-sidebar.blade.php` - 436, 3427
- `resources/views/layouts/corex.blade.php` - 206
- `resources/views/layouts/partials/_session-guard.blade.php` - 29
- `resources/views/layouts/partials/help-widget.blade.php` - 68, 118, 213
- `resources/views/layouts/partials/system-update-modal.blade.php` - 56, 190
- `resources/views/marketing/social-choose-page.blade.php` - 9
- `resources/views/my-portal/communication-capture/index.blade.php` - 29
- `resources/views/partials/agency-access-consent.blade.php` - 16, 340
- `resources/views/partials/whatsapp-send-confirm-modal.blade.php` - 23
- `resources/views/payroll/leave/reports/accrual-statement.blade.php` - 80
- `resources/views/presentations/partials/_no-active-competition.blade.php` - 18, 21
- `resources/views/presentations/partials/analysis-data-review.blade.php` - 894
- `resources/views/presentations/review.blade.php` - 385
- `resources/views/presentations/show.blade.php` - 1320
- `resources/views/profile/partials/update-profile-information-form.blade.php` - 31
- `resources/views/seller-outreach/sent.blade.php` - 51
- `resources/views/settings/email-setup/index.blade.php` - 29, 57
- `resources/views/settings/prospecting/address-matching.blade.php` - 9, 44
- `resources/views/settings/prospecting/stale-rules.blade.php` - 62
- `resources/views/tools/ad-manager/templates.blade.php` - 102
- `resources/views/training-help/index.blade.php` - 13
- `resources/views/training-help/pdf.blade.php` - 30, 43
- `routes/console.php` - 103, 169, 174, 223, 246, 258, 381, 382, 433, 434, 465
- `routes/web.php` - 276, 280

