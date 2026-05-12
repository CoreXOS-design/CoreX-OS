# CoreX OS Capability Audit

**Generated:** 2026-05-12
**Purpose:** Complete inventory of user-facing capabilities for 20 May staff training session
**Status:** Working document — not yet committed

---

## 1. Route Inventory

**Total routes: 1,223** (504 GET, 548 POST, 60 PUT, 27 PATCH, 79 DELETE, 5 PUT|PATCH)

### Auth (34 routes)
| Method | URI | Controller | Name | Permission |
|--------|-----|-----------|------|------------|
| GET | /login | AuthenticatedSessionController@create | login | none |
| POST | /login | AuthenticatedSessionController@store | — | none |
| POST | /logout | AuthenticatedSessionController@destroy | logout | none |
| GET | /register | RegisteredUserController@create | register | none |
| POST | /register | RegisteredUserController@store | — | none |
| GET | /forgot-password | PasswordResetLinkController@create | password.request | none |
| POST | /forgot-password | PasswordResetLinkController@store | password.email | none |
| GET | /reset-password/{token} | NewPasswordController@create | password.reset | none |
| POST | /reset-password | NewPasswordController@store | password.store | none |
| GET | /verify-email | EmailVerificationPromptController | verification.notice | none |
| GET | /verify-email/{id}/{hash} | VerifyEmailController | verification.verify | none |
| POST | /email/verification-notification | EmailVerificationNotificationController@store | verification.send | none |
| GET | /confirm-password | ConfirmablePasswordController@show | password.confirm | none |
| PUT | /password | PasswordController@update | password.update | none |
| GET | /account-setup/{user} | AccountSetupController@show | account.setup | none (signed URL) |
| POST | /account-setup/{user} | AccountSetupController@store | account.setup.store | none |
| POST | /api/login | Closure | — | none |
| POST | /api/logout | Closure | — | none |
| POST | /api/v1/client-auth/login | ClientAuthController@login | client-auth.login | none |
| POST | /api/v1/client-auth/logout | ClientAuthController@logout | client-auth.logout | none |
| POST | /api/v1/client-auth/password/* | ClientAuthController | client-auth.password.* | none |
| POST | /api/v1/client-auth/lookup | ClientAuthController@lookup | client-auth.lookup | none |
| POST | /api/v1/client-auth/otp/send | ClientAuthController@sendOtp | client-auth.otp.send | none |
| POST | /api/v1/client-auth/otp/verify | ClientAuthController@verifyOtp | client-auth.otp.verify | none |
| POST | /api/v1/client-auth/agency/select | ClientAuthController@selectAgency | client-auth.agency.select | none |

**Client App auth** uses OTP-based login (lookup by phone/email, send OTP, verify OTP), not username/password.

### Onboarding (21 routes)
| Method | URI | Name | What it does |
|--------|-----|------|-------------|
| GET | /corex/onboarding | onboarding.index | Admin onboarding queue |
| POST | /corex/onboarding | onboarding.store | Create new application |
| GET | /corex/onboarding/create | onboarding.create | New application form |
| GET | /corex/onboarding/{application} | onboarding.show | View application detail |
| POST | /corex/onboarding/{application}/activate | onboarding.activate | Activate approved applicant |
| POST | /corex/onboarding/{application}/status | onboarding.status | Update application status |
| POST | /corex/onboarding/{application}/upload | onboarding.upload | Upload document for applicant |
| POST | /corex/onboarding/checklist/{item}/toggle | onboarding.toggle-checklist | Toggle checklist item |
| POST | /corex/onboarding/document/{doc}/verify | onboarding.verify-document | Verify uploaded doc |
| GET | /onboarding/{token} | onboarding.portal.welcome | Public portal — welcome page |
| GET | /onboarding/{token}/review | onboarding.portal.review | Public portal — review listings |
| GET | /onboarding/{token}/finish | onboarding.portal.finish | Public portal — finish |
| GET | /onboarding/{token}/status | onboarding.portal.status | Public portal — status check |
| POST | /onboarding/{token}/rows/* | onboarding.portal.* | Bulk confirm/exclude/reassign rows |

### Command Center / Dashboard (65 routes)

**Sidebar:** Dashboard > Today, Calendar, Invitations, Event Classes, Tasks, My Performance, Branch Report, Agency Report, Buyer Pipeline, Lost Deals, Oversight, Performance, User Settings

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Today / Dashboard | GET /corex (corex.dashboard), GET /corex/command-center/today | view_dashboard |
| Calendar | GET/POST /corex/command-center/calendar | command_center.calendar.* |
| Calendar Events JSON | GET /corex/command-center/calendar/events | — |
| Calendar Invitations | GET /corex/command-center/calendar/invitations | — |
| Calendar Feedback | GET/POST /corex/command-center/calendar/{event}/feedback | — |
| Calendar Reschedule | PATCH /corex/command-center/calendar/{event}/reschedule | — |
| Event Classes | GET /corex/command-center/settings/event-classes | admin/super_admin/owner |
| Tasks (Kanban) | GET/POST /corex/command-center/tasks | command_center.tasks.* |
| Task Complete | POST /corex/command-center/tasks/{task}/complete | — |
| Task Archive Done | POST /corex/command-center/tasks/archive-done | — |
| Buyer Pipeline | GET /corex/command-center/buyers/pipeline | — |
| Buyer Detail | GET /corex/command-center/buyers/{contact} | — |
| Lost Deals | GET /corex/command-center/lost-deals | command_center.settings |
| Manager Oversight | GET /corex/dashboard/oversight | dashboard.oversight.view |
| Oversight Nudge | POST /corex/dashboard/oversight/nudge | dashboard.oversight.manage |
| Performance | GET /corex/command-center/performance | view_dashboard |
| Reporting - Agent | GET /corex/command-center/reporting/agent | — |
| Reporting - Branch | GET /corex/command-center/reporting/branch | dashboard.oversight.view |
| Reporting - Agency | GET /corex/command-center/reporting/agency | admin/super_admin/owner |
| User Settings | GET/PUT /corex/command-center/user-settings | — |
| Contact Governance | GET /corex/command-center/settings/contact-governance | command_center.settings |
| Market Intelligence | GET /corex/command-center/settings/market-intelligence | command_center.settings |
| Duplicate Cleanup | GET /corex/command-center/admin/duplicate-cleanup | command_center.settings |
| Feedback Reports | GET /corex/command-center/feedback-reports | command_center.settings |

### Real Estate Group (sidebar)

**Sidebar:** Real Estate > Prospecting, Properties, Contacts, Core Matches, Presentations, Commercial Evaluations, P24 Alerts

#### Properties (75 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| List Properties | GET /corex/properties | access_properties |
| Create Property (form) | GET /corex/properties/create | access_properties |
| Property Wizard | GET /corex/properties/wizard | access_properties |
| View Property | GET /corex/properties/{property} | access_properties |
| Edit Property | GET /corex/properties/{property}/edit | access_properties |
| Delete (soft) | DELETE /corex/properties/{property} | access_properties |
| Restore | POST /corex/properties/{property}/restore | access_properties |
| Duplicate | POST /corex/properties/{property}/duplicate | access_properties |
| Go Live | POST /corex/properties/{property}/go-live | access_properties |
| Publish Toggle | POST /corex/properties/{property}/publish-toggle | access_properties |
| Property Files (upload/delete/tag) | POST/DELETE /corex/properties/{property}/files/* | access_properties |
| Property Notes | POST/DELETE /corex/properties/{property}/notes/* | access_properties |
| Property Images | POST /corex/properties/{property}/reorder-images, delete-image | access_properties |
| Link Contacts | POST /corex/properties/{property}/contacts/link | access_properties |
| Unlink Contacts | DELETE /corex/properties/{property}/contacts/{contact} | access_properties |
| Create & Link Contact | POST /corex/properties/{property}/contacts/create-link | access_properties |
| PP Syndication | POST /corex/properties/{property}/syndication/* | access_properties |
| P24 Syndication | POST /corex/properties/{property}/p24-syndication/* | access_properties |
| Syndication Readiness | GET /corex/properties/{property}/syndication/readiness | access_properties |
| Marketing | GET /corex/properties/{property}/marketing | access_properties |
| Social Publish | POST /corex/properties/{property}/marketing/publish | access_properties |
| Generate Ad Copy | POST /corex/properties/{property}/marketing/generate-copy | access_properties |
| Ad Template Builder | GET /corex/ad-templates/builder | access_properties |
| Seller Links | POST /corex/properties/seller-links/generate | access_properties |
| Mark Sold | POST /corex/properties/mark-sold | access_properties |
| Live Preview | GET /corex/properties/{property}/preview/{slug?} | none (public) |
| Public Agency Properties | GET /{agencySlug}/properties | none (public) |

#### Contacts (44 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| List Contacts | GET /corex/contacts | access_contacts |
| Create Contact | POST /corex/contacts | access_contacts |
| Check Duplicate | POST /corex/contacts/check-duplicate | access_contacts |
| Import Contacts | POST /corex/contacts/import | access_contacts |
| View Contact | GET /corex/contacts/{contact} | access_contacts |
| Edit Contact | PUT /corex/contacts/{contact} | access_contacts |
| Delete (soft) | DELETE /corex/contacts/{contact} | access_contacts |
| Destroy All | DELETE /corex/contacts/destroy-all | access_contacts |
| Contact Documents (upload/delete/download/tag) | POST/DELETE/GET /corex/contacts/{contact}/documents/* | access_contacts |
| Contact Notes | POST/DELETE /corex/contacts/{contact}/notes/* | access_contacts |
| Contact Tags | POST /corex/contacts/{contact}/tags | access_contacts |
| Link Property | POST /corex/contacts/{contact}/properties/link | access_contacts |
| Unlink Property | DELETE /corex/contacts/{contact}/properties/{property} | access_contacts |
| Search Properties | GET /corex/contacts/{contact}/properties/search | access_contacts |
| Record Consent | POST /corex/contacts/{contact}/consent/record | access_contacts |
| Revoke Consent | POST /corex/contacts/{contact}/consent/revoke | access_contacts |
| Increment Channel | POST /corex/contacts/{contact}/increment | access_contacts |
| Touch (update last contact) | POST /corex/contacts/{contact}/touch | access_contacts |
| Client App Login | POST /corex/contacts/{contact}/client-login | access_contacts |
| Contact Match (create/update/delete/convert/hide/results) | /corex/contacts/{contact}/matches/* | access_contacts |

#### Core Matches (via Contacts)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Core Matches Index | GET /corex/core-matches | access_contacts |
| Per-contact match CRUD | /corex/contacts/{contact}/matches/* | access_contacts |
| Convert to Deal | POST /corex/contacts/{contact}/matches/{match}/convert/{property} | core_matches.convert_to_deal |

#### Prospecting (11 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Prospecting Index | GET /prospecting | access_prospecting |
| View Listing | GET /prospecting/{listing} | access_prospecting |
| Claim Listing | POST /prospecting/{listing}/claim | access_prospecting |
| Release Listing | POST /prospecting/{listing}/release | access_prospecting |
| Feedback | POST /prospecting/{listing}/feedback | access_prospecting |
| Thumbnail | GET /prospecting/thumbnail/{listing} | access_prospecting |

#### Presentations (43 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| List Presentations | GET /presentations | access_presentations |
| Create Presentation | GET /presentations/create, POST /presentations | access_presentations |
| View/Edit | GET /presentations/{presentation}, /edit | access_presentations |
| Run Analysis | POST /presentations/{presentation}/analysis/run | access_presentations |
| Compile Pack | POST /presentations/{presentation}/compile | access_presentations |
| Pricing Simulator | GET/POST /presentations/{presentation}/pricing-simulator/* | access_presentations |
| Competitive Threats | POST /presentations/{presentation}/competitive-threats | access_presentations |
| Upload Documents | POST /presentations/{presentation}/upload | access_presentations |
| Links (P24/PP URLs) | POST/DELETE /presentations/{presentation}/links/* | access_presentations |
| Snapshots | POST/GET /presentations/{presentation}/snapshots/* | access_presentations |
| Seller Live | GET /presentations/{presentation}/seller-live | access_presentations |
| Download PDF/Pack | GET /presentations/{presentation}/versions/{version}/pdf | access_presentations |
| Portal Captures | GET/POST /presentations/{presentation}/portal-captures/* | access_presentations |

#### Commercial Evaluations (21 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| List/Create/Show/Edit | CRUD /commercial-evaluations/* | access_commercial_evaluations |
| Assets/Comparables/Crops/Units/Livestock/Financials | Sub-resource CRUD | access_commercial_evaluations |
| Evaluate | POST /commercial-evaluations/{evaluation}/evaluate | access_commercial_evaluations |
| Download PDF | GET /commercial-evaluations/{evaluation}/pdf | access_commercial_evaluations |

### Documents / E-Sign (190 routes)

**Sidebar:** Documents > Create Document, E-Sign Document, My E-Sign Documents, Authorise Documents, My Documents, Packs, Web Packs, Clause Library, Template Management, Field Groups, Import Document

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Dashboard (My Documents) | GET /docuperfect | access_docuperfect |
| Create Document (non-esign) | GET /docuperfect/create | access_docuperfect |
| E-Sign Wizard Create | GET /docuperfect/esign/create | access_docuperfect |
| E-Sign Wizard Steps | GET/POST /docuperfect/esign/{flow}/step/{step} | access_docuperfect |
| Prepare Signing | POST /docuperfect/esign/{flow}/prepare-signing | access_docuperfect |
| Prepare Wet Ink | POST /docuperfect/esign/{flow}/prepare-wet-ink | access_docuperfect |
| Prepare Download | POST /docuperfect/esign/{flow}/prepare-download | access_docuperfect |
| My E-Sign Documents | GET /docuperfect/esign/my-documents | access_docuperfect |
| Cancel Document | POST /docuperfect/esign/documents/{st}/cancel | access_docuperfect |
| Download Document | GET /docuperfect/esign/download/{document} | access_docuperfect |
| Pack Chain Init | POST /docuperfect/esign/pack-chain/init | access_docuperfect |
| Duplicate FICA per Party | POST /docuperfect/esign/{flow}/duplicate-fica | access_docuperfect |
| **Signing (Internal)** | | |
| Sign Document | GET /docuperfect/documents/{document}/sign | access_docuperfect |
| Send for Signature | POST /docuperfect/documents/{document}/send-for-signature | access_docuperfect |
| Review | GET /docuperfect/documents/{document}/signatures/review | access_docuperfect |
| Setup | GET /docuperfect/documents/{document}/signatures/setup | access_docuperfect |
| Save Markers | POST /docuperfect/documents/{document}/signatures/markers | access_docuperfect |
| Audit Trail | GET /docuperfect/documents/{document}/signatures/audit | access_docuperfect |
| Download Signed | GET /docuperfect/documents/{document}/signatures/download | access_docuperfect |
| Authorise Signing | GET /docuperfect/documents/{document}/signatures/authorise-signing | access_docuperfect |
| Supersede | POST /docuperfect/documents/{document}/supersede | access_docuperfect |
| Amendments | GET/POST /docuperfect/documents/{document}/amendments/* | access_docuperfect |
| Wet Ink Review/Decision | GET/POST /docuperfect/documents/{document}/signatures/inspect/* | access_docuperfect |
| **External Signing (public)** | | |
| Sign Page | GET /sign/{token} | none |
| Gateway | GET /sign/{token}/gateway | none |
| Consent | GET/POST /sign/{token}/consent | none |
| Capture Signature | POST /sign/{token}/capture/{marker} | none |
| Complete | POST /sign/{token}/complete | none |
| Choose Method | POST /sign/{token}/choose-method | none |
| Amendment Review | GET /sign/{token}/amendment-review | none |
| Upload Wet Ink | POST /sign/{token}/upload | none |
| Decline | POST /sign/{token}/decline | none |
| **Templates** | | |
| Template List | GET /docuperfect/templates | access_docuperfect |
| Upload Template | POST /docuperfect/templates/upload | access_docuperfect |
| Edit Template | GET /docuperfect/templates/{id}/edit | access_docuperfect |
| Wizard Config | GET/POST /docuperfect/templates/{id}/wizard-config | access_docuperfect |
| CDS Builder | GET /docuperfect/templates/cds/builder/{draft} | access_docuperfect |
| **Packs** | | |
| Pack List | GET /docuperfect/packs | access_docuperfect |
| Create/Edit Pack | CRUD /docuperfect/packs/* | access_docuperfect |
| Launch Pack | GET/POST /docuperfect/packs/{id}/launch | access_docuperfect |
| Web Packs | CRUD /docuperfect/web-packs/* | access_docuperfect |
| **Clauses** | | |
| Clause Library | GET /docuperfect/clauses | access_docuperfect |
| CRUD Clauses | POST/PUT/DELETE /docuperfect/clauses/* | access_docuperfect |
| **Field Groups** | | |
| Field Groups | GET /docuperfect/field-groups | access_docuperfect |
| CRUD Field Groups | POST/PUT/DELETE /docuperfect/field-groups/* | access_docuperfect |
| **Document Importer** | | |
| Import Index | GET /docuperfect/import | access_docuperfect |
| Parse/Generate/Review | POST /docuperfect/import/* | access_docuperfect |
| **Leases** | | |
| Lease List | GET /docuperfect/leases | access_docuperfect |
| Renew/Terminate | POST /docuperfect/leases/{lease}/* | access_docuperfect |
| **Sales Documents** | | |
| Sales Index | GET /docuperfect/sales | access_docuperfect |
| Send to Client | GET/POST /docuperfect/sales/send | access_docuperfect |
| Public Upload Return | GET/POST /sales-documents/return/{token} | none |

### Compliance (99 routes)

**Sidebar:** Compliance > FICA, RMCP, RMCP Dashboard, Staff Screening, Agent Compliance, Verification Queue, Document Types, Agency Documents, Compliance Reporting, Communications Log, Send Standalone Info Pack

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| **FICA** | | |
| FICA Index | GET /corex/compliance/fica | access_compliance |
| Create FICA Request | GET /corex/compliance/fica/create | access_compliance |
| Store FICA Request | POST /corex/compliance/fica | access_compliance |
| Wet Ink FICA | GET/POST /corex/compliance/fica/wet-ink/* | access_compliance |
| View Submission | GET /corex/compliance/fica/{submission} | access_compliance |
| Agent Approve | POST /corex/compliance/fica/{submission}/agent-approve | access_compliance |
| Agent Upload | POST /corex/compliance/fica/{submission}/agent-upload | access_compliance |
| Compliance Review | GET /corex/compliance/fica/{submission}/compliance-review | access_compliance |
| Compliance Approve | POST /corex/compliance/fica/{submission}/compliance-approve | access_compliance |
| Compliance Reject | POST /corex/compliance/fica/{submission}/compliance-reject | access_compliance |
| Request Corrections | POST /corex/compliance/fica/{submission}/request-corrections | access_compliance |
| Resubmit Corrections | POST /corex/compliance/fica/{submission}/resubmit-corrections | access_compliance |
| Reopen Rejected | POST /corex/compliance/fica/{submission}/reopen | access_compliance |
| Resend | POST /corex/compliance/fica/{submission}/resend | access_compliance |
| Cancel | POST /corex/compliance/fica/{submission}/cancel | access_compliance |
| Download PDF | GET /corex/compliance/fica/{submission}/pdf | access_compliance |
| Public FICA Form | GET /fica/{token} | none (public token) |
| Public FICA Submit | POST /fica/{token} | none |
| Public FICA Upload | POST /fica/{token}/upload | none |
| **RMCP** | | |
| RMCP Manager Index | GET /corex/compliance/rmcp-manager | access_rmcp |
| Create Version | GET /corex/compliance/rmcp-manager/create | edit_rmcp |
| View/Edit Version | GET /corex/compliance/rmcp-manager/{version} | access_rmcp / edit_rmcp |
| Approve | GET/POST /corex/compliance/rmcp-manager/{version}/approve | approve_rmcp |
| Variables | GET /corex/compliance/rmcp-manager/variables | edit_rmcp |
| Download PDF | GET /corex/compliance/rmcp-manager/{version}/pdf | access_rmcp |
| RMCP Dashboard | GET /corex/compliance/rmcp-dashboard | access_compliance_dashboard |
| Send Reminder | POST /corex/compliance/rmcp-dashboard/reminder | access_compliance_dashboard |
| RMCP Acknowledgement (My Portal) | GET/POST /corex/my-portal/rmcp/acknowledge/* | access_rmcp |
| **Whistleblower** | | |
| Complaints Index | GET /corex/compliance/whistleblow | compliance.whistleblow.view |
| File New Complaint | GET /corex/compliance/whistleblow/new | compliance.whistleblow.create |
| Store Complaint | POST /corex/compliance/whistleblow | compliance.whistleblow.create |
| View Complaint | GET /corex/compliance/whistleblow/{complaint} | compliance.whistleblow.view |
| Approve | POST /corex/compliance/whistleblow/{complaint}/approve | compliance.whistleblow.approve |
| Reject | POST /corex/compliance/whistleblow/{complaint}/reject | compliance.whistleblow.approve |
| Request Changes | POST /corex/compliance/whistleblow/{complaint}/request-changes | compliance.whistleblow.approve |
| Lawyer Review Pack | GET /corex/compliance/whistleblow/lawyer-review-pack | compliance.whistleblow.configure |
| **Seller Info Pack** | | |
| Index | GET /corex/compliance/seller-info | compliance.whistleblow.view |
| Preview | POST /corex/compliance/seller-info/preview | compliance.whistleblow.view |
| Send | POST /corex/compliance/seller-info/send | compliance.whistleblow.view |
| WhatsApp Link | POST /corex/compliance/seller-info/whatsapp-link | compliance.whistleblow.view |
| Public View | GET /info/{token} | none (public) |
| **Verification Queue** | | |
| Queue Index | GET /corex/compliance/verification-queue | verify_user_documents |
| View Document | GET /corex/compliance/verification-queue/{doc} | verify_user_documents |
| Verify | POST /corex/compliance/verification-queue/{doc}/verify | verify_user_documents |
| Reject | POST /corex/compliance/verification-queue/{doc}/reject | verify_user_documents |
| Mark Expired | POST /corex/compliance/verification-queue/{doc}/expire | verify_user_documents |
| **Staff Screening** | | |
| Screening Index | GET /corex/compliance/screenings | manage_employee_screenings |
| Create Screening | GET /corex/compliance/screenings/create/{user?} | manage_employee_screenings |
| View/Complete/Flag | GET/POST /corex/compliance/screenings/{screening}/* | manage_employee_screenings |
| Overdue Report | GET /corex/compliance/screenings/overdue | manage_employee_screenings |
| Screening Dashboard | GET /corex/compliance/screening-dashboard | access_compliance_dashboard |
| My Screenings | GET /corex/my-portal/my-screenings | view_own_screening |
| **Other Compliance** | | |
| Agent Compliance | GET /corex/compliance/agents | owner/super_admin |
| Document Types Config | CRUD /corex/compliance/document-types/* | manage_agency_compliance |
| Agency Settings | CRUD /corex/compliance/agency-settings/* | manage_agency_compliance |
| Communications Log | GET /corex/compliance/communications | compliance.whistleblow.view |
| Compliance Overrides | POST /corex/admin/compliance-overrides/* | manage_user_compliance |

### Agency Tracker (sidebar group)

**Sidebar:** Agency Tracker > Worksheet, My Listing Stock, Rentals, (My Performance sub), (Branch sub), (Admin sub), (Commission sub), (Tools sub)

#### Performance / Finance (44 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Agent Performance | GET /admin/agent/{userId}/performance | view_performance |
| Branch Performance | GET /admin/branch/{branchId}/performance | view_performance |
| Company Performance | GET /admin/performance | view_performance |
| BM Performance | GET /bm/performance | view_performance |
| Worksheet | GET /worksheet | view_worksheet |
| Worksheet Store | POST /worksheet | view_worksheet |
| Worksheet Market (BM) | GET /bm/worksheet-market | access_worksheet_market |
| Worksheet Market (Admin) | GET /admin/worksheet-market | edit_worksheet |
| Targets | GET /admin/targets | manage_targets |
| Activity Definitions | GET /admin/targets/activity-definitions | manage_targets |
| Monthly Goals | GET /admin/monthly-goals | manage_targets |
| Commission Index | GET /corex/commission | none (role-checked) |
| Commission Principal | GET /corex/commission/principal | none (role-checked) |
| Commission Confirm/Pay | POST /corex/commission/{entry}/* | none (role-checked) |
| Commission Calculator (tool) | GET /tools/commission | access_calculators |
| CMA Generator | GET /tools/cma | access_calculators |
| Revenue Share Calculator | GET /corex/revenue-share/calculator | none |
| My Earnings | GET /corex/my-earnings | none |
| Finance Definitions | GET /admin/finance/definitions | access_finance_engine |
| Finance Audit | GET /admin/finance/audit | access_finance_engine |
| Calculators Hub | GET /calculators | access_calculators |
| Bond Calculator | POST /calculators/bond | access_calculators |
| Transfer Costs | POST /calculators/transfer-costs | access_calculators |

#### Daily Activity (12 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| My Daily Activity | GET /agent/daily | access_daily_activity |
| Store Activity | POST /agent/daily | access_daily_activity |
| Print Sheet | GET /agent/daily/print | access_daily_activity |
| Agent Summary | GET /agent/daily/summary | view_daily_activity |
| BM Summary | GET /bm/daily/summary | view_daily_activity |
| Admin Summary | GET /admin/daily/summary | view_daily_activity |
| Drill-down (activity/branch/agent) | nested routes | view_daily_activity |

#### Listings (15 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| My Listing Stock | GET /agent/listings | view_listings |
| Branch Listing Stock | GET /bm/listings | access_listing_stock |
| Company Listing Stock | GET /admin/listings/stock | view_listings |
| Listing Agents | GET /admin/listings/agents | view_listings |
| Import Listings | GET /admin/listings/import | import_listings |
| P24 Listings | GET /admin/p24/listings | manage_p24 |
| Listing Targets | GET /admin/listing-targets | manage_targets |
| Save CMA | POST /agent/listings/{listing}/cma | view_listings |

#### Deals V1 (19 routes)
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Deal Register | GET /admin/deals | create_deals |
| Create Deal | GET /admin/deals/create | create_deals |
| Edit Deal | GET /admin/deals/{deal}/edit | create_deals |
| Quick Update | POST /admin/deals/{deal}/quick | create_deals |
| Add Remark | POST /admin/deals/{deal}/remark | create_deals |
| Settle | GET/POST /admin/deals/{deal}/settle | settle_deals |
| Print Settlement | GET /admin/deals/{deal}/settle/print | settle_deals |
| Agent Payslip | GET /admin/deals/{deal}/settle/print/{user} | settle_deals |
| My Deals (agent) | GET /agent/deals | view_deals |

### Deals V2 (31 routes)

**Sidebar:** Admin > Deals > New Deal, Deal Register, Pipeline Setup

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Deal Register | GET /deals-v2 | deals_v2.view |
| Create Deal | GET /deals-v2/create | deals_v2.create |
| Store Deal | POST /deals-v2 | deals_v2.create |
| View Deal | GET /deals-v2/{deal} | deals_v2.view |
| Edit Deal | GET /deals-v2/{deal}/edit | deals_v2.edit |
| Update Deal | PUT /deals-v2/{deal} | deals_v2.edit |
| Delete Deal | DELETE /deals-v2/{deal} | deals_v2.archive |
| Settlement | GET/POST /deals-v2/{deal}/settlement | deals_v2.edit |
| Print Settlement/Payslip | GET /deals-v2/{deal}/settlement/print | deals_v2.view |
| Pipeline Setup | GET /deals-v2/pipeline-setup | deals_v2.manage_pipeline |
| Create/Edit Pipeline | CRUD /deals-v2/pipeline-setup/* | deals_v2.manage_pipeline |
| Steps CRUD | PUT/DELETE /deals-v2/pipeline-setup/steps/* | deals_v2.manage_pipeline |
| Complete Step | POST /deals-v2/steps/{step}/complete | deals_v2.edit |
| Approve Step | POST /deals-v2/steps/{step}/approve | — |
| Reject Step | POST /deals-v2/steps/{step}/reject | — |
| Override Due Date | POST /deals-v2/steps/{step}/override-date | deals_v2.override_dates |
| Upload Step Doc | POST /deals-v2/steps/{step}/upload | deals_v2.edit |

### Rentals (17 routes)

**Sidebar:** Rentals > Dashboard, Electronic Signatures, Active Leases, Expired Leases

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Dashboard | GET /rental | view_rentals |
| Active Leases | GET /rental/active-leases | view_rentals |
| Expired Leases | GET /rental/expired-leases | view_rentals |
| Electronic Signatures | GET /rental/signatures | view_rentals |
| Assign Metadata | POST /rental/signatures/{document}/assign-metadata | view_rentals |
| Set Expiry | POST /rental/signatures/{document}/set-expiry | view_rentals |
| Rentals (legacy AT) | CRUD /rentals/* | view_rentals |
| Rental Settings | GET /rental/settings | view_rentals |
| Rental Properties | CRUD /rental/settings/properties/* | view_rentals |
| Rental Document Types | CRUD /rental/settings/document-types/* | view_rentals |
| Rental Reminders | GET/PUT /rental/settings/reminders | view_rentals |

### Payroll / Leave (77 routes)

**Sidebar:** Branch Manager > Payroll > Employees, Earning Types, Deduction Types, Runs
**Sidebar:** Branch Manager > Leave Management > Dashboard, Applications, Balances, Leave Types, Reports, Public Holidays

#### Payroll
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Employees | CRUD /corex/payroll/employees/* | manage_payroll |
| Employee Banking | POST/PATCH /corex/payroll/employees/{employee}/banking | manage_payroll |
| Employee Earnings | POST/PATCH/DELETE /corex/payroll/employees/{employee}/earnings/* | manage_payroll |
| Employee Deductions | POST/PATCH/DELETE /corex/payroll/employees/{employee}/deductions/* | manage_payroll |
| Deactivate/Reactivate | POST /corex/payroll/employees/{employee}/* | manage_payroll |
| Earning Types | CRUD /corex/payroll/earning-types/* | manage_payroll |
| Deduction Types | CRUD /corex/payroll/deduction-types/* | manage_payroll |
| Payroll Runs | CRUD /corex/payroll/runs/* | run_payroll |
| Finalise Run | POST /corex/payroll/runs/{run}/finalise | run_payroll |
| Cancel Run | POST /corex/payroll/runs/{run}/cancel | run_payroll |
| Payslip Edit/Lines | CRUD /corex/payroll/runs/{run}/payslips/* | run_payroll |
| Payslip PDF | GET /corex/payroll/runs/{run}/payslips/{payslip}/pdf-* | run_payroll |
| Bundle PDF | GET /corex/payroll/runs/{run}/bundle | run_payroll |
| Run Report | GET /corex/payroll/runs/{run}/report | view_payroll_reports |
| My Payslips | GET /corex/my-portal/payslips | view_own_payslips |

#### Leave
| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Leave Dashboard (admin) | GET /corex/payroll/leave/dashboard | manage_leave |
| Applications (admin) | GET /corex/payroll/leave/applications | approve_leave |
| Approve/Reject | POST /corex/payroll/leave/applications/{app}/* | approve_leave |
| Balances | GET /corex/payroll/leave/balances | manage_leave |
| Adjust Balance | POST /corex/payroll/leave/balances/{employee}/adjust | adjust_leave_balances |
| Leave Types | CRUD /corex/payroll/leave/types/* | manage_leave_types |
| Public Holidays | CRUD /corex/payroll/leave/public-holidays/* | manage_leave_types |
| Reports | GET /corex/payroll/leave/reports/* | view_leave_reports |
| My Leave (agent) | GET /corex/my-portal/leave | apply_for_leave |
| Apply for Leave | GET/POST /corex/my-portal/leave/apply | apply_for_leave |
| Cancel Application | POST /corex/my-portal/leave/{application}/cancel | apply_for_leave |

### Admin / Franchise (91 routes)

**Sidebar:** Admin Section > Company Settings, Knowledge Base, Role Manager, Training Mgmt, Onboarding, Finance Engine, Contact Governance, Market Intelligence, Staff Take-On, Deals, Settings
**System Developer (Owner):** Agency Management, PP Agents, Duplicate Cleanup, API, Client App Activity, Feedback Reports, Importer, Fault Reports, Sales Documents, Evaluation

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| User Management | GET /admin/users | manage_users |
| Create User | GET /admin/users/create | manage_users |
| Edit User | GET /admin/users/{user}/edit | manage_users |
| Toggle Active | POST /admin/users/{user}/toggle | manage_users |
| Delete User | POST /admin/users/{user}/delete | manage_users |
| Resend Invite | POST /admin/users/{user}/resend-invite | manage_users |
| Update Role | POST /admin/users/{user}/role | manage_users |
| Branch Assignments | GET /admin/branch-assignments | access_branch_assignments |
| Create Branch | POST /admin/branches | access_branch_assignments |
| Delete/Restore Branch | POST /admin/branches/{branch}/* | access_branch_assignments |
| Impersonate Start | POST /admin/impersonate/{user} | impersonate_users |
| Impersonate Stop | POST /admin/impersonate/stop | — |
| Agency Switcher | POST /agency/switch/{agency} | (owner role) |
| Branch Switcher | POST /branch/switch/{branch} | branches.switch |
| Knowledge Base | GET /admin/knowledge | access_knowledge_base |
| KB Upload/CRUD | POST/PUT/DELETE /admin/knowledge/* | access_knowledge_base |
| API Catalog | GET /admin/api | manage_users |
| TV Messages (admin) | GET /admin/tv-messages | manage_tv_messages |
| TV Messages (BM) | GET /bm/tv-messages | manage_tv_messages |
| TV Code Generate | POST /admin/tv-code/generate | manage_tv_messages |
| P24 Importer | GET /admin/importer | owner_only |
| Property Review | GET /admin/importer/review | owner_only |
| Fault Reports | GET /admin/fault-reports | owner |
| Client App Activity | GET /admin/client-app-activity | client_app.view_logs |
| Agency Management | CRUD /corex/settings/agencies/* | owner |
| PP Agents | GET /admin/pp/agents | manage_users |
| Deposit Trust Interest | CRUD /admin/deposit-trust-interest/* | access_trust_interest |

### Tools / Calculators (53 routes)

**Sidebar:** Tools > Ellie AI, Trust Interest (Register, Calc, History, Calculators), PDF Suite, Document Library, Filing Register

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Ellie AI | GET /ellie, POST /ellie/send | access_ellie |
| PDF Suite Hub | GET /tools/pdf-suite | access_pdf_suite |
| PDF Merge | GET/POST /tools/pdf-suite/merge | access_pdf_suite |
| PDF Compress | GET/POST /tools/pdf-suite/compress | access_pdf_suite |
| PDF Protect | GET/POST /tools/pdf-suite/protect | access_pdf_suite |
| PDF Redact | GET/POST /tools/pdf-suite/redact | access_pdf_suite |
| PDF Rotate | GET/POST /tools/pdf-suite/rotate | access_pdf_suite |
| PDF Reorder | GET/POST /tools/pdf-suite/reorder | access_pdf_suite |
| Image to PDF | GET/POST /tools/pdf-suite/image-to-pdf | access_pdf_suite |
| PDF Splitter | GET /tools/pdf-splitter | access_pdf_splitter |
| Deposit Interest Calculator | GET /deposit-interest-calculator | access_deposit_calculator |
| Calculation History | GET /deposit-interest-calculator/history | access_deposit_calc_history |
| Trust Interest Register | GET /admin/deposit-trust-interest | access_trust_interest |
| Evaluation Reports | GET /evaluation | access_evaluation |
| Calculators Hub | GET /calculators | access_calculators |
| Commission Calculator | GET /tools/commission | access_calculators |
| CMA Certificate | GET /tools/cma | access_calculators |
| History & Logs | GET /tools/history | access_calculators |

### Training (LMS) (13 routes)

**Sidebar:** Agents > Training (for learners), Admin > Training Mgmt (for managers)

| Feature | Key Routes | Permission |
|---------|-----------|------------|
| Course List | GET /corex/training | none (auth) |
| View Course | GET /corex/training/{course} | none |
| Start Lesson | POST /corex/training/lesson/{lesson}/start | none |
| Complete Lesson | POST /corex/training/lesson/{lesson}/complete | none |
| Acknowledge Course | POST /corex/training/{course}/acknowledge | none |
| Manage Courses | GET /corex/training/manage | owner/super_admin |
| Create Course | GET /corex/training/manage/create | owner/super_admin |
| Edit Course | GET /corex/training/manage/{course}/edit | owner/super_admin |
| Create/Edit Lesson | CRUD /corex/training/manage/* | owner/super_admin |

### API (107 routes)

| Category | Count | Key Endpoints |
|----------|-------|--------------|
| Command Center API | 27 | /api/command-center/* (calendar, tasks, dashboard, user-settings) |
| Mobile Contacts | 6 | /api/mobile/contacts/* |
| Mobile Properties | 12 | /api/mobile/properties/* (CRUD, images, spaces, gallery tags) |
| Mobile Core Matches | 10 | /api/mobile/core-matches/* |
| Client Portal | 9 | /api/v1/client/* (matches, properties, profile) |
| Agency Access | 7 | /api/v1/agency-access/* (consent flow) |
| Notifications | 5 | /api/notifications/* |
| Branding | 2 | /api/v1/branding/* |
| Prospecting API | 2 | /api/prospecting/* |
| Other | 27 | logged-user, contacts, deals, properties, device-tokens, fault-report, pp-webhook |

### Public / Token-Based (24 routes)
All external signing routes at /sign/{token}/*, plus seller info pack at /info/{token}, shared match at /shared/match/{token}, buyer portal at /buyer/portal/{token}, seller link at /property/live/{token}.

### Other / Miscellaneous (41 routes)
Home redirect, legacy dashboard, profile routes, theme toggle, TV display system (/tv/*, /tv/display/{code}), sanctum CSRF, health check (/up), internal AI routes, social OAuth, company summary, supervision placeholder.

---

## 2. Roles & Permissions

### System Roles

| Role | Label | Scope Default | Description |
|------|-------|--------------|-------------|
| **super_admin** | System Owner | all | Platform owner — gets ALL permissions. Can switch agencies, manage system. |
| **admin** | Administrator | all | Agency-level admin. Full access except agency switching. |
| **branch_manager** | Branch Manager | branch | Manages one branch. Sees branch-scoped data. |
| **agent** | Agent | own | Field agent. Sees own data only. |
| **viewer** | Viewer | branch | Read-only access across branch. |
| **office_admin** | Office Staff | — | Custom role for office administrators. |

### Permission Count
Total permission keys defined: **~180** across 28 modules.

### Key Capabilities by Role

#### System Owner (super_admin)
- Everything admin can do, plus:
- Agency Management (create/edit/deactivate agencies)
- Agency Switching (switch between agencies)
- P24 Importer
- Property Review
- Fault Reports
- Sales Documents
- Evaluation Reports
- PP Agents management
- Duplicate Cleanup
- Client App Activity
- Feedback Reports

#### Administrator (admin)
- Full agency-level management
- User management (create, edit, deactivate, impersonate)
- Branch management
- All compliance features including whistleblower configuration
- Full payroll and leave management
- All document/template management
- Knowledge base management
- Finance engine
- Role Manager
- Training management
- All reporting (company-wide)
- Commission management

#### Branch Manager
- Everything an agent can do, plus:
- Branch-scoped performance views
- Daily activity summaries for branch
- Branch listing stock
- Deal Register access
- Worksheet Market
- Targets management
- TV Messages
- Compliance review & verification
- RMCP management
- Employee screenings
- Branch compliance docs
- Template management in DocuPerfect
- Branch leave management (approve/reject)
- Pipeline setup for Deals V2

#### Agent
- Dashboard / Today
- Calendar, Tasks
- My Portal (profile, documents, payslips, leave)
- My Listing Stock, My Deals, My Daily Activity
- Contacts (CRUD)
- Properties (CRUD)
- Core Matches
- DocuPerfect (create docs, use packs/clauses)
- Presentations
- Filing Register
- Commercial Evaluations (create)
- Prospecting
- Ellie AI, PDF Suite, Calculators
- Training (learner)
- Apply for leave
- Whistleblower (file complaints)

#### Viewer
- Read-only access to most modules
- Dashboard, listings, deals, contacts, properties
- No create/edit/delete capabilities
- Can access calculators, training, knowledge base

### Registration / Onboarding Flow for New Agent

1. **Admin creates application:** Admin > Onboarding > "Create Application" — enters name, email, phone, branch assignment
2. **System sends signed URL** to applicant's email (account-setup/{user})
3. **Applicant completes Account Setup:** sets password, uploads initial documents
4. **Admin reviews:** Onboarding queue shows application with checklist (ID, FFC, qualifications)
5. **Admin verifies documents:** Each document in the checklist can be individually verified
6. **Admin toggles checklist items** as requirements are met
7. **Admin activates:** "Activate" button creates the full user account
8. **For P24 imports:** Onboarding portal allows agents to review/confirm their listing imports
9. **First login:** User lands on My Portal where they complete profile, upload remaining compliance docs

**FICA/Compliance gates before active work:**
- FFC number and certificate must be uploaded
- Required training courses must be completed (amber dot on "My Portal" and "Training" if incomplete)
- Compliance documents go through Verification Queue for CO approval

---

## 3. Core Workflows

_Note: Workflows documented from blade views, controllers, and route analysis. Button labels and form fields are extracted verbatim from the codebase where possible._

### f) Agency Setup from Scratch (owner only)

**Sidebar path:** System Developer > Agency Management
**URL:** `/settings/agencies`
**Permission:** Owner role only (`owner_only` middleware)

1. Navigate: **System Developer > Agency Management** (sidebar, owner only)
2. Click **"Create Agency"** button on the index page (lists all agencies with branch/user counts)
3. Fill form:
   - **Agency Name** (required), **Slug** (auto-generated), **Trading Name**, **Tagline**
   - **Brand Colours:** 4 colour pickers (Sidebar, Icon, Default, Button — defaults to blue/navy)
   - **Is Active** (boolean), **Is Demo** (boolean)
   - **Address**, **Phone**, **Phone Secondary**, **Fax**, **Email**
   - **Reg No**, **VAT No**, **FFC No**, **FIC No**
   - **P24 Agency ID**, **P24 Agency Label**
   - **Logo** upload (jpg/png/webp, max 2MB)
   - **First Admin section** (required unless demo): Admin Name, Admin Email (unique), Admin Password (min 8 chars), Admin Cell
4. Submit — atomic transaction creates Agency record + first Admin user account
5. **No branches auto-created** — must add separately
6. Switch to new agency using **Agency Switcher** dropdown in sidebar header
7. Additional index page actions: **Toggle Active/Inactive**, **Edit**, **Permanent Delete** (password-gated: `Delete@corex@confirm!!`, cascades all tenant data)

### g) Adding a Branch

**URL:** `/admin/branch-assignments`
**Permission:** `access_branch_assignments`

1. Navigate to `/admin/branch-assignments` (not directly in sidebar — access via User Management area)
2. Page shows all users with branch assignments and all existing branches
3. Fill **Create Branch** form: **Name** (required), **Code** (required)
4. Submit — branch created with current agency_id
5. After creation, configure per-branch: Trading Name, Tagline, Address, Phone, Email, Reg/VAT/FFC numbers, P24 Agency ID, Logo upload
6. **Branch deletion:** soft-delete, but requires **reassignment map** — every user must be moved to another branch first
7. Soft-deleted branches restorable (requires `manage_system` permission)

### h) Inviting a New User / Agent

Two parallel flows exist: **Onboarding Pipeline** (formal) and **Account Setup** (password-set).

**Onboarding Pipeline:**
**Sidebar path:** Admin > Onboarding (with pending count badge)
**Permission:** Owner/super_admin only

1. Navigate: **Admin > Onboarding** — sees Kanban pipeline with columns: applied, screening, interview, documentation, training, activated, rejected, withdrawn
2. Click **"Create Application"**
3. Form: First Name, Last Name, Email, Phone, ID Number, Current Agency, Years Experience, FFC Number, FFC Expiry, PPRA Status, **Designation** (property_practitioner / candidate_practitioner / intern — required), Motivation, Referral Source, Referred By
4. Submit — auto-seeds a verification checklist
5. On detail page: Upload documents (id_copy, ffc_certificate, qualifications, pi_insurance, tax_clearance, proof_of_address, cv, other), Verify/reject each doc (auto-ticks checklist), Toggle checklist items, Advance status through pipeline
6. Click **"Activate"** — validates all required checklist items complete, then:
   - Creates User record (role: agent, random 12-char password)
   - Creates AgentCapPeriod, AgentMentor (if candidate/intern), AgentSponsorship (if referred)
   - Returns temporary password in flash message

**Account Setup (signed URL):**
1. New user receives email with signed URL `/account-setup/{user}`
2. Sets password (min 8 chars + confirmation)
3. `email_verified_at` set, redirected to login
4. First login → **My Portal** with outstanding tasks (FFC upload, training, compliance docs)

### i) Adding a Contact

1. Navigate: **Real Estate > Contacts** (sidebar)
2. Click **"Add Contact"** button (top right)
3. System checks for duplicates on key fields
4. Fill form fields: First Name, Last Name, Phone, Email, ID Number, Contact Type (dropdown — Buyer, Seller, Landlord, Tenant, etc.), Contact Source, Address, Notes
5. Click **"Save"** — contact created
6. Contact show page displays: profile, linked properties, documents, notes, tags, matches, FICA status, communication log

### j) FICA on a Contact

**Sidebar path:** Compliance > FICA
**Permission:** `access_compliance`

**Index page** shows pipeline indicator: "Awaiting Client" → "Awaiting Agent Review" → "Awaiting CO Approval" → "Complete" with counts per stage. Tabs: My CO Queue, All, Awaiting Client, Awaiting Agent Review, Awaiting CO Approval, Approved, Corrections Needed, Cancelled, Rejected. Header buttons: **"View RMCP"**, **"Create Wet-Ink FICA"**, **"Send Online FICA"**.

**Online FICA flow:**
1. Click **"Send Online FICA"** on index page
2. Searchable contact picker (contact must have email)
3. Click **"Send FICA Request"** — creates submission (status=draft), generates 64-char token (14-day expiry), emails contact
4. **Contact opens /fica/{token}** — public form: personal details, entity info, PEP status, ID upload, proof of address, source of funds
5. Contact submits → status becomes "submitted"

**Wet-Ink FICA flow:**
1. Click **"Create Wet-Ink FICA"** on index page
2. Form: Contact picker, Entity type (natural/company/trust/partnership), Wet ink received date, Checkbox "confirmed signed paper", File uploads: FICA form (required), ID copy (required), Proof of address (required), Supporting docs (optional)
3. Submit → creates submission with status=submitted, intake_type=wet_ink

**Agent Review (show page):**
1. Click **"Verify"** on a submitted row
2. Left panel: all submitted form data and document previews
3. Upload Supporting Document panel: Document Type dropdown (ID Copy, Proof of Address, FICA Form, etc.), File input, **"Upload Document"** button
4. **Verification Checklist:** 6 yes/no questions (identity, address, authority, VIP/PEP, suspicious activity, consistency)
5. **Agent Approval form:** Risk Rating (Low/Medium/High), Verification Method checkboxes (WhatsApp video call, Physically met, Video call, Certified copies — min 1 required), Notes
6. Click **"Approve (Send to Compliance Officer)"**
7. Or **"Request Corrections"** (textarea, re-sends token to contact) or **"Reject"** (textarea + confirm)

**CO Review:**
1. Click **"Review & Approve"** on index (or "Compliance Review" on show page)
2. Three-column layout: LEFT = submitted data, MIDDLE = agent's verification (read-only), RIGHT = CO form
3. **CO Compliance Checklist:** 7 items (identity, address < 2 months, authority, delegating authority, VIP/PEP, suspicious, consistency)
4. **TFS Screening Panel**
5. **Final Approval:** TFS completed? (yes/no), Risk Rating override, Notes, **Signature pad** (draw with mouse/touch, required)
6. Click **"Approve & Finalise"** — status → approved, 24-month expiry, contact record auto-updated, documents filed to contact's drive
7. Or **"Return to Agent"** (textarea) or **"Reject"** (textarea + confirm)

**Reopen Rejected (new feature):**
- Visible on rejected submissions for CO/owner/admin
- Button: **"Reopen for Corrections"** → modal with textarea (min 10 chars)
- Sets status from `rejected` to `corrections_requested`
- Agent can then fix and click **"Resubmit for CO Review"**

**What can fail:** Contact has no email → validation error; token expired (14 days); non-CO tries compliance-review → 403

### k) Adding a Property

**Sidebar path:** Real Estate > Properties
**Permission:** `access_properties` + `create_properties`

1. Navigate: **Real Estate > Properties** (`/corex/properties`)
2. Click **"Add Property"** — opens property show page in create mode (same view for create/edit)
3. Defaults: status=for_sale, listing_type=Sale, province=KwaZulu-Natal, agent=current user, branch=current branch

**Property Show/Create Page Tabs:**
| Tab | Purpose |
|-----|---------|
| **Overview** | Activity timeline, owner display, KPIs (existing properties only) |
| **Info** | Main edit form — all property fields |
| **Gallery** | Image upload: Dawn, Noon, Dusk, Gallery categories; drag-reorder; smart gallery |
| **Contacts** | Link/unlink contacts with role (free-text: owner, buyer, tenant, etc.) |
| **Notes** | Free-text notes |
| **History** | Audit log with CSV export |
| **Drive** | Documents organized by DocumentType folders filtered by listing_type |
| **Intelligence** | Buyer interest signals, seller preview |
| **Core Matches** | Auto-matched buyer contacts |

**Key form fields (Info tab):**
- **Required:** title, price, suburb, beds, baths, garages, agent_id
- **Address:** street_number, street_name, suburb, city, complex_name, unit_number, stand_number, zone_type
- **Financial:** price, rates_taxes, levy, commission_percent, admin_fee, marketing_fee
- **Rental-specific:** rental_amount, deposit_amount, lease_period, lease_type, lease_start/end_date
- **Details:** listing_type, property_type (agency dropdown), category, mandate_type (Sole/Open/Dual), status, size_m2, erf_size_m2
- **Media:** youtube_video_id, matterport_id, virtual_tour_url

**Sidebar actions on property page:**
- **Save Changes**, **Syndication** (toggle portals), **Live Preview**, **Ad Builder**, **Market Property** (social), **Duplicate**, **Archive**, **Report Non-Compliance**
- **Readiness Panel** shows percentage score checking: Title, Price, Status, Suburb, Description, Beds, Baths, Agent, Photos, Listed Date + portal status for HFC/PP/P24

### l) Linking a Contact as Seller

1. Navigate to **Property show page** > Contacts tab
2. Click **"Link Contact"** — searchable picker appears
3. Search for existing contact or click **"Create & Link"** for new contact
4. Select **role** from dropdown: Owner, Seller, Landlord, Lessor, Tenant, etc.
5. Click **"Link"** — creates contact_property pivot record with role
6. Alternatively: from **Contact show page** > Properties tab > "Link Property"

### m) Creating a Mandate / OTM via DocuPerfect

**Sidebar path:** Documents > E-Sign Document
**Permission:** `create_docuperfect_docs`

The E-Sign Wizard is a 6-step flow with a two-panel layout (left = form, right = live document preview):

1. **Step 1 — Template Selection**
   - "Continue where you left off" section shows draft flows with "Continue" / "Delete Draft"
   - Category filter buttons: All / Sales / Rentals
   - Search input: "Search templates..."
   - Template groups collapsed by document type, each showing: name, page count, field count, render type badge (Web/PDF)
   - Web Packs and PDF Packs sections (if packs exist)
   - For mandates: select "Mandate to Sell", "Sole Mandate", "Authority to Let", etc.
   - **Hard block:** Sale agreements / OTPs blocked from e-sign: "Sale agreements must be signed with wet ink per the Alienation of Land Act"
   - Click **"Next"**

2. **Step 2 — Property Selection**
   - Property search input with autocomplete (searches both properties and rental_properties)
   - Results show: address, property type, beds, price/rental, linked lessor/owner name
   - On selection: green checkmark, "Selected: [address]" badge with clear button
   - Manual entry fallback: Address, Suburb, Unit/Erf Number, Complex Name, Property Type dropdown
   - Selecting a property auto-links contacts to recipients in step 3

3. **Step 3 — Recipients**
   - Agent shown first as readonly locked card ("Signs first — locked")
   - Each recipient has: Role dropdown (Seller, Buyer, Landlord, Tenant, Witness), Contact search, Full Name, ID Number, Email, Cell Phone, Physical Address
   - "Linked" badge when contact loaded from database
   - Role mismatch warning if recipient role doesn't match template's signing_parties
   - **"+ Add Second [Owner] (Co-owner)"** button for co-owners
   - **"+ Add Recipient"** button
   - Contacts without _contact_id are auto-created as Contact records on save

4. **Step 4 — Document Details**
   - **Sales fields:** Asking Price (R), Commission (%), Mandate Start Date, Mandate Expiry Date with quick-fill: 1 Mo / 3 Mo / 6 Mo / 9 Mo
   - **Rental fields:** Monthly Rental (R), Deposit (R), Lease Start Date, Lease Duration buttons (6/12/24 months / Custom), Lease End Date (auto-calc), Commission (%), Marketing Fee (R)
   - Values auto-fill from selected property record
   - Commission defaults: 7.5% sales, 10% rentals

5. **Step 5 — Fill & Review**
   - ALL fields shown in document order with role badges
   - Party reassignment dropdown per field
   - Input types vary: text, date, select, Yes/No tick, strikethrough toggle, textarea
   - "Other Conditions / Additional Clauses" section at bottom with **"+ Insert Clause"** button (opens clause library modal)
   - Auto-save fires on input; filled fields turn green-bordered

6. **Step 6 — Signing Setup**
   - **Delivery Mode:** E-Sign / Wet Ink / Download Only
   - For e-sign: signing order cards, agent always position 1, non-agents reorderable
   - Per signer: email editable, "Send after previous" or "Sign later (deferred)", "Exclude from email" checkbox, "FICA verification required before signing" checkbox
   - Document summary: field counts per party, signature/initials zone counts
   - Click **"Prepare & Sign"**

### n) Sending the Mandate for E-Sign

After clicking "Prepare & Sign" on Step 6 (delivery mode = e-sign):

1. System creates Document, SignatureTemplate, and per-signer SignatureRequest records (each with unique token)
2. **Agent signs first** — redirected to signing interface immediately
3. After agent signs, confirmation page shows:
   - "Document Signed!" with "Next: Sent for signature" showing next recipient name, role, email
   - **"View Audit Trail"** and **"Create Another"** buttons
4. Next signer receives email with link: `/sign/{token}`
5. **What the external signer sees:** standalone signing page (no CoreX sidebar), document rendered with pre-filled fields, signature/initials zones to complete, consent capture
6. Signing order enforced: Tenant/Buyer before Landlord/Seller
7. After all parties sign → signed PDF generated with audit trail
8. Agent monitors on **My E-Sign Documents** page:
   - Status tiles: Draft, Ready to Sign, Awaiting Signatures, Completed
   - Per-signer progress: checkmark (completed), envelope (sent/viewed), lock (waiting)
   - Actions: **"Send Reminder"**, **"Cancel Document"** (requires reason)
   - Completed section: **"Audit"** and **"Download"** buttons

**Wet Ink alternative:** generates PDF with secure download links; agent reviews uploaded scans before proceeding
**Candidate practitioners:** require supervisor authorisation before signing proceeds (two-stage: Initial Review → Final Sign-off)

### o) Property Marketing Readiness

The `MarketingReadinessService` enforces **4 mandatory gates** — ALL must pass before any external marketing:

| Gate | Check | Blocker if failed |
|------|-------|-------------------|
| **1. Authority to Market** | Mandate or marketing permission document in property Drive | "No signed mandate or marketing authority found" |
| **2. Sellers FICA Approved** | ALL contacts with role owner/seller/landlord/lessor have FICA = complete | "FICA incomplete for [name(s)]" |
| **3. Photos** | At least 4 property photos uploaded (any group) | "Upload at least 4 property photos" |
| **4. Details Complete** | Key fields populated (title, price, suburb, etc.) | "Complete missing property details" |

**"Go Live" compliance snapshot:** Once all gates pass, authorized user clicks **"Go Live"** — freezes a compliance snapshot (timestamp + seller FICA data at that point). Future checks short-circuit if snapshot exists.

**Per-portal additional checks:** P24 and PP each have their own field requirements via their ListingMapper. PP additionally supports exclusive_days, showday events, address visibility toggles.

**Marketing status on Properties index:** Each property shows: **live** (snapshot exists), **ready** (gates pass, no snapshot), **blocked** (gates fail — shows which), **n/a** (sold/withdrawn/draft)

### p) Publishing a Listing to P24 / PP

1. Navigate to **Property show page** > Syndication tab
2. For **Private Property (PP):**
   - Toggle **"PP Syndication Enabled"**
   - Click **"Submit"** — system calls PP SOAP API
   - Status tracked: pending, active, error
   - Manage show days, video links, visibility settings
3. For **Property24 (P24):**
   - Toggle **"P24 Syndication Enabled"**
   - Click **"Submit"** — system calls P24 API
   - Status tracked: pending, active, error
4. Both show real-time status on the syndication tab with last synced timestamps

### q) Creating a Viewing in Calendar

**Sidebar path:** Dashboard > Calendar
**Permission:** `view_dashboard`

The calendar has 4 view modes: **Month**, **Week**, **Day**, **Agenda**.

1. Navigate: **Dashboard > Calendar**
2. Click on a date/time slot or click **"New Event"** button
3. Fill form:
   - Title, **Event Class** (manual classes: viewing, property_evaluation, listing_presentation, meeting, task, other)
   - Date, Time, Duration
   - Link to **Property** (searchable picker) — multi-property supported for listing presentations
   - Add **Attendees** (agents + contacts) — triggers invitation workflow
   - Set **Reminders** (JSON offsets)
   - Set **Recurring** options if needed
4. Click **"Save"** — event appears on calendar grid
5. Attendees receive **Calendar Invitations** (Dashboard > Invitations, with badge count) — can accept/decline with notes

**Filtering:** By event type (compliance, deal, document, lease, leave, payroll, people, property, recurring, manual), by event class/category, by scope (own/branch/all). Colour-by modes: class, branch, agent.

### r) Capturing Buyer Feedback After a Viewing

1. Navigate to completed/past viewing event on **Calendar**
2. Click on the event → detail modal (AJAX)
3. Click **"Capture Feedback"** — feedback form opens (per-contact or per-property mode)
4. Fill: outcomes, concerns, interest level, notes
5. Submit → feedback stored and linked to the calendar event
6. Visible on **Buyer Pipeline** and property history
7. **Missed feedback creates follow-up tasks** automatically
8. Events can also be: completed, dismissed, or extended

### s) Buyer Makes an Offer — Deal V2 Creation

**Sidebar path:** Admin > Deals > New Deal
**Permission:** `deals_v2.create`

Deal creation is a **5-step wizard:**

1. **Step 1 — Property:** Search by address (typeahead, min 2 chars), select property card showing address/price/agent. Click **"Next"**
2. **Step 2 — Contacts:** Add sellers (seller/co_seller role), buyers (buyer/co_buyer), other parties (conveyancer, bond_originator). Property's linked contacts auto-loaded. Click **"Next"**
3. **Step 3 — Details:**
   - **deal_type:** bond, cash, sale_of_2nd
   - **purchase_price** (required), **commission_percentage** or **total_commission_inc_vat** (15% VAT)
   - **offer_date** (required)
   - **listing_split_percent / selling_split_percent** (must total 100%)
   - Per-side: agent(s), external toggle + agency name + our share %, per-agent split override
   - **linked_deal_id** (for sale_of_2nd), **notes**
4. **Step 4 — Pipeline:** Select pipeline template (grouped by deal_type). Templates have ordered steps with triggers (days offset from other steps)
5. **Step 5 — Confirm:** Review all, submit → generates reference number, redirects to deal show page

### t) OTP Through to Deal Closure

**Deal Show Page** displays:
- Summary cards: Property, Contacts (with roles), Commission, Key Dates (offer, expected registration, days in pipeline)
- **RAG status:** green/amber/red/overdue indicator (pulsing for overdue)
- **Pipeline Tracker:** Each step as expandable card with status icon, due date, RAG color border, "Awaiting BM Approval" badge, Complete/Skip/Upload actions
- **Activity Log:** Last 50 entries

**Lifecycle:**
1. Steps progress: complete, skip, or await BM approval. Trigger-based due dates auto-calculate
2. RAG recalculates as dates pass (green → amber → red → overdue)
3. **Settlement** (`/deals-v2/{deal}/settlement`): per-agent listing/selling shares, agent cuts, PAYE, deductions
4. **"Mark as Paid"** locks all financial fields permanently
5. Deal can be: Completed, Cancelled, On Hold, or Archived (soft-delete)

### u) Filing a Whistleblower Complaint

**Sidebar path:** Compliance > Compliance Reporting
**Permission:** `compliance.whistleblow.create`
**Standalone URL:** `/corex/compliance/whistleblow/new`

1. Navigate: **Compliance > Compliance Reporting** > click **"File New Report"**
2. **Complaint Type** (radio, required):
   - **Tier 1 — Paperwork breach (seller confirmed):** Seller confirms no mandate, FICA, or MDF. Cites PPA S47, S67, FICA S21A.
   - **Tier 2 — No FFC displayed:** Advert missing valid FFC number. Cites PPA S61.
   - **Tier 3 — Unregistered practitioner:** Not found on PPRA register. Criminal offence under PPA S49.
3. **Property** section: Link to existing property (searchable) OR enter freetext address
4. **Subjects of Complaint** (multi-subject, 1–10): Each has Agency name (required), Practitioner name (optional), Portal URL (required, valid URL), Portal source (Property24/Private Property/Other). **"Add another subject"** button.
5. **Seller Information** (Tier 1 only): Seller statement textarea (required, appears verbatim in PPRA complaint PDF)
6. **Agent Notes:** internal notes textarea
7. **Evidence** section (tier-aware):
   - Tier 1: "seller statement is primary evidence, attachments optional"
   - Tier 2: **Required:** screenshot of advert showing missing FFC
   - Tier 3: **Required:** screenshot of advert AND PPRA register search
   - Multi-file upload (up to 5, max 10MB each)
8. Click **"Submit Report"** → validates tier-specific requirements → status = pending_approval
9. Reference generated: HFC-WB-{id}
10. CO badge count updates in sidebar

### v) Sending Seller Information Pack (Standalone)

**Sidebar path:** Compliance > Send Standalone Info Pack (small muted text at bottom of Compliance group)
**Permission:** `compliance.whistleblow.view`

1. Navigate: **Compliance > Send Standalone Info Pack**
2. **Which Issue?** (radio): No mandate/FICA/MDF signed | Agent has no FFC displayed | Agent appears unregistered
3. **Property** (optional): searchable picker — selecting auto-loads linked sellers (owner/lessor/seller/co_seller/landlord roles) as recipients with name/email pre-filled
4. **Recipients** (1–10): Auto-loaded from property (checkbox to enable/disable) + **"Add Recipient"** button for manual entry (name + email)
5. **"Preview Email"** → opens modal with rendered HTML preview in iframe
6. **"Send to {N} recipient(s)"** → sends individual emails, logs each in WhistleblowEmailLog
7. **"Copy WhatsApp Link"** (green button) → generates `/info/{token}` link (90-day expiry), copies to clipboard
8. Public view at /info/{token}

**Auto-fire on approve:** When a whistleblower complaint is approved, the system automatically sends seller info to all seller/owner contacts linked to the complaint's property (non-blocking — approval succeeds even if send fails)

### w) Approving a Whistleblower Complaint as CO

**Sidebar path:** Compliance > Compliance Reporting (badge shows pending count)
**Permission:** `compliance.whistleblow.approve`

1. Navigate: **Compliance > Compliance Reporting** → click **"Review"** on a pending complaint
2. **Show page** displays: Header (reference HFC-WB-{id}, tier badge, status, days open), Property, Subjects list (agency, practitioner, portal URL), Reporter, Seller Statement (Tier 1), Evidence list, Agent Notes, Audit Timeline, Generated PDF (if exists), Email History with inline viewer
3. **Action footer** (status=pending_approval only):
   - **"Approve & Send to PPRA"** (confirm: "Send this complaint to PPRA now?")
     - Generates tier-specific PDF via Puppeteer
     - Emails PPRA (tier-routed to configured recipients, fallback: complaints@theppra.org.za)
     - CC: agency CO email + approver email
     - Auto-fires seller info pack to all linked sellers/owners
     - Flags property with compliance_evidence_flags
   - **"Request Changes"** → modal with textarea → status = changes_requested, sends back to reporter
   - **"Reject"** → modal with reason textarea → status = rejected
4. All actions logged in **Communications Log** (separate sidebar item)

**PPRA routing:** Reads `agency.whistleblow_tier_recipients` JSON per tier. Demo mode available (sends to test email with [DEMO] prefix)

### x) Daily Activity Tracking

**Sidebar path:** Agency Tracker > My Performance > My Daily Activity
**Permission:** `access_daily_activity` (capture), `view_daily_activity` (summaries)

1. Navigate: **Agency Tracker > My Daily Activity**
2. Page shows a **monthly grid** with configurable activity columns (defined globally via `activity_columns` table, branch can override). Each row = one day
3. Agent enters numeric values per activity per day (calls, viewings, offers, etc.)
4. Click **"Save"** to record for the month
5. Print sheet available for paper-based capture

**Daily Activity Summary** (separate pages):
- **Agent view** (`agent.daily.summary`): Aggregated over configurable ranges (7d, month, 3m, 6m, 12m). Per-activity-definition totals with weighted points (from `activity_definitions` table with name, weight, scoring_mode). Grand total points calculated
- **BM view** (`bm.daily.summary`): Branch agents' activity with drill-down to agent level
- **Admin view** (`admin.daily.summary`): Company-wide with drill-down to branch → agent

### y) Prospecting — Capture, Match, Claim, Work

**Sidebar path:** Real Estate > Prospecting
**Permission:** `access_prospecting`

1. Navigate: **Real Estate > Prospecting** — header "Market Intelligence"
2. **Stats cards:** Total Active Listings, Average Asking Price, New This Week, Price Reductions, Cross-Listed, Buyer Matched, In Our Stock
3. **Filters:** Portal (All/P24/PP), Suburb, Property Type, Price Min/Max, Beds Min, Agent/Agency Name, Date range, Stock filter (In Stock/Not In Stock), Claim filter (My Claims/Unclaimed)
4. **Toggle buttons:** "Show Buyer-Matched Only", "Sort by Buyer Demand", "In Stock"/"Not In Stock"
5. **Listings table:** Grouped by property_group_id (same property across portals), shows portal icons, price history, cross-listing indicators, buyer match count badge, claim status

**Claiming:** Click **"Claim"** on unclaimed listing → creates ProspectingClaim. Expired claims auto-release.
**Feedback:** Agent provides status (contacted/meeting_set/listing/not_interested/lost) + notes. "not_interested"/"lost" auto-releases the claim.
**Stock Matching:** `matched_property_id` links to internal properties (bidirectional — Property show page shows "Also Marketed By")

**Core Matches (buyer requirements):**
- From Contact show page > Core Matches tab, create match with criteria: listing_type, category, property_type, price_min/max, beds/baths/garages min, suburbs, must_have_features, nice_to_have_features, pool/furnished/pets
- System scores properties via `MatchingService`
- Results page shows scored matches with **Hide** and **Convert to Deal** actions
- Convert creates Deal V1 with buyer pre-filled, optionally marks match as "fulfilled"

---

## 4. Data Relationships

```
Agency (agencies)
├── Branch (branches) — agency_id
│   ├── User (users) — branch_id, agency_id
│   │   ├── Role (roles) — user.role matches roles.name
│   │   │   └── RolePermission (role_permissions) — role, permission_key, scope
│   │   ├── Property (properties) — agent_id, branch_id, agency_id
│   │   │   ├── ContactProperty (contact_property) — property_id, contact_id, role
│   │   │   ├── PropertyFile (property_files) — property_id
│   │   │   ├── PropertyImage (property_images) — property_id
│   │   │   ├── PP Syndication — properties.pp_* columns
│   │   │   ├── P24 Syndication — properties.p24_* columns
│   │   │   ├── PropertyHealthScore — property_id
│   │   │   └── CalendarEvent — property_id (viewings)
│   │   ├── Contact (contacts) — created_by_user_id, agency_id
│   │   │   ├── ContactType (contact_types) — contact_type_id (includes esign_role)
│   │   │   ├── ContactSource (contact_sources) — contact_source_id
│   │   │   ├── ContactDocument — contact_id
│   │   │   ├── ContactNote — contact_id
│   │   │   ├── ContactTag (contact_tag pivot) — contact_id, tag_id
│   │   │   ├── FicaSubmission — contact_id
│   │   │   └── ContactMatch — contact_id (buyer matching criteria)
│   │   │       └── MatchResult → properties (auto-computed)
│   │   ├── Deal V1 (deals) — seller_name, buyer_name (text)
│   │   │   ├── DealUser (deal_user pivot) — deal_id, user_id, side
│   │   │   ├── DealSettlement — deal_id, user_id
│   │   │   └── DealMoneyLine — deal_id, user_id (computed)
│   │   ├── Deal V2 (deals_v2) — property_id
│   │   │   ├── DealV2Agent — deal_id, user_id
│   │   │   ├── DealV2Contact — deal_id, contact_id, role
│   │   │   ├── DealV2Settlement — deal_id, user_id
│   │   │   ├── DealStepInstance — deal_id, step_id
│   │   │   │   └── DealStepDocument — step_instance_id
│   │   │   ├── DealActivityLog — deal_id
│   │   │   └── DealPipelineTemplate → DealPipelineStep (configurable)
│   │   ├── DocuPerfect
│   │   │   ├── Template (docuperfect_templates) — agency_id
│   │   │   ├── Flow (flows) — template_id, property_id, contact_id, user_id
│   │   │   ├── Document (docuperfect_documents) — template_id, property_id
│   │   │   ├── SignatureTemplate — document_id
│   │   │   │   ├── SignatureRequest — signature_template_id, contact_id
│   │   │   │   └── SignatureMarker — signature_template_id
│   │   │   ├── Pack (document_packs) → PackTemplate pivot
│   │   │   ├── Clause (docuperfect_clauses)
│   │   │   └── FieldGroup (docuperfect_field_groups)
│   │   ├── Compliance
│   │   │   ├── FicaSubmission — contact_id, user_id
│   │   │   ├── WhistleblowComplaint — reported_by_user_id, agency_id
│   │   │   ├── SellerInfoPack — property_id, sent_by
│   │   │   ├── RmcpVersion — agency_id
│   │   │   ├── EmployeeScreening — user_id
│   │   │   └── UserDocument — user_id (for verification queue)
│   │   ├── CalendarEvent — user_id, property_id, contact_id
│   │   ├── CommandTask — assigned_to, property_id, contact_id, deal_id
│   │   ├── Presentation — user_id, property_id
│   │   └── DailyActivity — user_id, branch_id
│   └── PayrollEmployee — user_id, branch_id
│       ├── PayrollEarning, PayrollDeduction
│       └── Payslip (via PayrollRun)
├── PerformanceSetting — agency_id
├── FinanceComputedValue — agency_id
├── AgencyDashboardSetting — agency_id
└── AgencyComplianceProvision — agency_id
```

---

## 5. Gaps & Gotchas

### Orphaned Features (routes exist, no sidebar entry)
1. **GET /agent/dashboard** (agent.dashboard) — legacy dashboard route, superseded by /corex but still routed
2. **GET /corex/legacy-dashboard** (corex.dashboard.legacy) — explicit legacy dashboard
3. **GET /corex/supervision** — placeholder page only (PlaceholderController), sidebar item exists but feature not built
4. **GET /corex/documents** — placeholder redirecting to DocuPerfect
5. **GET /ai-buddy** — Closure route, likely internal/experimental
6. **GET /company-summary** — CompanySummaryController, no direct sidebar link
7. **GET /bm/my-dashboard** — "My Agent Dashboard" exists in BM sidebar section but may be confused with the main dashboard

### Settings Page Structure (12 tabs via `?s=` parameter)
The Settings page at `/corex/settings` has 12 distinct sections accessed via query parameter:
- `agency` — Company details, VAT rate, logo, document header preview
- `user` — Designations, social accounts, API tokens
- `system` — Reserved
- `notifications` — Master toggles (in_app, email, push), per-event preferences
- `feature-documents` — DocuPerfect named fields
- `feature-rentals` — Rental doc types, reminder settings
- `feature-contacts` — Contact types, sources, tags (CRUD)
- `feature-properties` — Property categories/types/statuses, mandate types, marketing toggle, syndication portals
- `feature-matches` — Core Matches on/off, visibility scope, WhatsApp template
- `feature-dashboard` — Dashboard mode (user vs agency), agency-wide settings
- `leave-visibility` — Cross-role leave visibility matrix
- `whistleblow-settings` — Approver IDs, CO email, per-tier PPRA recipients
- `remote-access` — Remote access consent toggle

### Features That Exist But Are Hard to Find
1. **Seller Links** — generate shareable live property links for sellers, buried in property show page
2. **Mark Sold** — property action, not prominently placed
3. **FICA Reopen** — recovery path for rejected FICA, recently added
4. **Standalone Seller Info Pack** — at the very bottom of the Compliance submenu, styled in muted text (font-size: 0.75rem, color: var(--text-muted))
5. **Authorise Documents** — only visible to users who canAuthorise (CandidatePractitionerService check), conditional sidebar item
6. **Communications Log** — under Compliance, easy to miss
7. **Lawyer Review Pack** — available only with compliance.whistleblow.configure permission
8. **Revenue Share Calculator** — top-level sidebar item, not grouped
9. **My Earnings** — top-level sidebar item, separate from Agency Tracker commission
10. **Property Ad Template Builder** — accessible via /corex/ad-templates/builder, no direct sidebar link

### Multi-Step Flows Where Users Could Get Stuck
1. **E-Sign Wizard** — 5-step wizard; if recipient role doesn't match template signing_parties, user gets blocked with no clear error
2. **Onboarding Portal** — public token-based; if token expires, user has no recovery path
3. **FICA Public Form** — if contact doesn't complete in one session, they must use the same token link (no save-and-resume from their side)
4. **Property Wizard** — multi-step; draft discarding is possible but user may not realize they have an abandoned draft

### Permission Inconsistencies
1. **Seller Info Pack** routes use `compliance.whistleblow.view` permission — not a dedicated seller-info permission
2. **Agency Switcher** routes use `manage_tv_messages` permission as a proxy — likely should have its own
3. **Branch Switcher** routes also use `manage_tv_messages` as middleware
4. **Several Commission routes** have `perm: none` — gated by role checks in controller instead of middleware
5. **Training routes** have `perm: none` — role-checked in Blade (`$isOwner || $effectiveRole === 'super_admin'`) instead of permission middleware
6. **Lost Deals** and **Feedback Reports** use `command_center.settings` as a catch-all admin permission

### Recent Changes (May 2026) Not Yet Familiar to Staff
1. **Whistleblower Module** — complete multi-subject redesign with standalone /new URL, tier-aware evidence, per-tier PPRA routing
2. **Seller Info Pack** — standalone sending with auto-fire on approve, multi-recipient support
3. **FICA Rejection Recovery** — CO/admin can reopen rejected FICA submissions
4. **Prospecting Stock Match** — bidirectional matching with observer hooks and artisan backfill
5. **DocuPerfect composite index** — performance fix for findSignedMandate, not user-facing but affects speed
6. **Buyer Pipeline** — new command center feature with per-contact detail pages, playbook actions, preferences, re-engage flows
7. **Lost Deals page** — new reporting page for admin/owner
8. **Calendar Invitations** — new invitation system with accept/decline/acknowledge
9. **Event Classes** — configurable event class settings

### Today Page — Card System (key for training)
The "Today" landing page (`/corex`) is a smart card-based grid that auto-refreshes every 5 minutes. Cards only appear if their count > 0. Agent-level cards include:
- A1: Today's Appointments (always shown)
- A2: Pending Calendar Invitations
- A3: Overdue Items (tasks + events)
- A4: Buyers Needing Follow-up
- A5: Buyer Portal Activity
- A6: Listings Needing Attention
- A7: E-Sign Activity
- A8: FICA CO Review Queue / A8b: My FICA Tracking
- A9: Active Buyer Pipeline
- A10: My Compliance
- A11: Deal Steps Assigned to Me
- A12: Prospecting Activity / A12c: Listings Pending Marketing
- A13: Draft Presentations
- A14: Leave Applications
- A15: Sales Documents Awaiting Return
- A16: Training & Qualifications
- A17: Events Needing Feedback
- A18: Unread Notifications
- A19: Recent Activity (always shown)

Branch Manager gets additional cards: B1-B5 (Agent Watch, Listings Review, Compliance Queue, Leave Approval, Lost Value).

### Data / Demo Notes
1. **Deposit Trust Interest** — 89 seeded records for trust account data
2. **Automation Rules** — 20 system default rules seeded
3. **Database has 297 tables** (up from 203 documented in CODEBASE_MAP)
4. **No demo mode exists** — all data is production-grade

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total routes | 1,223 |
| Route categories | 29 |
| System roles | 6 |
| Permission keys | ~180 |
| Database tables | 297 |
| Sidebar menu items (root level) | ~20 |
| Sidebar sub-panels | 12 (Dashboard, Real Estate, Agency Tracker, Documents, Rentals, Compliance, Payroll, Leave, Trust Interest, Deals V2, Evaluation, Importer) |
| Workflows documented (f through y) | 20 |
| API endpoints | 107 |
| Public/token routes | 24 |
