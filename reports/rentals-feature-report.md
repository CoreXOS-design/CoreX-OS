# CoreX Rentals — Feature Report

**Purpose:** source material for agency marketing. Every feature below was checked against the actual code (screens, routes, controllers, models), not just the specs.
**Audit date:** 1 November 2026. Read-only — no code was changed.

## How to read the release status

| Status | Meaning | Branch tip |
|---|---|---|
| **LIVE** | On the production system (corexos.co.za) today | `Prod` (30 Oct 2026) |
| **STAGING** | Finished and on Staging, not yet promoted to live | `Staging` (30 Oct 2026) |
| **QA1 ONLY** | Built, waiting for Johan's testing on QA1 | `QA1` (31 Oct 2026) |

- There is **no `main` branch** in the repository. The live server tracks `Prod` (per CLAUDE.md), so **LIVE = Prod**.
- The three branches stack cleanly: everything on Prod is on Staging, and everything on Staging is on QA1.
- Status was decided by **whether the code is actually present** on each branch, not by commit messages. Several spec documents carry stale labels ("QA1 only", "spec only") for features that are in fact live; the code was trusted over the labels.
- QA2 (Andre's lane) has diverged from Staging and was not used for status.
- **Marketing may only advertise items marked LIVE.**
- Most rental modules can be switched on or off per agency (feature switches) and per role (Role Manager). "LIVE" means the feature exists on the live system; whether a particular user sees it depends on those switches.

---

## 1. Rental listings

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Rentals > Properties | The agency's property list, filtered to rental stock only. | Agent, principal | Rentals > Properties | LIVE | `PropertyController::index` (route `corex.rentals.properties.index`) |
| Rental tab on a property | Rent, how it's charged (per month/week), deposit, lease type, optional advert text block, plus the agency's own extra rental fields. Also records where the water valve and DB board are (used in tenant first-aid advice). | Agent | Property > Rental tab | LIVE | `corex/properties/partials/rental-section-body.blade.php`, `PropertyRentalDetailsCustomField` |
| Rental Images | Separate dated photo galleries for move-in, move-out and any other date. | Agent | Property > Rental Images | LIVE | `PropertyController::uploadRentalImages` |
| Portal advertising of rentals | To-let listings push to Property24 and Private Property with rent, deposit and rate (per month etc.). | Agent, principal | Property > syndication | LIVE | `Property24ListingMapper.php`, `PrivatePropertyListingMapper.php` |
| Rentals > Letting > Core Matches | Matches prospective tenants to available rentals. When a tenant is approved, their matches are capped at the rent they qualified for. | Agent, principal | Rentals > Letting > Core Matches | LIVE | `corex.rentals.core-matches.*`, `SetRentalCoreMatchCeilingOnApproval` |
| Rentals > Letting > Rental Pipeline | Drag-and-drop board of prospective tenants by stage. | Agent, principal | Rentals > Letting > Rental Pipeline | LIVE | `BuyerPipelineController::index` (rental lock) |
| Rentals > Contacts | Contacts list filtered to tenants, prospective tenants and landlords. | Agent | Rentals > Contacts | LIVE | `corex.rentals.contacts.index` |
| Max admin/marketing fee on a rental listing | Agency sets a ceiling on fees for a rental listing. | Principal | Settings | LIVE | setting `rental_fee_max_amount` |

**Road to Rented** (the rentals version of Road to Sold) is **not built on any branch** — see section B.

---

## 2. Rental applications

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Applications list | Every application with search, sort, filters, status tiles, archive/restore; a "Returned" view for applications the applicant has handed back. | Agent, principal | Rentals > Letting > Applications | LIVE | `RentalApplicationController`, `corex/rental-applications/index.blade.php` |
| New Application | Pick or create the applicant, optionally the property, then email them a personal link, copy the link to share (e.g. WhatsApp) or re-issue an expired one. Only one live application per applicant. | Agent | Applications > New | LIVE | `RentalApplicationController::create/store/send`, `RentalApplicationInviteMail` |
| Online application form | Applicant fills in personal, contact, living situation, employment and lease needs with no login; saves automatically; uploads supporting documents; can also print, complete by hand and upload a scan. | Applicant | Link in the email | LIVE | `RentalApplicationSigningController`, `rental-applications/public/show.blade.php` |
| Applicant signs on screen | Two drawn signatures — declaration that the information is true, and credit-check consent (names the agency's own bureau). Every submission is sealed as a tamper-evident record with a PDF. | Applicant | Online form | LIVE | `RentalApplicationGeneration::seal()`, `RentalApplicationSignature` |
| Identity check (one-time code) | Applicant confirms ID number and a one-time email code before submitting; returning to a submitted link needs the same. Agency can switch off or tune. | Applicant | Online form | LIVE | `RentalApplicationSigningController::verifyIdentityGate` |
| Your own application form | Per field: show/hide, rename, help text, order, compulsory; marital-status options; the agency's own extra questions (including file uploads). | Principal/admin | Settings > Rental Applications | LIVE | `RentalApplicationCustomField`, settings routes `field-display`, `custom-fields` |
| Document checklist | Required documents per employment type (payslips, statements, etc.); optional rule that the checklist must be complete before approval; document age limits. | Principal/admin, agent | Settings > Rental Applications | LIVE | `RentalApplicationDocumentRequirement` |
| Review screen | Split screen: answers beside documents; highlight documents, capture income and expense lines from statements, vetting checklist, notes, file documents. | Agent | Application > Review | LIVE | `RentalApplicationReviewController` |
| Affordability check | Totals the income and expenses the agent captured and compares rent to the agency's maximum rent-to-income percentage. A manual calculator, not an automated score. | Agent | Review screen | LIVE | `RentalApplicationAssessment`, `RentalApplicationQualifyingSetting` |
| Credit check | Applicant's signed consent plus a vetting checklist where the agent records the outcome. The agent runs the check on the bureau's own website — there is **no automatic bureau connection**. | Agent | Review screen | LIVE | `RentalApplicationChecklistService` |
| FICA hand-off | After submitting, the applicant goes straight into the FICA form; agency can require FICA before approval. | Applicant, agent | Automatic | LIVE | `ApplicantFicaLink` |
| Application Authorisation | Agent submits to a named approver, who approves (with an approved rent), declines or asks for more information. Nobody approves their own work. One-step mode for one-person agencies. | Agent, principal | Rentals > Letting > Application Authorisation | LIVE | `RentalApplicationAuthorisationController` |
| Approval and decline emails | Approval email with the approved rent and a list of matching properties; decline email with the agency's own reason templates. | Agent → applicant | Review screen | LIVE | `RentalApplicationApprovedMail`, `RentalApplicationDeclineReasonTemplate` |
| Send back to applicant | Return the application with a note; applicant edits and re-signs; earlier signed versions are kept. | Agent → applicant | Review screen | LIVE | `RentalApplicationReopenedMail` |
| Link tenant to property / start the lease | Approved applicant is linked to the property, tagged as a tenant, and a lease is started pre-filled from the application. | Agent | Review screen | LIVE | `AddTenantTypeOnRentalApproval`, `LeaseController` |
| Applications on the phone app | List, create, send and share applications from the agent app. | Agent | Mobile app | LIVE | `api.php` `v1.mobile.rental-applications.*` |
| Application terms | The agency's application fee, deposit, first-lease fee, renewal fee, deposit banking details, payment reference rule, tenant information summary and "documents to accompany" list printed on the form and PDF. The fee is shown, not collected. | Principal/admin | Settings > Rental Applications / Leases | STAGING | `app/Services/Rentals/ApplicationTerms.php` |
| Fuller application form | Extra fields (tax number, marriage regime, years employed, previous landlord, banking, vehicle) and repeating sections for co-occupants, pets and the tenant's own income/expense schedule. | Applicant | Online form | STAGING | migration `2026_12_09_110000_add_extended_fields_to_rental_applications` |
| Correct details | Agent fixes a mistyped detail; every change is logged and the applicant is emailed (ID and account numbers masked). Agent can also send back specific fields for the applicant to fix. | Agent | Review screen > Correct details | STAGING | `RentalApplicationDetailEditor.php`, `RentalApplicationDetailsUpdatedMail` |
| Co-applicant | One application for a main applicant plus one co-applicant; both answer and sign; approval covers both; both become tenants on the lease. Maximum two people. | Applicant, agent | Online form, review screen | STAGING | migrations `2027_01_03_*`, `partials/co-applicant-strip.blade.php` |

---

## 3. Leases

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Leases list | All leases with search, sort, filters, "Expiring soon" tile, print, export, archive/restore. | Agent, principal | Rentals > Leases > Leases | LIVE | `LeaseController::index` |
| New lease | One capture screen: property, tenant(s), rent, deposit, lease fee, dates or month-to-month, notice and early-cancellation terms. Choose "Create lease only", "Create lease & prepare for signing", or attach a paper-signed lease. | Agent | Rentals > Leases > New lease, or from an approved application | LIVE | `LeaseCaptureService`, `capture.blade.php` |
| Lease screen (tenancy file) | Everything about the tenancy in one place: progress strip from application to move-out, next-step bar, tenancy log, notices, breaches, signers, portal access, deposit deductions, and a printable tenancy report. | Agent, principal | Click a lease | LIVE | `LeaseHubService`, `corex/leases/show.blade.php` |
| Lease agreement e-sign from capture | Opens e-sign with property, signers and lease values already filled from the capture screen. Signing order: agent, tenant(s), landlord(s). If the document is edited during signing, the agent sees the differences and confirms before the lease record is updated. | Agent, tenant, landlord | New lease > Create lease & prepare for signing | LIVE | `LeaseSigningLauncher`, `LeaseAgreementTemplateGuard` |
| Your own lease template | Agency links its own lease, renewal and addendum documents and maps their blanks to lease details. CoreX deliberately ships no standard lease. | Principal/admin | Settings > Rental Lease Templates | LIVE | `RentalLeaseTemplateController` |
| Smart lease import | When the agency imports its Word lease, CoreX recognises rent, deposit, fee, dates, etc. from the wording, and an "AI suggest" button fills unmatched blanks. | Principal/admin | Documents > template builder | LIVE | `LeaseFieldSource`, `LeasePhraseMatcher`, `CdsAiFieldSuggester` |
| Lease fee with your own name | Default lease/admin fee per agency, with the agency's own name for it (e.g. "Admin fee"); editable per lease; prints on the lease. | Principal, agent | Settings > Leases | LIVE | `LeaseSetting` (`lease_fee_label`) |
| Rent escalation | Record a new rent and effective date; future escalations apply automatically on the day. | Agent | Lease > Escalate | LIVE | `LeaseEscalation`, command `leases:apply-due-escalations` |
| Expiry tracking and alerts | Leases inside the agency's warning window show as expiring; agents get a daily alert. | Agent, principal | Leases list, Command Centre | LIVE | `CheckLeaseExpiry` |
| Lease expiry notice (CPA) | CoreX works out the Consumer Protection Act window in business days (public holidays skipped), emails the tenant the agency's own notice on the chosen day, and warns agents if it hasn't gone. | Agent; tenant receives | Lease screen | LIVE | `LeaseExpiryNoticeService`, `BusinessDayService` |
| Automatic month-to-month | A fixed-term lease that ends with no notice and no renewal switches to month-to-month by itself; the agent is told; reversible. | Automatic | Lease screen | LIVE | `LeaseAutoMonthToMonthService` |
| Renew lease | The capture screen opens pre-filled from the current lease; change only what changed. Also: paper renewal upload, record tenant/landlord not renewing, month-to-month. The new term goes live on its start date. | Agent | Lease > Renew lease | LIVE | `LeaseRenewalController`, `LeaseRenewalService` |
| Renewal e-sign | A renewal is sent for e-signature the same way as a new lease. | Agent, tenant, landlord | Renewal screen | LIVE | `LeaseSigningLauncher` |
| Edit / activate / cancel / archive / restore | Full lease management; nothing is ever permanently deleted. | Agent, principal | Lease screen | LIVE | `LeaseActivationService`, `LeaseArchiveService` |
| Deposit deductions | Lists what the move-out inspection charged to the tenant. Amounts are off by default; the agency can switch them on. CoreX records, it does not hold or pay out money. | Agent | Lease > Deposit deductions | LIVE | `LeaseDepositDeductionsController` |
| Deposit Interest Calculator | Works out interest on a deposit from the agency's monthly trust-account interest figures; PDF for agency and tenant; history. Deposit typed in by hand (not linked to a lease). | Principal, admin | Trust Interest > Deposit Interest Calculator | LIVE | `DepositInterestCalculatorController` |
| Rental Take-On Import | Load an existing rental book from CoreX's spreadsheet, another system's export (with column mapping) or a reOS Tenancy Schedule/Rent Roll. Preview every row, then create properties, contacts and leases. Never emails anyone. | Admin, principal | Rentals > Rental Take-On Import | LIVE | `RentalTakeOnImportController`, `Services/Rentals/TakeOnImport/Reos/*` |
| Leases on the phone app | Lease list and file, renewals, notices, escalation, signing actions. | Agent | Mobile app | LIVE | `MobileRentalLeaseController` |
| Co-applicant becomes a tenant on the lease | Lease capture pre-fills the co-applicant as a tenant. | Agent | New lease from application | STAGING | `LeaseController::coApplicantContact` |

---

## 4. Inspections and inventories

All inspection and inventory features are **LIVE**. The code is the same on Prod, Staging and QA1; the "QA1 only" labels in the inspection spec are out of date.

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Rental Inspections list | Every inspection with search, sort, filters, inspector filter, "Scheduled" tile, CSV export and print. | Agent, principal | Rentals > Leases > Inspections | LIVE | `RentalInspectionController::index` |
| Incoming, Outgoing, Interim and Routine inspections | All four types, started from the lease, the Command Centre or the property. One click starts the next inspection. | Agent | Lease, Command Centre, property | LIVE | `RentalInspection`, route `corex.rental-inspections.next` |
| Room-by-room checklist | Rooms (including meters) start from the agency's standard list; one-tap condition per item, room and overall notes, "All good" and "N/A" shortcuts, meter readings and keys; saves automatically. Can't be completed until every item is recorded. | Agent | Property > Inspections | LIVE | `RentalInspectionRecordingController` |
| Two agents on one inspection | Both agents' records are kept; any disagreement must be resolved before completion. | Agent | Inspection | LIVE | route `discrepancies/{discrepancy}/resolve` |
| Attendance | Records who attended and in what capacity, with invitations. | Agent | Inspection | LIVE | `RentalInspectionAttendanceController` |
| Photos | Photos per item, upload as soon as chosen; drop many at once and tag them later; photo notes; photos kept private behind secure links. | Agent | Inspection | LIVE | `public/js/corex-photo-batch-uploader.js`, `RentalInspectionPhotoNoteController` |
| Before-and-after photo compare | Side-by-side viewer matching the same spot at move-in and move-out, with automatic pairing. | Agent | Inspection | LIVE | setting `auto_pair_photos_enabled` |
| Move-out vs move-in comparison | Each item shows the move-in record next to the move-out record; the agent records a finding (wear and tear, landlord cost, charge tenant, etc.), which feeds the deposit deductions page. | Agent | Out inspection > Deposit comparison | LIVE | `RentalInspectionComparisonController` |
| Fault from an inspection | Raise a fault, work order or job card straight from a marked item. | Agent | Inspection | LIVE | `RentalInspectionFollowUpService` |
| Booking and calendar | Book, reschedule (history kept) and cancel; the booking appears on the inspector's calendar (optionally the lease agents' too). | Agent | Inspection list / lease | LIVE | `RentalInspectionCalendarSyncService` |
| Due inspections | Shows move-ins and move-outs that are due and the interim dates the agency planned, with one-click booking. | Agent | Rental Inspections > Due | LIVE | `RentalInspectionDueController` |
| Signing — every way | Tenant and landlord sign on the agent's device, by personal emailed link, by scanning a QR code, or on paper (scan uploaded). The agent signs with a PIN. Refusals are recorded with a reason. | Agent, tenant, landlord | Inspection > Signing | LIVE | `RentalInspectionSigningLinkController`, `RentalInspectionSigningController` |
| Signing window and reminders | Tenants get an agency-set number of days to sign; days left are shown and the agent is reminded. | Agent | Inspection | LIVE | `SendSigningWindowReminders` |
| Signed report is locked | Changing a signed report needs "Edit report" and everyone signs again; a report already sent is final. Tenant and landlord copies are held until everyone has signed. | Agent | Inspection | LIVE | `RentalInspection::assertContentEditable()` |
| Inspection report PDF | Branded report with letterhead, rooms, conditions, notes, photos, attendance and signatures; read-only public link. | Agent, tenant, landlord | Inspection > Report | LIVE | `RentalInspectionReportPdfService` |
| Automatic filing and sending | On completion, the signed report is filed to the property and emailed to the parties and the agency's copy addresses, with a "copies not delivered" alert. | Automatic | — | LIVE | `RentalInspectionCopiesService` |
| Paper form with scan-back | Print a blank branded form for the property; after the walk, upload the completed form and CoreX reads the ticked boxes (and optionally the handwritten notes using AI) for the agent to confirm. | Agent | Inspection | LIVE | `RentalInspectionScanController`, `RentalInspectionScanReaderService` |
| Inventory | Room-by-room list of furniture and contents with condition and photos; copy from the previous inventory; three-party signing (in person or paper); move-out comparison; signed PDF; buyer acceptance for furnished sales. **No sign-by-link or QR** for inventories. | Agent, tenant, landlord, buyer | Property > Inventory tab; Command Centre "Inventories" | LIVE | `RentalInventoryController`, `RentalInventoryComparisonService` |
| Inspections on the phone app | Full inspection and inventory capture from the agent app, including photos, signatures and completion; repeated uploads never create duplicates. | Agent | Mobile app | LIVE | `MobileRentalInspectionController`, `MobileRentalInventoryController` |
| Inspection emails | Booking, reschedule, cancel and reminder emails to tenant, landlord and inspector; signing-link emails; completion email with the PDF. | Tenant, landlord, agent | Automatic | LIVE | `RentalInspectionNotificationService`, `RentalInspectionSigningLinkMail` |

Note: the **PPRA Inspection Pack** (Admin) is a regulator-audit readiness tool for the agency, not a property inspection feature. It does pull move-in/move-out reports into sampled rental files. LIVE (`Admin\PpraInspectionPackController`).

---

## 5. Fault reports and owner approval

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Tenant reports a fault | From the portal or phone app: pick the problem type, see first-aid advice (which can name where this property's water valve or DB board is), describe it, add photos. "That fixed it" closes it as solved. Tenant can add to or withdraw a fault. | Tenant | Tenant portal > Faults | LIVE | `ClientTenantRentalsController::faultReportStore`, `rentals/portal/_fault-aid.blade.php` |
| Owner reports a problem | Owner raises a problem or requests work from their portal. | Owner | Owner portal | LIVE | `api.php` landlord `fault-reports` |
| Agent logs a fault | Agent logs a fault for a property/lease with type, cause and photos; edit, cancel, archive, restore. | Agent | Rentals > Maintenance > Fault Reports | LIVE | `RentalFaultReportController` |
| Fault Reports list | Search, sort, filter, print, export. | Agent, principal | Rentals > Maintenance > Fault Reports | LIVE | `RentalFaultReportController::index` |
| Fault Types | The agency's own fault list: category, urgency, first-aid steps, causes and documents to show tenants. Nine neutral starter types; "Other" faults can be reclassified later. | Principal | Rentals > Maintenance > Fault Types | LIVE | `RentalFaultTypeController`, `RentalFaultTypeService` |
| Fault photos | One viewer for all photos; agent can draw marks and add notes; agent chooses which photos the owner sees. | Agent | Fault screen | LIVE | `RentalFaultPhotoAnnotationService` |
| "Reported before" flag | Flags a fault reported again within the agency's window. | Agent | Fault screen | LIVE | `RentalFaultReportService` |
| Progress line | Tenant, owner and agent each see a plain-language progress line for the fault. | Tenant, owner, agent | Portal, fault screen | LIVE | `RentalFaultProgressService` |
| Send to owner | Agent sends the fault to the owner (email and portal) with selected photos; owner chooses "send someone to look and quote" or declines. | Agent → owner | Fault screen | LIVE | `RentalLandlordDecisionNeededMail` |
| Owner decides from a link | Owner approves, declines, queries, stops or resumes; "Stop" and "Query" work from email links with no login. | Owner | Email / owner portal | LIVE | `RentalOwnerHoldService` |
| Owner chasing | Owner is reminded twice automatically, then the fault comes back to the agent's queue. | Automatic | — | LIVE | `RentalOwnerChaseService` |
| Record owner's decision by phone/WhatsApp/email | Agent records what the owner said, when and how, with optional proof; saved under the agent's name and the owner is emailed. | Agent | Fault screen | LIVE | `RentalAuthorisationService::recordOwnerDecision` |
| Repair and emergency limits | The owner sets, per property, how much the agency may spend without asking, and an emergency limit. The agent can capture it on the owner's behalf; the owner can dispute it. | Owner, agent | Owner portal "How I want faults handled"; property | LIVE | `RentalOwnerFaultSettingsService` |
| Authorise within the limit / emergency approval | Agent authorises costs within the owner's limit (or emergency limit with a reason); the owner is told. | Agent | Work order | LIVE | `RentalAuthorisationService::authoriseCost`, `RentalEmergencyApprovalController` |
| Locked authorised amount | Anything above the approved amount — even R0.01 — goes back to the owner; the job can't finish until they decide. | Owner, agent | Work order | LIVE | `RentalWorkOrderVariationController` |
| Who pays | After the work, a recorded ruling of who pays (owner, tenant, deposit) with findings and evidence; the payer is emailed and may dispute. | Agent | Work order | LIVE | `RentalWhoPaysService` |
| One-form tenant fault report | One form, one submit, problem type optional, "Report a fault" button on the tenant home screen, confirmation email straight away. | Tenant | Tenant portal | QA1 ONLY | `rentals/portal/_fault-wizard.blade.php`, `RentalTenantFaultReceivedMail` |
| Send to owner in one press | No confirmation dialog. | Agent | Fault screen | QA1 ONLY | `_decision-one.blade.php` |
| "Owner authorised by phone/WhatsApp" button | One press records the owner's verbal approval of the exact quote amount. | Agent | Work order | QA1 ONLY | `RentalAuthorisationService::recordOwnerAuthorisedQuick` |

---

## 6. Quotes, contractors, work orders and job cards

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Suppliers & contractors | The agency's contractor directory: trades, preferred flag, call-out fee with history. | Agent, principal | Rentals > Maintenance > Suppliers & contractors | LIVE | `SupplierDirectoryController` |
| Who looks first / default contractor | Per property, who attends faults first (a default contractor, the owner's own contractor, etc.); warns if no contractor is loaded for that fault type. | Agent | Property / fault screen | LIVE | `RentalFaultContractorService` |
| Appoint a contractor | Appoint from the fault or work order; contractor emailed immediately; WhatsApp button opens a pre-filled message for the agent to send. | Agent | Fault / work order | LIVE | `RentalContractorJobService` |
| Contractor link (no login) | Contractor gets a private job link: accept/decline, quote, "I've started", photos, invoice, request extra money, "work finished". | Contractor | Emailed link | LIVE | `ContractorSecureLinkController` |
| Quotes | Contractor quotes on their link or the agent captures one; agent selects a quote (quotes listed on the work order — no side-by-side screen); owner approves on their link. Agency can add its fee to outside quotes. | Agent, contractor, owner | Work order | LIVE | `RentalWorkOrderQuoteController`, `RentalOwnerQuoteMail` |
| Owner's own contractor | Owner arranges and pays their own contractor. | Owner, agent | Work order | LIVE | `RentalWorkOrder::isOwnerContractor` |
| Rental Crews | The agency's own maintenance team; each crew has a standing link listing its upcoming jobs. | Principal | Rentals > Maintenance > Crews | LIVE | `RentalCrewController`, `CrewPageController` |
| Crew job link | Crew ticks tasks, adds photos and parts/labour, marks done with a signature name. | Crew | Emailed link | LIVE | `CrewJobLinkController` |
| Rental Work Orders | The repair record: list, search, filters, print, export, PDF, appointment, notes, photos, invoices, extra-cost requests, cancel/archive/restore. | Agent | Rentals > Maintenance > Rental Work Orders | LIVE | `RentalWorkOrderController` |
| Job Cards | Internal record for the agency's own team: tasks, parts and labour with markup and VAT, scheduling, sign-offs, PDF. Tenants and owners never see job cards. | Agent | Rentals > Maintenance > Job Cards | LIVE | `RentalJobCardController` |
| Parts & Labour Catalogue | The agency's price list for job cards, with markup and spreadsheet import. | Principal | Rentals > Maintenance > Parts & Labour Catalogue | LIVE | `RentalCatalogueItemController` |
| Supplier invoices | Upload invoices to the work order and share with the owner. Record only — CoreX does not pay invoices. | Contractor, agent | Work order / contractor link | LIVE | `RentalWorkOrderInvoiceController` |
| Tenant confirms the work | Tenant gets "please check the work" by email and portal; "not fixed" opens a dispute; no answer counts as accepted after the agency's window. | Tenant | Email / portal | LIVE | `RentalCompletionService` |
| Close the job + final statement | Close with final cost, who pays and findings, with safety checks (photo, cost above approval, open dispute). The owner gets a final statement email and PDF. | Agent; owner receives | Work order | LIVE | `RentalCloseGuards`, `RentalOwnerFinalStatementMail` |
| Work orders on the phone app | Create, assign, photos, invoices, complete; job cards. | Agent | Mobile app | LIVE | `api.php` `v1.mobile.work-orders.*` |
| One "Next" button that does the step | On fault, work order and job card screens the Next button performs the action (or opens a small dialog) instead of pointing to a panel. | Agent | Maintenance screens | QA1 ONLY | `app/Services/Rentals/NextAction.php` |
| One picker for contractors and crews | "Send to [contractor]" / "Assign [crew]" in one press creates the work order and sends the link. | Agent | Fault screen | QA1 ONLY | `_raise-work-order-form.blade.php` |
| Quote auto-selects and auto-authorises | A quote that arrives is selected automatically; within the owner's repair limit it is authorised at once and the contractor gets the go-ahead. | Automatic | — | QA1 ONLY | `RentalQuoteAutoAdvanceService.php` |
| Tenant told repair is arranged | Email and push to the tenant when a contractor or crew is sent. | Tenant | Automatic | QA1 ONLY | `RentalTenantRepairArrangedMail` |
| One-form "Job done" | Contractors and crews submit photos and "finished" together. | Contractor, crew | Links | QA1 ONLY | `ContractorSecureLinkController`, `CrewJobService` |
| One Close button | Close with the final cost pre-filled; blockers explained before pressing. | Agent | Work order / job card | QA1 ONLY | route `corex.rental-job-cards.close` |

---

## 7. Notices

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Notices | One screen with Breaches, Notices received, Notices sent and Templates tabs. | Agent, principal | Rentals > Notices | LIVE | `NoticesHubController` |
| Notices received | Tenant or owner gives notice in their portal (or the agent records it). CoreX flags problems (too short, too early, CPA early-cancellation rules, wrong dates). Agent accepts or rejects; accepting updates the lease and property and tells the other party. | Agent; tenant/owner give notice | Rentals > Notices > Notices received | LIVE | `LeaseNoticeReviewService` |
| Breach notices and Breach Register | Single or bulk payment-breach notices, plus other breaches, filled from the agency's own template, PDF, emailed from the agent's own mailbox, filed and shown in both portals. Remedy date worked out automatically; agents alerted when it passes. | Agent | Rentals > Notices > Breaches; Leases list bulk action | LIVE | `LeaseBreachService` |
| Notice to vacate | Issued from a breach only once the remedy date has passed; includes earlier breach notices. | Agent | Breach screen | LIVE | `LeaseBreachController` |
| Notices sent / Send notice | History of formal notices with delivery status, resend, download; send any template from a lease. | Agent | Rentals > Notices > Notices sent | LIVE | `RentalNoticeService` |
| Notice templates | Agency uploads its own Word templates (breach, vacate, expiry, general). CoreX ships no wording. | Principal/admin | Rentals > Notices > Templates | LIVE | `RentalNoticeTemplateController`, `NoticeDocxConverter` |

All notices are delivered by **email (from the agent's mailbox) and the portal**. No SMS or automatic WhatsApp.

---

## 8. Tenant and owner portals

One portal web page (`/portal`) serves both tenants and owners and uses the same connection as the phone app. Sign-in is by emailed one-time code, then a password. Access is granted automatically when a lease is signed (agency setting), or the agent sets it up and invites from the lease screen. Each portal can be switched on or off per agency. All **LIVE** unless marked.

**Tenant portal** — tabs: Home, Lease, Faults, Work orders, Notices, Documents

| Can see | Can do |
|---|---|
| Their agent's contact details, lease dates and renewal status, a FAQ built from their own lease terms, inspection dates | Report a fault with photos and first-aid advice; add to or withdraw it |
| Lease details (rent, deposit, dates, landlord name) | Confirm finished work is fixed, or say it isn't (with photos) |
| Faults and their progress; repair jobs (who's attending and when — never prices) | Report that work started (when they live there) |
| Notices they gave | Give notice |
| Documents: signed lease, inspection reports once sent, shared documents | View and download documents |

QA1 ONLY for tenants: "Report a fault" button on Home with open-fault progress, problem type optional, and instant "we have your fault report" and "repair arranged" messages.

**Owner (landlord) portal** — tabs: Home, Properties, Faults, Work orders, Notices, Documents

| Can see | Can do |
|---|---|
| Their properties, tenants and lease dates; occupancy history | Approve or decline repairs, quotes and extra costs |
| Faults with photos the agent shared, progress and who-pays ruling | Request work on a property |
| Work orders: stage, contractor, appointment, quotes, invoices (owner-facing amounts only) | Query, stop or resume a job (also from email links, no login) |
| Inspection reports once sent | Set appointments and report progress when the property is vacant |
| Documents and notices | Set "How I want faults handled": repair and emergency limits per property |
| | Give notice |

Tenants and owners **cannot** sign the lease inside the portal (lease signing is the e-sign flow) and cannot upload meter readings or bills.

**No-login links:** contractor job link, crew link, inspection signing link, public inspection and inventory reports, the tenant's "please check the work" link, the owner's "Stop/Query" email links, and the applicant's application link.

| Feature | Status | Evidence |
|---|---|---|
| Portal page, sign-in, branding, per-agency on/off | LIVE | `RentalPortalShellController`, `ClientAuthController`, `RentalPortalSetting` |
| Agent sets up and invites portal access from the lease | LIVE | `LeasePortalAccessController`, `RentalPortalInviteMail` |

---

## 9. Rentals Command Centre, reports and menu

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Rental Command Centre | The whole rental book at a glance: 12 count tiles (occupied, unoccupied, expiring, notice given, renewals, month-to-month, open faults, open work orders, inspections due, etc.); click a tile to see exactly those properties. Searchable, sortable property list; Own/Branch/All switch; print view. | Agent, principal | Rentals > Command Centre | LIVE | `RentalCommandCentreService`, `corex/rentals/command-centre/index.blade.php` |
| Needs action queue | The rentals to-do list: renewals to review, notices to review, inspections to start or finish, faults awaiting the owner, quotes to choose, contractors to appoint, jobs to sign off — each with one button to the right screen. Group and sort options, remembered per user. | Agent, principal | Command Centre | LIVE | `RentalCommandCentreService` |
| Rental Reports | Six period reports — faults, work orders, job cards, lease status, lease expiries, inspections — with filters, scope, print, PDF, Excel and CSV. | Agent, principal | Rentals > Reports | LIVE | `RentalReportController`, `RentalReportService` |
| Property History | The full rental story of one property (leases, faults, work orders, inspections); print and PDF. | Agent | Rentals > Reports | LIVE | route `corex.rentals.reports.property-history` |
| Landlord Activity Report | A one-property PDF to hand the owner showing everything that happened. | Agent → owner | Property History screen | LIVE | `landlord-activity-pdf.blade.php` |
| Rentals menu | Command Centre, Properties, Contacts, Letting (Applications, Authorisation, Core Matches, Pipeline), Leases (Leases, Inspections), Notices, Maintenance (Fault Reports, Job Cards, Crews, Catalogue, Work Orders, Suppliers, Fault Types), Take-On Import, Reports. Tabs show per permission and agency switch. | Everyone with rental access | Sidebar > Rentals | LIVE | `corex-sidebar.blade.php`, `app/Support/Navigation/Hubs/*Tabs.php` |
| Own / Branch / Agency visibility | Every rental list, report and phone-app screen limits records to the user's own, branch or whole agency, enforced in the database query. | All | Everywhere | LIVE | `PermissionService::getDataScope`, `Lease::visibleTo` |
| Command Centre and reports on the phone app | Same data for the agent app. | Agent | Mobile app | LIVE | `Api/V1/RentalCommandCentreController` |

---

## 10. Communication

| Channel | What is sent | Status |
|---|---|---|
| **Email** | About 45 rental emails, sent from the agent's own mailbox where configured. Tenants get: fault on record, status updates, appointment, "please check the work", who-pays ruling, job cancelled/on hold, notices, lease signed copy, portal invite, inspection bookings/reminders/signing links/reports. Owners get: decision needed (with reminders), what was authorised, quotes, extra costs, work finished, final statement, query answers, fault-handling settings, notices. Contractors/crews get: job, updates, cancel/hold, links. Applicants: invite, more info, approved, declined, reopened. | LIVE (tenant "fault received" and "repair arranged" emails are QA1 ONLY; applicant "details corrected" is STAGING) |
| **Phone push — agents** | About 37 rental alert types (fault created, owner decided, quote received, tenant says not fixed, notice received, expiry notice due, breach remedy date, inspection due, etc.). Each agent chooses in-app, email and/or push per alert. | LIVE |
| **Phone push — tenants and owners** | Must-receive: decision waiting (owner), please check the work (tenant), work finished (owner), repair appointment, notice update. Optional (person can switch off): fault progress, repair updates, inspection report ready. | LIVE |
| **In-app bell alerts** | Same rental alert list as above. | LIVE |
| **WhatsApp** | Buttons that open WhatsApp with a ready message for the **agent to send** (signing links, contractor links, application links). **No automatic WhatsApp sending.** | LIVE (manual only) |
| **SMS** | None. | — |

Push needs the phone-app push service configured on the server; without it, push silently does nothing (see Issues).

---

## 11. Mobile app (server side)

The phone app itself is a separate project; this audit confirms what the CoreX server supports for it. All **LIVE** (except the QA1 tenant fault changes noted earlier).

- **Agents:** Command Centre, "what's waiting for me", reports, applications, leases (renewals, notices, escalation, signing actions), notices received, inspections and inventories (full capture with photos, signatures and completion; repeated uploads never duplicate), faults, work orders, job cards. The app is told which rental screens each user may see and at what scope, and rentals are hidden for agencies or users without them. (`MobileRental*Controller`, `MobileVisibilityController`, about 136 rental routes.)
- **Tenants:** everything in the tenant portal plus push preferences; the app hides rentals for people who are not on a lease. (`ClientTenantRentalsController`, `ClientPortalController`.)
- **Owners:** everything in the owner portal plus push preferences.

---

## 12. Agency settings (what each agency can configure)

All LIVE unless marked. "Wizard" = also shown in the Agency Setup Wizard.

| Settings page | What the agency controls | Wizard |
|---|---|---|
| **Settings > Rental Applications** | Document checklist per employment type; document age limits; max rent-to-income %; credit bureau name; one-step or two-step approval and named approvers; require FICA / complete checklist before approval; rent-above-approved warn or block; field show/hide/rename/order/compulsory; custom questions; marital-status options; identity-check method; lock property after submission; tag as tenant on approval; approval and decline email wording and reasons; highlighters; pre-fill lease from application. **STAGING:** application fee, tenant summary, documents to accompany. | Most yes; caps, validity windows, custom fields and highlighters no |
| **Settings > Leases** | Expiry warning window; CPA expiry notice send and alert days; default deposit (months of rent); default lease fee and its name; tenant notice period; early-cancellation defaults; breach remedy days; notice-to-vacate days and time; automatic month-to-month; property status when a lease ends/is cancelled; re-advertise on notice; which statuses count as active rental stock. **STAGING:** renewal fee, deposit banking details, payment reference rule, deposit notes. | Yes, except active rental stock and pre-let status |
| **Settings > Rental Lease Templates** | The agency's own lease, renewal and addendum documents and field mapping. | Linked |
| **Rentals > Notices > Templates** | The agency's own notice letters. | — |
| **Settings > Rental Inspections** | Signing window; fault-report window; refusal reasons; room types, standard rooms and default items; condition words and colours; required notes; signatures required per inspection type; who gets report copies; automatic report sending; notification and reminder rules; due-inspection lead times; record deduction amounts; paper form size; tick-box reading sensitivity; AI note reading. | Yes, except three expert lists |
| **Settings > Inventory** | Condition and move-out wording; automatic report sending. | Partly |
| **Settings > Rental Work Orders** | Suggested repair limit; agents may approve quotes; agency fee on outside quotes; job card pricing and markups; quote estimate wording; tenant completion check and window; completion photo required; overdue reminder days; invoice file rules; name for the in-house team. | Yes |
| **Settings > Rental Portal** | Tenant and owner portal on/off; automatic access on lease signing; contractor and crew links and their expiry; what crews see; owner reminder timings; fault photo limits; "reported before" window; push default; sign-in rules; the 12 Home-page FAQ wordings. | Yes |
| **Settings > Rental Details** | Extra fields on the property's Rental tab. | No |
| **Maintenance tabs** | Fault types, parts and labour catalogue, crews, suppliers. | No (data, not settings) |
| **Company / agency** | Agency type (sales / rentals / both) switches the whole rentals module; trust account name; max rental admin/marketing fee. | Yes |
| **Role Manager** | Every rental action has its own permission, adjustable per agency. | — |

---

# A. Top 10 selling points (all LIVE)

1. **The whole rental book on one screen.** The Command Centre shows every rental at a glance — occupied, expiring, notices, open repairs, inspections due — with a to-do list that tells each agent exactly what to do next.
2. **Inspections that sign themselves off anywhere.** Room-by-room checklists with photos, and tenants and landlords sign on the agent's tablet, by emailed link, by scanning a QR code, or on paper.
3. **Move-out vs move-in, side by side.** Every item and photo compared automatically, with tenant charges flowing straight onto a deposit deductions page.
4. **Paper inspection forms that read themselves back in.** Upload the completed paper form and CoreX reads the ticked boxes for you.
5. **Online rental applications with signatures and identity checks.** The applicant applies, uploads documents, signs and consents on their phone; you review answers beside documents and approve with a proper sign-off trail.
6. **Leases that write themselves into e-sign.** Capture the lease once and it opens in e-signature already filled in, in the agency's own lease wording, signed in the right order.
7. **Lease deadlines handled for you.** CPA expiry notices calculated in business days and sent on time, escalations applied on the day, and expired leases moving to month-to-month automatically.
8. **Repairs run within the owner's own limits.** Owners set how much may be spent without asking; anything over goes back to them with one click from their email, and they get a final statement at the end.
9. **Contractors and crews without logins.** Contractors quote, report progress, upload photos and invoices from a single link; your own maintenance teams work from theirs.
10. **Portals and phone app for tenants and owners.** Tenants report faults with photos and track progress; owners approve repairs, see reports and documents, and get push notifications — with no extra admin for the agent.

Also strong, also LIVE: breach notices and notice-to-vacate built from the agency's own templates; rental take-on import (including from reOS); six rental reports with Excel/PDF export; full agent phone-app coverage of rentals.

---

# B. In spec, not built (do not market)

| Item | Detail |
|---|---|
| Road to Rented | The rentals version of Road to Sold. No screen, menu or permission on any branch; only placeholders in the code. |
| Rent money: invoices, payments, arrears, statements, rent splits, trust accounting | Specified (rental-money spec) but nothing built. The "last paid" column on breaches shows a dash; take-on arrears are a note only. |
| Outstanding / debtors report | Depends on the rent money stage. |
| Deposit settlement and refund | CoreX records deductions only; it does not hold, settle or refund deposits. The interest calculator is not linked to leases. |
| Automated credit/TPN checks and automatic affordability scoring | Credit check is consent plus a manual checklist; affordability is a manual calculator. |
| Application fee collection | The fee is displayed only (and only on Staging). |
| More than two applicants on one application | Co-applicant (Staging) is limited to one extra person; the co-applicant has no own emails, identity code or FICA link yet. |
| Automatic WhatsApp and any SMS | WhatsApp is manual (agent sends); there is no SMS. |
| Owner live link for rentals | Draft spec only; no code. |
| Tenant/owner upload of meter readings and bills | Draft spec only; no code. |
| One-click renewal / automatic renewal drafting | Renewal is the pre-filled screen; automatic drafting was built and deliberately reverted. |
| Sign inventory by link or QR | Only inspections have this. |
| Book a move-in inspection while the lease is still being signed | Still needs an active lease. |
| Tenant 7-day fault rule on inspections | The setting exists; nothing applies it. |
| Agency-editable inspection email text | Only the subject line is editable. |
| Inventory starter lists; authority-letter upload for someone attending on behalf | Not built. |
| Fault routing profiles (auto-send faults to contractors by type) | Rejected by Johan; no code. |
| In-app messaging between parties; contractor logins; paying supplier invoices | Out of scope by decision. |
| A CoreX standard lease or standard notice wording | Deliberately none — each agency uses its own. |

---

# C. Issues found (not fixed)

| # | Issue | Where | Why it matters |
|---|---|---|---|
| 1 | **Automatic quote approval ignores the "Agents can approve quotes" switch** (QA1 ONLY). The new automatic approval checks only the owner's repair limit; it never looks at the agency switch. With the switch off, a quote within the limit is still approved with no branch manager. | `app/Services/Rentals/RentalQuoteAutoAdvanceService.php:62-77`; `RentalAuthorisationService::costEvaluation` (`agent_can` comes from the limit only) | Contradicts the setting's own description. Johan to decide whether intended before promotion. |
| 2 | **Command Centre and Rental Reports are switched off by default for branch managers and agents.** The permissions exist but no role includes them by default (same for job cards, catalogue and take-on import). | `config/corex-permissions.php:130` and `:290` | A new agency (e.g. Cape Town) will see no Command Centre or Reports until someone enables them in Role Manager. |
| 3 | **Rental document types list is shared across all agencies.** No agency column; one agency's edits change everyone's list. | `database/migrations/2026_02_26_950001_create_rental_properties_and_document_types_tables.php`; `app/Models/Rental/RentalDocumentType.php`; edit route `routes/web.php:8004` | Breaks the multi-agency rule. Still open from the 8 Oct cross-cut audit. |
| 4 | **"Active rental stock" setting is not in the Setup Wizard** and not recorded as a deliberate omission. It drives the Command Centre's occupied/unoccupied tiles. | `resources/views/corex/settings/leases.blade.php:442-447`; absent from `config/agency-onboarding-copy.php` | Rule 10a gap. Also not in the wizard: Rental Details fields, application caps/validity windows/custom fields, three inspection expert lists, inventory disposition presets — only some are on the omission list. |
| 5 | **Inspection "notify via WhatsApp" setting does not send anything.** It only logs a note for the agent to send by hand. | `app/Services/Rentals/RentalInspectionNotificationService.php:77-80, 251` | An agency switching it on will expect tenants to receive WhatsApps. |
| 6 | **Push notifications fail silently** if the push service isn't configured: no warning or log. | `app/Providers/AppServiceProvider.php:140-157` | Settings show push "on" while nothing is delivered. |
| 7 | **Rental Upload & Send page has no menu link.** Only reachable by typing the address. | `routes/web.php:7826` | Hidden feature; awaiting Johan's decision (noted in the 8 Oct audit). |
| 8 | **Supplier job-request email appears unused.** No code found that sends it. | `app/Mail/Rentals/RentalWorkOrderSupplierMail.php` | Possible dead code; not confirmed. |
| 9 | **Old fault approval route still active** although replaced by the owner-decision popup. | `routes/web.php:4801` (`corex.rental-fault-reports.approval.store`) | Legacy path; noted in spec, not removed. |
| 10 | **Deposit amounts off by default for every agency.** This follows an HFC decision ("reOS handles money"). | setting `record_deduction_amounts` | A second agency must know to switch it on. |
| 11 | **Two similar lease-expiry settings** (calendar-day warning window vs CPA business-day send/alert days) can be set inconsistently. | `CheckLeaseExpiry.php:66`, `LeaseHubService.php:38` | Clarity risk in demos and setup. |
| 12 | **Take-on import is visible to anyone with its view permission**, whereas the spec describes it as admin-only. | `corex-sidebar.blade.php` ~1228 | Depends on role setup; not verified per role. |
| 13 | **Deposit Interest Calculator is not in the Rentals menu** (it sits under Trust Interest). | Sidebar | Rentals agents won't find it from a lease. |
| 14 | **Code comment names HFC.** No behaviour impact. | `app/Models/RentalApplicationQualifyingSetting.php:238` | Multi-agency hygiene. |
| 15 | **Stale spec labels** understate what is live: `rental-inspections.md` (§46–69 "QA1 only"), `leases.md` (lease fee "QA1 only"), `rental-renewals.md` ("spec only"), `esign-lease-field-source.md` ("Staging"), `rental-application-field-config.md` ("not built"), `rental-property-tab.md` ("in progress"), `rentals-menu-tabs.md`, `rental-documents-spec.md`. | `.ai/specs/` | Anyone writing marketing from the specs would undersell the product. |

**Not verified end to end** (existence of code confirmed only): the tenant "won" trigger when a rental is let, rental listing pushes against the live Property24/Private Property systems, and the phone app screens themselves (separate project).
